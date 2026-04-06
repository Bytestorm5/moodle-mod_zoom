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

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

// Render a detailed error page with diagnostic log and stack trace.
function mod_zoom_render_dropbox_oauth_error(string $title, string $message, string $log = '', string $trace = ''): void {
    global $PAGE, $OUTPUT;
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/mod/zoom/dropbox_oauth_callback.php'));
    $PAGE->set_pagelayout('admin');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($title));
    echo html_writer::tag('p', s($message));
    if ($log !== '') {
        echo $OUTPUT->heading(get_string('dropboxauth_log', 'mod_zoom'), 3);
        echo html_writer::tag('pre', s($log), ['style' => 'white-space:pre-wrap']);
    }
    if ($trace !== '') {
        echo $OUTPUT->heading('Stack trace', 3);
        echo html_writer::tag('pre', s($trace), ['style' => 'white-space:pre-wrap']);
    }
    $back = new moodle_url('/admin/settings.php', ['section' => 'modsettingzoom']);
    echo $OUTPUT->single_button($back, get_string('continue'));
    echo $OUTPUT->footer();
    exit;
}

$log = '';

try {
    $log .= "[1] Reading OAuth callback parameters\n";
    $code  = optional_param('code', null, PARAM_RAW);
    $state = optional_param('state', null, PARAM_RAW);
    $log .= "    code present:          " . (!empty($code) ? 'yes' : 'no') . "\n";
    $log .= "    state present:         " . (!empty($state) ? 'yes' : 'no') . "\n";
    $log .= "    session state present: " . (!empty($SESSION->mod_zoom_dropbox_state) ? 'yes' : 'no') . "\n";

    if (empty($code) || empty($state) || empty($SESSION->mod_zoom_dropbox_state) || $state !== $SESSION->mod_zoom_dropbox_state) {
        throw new \Exception(get_string('dropboxauth_error_state', 'mod_zoom'));
    }
    unset($SESSION->mod_zoom_dropbox_state, $SESSION->mod_zoom_dropbox_code_verifier);
    $log .= "[2] State verified and cleared\n";

    $appkey    = get_config('zoom', 'dropboxappkey') ?: '';
    $appsecret = get_config('zoom', 'dropboxappsecret') ?: '';
    if (empty($appkey) || empty($appsecret)) {
        throw new \Exception(get_string('dropboxauth_error_config', 'mod_zoom'));
    }
    $log .= "[3] App key and secret loaded\n";

    // redirect_uri MUST match what was sent in the authorization URL.
    // Dropbox uses it for validation; it must be included even though no redirect happens here.
    $redirecturi = (new moodle_url('/mod/zoom/dropbox_oauth_callback.php'))->out(false);
    $log .= "[4] Redirect URI: $redirecturi\n";

    // Token exchange: confidential-client authorization_code flow.
    // Endpoint: api.dropbox.com/oauth2/token (the OAuth endpoint; api.dropboxapi.com is for file API).
    // All credentials in POST body as application/x-www-form-urlencoded.
    // redirect_uri is required here even though it's only used for validation.
    $tokenparams = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirecturi,
        'client_id'     => $appkey,
        'client_secret' => $appsecret,
    ];
    // Explicitly pass '&' separator — PHP's arg_separator.output ini can default to '&amp;'.
    $body = http_build_query($tokenparams, '', '&');

    // Build masked version for log: replace the values of code and client_secret only.
    $logbody = str_replace(
        ['code=' . $code, 'client_secret=' . $appsecret],
        ['code=<omitted>', 'client_secret=<masked>'],
        $body
    );
    $log .= "[5] PHP arg_separator.output: '" . ini_get('arg_separator.output') . "'\n";
    $log .= "    Body byte length: " . strlen($body) . "\n";
    $log .= "    Token exchange body: $logbody\n";

    $log .= "[6] POSTing to https://api.dropbox.com/oauth2/token\n";
    $ch = curl_init('https://api.dropbox.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Expect:',
        ],
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $errmsg = curl_error($ch);
    $info   = curl_getinfo($ch);
    $senthdr = (string)($info['request_header'] ?? '');

    $log .= "    Request headers:\n";
    foreach (explode("\n", trim($senthdr)) as $line) {
        $log .= "        $line\n";
    }

    if ($errno) {
        throw new \Exception('cURL error ' . $errno . ': ' . $errmsg);
    }

    $httpcode = (int)$info['http_code'];
    $log .= "[7] HTTP response code: $httpcode\n";
    $log .= "    Response body: $resp\n";

    if ($httpcode >= 400) {
        throw new \Exception("HTTP $httpcode => $resp");
    }

    $json = json_decode($resp, true);
    $refreshtoken = $json['refresh_token'] ?? '';
    if (empty($refreshtoken)) {
        $log .= "    Response JSON keys: " . implode(', ', array_keys($json ?? [])) . "\n";
        throw new \Exception(get_string('dropboxauth_error_missing_refresh', 'mod_zoom'));
    }
    $log .= "[8] Refresh token received\n";

    set_config('dropboxrefreshtoken', $refreshtoken, 'zoom');
    $log .= "[9] Refresh token saved to plugin config\n";

    $redirect = new moodle_url('/admin/settings.php', ['section' => 'modsettingzoom']);
    redirect($redirect, get_string('dropboxauth_success', 'mod_zoom'));

} catch (\Throwable $e) {
    mod_zoom_render_dropbox_oauth_error(
        'Dropbox OAuth Error',
        $e->getMessage(),
        $log,
        $e->getTraceAsString()
    );
}
