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

// SkyKin: if already logged in, do NOT destroy the session (that logged people
// out when a background /login.php tab refreshed). Send them to their home page.
// To switch users, open /logout.php explicitly.
	if (!empty($_SESSION['authorized'])) {
		$skykin_domain = $_SESSION['user_context'] ?? ($_SESSION['domain_name'] ?? 'client1.skykin.local');
		$skykin_user   = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'agent');
		if (!empty($_SESSION['groups'])) {
			foreach ($_SESSION['groups'] as $g) {
				$name = strtolower((string)($g['group_name'] ?? ''));
				if ($name === 'agent') {
					header('Location: /app/agent_dashboard/index.php?agent=' . rawurlencode($skykin_user) . '&domain=' . rawurlencode($skykin_domain));
					exit;
				}
				if ($name === 'supervisor') {
					header('Location: /app/agent_dashboard/supervisor.php?domain=' . rawurlencode($skykin_domain));
					exit;
				}
			}
		}
		$dest = $settings->get('login', 'destination', PROJECT_PATH.'/core/dashboard/');
		header('Location: '.$dest);
		exit;
	}

//additional includes — shows login form when not authorized; redirects after successful login
	require_once "resources/check_auth.php";

//redirect to the dashboard (reached only when already authorized after login)
	header("Location: ".$settings->get('login', 'destination', PROJECT_PATH.'/core/dashboard/'));

?>
