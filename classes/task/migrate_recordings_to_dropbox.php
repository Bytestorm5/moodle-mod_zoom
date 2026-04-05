<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task to migrate Zoom recording URLs to Dropbox shared links.
 *
 * @package    mod_zoom
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use moodle_exception;
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

/**
 * Migrates Zoom recording URLs in {zoom_meeting_recordings} to Dropbox links.
 */
class migrate_recordings_to_dropbox extends scheduled_task {
    /** @var int Per-run processing limit to avoid timeouts */
    protected const RUN_LIMIT = 25;

    /**
     * Returns name of task for the admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('migraterecordingstodropbox', 'mod_zoom');
    }

    /**
     * Execute the migration.
     */
    public function execute() {
        global $DB;

        $config = get_config('zoom');

        if (empty($config->viewrecordings)) {
            mtrace('Skipping task - ' . get_string('zoomerr_viewrecordings_off', 'mod_zoom'));
            return;
        }

        // Try to initialise Zoom webservice (ensures credentials and token handling are correct).
        try {
            $service = \zoom_webservice();
            // Force token acquisition to populate oauth cache.
            $service->has_scope(['meeting:read:admin']);
        } catch (\Throwable $e) {
            mtrace('Cannot initialise Zoom webservice: ' . $e->getMessage());
            return;
        }

        // Build candidate list: recordings whose URL still points to Zoom.
        $params = ['z1' => '%zoom.%', 'z2' => '%zoom.us%'];
        $sql = "SELECT r.*, z.course, z.name AS zoomname\n"
             . "  FROM {zoom_meeting_recordings} r\n"
             . "  JOIN {zoom} z ON z.id = r.zoomid\n"
             . " WHERE (r.externalurl LIKE :z1 OR r.externalurl LIKE :z2)\n"
             . " ORDER BY r.recordingstart ASC, r.id ASC";
        $recs = $DB->get_records_sql($sql, $params, 0, self::RUN_LIMIT);
        if (empty($recs)) {
            mtrace('No Zoom recording URLs found to migrate.');
            return;
        }

        mtrace('Found ' . count($recs) . ' recording(s) to migrate to Dropbox...');

        // Get current OAuth token from cache after initialisation.
        $oauthcache = \cache::make('mod_zoom', 'oauth');
        
        // Get Dropbox access token (refreshing via refresh token if configured).
        try {
            $dropboxtoken = $this->get_dropbox_access_token(false);
        } catch (\Throwable $e) {
            mtrace('Skipping task - Dropbox token retrieval failed: ' . $e->getMessage());
            return;
        }

        $processed = 0; $skipped = 0; $failed = 0;
        foreach ($recs as $rec) {
            try {
                $processed++;
                mtrace("Processing recording id={$rec->id} meetinguuid={$rec->meetinguuid} recordingid={$rec->zoomrecordingid}");

                // Ensure we have a fresh OAuth token available from cache (service already seeded it).
                $zoomtoken = $oauthcache->get('accesstoken');
                if (empty($zoomtoken)) {
                    // As a fallback, poke the service again and re-read the cache.
                    $service->has_scope(['meeting:read:admin']);
                    $zoomtoken = $oauthcache->get('accesstoken');
                }
                if (empty($zoomtoken)) {
                    throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'OAuth token unavailable');
                }

                // Fetch precise download URL and filetype for this recording id.
                $dl = $this->get_zoom_recording_download($zoomtoken, $rec->meetinguuid, $rec->zoomrecordingid);
                $filetype = $dl['filetype'] ?? '';
                $dlurl = $dl['url'] ?? '';

                $allowed = ['MP4', 'M4A'];
                if (!in_array(strtoupper($filetype), $allowed, true)) {
                    $skipped++;
                    mtrace('  Skipping non-media file type: ' . $filetype);
                    continue;
                }

                $filename = $this->make_filename($rec, $filetype ?: 'mp4');
                $dropboxpath = $this->build_dropbox_path_for_recording($rec, $filename);

                // Download from Zoom with Bearer token.
                [$tmpfile, $size] = $this->download_zoom_to_temp($zoomtoken, $dlurl);
                mtrace('  Downloaded ' . round($size / (1024 * 1024), 2) . ' MB');

                // Upload to Dropbox (simple/chunked based on size threshold 150MB).
                mtrace('  Uploading to Dropbox path: ' . $dropboxpath);
                $meta = null;
                try {
                    if ($size <= 150 * 1024 * 1024) {
                        $meta = $this->dropbox_simple_upload($dropboxtoken, $dropboxpath, $tmpfile);
                    } else {
                        $meta = $this->dropbox_chunked_upload($dropboxtoken, $dropboxpath, $tmpfile);
                    }
                } catch (\Exception $ex) {
                    // If token expired, refresh once and retry.
                    if (strpos($ex->getMessage(), 'expired_access_token') !== false || strpos($ex->getMessage(), 'HTTP 401') !== false) {
                        mtrace('  Dropbox token expired; refreshing and retrying upload...');
                        $dropboxtoken = $this->get_dropbox_access_token(true);
                        if ($size <= 150 * 1024 * 1024) {
                            $meta = $this->dropbox_simple_upload($dropboxtoken, $dropboxpath, $tmpfile);
                        } else {
                            $meta = $this->dropbox_chunked_upload($dropboxtoken, $dropboxpath, $tmpfile);
                        }
                    } else {
                        throw $ex;
                    }
                }
                @unlink($tmpfile);

                // Create or fetch a shared link and convert to a download permalink.
                $shared = null;
                try {
                    $shared = $this->dropbox_get_or_create_shared_link($dropboxtoken, $meta['path_lower'] ?? $dropboxpath);
                } catch (\Exception $ex) {
                    if (strpos($ex->getMessage(), 'expired_access_token') !== false || strpos($ex->getMessage(), 'HTTP 401') !== false) {
                        mtrace('  Dropbox token expired while creating link; refreshing and retrying...');
                        $dropboxtoken = $this->get_dropbox_access_token(true);
                        $shared = $this->dropbox_get_or_create_shared_link($dropboxtoken, $meta['path_lower'] ?? $dropboxpath);
                    } else {
                        throw $ex;
                    }
                }
                $permalink = preg_match('/[?&]dl=/', $shared) ? $shared : ($shared . (strpos($shared, '?') === false ? '?dl=1' : '&dl=1'));

                // Update the DB record.
                $rec->externalurl = $permalink;
                $rec->timemodified = time();
                $DB->update_record('zoom_meeting_recordings', $rec);

                mtrace("✔ Migrated recording id={$rec->id} to Dropbox.");
            } catch (\Throwable $e) {
                $failed++;
                mtrace('✖ Failed recording id=' . $rec->id . ': ' . $e->getMessage());
            }
        }

