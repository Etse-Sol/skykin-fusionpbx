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
	Portions created by the Initial Developer are Copyright (C) 2008-2023
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J. Crane <markjcrane@fusionpbx.com>
*/
//includes files
	require_once __DIR__ . "/resources/require.php";

// SkyKin: visiting /login.php must show the login form even if a session
// is still active (otherwise admins/supervisors can never switch users —
// check_auth skips the form and this file redirected straight to dashboard).
	if (!empty($_SESSION['authorized'])) {
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'] ?? '/',
				$params['domain'] ?? '',
				$params['secure'] ?? false,
				$params['httponly'] ?? true
			);
		}
		session_destroy();
		session_start();
		$_SESSION['authorized'] = false;
	}

//additional includes — shows login form when not authorized; redirects after successful login
	require_once "resources/check_auth.php";

//redirect to the dashboard (reached only when already authorized after login)
	header("Location: ".$settings->get('login', 'destination', PROJECT_PATH.'/core/dashboard/'));

?>
