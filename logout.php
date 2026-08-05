<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2019
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once __DIR__ . "/resources/require.php";

//use custom logout destination if set otherwise redirect to the login page
	$logout_destination = $settings->get('login', 'logout_destination', PROJECT_PATH.'/login.php');

//record who logged out and from where (temporary diagnostics)
	require_once __DIR__ . "/resources/skykin_session_log.php";
	skykin_session_log('logout');

//destroy session
	session_unset();
	session_destroy();

// clear session cookie so the next page cannot revive the old login
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
			$params['path'] ?? '/',
			$params['domain'] ?? '',
			false,
			true
		);
	}
	if (!empty($_COOKIE['remember'])) {
		setcookie('remember', '', time() - 42000, '/');
	}

//redirect the user to the logout page
	header("Location: ".$logout_destination);
	exit;
