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
	Portions created by the Initial Developer are Copyright (C) 2008-2025
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
    require_once __DIR__ . "/require.php";

//add multi-lingual support
	$language = new text;
	$text = $language->get(null, 'resources');

//for compatibility, require this library if less than version 5.5
	if (version_compare(phpversion(), '5.5', '<')) {
		require_once "resources/functions/password.php";
	}

//start the session
	if (function_exists('session_start')) {
		if (!isset($_SESSION)) {
			session_start();
		}
	}


// SkyKin: development auto-login bypass
	$dev_auto_login = false;
	if (getenv('DEV_AUTO_LOGIN') === 'true') {
		$dev_auto_login = true;
	} else {
		$env_file = dirname(__DIR__) . '/.env';
		if (file_exists($env_file)) {
			foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
				$line = trim($line);
				if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
					list($key, $val) = array_map('trim', explode('=', $line, 2));
					$val = trim($val, " \t\n\r\0\x0B\"'");
					if ($key === 'DEV_AUTO_LOGIN' && strtolower($val) === 'true') {
						$dev_auto_login = true;
						break;
					}
				}
			}
		}
	}

	if ($dev_auto_login && (empty($_SESSION['authorized']) || !$_SESSION['authorized'])) {
		require_once __DIR__ . "/pdo.php";
		try {
			// Find the domain and user info for Agent1
			$sql = "SELECT u.user_uuid, u.username, d.domain_uuid, d.domain_name, u.contact_uuid
					FROM v_users u
					JOIN v_domains d ON d.domain_uuid = u.domain_uuid
					WHERE LOWER(u.username) = LOWER('Agent1') LIMIT 1";
			$s = $db->prepare($sql);
			$s->execute();
			$user_row = $s->fetch(PDO::FETCH_ASSOC);

			// Safe UUID generator helper (global uuid() function fails on Windows dev environments without COM extension)
			$get_uuid_safe = function() {
				$u = uuid();
				if (!empty($u)) { return $u; }
				$data = random_bytes(16);
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
				return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
			};

			if (!$user_row) {
				// Find first domain from v_domains
				$sql = "SELECT domain_uuid, domain_name FROM v_domains LIMIT 1";
				$s = $db->prepare($sql);
				$s->execute();
				$domain_row = $s->fetch(PDO::FETCH_ASSOC);
				if ($domain_row) {
					$domain_uuid = $domain_row['domain_uuid'];
					$domain_name = $domain_row['domain_name'];
				} else {
					$domain_uuid = '86970909-efb7-4b70-b271-14c9bc5ad619';
					$domain_name = '192.168.0.114';
				}

				// Create Agent1 user
				$user_uuid = $get_uuid_safe();
				$sql = "INSERT INTO v_users (user_uuid, domain_uuid, username, user_enabled)
						VALUES (:user_uuid, :domain_uuid, 'Agent1', true)";
				$s = $db->prepare($sql);
				$s->execute([
					':user_uuid' => $user_uuid,
					':domain_uuid' => $domain_uuid
				]);

				// Find agent group
				$sql = "SELECT group_uuid FROM v_groups WHERE group_name = 'agent' LIMIT 1";
				$s = $db->prepare($sql);
				$s->execute();
				$group_row = $s->fetch(PDO::FETCH_ASSOC);
				$group_uuid = $group_row ? $group_row['group_uuid'] : 'f18aff85-23fe-471d-8e00-d72c6654b57a';

				// Associate user with agent group
				$sql = "INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_uuid, user_uuid, group_name)
						VALUES (:user_group_uuid, :domain_uuid, :group_uuid, :user_uuid, 'agent')";
				$s = $db->prepare($sql);
				$s->execute([
					':user_group_uuid' => $get_uuid_safe(),
					':domain_uuid' => $domain_uuid,
					':group_uuid' => $group_uuid,
					':user_uuid' => $user_uuid
				]);

				// Try to map to extension 1003 if it exists
				$sql = "SELECT extension_uuid FROM v_extensions WHERE extension = '1003' LIMIT 1";
				$s = $db->prepare($sql);
				$s->execute();
				$ext_row = $s->fetch(PDO::FETCH_ASSOC);
				if ($ext_row) {
					$sql = "INSERT INTO v_extension_users (extension_user_uuid, domain_uuid, extension_uuid, user_uuid)
							VALUES (:extension_user_uuid, :domain_uuid, :extension_uuid, :user_uuid)";
					$s = $db->prepare($sql);
					$s->execute([
						':extension_user_uuid' => $get_uuid_safe(),
						':domain_uuid' => $domain_uuid,
						':extension_uuid' => $ext_row['extension_uuid'],
						':user_uuid' => $user_uuid
					]);
				}

				$user_row = [
					'user_uuid' => $user_uuid,
					'username' => 'Agent1',
					'domain_uuid' => $domain_uuid,
					'domain_name' => $domain_name,
					'contact_uuid' => null
				];
			}

			// Establish FusionPBX user session
			$settings = new settings(['database' => $database, 'domain_uuid' => $user_row['domain_uuid'], 'user_uuid' => $user_row['user_uuid']]);
			authentication::create_user_session($user_row, $settings);
			$_SESSION['authorized'] = true;

			// Determine redirect path
			$redirect_path = '/app/agent_dashboard/index.php';
			if (!empty($_REQUEST['path'])) {
				$parsed_url = parse_url($_REQUEST['path']);
				if (empty($parsed_url['host'])) {
					$redirect_path = $_REQUEST['path'];
				}
			}

			// Append agent/domain parameters for agent dashboard
			if (strpos($redirect_path, '/app/agent_dashboard/index.php') !== false) {
				$sep = (strpos($redirect_path, '?') === false) ? '?' : '&';
				if (strpos($redirect_path, 'agent=') === false) {
					$redirect_path .= $sep . 'agent=Agent1';
					$sep = '&';
				}
				if (strpos($redirect_path, 'domain=') === false) {
					$redirect_path .= $sep . 'domain=' . rawurlencode($user_row['domain_name']);
				}
			}

			header("Location: " . $redirect_path);
			exit;

		} catch (Exception $e) {
			// Fail silently or log error for local debug
			error_log("DEV_AUTO_LOGIN failed: " . $e->getMessage());
		}
	}

	//regenerate sessions to avoid session ID attacks, such as session fixation
	if (isset($_SESSION['authorized']) && $_SESSION['authorized']) {
		//set the last activity time
		$_SESSION['session']['last_activity'] = time();

		//if the session created is not set, then set the time
		if (!isset($_SESSION['session']['created'])) {
			$_SESSION['session']['created'] = time();
		}

		// Rotate session id every 8 hours (was 15 minutes with delete-old, which
		// logged users out when switching browser tabs). Keep old session briefly
		// so other open tabs do not suddenly lose auth.
		if (time() - $_SESSION['session']['created'] > 28800) {

			//build the user log array
			$log_array['domain_uuid'] = $_SESSION['user']['domain_uuid'];
			$log_array['domain_name'] = $_SESSION['user']['domain_name'];
			$log_array['username'] = $_SESSION['user']['username'];
			$log_array['user_uuid'] = $_SESSION['user']['user_uuid'];
			$log_array['authorized'] = true;

			//session started more than 8 hours ago
			session_regenerate_id(false);

			// update creation time
			$_SESSION['session']['created'] = time();

			//add the result to the user logs
			user_logs::add($log_array);
		}
	}

