<?php
// SkyKin Technologies – Supervisor Dashboard

// ── FusionPBX session bootstrap ──────────────────────────────────────────────
// Share FusionPBX session by using its session name and save path
$fpbx_session_path = '/var/lib/php/sessions';
if (is_dir($fpbx_session_path)) session_save_path($fpbx_session_path);
session_name('PHPSESSID');
session_start();

// ── Auth check ────────────────────────────────────────────────────────────────
// ── Auth check ────────────────────────────────────────────────────────────────
// API endpoints must return JSON on expiry (not an HTML login redirect),
// otherwise Live Agent Status polling silently dies.
$is_api = isset($_GET['action']);
if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
    if ($is_api) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Session expired','login'=>'/']);
        exit;
    }
    $path = $_SERVER['REQUEST_URI'] ?? '/app/agent_dashboard/supervisor.php';
    header('Location: /?path=' . urlencode($path));
    exit;
}

// Allowed roles — superadmin, admin, or supervisor (custom group) can open this page
$allowed_groups = ['superadmin', 'admin', 'supervisor'];
$raw_groups     = isset($_SESSION['groups']) ? $_SESSION['groups'] : [];

// FusionPBX stores groups in various formats — flatten everything to a simple string array
$user_groups = [];
array_walk_recursive($raw_groups, function($val) use (&$user_groups) {
    if (is_string($val)) {
        foreach (array_map('trim', explode(',', $val)) as $g) {
            if ($g !== '') $user_groups[] = strtolower($g);
        }
    }
});
$allowed_lower = array_map('strtolower', $allowed_groups);
$has_access = !empty(array_intersect($allowed_lower, $user_groups));

