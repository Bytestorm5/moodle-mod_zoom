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

require_login();
require_capability('moodle/site:config', context_system::instance());

$sesskey = required_param('sesskey', PARAM_RAW);
require_sesskey();

// Read app key/secret from settings.
$appkey = get_config('zoom', 'dropboxappkey');
$appsecret = get_config('zoom', 'dropboxappsecret');
if (empty($appkey) || empty($appsecret)) {
    print_error('error', 'core', '', 'Configure Dropbox App key and secret in plugin settings first.');
}

// Build authorization URL.
$state = bin2hex(random_bytes(16));
$SESSION->mod_zoom_dropbox_state = $state;

$redirect = new moodle_url('/mod/zoom/dropbox_oauth_callback.php');
$redirecturi = $redirect->out(false);

$params = [
    'client_id' => $appkey,
    'response_type' => 'code',
    'redirect_uri' => $redirecturi,
    'token_access_type' => 'offline',
    'state' => $state,
    // Requested scopes (optional but recommended).
    'scope' => 'files.content.write sharing.write',
];
$authurl = new moodle_url('https://www.dropbox.com/oauth2/authorize', $params);
redirect($authurl);
*** End Patch