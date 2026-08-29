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

// SkyKin: if already logged in, do not silently bounce away from login.
// Show a chooser so the user can continue or sign in as someone else.
	if (!empty($_SESSION['authorized'])) {
		// Switch-user must be an explicit POST. As a GET link it could be
		// triggered by a link prefetch or a background redirect, which silently
		// destroyed a working login.
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['switch'] ?? '') === '1') {
			$_SESSION = [];
			if (ini_get('session.use_cookies')) {
				$params = session_get_cookie_params();
				setcookie(session_name(), '', time() - 42000,
					$params['path'] ?? '/',
					$params['domain'] ?? '',
					false,
					true
				);
				if (!empty($_COOKIE['remember'])) {
					setcookie('remember', '', time() - 42000, '/');
				}
			}
			session_destroy();
			session_start();
			$_SESSION['authorized'] = false;
			// fall through to check_auth → login form
		}
		else {
			$who = htmlspecialchars($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
			$skykin_domain = $_SESSION['user_context'] ?? ($_SESSION['domain_name'] ?? 'client1.skykin.local');
			$skykin_user   = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'agent');
			$home = PROJECT_PATH.'/core/dashboard/';
			if (!empty($_SESSION['groups'])) {
				foreach ($_SESSION['groups'] as $g) {
					$name = strtolower((string)($g['group_name'] ?? ''));
					if ($name === 'agent') {
						$home = '/app/agent_dashboard/index.php?agent=' . rawurlencode($skykin_user) . '&domain=' . rawurlencode($skykin_domain);
						break;
					}
					if ($name === 'supervisor') {
						$home = '/app/agent_dashboard/supervisor.php?domain=' . rawurlencode($skykin_domain);
						break;
					}
				}
			}
			header('Content-Type: text/html; charset=UTF-8');
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sky Connect – Signed in</title>';
			echo '<link rel="icon" type="image/png" sizes="32x32" href="/favicon.png">';
			echo '<link rel="shortcut icon" href="/favicon.ico">';
			echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f4f7fb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
			echo '.box{background:#fff;border-radius:12px;padding:32px;max-width:420px;width:90%;box-shadow:0 8px 28px rgba(0,0,0,.08);text-align:center}';
			echo 'h1{font-size:20px;color:#0047AB;margin:0 0 8px}p{color:#555;font-size:14px;margin:0 0 22px}';
			echo '.btn{display:inline-block;margin:6px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;border:0;cursor:pointer;font-family:inherit}';
			echo '.cont{background:#0047AB;color:#fff}.switch{background:#eef2f7;color:#333}';
			echo '.note{background:#fff8e1;color:#8a6d3b;border-radius:8px;padding:10px;font-size:13px;margin:0 0 18px}</style></head><body>';
			echo '<div class="box"><h1>Already signed in</h1>';
			if (isset($_GET['expired'])) {
				echo '<div class="note">A background request failed, but your session is still active.</div>';
			}
			echo '<p>You are logged in as <strong>'.$who.'</strong>.</p>';
			echo '<a class="btn cont" href="'.htmlspecialchars($home, ENT_QUOTES, 'UTF-8').'">Continue</a>';
			echo '<form method="post" action="/login.php" style="display:inline">';
			echo '<input type="hidden" name="switch" value="1">';
			echo '<button class="btn switch" type="submit">Sign in as different user</button>';
			echo '</form>';
			echo '<p style="margin:22px 0 0;font-size:11px;color:#aaa">Sky Connect &copy; '.date('Y').' | Powered by SkyKin Technology</p>';
			echo '</div></body></html>';
			exit;
		}
	}

//additional includes — shows login form when not authorized; redirects after successful login
	require_once "resources/check_auth.php";

//redirect to the dashboard (reached only when already authorized after login)
	header("Location: ".$settings->get('login', 'destination', PROJECT_PATH.'/core/dashboard/'));

?>
