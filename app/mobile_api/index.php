<?php
// SkyKin Technologies – Standalone Mobile Softphone API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__, 2) . '/resources/require.php';
require_once dirname(__DIR__, 2) . '/resources/pdo.php';

$action = $_GET['action'] ?? '';

// Helper function to resolve extension/SIP server IP
function get_server_ip() {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '192.168.1.10');
    return preg_replace('/:\d+$/', '', $host);
}

// ── 1. LOGIN / PROVISIONING ENDPOINT ───────────────────────────────────────
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $loginInput = trim($input['extension'] ?? $input['username'] ?? '');
    $password   = trim($input['password'] ?? '');

    if ($loginInput === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Extension/Username and password are required.']);
        exit;
    }

    // DEBUG: log what arrived (safe to remove once login is stable)
    error_log("[mobile_api/login] LOGIN ATTEMPT  input='{$loginInput}'  password_len=" . strlen($password));

    try {
        // PATH A: Match by SIP extension number -> v_extensions.password (plaintext)
        // FusionPBX stores SIP passwords in plaintext in v_extensions.password.
        // This path works even when v_extension_users has no link for the extension
        // (e.g. extensions 1001, 1002, 1004, 5000 which have no v_users row).
        $sqlExt = "SELECT e.extension, e.password AS sip_password, e.extension_uuid,
                          d.domain_name, d.domain_uuid,
                          u.username, u.user_uuid
                   FROM v_extensions e
                   LEFT JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                   LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                   LEFT JOIN v_users u ON u.user_uuid = eu.user_uuid
                   WHERE e.extension = :input";
        $stmtExt = $db->prepare($sqlExt);
        $stmtExt->execute([':input' => $loginInput]);
        $extRows = $stmtExt->fetchAll(PDO::FETCH_ASSOC);

        error_log("[mobile_api/login] PATH-A: found " . count($extRows) . " extension row(s) for '{$loginInput}'");

        $authenticatedUser = null;

        foreach ($extRows as $row) {
            $sipPass = $row['sip_password'] ?? '';
            error_log("[mobile_api/login] PATH-A: ext={$row['extension']}  domain={$row['domain_name']}  sip_pass_len=" . strlen($sipPass) . "  linked_user=" . ($row['username'] ?? 'NONE'));

            if ($sipPass === '') {
                error_log("[mobile_api/login] PATH-A: SKIP - no SIP password set");
                continue;
            }

            if ($password === $sipPass) {
                error_log("[mobile_api/login] PATH-A: SIP password MATCHED for extension {$row['extension']}");
                $authenticatedUser = $row;
                break;
            } else {
                error_log("[mobile_api/login] PATH-A: SIP password MISMATCH  received_len=" . strlen($password) . "  stored_len=" . strlen($sipPass));
            }
        }

        // PATH B: Fallback - match by web-portal username + hashed password
        // Used when someone logs in with their FusionPBX web username (e.g. admin, Agent1).
        if (!$authenticatedUser) {
            $sqlUser = "SELECT u.username, u.password AS password_hash, u.salt, u.user_uuid,
                               e.password AS sip_password, e.extension, e.extension_uuid,
                               d.domain_name, d.domain_uuid
                        FROM v_users u
                        LEFT JOIN v_extension_users eu ON eu.user_uuid = u.user_uuid
                        LEFT JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid
                        LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid
                        WHERE u.username = :input
                          AND (u.user_enabled = true OR u.user_enabled IS NULL)";
            $stmtUser = $db->prepare($sqlUser);
            $stmtUser->execute([':input' => $loginInput]);
            $userRows = $stmtUser->fetchAll(PDO::FETCH_ASSOC);

            error_log("[mobile_api/login] PATH-B: found " . count($userRows) . " user row(s) for username '{$loginInput}'");

            foreach ($userRows as $user) {
                $hash = $user['password_hash'] ?? '';
                $salt = $user['salt'] ?? '';

                error_log("[mobile_api/login] PATH-B: user={$user['username']}  hash_len=" . strlen($hash) . "  salt_len=" . strlen($salt) . "  linked_ext=" . ($user['extension'] ?? 'NONE'));

                if ($hash === '') {
                    error_log("[mobile_api/login] PATH-B: SKIP - empty password hash for user {$user['username']}");
                    continue;
                }

                $valid = false;
                if ($hash[0] === '$') {
                    $valid = password_verify($password, $hash);
                    error_log("[mobile_api/login] PATH-B: bcrypt verify=" . ($valid ? 'MATCH' : 'MISMATCH'));
                } else {
                    $computed = md5($salt . $password);
                    $valid = ($computed === $hash);
                    error_log("[mobile_api/login] PATH-B: md5 verify=" . ($valid ? 'MATCH' : 'MISMATCH') . "  computed=" . $computed);
                }

                if ($valid) {
                    $authenticatedUser = $user;
                    break;
                }
            }
        }

        if (!$authenticatedUser) {
            error_log("[mobile_api/login] RESULT: 401 - no path authenticated the credentials");
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials. Check your extension and password.']);
            exit;
        }

        if (empty($authenticatedUser['extension'])) {
            error_log("[mobile_api/login] RESULT: 400 - authenticated but no SIP extension linked");
            http_response_code(400);
            echo json_encode(['error' => 'Authenticated successfully, but this account has no SIP extension assigned.']);
            exit;
        }

        $domainOut = $authenticatedUser['domain_name'] ?: get_server_ip();
        error_log("[mobile_api/login] RESULT: 200 SUCCESS  ext={$authenticatedUser['extension']}  domain={$domainOut}  user=" . ($authenticatedUser['username'] ?? $loginInput));

        echo json_encode([
            'status'       => 'success',
            'username'     => $authenticatedUser['username'] ?? $loginInput,
            'extension'    => $authenticatedUser['extension'],
            'sip_password' => $authenticatedUser['sip_password'],
            'domain'       => $domainOut,
            'server_ip'    => get_server_ip()
        ]);
        exit;
    } catch (Exception $e) {
        error_log("[mobile_api/login] EXCEPTION: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ── 2. VOICEMAIL LIST ENDPOINT ─────────────────────────────────────────────
if ($action === 'voicemails') {
    $extension = $_GET['extension'] ?? '';
    if ($extension === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Extension is required.']);
        exit;
    }

    try {
        $sql = "SELECT m.voicemail_message_uuid, m.created_epoch, m.caller_id_name, 
                       m.caller_id_number, m.message_length, m.message_status
                FROM v_voicemail_messages m
                JOIN v_voicemails v ON v.voicemail_uuid = m.voicemail_uuid
                WHERE v.voicemail_id = :extension
                ORDER BY m.created_epoch DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':extension' => $extension]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $voicemails = [];
        foreach ($rows as $row) {
            $voicemails[] = [
                'uuid'         => $row['voicemail_message_uuid'],
                'date'         => date('Y-m-d H:i:s', $row['created_epoch']),
                'caller_name'  => $row['caller_id_name'] ?: 'Unknown',
                'caller_num'   => $row['caller_id_number'] ?: 'Unknown',
                'duration'     => (int)$row['message_length'],
                'status'       => $row['message_status'] ?: 'New',
                'download_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/app/mobile_api/index.php?action=download_voicemail&uuid=' . $row['voicemail_message_uuid']
            ];
        }

        echo json_encode($voicemails);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ── 3. VOICEMAIL DOWNLOAD / STREAM ENDPOINT ────────────────────────────────
if ($action === 'download_voicemail') {
    $uuid = $_GET['uuid'] ?? '';
    if (!is_uuid($uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid voicemail UUID.']);
        exit;
    }

    try {
        $sql = "SELECT m.message_base64, m.domain_uuid, v.voicemail_id, d.domain_name
                FROM v_voicemail_messages m
                JOIN v_voicemails v ON v.voicemail_uuid = m.voicemail_uuid
                JOIN v_domains d ON d.domain_uuid = m.domain_uuid
                WHERE m.voicemail_message_uuid = :uuid";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Voicemail message not found.']);
            exit;
        }

        // Clean output buffers to prevent corruption
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Try streaming from base64 first (if stored in database)
        if (!empty($row['message_base64'])) {
            $binary = base64_decode($row['message_base64']);
            header('Content-Type: audio/mpeg');
            header('Content-Length: ' . strlen($binary));
            header('Cache-Control: private, no-store');
            echo $binary;
            exit;
        }

        // Otherwise, locate and stream from files storage disk
        $voicemail_dir = realpath($_SESSION['switch']['voicemail']['dir'] ?? '/var/lib/freeswitch/storage/voicemail');
        if (!$voicemail_dir) {
            $voicemail_dir = '/var/lib/freeswitch/storage/voicemail';
        }
        
        $dir = $voicemail_dir . '/default/' . $row['domain_name'] . '/' . $row['voicemail_id'];
        $file_path = '';
        foreach (['mp3', 'wav', 'ogg'] as $ext) {
            $test_path = $dir . '/msg_' . $uuid . '.' . $ext;
            if (file_exists($test_path)) {
                $file_path = $test_path;
                break;
            }
        }

        if ($file_path && file_exists($file_path)) {
            $ext = pathinfo($file_path, PATHINFO_EXTENSION);
            $mime = 'audio/mpeg';
            if ($ext === 'wav') $mime = 'audio/wav';
            if ($ext === 'ogg') $mime = 'audio/ogg';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($file_path));
            header('Cache-Control: private, no-store');
            readfile($file_path);
            exit;
        }

        // If not found in base64 or file path
        http_response_code(404);
        echo json_encode(['error' => 'Audio storage file not found.']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error fetching audio: ' . $e->getMessage()]);
        exit;
    }
}

// ── 4. OUTBOUND SMS ENDPOINT (AFRICA'S TALKING) ────────────────────────────
if ($action === 'send_sms') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $to      = trim($input['to'] ?? '');
    $message = trim($input['message'] ?? '');

    if ($to === '' || $message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Destination (to) and message text are required.']);
        exit;
    }

    // Call existing sendSms function from web dashboard codebase (loaded via require)
    // To ensure sendSms is accessible, we declare or load the dashboard index.php function
    // For cleaner code, we define a small wrapper that imports environment config
    try {
        if (!function_exists('sendSms')) {
            // Include index.php from dashboard where sendSms resides
            // To prevent executing HTML output of dashboard, we extract or duplicate sendSms wrapper
            // Let's implement our own sendSms inside mobile_api context to avoid output buffers conflict
            require_once dirname(__DIR__) . '/agent_dashboard/skykin_config.php';
        }

        // Re-use or define local sendSms helper
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_sms_logs (
                sms_id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT,
                message TEXT,
                status TEXT DEFAULT 'Logged',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_sms_logs (
                sms_id SERIAL PRIMARY KEY,
                phone_number VARCHAR(50),
                message TEXT,
                status VARCHAR(50) DEFAULT 'Logged',
                created_at TIMESTAMP DEFAULT NOW()
            )");
        }

        $s = $db->prepare("INSERT INTO skykin_sms_logs (phone_number, message, status) VALUES (:phone, :msg, :status)");
        $s->execute([':phone' => $to, ':msg' => $message, ':status' => 'Pending']);
        $smsId = $db->lastInsertId();

        $apiKey   = getenv('SMS_API_KEY');
        $username = getenv('SMS_API_USERNAME');
        $senderId = getenv('SMS_SENDER_ID');

        if (!$apiKey || !$username) {
            foreach ([dirname(__DIR__, 2) . '/.env', dirname(__DIR__) . '/.env', __DIR__ . '/.env'] as $envPath) {
                if (file_exists($envPath)) {
                    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value, " \t\n\r\0\x0B\"'");
                        if ($name === 'SMS_API_KEY') $apiKey = $value;
                        if ($name === 'SMS_API_USERNAME') $username = $value;
                        if ($name === 'SMS_SENDER_ID') $senderId = $value;
                    }
                    break;
                }
            }
        }

        if (!$apiKey || !$username) {
            throw new Exception("SMS credentials not configured. Please define SMS_API_KEY and SMS_API_USERNAME in .env");
        }

        $isSandbox = (strtolower($username) === 'sandbox');
        $endpoint = $isSandbox 
            ? 'https://api.sandbox.africastalking.com/version1/messaging' 
            : 'https://api.africastalking.com/version1/messaging';

        $postData = [
            'username' => $username,
            'to'       => $to,
            'message'  => $message
        ];
        if (!empty($senderId)) {
            $postData['from'] = $senderId;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apikey: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $finalStatus = "Sent";
        if ($httpCode < 200 || $httpCode >= 300) {
            $finalStatus = "Error: HTTP Code " . $httpCode;
        }

        $update = $db->prepare("UPDATE skykin_sms_logs SET status = :status WHERE sms_id = :id");
        $update->execute([':status' => $finalStatus, ':id' => $smsId]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'SMS request processed.',
            'api_response' => json_decode($resp, true) ?: $resp
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'SMS sending failed: ' . $e->getMessage()]);
        exit;
    }
}

// ── 5. SMS HISTORY LOGS ──────────────────────────────────────────────────
if ($action === 'sms_logs') {
    try {
        $sql = "SELECT sms_id, phone_number, message, status, created_at FROM skykin_sms_logs ORDER BY created_at DESC LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Default error route
http_response_code(400);
echo json_encode(['error' => 'Unknown mobile API action.']);
exit;