//set the domains session
	if (!isset($_SESSION['domains'])) {
		$domain = new domains();
		$domain->session();
		$domain->set();
	}

//set the domain_uuid variable from the session
	if (!empty($_SESSION["domain_uuid"])) {
		$domain_uuid = $_SESSION["domain_uuid"];
	}

//define variables
	if (!isset($_SESSION['template_content'])) { $_SESSION["template_content"] = null; }

//if session authorized is not set, then set the default value to false
	if (!isset($_SESSION['authorized'])) {
		$_SESSION['authorized'] = false;
	}

//session validate: use HTTP_USER_AGENT as a default value
	if (!isset($conf['session.validate'])) {
		$conf['session.validate'][] = 'HTTP_USER_AGENT';
	}

//session validate: prepare the server array
	foreach($conf['session.validate'] as $name) {
		$server_array[$name] = $_SERVER[$name];
	}
	unset($name);

//session validate: check to see if the session is valid
	if ($_SESSION['authorized'] && $_SESSION["user_hash"] !== hash('sha256', implode($server_array))) {
		require_once __DIR__ . "/skykin_session_log.php";
		skykin_session_log('user_hash_mismatch', ['reason' => 'session.validate hash changed']);
		session_destroy();
		header("Location: ".PROJECT_PATH."/logout.php");
	}

