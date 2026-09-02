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

//class auto loader
	if (!class_exists('auto_loader')) {
		require_once __DIR__ . "/classes/auto_loader.php";
		$autoload = new auto_loader();
	}

//load config file
	global $config;
	$config = config::load();

//config.conf file not found re-direct the request to the install
	if ($config->is_empty()) {
		header("Location: /core/install/install.php");
		exit;
	}

//compatibility settings - planned to deprecate
	global $conf, $db_type, $db_host, $db_port, $db_name, $db_username, $db_password;
	$conf = $config->configuration();
	$db_type = $config->get('database.0.type');
	$db_host = $config->get('database.0.host');
	$db_port = $config->get('database.0.port');
	$db_name = $config->get('database.0.name');
	$db_username = $config->get('database.0.username');
	$db_password = $config->get('database.0.password');

//set the error reporting
	ini_set('display_errors', '1');
	$error_reporting_scope = $config->get('error.reporting', 'user');
	switch ($error_reporting_scope) {
	case 'user':
		error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING ^ E_DEPRECATED);
		break;
	case 'dev':
		error_reporting(E_ALL ^ E_NOTICE);
		break;
	case 'all':
		error_reporting(E_ALL);
		break;
	default:
		error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING ^ E_DEPRECATED);
	}

//set runtime ini settings
	$max_input_vars = $config->get('php.max_input_vars', '15000');
	ini_set('max_input_vars', $max_input_vars);

//debug info
	//echo "Include Path: ".get_include_path()."\n";
	//echo "Document Root: ".dirname(__DIR__, 1)."\n";
	//echo "Project Root: ".dirname(__DIR__, 1)."\n";

//include global functions
	require_once __DIR__ . "/functions.php";

//connect to the database
	global $database;
	$database = database::new(['config' => $config]);

//security headers
	if (!defined('STDIN') && session_status() === PHP_SESSION_NONE) {
		header("X-Frame-Options: SAMEORIGIN");
		header("Content-Security-Policy: frame-ancestors 'self';");
		header("X-Content-Type-Options: nosniff");
		header("Referrer-Policy: strict-origin-when-cross-origin");
		//header("Strict-Transport-Security: max-age=63072000; includeSubDomains; preload");
	}

//start the session if not using the command line
	global $no_session;
	if (!defined('STDIN') && empty($no_session) && session_status() === PHP_SESSION_NONE) {
		// Keep sessions alive for 8 hours of inactivity (default PHP is only 24 minutes)
		ini_set('session.gc_maxlifetime', '28800');

		// Reject client-supplied session ids that were never issued by this server,
		// so one browser can never adopt another browser's session id.
		ini_set('session.use_strict_mode', '1');

		$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

		// Use 0/1 — PHP treats the string "false" as enabling boolean ini settings.
		ini_set('session.cookie_httponly', '1');
		// Never force Secure on plain HTTP (config.conf may say true; that breaks HTTP logins).
		ini_set('session.cookie_secure', $https ? '1' : '0');
		ini_set('session.cookie_samesite', $config->get('session.cookie_samesite', 'Lax') ?: 'Lax');

		$cookie_params = session_get_cookie_params();
		session_set_cookie_params([
			'lifetime' => 0,
			'path' => $cookie_params['path'] ?? '/',
			'domain' => $cookie_params['domain'] ?? '',
			'secure' => $https,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();
	}

//get the domain_name and the domain_uuid
	if (empty($_SESSION['domain_uuid'])) {
		//get the domain from the url
		$domain_name = $_SERVER["HTTP_HOST"];

		//get the domain name from the http value
		if (!empty($_REQUEST["domain_name"])) {
			$domain_name = $_REQUEST["domain_name"];
		}

		//remote port number from the domain name
		$domain_array = explode(":", $domain_name);
		if (count($domain_array) > 1) {
			$domain_name = $domain_array[0];
		}

		//get the domain_uuid from the database
		$sql = "select domain_uuid from v_domains \n";
		$sql .= "where domain_name = :domain_name \n";
		$parameters['domain_name'] = $domain_name;
		$row = $database->select($sql, $parameters, 'row');
		$domain_uuid = '';
		if (is_array($row) && sizeof($row) != 0) {
			$domain_uuid = $row['domain_uuid'];
			if (session_status() === PHP_SESSION_ACTIVE) {
				$_SESSION['domain_uuid'] = $domain_uuid;
				$_SESSION['domain_name'] = $domain_name;
			}
		}
		unset($parameters, $row);
	}

//load settings
	global $settings;
	$settings = new settings(['database' => $database, 'domain_uuid' => $_SESSION['domain_uuid'] ?? $domain_uuid, 'user_uuid' => $_SESSION['user_uuid'] ?? '']);

//check if the cidr range is valid
	global $no_cidr;
	if (!defined('STDIN') && empty($no_cidr)) {
		require_once __DIR__ . '/cidr.php';
	}

//include switch functions when available
	if (file_exists(__DIR__ . '/switch.php')) {
		require_once __DIR__ . '/switch.php';
	}

//change language on the fly - for translate tool (if available)
	//if (!defined('STDIN') && isset($_REQUEST['view_lang_code']) && ($_REQUEST['view_lang_code']) != '') {
	//	$_SESSION['domain']['language']['en-us'] = $_REQUEST['view_lang_code'];
	//}

//change the domain
	if (!empty($_GET["domain_uuid"]) && is_uuid($_GET["domain_uuid"]) && !empty($_GET["domain_change"]) && $_GET["domain_change"] == "true" && permission_exists('domain_select')) {

		//include domains
			if (file_exists(dirname(__DIR__, 1)."/app/domains/app_config.php") && !permission_exists('domain_all')) {
				include_once "app/domains/domains.php";
			}

		//update the domain session variables
			$domain_uuid = $_GET["domain_uuid"];
			$_SESSION["previous_domain_uuid"] = $_SESSION['domain_uuid'];
			$_SESSION['domain_uuid'] = $domain_uuid;

		//get the domain details
			$sql = "select * from v_domains ";
			$sql .= "order by domain_name asc ";
			$domains = $database->select($sql, null, 'all');
			if (!empty($domains)) {
				foreach($domains as $row) {
					$_SESSION['domains'][$row['domain_uuid']] = $row;
				}
			}
			unset($sql, $domains);

		//update the domain session variables
			$_SESSION["domain_name"] = $_SESSION['domains'][$domain_uuid]['domain_name'];
			$_SESSION["context"] = $_SESSION["domain_name"];

		//clear the extension array so that it is regenerated for the selected domain
			unset($_SESSION['extension_array']);

		//set the setting arrays
			$domain = new domains();
			$domain->set();
	}

// SkyKin: ensure favicon on every FusionPBX HTML page
	if (is_file(__DIR__ . '/skykin_favicon.php')) {
		require_once __DIR__ . '/skykin_favicon.php';
	}
