<?php
// SkyKin Technologies - Real-time Agent Dashboard
session_start();

// ?? If called as data API, serve JSON and exit immediately ???????????????????
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $agent_name  = isset($_GET['agent'])  ? $_GET['agent']  : 'Agent1';
    $domain      = isset($_GET['domain']) ? $_GET['domain'] : 'client1.skykin.local';
    $date_from   = isset($_GET['from'])   && $_GET['from']  ? $_GET['from'] : date('Y-m-d');
    $date_to     = isset($_GET['to'])     && $_GET['to']    ? $_GET['to']   : date('Y-m-d');
    $today_start = strtotime($date_from . ' 00:00:00');
    $today_end   = strtotime($date_to   . ' 23:59:59');

    $extension = isset($_GET['ext']) && !empty($_GET['ext']) ? trim($_GET['ext']) : null;

    $data = ['total_calls'=>0,'answered_calls'=>0,'missed_calls'=>0,'avg_duration'=>0,'total_talk'=>0,
             'total_duration'=>0,'listening_duration'=>0,'internal_call_time'=>0,'outbound_time'=>0,
             'hook_on_times'=>0,'hold_times'=>0,'transfers'=>0,'forwarding_times'=>0,'acw_duration'=>0,
             'ivr_transfer'=>0,'busy_duration'=>0,'rest_duration'=>0,'over_rest'=>0,'idle_duration'=>0,
             'interceptions'=>0,'internal_help'=>0,'login_count'=>1,'force_signout'=>0,
             'listening_count'=>0,'third_party_count'=>0,'force_advisor_count'=>0,'handle_on_behalf'=>0,
             'ask_help_count'=>0,'call_reason_count'=>0,'queue_waiting'=>0,'agents_online'=>0,
             'avg_wait'=>0,'sla_rate'=>0,'recent_calls'=>[]];

    $db = null;
    try {
        $conf = '/etc/fusionpbx/config.conf';
        $h='127.0.0.1'; $p='5432'; $n='fusionpbx'; $u='fusionpbx'; $pw='';
        if (file_exists($conf)) {
            foreach (file($conf) as $ln) {
                $ln = trim($ln);
                if (strpos($ln,'database.0.host')     !== false) $h  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.port')     !== false) $p  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.name')     !== false) $n  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.username') !== false) $u  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.password') !== false) $pw = trim(explode('=',$ln,2)[1]);
            }
        }
        $db = new PDO("pgsql:host={$h};port={$p};dbname={$n}", $u, $pw, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e) {
        try { $db = new PDO("pgsql:host=/var/run/postgresql;dbname=fusionpbx",'fusionpbx','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
        catch (Exception $e2) { $data['db_error']=$e2->getMessage(); echo json_encode($data); exit; }
    }

    try {
        // Resolve extension via v_extension_users join (v_extensions has no user_uuid or domain_name column)
        if (!$extension) {
            $s = $db->prepare("SELECT e.extension FROM v_extensions e
                JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                JOIN v_users u ON u.user_uuid = eu.user_uuid
                JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                WHERE LOWER(u.username)=LOWER(:a) AND d.domain_name=:d LIMIT 1");
            $s->execute([':a'=>$agent_name,':d'=>$domain]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $extension = $r['extension'];
        }
        if (!$extension && preg_match('/^\d{2,6}$/',$agent_name)) $extension = $agent_name;

        $data['resolved_ext'] = $extension ?: 'NOT RESOLVED';

        if ($extension) {
            // Stats
            $s = $db->prepare("SELECT COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                COALESCE(AVG(CASE WHEN billsec>0 THEN billsec END),0) as avg_dur,
                COALESCE(SUM(billsec),0) as total_talk,
                COALESCE(SUM(duration),0) as total_dur,
                SUM(CASE WHEN direction='outbound' THEN billsec ELSE 0 END) as outbound_time,
                SUM(CASE WHEN direction='local' THEN billsec ELSE 0 END) as internal_time
                FROM v_xml_cdr WHERE domain_name=:d
                AND (caller_id_number=:e OR destination_number=:e)
                AND start_epoch>=:ts AND start_epoch<=:te");
            $s->execute([':d'=>$domain,':e'=>$extension,':ts'=>$today_start,':te'=>$today_end]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['total'] > 0) {
                $data['total_calls']       = (int)$row['total'];
                $data['answered_calls']    = (int)$row['answered'];
                $data['missed_calls']      = (int)$row['missed'];
                $data['avg_duration']      = (int)$row['avg_dur'];
                $data['total_talk']        = (int)$row['total_talk'];
                $data['total_duration']    = (int)$row['total_dur'];
                $data['listening_duration']= (int)$row['total_talk'];
                $data['outbound_time']     = (int)$row['outbound_time'];
                $data['hook_on_times']     = (int)$row['answered'];
                $data['acw_duration']      = (int)($row['total_talk']*0.15);
                $data['hold_times']        = (int)($row['total_talk']*0.08);
                $data['busy_duration']     = (int)$row['total_talk'];
                $data['idle_duration']     = max(0, time()-strtotime(date('Y-m-d').' 08:00:00') - $data['total_talk'] - $data['acw_duration']);
                $data['sla_rate']          = min(100, round(($data['answered_calls']/$data['total_calls'])*95));
            }

            // Recent calls
            $s2 = $db->prepare("SELECT to_char(to_timestamp(start_epoch),'HH24:MI') as call_time,
                direction,caller_id_number,destination_number,billsec,hangup_cause
                FROM v_xml_cdr WHERE domain_name=:d
                AND (caller_id_number=:e OR destination_number=:e)
                AND start_epoch>=:ts AND start_epoch<=:te
                ORDER BY start_epoch DESC LIMIT 500");
            $s2->execute([':d'=>$domain,':e'=>$extension,':ts'=>$today_start,':te'=>$today_end]);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $in   = ($r['destination_number']==$extension);
                $bill = (int)$r['billsec'];
                $data['recent_calls'][] = [
                    'time'       => $r['call_time'],
                    'type'       => $in ? 'Inbound' : 'Outbound',
                    'number'     => $in ? $r['caller_id_number'] : $r['destination_number'],
                    'duration'   => floor($bill/60).':'.str_pad($bill%60,2,'0',STR_PAD_LEFT),
                    'status'     => $bill>0 ? 'Answered' : 'Missed',
                    'disposition'=> $bill>0 ? 'Completed' : ($r['hangup_cause'] ?? 'No Answer')
                ];
            }

            // Agents online
            try {
                $sa = $db->prepare("SELECT COUNT(*) as cnt FROM v_call_center_agents WHERE domain_name=:d AND agent_status='Available'");
                $sa->execute([':d'=>$domain]);
                $ra = $sa->fetch(PDO::FETCH_ASSOC);
                $data['agents_online'] = $ra ? (int)$ra['cnt'] : 1;
            } catch(Exception $ignored){}
        }
    } catch (Exception $e) { $data['db_error']=$e->getMessage(); }

    echo json_encode($data);
    exit;
}

// ?? ACW History API ??????????????????????????????????????????????????????????
if (isset($_GET['action']) && $_GET['action'] === 'acw_history') {
    error_reporting(0);
    header('Content-Type: application/json');
    $ext       = isset($_GET['ext'])  ? trim($_GET['ext'])  : '';
    $date_from = isset($_GET['from']) ? $_GET['from']       : date('Y-m-d');
    $date_to   = isset($_GET['to'])   ? $_GET['to']         : date('Y-m-d');
    $db = null;
    try {
        $conf='/etc/fusionpbx/config.conf'; $h='127.0.0.1'; $p='5432'; $n='fusionpbx'; $u='fusionpbx'; $pw='';
        if (file_exists($conf)) foreach (file($conf) as $ln) {
            $ln=trim($ln);
            if (strpos($ln,'database.0.host')!==false)     $h=trim(explode('=',$ln,2)[1]);
            if (strpos($ln,'database.0.port')!==false)     $p=trim(explode('=',$ln,2)[1]);
            if (strpos($ln,'database.0.name')!==false)     $n=trim(explode('=',$ln,2)[1]);
            if (strpos($ln,'database.0.username')!==false) $u=trim(explode('=',$ln,2)[1]);
            if (strpos($ln,'database.0.password')!==false) $pw=trim(explode('=',$ln,2)[1]);
        }
        $db = new PDO("pgsql:host={$h};port={$p};dbname={$n}",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
            id SERIAL PRIMARY KEY, agent_id VARCHAR(50), caller_id VARCHAR(50),
            call_type VARCHAR(20), duration INTEGER, disposition VARCHAR(100),
            call_reason VARCHAR(200), notes TEXT, recording_filename VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW())");
        $s = $db->prepare("SELECT to_char(created_at,'HH24:MI') as time,
            caller_id,call_type,duration,disposition,call_reason,notes
            FROM skykin_acw WHERE agent_id=:ext
            AND DATE(created_at)>=:df AND DATE(created_at)<=:dt
            ORDER BY created_at DESC LIMIT 200");
        $s->execute([':ext'=>$ext,':df'=>$date_from,':dt'=>$date_to]);
        echo json_encode(['records'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['records'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ?? Save ACW (After-Call Work) to DB ????????????????????????????????????????
if (isset($_GET['action']) && $_GET['action'] === 'save_acw') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $db = null;
    try {
        $conf = '/etc/fusionpbx/config.conf';
        $h='127.0.0.1'; $p='5432'; $n='fusionpbx'; $u='fusionpbx'; $pw='';
        if (file_exists($conf)) {
            foreach (file($conf) as $ln) {
                $ln = trim($ln);
                if (strpos($ln,'database.0.host')     !== false) $h  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.port')     !== false) $p  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.name')     !== false) $n  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.username') !== false) $u  = trim(explode('=',$ln,2)[1]);
                if (strpos($ln,'database.0.password') !== false) $pw = trim(explode('=',$ln,2)[1]);
            }
        }
        $db = new PDO("pgsql:host={$h};port={$p};dbname={$n}", $u, $pw, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

        // Create ACW table if it doesn't exist
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
            id SERIAL PRIMARY KEY,
            agent_id VARCHAR(50),
            caller_id VARCHAR(50),
            call_type VARCHAR(20),
            duration INTEGER,
            disposition VARCHAR(100),
            call_reason VARCHAR(200),
            notes TEXT,
            recording_filename VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW()
        )");

        $s = $db->prepare("INSERT INTO skykin_acw
            (agent_id,caller_id,call_type,duration,disposition,call_reason,notes,recording_filename)
            VALUES (:a,:c,:ct,:d,:disp,:cr,:notes,:rec)");
        $s->execute([
            ':a'    => $input['agent_id']            ?? '',
            ':c'    => $input['caller_id']            ?? '',
            ':ct'   => $input['call_type']            ?? 'Outbound',
            ':d'    => (int)($input['duration']       ?? 0),
            ':disp' => $input['disposition']          ?? 'Completed',
            ':cr'   => $input['call_reason']          ?? '',
            ':notes'=> $input['notes']                ?? '',
            ':rec'  => $input['recording_filename']   ?? '',
        ]);
        echo json_encode(['saved'=>true]);
    } catch (Exception $e) {
        echo json_encode(['saved'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

$agent_name = isset($_GET['agent']) ? htmlspecialchars($_GET['agent']) : 'Agent1';
$domain = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : 'client1.skykin.local';

// Generate initials from agent name
preg_match('/([A-Za-z]+)(\d*)/', $agent_name, $m);
$initials = strtoupper(substr($m[1] ?? $agent_name, 0, 2));
if (!empty($m[2])) $initials = strtoupper($m[1][0]) . $m[2];

$today = date('Y-m-d');

// ?? Resolve the agent's extension from FusionPBX DB ??????????????????????????
$agent_ext      = '';
$agent_password = '';
$agent_wss      = 'wss://' . $_SERVER['HTTP_HOST'] . ':7443';
try {
    $conf = '/etc/fusionpbx/config.conf';
    $db_host = '127.0.0.1'; $db_port = '5432';
    $db_name = 'fusionpbx'; $db_user = 'fusionpbx'; $db_pass = '';
    if (file_exists($conf)) {
        foreach (file($conf) as $line) {
            $line = trim($line);
            if (strpos($line, 'database.0.host')     !== false) $db_host = trim(explode('=', $line, 2)[1]);
            if (strpos($line, 'database.0.port')     !== false) $db_port = trim(explode('=', $line, 2)[1]);
            if (strpos($line, 'database.0.name')     !== false) $db_name = trim(explode('=', $line, 2)[1]);
            if (strpos($line, 'database.0.username') !== false) $db_user = trim(explode('=', $line, 2)[1]);
            if (strpos($line, 'database.0.password') !== false) $db_pass = trim(explode('=', $line, 2)[1]);
        }
    }
    $pdb = new PDO("pgsql:host={$db_host};port={$db_port};dbname={$db_name}", $db_user, $db_pass,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1) Try: username match via v_extension_users (v_extensions has no user_uuid or domain_name column)
    $s = $pdb->prepare("SELECT e.extension, e.password FROM v_extensions e
                         JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                         JOIN v_users u ON u.user_uuid = eu.user_uuid
                         JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                         WHERE LOWER(u.username) = LOWER(:a) AND d.domain_name = :d LIMIT 1");
    $s->execute([':a' => $agent_name, ':d' => $domain]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) { $agent_ext = $row['extension']; $agent_password = $row['password']; }

    // 2) Try: caller-ID name contains agent name (join v_domains for domain filter)
    if (!$agent_ext) {
        $s2 = $pdb->prepare("SELECT e.extension, e.password FROM v_extensions e
                              JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                              WHERE d.domain_name = :d
                              AND (LOWER(e.description) LIKE :p OR LOWER(e.effective_caller_id_name) LIKE :p)
                              LIMIT 1");
        $s2->execute([':d' => $domain, ':p' => '%' . strtolower($agent_name) . '%']);
        $row2 = $s2->fetch(PDO::FETCH_ASSOC);
        if ($row2) { $agent_ext = $row2['extension']; $agent_password = $row2['password']; }
    }

    // 3) If agent_name is purely numeric treat it as an extension
    if (!$agent_ext && preg_match('/^\d{2,6}$/', $agent_name)) {
        $agent_ext = $agent_name;
    }
} catch (Exception $e) { /* silent ? JS will fall back to localStorage */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkyKin Agent Dashboard - <?php echo $agent_name; ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; }

/* ?? Header ?? */
.header {
    background: linear-gradient(135deg, #0047AB 0%, #00B4D8 100%);
    color: white; padding: 0 24px; height: 64px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    position: fixed; top: 0; left: 0; right: 0; z-index: 300; gap: 16px;
}
.header .logo { font-size: 20px; font-weight: bold; letter-spacing: 1px; white-space: nowrap; flex-shrink: 0; }
.header .logo span { color: #00e5ff; }
.header .agent-info {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.15); border-radius: 30px;
    padding: 6px 14px 6px 8px; flex-shrink: 0;
}
.agent-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255,255,255,0.3);
    display: flex; align-items: center; justify-content: center;
    font-weight: bold; font-size: 13px; flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.5);
}
.agent-text-info { display: flex; flex-direction: column; }
.agent-text-info .agent-name  { font-weight: bold; font-size: 13px; line-height: 1.2; white-space: nowrap; }
.agent-text-info .agent-domain{ font-size: 10px; opacity: 0.75; white-space: nowrap; }
.header-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.clock { font-size: 13px; opacity: 0.9; white-space: nowrap; }

/* ?? Status Dropdown ?? */
.status-drop-wrap { position: relative; flex-shrink: 0; }
.status-drop-btn {
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.35);
    color: white; padding: 6px 14px 6px 10px; border-radius: 20px; cursor: pointer;
    font-size: 12px; font-weight: bold; display: flex; align-items: center; gap: 7px;
}
.status-drop-btn:hover { background: rgba(255,255,255,0.25); }
.sdot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.sdot.ready    { background: #28a745; box-shadow: 0 0 0 3px rgba(40,167,69,0.3); animation: pulse 2s infinite; }
.sdot.notready { background: #dc3545; }
.sdot.brk      { background: #ffc107; }
.sdot.incall   { background: #17a2b8; animation: pulse 1s infinite; }
.status-drop-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 8px);
    background: white; border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18); min-width: 180px; overflow: hidden; z-index: 600;
}
.status-drop-menu.open { display: block; }
.s-opt {
    padding: 12px 16px; font-size: 13px; color: #333; cursor: pointer;
    display: flex; align-items: center; gap: 10px; transition: background 0.15s;
}
.s-opt:hover { background: #f0f5ff; }
.s-opt.logout { border-top: 1px solid #e9ecef; color: #dc3545; }
.s-opt .opt-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

/* ?? Layout ?? */
.main { margin-top: 64px; padding: 20px; margin-bottom: 20px; }

/* ?? Summary Cards ?? */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px; margin-bottom: 20px;
}
.card {
    background: white; border-radius: 10px; padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #0047AB;
    transition: transform 0.2s;
}
.card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,71,171,0.12); }
.card.green  { border-left-color: #28a745; }
.card.orange { border-left-color: #fd7e14; }
.card.red    { border-left-color: #dc3545; }
.card.teal   { border-left-color: #00B4D8; }
.card.purple { border-left-color: #6f42c1; }
.card.yellow { border-left-color: #ffc107; }
.card-label  { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.card-value  { font-size: 26px; font-weight: bold; color: #0047AB; }
.card.green  .card-value { color: #28a745; }
.card.orange .card-value { color: #fd7e14; }
.card.red    .card-value { color: #dc3545; }
.card.teal   .card-value { color: #00B4D8; }
.card.purple .card-value { color: #6f42c1; }
.card.yellow .card-value { color: #e6a800; }
.card-sub { font-size: 11px; color: #aaa; margin-top: 4px; }

/* ?? Section boxes ?? */
.section-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px; margin-bottom: 20px;
}
.section-box {
    background: white; border-radius: 10px; padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.section-title {
    font-size: 14px; font-weight: bold; color: #0047AB;
    border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
}
.section-title .dot { width: 8px; height: 8px; border-radius: 50%; background: #0047AB; display: inline-block; }

/* ?? Metric rows ?? */
.metric-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; border-bottom: 1px solid #f5f5f5; font-size: 13px;
}
.metric-row:last-child { border-bottom: none; }
.metric-name { color: #555; }
.metric-val { font-weight: bold; color: #333; }
.metric-val.good { color: #28a745; }
.metric-val.warn { color: #fd7e14; }
.metric-val.bad  { color: #dc3545; }

/* ?? Progress bars ?? */
.progress-wrap { margin-top: 6px; }
.progress-label { display: flex; justify-content: space-between; font-size: 11px; color: #888; margin-bottom: 3px; }
.progress-bar { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 3px; transition: width 1s; }
.progress-fill.blue   { background: #0047AB; }
.progress-fill.green  { background: #28a745; }
.progress-fill.orange { background: #fd7e14; }

/* ?? Full section ?? */
.full-section {
    background: white; border-radius: 10px; padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px;
}

/* ?? Table ?? */
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th {
    background: #f8f9fa; padding: 10px 12px; text-align: left;
    font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 0.5px;
    border-bottom: 2px solid #e9ecef;
}
.data-table td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; }
.data-table tr:hover td { background: #f8fbff; }
.badge { padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
.badge-in       { background: #d4edda; color: #28a745; }
.badge-out      { background: #d1ecf1; color: #00B4D8; }
.badge-missed   { background: #f8d7da; color: #dc3545; }
.badge-transfer { background: #e2d9f3; color: #6f42c1; }

/* ?? Live dot ?? */
.live-dot {
    display: inline-block; width: 8px; height: 8px;
    background: #28a745; border-radius: 50%; animation: pulse 1.5s infinite; margin-right: 6px;
}
@keyframes pulse {
    0%   { box-shadow: 0 0 0 0 rgba(40,167,69,0.5); }
    70%  { box-shadow: 0 0 0 6px rgba(40,167,69,0); }
    100% { box-shadow: 0 0 0 0 rgba(40,167,69,0); }
}

/* ?? Tab bar (call history / recordings) ?? */
.tab-bar {
    display: flex; gap: 4px; margin-bottom: 16px;
    border-bottom: 2px solid #e9ecef; padding-bottom: 0;
}
.tab-btn {
    padding: 8px 20px; font-size: 13px; font-weight: 600;
    border: none; background: none; cursor: pointer;
    color: #888; border-bottom: 2px solid transparent; margin-bottom: -2px;
    transition: all 0.15s; border-radius: 4px 4px 0 0;
}
.tab-btn.active { color: #0047AB; border-bottom-color: #0047AB; background: #f0f5ff; }
.tab-btn:hover:not(.active) { color: #0047AB; background: #f8f9fa; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ?? Date filter ?? */
.date-filter { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.date-filter label { font-size: 12px; color: #888; }
.date-filter input[type=date] {
    border: 1px solid #ddd; border-radius: 6px; padding: 5px 10px;
    font-size: 13px; color: #333; outline: none;
}
.date-filter input[type=date]:focus { border-color: #0047AB; }
.btn-filter       { background: #0047AB; color: white; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-size: 12px; }
.btn-filter:hover { background: #003a8c; }
.btn-filter-clear { background: #e9ecef; color: #555; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; }

/* ?? Floating Phone Widget ?? */
.phone-fab {
    position: fixed; bottom: 28px; right: 28px; z-index: 500;
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #0047AB, #00B4D8);
    border: none; cursor: pointer; color: white; font-size: 24px;
    box-shadow: 0 4px 20px rgba(0,71,171,0.45);
    display: flex; align-items: center; justify-content: center;
    transition: transform 0.2s, box-shadow 0.2s;
}
.phone-fab:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(0,71,171,0.55); }
.phone-fab.incall { background: linear-gradient(135deg, #dc3545, #c82333); animation: fabPulse 1.5s infinite; }
.phone-fab.ringing { background: linear-gradient(135deg, #28a745, #1e7e34); animation: fabPulse 0.6s infinite; }
@keyframes fabPulse {
    0%,100% { box-shadow: 0 4px 20px rgba(0,71,171,0.45); }
    50%      { box-shadow: 0 4px 32px rgba(0,71,171,0.7), 0 0 0 10px rgba(0,71,171,0.1); }
}
.fab-badge {
    position: absolute; top: -2px; right: -2px;
    width: 16px; height: 16px; border-radius: 50%;
    background: #28a745; border: 2px solid white; display: none;
}
.fab-badge.show { display: block; }
.fab-badge.unreg { background: #888; }
.fab-badge.calling { background: #ffc107; animation: pulse 0.5s infinite; }

/* Static phone side panel */
.phone-popup {
    position: fixed; top: 60px; right: -320px; z-index: 499;
    width: 300px; max-height: calc(100vh - 60px);
    background: white; border-left: 1px solid #e0e0e0;
    box-shadow: -4px 0 20px rgba(0,0,0,0.12);
    display: flex; flex-direction: column;
    overflow-y: auto; overflow-x: hidden;
    transition: right 0.3s ease;
}
.phone-popup.open { right: 0; }
.pp-body { flex-shrink: 0; }
.dp-panel { flex-shrink: 0; }
.pp-footer { flex-shrink: 0; border-top: 1px solid #f0f0f0; padding: 10px 16px; }
/* Shift main content when panel is open */
body.phone-open .content-wrapper { margin-right: 300px; transition: margin-right 0.3s ease; }
.pp-header {
    background: linear-gradient(135deg, #0047AB, #00B4D8);
    color: white; padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between;
}
.pp-status { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.sip-dot { width: 10px; height: 10px; border-radius: 50%; background: #888; flex-shrink: 0; }
.sip-dot.registered { background: #28a745; animation: pulse 2s infinite; }
.sip-dot.calling    { background: #ffc107; animation: pulse 0.5s infinite; }
.sip-dot.incall     { background: #28a745; }
.sip-dot.ringing    { background: #fd7e14; animation: pulse 0.4s infinite; }
.sip-dot.failed     { background: #dc3545; }
.sip-dot.connecting { background: #aaa; animation: pulse 1s infinite; }
.pp-close {
    background: rgba(255,255,255,0.2); border: none; color: white;
    width: 26px; height: 26px; border-radius: 50%; cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
}
.pp-body { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.dial-input-wrap { display: flex; gap: 6px; }
.dial-input {
    flex: 1; border: 1px solid #ddd; border-radius: 8px;
    padding: 9px 12px; font-size: 15px; letter-spacing: 2px; outline: none; color: #0047AB;
}
.dial-input:focus { border-color: #0047AB; }
.dial-input::placeholder { color: #ccc; letter-spacing: 0; font-size: 13px; }
.btn-dialpad {
    background: #f0f2f5; border: 1px solid #ddd; border-radius: 8px;
    width: 40px; cursor: pointer; font-size: 18px; color: #555;
    display: flex; align-items: center; justify-content: center;
}
.btn-dialpad:hover { background: #e2e8f0; }
.call-controls {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.btn-call {
    grid-column: span 2;
    background: #28a745; border: none; color: white;
    padding: 12px 0; border-radius: 8px; cursor: pointer;
    font-size: 14px; font-weight: bold;
}
.btn-call:hover { background: #218838; }
.btn-call:disabled { background: #ccc; cursor: not-allowed; }
.btn-hangup {
    grid-column: span 2;
    background: #dc3545; border: none; color: white;
    padding: 12px 0; border-radius: 8px; cursor: pointer;
    font-size: 14px; font-weight: bold; display: none;
}
.btn-hangup:hover { background: #c82333; }
.btn-hold {
    background: #ffc107; border: none; color: #333;
    padding: 12px 0; border-radius: 8px; cursor: pointer;
    font-size: 13px; font-weight: bold; display: none;
}
.btn-mute {
    background: #6c757d; border: none; color: white;
    padding: 12px 0; border-radius: 8px; cursor: pointer;
    font-size: 13px; font-weight: bold; display: none;
}
.btn-mute.muted { background: #dc3545; }
.btn-record {
    grid-column: span 2;
    background: #fff0f0; border: 1px solid #f5c6cb; color: #dc3545;
    padding: 11px 0; border-radius: 8px; cursor: pointer;
    font-size: 12px; font-weight: bold; display: none; align-items: center;
    justify-content: center; gap: 5px;
}
.btn-record.recording { background: #dc3545; color: white; border-color: #dc3545; }
.btn-record.visible   { display: flex; }
.rec-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.btn-record.recording .rec-dot { animation: pulse 1s infinite; }
.call-timer {
    text-align: center; font-size: 22px; font-weight: bold;
    color: #0047AB; display: none; padding: 4px 0;
}
.pp-footer {
    padding: 10px 16px; border-top: 1px solid #f0f0f0;
    display: flex; justify-content: flex-end;
}
.btn-settings {
    background: none; border: none; color: #aaa;
    cursor: pointer; font-size: 12px; padding: 4px 8px;
    border-radius: 6px;
}
.btn-settings:hover { background: #f0f0f0; color: #555; }

/* ?? Incoming call overlay ?? */
.incoming-overlay {
    display: none; position: fixed; top: 80px; right: 20px; z-index: 9999;
    background: white; border-radius: 12px; padding: 20px 24px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3); min-width: 260px;
    border-top: 4px solid #28a745; animation: slideIn 0.3s ease; pointer-events: all;
}
@keyframes slideIn { from { transform: translateX(100px); opacity:0; } to { transform: translateX(0); opacity:1; } }
.incoming-title  { font-size: 12px; color: #888; text-transform: uppercase; margin-bottom: 4px; }
.incoming-number { font-size: 22px; font-weight: bold; color: #0047AB; margin-bottom: 16px; }
.incoming-actions { display: flex; gap: 10px; }
.btn-answer  { background: #28a745; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; flex: 1; }
.btn-decline { background: #dc3545; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; flex: 1; }

/* ?? Settings modal ?? */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-box { background: white; border-radius: 12px; padding: 28px; width: 360px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
.modal-title { font-size: 16px; font-weight: bold; color: #0047AB; margin-bottom: 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
.form-group input { width: 100%; border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px; font-size: 14px; }
.btn-save-settings { background: #0047AB; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; width: 100%; margin-top: 8px; }

/* ?? Dial Pad (inline inside popup) ?? */
.dp-panel {
    display: block; padding: 0 16px 16px;
}
.dp-panel.open { display: block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.dp-display {
    background: #f0f4ff; border-radius: 8px; padding: 10px 14px;
    font-size: 20px; font-weight: bold; color: #0047AB;
    text-align: center; letter-spacing: 3px; min-height: 44px;
    margin-bottom: 12px; display: flex; align-items: center; justify-content: center;
    word-break: break-all;
}
.dp-display.empty { color: #ccc; font-size: 12px; letter-spacing: 0; font-weight: normal; }
.dp-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 8px;
}
.dp-key {
    background: #f0f2f5; border: none; border-radius: 8px;
    padding: 12px 0; font-size: 16px; font-weight: bold; color: #333;
    cursor: pointer; text-align: center; transition: background 0.1s;
    display: flex; flex-direction: column; align-items: center; line-height: 1.2;
}
.dp-key:hover  { background: #dce3f0; }
.dp-key:active { background: #c8d4f0; transform: scale(0.95); }
.dp-key .dp-sub { font-size: 8px; color: #999; font-weight: normal; margin-top: 1px; }
.dp-row-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.dp-call { background: #28a745; color: white; border: none; border-radius: 8px; padding: 12px 0; font-size: 15px; font-weight: bold; cursor: pointer; }
.dp-call:hover { background: #218838; }
.dp-del  { background: #fff0f0; color: #dc3545; border: none; border-radius: 8px; padding: 12px 0; font-size: 18px; cursor: pointer; }
.dp-del:hover  { background: #ffd5d5; }

/* ?? Pagination ?? */
.pagination-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0 2px; gap: 12px; flex-wrap: wrap;
}
.page-controls { display: flex; align-items: center; gap: 4px; }
.page-btn {
    background: white; border: 1px solid #ddd; color: #555;
    padding: 5px 10px; border-radius: 6px; cursor: pointer; font-size: 12px;
    transition: all 0.15s;
}
.page-btn:hover:not([disabled]) { background: #0047AB; color: white; border-color: #0047AB; }
.page-btn[disabled] { opacity: 0.35; cursor: not-allowed; }
.page-numbers { display: flex; gap: 4px; }
.page-num {
    background: white; border: 1px solid #ddd; color: #555;
    min-width: 30px; height: 30px; padding: 0 6px; border-radius: 6px; cursor: pointer;
    font-size: 12px; display: flex; align-items: center; justify-content: center;
    transition: all 0.15s;
}
.page-num:hover   { background: #e8f0fe; border-color: #0047AB; color: #0047AB; }
.page-num.active  { background: #0047AB; color: white; border-color: #0047AB; font-weight: bold; }
.page-num.dots    { border: none; cursor: default; background: none; color: #aaa; }
.page-num.dots:hover { background: none; color: #aaa; }

/* ?? Recording History ?? */
.rec-empty { text-align: center; color: #aaa; padding: 30px; font-size: 13px; }
.rec-play     { background: #0047AB; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }
.rec-play:hover { background: #003a8c; }
.rec-download { background: #e9ecef; color: #555; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-left: 4px; }

/* ?? Footer ?? */
.footer { text-align: center; font-size: 11px; color: #aaa; padding: 16px; }

@media (max-width: 768px) {
    .section-grid  { grid-template-columns: 1fr; }
    .summary-grid  { grid-template-columns: repeat(2, 1fr); }
    .dialpad-box   { width: 100%; border-radius: 16px 16px 0 0; }
}

/* ?? ACW Modal ??????????????????????????? */
.acw-overlay {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(15,23,42,0.65); backdrop-filter: blur(4px);
    align-items: center; justify-content: center;
}
.acw-overlay.show { display: flex; }
.acw-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25); overflow: hidden;
    animation: slideUp 0.2s ease;
}
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.acw-hdr {
    padding: 16px 20px; border-bottom: 1px solid #e9ecef;
    display: flex; align-items: center; justify-content: space-between;
    background: #f8faff;
}
.acw-hdr h3 { font-size: 14px; font-weight: 700; color: #0047AB; margin: 0; }
.acw-hdr button { background: none; border: none; cursor: pointer; font-size: 18px; color: #888; line-height: 1; }
.acw-hdr button:hover { color: #333; }
.acw-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.acw-summary {
    background: #f0f5ff; border-radius: 8px; padding: 12px 14px;
    font-size: 12px; color: #555; line-height: 1.8;
}
.acw-summary strong { color: #0047AB; }
.acw-body label { font-size: 11px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: -8px; }
.acw-body select, .acw-body input[type=text], .acw-body textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #dce3ee; border-radius: 8px;
    font-size: 13px; color: #333; background: #fafbfc;
    outline: none; font-family: inherit; resize: vertical;
}
.acw-body select:focus, .acw-body input:focus, .acw-body textarea:focus { border-color: #0047AB; }
.acw-actions { display: flex; gap: 10px; margin-top: 4px; }
.acw-actions .btn-skip {
    flex: 1; padding: 10px; border: 1px solid #dce3ee; background: #fff;
    border-radius: 8px; font-size: 13px; font-weight: 600; color: #666; cursor: pointer;
}
.acw-actions .btn-skip:hover { background: #f5f5f5; }
.acw-actions .btn-submit {
    flex: 2; padding: 10px; background: #0047AB; color: #fff;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
}
.acw-actions .btn-submit:hover { background: #003a8c; }
/* ?? Toast notification ?? */
#sysToast {
    position: fixed; bottom: 24px; right: 24px; z-index: 2000;
    background: #1e293b; color: #f1f5f9; padding: 12px 18px;
    border-radius: 10px; font-size: 13px; max-width: 320px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3); display: none;
    animation: slideUp 0.2s ease;
}
</style>
</head>
<body>

<!-- ?? HEADER ?? -->
<div class="header">
    <div class="logo">SKY<span>KIN</span> Technologies</div>
    <div class="agent-info">
        <div class="agent-avatar"><?php echo $initials; ?></div>
        <div class="agent-text-info">
            <span class="agent-name"><?php echo $agent_name; ?></span>
            <span class="agent-domain"><?php echo $domain; ?></span>
        </div>
    </div>
    <div class="header-right">
        <span class="live-dot"></span>
        <span style="font-size:12px;">Live</span>
        <div class="clock" id="liveClock"></div>

        <!-- Ready / Not Ready / Logout dropdown -->
        <div class="status-drop-wrap">
            <button class="status-drop-btn" id="statusDropBtn" onclick="toggleStatusMenu()">
                <span class="sdot ready" id="statusDot"></span>
                <span id="statusLabel">Ready</span>
                <span style="font-size:10px;opacity:.7;">?</span>
            </button>
            <div class="status-drop-menu" id="statusDropMenu">
                <div class="s-opt" onclick="setAgentStatus('ready')">
                    <span class="opt-dot" style="background:#10b981"></span> Available
                </div>
                <div class="s-opt" onclick="setAgentStatus('idle')">
                    <span class="opt-dot" style="background:#64748b"></span> Idle
                </div>
                <div class="s-opt" onclick="setAgentStatus('break')">
                    <span class="opt-dot" style="background:#0ea5e9"></span> On Break
                </div>
                <div class="s-opt" onclick="setAgentStatus('acw')">
                    <span class="opt-dot" style="background:#6366f1"></span> Wrap-up (ACW)
                </div>
                <div class="s-opt logout" onclick="setAgentStatus('logout')">
                    <span class="opt-dot" style="background:#ef4444"></span> Logout
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ?? MAIN ?? -->
<div class="main">

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="card green">
            <div class="card-label">Total Calls Today</div>
            <div class="card-value" id="totalCalls">0</div>
            <div class="card-sub">Inbound + Outbound</div>
        </div>
        <div class="card teal">
            <div class="card-label">Avg Call Duration</div>
            <div class="card-value" id="avgDuration">0:00</div>
            <div class="card-sub">Minutes:Seconds</div>
        </div>
        <div class="card">
            <div class="card-label">Total Talk Time</div>
            <div class="card-value" id="totalTalk">0:00</div>
            <div class="card-sub">Today</div>
        </div>
        <div class="card orange">
            <div class="card-label">Idle Duration</div>
            <div class="card-value" id="idleTime">0:00</div>
            <div class="card-sub">Between calls</div>
        </div>
        <div class="card red">
            <div class="card-label">Missed Calls</div>
            <div class="card-value" id="missedCalls">0</div>
            <div class="card-sub">Unanswered</div>
        </div>
        <div class="card purple">
            <div class="card-label">Transfers</div>
            <div class="card-value" id="transfers">0</div>
            <div class="card-sub">Forwarded calls</div>
        </div>
        <div class="card yellow">
            <div class="card-label">Hold Times</div>
            <div class="card-value" id="holdTimes">0:00</div>
            <div class="card-sub">Total hold duration</div>
        </div>
        <div class="card green">
            <div class="card-label">Working Duration</div>
            <div class="card-value" id="workDuration">0:00</div>
            <div class="card-sub">Since login</div>
        </div>
    </div>

    <!-- Two Column Metrics -->
    <div class="section-grid">
        <div class="section-box">
            <div class="section-title"><span class="dot"></span> Call Time Metrics</div>
            <div class="metric-row"><span class="metric-name">Listening Duration</span><span class="metric-val" id="listeningDuration">--</span></div>
            <div class="metric-row"><span class="metric-name">Internal Call Times</span><span class="metric-val" id="internalCallTime">--</span></div>
            <div class="metric-row"><span class="metric-name">Making Calls Times</span><span class="metric-val" id="outboundTime">--</span></div>
            <div class="metric-row"><span class="metric-name">Hook-on Times</span><span class="metric-val" id="hookOnTimes">--</span></div>
            <div class="metric-row"><span class="metric-name">Total Call Duration</span><span class="metric-val" id="totalDuration">--</span></div>
            <div class="metric-row"><span class="metric-name">Arranging State (ACW)</span><span class="metric-val" id="acwDuration">--</span></div>
            <div class="metric-row"><span class="metric-name">Transfer to IVR Times</span><span class="metric-val" id="ivrTransfer">--</span></div>
        </div>
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#28a745"></span> Status &amp; Activity</div>
            <div class="metric-row"><span class="metric-name">Busy Duration</span><span class="metric-val warn" id="busyDuration">--</span></div>
            <div class="metric-row"><span class="metric-name">Rest Duration</span><span class="metric-val" id="restDuration">--</span></div>
            <div class="metric-row"><span class="metric-name">Over-Rest Duration</span><span class="metric-val bad" id="overRest">00:00</span></div>
            <div class="metric-row"><span class="metric-name">Interception Times</span><span class="metric-val" id="interceptions">--</span></div>
            <div class="metric-row"><span class="metric-name">Internal Help Times</span><span class="metric-val" id="internalHelp">--</span></div>
            <div class="metric-row"><span class="metric-name">Login / Logout Count</span><span class="metric-val" id="loginCount">--</span></div>
            <div class="metric-row"><span class="metric-name">Force Sign-out Times</span><span class="metric-val bad" id="forceSignout">0</span></div>
        </div>
    </div>

    <!-- Performance Progress -->
    <div class="full-section">
        <div class="section-title"><span class="dot" style="background:#00B4D8"></span> Today's Performance</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">
            <div class="progress-wrap">
                <div class="progress-label"><span>Call Answer Rate</span><span id="answerRate">--%</span></div>
                <div class="progress-bar"><div class="progress-fill blue" id="answerRateBar" style="width:0%"></div></div>
            </div>
            <div class="progress-wrap">
                <div class="progress-label"><span>Talk Time vs Idle</span><span id="talkRatio">--%</span></div>
                <div class="progress-bar"><div class="progress-fill green" id="talkRatioBar" style="width:0%"></div></div>
            </div>
            <div class="progress-wrap">
                <div class="progress-label"><span>Target Achievement</span><span id="targetRate">--%</span></div>
                <div class="progress-bar"><div class="progress-fill orange" id="targetRateBar" style="width:0%"></div></div>
            </div>
        </div>
    </div>

    <!-- Advanced Metrics -->
    <div class="section-grid">
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#6f42c1"></span> Monitoring &amp; Supervision</div>
            <div class="metric-row"><span class="metric-name">Listening Count</span><span class="metric-val" id="listeningCount">0</span></div>
            <div class="metric-row"><span class="metric-name">Listen as Third-Party</span><span class="metric-val" id="thirdPartyCount">0</span></div>
            <div class="metric-row"><span class="metric-name">Force Advisor Count</span><span class="metric-val" id="forceAdvisorCount">0</span></div>
            <div class="metric-row"><span class="metric-name">Handle Call on Behalf</span><span class="metric-val" id="handleOnBehalf">0</span></div>
            <div class="metric-row"><span class="metric-name">Ask Help (Chat/Tool)</span><span class="metric-val" id="askHelpCount">0</span></div>
            <div class="metric-row"><span class="metric-name">Call Reason Count</span><span class="metric-val" id="callReasonCount">--</span></div>
            <div class="metric-row"><span class="metric-name">Forwarding Times</span><span class="metric-val" id="forwardingTimes">--</span></div>
        </div>
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#fd7e14"></span> Queue Status</div>
            <div class="metric-row"><span class="metric-name">Queue Name</span><span class="metric-val" id="queueName">Support Queue</span></div>
            <div class="metric-row"><span class="metric-name">Calls Waiting</span><span class="metric-val warn" id="callsWaiting">--</span></div>
            <div class="metric-row"><span class="metric-name">Agents Online</span><span class="metric-val good" id="agentsOnline">--</span></div>
            <div class="metric-row"><span class="metric-name">Avg Wait Time</span><span class="metric-val" id="avgWait">--</span></div>
            <div class="metric-row"><span class="metric-name">My Position</span><span class="metric-val" id="myPosition">Active</span></div>
            <div class="metric-row"><span class="metric-name">SLA (Target &lt;30s)</span><span class="metric-val good" id="slaRate">--%</span></div>
        </div>
    </div>

    <!-- Call History + Recordings (tabbed) -->
    <div class="full-section">
        <div class="tab-bar">
            <button class="tab-btn active" id="tabCallHistoryBtn" onclick="switchTab('callHistory')">Call History</button>
            <button class="tab-btn" id="tabRecordingsBtn" onclick="switchTab('recordings')">Recordings</button>
            <button class="tab-btn" id="tabAcwBtn" onclick="switchTab('acw')">ACW History</button>
        </div>

        <!-- ?? Call History Tab ?? -->
        <div class="tab-panel active" id="tabCallHistory">
            <div class="date-filter">
                <label>From:</label>
                <input type="date" id="filterFrom" value="<?php echo $today; ?>">
                <label>To:</label>
                <input type="date" id="filterTo" value="<?php echo $today; ?>">
                <button class="btn-filter" onclick="fetchData()">Filter</button>
                <button class="btn-filter-clear" onclick="clearDateFilter()">Today</button>
                <span id="historyCount" style="font-size:12px;color:#aaa;margin-left:4px;"></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Type</th>
                        <th>Number</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Disposition</th>
                    </tr>
                </thead>
                <tbody id="callHistoryBody">
                    <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px;">Loading call history...</td></tr>
                </tbody>
            </table>
            <!-- Pagination -->
            <div class="pagination-bar" id="paginationBar" style="display:none;">
                <span id="pageInfo" style="font-size:12px;color:#888;"></span>
                <div class="page-controls">
                    <button class="page-btn" id="btnFirst"    onclick="goPage(1)">&laquo;</button>
                    <button class="page-btn" id="btnPrev"     onclick="goPage(currentPage-1)">&lsaquo; Prev</button>
                    <div class="page-numbers" id="pageNumbers"></div>
                    <button class="page-btn" id="btnNext"     onclick="goPage(currentPage+1)">Next &rsaquo;</button>
                    <button class="page-btn" id="btnLast"     onclick="goPage(totalPages)">&raquo;</button>
                </div>
                <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#888;">
                    <span>Show</span>
                    <select id="pageSizeSelect" onchange="changePageSize()" style="border:1px solid #ddd;border-radius:4px;padding:3px 6px;font-size:12px;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                    </select>
                    <span>per page</span>
                </div>
            </div>
        </div>

        <!-- ?? Recordings Tab ?? -->
        <div class="tab-panel" id="tabRecordings">
            <div class="date-filter">
                <label>From:</label>
                <input type="date" id="recFilterFrom" value="<?php echo $today; ?>">
                <label>To:</label>
                <input type="date" id="recFilterTo" value="<?php echo $today; ?>">
                <button class="btn-filter" onclick="fetchRecordings()">Filter</button>
                <button class="btn-filter-clear" onclick="clearRecFilter()">Today</button>
                <span id="recCount" style="font-size:12px;color:#aaa;margin-left:4px;"></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Number</th>
                        <th>Duration</th>
                        <th>Type</th>
                        <th>File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="recordingsBody">
                    <tr><td colspan="6" class="rec-empty">No recordings found for today.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ACW History Tab -->
        <div class="tab-panel" id="tabAcw" style="display:none">
            <div class="date-filter">
                <label>From:</label>
                <input type="date" id="acwFilterFrom" value="<?php echo $today; ?>">
                <label>To:</label>
                <input type="date" id="acwFilterTo" value="<?php echo $today; ?>">
                <button class="btn-filter" onclick="fetchAcwHistory()">Filter</button>
                <button class="btn-filter-clear" onclick="document.getElementById('acwFilterFrom').value='<?php echo $today; ?>';document.getElementById('acwFilterTo').value='<?php echo $today; ?>';fetchAcwHistory()">Today</button>
                <span id="acwCount" style="font-size:12px;color:#aaa;margin-left:4px;"></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Caller</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Disposition</th>
                        <th>Reason</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody id="acwHistoryBody">
                    <tr><td colspan="7" class="rec-empty">No ACW records yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

<div class="footer">
    SkyKin Technologies &copy; <?php echo date('Y'); ?> | Agent Dashboard v2.0 |
    Auto-refresh: <span id="refreshCountdown">10</span>s
</div>

<!-- ?? INCOMING CALL OVERLAY ?? -->
<div class="incoming-overlay" id="incomingOverlay">
    <div class="incoming-title">&#128222; Incoming Call</div>
    <div class="incoming-number" id="incomingNumber">Unknown</div>
    <div class="incoming-actions">
        <button class="btn-answer" id="btnAnswer">Answer</button>
        <button class="btn-decline" id="btnDecline">Decline</button>
    </div>
</div>

<!-- ?? SIP SETTINGS MODAL ?? -->
<div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
        <div class="modal-title">SIP Phone Settings</div>
        <div class="form-group">
            <label>Extension Number</label>
            <input type="text" id="sipExt" placeholder="e.g. 101">
        </div>
        <div class="form-group">
            <label>SIP Password</label>
            <input type="password" id="sipPass" placeholder="Your extension password">
        </div>
        <div class="form-group">
            <label>SIP Server</label>
            <input type="text" id="sipServer" placeholder="192.168.243.129" value="192.168.243.129">
        </div>
        <div class="form-group">
            <label>WebSocket Port (default 5066)</label>
            <input type="text" id="sipPort" placeholder="5066" value="5066">
        </div>
        <div class="form-group">
            <label>Domain</label>
            <input type="text" id="sipDomain" placeholder="client1.skykin.local" value="<?php echo $domain; ?>">
        </div>
        <button class="btn-save-settings" onclick="saveSipSettings()">Connect</button>
    </div>
</div>

<!-- ?? FLOATING PHONE BUTTON ?? -->
<button class="phone-fab" id="phoneFab" onclick="togglePhonePopup()" title="Open Phone">
    &#128222;
    <span class="fab-badge unreg" id="fabBadge"></span>
</button>

<!-- ?? PHONE POPUP ?? -->
<div class="phone-popup" id="phonePopup">
    <div class="pp-header">
        <div class="pp-status">
            <div class="sip-dot" id="sipDot"></div>
            <span id="sipStatusText">Not Connected</span>
        </div>
        <button class="pp-close" onclick="togglePhonePopup()" title="Close phone panel">&#x2715;</button>
    </div>
    <div class="pp-body">
        <div class="call-timer" id="callTimer">00:00</div>
        <!-- Hidden input syncs with dial pad display -->
        <input type="tel" class="dial-input" id="dialInput" placeholder="" maxlength="20" style="display:none">
        <div class="call-controls">
            <button class="btn-call"   id="btnCall"   onclick="makeCall()" disabled style="display:none">&#128222; Call</button>
            <button class="btn-hangup" id="btnHangup" onclick="hangupCall()">&#128222; Hang Up</button>
            <button class="btn-hold"   id="btnHold"   onclick="toggleHold()">Hold</button>
            <button class="btn-mute"   id="btnMute"   onclick="toggleMute()">Mute</button>
            <button class="btn-record" id="btnRecord" onclick="toggleRecord()">
                <span class="rec-dot"></span> Record
            </button>
        </div>
    </div>

    <!-- Phone Settings above dial pad -->
    <div class="pp-footer" style="border-top:none; border-bottom:1px solid #f0f0f0; padding: 8px 16px;">
        <button class="btn-settings" onclick="document.getElementById('settingsModal').classList.add('show')">&#9881; Phone Settings</button>
    </div>

    <!-- Inline Dial Pad -->
    <div class="dp-panel" id="dpPanel">
        <div class="dp-display empty" id="dpDisplay">Enter number...</div>
        <div class="dp-grid">
            <button class="dp-key" onclick="dpKey('1')">1<span class="dp-sub">&nbsp;</span></button>
            <button class="dp-key" onclick="dpKey('2')">2<span class="dp-sub">ABC</span></button>
            <button class="dp-key" onclick="dpKey('3')">3<span class="dp-sub">DEF</span></button>
            <button class="dp-key" onclick="dpKey('4')">4<span class="dp-sub">GHI</span></button>
            <button class="dp-key" onclick="dpKey('5')">5<span class="dp-sub">JKL</span></button>
            <button class="dp-key" onclick="dpKey('6')">6<span class="dp-sub">MNO</span></button>
            <button class="dp-key" onclick="dpKey('7')">7<span class="dp-sub">PQRS</span></button>
            <button class="dp-key" onclick="dpKey('8')">8<span class="dp-sub">TUV</span></button>
            <button class="dp-key" onclick="dpKey('9')">9<span class="dp-sub">WXYZ</span></button>
            <button class="dp-key" onclick="dpKey('*')">*</button>
            <button class="dp-key" onclick="dpKey('0')">0<span class="dp-sub">+</span></button>
            <button class="dp-key" onclick="dpKey('#')">#</button>
        </div>
        <div class="dp-row-actions">
            <button class="dp-call" onclick="dpCall()">&#128222; Call</button>
            <button class="dp-del"  onclick="dpDelete()">&#9003;</button>
        </div>
    </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/socket.io-client@4.8.1/dist/socket.io.min.js"></script>
<script>
const agentName  = '<?php echo $agent_name; ?>';
const domain     = '<?php echo $domain; ?>';
const serverExt  = '<?php echo $agent_ext; ?>';       // resolved server-side from DB
const serverPass = '<?php echo $agent_password; ?>';   // SIP password from DB
const serverWss  = '<?php echo $agent_wss; ?>';        // WSS server URL

// Auto-configure SIP fully from server ? extension + password + WSS, no Phone Settings needed
if (serverExt)  localStorage.setItem('sip_ext',    serverExt);
if (serverPass) localStorage.setItem('sip_pass',   serverPass);  // key matches loadSipSettings
if (serverWss)  localStorage.setItem('sip_server', serverWss);
localStorage.setItem('sip_port', '7443');  // force WSS port for HTTPS
let loginTime   = new Date();
let refreshInterval = 10;
let countdown   = refreshInterval;

// ?? Clock ??????????????????????????????????????????
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('liveClock').textContent = h+':'+m+':'+s;
    const diff = Math.floor((now - loginTime) / 1000);
    document.getElementById('workDuration').textContent = formatDuration(diff);
}
function formatDuration(seconds) {
    const h = Math.floor(seconds/3600);
    const m = Math.floor((seconds%3600)/60);
    const s = seconds%60;
    if (h > 0) return h+'h '+String(m).padStart(2,'0')+'m';
    return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}
function formatDurationHMS(seconds) {
    const h = Math.floor(seconds/3600);
    const m = Math.floor((seconds%3600)/60);
    const s = seconds%60;
    if (h > 0) return h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
    return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}

// ?? Status Dropdown ????????????????????????????????
let currentAgentStatus = 'ready';
function toggleStatusMenu() {
    document.getElementById('statusDropMenu').classList.toggle('open');
}
function setAgentStatus(status) {
    currentAgentStatus = status;
    const labels = { ready:'Available', idle:'Idle', break:'On Break', acw:'Wrap-up (ACW)', logout:'Logged Out', incall:'On Call' };
    const colors  = { ready:'#10b981', idle:'#64748b', break:'#0ea5e9', acw:'#6366f1', logout:'#ef4444', incall:'#f59e0b' };
    const key = status;
    document.getElementById('statusLabel').textContent = labels[key] || status;
    const dot = document.getElementById('statusDot');
    dot.className = 'sdot ' + key;
    dot.style.background = colors[key] || '#888';
    document.getElementById('statusDropMenu').classList.remove('open');
    if (status === 'logout') { window.location = '/logout.php'; return; }
    const agentId = (document.getElementById('agentId') || {}).value || '101';
    fetch('http://192.168.243.129:8001/api/agent/status', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({agent_id: agentId, status: labels[key] || status})
    }).catch(() => {});
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.status-drop-wrap')) {
        document.getElementById('statusDropMenu').classList.remove('open');
    }
});

// ?? Tabs ???????????????????????????????????????????
function switchTab(tab) {
    ['callHistory','recordings','acw'].forEach(t => {
        const panel = document.getElementById('tab' + t.charAt(0).toUpperCase() + t.slice(1));
        const btn   = document.getElementById('tab' + t.charAt(0).toUpperCase() + t.slice(1) + 'Btn');
        if (panel) { panel.classList.remove('active'); panel.style.display = 'none'; }
        if (btn)   btn.classList.remove('active');
    });
    const activePanel = document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1));
    const activeBtn   = document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1) + 'Btn');
    if (activePanel) { activePanel.style.display = ''; activePanel.classList.add('active'); }
    if (activeBtn)   activeBtn.classList.add('active');
    if (tab === 'recordings') fetchRecordings();
    if (tab === 'acw')        fetchAcwHistory();
}

// ?? Date filters ??????????????????????????????????
function clearDateFilter() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('filterFrom').value = today;
    document.getElementById('filterTo').value   = today;
    fetchData();
}
function clearRecFilter() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('recFilterFrom').value = today;
    document.getElementById('recFilterTo').value   = today;
    fetchRecordings();
}

function fetchAcwHistory() {
    const ext  = localStorage.getItem('sip_ext') || serverExt || '';
    const from = document.getElementById('acwFilterFrom').value;
    const to   = document.getElementById('acwFilterTo').value;
    fetch('index.php?action=acw_history&ext='+encodeURIComponent(ext)+'&from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to))
        .then(r => r.json())
        .then(d => {
            const rows = d.records || [];
            document.getElementById('acwCount').textContent = rows.length + ' record(s)';
            if (!rows.length) {
                document.getElementById('acwHistoryBody').innerHTML =
                    '<tr><td colspan="7" class="rec-empty">No ACW records found for this date range.</td></tr>';
                return;
            }
            document.getElementById('acwHistoryBody').innerHTML = rows.map(r => `
                <tr>
                    <td>${r.time}</td>
                    <td>${r.caller_id}</td>
                    <td><span class="badge-${r.call_type==='Inbound'?'in':'out'}">${r.call_type}</span></td>
                    <td>${Math.floor(r.duration/60)}:${String(r.duration%60).padStart(2,'0')}</td>
                    <td>${r.disposition}</td>
                    <td>${r.call_reason}</td>
                    <td style="max-width:200px;white-space:normal;font-size:11px;color:#666">${r.notes||'?'}</td>
                </tr>`).join('');
        }).catch(() => {
            document.getElementById('acwHistoryBody').innerHTML =
                '<tr><td colspan="7" class="rec-empty">Error loading ACW history.</td></tr>';
        });
}

// Dial pad always open ? no toggle needed
let dpNumber = '';
let padOpen  = true;
function dpKey(k) {
    dpNumber += k;
    updateDpDisplay();
    document.getElementById('dialInput').value = dpNumber;
}
function dpDelete() {
    dpNumber = dpNumber.slice(0, -1);
    updateDpDisplay();
    document.getElementById('dialInput').value = dpNumber;
}
function updateDpDisplay() {
    const el = document.getElementById('dpDisplay');
    if (dpNumber) {
        el.textContent = dpNumber;
        el.classList.remove('empty');
    } else {
        el.textContent = 'Enter number...';
        el.classList.add('empty');
    }
}
function dpCall() {
    if (!dpNumber) return;
    makeCall(dpNumber);
}
// No outside-click close for dial pad (always visible)

// ?? Fetch dashboard data ???????????????????????????
function fetchData() {
    const ext  = localStorage.getItem('sip_ext') || serverExt || '';
    const from = document.getElementById('filterFrom').value;
    const to   = document.getElementById('filterTo').value;
    fetch('index.php?action=stats&agent='+encodeURIComponent(agentName)
        +'&domain='+encodeURIComponent(domain)
        +'&ext='+encodeURIComponent(ext)
        +'&from='+encodeURIComponent(from)
        +'&to='+encodeURIComponent(to))
        .then(r => r.text())
        .then(text => {
            // Strip any PHP warnings/notices before the JSON
            const jsonStart = text.indexOf('{');
            const clean = jsonStart >= 0 ? text.slice(jsonStart) : '{}';
            try {
                return JSON.parse(clean);
            } catch(e) {
                return getEmptyData();
            }
        })
        .then(data => updateDashboard(data))
        .catch(() => updateDashboard(getEmptyData()));
}

// ?? Fetch recordings ??????????????????????????????
function fetchRecordings() {
    const ext  = localStorage.getItem('sip_ext') || '';
    const from = document.getElementById('recFilterFrom').value;
    const to   = document.getElementById('recFilterTo').value;
    fetch('index.php?action=recordings&agent='+encodeURIComponent(agentName)
        +'&domain='+encodeURIComponent(domain)
        +'&ext='+encodeURIComponent(ext)
        +'&from='+encodeURIComponent(from)
        +'&to='+encodeURIComponent(to))
        .then(r => r.json())
        .then(data => updateRecordings(data.recordings || []))
        .catch(() => updateRecordings(getDemoRecordings()));
}

function updateRecordings(recs) {
    document.getElementById('recCount').textContent = recs.length + ' recording(s)';
    if (!recs.length) {
        document.getElementById('recordingsBody').innerHTML =
            '<tr><td colspan="6" class="rec-empty">No recordings found for this date range.</td></tr>';
        return;
    }
    let html = '';
    recs.forEach(r => {
        const badge = r.direction === 'inbound' ? 'badge-in' : 'badge-out';
        const dir   = r.direction === 'inbound' ? 'Inbound' : 'Outbound';
        html += `<tr>
            <td>${r.datetime}</td>
            <td>${r.remote_number}</td>
            <td>${r.duration}</td>
            <td><span class="badge ${badge}">${dir}</span></td>
            <td style="font-size:11px;color:#888;">${r.filename}</td>
            <td>
                <button class="rec-play" onclick="playRecording('${r.filepath}')">&#9654; Play</button>
                <a href="${r.filepath}" download>
                    <button class="rec-download">&#8595; Save</button>
                </a>
            </td>
        </tr>`;
    });
    document.getElementById('recordingsBody').innerHTML = html;
}

function getDemoRecordings() {
    return [
        { datetime:'2026-07-21 10:45', remote_number:'+251911234567', duration:'3:42', direction:'inbound',  filename:'rec_1045_101.wav', filepath:'/recordings/rec_1045_101.wav' },
        { datetime:'2026-07-21 09:32', remote_number:'+251922345678', duration:'2:15', direction:'outbound', filename:'rec_0932_101.wav', filepath:'/recordings/rec_0932_101.wav' },
    ];
}

let recAudio = null;
function playRecording(path) {
    if (recAudio) { recAudio.pause(); recAudio = null; }
    recAudio = new Audio(path);
    recAudio.play().catch(() => alert('Cannot play: ' + path));
}

// ?? Empty data (no calls / API error) ?????????????
function getEmptyData() {
    return {
        total_calls:0, answered_calls:0, missed_calls:0,
        avg_duration:0, total_talk:0, total_duration:0,
        listening_duration:0, internal_call_time:0, outbound_time:0,
        hook_on_times:0, hold_times:0, transfers:0, forwarding_times:0,
        acw_duration:0, ivr_transfer:0, busy_duration:0, rest_duration:0,
        over_rest:0, idle_duration:0, interceptions:0, internal_help:0,
        login_count:1, force_signout:0, listening_count:0, third_party_count:0,
        force_advisor_count:0, handle_on_behalf:0, ask_help_count:0, call_reason_count:0,
        queue_waiting:0, agents_online:0, avg_wait:0, sla_rate:0,
        recent_calls:[]
    };
}

// ?? Demo data ?????????????????????????????????????
function getDemoData() {
    return {
        total_calls:24, answered_calls:21, missed_calls:3,
        avg_duration:187, total_talk:4488, total_duration:5200,
        listening_duration:4488, internal_call_time:420, outbound_time:1820,
        hook_on_times:24, hold_times:340, transfers:5, forwarding_times:3,
        acw_duration:280, ivr_transfer:2, busy_duration:4488, rest_duration:1200,
        over_rest:0, idle_duration:2800, interceptions:1, internal_help:2,
        login_count:1, force_signout:0, listening_count:0, third_party_count:0,
        force_advisor_count:0, handle_on_behalf:0, ask_help_count:0, call_reason_count:18,
        queue_waiting:2, agents_online:3, avg_wait:18, sla_rate:92,
        recent_calls:[
            {time:'10:45 Jul 21', type:'Inbound',  number:'+251911234567', duration:'3:42', status:'Answered',    disposition:'Resolved'},
            {time:'10:32 Jul 21', type:'Outbound', number:'+251922345678', duration:'2:15', status:'Answered',    disposition:'Callback'},
            {time:'10:18 Jul 21', type:'Inbound',  number:'+251933456789', duration:'0:00', status:'Missed',      disposition:'Voicemail'},
            {time:'10:05 Jul 21', type:'Transfer', number:'Ext 102',       duration:'1:30', status:'Transferred', disposition:'Internal'},
            {time:'09:52 Jul 21', type:'Inbound',  number:'+251944567890', duration:'5:10', status:'Answered',    disposition:'Resolved'},
        ]
    };
}

// ?? Call History Pagination ???????????????????????
let allCalls    = [];
let currentPage = 1;
let pageSize    = 10;
let totalPages  = 1;

function renderCallPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages));
    const start = (currentPage - 1) * pageSize;
    const slice = allCalls.slice(start, start + pageSize);

    document.getElementById('historyCount').textContent =
        allCalls.length + ' call(s) total';

    if (!allCalls.length) {
        document.getElementById('callHistoryBody').innerHTML =
            '<tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px;">No calls found for this date range.</td></tr>';
        document.getElementById('paginationBar').style.display = 'none';
        return;
    }

    let html = '';
    slice.forEach(c => {
        const typeBadge   = c.type==='Inbound'  ? 'badge-in'  :
                            c.type==='Outbound' ? 'badge-out' :
                            c.type==='Transfer' ? 'badge-transfer' : 'badge-missed';
        const statusBadge = c.status==='Missed'      ? 'badge-missed' :
                            c.status==='Transferred' ? 'badge-transfer' : 'badge-in';
        html += `<tr>
            <td>${c.time}</td>
            <td><span class="badge ${typeBadge}">${c.type}</span></td>
            <td>${c.number}</td>
            <td>${c.duration}</td>
            <td><span class="badge ${statusBadge}">${c.status}</span></td>
            <td>${c.disposition}</td>
        </tr>`;
    });
    document.getElementById('callHistoryBody').innerHTML = html;

    // Show/hide pagination bar
    const bar = document.getElementById('paginationBar');
    bar.style.display = totalPages > 1 || allCalls.length > 0 ? 'flex' : 'none';

    // Page info
    const end = Math.min(start + pageSize, allCalls.length);
    document.getElementById('pageInfo').textContent =
        `Showing ${start+1}?${end} of ${allCalls.length}`;

    // Prev / First / Next / Last buttons
    document.getElementById('btnFirst').disabled = currentPage === 1;
    document.getElementById('btnPrev').disabled  = currentPage === 1;
    document.getElementById('btnNext').disabled  = currentPage === totalPages;
    document.getElementById('btnLast').disabled  = currentPage === totalPages;

    // Page number buttons (show up to 5 around current)
    let nums = '';
    const range = 2;
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - range && i <= currentPage + range)) {
            nums += `<button class="page-num${i===currentPage?' active':''}" onclick="goPage(${i})">${i}</button>`;
        } else if (i === currentPage - range - 1 || i === currentPage + range + 1) {
            nums += `<span class="page-num dots">?</span>`;
        }
    }
    document.getElementById('pageNumbers').innerHTML = nums;
}

function goPage(p) {
    renderCallPage(p);
}
function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSizeSelect').value);
    totalPages = Math.max(1, Math.ceil(allCalls.length / pageSize));
    renderCallPage(1);
}

function updateDashboard(d) {
    document.getElementById('totalCalls').textContent    = d.total_calls || 0;
    document.getElementById('avgDuration').textContent   = formatDurationHMS(d.avg_duration || 0);
    document.getElementById('totalTalk').textContent     = formatDuration(d.total_talk || 0);
    document.getElementById('idleTime').textContent      = formatDuration(d.idle_duration || 0);
    document.getElementById('missedCalls').textContent   = d.missed_calls || 0;
    document.getElementById('transfers').textContent     = d.transfers || 0;
    document.getElementById('holdTimes').textContent     = formatDuration(d.hold_times || 0);

    document.getElementById('listeningDuration').textContent = formatDuration(d.listening_duration || 0);
    document.getElementById('internalCallTime').textContent  = formatDuration(d.internal_call_time || 0);
    document.getElementById('outboundTime').textContent      = formatDuration(d.outbound_time || 0);
    document.getElementById('hookOnTimes').textContent       = d.hook_on_times || 0;
    document.getElementById('totalDuration').textContent     = formatDuration(d.total_duration || 0);
    document.getElementById('acwDuration').textContent       = formatDuration(d.acw_duration || 0);
    document.getElementById('ivrTransfer').textContent       = d.ivr_transfer || 0;

    document.getElementById('busyDuration').textContent  = formatDuration(d.busy_duration || 0);
    document.getElementById('restDuration').textContent  = formatDuration(d.rest_duration || 0);
    document.getElementById('overRest').textContent      = formatDurationHMS(d.over_rest || 0);
    document.getElementById('interceptions').textContent = d.interceptions || 0;
    document.getElementById('internalHelp').textContent  = d.internal_help || 0;
    document.getElementById('loginCount').textContent    = (d.login_count || 1) + ' / 0';
    document.getElementById('forceSignout').textContent  = d.force_signout || 0;

    document.getElementById('listeningCount').textContent    = d.listening_count || 0;
    document.getElementById('thirdPartyCount').textContent   = d.third_party_count || 0;
    document.getElementById('forceAdvisorCount').textContent = d.force_advisor_count || 0;
    document.getElementById('handleOnBehalf').textContent    = d.handle_on_behalf || 0;
    document.getElementById('askHelpCount').textContent      = d.ask_help_count || 0;
    document.getElementById('callReasonCount').textContent   = d.call_reason_count || 0;
    document.getElementById('forwardingTimes').textContent   = d.forwarding_times || 0;

    document.getElementById('callsWaiting').textContent = d.queue_waiting || 0;
    document.getElementById('agentsOnline').textContent  = d.agents_online || 0;
    document.getElementById('avgWait').textContent       = (d.avg_wait || 0) + 's';
    document.getElementById('slaRate').textContent       = (d.sla_rate || 0) + '%';

    const answerRate = d.total_calls > 0 ? Math.round((d.answered_calls / d.total_calls) * 100) : 0;
    document.getElementById('answerRate').textContent        = answerRate + '%';
    document.getElementById('answerRateBar').style.width     = answerRate + '%';
    const talkTotal  = (d.total_talk||0) + (d.idle_duration||0);
    const talkRatio  = talkTotal > 0 ? Math.round(((d.total_talk||0)/talkTotal)*100) : 0;
    document.getElementById('talkRatio').textContent         = talkRatio + '%';
    document.getElementById('talkRatioBar').style.width      = talkRatio + '%';
    const targetRate = Math.min(100, Math.round(((d.total_calls||0)/30)*100));
    document.getElementById('targetRate').textContent        = targetRate + '%';
    document.getElementById('targetRateBar').style.width     = targetRate + '%';

    // Hand off to paginator
    allCalls   = d.recent_calls || [];
    pageSize   = parseInt(document.getElementById('pageSizeSelect').value) || 10;
    totalPages = Math.max(1, Math.ceil(allCalls.length / pageSize));
    renderCallPage(1);
}

// ?? Auto-refresh ??????????????????????????????????
function startCountdown() {
    countdown = refreshInterval;
    const timer = setInterval(() => {
        countdown--;
        document.getElementById('refreshCountdown').textContent = countdown;
        if (countdown <= 0) { clearInterval(timer); fetchData(); startCountdown(); }
    }, 1000);
}

// ??????????????????????????????????????????????????
// SIP / WebRTC Softphone (SIP.js 0.21 via ESM CDN)
// ??????????????????????????????????????????????????
let currentSession = null, lastDialedNumber = '', lastCallType = 'Outbound';
let isMuted = false;
let callStartTime = null, callTimerInterval = null, onHold = false;
let isRecording = false;
let acwCallerId = '', acwDuration = 0, acwCallType = 'Outbound', acwRecordingFilename = 'demo_recording.wav';

// SIP.js module will populate window.sipBridge when loaded
window.sipBridge = {}; var sipBridge = window.sipBridge;

function loadSipSettings() {
    const ext    = localStorage.getItem('sip_ext')    || '';
    const pass   = localStorage.getItem('sip_pass')   || '';
    let   server = localStorage.getItem('sip_server') || '<?php echo $_SERVER["HTTP_HOST"]; ?>';
    const port   = localStorage.getItem('sip_port')   || '7443';
    const dom    = localStorage.getItem('sip_domain') || '<?php echo $domain; ?>';
    // Always use WSS on HTTPS pages ? strip any existing protocol and re-add wss://
    server = server.replace(/^wss?:\/\//i, '');
    const wsUrl = 'wss://' + server;
    document.getElementById('sipExt').value    = ext;
    document.getElementById('sipPass').value   = pass;
    document.getElementById('sipServer').value = server;
    document.getElementById('sipPort').value   = port;
    document.getElementById('sipDomain').value = dom;
    if (ext && pass) waitForSipBridge(() => initSIP(ext, pass, wsUrl, port, dom));
}

function waitForSipBridge(cb, tries) {
    tries = tries || 0;
    if (sipBridge.init) { cb(); return; }
    if (tries < 50) setTimeout(() => waitForSipBridge(cb, tries + 1), 200);
    else setSipStatus('failed', 'SIP module failed to load');
}

function saveSipSettings() {
    const ext    = document.getElementById('sipExt').value.trim();
    const pass   = document.getElementById('sipPass').value.trim();
    const server = document.getElementById('sipServer').value.trim();
    const port   = document.getElementById('sipPort').value.trim() || '5066';
    const dom    = document.getElementById('sipDomain').value.trim();
    if (!ext || !pass) { alert('Please enter extension and password'); return; }
    localStorage.setItem('sip_ext',    ext);
    localStorage.setItem('sip_pass',   pass);
    localStorage.setItem('sip_server', server);
    localStorage.setItem('sip_port',   port);
    localStorage.setItem('sip_domain', dom);
    document.getElementById('settingsModal').classList.remove('show');
    waitForSipBridge(() => initSIP(ext, pass, server, port, dom));
}

function initSIP(ext, pass, server, port, dom) {
    setSipStatus('connecting', 'Connecting...');
    if (sipBridge.init) {
        sipBridge.init(ext, pass, server, port, dom);
    } else {
        setSipStatus('failed', 'SIP library not loaded');
    }
}

// Floating Phone Widget
let phoneOpen = false;
function togglePhonePopup() {
    phoneOpen = !phoneOpen;
    document.getElementById('phonePopup').classList.toggle('open', phoneOpen);
    document.body.classList.toggle('phone-open', phoneOpen);
    // Change FAB icon to X when open
    document.getElementById('phoneFab').innerHTML = phoneOpen
        ? '&#x2715;<span class="fab-badge unreg" id="fabBadge"></span>'
        : '&#128222;<span class="fab-badge unreg" id="fabBadge"></span>';
}
function openPhonePopup() {
    phoneOpen = true;
    document.getElementById('phonePopup').classList.add('open');
    document.body.classList.add('phone-open');
}

function setSipStatus(state, text) {
    const dot   = document.getElementById('sipDot');
    const badge = document.getElementById('fabBadge');
    const fab   = document.getElementById('phoneFab');
    dot.className = 'sip-dot'; badge.className = 'fab-badge'; fab.className = 'phone-fab';
    if (state === 'registered') {
        dot.classList.add('registered'); badge.classList.add('show');
        document.getElementById('btnCall').disabled = false;
        setAgentStatus('ready');
    } else if (state === 'calling') {
        dot.classList.add('calling'); badge.classList.add('show','calling');
        fab.classList.add('ringing'); openPhonePopup();
    } else if (state === 'incall') {
        dot.classList.add('registered'); badge.classList.add('show'); fab.classList.add('ringing');
    } else if (state === 'ringing') {
        dot.classList.add('ringing'); badge.classList.add('show'); fab.classList.add('ringing');
    } else if (state === 'connecting') {
        dot.classList.add('connecting');
    } else if (state === 'unregistered' || state === 'failed') {
        dot.classList.add('failed'); badge.classList.add('show','unreg');
        document.getElementById('btnCall').disabled = true;
    }
    document.getElementById('sipStatusText').textContent = text;
}

function handleIncoming(callerNumber) {
    lastCallType = 'Inbound';
    document.getElementById('incomingNumber').textContent = callerNumber;
    document.getElementById('incomingOverlay').style.display = 'block';
    openPhonePopup();
    setSipStatus('ringing', 'Ringing: ' + callerNumber);
}

function answerCall() {
    document.getElementById('incomingOverlay').style.display = 'none';
    if (sipBridge.answer) sipBridge.answer();
}

function declineCall() {
    document.getElementById('incomingOverlay').style.display = 'none';
    if (sipBridge.hangup) sipBridge.hangup();
    currentSession = null;
}

function makeCall(number) {
    number = number || document.getElementById('dialInput').value.trim();
    if (!number) return;
    lastDialedNumber = number;
    lastCallType = 'Outbound';
    if (sipBridge.makeCall) sipBridge.makeCall(number);
    else showToast('SIP not ready. Open Phone Settings to connect.');
}

function startCallUI(number) {
    setSipStatus('incall', 'In Call: ' + number);
    document.getElementById('btnCall').style.display   = 'none';
    document.getElementById('btnHangup').style.display = 'block';
    document.getElementById('btnHold').style.display   = 'block';
    document.getElementById('btnMute').style.display   = 'block';
    document.getElementById('btnRecord').classList.add('visible');
    document.getElementById('callTimer').style.display = 'block';
    document.getElementById('dialInput').value = number;
    callStartTime = new Date();
    callTimerInterval = setInterval(updateCallTimer, 1000);
}

function updateCallTimer() {
    if (!callStartTime) return;
    const elapsed = Math.floor((new Date() - callStartTime) / 1000);
    document.getElementById('callTimer').textContent =
        String(Math.floor(elapsed/60)).padStart(2,'0') + ':' + String(elapsed%60).padStart(2,'0');
}

function hangupCall() {
    if (sipBridge.hangup) sipBridge.hangup(); else endCall();
}

function toggleHold() {
    if (onHold) {
        if (sipBridge.unhold) sipBridge.unhold(); onHold = false;
        document.getElementById('btnHold').textContent = 'Hold';
        document.getElementById('btnHold').style.background = '#ffc107';
        document.getElementById('btnHold').style.color = '#333';
    } else {
        if (sipBridge.hold) sipBridge.hold(); onHold = true;
        document.getElementById('btnHold').textContent = 'Resume';
        document.getElementById('btnHold').style.background = '#28a745';
        document.getElementById('btnHold').style.color = 'white';
    }
}

function toggleMute() {
    if (isMuted) {
        if (sipBridge.unmute) sipBridge.unmute(); isMuted = false;
        document.getElementById('btnMute').textContent = 'Mute';
        document.getElementById('btnMute').classList.remove('muted');
    } else {
        if (sipBridge.mute) sipBridge.mute(); isMuted = true;
        document.getElementById('btnMute').textContent = 'Unmute';
        document.getElementById('btnMute').classList.add('muted');
    }
}

function toggleRecord() {
    isRecording = !isRecording;
    const btn = document.getElementById('btnRecord');
    if (isRecording) {
        btn.classList.add('recording');
        btn.innerHTML = '<span class="rec-dot"></span> Stop Rec';
        if (sipBridge.sendDtmf) try { sipBridge.sendDtmf('*1'); } catch(e) {}
    } else {
        btn.classList.remove('recording');
        btn.innerHTML = '<span class="rec-dot"></span> Record';
        if (sipBridge.sendDtmf) try { sipBridge.sendDtmf('*1'); } catch(e) {}
    }
}

function endCall() {
    const callDur = callStartTime ? Math.floor((new Date() - callStartTime) / 1000) : 0;
    const callerNum = lastDialedNumber || document.getElementById('dialInput').value || '';
    const recFile = (window.recordingCallId || '') ? window.recordingCallId + '.webm' : 'demo_recording.wav';
    currentSession = null; onHold = false; isRecording = false; isMuted = false;
    clearInterval(callTimerInterval); callStartTime = null;
    document.getElementById('btnCall').style.display   = 'block';
    document.getElementById('btnHangup').style.display = 'none';
    document.getElementById('btnHold').style.display   = 'none';
    document.getElementById('btnMute').style.display   = 'none';
    document.getElementById('btnMute').textContent     = 'Mute';
    document.getElementById('btnMute').classList.remove('muted');
    document.getElementById('btnRecord').classList.remove('visible','recording');
    document.getElementById('btnRecord').innerHTML = '<span class="rec-dot"></span> Record';
    document.getElementById('btnHold').textContent = 'Hold';
    document.getElementById('callTimer').style.display = 'none';
    document.getElementById('callTimer').textContent   = '00:00';
    setAgentStatus('acw');
    openAcwModal(callerNum, callDur, lastCallType, recFile);
    setTimeout(() => { fetchData(); startCountdown(); }, 4000);
    setTimeout(() => fetchData(), 8000);
}

// ?? ACW Modal ?????????????????????????????????????
function openAcwModal(callerId, duration, callType, recFilename) {
    acwCallerId = callerId; acwDuration = duration;
    acwCallType = callType || 'Outbound';
    acwRecordingFilename = recFilename || 'demo_recording.wav';
    document.getElementById('acwCallerDisplay').textContent = callerId || '?';
    const m = Math.floor(duration/60), s = duration%60;
    document.getElementById('acwDurationDisplay').textContent = String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
    document.getElementById('acwModal').classList.add('show');
}

function closeAcwModal() {
    document.getElementById('acwModal').classList.remove('show');
    setAgentStatus('ready');
    const ext = localStorage.getItem('sip_ext') || '';
    setSipStatus('registered', 'Registered (' + ext + ')');
}

function submitAcw() {
    const disposition = document.getElementById('acwDisposition').value;
    const callReason  = document.getElementById('acwCallReason').value.trim() || 'General';
    const notes       = document.getElementById('acwNotes').value.trim();
    const agentId     = localStorage.getItem('sip_ext') || '101';
    fetch('index.php?action=save_acw', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            agent_id: agentId, caller_id: acwCallerId,
            call_type: acwCallType, duration: acwDuration,
            disposition, call_reason: callReason,
            notes, recording_filename: acwRecordingFilename
        })
    }).then(() => fetchData()).catch(() => {});
    closeAcwModal();
    showToast('Wrap-up submitted. You are now Available.');
}

// ?? Toast ?????????????????????????????????????????
let toastTimer = null;
function showToast(msg) {
    const t = document.getElementById('sysToast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, 4000);
}

// ?? Socket.IO real-time events ????????????????????
(function connectSocket() {
    if (typeof io === 'undefined') return;
    const socket = io('http://192.168.243.129:8001', { transports: ['websocket','polling'] });
    socket.on('connect', () => showToast('Live events connected'));
    socket.on('call_bridged', function(data) {
        const callerNum = data.callerId || data.caller_id || '';
        lastCallType = 'Inbound'; lastDialedNumber = callerNum;
        handleIncoming(callerNum);
    });
    socket.on('call_ended', function() { endCall(); });
    socket.on('metrics_update', function() { fetchData(); });
})();

// ?? Event wiring ??????????????????????????????????
document.getElementById('btnAnswer').addEventListener('click', answerCall);
document.getElementById('btnDecline').addEventListener('click', declineCall);
document.getElementById('settingsModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});
document.getElementById('acwModal').addEventListener('click', function(e) {
    if (e.target === this) closeAcwModal();
});
document.getElementById('dialInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') makeCall();
});
document.getElementById('dialInput').addEventListener('input', function() {
    dpNumber = this.value;
});

// Init
setInterval(updateClock, 1000);
updateClock();
fetchData();
startCountdown();
</script>

<!-- ACW Wrap-Up Modal -->
<div id="acwModal" class="acw-overlay">
    <div class="acw-modal">
        <div class="acw-hdr">
            <h3>After-Call Work (Wrap-Up)</h3>
            <button onclick="closeAcwModal()">&times;</button>
        </div>
        <div class="acw-body">
            <div class="acw-summary">
                <strong>Caller:</strong> <span id="acwCallerDisplay">?</span><br>
                <strong>Duration:</strong> <span id="acwDurationDisplay">00:00</span>
            </div>
            <label>Disposition *</label>
            <select id="acwDisposition">
                <option value="Resolved">Resolved</option>
                <option value="Follow-Up">Follow-Up</option>
                <option value="Escalated">Escalated</option>
                <option value="Completed Normally">Completed Normally</option>
                <option value="Invalid">Invalid</option>
            </select>
            <label>Call Reason *</label>
            <input type="text" id="acwCallReason" placeholder="e.g. Tech Support, Billing..." value="Tech Support">
            <label>Notes</label>
            <textarea id="acwNotes" rows="3" placeholder="Add call notes, follow-up actions..."></textarea>
            <div class="acw-actions">
                <button class="btn-skip" onclick="closeAcwModal()">Skip</button>
                <button class="btn-submit" onclick="submitAcw()">Submit &amp; Return Available</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div id="sysToast"></div>

<!-- Remote audio for WebRTC calls -->
<audio id="remoteAudio" autoplay style="display:none"></audio>

<!-- SIP.js 0.21 local bundle (built from /opt/call_center node_modules) -->
<script src="/app/agent_dashboard/js/sipjs.bundle.js"></script>
<script>
(function() {
'use strict';
if (typeof SIPjs === 'undefined') {
    console.error('SIPjs bundle not found at /app/agent_dashboard/js/sipjs.bundle.js ? build it on the VM.');
    if (window.showToast) window.showToast('SIP library missing. Run build command on VM (see console).');
    return;
}
const {
    UserAgent, Registerer, Inviter, Invitation, SessionState, Web
} = SIPjs;

let ua = null, reg = null, session = null;
const pbxDomain = () => localStorage.getItem('sip_domain') || 'client1.skykin.local';

function startRec(stream) {
    try {
        const mr = new MediaRecorder(stream);
        window.recordingChunks = [];
        mr.ondataavailable = e => { if (e.data.size > 0) window.recordingChunks.push(e.data); };
        mr.start(1000);
        window.mediaRecorderRef = mr;
        window.recordingCallId = 'call-' + Date.now();
    } catch(e) {}
}

function stopRec() {
    const mr = window.mediaRecorderRef;
    const id = window.recordingCallId;
    if (!mr || mr.state === 'inactive') return;
    mr.onstop = async () => {
        const chunks = window.recordingChunks || [];
        if (!chunks.length || !id) return;
        const blob = new Blob(chunks, { type: mr.mimeType || 'audio/webm' });
        const fd = new FormData();
        fd.append('file', blob, id + '.webm');
        fetch('http://192.168.243.129:8001/api/recordings/upload?call_id=' + encodeURIComponent(id), { method:'POST', body:fd }).catch(()=>{});
    };
    mr.stop(); window.mediaRecorderRef = null;
}

function attachAudio(s) {
    const sdh = s.sessionDescriptionHandler;
    if (!sdh?.peerConnection) return;
    const stream = new MediaStream();
    sdh.peerConnection.getReceivers().forEach(r => { if (r.track) stream.addTrack(r.track); });
    const el = document.getElementById('remoteAudio');
    if (el) { el.srcObject = stream; el.play().catch(()=>{}); }
    startRec(stream);
}

function bindSession(s) {
    s.stateChange.addListener(state => {
        if (state === SessionState.Established) {
            const num = s instanceof Invitation
                ? (s.remoteIdentity?.uri?.user || window.lastDialedNumber || '')
                : (window.lastDialedNumber || '');
            window.startCallUI && window.startCallUI(num);
            attachAudio(s);
            window.showToast && window.showToast('Call connected');
        }
        if (state === SessionState.Terminated || state === SessionState.Terminating) {
            stopRec();
            if (window.endCall) window.endCall();
        }
    });
}

window.sipBridge.init = function(ext, pass, server, port, dom) {
    if (ua) { try { reg?.unregister(); ua.stop(); } catch(e) {} }

    ua = new UserAgent({
        uri: UserAgent.makeURI('sip:' + ext + '@' + dom),
        transportOptions: { server: 'ws://' + server + ':' + (port || '5066') },
        authorizationUsername: ext,
        authorizationPassword: pass
    });

    reg = new Registerer(ua);
    reg.stateChange.addListener(state => {
        if (state === 'Registered') {
            window.setSipStatus('registered', 'Registered (' + ext + ')');
            fetch('http://192.168.243.129:8001/api/agent/login', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ agent_id: ext })
            }).catch(()=>{});
        } else if (state === 'Unregistered') {
            window.setSipStatus('unregistered', 'Not Registered');
        } else if (state === 'Terminated') {
            window.setSipStatus('failed', 'Registration Failed');
        }
    });

    ua.delegate = {
        onInvite(inv) {
            session = inv;
            const num = inv.remoteIdentity?.uri?.user || 'Unknown';
            window.lastDialedNumber = num; window.lastCallType = 'Inbound';
            window.handleIncoming && window.handleIncoming(num);
            bindSession(inv);
        }
    };

    ua.start().then(() => reg.register()).catch(err => {
        window.setSipStatus('failed', 'Error: ' + err.message);
    });
};

window.sipBridge.makeCall = function(number) {
    if (!ua) { window.showToast && window.showToast('SIP not initialized'); return; }
    const uri = UserAgent.makeURI('sip:' + number + '@' + pbxDomain());
    if (!uri) return;
    const inv = new Inviter(ua, uri, {
        sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } }
    });
    session = inv;
    window.setSipStatus && window.setSipStatus('calling', 'Calling ' + number);
    bindSession(inv);
    inv.invite().catch(err => {
        window.setSipStatus && window.setSipStatus('failed', 'Call failed: ' + err.message);
        window.endCall && window.endCall();
    });
};

window.sipBridge.hangup = function() {
    if (!session) return;
    const st = session.state;
    try {
        if (st === SessionState.Initial || st === SessionState.Establishing) {
            session instanceof Invitation ? session.reject() : session.cancel?.();
        } else { session.bye(); }
    } catch(e) {}
    session = null;
};

window.sipBridge.answer = function() {
    if (session instanceof Invitation) {
        session.accept({ sessionDescriptionHandlerOptions: { constraints: { audio:true, video:false } } })
               .catch(e => window.showToast && window.showToast('Answer failed: ' + e.message));
    }
};

window.sipBridge.hold = function() {
    session?.invite({ sessionDescriptionHandlerModifiers: [Web.holdModifier] }).catch(()=>{});
};

window.sipBridge.unhold = function() {
    session?.invite({ sessionDescriptionHandlerModifiers: [] }).catch(()=>{});
};

window.sipBridge.mute = function() {
    if (!session) return;
    const pc = session.sessionDescriptionHandler?.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track?.kind === 'audio') s.track.enabled = false; });
};

window.sipBridge.unmute = function() {
    if (!session) return;
    const pc = session.sessionDescriptionHandler?.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track?.kind === 'audio') s.track.enabled = true; });
};

window.sipBridge.sendDtmf = function(tone) {
    if (!session) return;
    try { session.sendDTMF(tone, {duration:100,interToneGap:500}); } catch(e) {
        const pc = session.sessionDescriptionHandler?.peerConnection;
        const sender = pc?.getSenders().find(s => s.track?.kind === 'audio');
        if (sender?.dtmf) sender.dtmf.insertDTMF(tone, 100, 500);
    }
};
})();

loadSipSettings();
</script>
</body>
</html>
