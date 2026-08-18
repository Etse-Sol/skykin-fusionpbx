<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';
// SkyKin Technologies - Agent Dashboard Data API
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

skykin_require_login(true);

$agent_name  = isset($_GET['agent'])  ? $_GET['agent']  : 'Agent1';
$domain      = skykin_domain_param($_GET['domain'] ?? null);
$ext_override= isset($_GET['ext'])    ? $_GET['ext']    : null;

// Date filter (default = today)
$date_from = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-d');
$date_to   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
$today_start = strtotime($date_from . ' 00:00:00');
$today_end   = strtotime($date_to   . ' 23:59:59');

// Use FusionPBX's own database connection
$db = null;
try {
    // Parse FusionPBX config.conf format
    $db_host = '127.0.0.1';
    $db_port = '5432';
    $db_name = 'fusionpbx';
    $db_user = 'fusionpbx';
    $db_pass = '';

    $conf = '/etc/fusionpbx/config.conf';
    if (file_exists($conf)) {
        foreach (file($conf) as $line) {
            $line = trim($line);
            if (strpos($line, 'database.0.host') !== false) $db_host = trim(explode('=', $line)[1]);
            if (strpos($line, 'database.0.port') !== false) $db_port = trim(explode('=', $line)[1]);
            if (strpos($line, 'database.0.name') !== false) $db_name = trim(explode('=', $line)[1]);
            if (strpos($line, 'database.0.username') !== false) $db_user = trim(explode('=', $line)[1]);
            if (strpos($line, 'database.0.password') !== false) $db_pass = trim(explode('=', $line)[1]);
        }
    }
    $db = new PDO("pgsql:host={$db_host};port={$db_port};dbname={$db_name}", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    // Try unix socket as fallback
    try {
        $db = new PDO("pgsql:host=/var/run/postgresql;dbname=fusionpbx", 'fusionpbx', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e2) {
        echo json_encode(['total_calls'=>0,'answered_calls'=>0,'missed_calls'=>0,'avg_duration'=>0,'total_talk'=>0,'total_duration'=>0,'listening_duration'=>0,'internal_call_time'=>0,'outbound_time'=>0,'hook_on_times'=>0,'hold_times'=>0,'transfers'=>0,'forwarding_times'=>0,'acw_duration'=>0,'ivr_transfer'=>0,'busy_duration'=>0,'rest_duration'=>0,'over_rest'=>0,'idle_duration'=>0,'interceptions'=>0,'internal_help'=>0,'login_count'=>1,'force_signout'=>0,'listening_count'=>0,'third_party_count'=>0,'force_advisor_count'=>0,'handle_on_behalf'=>0,'ask_help_count'=>0,'call_reason_count'=>0,'queue_waiting'=>0,'agents_online'=>0,'avg_wait'=>0,'sla_rate'=>0,'recent_calls'=>[],'db_error'=>$e2->getMessage()]);
        exit;
    }
}

$today_start = isset($today_start) ? $today_start : strtotime(date('Y-m-d') . ' 00:00:00');
$today_end   = isset($today_end)   ? $today_end   : strtotime(date('Y-m-d') . ' 23:59:59');

$data = [
    'total_calls' => 0, 'answered_calls' => 0, 'missed_calls' => 0,
    'avg_duration' => 0, 'total_talk' => 0, 'total_duration' => 0,
    'listening_duration' => 0, 'internal_call_time' => 0, 'outbound_time' => 0,
    'hook_on_times' => 0, 'hold_times' => 0, 'transfers' => 0,
    'forwarding_times' => 0, 'acw_duration' => 0, 'ivr_transfer' => 0,
    'busy_duration' => 0, 'rest_duration' => 0, 'over_rest' => 0,
    'idle_duration' => 0, 'interceptions' => 0, 'internal_help' => 0,
    'login_count' => 1, 'force_signout' => 0, 'listening_count' => 0,
    'third_party_count' => 0, 'force_advisor_count' => 0, 'handle_on_behalf' => 0,
    'ask_help_count' => 0, 'call_reason_count' => 0,
    'queue_waiting' => 0, 'agents_online' => 0, 'avg_wait' => 0,
    'sla_rate' => 0, 'recent_calls' => []
];

try {
    // Get extension number for this agent
    $extension = $ext_override;
    if (!$extension) {
        // Try lookup by FusionPBX username
        try {
            $ext_stmt = $db->prepare("
                SELECT e.extension 
                FROM v_extensions e
                JOIN v_users u ON u.user_uuid = e.user_uuid
                WHERE u.username = :agent AND e.domain_name = :domain
                LIMIT 1
            ");
            $ext_stmt->execute([':agent' => $agent_name, ':domain' => $domain]);
            $ext_row = $ext_stmt->fetch(PDO::FETCH_ASSOC);
            $extension = $ext_row ? $ext_row['extension'] : null;
        } catch (Exception $ignored) {}

        // Fallback: if agent_name looks like an extension number, use it directly
        if (!$extension && preg_match('/^\d{2,6}$/', $agent_name)) {
            $extension = $agent_name;
        }
    }

    // Allow direct extension override from URL parameter (always wins)
    if (isset($_GET['ext']) && !empty($_GET['ext'])) {
        $extension = trim($_GET['ext']);
    }

    if (!$extension) {
        $data['db_error'] = 'Extension not resolved. Pass ?ext=101 in the URL or save SIP settings first. agent=' . $agent_name . ' domain=' . $domain;
    }

    if ($extension) {
        $data['resolved_ext'] = $extension;
        // Total calls today for this extension
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN billsec > 0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec = 0 THEN 1 ELSE 0 END) as missed,
                COALESCE(AVG(CASE WHEN billsec > 0 THEN billsec END), 0) as avg_dur,
                COALESCE(SUM(billsec), 0) as total_talk,
                COALESCE(SUM(duration), 0) as total_dur,
                SUM(CASE WHEN direction = 'outbound' THEN billsec ELSE 0 END) as outbound_time,
                SUM(CASE WHEN direction = 'local' THEN billsec ELSE 0 END) as internal_time,
                SUM(CASE WHEN xml_cdr.xml::text LIKE '%transferred%' THEN 1 ELSE 0 END) as transfers
            FROM v_xml_cdr xml_cdr
            WHERE domain_name = :domain
            AND (caller_id_number = :ext OR destination_number = :ext OR caller_destination = :ext
                 OR (cc_agent IN (
                    SELECT call_center_agent_uuid::text FROM v_call_center_agents
                    WHERE agent_id = :ext OR agent_contact LIKE '%/' || :ext || '@%'
                 ) AND destination_number ~ '^[+0-9]{3,}$'))
            AND start_epoch >= :today_start
            AND start_epoch <= :today_end
        ");
        $stmt->execute([
            ':domain' => $domain,
            ':ext' => $extension,
            ':today_start' => $today_start,
            ':today_end' => $today_end
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $data['total_calls']       = (int)$row['total'];
            $data['answered_calls']    = (int)$row['answered'];
            $data['missed_calls']      = (int)$row['missed'];
            $data['avg_duration']      = (int)$row['avg_dur'];
            $data['total_talk']        = (int)$row['total_talk'];
            $data['total_duration']    = (int)$row['total_dur'];
            $data['listening_duration']= (int)$row['total_talk'];
            $data['outbound_time']     = (int)$row['outbound_time'];
            $data['internal_call_time']= (int)$row['internal_time'];
            $data['hook_on_times']     = (int)$row['answered'];
            $data['transfers']         = (int)$row['transfers'];
            $data['forwarding_times']  = (int)$row['transfers'];
            $data['call_reason_count'] = (int)$row['answered'];

            // Estimated ACW (after call work) = ~15% of talk time
            $data['acw_duration']   = (int)($row['total_talk'] * 0.15);
            // Estimated hold = ~8% of talk time
            $data['hold_times']     = (int)($row['total_talk'] * 0.08);

            // Working time estimation
            $work_seconds = time() - strtotime(date('Y-m-d') . ' 08:00:00');
            $data['idle_duration']  = max(0, $work_seconds - $data['total_talk'] - $data['acw_duration']);
            $data['busy_duration']  = $data['total_talk'];

            // SLA rate (calls answered under 30s wait - estimated)
            $data['sla_rate'] = $data['total_calls'] > 0
                ? min(100, round(($data['answered_calls'] / $data['total_calls']) * 95))
                : 100;
        }

        // Recent calls
        $recent_stmt = $db->prepare("
            SELECT 
                to_char(to_timestamp(start_epoch), 'HH24:MI') as call_time,
                direction,
                caller_id_number,
                destination_number,
                billsec,
                duration,
                hangup_cause
            FROM v_xml_cdr
            WHERE domain_name = :domain
            AND (caller_id_number = :ext OR destination_number = :ext OR caller_destination = :ext
                 OR (cc_agent IN (
                    SELECT call_center_agent_uuid::text FROM v_call_center_agents
                    WHERE agent_id = :ext OR agent_contact LIKE '%/' || :ext || '@%'
                 ) AND destination_number ~ '^[+0-9]{3,}$'))
            AND start_epoch >= :today_start
            AND start_epoch <= :today_end
            ORDER BY start_epoch DESC
            LIMIT 500
        ");
        $recent_stmt->execute([
            ':domain' => $domain,
            ':ext' => $extension,
            ':today_start' => $today_start,
            ':today_end' => $today_end
        ]);
        $recent_rows = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recent_rows as $r) {
            $digits = preg_replace('/\D+/', '', (string)$r['destination_number']);
            $is_inbound = (strtolower((string)($r['direction'] ?? '')) === 'inbound')
                || ($r['destination_number'] == $extension)
                || ($r['destination_number'] == '8000')
                || (strpos((string)$r['destination_number'], '+') === 0)
                || (bool)preg_match('/11113875\d$/', (string)$digits);
            $type = $is_inbound ? 'Inbound' : 'Outbound';
            if ($is_inbound) {
                $cid = (string)($r['caller_id_number'] ?? '');
                $cid_digits = preg_replace('/\D+/', '', $cid);
                if (preg_match('/^(0?9\d{8}|2519\d{8})$/', (string)$cid_digits)) {
                    $number = $cid;
                } else {
                    $number = 'Unknown';
                }
            } else {
                $number = $r['destination_number'];
            }
            $answered = $r['billsec'] > 0;
            $mins = floor($r['billsec'] / 60);
            $secs = $r['billsec'] % 60;
            $data['recent_calls'][] = [
                'time'        => $r['call_time'],
                'type'        => $type,
                'number'      => $number ?: 'Unknown',
                'duration'    => sprintf('%d:%02d', $mins, $secs),
                'status'      => $answered ? 'Answered' : 'Missed',
                'disposition' => $answered ? 'Completed' : ($r['hangup_cause'] ?? 'No Answer')
            ];
        }

        // Agent status from call center
        $agent_stmt = $db->prepare("
            SELECT agent_status, COUNT(*) as cnt
            FROM v_call_center_agents
            WHERE domain_name = :domain
            AND agent_status = 'Available'
        ");
        $agent_stmt->execute([':domain' => $domain]);
        $agent_row = $agent_stmt->fetch(PDO::FETCH_ASSOC);
        $data['agents_online'] = $agent_row ? (int)$agent_row['cnt'] : 1;

        // Queue waiting calls
        $queue_stmt = $db->prepare("
            SELECT COUNT(*) as waiting
            FROM v_call_center_calls
            WHERE domain_name = :domain
            AND serving_agent IS NULL
        ");
        $queue_stmt->execute([':domain' => $domain]);
        $queue_row = $queue_stmt->fetch(PDO::FETCH_ASSOC);
        $data['queue_waiting'] = $queue_row ? (int)$queue_row['waiting'] : 0;
    }

    // --- DIAGNOSTIC: last 5 CDR rows for the domain (remove once working) ---
    try {
        $dbg = $db->prepare("
            SELECT caller_id_number, destination_number, billsec,
                   start_epoch, domain_name
            FROM v_xml_cdr
            WHERE domain_name = :domain
            ORDER BY start_epoch DESC
            LIMIT 5
        ");
        $dbg->execute([':domain' => $domain]);
        $data['_debug_cdr'] = $dbg->fetchAll(PDO::FETCH_ASSOC);
        $data['_debug_ext'] = $extension ?: 'NOT RESOLVED';
        $data['_debug_domain'] = $domain;
        $data['_debug_agent'] = $agent_name;
    } catch (Exception $ignored) {}

} catch (Exception $e) {
    // Return basic data on DB error - dashboard still works
    $data['db_error'] = $e->getMessage();
}

echo json_encode($data);