        mtrace("Done. Processed: $processed, Skipped: $skipped, Failed: $failed");
    }

    // -------------------- Helper methods (ported from CLI) -------------------- //

    protected function sanitize_segment(string $name): string {
        $name = \core_text::substr(clean_param($name, PARAM_FILE), 0, 140);
        $name = trim($name);
        $name = str_replace(['\\', '/'], '-', $name);
        return $name === '' ? 'unnamed' : $name;
    }

    protected function resolve_course_section_path(int $zoomid): array {
        global $DB;
        $rec = $DB->get_record_sql(
            "SELECT z.id as zoomid, z.name AS zoomname, c.id AS courseid, c.fullname as coursename, c.shortname as courseshort,\n"
            . "       cs.id AS sectionid, cs.name AS sectionname, cs.section AS sectionnum\n"
            . "  FROM {zoom} z\n"
            . "  JOIN {course} c ON c.id = z.course\n"
            . "  JOIN {modules} m ON m.name = :modname\n"
            . "  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = z.id\n"
            . "  JOIN {course_sections} cs ON cs.id = cm.section\n"
            . " WHERE z.id = :zoomid",
            ['modname' => 'zoom', 'zoomid' => $zoomid]
        );
        if (!$rec) {
            return ['/Unknown Course', '/Unknown Section'];
        }
        $course = $this->sanitize_segment(($rec->courseshort ?? '') !== '' ? $rec->courseshort : ($rec->coursename ?? 'Course'));
        $section = $this->sanitize_segment(($rec->sectionname ?? '') !== '' ? $rec->sectionname : ('Topic ' . (string)($rec->sectionnum ?? '')));
        return ["/{$course}", "/{$section}"];
    }

    // Removed custom OAuth/token calls; we use zoom_webservice() and oauth cache instead.

    protected function download_zoom_to_temp(string $token, string $url): array {
        $tmp = tempnam(sys_get_temp_dir(), 'zoomrec_');
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \Exception('Failed to open temp file for writing');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: */*',
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
        ]);
        $ok = curl_exec($ch);
        $err = $ok ? null : curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $code >= 400) {
            @unlink($tmp);
            throw new \Exception('Zoom download failed (HTTP ' . $code . '): ' . ($err ?? 'unknown') . '; URL=' . $url);
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            @unlink($tmp);
            throw new \Exception('Downloaded file is empty');
        }
        return [$tmp, $size];
    }

    protected function get_zoom_recording_download(string $token, string $meetinguuid, string $recordingid): array {
        $encodeduuid = (new \mod_zoom\webservice())->encode_uuid($meetinguuid);
        $apiurl = zoom_get_api_url();
        $url = rtrim($apiurl, '/') . '/meetings/' . $encodeduuid . '/recordings';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Zoom recordings list failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \Exception('Zoom recordings list HTTP ' . $code . ' => ' . $resp);
        }
        $json = json_decode($resp);
        if (empty($json) || empty($json->recording_files)) {
            throw new \Exception('No recording_files for meeting uuid ' . $meetinguuid);
        }
        foreach ($json->recording_files as $rf) {
            if (!empty($rf->id) && (string)$rf->id === (string)$recordingid) {
                $download = $rf->download_url ?? null;
                $play = $rf->play_url ?? null;
                $filetype = $rf->file_type ?? '';
                if (empty($download) && !empty($play)) {
                    $download = $play;
                }
                if (empty($download)) {
                    throw new \Exception('Recording has no downloadable URL (id=' . $recordingid . ')');
                }
                return [
                    'url' => $download,
                    'filetype' => $filetype,
                ];
            }
        }
        throw new \Exception('Recording id not found in meeting files (id=' . $recordingid . ')');
    }

    protected function dropbox_simple_upload(string $token, string $path, string $filepath): array {
        $data = file_get_contents($filepath);
        if ($data === false) {
            throw new \Exception('Failed reading temp file for Dropbox upload');
        }
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        $args = json_encode([
            'path' => $path,
            'mode' => 'add',
            'autorename' => true,
            'mute' => false,
            'strict_conflict' => false,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . $args,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Dropbox upload failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \Exception('Dropbox upload HTTP ' . $code . ' => ' . $resp);
        }
        return json_decode($resp, true);
    }

    protected function dropbox_chunked_upload(string $token, string $path, string $filepath, int $chunksize = 8388608): array {
        $fh = fopen($filepath, 'rb');
        if ($fh === false) {
            throw new \Exception('Failed opening file for chunked upload');
        }

        // Start session.
        $first = fread($fh, $chunksize);
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload_session/start');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode(['close' => false]),
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $first,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            throw new \Exception('Dropbox start session failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            fclose($fh);
            throw new \Exception('Dropbox start session HTTP ' . $code . ' => ' . $resp);
        }
        $session = json_decode($resp, true);
        $sessionid = $session['session_id'] ?? null;
        if (!$sessionid) {
            fclose($fh);
            throw new moodle_exception('error', 'core', '', 'Dropbox response missing session_id');
        }
        $offset = strlen($first);

        // Append chunks.
        while (!feof($fh)) {
            $chunk = fread($fh, $chunksize);
            if ($chunk === '' || $chunk === false) { break; }
            $endpoint = 'https://content.dropboxapi.com/2/files/upload_session/append_v2';
            $ch = curl_init($endpoint);
            $arg = [
                'cursor' => [
                    'session_id' => $sessionid,
                    'offset' => $offset,
                ],
                'close' => false,
            ];
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/octet-stream',
                    'Dropbox-API-Arg: ' . json_encode($arg),
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $chunk,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 0,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                fclose($fh);
                throw new \Exception('Dropbox append failed: ' . $err);
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                fclose($fh);
                throw new \Exception('Dropbox append HTTP ' . $code . ' => ' . $resp);
            }
            $offset += strlen($chunk);
        }

        // Finish session.
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload_session/finish');
        $commit = [
            'path' => $path,
            'mode' => 'add',
            'autorename' => true,
            'mute' => false,
            'strict_conflict' => false,
        ];
        $arg = [
            'cursor' => [
                'session_id' => $sessionid,
                'offset' => $offset,
            ],
            'commit' => $commit,
        ];
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode($arg),
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
        ]);
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : null;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($resp === false || $code >= 400) {
            throw new \Exception('Dropbox finish HTTP ' . $code . ' => ' . ($resp ?: $err));
        }
        return json_decode($resp, true);
    }

    protected function dropbox_get_or_create_shared_link(string $token, string $path): string {
        // Try create first.
        $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
        $payload = json_encode([
            'path' => $path,
            'settings' => new \stdClass(),
        ]);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $json = json_decode($resp, true);
            return $json['url'] ?? '';
        }

        // If shared link already exists, list to get it.
        $json = json_decode($resp, true);
        $tag = $json['error']['.tag'] ?? '';
        if ($code === 409 && $tag === 'shared_link_already_exists') {
            $ch = curl_init('https://api.dropboxapi.com/2/sharing/list_shared_links');
            $payload = json_encode([
                'path' => $path,
                'direct_only' => true,
            ]);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200) {
                $json = json_decode($resp, true);
                if (!empty($json['links'][0]['url'])) {
                    return $json['links'][0]['url'];
                }
            }
        }
        throw new \Exception('Failed to get or create Dropbox shared link: ' . $resp);
    }

    protected function build_dropbox_path_for_recording($zoomrec, string $filename): string {
        [$course, $section] = $this->resolve_course_section_path((int)$zoomrec->zoomid);
        return $course . $section . '/' . $filename;
    }

    protected function make_filename($zoomrec, string $filetype): string {
        $ext = strtolower($filetype);
        switch ($ext) {
            case 'mp4': $suffix = '.mp4'; break;
            case 'm4a': $suffix = '.m4a'; break;
            default: $suffix = '.bin'; break;
        }
        // Use recording ID to ensure uniqueness and keep names short.
        $idpart = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$zoomrec->zoomrecordingid);
        if ($idpart === '') { $idpart = (string)($zoomrec->id ?? time()); }
        return $idpart . $suffix;
    }

    // -------------------- Dropbox OAuth helper -------------------- //

    protected function get_dropbox_access_token(bool $forceRefresh = false): string {
        $appkey = get_config('zoom', 'dropboxappkey') ?: '';
        $appsecret = get_config('zoom', 'dropboxappsecret') ?: '';
        $refreshtoken = get_config('zoom', 'dropboxrefreshtoken') ?: '';

        if ($appkey !== '' && $appsecret !== '' && $refreshtoken !== '') {
            $cache = \cache::make('mod_zoom', 'dropboxoauth');
            $token = !$forceRefresh ? ($cache->get('accesstoken') ?: '') : '';
            $expires = !$forceRefresh ? (int)($cache->get('expires') ?: 0) : 0;
            if ($token === '' || $expires === 0 || time() >= $expires) {
                [$token, $expiry] = $this->refresh_dropbox_access_token($appkey, $appsecret, $refreshtoken);
                $cache->set_many([
                    'accesstoken' => $token,
                    'expires' => $expiry,
                ]);
            }
            return $token;
        }

        // Fallback to legacy static token.
        $legacy = get_config('zoom', 'dropboxtoken') ?: '';
        if ($legacy === '') {
            throw new \Exception('Dropbox credentials not configured. Provide app key/secret + refresh token or a static access token.');
        }
        return $legacy;
    }

    protected function refresh_dropbox_access_token(string $appkey, string $appsecret, string $refreshtoken): array {
        $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
        $basic = base64_encode($appkey . ':' . $appsecret);
        $fields = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshtoken,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $basic,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Dropbox token refresh failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \Exception('Dropbox token refresh HTTP ' . $code . ' => ' . $resp);
        }
        $json = json_decode($resp, true);
        $token = $json['access_token'] ?? '';
        $expiresin = (int)($json['expires_in'] ?? 3600);
        if ($token === '') {
            throw new \Exception('Dropbox token refresh response missing access_token');
        }
        $expiry = time() + max(300, $expiresin - 60); // Store slightly earlier expiry to be safe.
        return [$token, $expiry];
    }
}
