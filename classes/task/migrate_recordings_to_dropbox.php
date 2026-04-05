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
        $token = $config->dropboxtoken ?? '';
        if (empty($token)) {
            mtrace('Skipping task - Dropbox token not configured (zoom/dropboxtoken).');
            return;
        }

        // If recordings are not enabled in plugin settings, there's nothing to migrate.
        if (empty($config->viewrecordings)) {
            mtrace('Skipping task - ' . get_string('zoomerr_viewrecordings_off', 'mod_zoom'));
            return;
        }

        // Build candidate list: recordings whose URL still points to Zoom.
        $params = [
            'z1' => '%zoom.%',
            'z2' => '%zoom.us%'
        ];
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

        // Fetch Zoom API access token via plugin credentials.
        try {
            $zoomtoken = $this->get_zoom_access_token();
        } catch (\Throwable $e) {
            mtrace('Cannot obtain Zoom access token: ' . $e->getMessage());
            return;
        }

        $processed = 0; $skipped = 0; $failed = 0;

        foreach ($recs as $rec) {
            try {
                $processed++;
                mtrace("Processing recording id={$rec->id} meetinguuid={$rec->meetinguuid} recordingid={$rec->zoomrecordingid}");

                $dl = $this->get_zoom_recording_download($zoomtoken, $rec->meetinguuid, $rec->zoomrecordingid);
                $allowed = ['MP4', 'M4A'];
                if (!in_array(strtoupper($dl['filetype'] ?? ''), $allowed, true)) {
                    $skipped++;
                    mtrace('  Skipping non-media file type: ' . ($dl['filetype'] ?? 'unknown'));
                    continue;
                }

                $filename = $this->make_filename($rec, $dl['filetype'] ?? 'mp4');
                $dropboxpath = $this->build_dropbox_path_for_recording($rec, $filename);

                // Download from Zoom.
                [$tmpfile, $size] = $this->download_zoom_to_temp($zoomtoken, $dl['url']);
                mtrace('  Downloaded ' . round($size / (1024 * 1024), 2) . ' MB');

                // Upload to Dropbox (simple/chunked based on size threshold 150MB).
                mtrace('  Uploading to Dropbox path: ' . $dropboxpath);
                if ($size <= 150 * 1024 * 1024) {
                    $meta = $this->dropbox_simple_upload($token, $dropboxpath, $tmpfile);
                } else {
                    $meta = $this->dropbox_chunked_upload($token, $dropboxpath, $tmpfile);
                }
                @unlink($tmpfile);

                // Create or fetch a shared link and convert to a download permalink.
                $shared = $this->dropbox_get_or_create_shared_link($token, $meta['path_lower'] ?? $dropboxpath);
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
            "SELECT z.id as zoomid, z.name AS zoomname, c.id AS courseid, c.fullname as coursename,\n"
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
        $course = $this->sanitize_segment($rec->coursename ?? 'Course');
        $section = $this->sanitize_segment(($rec->sectionname ?? '') !== '' ? $rec->sectionname : ('Topic ' . (string)($rec->sectionnum ?? '')));
        return ["/{$course}", "/{$section}"];
    }

    protected function get_zoom_access_token(): string {
        $clientid = get_config('zoom', 'clientid');
        $clientsecret = get_config('zoom', 'clientsecret');
        $accountid = get_config('zoom', 'accountid');
        if (empty($clientid) || empty($clientsecret) || empty($accountid)) {
            throw new moodle_exception('error', 'mod_zoom', '', 'Zoom plugin credentials are not configured (clientid/clientsecret/accountid).');
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($clientid . ':' . $clientsecret),
            'Accept: application/json',
        ];

        $ch = curl_init('https://zoom.us/oauth/token');
        $fields = http_build_query([
            'grant_type' => 'account_credentials',
            'account_id' => $accountid,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new moodle_exception('error', 'mod_zoom', '', 'Failed to get Zoom access token: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($resp);
        if ($code >= 400 || empty($json->access_token)) {
            throw new moodle_exception('error', 'mod_zoom', '', 'Failed to get Zoom access token, HTTP ' . $code . ' response: ' . $resp);
        }
        return $json->access_token;
    }

    protected function get_zoom_recording_download(string $token, string $meetinguuid, string $recordingid): array {
        $encodeduuid = (new \mod_zoom\webservice())->encode_uuid($meetinguuid);
        $url = 'https://api.zoom.us/v2/meetings/' . $encodeduuid . '/recordings';

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
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Zoom recordings list failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Zoom recordings list HTTP ' . $code . ' => ' . $resp);
        }
        $json = json_decode($resp);
        if (empty($json) || empty($json->recording_files)) {
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'No recording_files for meeting uuid ' . $meetinguuid);
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
                    throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Recording has no downloadable URL');
                }
                return [
                    'url' => $download,
                    'filetype' => $filetype,
                ];
            }
        }
        throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Recording id not found in meeting files');
    }

    protected function download_zoom_to_temp(string $token, string $url): array {
        $tmp = tempnam(sys_get_temp_dir(), 'zoomrec_');
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new moodle_exception('error', 'core', '', 'Failed to open temp file for writing');
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
            throw new moodle_exception('error', 'core', '', 'Failed to download from Zoom (HTTP ' . $code . '): ' . ($err ?? 'unknown'));
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            @unlink($tmp);
            throw new moodle_exception('error', 'core', '', 'Downloaded file is empty');
        }
        return [$tmp, $size];
    }

    protected function dropbox_simple_upload(string $token, string $path, string $filepath): array {
        $data = file_get_contents($filepath);
        if ($data === false) {
            throw new moodle_exception('error', 'core', '', 'Failed reading temp file for Dropbox upload');
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
            throw new moodle_exception('error', 'core', '', 'Dropbox upload failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new moodle_exception('error', 'core', '', 'Dropbox upload HTTP ' . $code . ' => ' . $resp);
        }
        return json_decode($resp, true);
    }

    protected function dropbox_chunked_upload(string $token, string $path, string $filepath, int $chunksize = 8388608): array {
        $fh = fopen($filepath, 'rb');
        if ($fh === false) {
            throw new moodle_exception('error', 'core', '', 'Failed opening file for chunked upload');
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
            throw new moodle_exception('error', 'core', '', 'Dropbox start session failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            fclose($fh);
            throw new moodle_exception('error', 'core', '', 'Dropbox start session HTTP ' . $code . ' => ' . $resp);
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
                throw new moodle_exception('error', 'core', '', 'Dropbox append failed: ' . $err);
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                fclose($fh);
                throw new moodle_exception('error', 'core', '', 'Dropbox append HTTP ' . $code . ' => ' . $resp);
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
            throw new moodle_exception('error', 'core', '', 'Dropbox finish HTTP ' . $code . ' => ' . ($resp ?: $err));
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
        throw new moodle_exception('error', 'core', '', 'Failed to get or create Dropbox shared link: ' . $resp);
    }

    protected function build_dropbox_path_for_recording($zoomrec, string $filename): string {
        [$course, $section] = $this->resolve_course_section_path((int)$zoomrec->zoomid);
        return $course . $section . '/' . $filename;
    }

    protected function make_filename($zoomrec, string $filetype): string {
        $base = $this->sanitize_segment($zoomrec->name);
        $dt = userdate($zoomrec->recordingstart, '%Y-%m-%d_%H-%M-%S', 0, false);
        $ext = strtolower($filetype);
        switch ($ext) {
            case 'mp4': $suffix = '.mp4'; break;
            case 'm4a': $suffix = '.m4a'; break;
            default: $suffix = '.bin'; break;
        }
        return $base . '_' . $dt . '_' . substr($zoomrec->zoomrecordingid, 0, 8) . $suffix;
    }
}