//if the session is not authorized, then verify the identity
	if (!$_SESSION['authorized']) {

		//record every unauthorized page hit (temporary diagnostics)
			require_once __DIR__ . "/skykin_session_log.php";
			skykin_session_log('session_not_authorized', [
				'cookie_sent' => isset($_COOKIE[session_name()]) ? '1' : '0',
				'cookie_sid'  => $_COOKIE[session_name()] ?? '-',
				'sess_keys'   => implode(',', array_slice(array_keys($_SESSION), 0, 10)),
			]);

		//clear the template only if the template has not been assigned by the superadmin
			if (empty($settings->get('domain', 'template'))) {
				$_SESSION["template_content"] = '';
			}

		//validate the username and password
			$auth = new authentication(['settings' => $settings]);
			$result = $auth->validate();

			//if not authorized
			if (empty($_SESSION['authorized']) || !$_SESSION['authorized']) {
				//record why the login page is being shown (temporary diagnostics)
				require_once __DIR__ . "/skykin_session_log.php";
				skykin_session_log('not_authorized', [
					'cookie_sent' => isset($_COOKIE[session_name()]) ? '1' : '0',
					'cookie_sid'  => $_COOKIE[session_name()] ?? '-',
					'sess_keys'   => implode(',', array_slice(array_keys($_SESSION), 0, 8)),
				]);

				//log the failed auth attempt to the system to the syslog server
				openlog('FusionPBX', LOG_NDELAY, LOG_AUTH);
				syslog(LOG_WARNING, '['.$_SERVER['REMOTE_ADDR']."] authentication failed for ".$result["username"]);
				closelog();

				//redirect the user to the login page
				$target_path = !empty($_REQUEST["path"]) ? $_REQUEST["path"] : $_SERVER["PHP_SELF"];
				message::add($text['message-authentication_failed'], 'negative');
				header("Location: ".PROJECT_PATH."/?path=".urlencode($target_path));
				exit;
			}

		//clear the menu
			unset($_SESSION["menu"]);

		//get settings based on the user
			$settings = new settings(['database' => $database, 'domain_uuid' => $_SESSION['domain_uuid'], 'user_uuid' => $_SESSION['user_uuid']]);
			settings::clear_cache();

		//if logged in, redirect to login destination
			if (!isset($_REQUEST["key"]) && !isset($_COOKIE['remember'])) {
				//redirect the user
				if (isset($_SESSION['redirect_path'])) {
					$redirect_path = $_SESSION['redirect_path'];
					unset($_SESSION['redirect_path']);

					// prevent open redirect attacks. The redirect URL shouldn't contain a hostname
					$parsed_url = parse_url($redirect_path);
					if ($parsed_url['host']) {
						die("Was someone trying to hack you?");
					}
					header("Location: ".$redirect_path);
					exit;
				}

				// SkyKin: role-based landing after login
				if (!empty($_SESSION['groups'])) {
					$skykin_domain = $_SESSION['user_context'] ?? ($_SESSION['domain_name'] ?? 'client1.skykin.local');
					$skykin_user   = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'agent');
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

				if (!empty($settings->get('login', 'destination', ''))) {
					header("Location: ".$settings->get('login', 'destination', ''));
					exit;
				}
				elseif (file_exists(dirname(__DIR__, 1)."/core/dashboard/app_config.php")) {
					header("Location: ".PROJECT_PATH."/core/dashboard/");
					exit;
				}
				else {
					require_once "resources/header.php";
					require_once "resources/footer.php";
				}
			}

	}

?>
