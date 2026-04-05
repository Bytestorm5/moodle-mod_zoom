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
 * CLI script to migrate Zoom recording URLs to Dropbox links.
 *
 * This script:
 * - Finds records in {zoom_meeting_recordings} that still point to Zoom
 * - Downloads the actual media from Zoom using the plugin's OAuth credentials
 * - Uploads to Dropbox using a provided access token
 * - Replaces the DB URL with a Dropbox shared link (permalink)
 * - Organizes uploads in Dropbox by Course/Section folders
 *
 * Run separately from Moodle's main cron (e.g. OS scheduler/Task Scheduler).
 *
 * @package    mod_zoom
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

// Allow long-running execution.
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

// Parse CLI options.
[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'dropbox-token' => null,
        'courseid' => null,
        'since' => null, // YYYY-MM-DD filter on recordingstart.
        'limit' => 0,    // Process at most N records.
        'dry-run' => false,
        'verbose' => false,
    ],
    [
        'h' => 'help',
        't' => 'dropbox-token',
        'c' => 'courseid',
        's' => 'since',
        'n' => 'limit',
        'd' => 'dry-run',
        'v' => 'verbose',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "Migrate Zoom recording URLs in DB to Dropbox shared links.\n\n"
        . "Options:\n"
        . "-h, --help                 Show this help\n"
        . "-t, --dropbox-token=TOKEN  Dropbox access token (or use env DROPBOX_ACCESS_TOKEN)\n"
        . "-c, --courseid=ID         Optional: only process recordings from this course id\n"
        . "-s, --since=YYYY-MM-DD    Optional: only process recordings on/after this date (recordingstart)\n"
        . "-n, --limit=N             Optional: limit the number of records processed\n"
        . "-d, --dry-run             Optional: do not upload/update, just print actions\n"
        . "-v, --verbose             Optional: verbose output\n\n"
        . "Examples:\n"
        . "$ sudo -u www-data php mod/zoom/cli/recordings_to_dropbox.php --dropbox-token=... --since=2025-01-01\n"
        . "$ setx DROPBOX_ACCESS_TOKEN <token> & php mod/zoom/cli/recordings_to_dropbox.php -c=5 -n=10\n";
    cli_writeln($help);
    exit(0);
}

$dropboxtoken = $options['dropbox-token'] ?? getenv('DROPBOX_ACCESS_TOKEN') ?? (get_config('zoom', 'dropboxtoken') ?: null);
if (empty($dropboxtoken)) {
    cli_error('Missing Dropbox token. Provide --dropbox-token, set env DROPBOX_ACCESS_TOKEN, or configure zoom/dropboxtoken.');
}

$dryrun = !empty($options['dry-run']);
$verbose = !empty($options['verbose']);

// Helper: verbose logging.
function vout($msg, $verbose) {
    if ($verbose) { cli_writeln($msg); }
}

// Helper: sanitize a path segment for Dropbox (no control chars, trim, replace slashes).
function sanitize_segment($name) {
    $name = core_text::substr(clean_param($name, PARAM_FILE), 0, 140);
    $name = trim($name);
    $name = str_replace(['\\', '/'], '-', $name);
    return $name === '' ? 'unnamed' : $name;
}