if (!$has_access) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Access Denied – SkyKin</title>
    <style>body{font-family:Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5;margin:0}
    .box{background:#fff;padding:40px 48px;border-radius:14px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)}
    h2{color:#c62828;margin-bottom:10px} p{color:#666;font-size:14px} a{color:#0047AB;font-size:13px}
    .badge{background:#ffebee;color:#c62828;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;display:inline-block;margin-bottom:16px}
    </style></head><body><div class="box">
    <div class="badge">ACCESS DENIED</div>
    <h2>Supervisor Access Required</h2>
    <p>Your account (<strong>'.htmlspecialchars($_SESSION['username'] ?? 'Unknown').'</strong>) does not have the <strong>supervisor</strong> or <strong>superadmin</strong> role.</p>
    <p style="margin-top:8px;font-size:12px;color:#aaa">Ask your administrator to assign you the <strong>admin</strong> or <strong>supervisor</strong> group in FusionPBX &rarr; Accounts &rarr; Users.</p>
    <br><a href="/app/agent_dashboard/index.php">Go to Agent Dashboard</a> &nbsp;|&nbsp; <a href="/logout.php">Login as different user</a>
    </div></body></html>';
    exit;
}

// ── Pull identity from session ────────────────────────────────────────────────
$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = $_SESSION['domain_name'] ?? 'client1.skykin.local';

$domain  = isset($_GET['domain']) ? htmlspecialchars($_GET['domain'])  : $logged_in_domain;
$sup_ext = isset($_GET['ext'])    ? htmlspecialchars($_GET['ext'])     : '';

// Auto-detect supervisor's own extension from their username
if (!$sup_ext && $logged_in_user) {
    try {
        $db_se = getDB();
        $se = $db_se->prepare("
            SELECT e.extension FROM v_extensions e
            JOIN v_extension_users eu ON eu.extension_uuid=e.extension_uuid
            JOIN v_users u ON u.user_uuid=eu.user_uuid
            JOIN v_domains d ON d.domain_uuid=e.domain_uuid
            WHERE u.username=:u AND d.domain_name=:d LIMIT 1");
        $se->execute([':u'=>$logged_in_user,':d'=>$domain]);
        $row = $se->fetch(PDO::FETCH_ASSOC);
        if ($row) $sup_ext = $row['extension'];
    } catch(Exception $ignored){}
}
$today   = date('Y-m-d');

// Fetch agent list for Agent View dropdown
$nav_agents = [];
try {
    $db_nav = getDB();
    $sn = $db_nav->prepare("
        SELECT e.extension, COALESCE(NULLIF(e.effective_caller_id_name,''), 'Extension '||e.extension) as name,
               u.username
        FROM v_extensions e
        JOIN v_domains d ON d.domain_uuid = e.domain_uuid
        LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
        LEFT JOIN v_users u ON u.user_uuid = eu.user_uuid
        LEFT JOIN v_user_groups ug ON ug.user_uuid = u.user_uuid
        LEFT JOIN v_groups g ON g.group_uuid = ug.group_uuid
        WHERE d.domain_name = :d
        AND (g.group_name IS NULL OR LOWER(g.group_name) NOT IN ('superadmin','admin','supervisor'))
        ORDER BY e.extension");
    $sn->execute([':d' => $domain]);
    $nav_agents = $sn->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* silent */ }

// ── DB helper ────────────────────────────────────────────────────────────────
function getDB() {
    static $db = null;
    if ($db) return $db;
    $h='127.0.0.1';$p='5432';$n='fusionpbx';$u='fusionpbx';$pw='';
    $conf='/etc/fusionpbx/config.conf';
    if (file_exists($conf)) foreach(file($conf) as $ln) {
        $ln=trim($ln);
        if(strpos($ln,'database.0.host')!==false)     $h=trim(explode('=',$ln,2)[1]);
        if(strpos($ln,'database.0.port')!==false)     $p=trim(explode('=',$ln,2)[1]);
        if(strpos($ln,'database.0.name')!==false)     $n=trim(explode('=',$ln,2)[1]);
        if(strpos($ln,'database.0.username')!==false) $u=trim(explode('=',$ln,2)[1]);
        if(strpos($ln,'database.0.password')!==false) $pw=trim(explode('=',$ln,2)[1]);
    }
    try { $db=new PDO("pgsql:host={$h};port={$p};dbname={$n}",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    catch(Exception $e) { $db=new PDO("pgsql:host=/var/run/postgresql;dbname=fusionpbx",'fusionpbx','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    return $db;
}

function ensureLeaveRequestsTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS skykin_leave_requests (
        id SERIAL PRIMARY KEY,
        domain VARCHAR(255) NOT NULL,
        agent_ext VARCHAR(50) NOT NULL,
        agent_name VARCHAR(255),
        request_type VARCHAR(50) NOT NULL,
        reason TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT NOW(),
        resolved_at TIMESTAMP,
        resolved_by VARCHAR(255)
    )");
}

/** Apply call-center status via DB + ESL (used by force_status and leave approve). */
function applyAgentCcStatus($db, $agent_ext, $new_status, $domain_) {
    $allowed = ['Available', 'Available (On Demand)', 'On Break', 'Logged Out'];
    if (!in_array($new_status, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid status: ' . $new_status];
    }
    $s_dom = $db->prepare("SELECT domain_uuid FROM v_domains WHERE domain_name = :d LIMIT 1");
    $s_dom->execute([':d' => $domain_]);
    $domain_uuid = $s_dom->fetchColumn();
    if (!$domain_uuid) {
        $domain_uuid = $db->query("SELECT domain_uuid FROM v_domains LIMIT 1")->fetchColumn();
    }
    if (!$domain_uuid) {
        return ['ok' => false, 'error' => 'No domain found'];
    }
    $pat = '%/' . $agent_ext . '@%';
    $s_agent = $db->prepare(
        "SELECT call_center_agent_uuid, agent_name
         FROM v_call_center_agents
         WHERE (agent_id = :ext OR agent_contact LIKE :pat OR agent_name = :ext)
           AND domain_uuid = :domain_uuid
         LIMIT 1"
    );
    $s_agent->execute([':ext' => $agent_ext, ':pat' => $pat, ':domain_uuid' => $domain_uuid]);
    $agent_row = $s_agent->fetch(PDO::FETCH_ASSOC);
    if (!$agent_row) {
        return ['ok' => false, 'error' => 'No Call Center agent found for ext ' . $agent_ext];
    }
    $agent_uuid = $agent_row['call_center_agent_uuid'];
    $s_upd = $db->prepare("UPDATE v_call_center_agents SET agent_status = :s WHERE call_center_agent_uuid = :uuid");
    $s_upd->execute([':s' => $new_status, ':uuid' => $agent_uuid]);

    $esl_host = '127.0.0.1';
    $esl_port = 8021;
    $esl_pass = 'ClueCon';
    foreach ([__DIR__ . '/../../.env', __DIR__ . '/../.env', __DIR__ . '/.env'] as $envPath) {
        if (file_exists($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                $ln = trim($ln);
                if (strpos($ln, '#') === 0 || strpos($ln, '=') === false) continue;
                [$k, $v] = explode('=', $ln, 2);
                $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
                if ($k === 'ESL_HOST')     $esl_host = $v;
                if ($k === 'ESL_PORT')     $esl_port = intval($v);
                if ($k === 'ESL_PASSWORD') $esl_pass = $v;
            }
            break;
        }
    }
    $esl_connected = false;
    $esl_response = '';
    $esl_error = '';
    try {
        if (!class_exists('config'))       require_once __DIR__ . '/../../resources/classes/config.php';
        if (!class_exists('event_socket')) require_once __DIR__ . '/../../resources/classes/event_socket.php';
        $esl = new event_socket();
        if ($esl->connect($esl_host, $esl_port, $esl_pass)) {
            $esl_connected = true;
            $res = $esl->request('api callcenter_config agent set status ' . $agent_uuid . " '" . $new_status . "'");
            $esl_response = is_array($res) ? ($res['$'] ?? implode(' | ', $res)) : (string)$res;
            if ($new_status === 'Available' || $new_status === 'Logged Out') {
                $esl->request('api callcenter_config agent set state ' . $agent_uuid . " 'Waiting'");
            }
        } else {
            $esl_error = 'ESL connect failed';
        }
    } catch (Throwable $ex) {
        $esl_error = $ex->getMessage();
    }
    return [
        'ok' => true,
        'agent_name' => $agent_row['agent_name'],
        'status' => $new_status,
        'esl_connected' => $esl_connected,
        'esl_response' => trim($esl_response),
        'esl_error' => $esl_error,
    ];
}

// ── API: agents ──────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='agents') {
    error_reporting(0); header('Content-Type: application/json');
    $domain = $_GET['domain'] ?? 'client1.skykin.local';
    $today_start = strtotime(date('Y-m-d').' 00:00:00');
    $today_end   = strtotime(date('Y-m-d').' 23:59:59');
    try {
        $db = getDB();
        // All extensions in domain — join v_domains since v_extensions uses domain_uuid
        $s = $db->prepare("SELECT e.extension, e.effective_caller_id_name
            FROM v_extensions e
            JOIN v_domains d ON d.domain_uuid = e.domain_uuid
            WHERE d.domain_name=:d ORDER BY e.extension");
        $s->execute([':d'=>$domain]);
        $allExts = $s->fetchAll(PDO::FETCH_ASSOC);

        // Get extensions belonging to supervisor/admin users — exclude them from agent cards
        $supervisorExts = [];
        try {
            $sx = $db->prepare("SELECT e.extension FROM v_extensions e
                JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                JOIN v_users u ON u.user_uuid = eu.user_uuid
                JOIN v_user_groups ug ON ug.user_uuid = u.user_uuid
                JOIN v_groups g ON g.group_uuid = ug.group_uuid
                JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                WHERE d.domain_name=:d AND LOWER(g.group_name) IN ('superadmin','admin','supervisor')");
            $sx->execute([':d'=>$domain]);
            foreach($sx->fetchAll(PDO::FETCH_ASSOC) as $r) $supervisorExts[$r['extension']] = true;
        } catch(Exception $ignored){}

        // Filter out supervisor/admin extensions
        $exts = array_filter($allExts, function($e) use ($supervisorExts) {
            return !isset($supervisorExts[$e['extension']]);
        });

        // Today's CDR stats per extension — resolve SIP usernames to extension numbers
        $s2 = $db->prepare("SELECT
            CASE WHEN direction='outbound' OR direction='local' THEN caller_id_number ELSE destination_number END as ext,
            COUNT(*) as total,
            SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
            COALESCE(SUM(billsec),0) as total_talk,
            COALESCE(AVG(CASE WHEN billsec>0 THEN billsec END),0) as avg_dur
            FROM v_xml_cdr WHERE domain_name=:d
            AND start_epoch>=:ts AND start_epoch<=:te
            GROUP BY 1");
        $s2->execute([':d'=>$domain,':ts'=>$today_start,':te'=>$today_end]);
        $stats = [];
        foreach($s2->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['ext']] = $r;

        // Call center agent status — key by agent_id AND by extension extracted from contact.
        // BUGFIX: previously keyed only by agent_name ("Agent 1"), then looked up by
        // extension ("101"), so status never matched and everyone looked Offline.
        $ccStatus = [];
        try {
            $s3 = $db->prepare("SELECT agent_name, agent_id, agent_status, agent_contact
                FROM v_call_center_agents ca
                JOIN v_domains d ON d.domain_uuid=ca.domain_uuid WHERE d.domain_name=:d");
            $s3->execute([':d'=>$domain]);
            foreach($s3->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $row = $r;
                if (!empty($r['agent_id'])) $ccStatus[(string)$r['agent_id']] = $row;
                if (!empty($r['agent_name'])) $ccStatus[$r['agent_name']] = $row;
                if (preg_match('/(?:user\/)?(\d+)@/i', (string)($r['agent_contact'] ?? ''), $m)) {
                    $ccStatus[$m[1]] = $row;
                }
            }
        } catch(Exception $ignored){}

        // Live FreeSWITCH call-center status (authoritative for queue state)
        $fsCcStatus = [];
        try {
            $fs_agents = shell_exec("fs_cli -x 'callcenter_config agent list' 2>/dev/null");
            if ($fs_agents) {
                foreach (explode("\n", $fs_agents) as $line) {
                    $line = trim($line);
                    if (!$line || strpos($line, 'name|') === 0 || strpos($line, '+OK') === 0) continue;
                    $parts = explode('|', $line);
                    // contact column usually contains user/101@domain
                    $contact = $parts[4] ?? '';
                    $status  = $parts[5] ?? '';
                    $state   = $parts[6] ?? '';
                    if (preg_match('/(?:user\/)?(\d+)@/i', $contact, $m)) {
                        $fsCcStatus[$m[1]] = ['status' => $status, 'state' => $state];
                    }
                }
            }
        } catch(Exception $ignored){}

        // SIP registrations via FreeSWITCH CLI (not in PostgreSQL)
        $registered = [];
        try {
            $fs_out = shell_exec("fs_cli -x 'show registrations as json' 2>/dev/null");
            if ($fs_out) {
                $reg_json = json_decode($fs_out, true);
                $reg_rows = $reg_json['rows'] ?? null;
                if (is_array($reg_rows)) {
                    foreach ($reg_rows as $rr) {
                        $reg_user = (string)($rr['reg_user'] ?? $rr['user'] ?? '');
                        $realm    = (string)($rr['realm'] ?? '');
                        if ($reg_user !== '' && ($realm === '' || stripos($realm, $domain) !== false || $domain === $realm)) {
                            $registered[$reg_user] = true;
                        }
                    }
                } else {
                    // Fallback plain-text table
                    foreach (explode("\n", $fs_out) as $line) {
                        $line = trim($line);
                        if (!$line || strpos($line,'reg_user')!==false || strpos($line,'row')!==false) continue;
                        $parts = explode('|', $line);
                        if (count($parts) >= 2) {
                            $reg_user = trim($parts[0]);
                            $realm    = trim($parts[1]);
                            if (stripos($realm, $domain) !== false || $domain === $realm || $realm === '') {
                                $registered[$reg_user] = true;
                            }
                        }
                    }
                }
            }
            // Plain command fallback if JSON unsupported
            if (!$registered) {
                $fs_out2 = shell_exec("fs_cli -x 'show registrations' 2>/dev/null");
                if ($fs_out2) {
                    foreach (explode("\n", $fs_out2) as $line) {
                        $line = trim($line);
                        if (!$line || strpos($line,'reg_user')!==false || strpos($line,'total')!==false) continue;
                        $parts = explode('|', $line);
                        if (count($parts) >= 2) {
                            $reg_user = trim($parts[0]);
                            $realm    = trim($parts[1]);
                            if (stripos($realm, $domain) !== false || $domain === $realm) {
                                $registered[$reg_user] = true;
                            }
                        }
                    }
                }
            }
        } catch(Exception $ignored){}

        // ── Active calls from FreeSWITCH live channels ───────────────────────
        // CDR only written after call ends, so use fs_cli show channels instead
        $activeCalls = [];
        try {
            $ch_out = shell_exec("fs_cli -x 'show channels as json' 2>/dev/null");
            if ($ch_out) {
                $ch_json = json_decode($ch_out, true);
                $rows = $ch_json['rows'] ?? [];
                foreach ($rows as $ch_row) {
                    // Column order: uuid, direction, created, name, state, cid_name, cid_num, ip, dest, ...
                    $ch_name  = $ch_row['name']        ?? '';  // e.g. sofia/internal/101@domain
                    $ch_dest  = $ch_row['dest']        ?? '';
                    $ch_cid   = $ch_row['cid_num']     ?? '';
                    $ch_state = $ch_row['callstate']   ?? '';
                    $ch_epoch = $ch_row['created_epoch'] ?? 0;
                    // Extract extension from channel name
                    if (preg_match('/sofia\/internal\/(\d+)@/i', $ch_name, $m)) {
                        $ch_ext = $m[1];
                        $activeCalls[$ch_ext] = [
                            'ext'   => $ch_ext,
                            'destination_number' => $ch_dest ?: $ch_cid,
                            'start_epoch' => (int)$ch_epoch,
                        ];
                    }
                }
            }
        } catch(Exception $ignored){}

        // Pending leave requests for this domain
        $pendingLeaves = [];
        try {
            ensureLeaveRequestsTable($db);
            $pl = $db->prepare("SELECT id, agent_ext, request_type, reason, requested_at
                FROM skykin_leave_requests
                WHERE domain = :d AND status = 'pending'");
            $pl->execute([':d' => $domain]);
            foreach ($pl->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $pendingLeaves[$pr['agent_ext']] = $pr;
            }
        } catch (Exception $ignored) {}

        $agents = [];
        foreach($exts as $e) {
            $ext  = $e['extension'];
            $name = $e['effective_caller_id_name'] ?: 'Extension '.$ext;
            $st   = $stats[$ext] ?? [];
            $cc   = $ccStatus[$ext] ?? null;
            $fsCc = $fsCcStatus[$ext] ?? null;
            $ac   = $activeCalls[$ext] ?? null;

            // Determine live status:
            // 1) Active channel  → incall
            // 2) FreeSWITCH CC  → status + state (authoritative while agent is in queue)
            // 3) DB CC status   → Available / On Break / Logged Out
            // 4) SIP registered → ready
            // 5) else offline
            $status = 'offline';
            $cc_label = $cc['agent_status'] ?? 'Unknown';
            $s_map = [
                'Available' => 'ready', 'Logged Out' => 'offline', 'On Break' => 'break',
                'In Queue Call' => 'incall', 'On Call' => 'incall',
                'Idle' => 'ready', 'Waiting' => 'ready', 'Receiving' => 'incall',
                'In a queue call' => 'incall',
            ];

            if ($fsCc) {
                $fs_status = trim((string)($fsCc['status'] ?? ''));
                $fs_state  = trim((string)($fsCc['state'] ?? ''));
                if ($fs_status !== '') $cc_label = $fs_status;
                // FreeSWITCH state overrides status for in-call / receiving
                if (stripos($fs_state, 'In a queue call') !== false || stripos($fs_state, 'Receiving') !== false) {
                    $status = 'incall';
                } elseif ($fs_status !== '') {
                    $status = $s_map[$fs_status] ?? strtolower(str_replace(' ', '_', $fs_status));
                }
            } elseif ($cc) {
                $status = $s_map[$cc['agent_status']] ?? strtolower(str_replace(' ', '_', $cc['agent_status']));
            } elseif (isset($registered[$ext])) {
                $status = 'ready';
                $cc_label = 'Registered';
            }
            if ($ac) $status = 'incall';

            $call_duration = 0;
            if ($ac && $ac['start_epoch']) {
                $call_duration = time() - (int)$ac['start_epoch'];
            }

            $leave = $pendingLeaves[$ext] ?? null;
            $agents[] = [
                'ext'          => $ext,
                'name'         => $name,
                'status'       => $status,
                'cc_status'    => $cc_label,
                'registered'   => isset($registered[$ext]),
                'leave_pending'=> $leave ? [
                    'id' => (int)$leave['id'],
                    'request_type' => $leave['request_type'],
                    'reason' => $leave['reason'],
                    'requested_at' => $leave['requested_at'],
                ] : null,
                'total_calls'  => (int)($st['total'] ?? 0),
                'answered'     => (int)($st['answered'] ?? 0),
                'missed'       => (int)($st['missed'] ?? 0),
                'total_talk'   => (int)($st['total_talk'] ?? 0),
                'avg_dur'      => (int)($st['avg_dur'] ?? 0),
                'call_duration'=> $call_duration,
                'on_call_with' => $ac['destination_number'] ?? null,
            ];
        }
        echo json_encode(['agents'=>$agents]);
    } catch(Exception $e) { echo json_encode(['agents'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: queue ───────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='queue') {
    error_reporting(0); header('Content-Type: application/json');
    $domain = $_GET['domain'] ?? 'client1.skykin.local';
    $today_start = strtotime(date('Y-m-d').' 00:00:00');
    $today_end   = strtotime(date('Y-m-d').' 23:59:59');
    try {
        $db = getDB();
        $queues = [];
        try {
            $s = $db->prepare("SELECT q.call_queue_name as name,
                COUNT(c.uuid) as waiting
                FROM v_call_queues q
                LEFT JOIN v_call_center_calls c ON c.queue_name=q.call_queue_name AND c.state='Waiting'
                WHERE q.domain_name=:d GROUP BY q.call_queue_name");
            $s->execute([':d'=>$domain]);
            $queues = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $ignored){}

        // Today totals
        $s2 = $db->prepare("SELECT COUNT(*) as total,
            SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
            COALESCE(AVG(CASE WHEN billsec>0 THEN billsec END),0) as avg_talk,
            COALESCE(AVG(duration-billsec),0) as avg_wait
            FROM v_xml_cdr WHERE domain_name=:d
            AND start_epoch>=:ts AND start_epoch<=:te");
        $s2->execute([':d'=>$domain,':ts'=>$today_start,':te'=>$today_end]);
        $totals = $s2->fetch(PDO::FETCH_ASSOC);

        $total    = (int)($totals['total']??0);
        $answered = (int)($totals['answered']??0);
        $sla      = $total>0 ? min(100,round(($answered/$total)*95)) : 100;

        // Agents online — count SIP registrations via FreeSWITCH CLI
        $online_count = 0;
        try {
            $fs_out = shell_exec("fs_cli -x 'show registrations' 2>/dev/null");
            if ($fs_out) {
                foreach (explode("\n", $fs_out) as $line) {
                    $line = trim($line);
                    if (!$line || strpos($line,'reg_user')!==false) continue;
                    $parts = explode('|', $line);
                    if (count($parts) >= 2 && stripos(trim($parts[1]), $domain) !== false) {
                        $online_count++;
                    }
                }
            }
        } catch(Exception $ignored){}
        $online = ['cnt' => $online_count];

        echo json_encode([
            'queues'         => $queues,
            'total_today'    => $total,
            'answered_today' => $answered,
            'missed_today'   => $total - $answered,
            'avg_talk'       => (int)($totals['avg_talk']??0),
            'avg_wait'       => (int)($totals['avg_wait']??0),
            'sla'            => $sla,
            'agents_online'  => (int)($online['cnt']??0),
        ]);
    } catch(Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: leaderboard ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='leaderboard') {
    error_reporting(0); header('Content-Type: application/json');
    $domain = $_GET['domain'] ?? 'client1.skykin.local';
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        $s = $db->prepare("SELECT
            CASE WHEN direction='outbound' OR direction='local' THEN caller_id_number ELSE destination_number END as ext,
            COUNT(*) as total,
            SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
            COALESCE(SUM(billsec),0) as total_talk,
            COALESCE(AVG(CASE WHEN billsec>0 THEN billsec END),0) as avg_dur,
            COALESCE(MAX(CASE WHEN billsec>0 THEN billsec END),0) as max_dur
            FROM v_xml_cdr WHERE domain_name=:d
            AND start_epoch>=:ts AND start_epoch<=:te
            GROUP BY 1 ORDER BY answered DESC LIMIT 20");
        $s->execute([':d'=>$domain,':ts'=>$ts,':te'=>$te]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with names — join v_domains since v_extensions uses domain_uuid
        $names = [];
        $sn = $db->prepare("SELECT e.extension, e.effective_caller_id_name
            FROM v_extensions e JOIN v_domains d ON d.domain_uuid=e.domain_uuid
            WHERE d.domain_name=:d");
        $sn->execute([':d'=>$domain]);
        foreach($sn->fetchAll(PDO::FETCH_ASSOC) as $r) $names[$r['extension']] = $r['effective_caller_id_name'];

        foreach($rows as &$r) {
            $r['name']     = $names[$r['ext']] ?? 'Ext '.$r['ext'];
            $r['total']    = (int)$r['total'];
            $r['answered'] = (int)$r['answered'];
            $r['missed']   = (int)$r['missed'];
            $r['total_talk']=(int)$r['total_talk'];
            $r['avg_dur']  = (int)$r['avg_dur'];
        }
        echo json_encode(['rows'=>$rows]);
    } catch(Exception $e) { echo json_encode(['rows'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: acw_all ─────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='acw_all') {
    error_reporting(0); header('Content-Type: application/json');
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
            id SERIAL PRIMARY KEY, agent_id VARCHAR(50), caller_id VARCHAR(50),
            call_type VARCHAR(20), duration INTEGER, disposition VARCHAR(100),
            call_reason VARCHAR(200), notes TEXT, recording_filename VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW())");
        $s = $db->prepare("SELECT to_char(created_at,'YYYY-MM-DD HH24:MI') as created_at,
            agent_id,caller_id,call_type,duration,disposition,call_reason,notes
            FROM skykin_acw WHERE DATE(created_at)>=:f AND DATE(created_at)<=:t
            ORDER BY created_at DESC LIMIT 200");
        $s->execute([':f'=>$from,':t'=>$to]);
        echo json_encode(['records'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { echo json_encode(['records'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: monitor (eavesdrop via fs_cli) ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='monitor') {
    error_reporting(0); header('Content-Type: application/json');
    $mode      = $_GET['mode']      ?? 'listen';
    $agent_ext = preg_replace('/[^0-9]/','',$_GET['agent_ext'] ?? '');
    $sup_ext_  = preg_replace('/[^0-9]/','',$_GET['sup_ext']   ?? '');
    $domain_   = preg_replace('/[^a-zA-Z0-9.\-]/','',$_GET['domain'] ?? 'client1.skykin.local');

    if (!$agent_ext || !$sup_ext_) { echo json_encode(['ok'=>false,'error'=>'Missing extension']); exit; }

    // Map mode to eavesdrop flag: m=mute(listen), w=whisper to agent, t=three-way(barge)
    $flag_map = ['listen'=>'m','whisper'=>'w','barge'=>'t'];
    $flag = $flag_map[$mode] ?? 'm';

    // Use fs_cli to originate eavesdrop — supervisor's phone will ring
    $originate = "{eavesdrop_enable_dtmf=true,eavesdrop_audio={$flag}}sofia/internal/{$sup_ext_}@{$domain_}";
    $cmd = "fs_cli -x " . escapeshellarg("originate {$originate} &eavesdrop({$agent_ext}@{$domain_})") . " 2>&1";
    $res = shell_exec($cmd);

    if (strpos($res, '+OK') !== false || strpos($res, 'uuid') !== false) {
        echo json_encode(['ok'=>true,'result'=>trim($res)]);
    } else {
        echo json_encode(['ok'=>false,'error'=>trim($res) ?: 'Agent may not be on a call']);
    }
    exit;
}

// ── API: force_status ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='force_status') {
    error_reporting(0); header('Content-Type: application/json');
    $agent_ext = $_GET['agent_ext'] ?? '';
    $new_status= $_GET['status']    ?? 'Available';
    $domain_   = $_GET['domain']    ?? 'client1.skykin.local';
    try {
        $db = getDB();
        $result = applyAgentCcStatus($db, $agent_ext, $new_status, $domain_);
        // Cancel any pending leave for this agent when supervisor forces status
        if (!empty($result['ok'])) {
            try {
                ensureLeaveRequestsTable($db);
                $c = $db->prepare("UPDATE skykin_leave_requests
                    SET status='cancelled', resolved_at=NOW(), resolved_by=:by
                    WHERE domain=:d AND agent_ext=:e AND status='pending'");
                $c->execute([
                    ':by' => $_SESSION['username'] ?? 'supervisor',
                    ':d' => $domain_,
                    ':e' => $agent_ext,
                ]);
            } catch (Exception $ignored) {}
        }
        echo json_encode($result);
    } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── API: leave_requests (pending list for supervisor) ────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'leave_requests') {
    error_reporting(0); header('Content-Type: application/json');
    $domain_ = $_GET['domain'] ?? 'client1.skykin.local';
    try {
        $db = getDB();
        ensureLeaveRequestsTable($db);
        $s = $db->prepare("SELECT id, agent_ext, agent_name, request_type, reason, status,
            to_char(requested_at, 'YYYY-MM-DD HH24:MI') as requested_at
            FROM skykin_leave_requests
            WHERE domain = :d AND status = 'pending'
            ORDER BY requested_at ASC");
        $s->execute([':d' => $domain_]);
        echo json_encode(['ok' => true, 'requests' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'requests' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: resolve_leave (approve / deny) ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'resolve_leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0); header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id      = (int)($body['id'] ?? $_GET['id'] ?? 0);
    $decision = trim($body['decision'] ?? $_GET['decision'] ?? '');
    $domain_ = trim($body['domain'] ?? $_GET['domain'] ?? 'client1.skykin.local');
    if ($id < 1 || !in_array($decision, ['approved', 'denied'], true)) {
        echo json_encode(['ok' => false, 'error' => 'id and decision (approved|denied) required']);
        exit;
    }
    try {
        $db = getDB();
        ensureLeaveRequestsTable($db);
        $s = $db->prepare("SELECT * FROM skykin_leave_requests WHERE id = :id AND domain = :d LIMIT 1");
        $s->execute([':id' => $id, ':d' => $domain_]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Request not found']);
            exit;
        }
        if ($row['status'] !== 'pending') {
            echo json_encode(['ok' => false, 'error' => 'Request already ' . $row['status']]);
            exit;
        }
        $resolver = $_SESSION['username'] ?? 'supervisor';
        $esl_result = null;
        if ($decision === 'approved') {
            $esl_result = applyAgentCcStatus($db, $row['agent_ext'], $row['request_type'], $domain_);
            if (empty($esl_result['ok'])) {
                echo json_encode(['ok' => false, 'error' => $esl_result['error'] ?? 'Failed to apply status']);
                exit;
            }
        }
        $u = $db->prepare("UPDATE skykin_leave_requests
            SET status = :st, resolved_at = NOW(), resolved_by = :by
            WHERE id = :id AND status = 'pending'");
        $u->execute([':st' => $decision, ':by' => $resolver, ':id' => $id]);
        echo json_encode([
            'ok' => true,
            'decision' => $decision,
            'request' => [
                'id' => $id,
                'agent_ext' => $row['agent_ext'],
                'request_type' => $row['request_type'],
            ],
            'status_result' => $esl_result,
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: call_history_all ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='call_history_all') {
    error_reporting(0); header('Content-Type: application/json');
    $domain_ = $_GET['domain'] ?? 'client1.skykin.local';
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        // Build SIP-username -> extension map for this domain
        $uname_ext = [];
        try {
            $su = $db->prepare("SELECT e.extension, u.username
                FROM v_extensions e
                JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                JOIN v_users u ON u.user_uuid = eu.user_uuid
                JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                WHERE d.domain_name = :d");
            $su->execute([':d' => $domain_]);
            foreach ($su->fetchAll(PDO::FETCH_ASSOC) as $um)
                $uname_ext[strtolower($um['username'])] = $um['extension'];
        } catch (Exception $ignore) {}

        $where = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te";
        $params = [':d'=>$domain_,':ts'=>$ts,':te'=>$te];
        if ($search) { $where.=" AND (caller_id_number LIKE :q OR destination_number LIKE :q)"; $params[':q']='%'.$search.'%'; }
        $s = $db->prepare("SELECT to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as call_time,
            caller_id_number, destination_number, direction, billsec, duration, hangup_cause
            FROM v_xml_cdr WHERE $where ORDER BY start_epoch DESC LIMIT 500");
        $s->execute($params);
        $rows = [];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b=(int)$r['billsec'];
            // Resolve SIP usernames to extension numbers
            $caller = preg_replace('/@.*$/', '', $r['caller_id_number']);
            $dest   = preg_replace('/@.*$/', '', $r['destination_number']);
            if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $caller))
                $caller = $uname_ext[strtolower($caller)] ?? 'Unknown';
            if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $dest))
                $dest   = $uname_ext[strtolower($dest)]   ?? 'Unknown';
            $rows[] = ['time'=>$r['call_time'],'caller'=>$caller,
                'destination'=>$dest,'direction'=>$r['direction'],
                'duration'=>floor($b/60).':'.str_pad($b%60,2,'0',STR_PAD_LEFT),
                'status'=>$b>0?'Answered':'Missed','cause'=>$r['hangup_cause']??''];
        }
        echo json_encode(['rows'=>$rows]);
    } catch(Exception $e) { echo json_encode(['rows'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: recordings_all ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='recordings_all') {
    error_reporting(0); header('Content-Type: application/json');
    $domain_ = $_GET['domain'] ?? 'client1.skykin.local';
    $from    = $_GET['from']   ?? date('Y-m-d');
    $to      = $_GET['to']     ?? date('Y-m-d');
    $search  = $_GET['search'] ?? '';
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        $where  = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te AND (record_path IS NOT NULL OR record_name IS NOT NULL)";
        $params = [':d'=>$domain_,':ts'=>$ts,':te'=>$te];
        if ($search) { $where.=" AND (caller_id_number LIKE :q OR destination_number LIKE :q)"; $params[':q']='%'.$search.'%'; }
        $s = $db->prepare("SELECT to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as call_time,
            caller_id_number, destination_number, direction, billsec,
            record_path, record_name, hangup_cause
            FROM v_xml_cdr WHERE $where ORDER BY start_epoch DESC LIMIT 300");
        $s->execute($params);
        $rows = [];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = (int)$r['billsec'];
            $file = trim($r['record_name'] ?? '');
            $path = trim($r['record_path'] ?? '');
            // Build playback URL using FusionPBX recordings path
            $play_url = '';
            if ($file) {
                $play_url = '/app/recordings/index.php?filename='.urlencode($file).'&path='.urlencode($path);
            }
            $rows[] = [
                'time'        => $r['call_time'],
                'caller'      => $r['caller_id_number'],
                'destination' => $r['destination_number'],
                'direction'   => $r['direction'],
                'duration'    => floor($b/60).':'.str_pad($b%60,2,'0',STR_PAD_LEFT),
                'file'        => $file,
                'path'        => $path,
                'play_url'    => $play_url,
                'cause'       => $r['hangup_cause'] ?? '',
            ];
        }
        echo json_encode(['rows'=>$rows,'total'=>count($rows)]);
    } catch(Exception $e) { echo json_encode(['rows'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: voice_quality ────────────────────────────────────────────────────────
// Estimates MOS score from CDR: WebRTC calls short = poor, longer = better
// Formula: MOS = 4.5 - (missed_calls_weight + duration_factor)
// For real MOS you would need rtcp data; this gives a practical quality indication.
if (isset($_GET['action']) && $_GET['action']==='voice_quality') {
    error_reporting(0); header('Content-Type: application/json');
    $domain_ = $_GET['domain'] ?? 'client1.skykin.local';
    $from    = $_GET['from']   ?? date('Y-m-d');
    $to      = $_GET['to']     ?? date('Y-m-d');
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        $s = $db->prepare("SELECT
            to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as call_time,
            caller_id_number, destination_number, direction, billsec, duration,
            hangup_cause
            FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
            AND billsec > 0
            ORDER BY start_epoch DESC LIMIT 500");
        $s->execute([':d'=>$domain_,':ts'=>$ts,':te'=>$te]);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $billsec  = (int)$r['billsec'];
            $duration = (int)$r['duration'];
            // WebRTC calls use Opus by default — base MOS 4.3
            $base = 4.3;
            // Very short calls suggest audio issue
            if ($billsec < 10) $base -= 0.6;
            elseif ($billsec < 30) $base -= 0.2;
            // Long ring time penalty
            $ring_time = max(0, $duration - $billsec);
            if ($ring_time > 30) $base -= 0.1;
            $mos = max(1.0, min(5.0, $base));
            $b = $billsec;
            $rows[] = [
                'call_time'         => $r['call_time'],
                'caller_id_number'  => $r['caller_id_number'],
                'destination_number'=> $r['destination_number'],
                'direction'         => $r['direction'],
                'duration'          => floor($b/60).':'.str_pad($b%60,2,'0',STR_PAD_LEFT),
                'mos'               => round($mos, 2),
                'codec'             => 'opus/WebRTC',
                'hangup_cause'      => $r['hangup_cause'] ?? '',
            ];
        }
        echo json_encode(['rows'=>$rows,'total'=>count($rows)]);
    } catch(Exception $e) { echo json_encode(['rows'=>[],'error'=>$e->getMessage()]); }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SkyKin Supervisor – <?php echo $domain; ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f5;color:#333;min-height:100vh}

/* Header */
.header{background:linear-gradient(135deg,#0047AB,#00B4D8);color:#fff;padding:0 24px;height:60px;
    display:flex;align-items:center;justify-content:space-between;
    position:fixed;top:0;left:0;right:0;z-index:300;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.header .logo{font-size:18px;font-weight:700;letter-spacing:1px}
.header .logo span{color:#00e5ff}
.header .logo .role-badge{background:rgba(255,255,255,.2);border-radius:20px;padding:2px 10px;
    font-size:11px;font-weight:600;margin-left:10px;letter-spacing:0}
.header-right{display:flex;align-items:center;gap:14px;font-size:13px}
.header-right .clock{opacity:.9}
.live-dot{width:8px;height:8px;border-radius:50%;background:#00e676;display:inline-block;margin-right:4px;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* Main layout */
.main{margin-top:60px;padding:20px;max-width:1600px;margin-left:auto;margin-right:auto}
.main{margin-top:68px}

/* Queue stats bar */
.queue-bar{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:20px}
.qstat{background:#fff;border-radius:10px;padding:14px 16px;text-align:center;
    box-shadow:0 1px 4px rgba(0,0,0,.08);border-top:3px solid #0047AB}
.qstat.warn{border-top-color:#ff9800}
.qstat.danger{border-top-color:#f44336}
.qstat.good{border-top-color:#4caf50}
.qstat-val{font-size:24px;font-weight:700;color:#0047AB;line-height:1.2}
.qstat.warn .qstat-val{color:#e65100}
.qstat.danger .qstat-val{color:#c62828}
.qstat.good .qstat-val{color:#2e7d32}
.qstat-lbl{font-size:11px;color:#888;margin-top:3px}

/* Agent list (expandable rows) */
.agents-section{margin-bottom:20px}
.section-title{font-size:15px;font-weight:700;color:#0047AB;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.agents-grid{display:flex;flex-direction:column;gap:0;max-height:520px;overflow-y:auto}
.agent-row{border-bottom:1px solid #f0f0f0;transition:background .15s}
.agent-row:last-child{border-bottom:none}
.agent-row:hover{background:#f8fbff}
.agent-row.open{background:#f0f5ff}
.agent-row.offline{opacity:.7}
.agent-row-main{display:grid;grid-template-columns:36px 1fr 70px 90px 60px 60px 70px 18px;gap:8px;align-items:center;padding:10px 12px;cursor:pointer;user-select:none}
.agent-row-main:hover .row-chevron{color:#0047AB}
.agent-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:12px;color:#fff;flex-shrink:0}
.avatar-ready{background:#4caf50}.avatar-incall{background:#2196f3}
.avatar-acw{background:#ff9800}.avatar-break{background:#9c27b0}.avatar-offline{background:#aaa}
.row-name{font-weight:700;font-size:13px;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-ext{font-size:11px;color:#888}
.row-meta{font-size:12px;color:#555;text-align:center}
.row-meta.missed{color:#f44336;font-weight:600}
.row-meta.talk{color:#0047AB;font-weight:600}
.row-oncall{font-size:11px;color:#1565c0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-chevron{font-size:14px;color:#bbb;transition:transform .2s,color .15s;text-align:center}
.agent-row.open .row-chevron{transform:rotate(90deg);color:#0047AB}
.status-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-ready{background:#e8f5e9;color:#2e7d32}
.badge-incall{background:#e3f2fd;color:#1565c0}
.badge-acw{background:#fff3e0;color:#e65100}
.badge-break{background:#f3e5f5;color:#6a1b9a}
.badge-offline{background:#f5f5f5;color:#757575}
.badge-lunch{background:#fbe9e7;color:#bf360c}
.badge-meeting{background:#eceff1;color:#455a64}

.agent-row-detail{display:none;padding:0 12px 12px 56px;border-top:1px dashed #e8eef8}
.agent-row.open .agent-row-detail{display:block}
.detail-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:10px 0 12px}
.detail-metric{background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:8px 10px}
.detail-metric-lbl{font-size:10px;color:#888;text-transform:uppercase;margin-bottom:2px}
.detail-metric-val{font-size:14px;font-weight:700;color:#0047AB}
.detail-actions{display:flex;flex-wrap:wrap;gap:6px}
.detail-actions button{padding:7px 12px;border:none;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;transition:opacity .15s}
.detail-actions button:hover{opacity:.85}
.detail-actions button:disabled{opacity:.4;cursor:not-allowed}
.btn-listen{background:#e3f2fd;color:#1565c0}
.btn-whisper{background:#f3e5f5;color:#6a1b9a}
.btn-barge{background:#fce4ec;color:#c62828}
.btn-force-avail{background:#e8f5e9;color:#2e7d32}
.btn-force-break{background:#fff3e0;color:#e65100}
.btn-force-out{background:#ffebee;color:#c62828}
.agents-list-head{display:grid;grid-template-columns:36px 1fr 70px 90px 60px 60px 70px 18px;gap:8px;align-items:center;padding:6px 12px;font-size:10px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #eee;background:#fafbfc;position:sticky;top:0;z-index:1}

/* Dashboard proportions */
.dashboard-overview{display:flex;flex-direction:column;gap:16px}
.dashboard-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}
.dashboard-kpi{min-height:92px;background:#fff;border:1px solid #edf0f5;border-radius:10px;padding:14px 16px;
    box-shadow:0 1px 5px rgba(0,0,0,.05);border-top:3px solid #0047AB;display:flex;flex-direction:column;justify-content:center}
.dashboard-kpi-value{font-size:25px;font-weight:700;line-height:1;color:#0047AB}
.dashboard-kpi-label{font-size:11px;color:#777;margin-top:7px}
.dashboard-kpi.answered{border-top-color:#28a745}.dashboard-kpi.answered .dashboard-kpi-value{color:#28a745}
.dashboard-kpi.missed{border-top-color:#dc3545}.dashboard-kpi.missed .dashboard-kpi-value{color:#dc3545}
.dashboard-kpi.online{border-top-color:#17a2b8}.dashboard-kpi.online .dashboard-kpi-value{color:#17a2b8}
.queue-health-card{grid-column:span 2;border-top-color:#fd7e14}
.queue-health-title{font-size:11px;font-weight:700;color:#555;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.queue-health-dot{width:7px;height:7px;border-radius:50%;background:#fd7e14}
.queue-health-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.queue-health-metric{border-left:1px solid #eee;padding-left:10px}
.queue-health-metric:first-child{border-left:none;padding-left:0}
.queue-health-value{display:block;font-size:16px;font-weight:700;color:#333}
.queue-health-label{display:block;font-size:9px;color:#999;margin-top:3px}
.live-agents-panel{background:#fff;border:1px solid #edf0f5;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden}
.live-agents-header{padding:14px 18px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;gap:12px}
.live-agents-tools{display:flex;align-items:center;gap:10px}

/* Bottom tabs */
.bottom-section{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.08);overflow:hidden}
.tab-bar{display:flex;border-bottom:2px solid #f0f0f0;padding:0 16px;gap:4px}
.tab-btn{padding:12px 18px;background:none;border:none;cursor:pointer;font-size:13px;font-weight:600;
    color:#888;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s}
.tab-btn.active{color:#0047AB;border-bottom-color:#0047AB;background:#f8fbff}
.tab-btn:hover:not(.active){color:#0047AB;background:#f8f9fa}
.tab-content{padding:16px;display:none}
.tab-content.active{display:block}

/* Table */
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #eee;font-size:11px;text-transform:uppercase}
.data-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.data-table tr:hover td{background:#fafbff}
.badge-in{background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-out{background:#f3e5f5;color:#6a1b9a;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-answered{background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-missed{background:#ffebee;color:#c62828;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.rank-medal{font-size:16px}

/* Date filter */
.date-filter{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.date-filter label{font-size:12px;color:#666;font-weight:600}
.date-filter input[type=date]{padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:12px}
.btn-filter{padding:6px 14px;background:#0047AB;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600}
.btn-filter-clear{padding:6px 14px;background:#f0f0f0;color:#666;border:none;border-radius:6px;cursor:pointer;font-size:12px}
.search-input{padding:6px 12px;border:1px solid #ddd;border-radius:6px;font-size:12px;width:200px}

/* Toast */
#supToast{position:fixed;bottom:20px;right:20px;background:#323232;color:#fff;padding:12px 20px;
    border-radius:8px;font-size:13px;display:none;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.3)}

/* Leaderboard rank */
.lb-rank{font-weight:700;font-size:16px;text-align:center}
.rank-1{color:#f9a825}.rank-2{color:#90a4ae}.rank-3{color:#a1887f}
.progress-bar-wrap{background:#f0f0f0;border-radius:4px;height:6px;margin-top:3px}
.progress-bar-fill{background:#0047AB;border-radius:4px;height:6px;transition:width .5s}

/* Responsive */
@media(max-width:900px){
    .queue-bar{grid-template-columns:repeat(3,1fr)}
    .dashboard-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
    .queue-health-card{grid-column:span 2}
}
@media(max-width:700px){
    .live-agents-header{align-items:flex-start;flex-direction:column}
    .live-agents-tools{width:100%;justify-content:flex-end}
    .agent-row-main,.agents-list-head{grid-template-columns:32px minmax(110px,1fr) 70px 60px 18px}
    .agent-row-main>:nth-child(4),.agent-row-main>:nth-child(6),.agent-row-main>:nth-child(7),
    .agents-list-head>:nth-child(4),.agents-list-head>:nth-child(6),.agents-list-head>:nth-child(7){display:none}
    .agent-row-detail{padding-left:12px}
}
@media(max-width:600px){
    .queue-bar{grid-template-columns:repeat(2,1fr)}
    .dashboard-kpis{grid-template-columns:1fr 1fr}
    .queue-health-card{grid-column:span 2}
    .tab-bar{overflow-x:auto}
}
</style>
</head>
<body>

<div class="header">
    <div style="display:flex;align-items:center;gap:12px">
        <button onclick="toggleSideMenu()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;border-radius:8px;font-size:20px;cursor:pointer;line-height:1">&#9776;</button>
        <div class="logo"><span>SKY</span>KIN Technologies <span class="role-badge">SUPERVISOR</span></div>
    </div>
    <div class="header-right">
        <span><span class="live-dot"></span>Live</span>
        <span class="clock" id="supClock">--:--:--</span>
        <span style="opacity:.6;font-size:11px"><?php echo $domain; ?></span>
        &nbsp;|&nbsp;
        <span style="position:relative;display:inline-block">
            <span id="agentViewBtn" onclick="document.getElementById('agentViewDrop').style.display=document.getElementById('agentViewDrop').style.display==='block'?'none':'block'"
                  style="color:rgba(255,255,255,.9);font-size:11px;cursor:pointer;padding:4px 8px;border-radius:4px;background:rgba(255,255,255,.1)">
                &#128100; Agent View &#9660;
            </span>
            <div id="agentViewDrop" style="display:none;position:absolute;right:0;top:28px;background:#fff;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.2);min-width:160px;z-index:999;overflow:hidden">
                <?php foreach($nav_agents as $na): ?>
                <a href="/app/agent_dashboard/index.php?agent=<?php echo urlencode($na['username'] ?: $na['extension']); ?>&domain=<?php echo urlencode($domain); ?>"
                   style="display:block;padding:8px 14px;font-size:12px;color:#333;text-decoration:none;border-bottom:1px solid #f0f0f0"
                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background=''">
                    <?php echo htmlspecialchars($na['extension'].' — '.$na['name']); ?>
                </a>
                <?php endforeach; ?>
                <?php if(empty($nav_agents)): ?>
                <span style="display:block;padding:8px 14px;font-size:12px;color:#999">No agents found</span>
                <?php endif; ?>
            </div>
        </span>
    </div>
</div>

<!-- Slide-out side menu -->
<div id="sideMenu" style="position:fixed;top:0;left:-260px;width:250px;height:100vh;background:#fff;box-shadow:4px 0 24px rgba(0,0,0,.18);z-index:500;transition:left .25s ease;display:flex;flex-direction:column">
    <div style="background:linear-gradient(135deg,#0047AB,#00B4D8);padding:20px;color:#fff;flex-shrink:0">
        <div style="font-size:17px;font-weight:700"><span style="color:#00e5ff">SKY</span>KIN Technologies</div>
        <div style="font-size:11px;opacity:.8;margin-top:3px">Supervisor Panel</div>
    </div>
    <nav style="flex:1;padding:8px 0;overflow-y:auto">
        <a href="#" onclick="toggleSideMenu();showTabDirect('dashboard')" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#0047AB;text-decoration:none;font-size:14px;font-weight:600;background:#f0f4ff;border-left:4px solid #0047AB">
            <span style="font-size:18px">&#128187;</span> Dashboard
        </a>
        <a href="#" onclick="toggleSideMenu();showTabDirect('leaderboard')" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#127942;</span> Leaderboard
        </a>
        <a href="#" onclick="toggleSideMenu();showTabDirect('callhistory')" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#128222;</span> Call History
        </a>
        <a href="#" onclick="toggleSideMenu();showTabDirect('recordings')" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#127908;</span> Recordings
        </a>
        <a href="/app/agent_dashboard/reports.php" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#128202;</span> Reports
        </a>
        <a href="/app/agent_dashboard/evaluation.php" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#9733;</span> Evaluation
        </a>
        <a href="/app/agent_dashboard/crm.php" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#128100;</span> CRM
        </a>
        <a href="#" onclick="toggleSideMenu();showTabDirect('ahununu')" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#127760;</span> Ahununu.com
        </a>
        <div style="height:1px;background:#eee;margin:6px 0"></div>
        <a href="/logout.php" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#dc3545;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
            <span style="font-size:18px">&#128682;</span> Sign Out
        </a>
    </nav>
    <div style="padding:12px 20px;border-top:1px solid #f0f0f0;font-size:11px;color:#bbb;flex-shrink:0">SkyKin &copy; <?php echo date('Y'); ?></div>
</div>
<div id="sideMenuBackdrop" onclick="toggleSideMenu()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.25);z-index:499"></div>

<div class="main">

    <!-- Tabs: Dashboard first, then detail views -->
    <div class="bottom-section">
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="dashboard" onclick="showTab('dashboard')">Dashboard</button>
            <button class="tab-btn" data-tab="leaderboard" onclick="showTab('leaderboard')">Leaderboard</button>
            <button class="tab-btn" data-tab="callhistory" onclick="showTab('callhistory')">All Call History</button>
            <button class="tab-btn" data-tab="acwall" onclick="showTab('acwall')">ACW Review</button>
            <button class="tab-btn" data-tab="recordings" onclick="showTab('recordings')">Call Recordings</button>
            <button class="tab-btn" data-tab="voicequality" onclick="showTab('voicequality')">Voice Quality</button>
            <button class="tab-btn" data-tab="skills" onclick="showTab('skills')">Agent Skills</button>
            <button class="tab-btn" data-tab="ahununu" onclick="showTab('ahununu')">&#127760; Ahununu.com</button>
        </div>

        <!-- Dashboard Tab (landing overview) -->
        <div class="tab-content active" id="tab-dashboard" style="padding:16px">
            <div class="dashboard-overview">
                <!-- Full-width summary strip -->
                <div class="dashboard-kpis">
                    <div class="dashboard-kpi">
                        <div class="dashboard-kpi-value" id="qs-total">–</div>
                        <div class="dashboard-kpi-label">Calls Today</div>
                    </div>
                    <div class="dashboard-kpi answered">
                        <div class="dashboard-kpi-value" id="qs-answered">–</div>
                        <div class="dashboard-kpi-label">Answered</div>
                    </div>
                    <div class="dashboard-kpi missed">
                        <div class="dashboard-kpi-value" id="qs-missed">–</div>
                        <div class="dashboard-kpi-label">Missed</div>
                    </div>
                    <div class="dashboard-kpi online">
                        <div class="dashboard-kpi-value" id="qs-online">–</div>
                        <div class="dashboard-kpi-label">Agents Online</div>
                    </div>
                    <div class="dashboard-kpi queue-health-card">
                        <div class="queue-health-title"><span class="queue-health-dot"></span> Queue Health</div>
                        <div class="queue-health-metrics">
                            <div class="queue-health-metric">
                                <strong class="queue-health-value" id="qs-avgtalk">–</strong>
                                <span class="queue-health-label">Avg Talk</span>
                            </div>
                            <div class="queue-health-metric">
                                <strong class="queue-health-value" id="qs-avgwait">–</strong>
                                <span class="queue-health-label">Avg Wait</span>
                            </div>
                            <div class="queue-health-metric">
                                <strong class="queue-health-value" id="qs-sla" style="color:#28a745">–</strong>
                                <span class="queue-health-label">SLA</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Leave requests awaiting supervisor approval -->
                <div class="live-agents-panel" id="leaveRequestsPanel" style="margin-bottom:14px">
                    <div class="live-agents-header">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:#333">
                            Leave Requests
                            <span id="leaveReqCount" style="background:#f59e0b;color:#fff;font-size:11px;border-radius:10px;padding:1px 8px;display:none">0</span>
                        </div>
                        <span style="font-size:11px;color:#aaa">Agents stay Available until you approve</span>
                    </div>
                    <div id="leaveRequestsList" style="padding:12px 16px;color:#aaa;font-size:13px">No pending leave requests.</div>
                </div>

                <!-- Full-width live agent list -->
                <div class="live-agents-panel">
                    <div class="live-agents-header">
                <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:#333">
                    <span class="live-dot"></span> Live Agent Status
                </div>
                <div class="live-agents-tools">
                    <span style="font-size:11px;color:#aaa">Auto-refreshes every 10s</span>
                    <span id="agentPageInfo" style="font-size:11px;color:#666"></span>
                    <button onclick="agentPage(-1)" id="agentPrev" style="background:#f0f0f0;border:none;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:12px">&#8249;</button>
                    <button onclick="agentPage(+1)" id="agentNext" style="background:#f0f0f0;border:none;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:12px">&#8250;</button>
                </div>
            </div>
            <div style="padding:0">
                <div class="agents-list-head">
                    <span></span>
                    <span>Agent</span>
                    <span>Status</span>
                    <span>On Call</span>
                    <span style="text-align:center">Ans</span>
                    <span style="text-align:center">Miss</span>
                    <span style="text-align:center">Talk</span>
                    <span></span>
                </div>
                <div class="agents-grid" id="agentsGrid">
                    <div style="color:#aaa;font-size:13px;padding:20px">Loading agents...</div>
                </div>
            </div>
        </div>
            </div>
        </div>

        <!-- Leaderboard -->
        <div class="tab-content" id="tab-leaderboard">
            <div class="date-filter">
                <label>From:</label><input type="date" id="lbFrom" value="<?php echo $today;?>">
                <label>To:</label><input type="date" id="lbTo" value="<?php echo $today;?>">
                <button class="btn-filter" onclick="fetchLeaderboard()">Filter</button>
                <button class="btn-filter-clear" onclick="setToday('lbFrom','lbTo');fetchLeaderboard()">Today</button>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th style="width:50px">Rank</th>
                    <th>Agent</th>
                    <th>Answered</th>
                    <th>Missed</th>
                    <th>Total Talk</th>
                    <th>Avg Duration</th>
                    <th>Performance</th>
                </tr></thead>
                <tbody id="lbBody"><tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- All Call History -->
        <div class="tab-content" id="tab-callhistory">
            <div class="date-filter">
                <label>From:</label><input type="date" id="chFrom" value="<?php echo $today;?>">
                <label>To:</label><input type="date" id="chTo" value="<?php echo $today;?>">
                <input type="text" class="search-input" id="chSearch" placeholder="Search number...">
                <button class="btn-filter" onclick="fetchCallHistory()">Search</button>
                <button class="btn-filter-clear" onclick="setToday('chFrom','chTo');document.getElementById('chSearch').value='';fetchCallHistory()">Today</button>
                <span id="chCount" style="font-size:12px;color:#aaa"></span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Time</th><th>Caller</th><th>Destination</th>
                    <th>Direction</th><th>Duration</th><th>Status</th><th>Cause</th>
                </tr></thead>
                <tbody id="chBody"><tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- ACW Review -->
        <div class="tab-content" id="tab-acwall">
            <div class="date-filter">
                <label>From:</label><input type="date" id="acwFrom" value="<?php echo $today;?>">
                <label>To:</label><input type="date" id="acwTo" value="<?php echo $today;?>">
                <button class="btn-filter" onclick="fetchAcwAll()">Filter</button>
                <button class="btn-filter-clear" onclick="setToday('acwFrom','acwTo');fetchAcwAll()">Today</button>
                <span id="acwAllCount" style="font-size:12px;color:#aaa"></span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Time</th><th>Agent</th><th>Caller</th><th>Type</th>
                    <th>Duration</th><th>Disposition</th><th>Reason</th><th>Notes</th>
                </tr></thead>
                <tbody id="acwAllBody"><tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Call Recordings -->
        <div class="tab-content" id="tab-recordings">
            <div class="date-filter">
                <label>From:</label><input type="date" id="recFrom" value="<?php echo $today;?>">
                <label>To:</label><input type="date" id="recTo" value="<?php echo $today;?>">
                <input type="text" class="search-input" id="recSearch" placeholder="Search number...">
                <button class="btn-filter" onclick="fetchRecordings()">Search</button>
                <button class="btn-filter-clear" onclick="setToday('recFrom','recTo');document.getElementById('recSearch').value='';fetchRecordings()">Today</button>
                <span id="recCount" style="font-size:12px;color:#aaa"></span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Time</th><th>Caller</th><th>Destination</th>
                    <th>Direction</th><th>Duration</th><th>Length</th><th>Play</th>
                </tr></thead>
                <tbody id="recBody"><tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
            </table>
            <audio id="recPlayer" controls style="width:100%;margin-top:12px;display:none"></audio>
        </div>

        <!-- Voice Quality Tab -->
        <div class="tab-content" id="tab-voicequality">
            <div class="date-filter">
                <label>From:</label><input type="date" id="vqFrom" value="<?php echo $today;?>">
                <label>To:</label><input type="date" id="vqTo" value="<?php echo $today;?>">
                <button class="btn-filter" onclick="fetchVoiceQuality()">Search</button>
                <button class="btn-filter-clear" onclick="setToday('vqFrom','vqTo');fetchVoiceQuality()">Today</button>
                <span id="vqSummary" style="font-size:12px;color:#aaa;margin-left:8px"></span>
            </div>
            <!-- MOS Legend -->
            <div style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap;font-size:12px">
                <span style="color:#27ae60">&#9632; Excellent (MOS &ge; 4.0)</span>
                <span style="color:#f39c12">&#9632; Good (3.5&ndash;3.9)</span>
                <span style="color:#e67e22">&#9632; Fair (3.0&ndash;3.4)</span>
                <span style="color:#e74c3c">&#9632; Poor (&lt;3.0)</span>
                <span style="color:#aaa;margin-left:8px"><em>MOS = Mean Opinion Score (1&ndash;5). WebRTC quality estimated from call duration.</em></span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Time</th><th>Caller</th><th>Destination</th>
                    <th>Direction</th><th>Duration</th>
                    <th>MOS Score</th><th>Quality</th><th>Hangup Cause</th>
                </tr></thead>
                <tbody id="vqBody"><tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Agent Skills Tab -->
        <div class="tab-content" id="tab-skills">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                <!-- Left: Agent → Queue assignments -->
                <div>
                    <h4 style="margin-bottom:12px;color:#333;font-size:14px">Agent Language Skills & Queue Assignment</h4>
                    <p style="font-size:12px;color:#666;margin-bottom:14px">
                        Use the script below to assign agents to language queues. Run it once on the FusionPBX server.
                        After running, each agent will be available in their assigned language queue.
                    </p>
                    <div id="skillsAgentList" style="background:#f8f9fa;border-radius:8px;padding:14px">
                        Loading agents...
                    </div>
                </div>
                <!-- Right: Queue → Language mapping info -->
                <div>
                    <h4 style="margin-bottom:12px;color:#333;font-size:14px">Language Queue Map</h4>
                    <table class="data-table">
                        <thead><tr><th>Extension</th><th>Queue Name</th><th>Language</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr><td><strong>9000</strong></td><td>Language IVR Menu</td><td>—</td><td><span style="color:#27ae60">Entry Point</span></td></tr>
                            <tr><td><strong>8000</strong></td><td>General Queue</td><td>All Languages</td><td><span style="color:#27ae60">Active</span></td></tr>
                            <tr><td><strong>8001</strong></td><td>Amharic Queue</td><td>Amharic</td><td><span style="color:#f39c12">Run setup script</span></td></tr>
                            <tr><td><strong>8002</strong></td><td>English Queue</td><td>English</td><td><span style="color:#f39c12">Run setup script</span></td></tr>
                            <tr><td><strong>8003</strong></td><td>Oromo Queue</td><td>Oromo</td><td><span style="color:#f39c12">Run setup script</span></td></tr>
                        </tbody>
                    </table>
                    <div style="margin-top:16px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;font-size:12px">
                        <strong>Setup Instructions:</strong><br>
                        1. SSH to your FusionPBX VM<br>
                        2. Run: <code>bash /tmp/ivr_language_setup.sh</code><br>
                        3. Callers will hear: <em>"Press 1 for Amharic, 2 for English, 3 for Oromo"</em><br>
                        4. Each press routes to the correct language queue<br>
                        5. Upload IVR greeting in FusionPBX &rarr; IVR &rarr; SkyKin Language IVR
                    </div>
                    <div style="margin-top:12px;background:#f8f9fa;border-radius:6px;padding:10px;font-size:11px;color:#666">
                        <strong>IVR Call Flow:</strong><br>
                        Incoming Call &rarr; 9000 (IVR) &rarr; Press 1/2/3 &rarr; Language Queue &rarr; Agent with that language skill
                    </div>
                </div>
            </div>
        </div>
    </div>
        <div class="tab-content" id="tab-ahununu" style="padding:0;height:700px">
            <iframe src="about:blank" id="ahununuFrame" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px" allow="camera;microphone"></iframe>
        </div>

</div>

<div id="supToast"></div>

<script>
const domain   = '<?php echo $domain; ?>';
const supExt   = '<?php echo $sup_ext; ?>' || localStorage.getItem('sup_ext') || '';
// Pre-fill localStorage with server-detected extension so monitor() doesn't prompt
if ('<?php echo $sup_ext; ?>' && !localStorage.getItem('sup_ext')) localStorage.setItem('sup_ext','<?php echo $sup_ext; ?>');
const today    = '<?php echo $today; ?>';
let agentTimers = {};

// ── Side menu toggle ───────────────────────────────────────────────────────
function toggleSideMenu() {
    const menu     = document.getElementById('sideMenu');
    const backdrop = document.getElementById('sideMenuBackdrop');
    const isOpen   = menu.style.left === '0px';
    menu.style.left          = isOpen ? '-260px' : '0px';
    backdrop.style.display   = isOpen ? 'none'   : 'block';
}

// ── Clock ──────────────────────────────────────────────────────────────────
function updateClock(){
    document.getElementById('supClock').textContent = new Date().toLocaleTimeString();
}
setInterval(updateClock,1000); updateClock();

// ── Toast ──────────────────────────────────────────────────────────────────
let toastT;
function toast(msg,color){
    const t=document.getElementById('supToast');
    t.textContent=msg; t.style.background=color||'#323232'; t.style.display='block';
    clearTimeout(toastT); toastT=setTimeout(()=>t.style.display='none',3500);
}

// ── Helpers ────────────────────────────────────────────────────────────────
function fmtSec(s){s=parseInt(s)||0;return Math.floor(s/3600)>0?Math.floor(s/3600)+'h '+Math.floor((s%3600)/60)+'m':Math.floor(s/60)+':'+String(s%60).padStart(2,'0')}
function fmtDur(s){s=parseInt(s)||0;return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')}
function setToday(f,t){const d=new Date().toISOString().slice(0,10);document.getElementById(f).value=d;document.getElementById(t).value=d;}
function statusColor(s){return{ready:'#4caf50',incall:'#2196f3',acw:'#ff9800',break:'#9c27b0',offline:'#aaa',lunch:'#ff5722',meeting:'#607d8b'}[s]||'#aaa'}
function statusLabel(s){return{ready:'Ready',incall:'In Call',acw:'ACW',break:'Break',offline:'Offline',lunch:'Lunch',meeting:'Meeting'}[s]||s}
function initials(name){const p=name.split(' ');return p.length>1?(p[0][0]+p[1][0]).toUpperCase():(name.slice(0,2).toUpperCase())}

// ── Queue Stats ────────────────────────────────────────────────────────────
function fetchQueue(){
    fetch('supervisor.php?action=queue&domain='+encodeURIComponent(domain))
        .then(r=>r.json()).then(d=>{
            document.getElementById('qs-online').textContent  = d.agents_online??'0';
            document.getElementById('qs-total').textContent   = d.total_today??'0';
            document.getElementById('qs-answered').textContent= d.answered_today??'0';
            document.getElementById('qs-missed').textContent  = d.missed_today??'0';
            document.getElementById('qs-avgtalk').textContent = fmtDur(d.avg_talk);
            document.getElementById('qs-avgwait').textContent = fmtDur(d.avg_wait);
            document.getElementById('qs-sla').textContent     = (d.sla??100)+'%';
        }).catch(()=>{});
}

// ── Agent Cards ────────────────────────────────────────────────────────────
function fetchAgents(){
    fetch('supervisor.php?action=agents&domain='+encodeURIComponent(domain), {credentials:'same-origin'})
        .then(r=>{
            if (r.status === 401) {
                window.location.href = '/?path=' + encodeURIComponent(window.location.pathname + window.location.search);
                return null;
            }
            return r.json();
        })
        .then(d=>{
            if (!d) return;
            _allAgents = d.agents||[];
            // keep current page valid after refresh
            const maxPage = Math.max(0, Math.ceil(_allAgents.length / _agentsPerPage) - 1);
            if (_agentPage > maxPage) _agentPage = maxPage;
            renderAgentPage();
            renderLeaveFromAgents(_allAgents);
        }).catch(()=>{});
}

function fetchLeaveRequests(){
    fetch('supervisor.php?action=leave_requests&domain='+encodeURIComponent(domain), {credentials:'same-origin'})
        .then(r=>r.json())
        .then(d=>{
            renderLeaveRequests(d.requests||[]);
        }).catch(()=>{});
}

function renderLeaveFromAgents(agents){
    const pending = (agents||[]).filter(a=>a.leave_pending).map(a=>({
        id: a.leave_pending.id,
        agent_ext: a.ext,
        agent_name: a.name,
        request_type: a.leave_pending.request_type,
        reason: a.leave_pending.reason,
        requested_at: a.leave_pending.requested_at
    }));
    // Prefer dedicated API list when available; this keeps badge in sync with agent poll
    if (pending.length) renderLeaveRequests(pending);
    else fetchLeaveRequests();
}

function renderLeaveRequests(requests){
    const list = document.getElementById('leaveRequestsList');
    const badge = document.getElementById('leaveReqCount');
    if (!list) return;
    const n = (requests||[]).length;
    if (badge) {
        badge.textContent = String(n);
        badge.style.display = n ? 'inline-block' : 'none';
    }
    if (!n) {
        list.innerHTML = '<div style="color:#aaa;font-size:13px">No pending leave requests.</div>';
        return;
    }
    list.innerHTML = requests.map(r=>{
        const typeLbl = r.request_type === 'Logged Out' ? 'Logout' : 'Break';
        const reason = r.reason ? `<div style="font-size:12px;color:#666;margin-top:2px">${escHtml(r.reason)}</div>` : '';
        return `<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0">
            <div style="min-width:0">
                <div style="font-weight:600;font-size:13px;color:#222">${escHtml(r.agent_name||('Ext '+r.agent_ext))} <span style="color:#888;font-weight:400">· Ext ${escHtml(r.agent_ext)}</span></div>
                <div style="font-size:12px;color:#b45309;margin-top:2px">Requesting ${typeLbl}${r.requested_at?' · '+escHtml(r.requested_at):''}</div>
                ${reason}
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0">
                <button onclick="resolveLeave(${Number(r.id)},'approved')" style="background:#2e7d32;color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600">Approve</button>
                <button onclick="resolveLeave(${Number(r.id)},'denied')" style="background:#c62828;color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600">Deny</button>
            </div>
        </div>`;
    }).join('');
}

function escHtml(s){
    return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function resolveLeave(id, decision){
    const verb = decision === 'approved' ? 'approve' : 'deny';
    if (!confirm(verb.charAt(0).toUpperCase()+verb.slice(1)+' this leave request?')) return;
    fetch('supervisor.php?action=resolve_leave', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: id, decision: decision, domain: domain })
    })
    .then(r=>r.json())
    .then(d=>{
        if (d.ok) {
            toast(decision==='approved'?'Leave approved — status applied':'Leave request denied', decision==='approved'?'#2e7d32':'#c62828');
            fetchAgents();
            fetchLeaveRequests();
        } else {
            toast('Failed: '+(d.error||'Unknown'),'#c62828');
        }
    })
    .catch(e=>toast('Network error: '+e.message,'#c62828'));
}

let _allAgents = [], _agentPage = 0, _agentsPerPage = 12, _openAgentExt = null;

function agentPage(dir) {
    const maxPage = Math.ceil(_allAgents.length / _agentsPerPage) - 1;
    _agentPage = Math.max(0, Math.min(maxPage, _agentPage + dir));
    renderAgentPage();
}

function renderAgentPage() {
    const start = _agentPage * _agentsPerPage;
    const slice = _allAgents.slice(start, start + _agentsPerPage);
    const total = _allAgents.length;
    const maxPage = Math.ceil(total / _agentsPerPage);
    document.getElementById('agentPageInfo').textContent =
        total > _agentsPerPage ? `${start+1}–${Math.min(start+_agentsPerPage,total)} of ${total}` : `${total} agent${total!==1?'s':''}`;
    document.getElementById('agentPrev').style.opacity = _agentPage===0?'0.3':'1';
    document.getElementById('agentNext').style.opacity = _agentPage>=maxPage-1?'0.3':'1';
    renderAgents(slice);
}

function renderAgents(agents){
    const grid = document.getElementById('agentsGrid');
    if(!agents.length){grid.innerHTML='<div style="color:#aaa;padding:20px">No agents found in this domain.</div>';return;}

    // Prefer keeping In Call agents visible first within the current page slice
    const order = {incall:0, ready:1, acw:2, break:3, lunch:4, meeting:5, offline:6};
    agents = agents.slice().sort((a,b)=>(order[a.status]??9)-(order[b.status]??9));

    grid.innerHTML = agents.map(a=>{
        const col    = statusColor(a.status);
        const lbl    = statusLabel(a.status);
        const ini    = initials(a.name);
        const inCall = a.status==='incall';
        const onCall = inCall && a.on_call_with ? a.on_call_with : '—';
        const isOpen = window._openAgentExt === a.ext;

        const rate      = a.total_calls>0 ? Math.round((a.answered/a.total_calls)*100) : 0;
        const rateLabel = a.total_calls===0?'—':rate+'%';
        const monDisabled = inCall ? '' : 'disabled title="Agent is not on a call"';
        const leaveBadge = a.leave_pending
            ? `<span style="display:inline-block;margin-left:6px;background:#fef3c7;color:#b45309;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;vertical-align:middle">LEAVE</span>`
            : '';

        return `<div class="agent-row ${a.status}${isOpen?' open':''}" id="row-${a.ext}">
            <div class="agent-row-main" onclick="toggleAgentRow('${a.ext}')">
                <div class="agent-avatar avatar-${a.status}" style="background:${col}">${ini}</div>
                <div>
                    <div class="row-name">${a.name}${leaveBadge}</div>
                    <div class="row-ext">Ext ${a.ext}${a.leave_pending?' · wants '+(a.leave_pending.request_type==='Logged Out'?'logout':'break'):''}</div>
                </div>
                <div><span class="status-badge badge-${a.status}">${lbl}</span></div>
                <div class="row-oncall">${onCall}</div>
                <div class="row-meta" style="text-align:center">${a.answered}</div>
                <div class="row-meta missed" style="text-align:center">${a.missed}</div>
                <div class="row-meta talk" style="text-align:center">${fmtSec(a.total_talk)}</div>
                <div class="row-chevron">&#8250;</div>
            </div>
            <div class="agent-row-detail">
                <div class="detail-metrics">
                    <div class="detail-metric">
                        <div class="detail-metric-lbl">Answer Rate</div>
                        <div class="detail-metric-val">${rateLabel}</div>
                    </div>
                    <div class="detail-metric">
                        <div class="detail-metric-lbl">Avg Call</div>
                        <div class="detail-metric-val">${fmtDur(a.avg_dur)}</div>
                    </div>
                    <div class="detail-metric">
                        <div class="detail-metric-lbl">Total Calls</div>
                        <div class="detail-metric-val">${a.total_calls||0}</div>
                    </div>
                </div>
                <div class="detail-actions">
                    <button class="btn-listen"  ${monDisabled} onclick="event.stopPropagation();monitor('${a.ext}','listen')">&#128266; Listen</button>
                    <button class="btn-whisper" ${monDisabled} onclick="event.stopPropagation();monitor('${a.ext}','whisper')">&#128172; Whisper</button>
                    <button class="btn-barge"   ${monDisabled} onclick="event.stopPropagation();monitor('${a.ext}','barge')">&#128483; Barge</button>
                    <button class="btn-force-avail" onclick="event.stopPropagation();forceStatus('${a.ext}','Available')">&#10003; Set Available</button>
                    <button class="btn-force-break" onclick="event.stopPropagation();forceStatus('${a.ext}','On Break')">&#9749; Set Break</button>
                    <button class="btn-force-out"   onclick="event.stopPropagation();forceStatus('${a.ext}','Logged Out')">&#10006; Force Sign-Out</button>
                </div>
            </div>
        </div>`;
    }).join('');
}

function toggleAgentRow(ext){
    const row = document.getElementById('row-'+ext);
    if(!row) return;
    const wasOpen = row.classList.contains('open');
    document.querySelectorAll('.agent-row.open').forEach(r=>r.classList.remove('open'));
    if(!wasOpen){
        row.classList.add('open');
        window._openAgentExt = ext;
    } else {
        window._openAgentExt = null;
    }
}

// ── Monitor / Barge ────────────────────────────────────────────────────────
function monitor(agentExt, mode){
    let myExt = supExt || localStorage.getItem('sup_ext') || '';
    if(!myExt){
        myExt = prompt('Enter YOUR supervisor extension (the phone that will ring):');
        if(!myExt){toast('Cancelled — no supervisor extension set','#c62828');return;}
        localStorage.setItem('sup_ext', myExt);
    }
    toast('&#128266; Connecting '+mode+' — your phone (ext '+myExt+') will ring in 5-10 sec...');
    fetch('supervisor.php?action=monitor&mode='+mode+'&agent_ext='+encodeURIComponent(agentExt)+'&sup_ext='+encodeURIComponent(myExt)+'&domain='+encodeURIComponent(domain))
        .then(r=>r.json()).then(d=>{
            if(d.ok) toast('&#128266; '+mode.charAt(0).toUpperCase()+mode.slice(1)+' started — pick up your phone (ext '+myExt+')','#2e7d32');
            else toast('Monitor failed: '+(d.error||d.result||'Agent not on a call'),'#c62828');
        }).catch(e=>toast('Network error: '+e.message,'#c62828'));
}

// ── Force Status ───────────────────────────────────────────────────────────
function forceStatus(agentExt, newStatus){
    if(!confirm('Set '+agentExt+' to "'+newStatus+'"?')) return;
    fetch('supervisor.php?action=force_status&agent_ext='+encodeURIComponent(agentExt)+'&status='+encodeURIComponent(newStatus)+'&domain='+encodeURIComponent(domain))
        .then(r=>r.json()).then(d=>{
            if(d.ok) { toast('Agent '+agentExt+' set to '+newStatus,'#2e7d32'); fetchAgents(); }
            else toast('Failed: '+(d.error||'Unknown'),'#c62828');
        });
}

// ── Leaderboard ────────────────────────────────────────────────────────────
function fetchLeaderboard(){
    const from=document.getElementById('lbFrom').value;
    const to=document.getElementById('lbTo').value;
    fetch('supervisor.php?action=leaderboard&domain='+encodeURIComponent(domain)+'&from='+from+'&to='+to)
        .then(r=>r.json()).then(d=>{
            const rows=d.rows||[];
            if(!rows.length){document.getElementById('lbBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No data for this period.</td></tr>';return;}
            const maxTalk=Math.max(...rows.map(r=>r.total_talk),1);
            document.getElementById('lbBody').innerHTML = rows.map((r,i)=>{
                const medals=['&#129351;','&#129352;','&#129353;'];
                const rank=i<3?`<span class="rank-medal">${medals[i]}</span>`:`<span class="lb-rank rank-other">${i+1}</span>`;
                const pct=Math.round((r.total_talk/maxTalk)*100);
                return `<tr>
                    <td style="text-align:center">${rank}</td>
                    <td><strong>${r.name}</strong><br><span style="color:#aaa;font-size:11px">Ext ${r.ext}</span></td>
                    <td><strong>${r.answered}</strong></td>
                    <td style="color:#f44336">${r.missed}</td>
                    <td>${fmtSec(r.total_talk)}
                        <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:${pct}%"></div></div>
                    </td>
                    <td>${fmtDur(r.avg_dur)}</td>
                    <td>
                        <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:${r.total_calls>0?Math.round((r.answered/r.total_calls)*95):0}%;background:#4caf50"></div></div>
                        <span style="font-size:10px;color:#888">${r.total_calls>0?Math.round((r.answered/r.total_calls)*95):100}% SLA</span>
                    </td>
                </tr>`;
            }).join('');
        });
}

// ── Call History ───────────────────────────────────────────────────────────
function fetchCallHistory(){
    const from=document.getElementById('chFrom').value;
    const to=document.getElementById('chTo').value;
    const q=document.getElementById('chSearch').value;
    fetch('supervisor.php?action=call_history_all&domain='+encodeURIComponent(domain)+'&from='+from+'&to='+to+'&search='+encodeURIComponent(q))
        .then(r=>r.json()).then(d=>{
            const rows=d.rows||[];
            document.getElementById('chCount').textContent=rows.length+' records';
            if(!rows.length){document.getElementById('chBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No calls found.</td></tr>';return;}
            document.getElementById('chBody').innerHTML=rows.map(r=>`<tr>
                <td>${r.time}</td>
                <td>${r.caller}</td>
                <td>${r.destination}</td>
                <td><span class="badge-${r.direction==='outbound'?'out':'in'}">${r.direction}</span></td>
                <td>${r.duration}</td>
                <td><span class="badge-${r.status==='Answered'?'answered':'missed'}">${r.status}</span></td>
                <td style="font-size:11px;color:#888">${r.cause}</td>
            </tr>`).join('');
        });
}

// ── ACW Review ─────────────────────────────────────────────────────────────
function fetchAcwAll(){
    const from=document.getElementById('acwFrom').value;
    const to=document.getElementById('acwTo').value;
    fetch('supervisor.php?action=acw_all&from='+from+'&to='+to)
        .then(r=>r.json()).then(d=>{
            const rows=d.records||[];
            document.getElementById('acwAllCount').textContent=rows.length+' records';
            if(!rows.length){document.getElementById('acwAllBody').innerHTML='<tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">No ACW records for this period.</td></tr>';return;}
            document.getElementById('acwAllBody').innerHTML=rows.map(r=>`<tr>
                <td>${r.created_at}</td>
                <td><strong>${r.agent_id}</strong></td>
                <td>${r.caller_id}</td>
                <td><span class="badge-${r.call_type==='Inbound'?'in':'out'}">${r.call_type}</span></td>
                <td>${fmtDur(r.duration)}</td>
                <td><strong>${r.disposition}</strong></td>
                <td>${r.call_reason}</td>
                <td style="max-width:200px;white-space:normal;font-size:11px;color:#666">${r.notes||'—'}</td>
            </tr>`).join('');
        });
}

// ── Tab switcher ───────────────────────────────────────────────────────────
function showTab(name){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    const panel = document.getElementById('tab-'+name);
    if (panel) panel.classList.add('active');
    const btn = document.querySelector('.tab-btn[data-tab="'+name+'"]');
    if (btn) btn.classList.add('active');
    else if (typeof event !== 'undefined' && event && event.target) event.target.classList.add('active');
    if(name==='leaderboard') fetchLeaderboard();
    if(name==='callhistory') fetchCallHistory();
    if(name==='acwall')      fetchAcwAll();
    if(name==='recordings')  fetchRecordings();
    if(name==='voicequality') fetchVoiceQuality();
    if(name==='skills') fetchSkillsAgents();
    if(name==='ahununu') {
        const f = document.getElementById('ahununuFrame');
        if (f.src === 'about:blank') f.src = 'https://ahununu.com/';
    }
}

function showTabDirect(name){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    const panel = document.getElementById('tab-'+name);
    if (panel) panel.classList.add('active');
    const btn = document.querySelector('.tab-btn[data-tab="'+name+'"]');
    if (btn) btn.classList.add('active');
    if(name==='leaderboard') fetchLeaderboard();
    if(name==='callhistory') fetchCallHistory();
    if(name==='acwall')      fetchAcwAll();
    if(name==='recordings')  fetchRecordings();
    if(name==='voicequality') fetchVoiceQuality();
    if(name==='skills') fetchSkillsAgents();
    if(name==='ahununu') {
        const f = document.getElementById('ahununuFrame');
        if (f && f.src === 'about:blank') f.src = 'https://ahununu.com/';
    }
}

// ── Init & auto-refresh ────────────────────────────────────────────────────
fetchQueue();
fetchAgents();
fetchLeaveRequests();

// ── Recordings ─────────────────────────────────────────────────────────────
function fetchRecordings(){
    const from=document.getElementById('recFrom').value;
    const to=document.getElementById('recTo').value;
    const q=document.getElementById('recSearch').value;
    document.getElementById('recBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr>';
    fetch('supervisor.php?action=recordings_all&domain='+encodeURIComponent(domain)+'&from='+from+'&to='+to+'&search='+encodeURIComponent(q))
        .then(r=>r.json()).then(d=>{
            const rows=d.rows||[];
            document.getElementById('recCount').textContent=rows.length+' recordings';
            if(!rows.length){
                document.getElementById('recBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No recordings found for this period.</td></tr>';
                return;
            }
            document.getElementById('recBody').innerHTML=rows.map(r=>{
                const playBtn = r.file
                    ? `<button onclick="playRec('${encodeURIComponent(r.path)}','${encodeURIComponent(r.file)}')"
                        style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:11px;font-weight:700">
                        &#9654; Play</button>`
                    : '<span style="color:#ccc;font-size:11px">No file</span>';
                return `<tr>
                    <td>${r.time}</td>
                    <td>${r.caller}</td>
                    <td>${r.destination}</td>
                    <td><span class="badge-${r.direction==='outbound'?'out':'in'}">${r.direction}</span></td>
                    <td>${r.duration}</td>
                    <td>${r.length||'–'}s</td>
                    <td>${playBtn}</td>
                </tr>`;
            }).join('');
        }).catch(()=>{
            document.getElementById('recBody').innerHTML='<tr><td colspan="7" style="text-align:center;color:#f44336;padding:20px">Error loading recordings.</td></tr>';
        });
}

function playRec(path, file){
    const player=document.getElementById('recPlayer');
    // .webm files served via FastAPI, .wav via FusionPBX built-in
    const url = file.endsWith('.webm')
        ? 'http://192.168.243.129:8001/api/recordings/'+encodeURIComponent(file)
        : '/app/recordings/index.php?filename='+encodeURIComponent(file)+'&path='+encodeURIComponent(path);
    player.src=url; player.style.display='block';
    player.play().catch(()=>{ toast('Could not play recording. File may have moved.','#c62828'); });
}

setInterval(()=>{ fetchQueue(); fetchAgents(); fetchLeaveRequests(); }, 10000);

// ── Voice Quality ──────────────────────────────────────────────────────────
function fetchVoiceQuality() {
    const from = document.getElementById('vqFrom').value;
    const to   = document.getElementById('vqTo').value;
    fetch(`supervisor.php?action=voice_quality&domain=${domain}&from=${from}&to=${to}`)
        .then(r=>r.json()).then(data=>{
            const tbody = document.getElementById('vqBody');
            const rows  = data.rows || [];
            document.getElementById('vqSummary').textContent = rows.length + ' calls';
            if (!rows.length) {
                tbody.innerHTML='<tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">No calls found</td></tr>';
                return;
            }
            // MOS color
            function mosColor(mos) {
                if (mos >= 4.0) return '#27ae60';
                if (mos >= 3.5) return '#f39c12';
                if (mos >= 3.0) return '#e67e22';
                return '#e74c3c';
            }
            function mosLabel(mos) {
                if (mos >= 4.0) return 'Excellent';
                if (mos >= 3.5) return 'Good';
                if (mos >= 3.0) return 'Fair';
                return 'Poor';
            }
            tbody.innerHTML = rows.map(r => {
                const mos   = parseFloat(r.mos || 0);
                const color = mosColor(mos);
                return `<tr>
                  <td>${r.call_time}</td>
                  <td>${r.caller_id_number}</td>
                  <td>${r.destination_number}</td>
                  <td><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:${r.direction==='inbound'?'rgba(56,139,253,.15)':'rgba(240,136,62,.15)'};color:${r.direction==='inbound'?'#58a6ff':'#f0883e'}">${r.direction}</span></td>
                  <td>${r.duration}</td>
                  <td><strong style="color:${color};font-size:15px">${mos.toFixed(2)}</strong></td>
                  <td><span style="color:${color};font-size:12px;font-weight:600">${mosLabel(mos)}</span></td>
                  <td style="font-size:11px;color:#aaa">${r.hangup_cause||''}</td>
                </tr>`;
            }).join('');
        }).catch(e=>{ document.getElementById('vqBody').innerHTML='<tr><td colspan="8" style="text-align:center;color:#e74c3c;padding:20px">Error: '+e.message+'</td></tr>'; });
}

function fetchSkillsAgents() {
    // Fetch agent list from supervisor API and display with skill tags
    fetch(`supervisor.php?action=agents&domain=${domain}`)
        .then(r=>r.json()).then(data=>{
            const agents = data.agents || data;
            const div = document.getElementById('skillsAgentList');
            const langColors = {Amharic:'#e74c3c',English:'#27ae60',Oromo:'#3498db',Other:'#f39c12'};
            const queueMap = {Amharic:'8001',English:'8002',Oromo:'8003'};
            if (!agents||!agents.length) { div.innerHTML='<p style="color:#666">No agents found</p>'; return; }
            div.innerHTML = agents.map(a => {
                const lang = a.language || 'English';
                const color = langColors[lang] || '#aaa';
                const queueExt = queueMap[lang] || '8000';
                const ext = a.ext || a.extension || '?';
                return `<div style="display:flex;align-items:center;justify-content:space-between;
                    padding:8px 12px;background:#fff;border-radius:6px;margin-bottom:8px;
                    border:1px solid #e0e0e0">
                    <div>
                        <strong>${a.name||ext}</strong>
                        <span style="color:#666;font-size:12px;margin-left:8px">Ext ${ext}</span>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span style="background:${color}22;color:${color};padding:2px 10px;
                            border-radius:10px;font-size:11px;font-weight:600">${lang}</span>
                        <span style="background:#f0f0f0;color:#666;padding:2px 8px;
                            border-radius:10px;font-size:11px">Queue ${queueExt}</span>
                    </div>
                </div>`;
            }).join('');
        }).catch(()=>{ document.getElementById('skillsAgentList').innerHTML='<p style="color:#999">Could not load agents</p>'; });
}

// Close Agent View dropdown when clicking outside
document.addEventListener('click', function(e) {
    const drop = document.getElementById('agentViewDrop');
    const btn  = document.getElementById('agentViewBtn');
    if (drop && btn && !btn.contains(e.target) && !drop.contains(e.target)) {
        drop.style.display = 'none';
    }
});
</script>
</body>
</html>
