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

// Render a detailed error page with stack trace and diagnostic log.
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
    $code = optional_param('code', null, PARAM_RAW);
    $state = optional_param('state', null, PARAM_RAW);
    $log .= "    code present: " . (!empty($code) ? 'yes' : 'no') . "\n";
    $log .= "    state present: " . (!empty($state) ? 'yes' : 'no') . "\n";
    $log .= "    session state present: " . (!empty($SESSION->mod_zoom_dropbox_state) ? 'yes' : 'no') . "\n";

    if (empty($code) || empty($state) || empty($SESSION->mod_zoom_dropbox_state) || $state !== $SESSION->mod_zoom_dropbox_state) {
        throw new \Exception(get_string('dropboxauth_error_state', 'mod_zoom'));
    }
    unset($SESSION->mod_zoom_dropbox_state);
    $log .= "[2] State verified and cleared\n";

    $appkey = get_config('zoom', 'dropboxappkey');
    $appsecret = get_config('zoom', 'dropboxappsecret');
    if (empty($appkey) || empty($appsecret)) {
        throw new \Exception(get_string('dropboxauth_error_config', 'mod_zoom'));
    }
    $log .= "[3] App key and secret loaded from config\n";

    // Build redirect URI - must exactly match the one used in the authorization request.
    $redirecturi = (new moodle_url('/mod/zoom/dropbox_oauth_callback.php'))->out(false);
    $log .= "[4] Redirect URI: $redirecturi\n";

    // Exchange authorization code for tokens.
    // IMPORTANT: Moodle's curl::post() sends multipart/form-data when given an array,
    // even if you set Content-Type manually. Dropbox requires application/x-www-form-urlencoded.
    // Always pass a pre-encoded string body via http_build_query().
    $data = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirecturi,
        'client_id'     => $appkey,
        'client_secret' => $appsecret,
    ];
    $body = http_build_query($data);
    $log .= "[5] Token exchange body built (code value omitted from log)\n";
    $log .= "    Params: grant_type, code, redirect_uri, client_id, client_secret\n";

    $curl = new curl();
    $curl->setHeader('Content-Type: application/x-www-form-urlencoded');
    $curl->setHeader('Accept: application/json');

    $log .= "[6] POSTing to https://api.dropboxapi.com/oauth2/token\n";
    $resp = $curl->post('https://api.dropboxapi.com/oauth2/token', $body);

    if ($curl->get_errno()) {
        throw new \Exception('cURL error: ' . $curl->error);
    }

    $info = $curl->get_info();
    $httpcode = $info['http_code'] ?? 0;
    $log .= "[7] HTTP response code: $httpcode\n";
    $log .= "    Response body: $resp\n";

    if ($httpcode >= 400) {
        throw new \Exception("HTTP $httpcode => $resp");
    }

    $json = json_decode($resp, true);
    $refreshtoken = $json['refresh_token'] ?? '';
    if (empty($refreshtoken)) {
        $log .= "[8] Response JSON keys: " . implode(', ', array_keys($json ?? [])) . "\n";
        throw new \Exception(get_string('dropboxauth_error_missing_refresh', 'mod_zoom'));
    }
    $log .= "[8] Refresh token received\n";

    // Store refresh token in plugin config.
    set_config('dropboxrefreshtoken', $refreshtoken, 'zoom');
    $log .= "[9] Refresh token saved to plugin config\n";

    // Success: go back to settings with a success notice.
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