// Resolve course and section names for a given zoom instance id.
function resolve_course_section_path(int $zoomid) {
    global $DB;

    $rec = $DB->get_record_sql(
        "SELECT z.id as zoomid, z.name AS zoomname, c.id AS courseid, c.fullname as coursename, \n"
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

    $course = sanitize_segment($rec->coursename ?? 'Course');
    $section = sanitize_segment(($rec->sectionname ?? '') !== '' ? $rec->sectionname : ('Topic ' . (string)($rec->sectionnum ?? '')));
    return ["/{$course}", "/{$section}"];
}

// Fetch Zoom OAuth token using plugin settings.
function get_zoom_access_token(): string {
    $clientid = get_config('zoom', 'clientid');
    $clientsecret = get_config('zoom', 'clientsecret');
    $accountid = get_config('zoom', 'accountid');
    if (empty($clientid) || empty($clientsecret) || empty($accountid)) {
        cli_error('Zoom plugin credentials are not configured (clientid/clientsecret/accountid).');
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
        cli_error('Failed to get Zoom access token: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($resp);
    if ($code >= 400 || empty($json->access_token)) {
        cli_error('Failed to get Zoom access token, HTTP ' . $code . ' response: ' . $resp);
    }
    return $json->access_token;
}

// Get the download info for a single recording by meeting UUID and recording id.
function get_zoom_recording_download(string $token, string $meetinguuid, string $recordingid) {
    // Get list of files for this meeting UUID, then pick the matching id.
    $encodeduuid = (new mod_zoom\webservice())->encode_uuid($meetinguuid);
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
            $recordingtype = $rf->recording_type ?? 'null';
            if (empty($download) && !empty($play)) {
                // Fallback: play URL (may not be directly downloadable via API).
                $download = $play;
            }
            if (empty($download)) {
                throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Recording has no downloadable URL');
            }
            return [
                'url' => $download,
                'filetype' => $filetype,
                'recordingtype' => $recordingtype,
            ];
        }
    }
    throw new moodle_exception('errorwebservice', 'mod_zoom', '', 'Recording id not found in meeting files');
}

// Download a Zoom recording to a temp file, return [filepath, size].
function download_zoom_to_temp(string $token, string $url) {
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
        CURLOPT_TIMEOUT => 0, // Allow long downloads.
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

// Upload a file (<=150MB) to Dropbox at the given path. Returns metadata.
function dropbox_simple_upload(string $token, string $path, string $filepath) {
    $size = filesize($filepath);
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

// Chunked upload (>150MB) using upload sessions. Returns metadata.
function dropbox_chunked_upload(string $token, string $path, string $filepath, int $chunksize = 8_388_608) { // 8 MB chunks.
    $size = filesize($filepath);
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
    // No body for finish; Dropbox expects last chunk in body. We'll finish with zero-byte append then finish commit by API semantics
    // that allow empty body for finish when offset == size.
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

// Ensure a shared link exists for the uploaded file, return URL.
function dropbox_get_or_create_shared_link(string $token, string $path): string {
    // Try to create first.
    $create = function() use ($token, $path) {
        $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
        $payload = json_encode([
            'path' => $path,
            'settings' => new stdClass(), // Defaults; visibility determined by account policy.
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
        return [$code, $resp];
    };

    [$code, $resp] = $create();
    if ($code === 200) {
        $json = json_decode($resp, true);
        return $json['url'] ?? '';
    }
    // If shared link already exists, list to retrieve it.
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

// Build a Dropbox path from course/section and filename.
function build_dropbox_path_for_recording($zoomrec, $filename) {
    [$course, $section] = resolve_course_section_path((int)$zoomrec->zoomid);
    return $course . $section . '/' . $filename;
}

// Determine a target filename with extension based on file type and metadata.
function make_filename($zoomrec, $filetype): string {
    $base = sanitize_segment($zoomrec->name);
    $dt = userdate($zoomrec->recordingstart, '%Y-%m-%d_%H-%M-%S', 0, false);
    $ext = strtolower($filetype);
    switch ($ext) {
        case 'mp4': $suffix = '.mp4'; break;
        case 'm4a': $suffix = '.m4a'; break;
        default: $suffix = '.bin'; break;
    }
    return $base . '_' . $dt . '_' . substr($zoomrec->zoomrecordingid, 0, 8) . $suffix;
}

// Select candidate recordings.
global $DB;

$params = [];
$wheres = ["(r.externalurl LIKE :z1 OR r.externalurl LIKE :z2)"];
$params['z1'] = '%zoom.%';
$params['z2'] = '%zoom.us%';

if (!empty($options['courseid'])) {
    $wheres[] = 'z.course = :courseid';
    $params['courseid'] = (int)$options['courseid'];
}
if (!empty($options['since'])) {
    $ts = strtotime($options['since'] . ' 00:00:00');
    if ($ts !== false) {
        $wheres[] = 'r.recordingstart >= :since';
        $params['since'] = $ts;
    }
}

$limit = (int)($options['limit'] ?? 0);

$sql = "SELECT r.*, z.course, z.name AS zoomname \n"
     . "  FROM {zoom_meeting_recordings} r \n"
     . "  JOIN {zoom} z ON z.id = r.zoomid \n"
     . ' WHERE ' . implode(' AND ', $wheres)
     . ' ORDER BY r.recordingstart ASC, r.id ASC';

$recs = $DB->get_records_sql($sql, $params, 0, $limit > 0 ? $limit : 0);
if (empty($recs)) {
    cli_writeln('No Zoom recording URLs found to migrate.');
    exit(0);
}

cli_writeln('Found ' . count($recs) . ' recording(s) to migrate...');

// Fetch Zoom token up front.
$zoomtoken = get_zoom_access_token();

$processed = 0;
$skipped = 0;
$failed = 0;

foreach ($recs as $rec) {
    try {
        $processed++;
        vout("Processing recording id={$rec->id} meetinguuid={$rec->meetinguuid} zoomrecordingid={$rec->zoomrecordingid}", $verbose);

        $dl = get_zoom_recording_download($zoomtoken, $rec->meetinguuid, $rec->zoomrecordingid);
        // Only handle video/audio types; skip others (chat, transcript, cc).
        $allowed = ['MP4', 'M4A'];
        if (!in_array(strtoupper($dl['filetype'] ?? ''), $allowed, true)) {
            $skipped++;
            vout('  Skipping non-media file type: ' . ($dl['filetype'] ?? 'unknown'), $verbose);
            continue;
        }
        $filename = make_filename($rec, $dl['filetype'] ?? 'mp4');
        $dropboxpath = build_dropbox_path_for_recording($rec, $filename);

        vout('  Downloading from Zoom: ' . $dl['url'], $verbose);
        if ($dryrun) {
            vout('  [dry-run] Would download -> ' . $filename, $verbose);
        } else {
            [$tmpfile, $size] = download_zoom_to_temp($zoomtoken, $dl['url']);

            vout('  Downloaded ' . round($size / (1024 * 1024), 2) . ' MB', $verbose);

            // Upload to Dropbox.
            vout('  Uploading to Dropbox path: ' . $dropboxpath, $verbose);
            if ($size <= 150 * 1024 * 1024) {
                $meta = dropbox_simple_upload($dropboxtoken, $dropboxpath, $tmpfile);
            } else {
                $meta = dropbox_chunked_upload($dropboxtoken, $dropboxpath, $tmpfile);
            }
            @unlink($tmpfile);

            // Create or fetch shared link.
            $shared = dropbox_get_or_create_shared_link($dropboxtoken, $meta['path_lower'] ?? $dropboxpath);
            // Force direct download if desired by appending dl=1.
            $permalink = preg_match('/[?&]dl=/', $shared) ? $shared : ($shared . (strpos($shared, '?') === false ? '?dl=1' : '&dl=1'));

            // Update DB record externalurl with Dropbox permalink.
            $rec->externalurl = $permalink;
            $rec->timemodified = time();
            $DB->update_record('zoom_meeting_recordings', $rec);
        }

        cli_writeln("✔ Migrated recording id={$rec->id} to Dropbox." . ($dryrun ? ' (dry-run)' : ''));
    } catch (Throwable $e) {
        $failed++;
        cli_writeln("✖ Failed recording id={$rec->id}: " . $e->getMessage());
        // Continue with next.
    }
}

cli_writeln("Done. Processed: $processed, Skipped: $skipped, Failed: $failed");
