<?php
// SkyKin Technologies - Real-time Agent Dashboard
session_start();

// ── Shared Skykin DB: tries PostgreSQL, falls back to local SQLite ──────────
function getSkykinDB() {
    static $db = null;
    if ($db) return $db;
    $conf = '/etc/fusionpbx/config.conf';
    $h = '127.0.0.1'; $p = '5432'; $n = 'fusionpbx'; $u = 'fusionpbx'; $pw = '';
    if (file_exists($conf)) {
        foreach (file($conf) as $ln) {
            $ln = trim($ln);
            if (strpos($ln, 'database.0.host')     !== false) $h  = trim(explode('=', $ln, 2)[1]);
            if (strpos($ln, 'database.0.port')     !== false) $p  = trim(explode('=', $ln, 2)[1]);
            if (strpos($ln, 'database.0.name')     !== false) $n  = trim(explode('=', $ln, 2)[1]);
            if (strpos($ln, 'database.0.username') !== false) $u  = trim(explode('=', $ln, 2)[1]);
            if (strpos($ln, 'database.0.password') !== false) $pw = trim(explode('=', $ln, 2)[1]);
        }
    }
    // Try connection options: local config, remote DB, and default postgres user with correct password
    foreach ([$h, '192.168.0.114'] as $_h) {
        foreach ([[$u, $pw], ['fusionpbx', 'vtEWIukU24Lbr9Zi5NxchwVF2g'], ['postgres', 'vtEWIukU24Lbr9Zi5NxchwVF2g']] as [$_u, $_pw]) {
            try {
                $db = new PDO("pgsql:host={$_h};port={$p};dbname={$n};connect_timeout=2", $_u, $_pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                return $db;
            } catch (Exception $ignored) {}
        }
    }
    // SQLite fallback for local development
    $sqliteFile = __DIR__ . '/skykin_local.db';
    $db = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL');
    return $db;
}

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

    try {
        $db = getSkykinDB();
    } catch (Exception $e) {
        $data['db_error'] = $e->getMessage();
        echo json_encode($data);
        exit;
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
                AND caller_id_number ~ '^[0-9+][0-9\-\(\) ]*$'
                AND destination_number ~ '^[0-9+][0-9\-\(\) ]*$'
                AND start_epoch>=:ts AND start_epoch<=:te
                ORDER BY start_epoch DESC LIMIT 500");
            $s2->execute([':d'=>$domain,':e'=>$extension,':ts'=>$today_start,':te'=>$today_end]);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $in   = ($r['destination_number']==$extension);
                $bill = (int)$r['billsec'];
                // Clean up SIP addresses ? strip @domain suffix
                $raw_num = $in ? $r['caller_id_number'] : $r['destination_number'];
                $clean_num = preg_replace('/@.*$/', '', $raw_num);   // strip @host
                // If it still looks like garbage (non-dialable), mark as Unknown
                if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $clean_num)) {
                    $clean_num = 'Unknown';
                }
                $data['recent_calls'][] = [
                    'time'       => $r['call_time'],
                    'type'       => $in ? 'Inbound' : 'Outbound',
                    'number'     => $clean_num,
                    'duration'   => floor($bill/60).':'.str_pad($bill%60,2,'0',STR_PAD_LEFT),
                    'status'     => $bill>0 ? 'Answered' : 'Missed',
                    'disposition'=> $bill>0 ? 'Completed' : ($r['hangup_cause'] ?? 'No Answer')
                ];
            }

            // Agents online
            try {
                $sa = $db->prepare("SELECT COUNT(*) as cnt FROM v_call_center_agents WHERE domain_uuid = (SELECT domain_uuid FROM v_domains WHERE domain_name = :d LIMIT 1) AND agent_status='Available'");
                $sa->execute([':d'=>$domain]);
                $ra = $sa->fetch(PDO::FETCH_ASSOC);
                $data['agents_online'] = $ra ? (int)$ra['cnt'] : 1;
            } catch(Exception $ignored){}

            // Agent Timing Controls (live lookup)
            try {
                $pat = '%/' . $extension . '@%';
                $st = $db->prepare(
                    "SELECT agent_call_timeout, agent_no_answer_delay_time,
                            agent_wrap_up_time, agent_reject_delay_time, agent_busy_delay_time
                     FROM v_call_center_agents
                     WHERE agent_contact LIKE :pat
                     LIMIT 1"
                );
                $st->execute([':pat' => $pat]);
                $data['agent_timing'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch(Exception $e){ $data['agent_timing_err'] = $e->getMessage(); }

            // Agent Assigned Queue(s) & Tiers (live lookup)
            try {
                $pat = '%/' . $extension . '@%';
                $sq = $db->prepare(
                    "SELECT q.queue_name, q.queue_extension,
                            t.tier_level, t.tier_position
                     FROM v_call_center_tiers  t
                     JOIN v_call_center_queues q ON q.call_center_queue_uuid = t.call_center_queue_uuid
                     JOIN v_call_center_agents a ON a.call_center_agent_uuid = t.call_center_agent_uuid
                     WHERE a.agent_contact LIKE :pat
                     ORDER BY t.tier_level ASC, t.tier_position ASC"
                );
                $sq->execute([':pat' => $pat]);
                $data['agent_queues'] = $sq->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch(Exception $e){ $data['agent_queues_err'] = $e->getMessage(); }
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
    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
                id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id TEXT, caller_id TEXT,
                call_type TEXT, duration INTEGER, disposition TEXT,
                call_reason TEXT, notes TEXT, recording_filename TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
            $timeFmt = "strftime('%H:%M', created_at)";
            $dateWhere = "date(created_at)>=:df AND date(created_at)<=:dt";
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
                id SERIAL PRIMARY KEY, agent_id VARCHAR(50), caller_id VARCHAR(50),
                call_type VARCHAR(20), duration INTEGER, disposition VARCHAR(100),
                call_reason VARCHAR(200), notes TEXT, recording_filename VARCHAR(255),
                created_at TIMESTAMP DEFAULT NOW())");
            $timeFmt = "to_char(created_at,'HH24:MI')";
            $dateWhere = "DATE(created_at)>=:df AND DATE(created_at)<=:dt";
        }
        $s = $db->prepare("SELECT {$timeFmt} as time,
            caller_id,call_type,duration,disposition,call_reason,notes
            FROM skykin_acw WHERE agent_id=:ext
            AND {$dateWhere}
            ORDER BY created_at DESC LIMIT 200");
        $s->execute([':ext'=>$ext,':df'=>$date_from,':dt'=>$date_to]);
        echo json_encode(['records'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['records'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ?? Save ACW (After-Call Work) to DB ????????????????????????????????????????
// ?? Save recording (browser WebRTC recording ? server file + CDR link) ??????
if (isset($_GET['action']) && $_GET['action'] === 'save_recording') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

        // Create ACW table if it doesn't exist
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_acw (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_id TEXT,
                caller_id TEXT,
                call_type TEXT,
                duration INTEGER,
                disposition TEXT,
                call_reason TEXT,
                notes TEXT,
                recording_filename TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
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
        }

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

// ── Save Case (Escalation) to DB ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'save_case') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

        // Create cases table (SQLite or PostgreSQL)
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                case_id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name TEXT, customer_phone TEXT, order_id TEXT,
                issue_type TEXT, description TEXT, delivery_date TEXT,
                department TEXT, agent_id TEXT,
                priority TEXT DEFAULT 'Medium', status TEXT DEFAULT 'Received',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                case_id SERIAL PRIMARY KEY,
                customer_name VARCHAR(150), customer_phone VARCHAR(50),
                order_id VARCHAR(50), issue_type VARCHAR(50), description TEXT,
                delivery_date DATE, department VARCHAR(50), agent_id VARCHAR(50),
                priority VARCHAR(20) DEFAULT 'Medium', status VARCHAR(20) DEFAULT 'Received',
                created_at TIMESTAMP DEFAULT NOW()
            )");
            try {
                $db->exec("ALTER TABLE skykin_cases ADD COLUMN IF NOT EXISTS priority VARCHAR(20) DEFAULT 'Medium'");
                $db->exec("ALTER TABLE skykin_cases ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Received'");
            } catch (Exception $ignored) {}
        }

        $s = $db->prepare("INSERT INTO skykin_cases
            (customer_name, customer_phone, order_id, issue_type, description, delivery_date, department, agent_id, priority, status)
            VALUES (:cn, :cp, :oid, :it, :desc, :dd, :dept, :aid, :pri, :st)");
        $s->execute([
            ':cn'   => $input['customer_name']    ?? '',
            ':cp'   => $input['customer_phone']   ?? '',
            ':oid'  => $input['order_id']         ?? '',
            ':it'   => $input['issue_type']        ?? 'Other',
            ':desc' => $input['description']       ?? '',
            ':dd'   => $input['delivery_date']     ?? date('Y-m-d'),
            ':dept' => $input['department']       ?? '',
            ':aid'  => $input['agent_id']         ?? '',
            ':pri'  => $input['priority']         ?? 'Medium',
            ':st'   => 'Received'
        ]);
        echo json_encode(['saved' => true]);
    } catch (Exception $e) {
        echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Case History API ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'case_history') {
    error_reporting(0);
    header('Content-Type: application/json');
    $date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
    $date_to   = isset($_GET['to'])   ? $_GET['to']   : date('Y-m-d');

    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                case_id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name TEXT, customer_phone TEXT, order_id TEXT,
                issue_type TEXT, description TEXT, delivery_date TEXT,
                department TEXT, agent_id TEXT,
                priority TEXT DEFAULT 'Medium', status TEXT DEFAULT 'Received',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $dateFmt   = "strftime('%Y-%m-%d %H:%M', created_at)";
            $dateWhere = "date(created_at) >= :df AND date(created_at) <= :dt";
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                case_id SERIAL PRIMARY KEY,
                customer_name VARCHAR(150), customer_phone VARCHAR(50),
                order_id VARCHAR(50), issue_type VARCHAR(50), description TEXT,
                delivery_date DATE, department VARCHAR(50), agent_id VARCHAR(50),
                priority VARCHAR(20) DEFAULT 'Medium', status VARCHAR(20) DEFAULT 'Received',
                created_at TIMESTAMP DEFAULT NOW()
            )");
            try {
                $db->exec("ALTER TABLE skykin_cases ADD COLUMN IF NOT EXISTS priority VARCHAR(20) DEFAULT 'Medium'");
                $db->exec("ALTER TABLE skykin_cases ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Received'");
            } catch (Exception $ignored) {}
            $dateFmt   = "to_char(created_at,'YYYY-MM-DD HH24:MI')";
            $dateWhere = "created_at >= :df::date AND created_at < (:dt::date + interval '1 day')";
        }

        $s = $db->prepare("SELECT case_id, {$dateFmt} as formatted_date,
            customer_name, customer_phone, order_id, issue_type, description, delivery_date, department, agent_id, priority, status
            FROM skykin_cases
            WHERE {$dateWhere}
            ORDER BY created_at DESC LIMIT 200");
        $s->execute([':df' => $date_from, ':dt' => $date_to]);
        echo json_encode(['records' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['records' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: Lookup Customer/Order ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'lookup_customer') {
    error_reporting(0);
    header('Content-Type: application/json');
    $query = trim($_GET['query'] ?? '');
    
    $data = [
        'contact' => null,
        'deliveries' => [],
        'tickets' => [],
        'current_intransit' => null
    ];
    
    if ($query !== '') {
        try {
            $db = getSkykinDB();
            $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            
            // Ensure skykin_deliveries table exists
            if ($isSQLite) {
                $db->exec("CREATE TABLE IF NOT EXISTS skykin_deliveries (
                    delivery_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_id TEXT UNIQUE NOT NULL,
                    customer_name TEXT,
                    customer_phone TEXT,
                    delivery_address TEXT,
                    delivery_date TEXT,
                    status TEXT DEFAULT 'Pending',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS skykin_deliveries (
                    delivery_id SERIAL PRIMARY KEY,
                    order_id VARCHAR(50) UNIQUE NOT NULL,
                    customer_name VARCHAR(150),
                    customer_phone VARCHAR(50),
                    delivery_address TEXT,
                    delivery_date DATE,
                    status VARCHAR(50) DEFAULT 'Pending',
                    created_at TIMESTAMP DEFAULT NOW()
                )");
            }
            
            // Seed skykin_deliveries if empty
            $cnt = $db->query("SELECT COUNT(*) FROM skykin_deliveries")->fetchColumn();
            if ($cnt == 0) {
                $deliveries = [
                    ['ORD-987654', 'Abebe Girma', '+251911000001', 'Bole, Addis Ababa', date('Y-m-d', strtotime('-3 days')), 'Delivered'],
                    ['ORD-987655', 'Abebe Girma', '+251911000001', 'Bole, Addis Ababa', date('Y-m-d'), 'In Transit'],
                    ['ORD-123456', 'Sara Mohammed', '+251922000002', 'Kazanchis, Addis Ababa', date('Y-m-d', strtotime('-2 days')), 'Delivered'],
                    ['ORD-123457', 'Sara Mohammed', '+251922000002', 'Kazanchis, Addis Ababa', date('Y-m-d', strtotime('+1 day')), 'Pending'],
                    ['ORD-111222', 'Dawit Bekele', '+251933000003', 'Piazza, Addis Ababa', date('Y-m-d', strtotime('-1 days')), 'Delivered'],
                    ['ORD-333444', 'Abebe Girma', '0911000001', 'Bole, Addis Ababa', date('Y-m-d', strtotime('-5 days')), 'Delivered'],
                    ['ORD-555666', 'Sara Mohammed', '0922000002', 'Kazanchis, Addis Ababa', date('Y-m-d', strtotime('-6 days')), 'Delivered']
                ];
                $ins = $db->prepare("INSERT INTO skykin_deliveries (order_id, customer_name, customer_phone, delivery_address, delivery_date, status) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($deliveries as $d) {
                    $ins->execute($d);
                }
            }
            
            // Check if query is an Order ID
            $s_ord = $db->prepare("SELECT * FROM skykin_deliveries WHERE UPPER(order_id) = UPPER(:q) LIMIT 1");
            $s_ord->execute([':q' => $query]);
            $orderRow = $s_ord->fetch(PDO::FETCH_ASSOC);
            
            $customerPhone = $query;
            if ($orderRow) {
                $customerPhone = $orderRow['customer_phone'];
            }
            
            $cleanPhone = preg_replace('/^(\+251|00251|0)/', '', $customerPhone);
            
            // Look up CRM contact profile
            $hasContacts = false;
            try {
                $db->query("SELECT 1 FROM skykin_contacts LIMIT 1");
                $hasContacts = true;
            } catch (Exception $e) {}
            
            if ($hasContacts) {
                $s_c = $db->prepare("SELECT * FROM skykin_contacts 
                    WHERE phone LIKE :q OR alt_phone LIKE :q 
                       OR phone LIKE :c OR alt_phone LIKE :c 
                    ORDER BY contact_id LIMIT 1");
                $s_c->execute([':q' => '%' . $customerPhone . '%', ':c' => '%' . $cleanPhone . '%']);
                $data['contact'] = $s_c->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            
            // Create profile fallback if not found
            if (!$data['contact']) {
                $s_name = $db->prepare("SELECT customer_name FROM skykin_deliveries WHERE customer_phone = :phone LIMIT 1");
                $s_name->execute([':phone' => $customerPhone]);
                $foundName = $s_name->fetchColumn();
                if (!$foundName) {
                    $s_name2 = $db->prepare("SELECT customer_name FROM skykin_cases WHERE customer_phone = :phone LIMIT 1");
                    $s_name2->execute([':phone' => $customerPhone]);
                    $foundName = $s_name2->fetchColumn();
                }
                $data['contact'] = [
                    'full_name' => $foundName ?: 'Customer (' . $customerPhone . ')',
                    'phone' => $customerPhone,
                    'email' => '-',
                    'company' => '-',
                    'language' => 'English',
                    'account_type' => 'Customer',
                    'notes' => ''
                ];
            }
            
            // Fetch deliveries
            $s_dels = $db->prepare("SELECT * FROM skykin_deliveries 
                WHERE customer_phone = :phone OR customer_phone LIKE :c 
                ORDER BY delivery_date DESC");
            $s_dels->execute([':phone' => $customerPhone, ':c' => '%' . $cleanPhone]);
            $data['deliveries'] = $s_dels->fetchAll(PDO::FETCH_ASSOC);
            
            // Current in-transit
            foreach ($data['deliveries'] as $del) {
                if ($del['status'] === 'In Transit' || $del['status'] === 'Pending') {
                    $data['current_intransit'] = $del;
                    break;
                }
            }
            
            // Fetch tickets
            if ($isSQLite) {
                $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                    case_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    customer_name TEXT, customer_phone TEXT, order_id TEXT,
                    issue_type TEXT, description TEXT, delivery_date TEXT,
                    department TEXT, agent_id TEXT,
                    priority TEXT DEFAULT 'Medium', status TEXT DEFAULT 'Received',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
                $dateFmt = "strftime('%Y-%m-%d %H:%M', created_at)";
            } else {
                $dateFmt = "to_char(created_at,'YYYY-MM-DD HH24:MI')";
            }
            
            $s_tix = $db->prepare("SELECT case_id, {$dateFmt} as formatted_date,
                customer_name, customer_phone, order_id, issue_type, description, delivery_date, department, agent_id, priority, status
                FROM skykin_cases 
                WHERE customer_phone = :phone OR customer_phone LIKE :c 
                ORDER BY created_at DESC");
            $s_tix->execute([':phone' => $customerPhone, ':c' => '%' . $cleanPhone]);
            $data['tickets'] = $s_tix->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $data['error'] = $e->getMessage();
        }
    }
    
    echo json_encode($data);
    exit;
}

// ── API: Save Callback ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'save_callback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $customer_name  = $body['customer_name'] ?? '';
    $customer_phone = $body['customer_phone'] ?? '';
    $callback_time  = $body['callback_time'] ?? '';
    $notes          = $body['notes'] ?? '';
    $agent_id       = $body['agent_id'] ?? '';
    
    if (!$customer_phone || !$callback_time) {
        echo json_encode(['ok' => false, 'error' => 'Phone and Time are required.']);
        exit;
    }
    
    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_callbacks (
                callback_id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name TEXT,
                customer_phone TEXT,
                callback_time TEXT,
                notes TEXT,
                agent_id TEXT,
                status TEXT DEFAULT 'Scheduled',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_callbacks (
                callback_id SERIAL PRIMARY KEY,
                customer_name VARCHAR(150),
                customer_phone VARCHAR(50),
                callback_time TIMESTAMP,
                notes TEXT,
                agent_id VARCHAR(50),
                status VARCHAR(50) DEFAULT 'Scheduled',
                created_at TIMESTAMP DEFAULT NOW()
            )");
        }
        
        $s = $db->prepare("INSERT INTO skykin_callbacks (customer_name, customer_phone, callback_time, notes, agent_id) VALUES (:name, :phone, :time, :notes, :agent)");
        $s->execute([
            ':name' => $customer_name,
            ':phone' => $customer_phone,
            ':time' => $callback_time,
            ':notes' => $notes,
            ':agent' => $agent_id
        ]);
        
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: List Callbacks ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'list_callbacks') {
    error_reporting(0);
    header('Content-Type: application/json');
    $agent_id = $_GET['agent_id'] ?? '';
    
    try {
        $db = getSkykinDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_callbacks (
                callback_id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name TEXT,
                customer_phone TEXT,
                callback_time TEXT,
                notes TEXT,
                agent_id TEXT,
                status TEXT DEFAULT 'Scheduled',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $timeFmt = "callback_time";
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_callbacks (
                callback_id SERIAL PRIMARY KEY,
                customer_name VARCHAR(150),
                customer_phone VARCHAR(50),
                callback_time TIMESTAMP,
                notes TEXT,
                agent_id VARCHAR(50),
                status VARCHAR(50) DEFAULT 'Scheduled',
                created_at TIMESTAMP DEFAULT NOW()
            )");
            $timeFmt = "to_char(callback_time, 'YYYY-MM-DD HH24:MI')";
        }
        
        $s = $db->prepare("SELECT callback_id, customer_name, customer_phone, {$timeFmt} as formatted_time, notes, status 
            FROM skykin_callbacks 
            WHERE agent_id = :agent AND status = 'Scheduled' 
            ORDER BY callback_time ASC LIMIT 100");
        $s->execute([':agent' => $agent_id]);
        
        echo json_encode(['records' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['records' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: Update Callback Status ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_callback_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($body['callback_id'] ?? 0);
    $status = $body['status'] ?? 'Completed';
    
    try {
        $db = getSkykinDB();
        $s = $db->prepare("UPDATE skykin_callbacks SET status = :status WHERE callback_id = :id");
        $s->execute([':status' => $status, ':id' => $id]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: Set Agent Status in FusionPBX Call Center via DB + ESL ─────────────
// FusionPBX's real mechanism: UPDATE v_call_center_agents AND send
//   callcenter_config agent set status <uuid> '<status>'
// through the FreeSWITCH Event Socket (port 8021).
// ESL credentials are read from .env: ESL_HOST, ESL_PORT, ESL_PASSWORD.
// If ESL is unreachable (e.g. local dev with ACL blocking), the DB is still
// updated and the response includes esl_error for debugging.
if (isset($_GET['action']) && $_GET['action'] === 'set_agent_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $body       = json_decode(file_get_contents('php://input'), true) ?: [];
    $agent_ext  = trim($body['agent_ext']  ?? '');
    $new_status = trim($body['new_status'] ?? 'Available');
    $domain_    = trim($body['domain']     ?? 'client1.skykin.local');

    // Only allow valid FusionPBX agent_status values
    $allowed = ['Available', 'Available (On Demand)', 'On Break', 'Logged Out'];
    if (!in_array($new_status, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid status: ' . $new_status]);
        exit;
    }
    if (!$agent_ext) {
        echo json_encode(['ok' => false, 'error' => 'agent_ext is required']);
        exit;
    }

    try {
        $db = getSkykinDB();

        // ── 1. Resolve domain_uuid (robust: exact match → first domain fallback) ──
        // v_call_center_agents uses domain_uuid FK; there is no domain_name column.
        // The domain passed in the URL (e.g. client1.skykin.local) may differ from
        // the v_domains name (e.g. 192.168.0.114). Fallback ensures we always match.
        $s_dom = $db->prepare("SELECT domain_uuid FROM v_domains WHERE domain_name = :d LIMIT 1");
        $s_dom->execute([':d' => $domain_]);
        $domain_uuid = $s_dom->fetchColumn();
        if (!$domain_uuid) {
            // No exact domain_name match — fall back to the first (or only) domain
            $domain_uuid = $db->query("SELECT domain_uuid FROM v_domains LIMIT 1")->fetchColumn();
        }
        if (!$domain_uuid) {
            echo json_encode(['ok' => false, 'error' => 'No domain found in v_domains']);
            exit;
        }

        // ── 2. Lookup the agent record (by agent_id or agent_contact pattern) ────
        // FusionPBX stores agent_contact as e.g. "user/1003@192.168.0.114".
        // Match by agent_id (the numeric ID) OR the SIP contact pattern.
        $pat = '%/' . $agent_ext . '@%';
        $s_agent = $db->prepare(
            "SELECT call_center_agent_uuid, agent_name, user_uuid
             FROM v_call_center_agents
             WHERE (agent_id = :ext OR agent_contact LIKE :pat)
               AND domain_uuid = :domain_uuid
             LIMIT 1"
        );
        $s_agent->execute([':ext' => $agent_ext, ':pat' => $pat, ':domain_uuid' => $domain_uuid]);
        $agent_row = $s_agent->fetch(PDO::FETCH_ASSOC);
        if (!$agent_row) {
            echo json_encode([
                'ok'          => false,
                'error'       => 'No Call Center agent found for ext ' . $agent_ext .
                                 ' in domain_uuid ' . $domain_uuid .
                                 '. Add this extension in FusionPBX → Call Center → Agents.',
                'domain_uuid' => $domain_uuid,
                'agent_ext'   => $agent_ext,
            ]);
            exit;
        }
        $agent_uuid = $agent_row['call_center_agent_uuid'];

        // ── 3. Update the database ────────────────────────────────────────────────
        $s_upd = $db->prepare(
            "UPDATE v_call_center_agents SET agent_status = :s WHERE call_center_agent_uuid = :uuid"
        );
        $s_upd->execute([':s' => $new_status, ':uuid' => $agent_uuid]);

        // ── 4. Send ESL command to FreeSWITCH ─────────────────────────────────────
        // Load ESL credentials: env vars → .env file → defaults (127.0.0.1:8021/ClueCon)
        // On production VM the ESL ACL allows 127.0.0.1 by default.
        // For local dev: add ESL_HOST=<vm_ip> and ensure VM ACL allows your IP,
        // or SSH-tunnel: ssh -L 8021:127.0.0.1:8021 user@<vm_ip>
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
        if (getenv('ESL_HOST'))     $esl_host = getenv('ESL_HOST');
        if (getenv('ESL_PORT'))     $esl_port = intval(getenv('ESL_PORT'));
        if (getenv('ESL_PASSWORD')) $esl_pass = getenv('ESL_PASSWORD');

        // Load FusionPBX's own event_socket class (no require.php needed)
        $esl_connected   = false;
        $esl_response    = '';
        $esl_state_resp  = '';
        $esl_error       = '';
        try {
            if (!class_exists('config'))       require_once __DIR__ . '/../../resources/classes/config.php';
            if (!class_exists('event_socket')) require_once __DIR__ . '/../../resources/classes/event_socket.php';
            $esl = new event_socket();
            if ($esl->connect($esl_host, $esl_port, $esl_pass)) {
                $esl_connected = true;
                // Set agent status (exact FusionPBX pattern from call_center_agent_status.php)
                $res = $esl->request('api callcenter_config agent set status ' . $agent_uuid . " '" . $new_status . "'");
                $esl_response = is_array($res) ? ($res['$'] ?? implode(' | ', $res)) : (string)$res;
                // Set agent state to Waiting when becoming Available or Logged Out
                if ($new_status === 'Available' || $new_status === 'Logged Out') {
                    $res2 = $esl->request('api callcenter_config agent set state ' . $agent_uuid . " 'Waiting'");
                    $esl_state_resp = is_array($res2) ? ($res2['$'] ?? implode(' | ', $res2)) : (string)$res2;
                }
            } else {
                $esl_error = 'ESL connect failed — ACL rejected or wrong password. ' .
                             'On production VM this works via 127.0.0.1. ' .
                             'For local dev: add ESL_HOST=' . $esl_host . ' to .env and allow your IP in ' .
                             'freeswitch/autoload_configs/event_socket.conf.xml, or SSH-tunnel port 8021.';
            }
        } catch (Throwable $esl_ex) {
            $esl_error = $esl_ex->getMessage();
        }

        echo json_encode([
            'ok'             => true,
            'agent_name'     => $agent_row['agent_name'],
            'agent_uuid'     => $agent_uuid,
            'status'         => $new_status,
            'db_updated'     => true,
            'esl_connected'  => $esl_connected,
            'esl_response'   => trim($esl_response),
            'esl_state_resp' => trim($esl_state_resp),
            'esl_error'      => $esl_error,
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: Get Available Agents (for call transfer target list) ────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_available_agents') {
    error_reporting(0);
    header('Content-Type: application/json');
    $domain_ = trim($_GET['domain'] ?? 'client1.skykin.local');
    $my_ext  = trim($_GET['my_ext'] ?? '');

    try {
        $db = getSkykinDB();
        // Resolve domain_uuid with fallback (same logic as set_agent_status)
        $s_dom = $db->prepare("SELECT domain_uuid FROM v_domains WHERE domain_name = :d LIMIT 1");
        $s_dom->execute([':d' => $domain_]);
        $domain_uuid = $s_dom->fetchColumn();
        if (!$domain_uuid) {
            $domain_uuid = $db->query("SELECT domain_uuid FROM v_domains LIMIT 1")->fetchColumn();
        }
        $s  = $db->prepare(
            "SELECT agent_name, agent_contact, agent_status
             FROM v_call_center_agents
             WHERE domain_uuid = :domain_uuid AND agent_status = 'Available'
             ORDER BY agent_name ASC"
        );
        $s->execute([':domain_uuid' => $domain_uuid]);
        $agents = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Parse extension from contact URI: user/EXT@host or sofia/internal/EXT@domain
            if (preg_match('/\/(\d{2,6})@/', $row['agent_contact'], $m)) {
                $ext = $m[1];
                if ($ext === $my_ext) continue; // exclude caller's own extension
                $agents[] = [
                    'name'      => $row['agent_name'],
                    'extension' => $ext,
                    'status'    => $row['agent_status'],
                ];
            }
        }
        echo json_encode(['agents' => $agents]);
    } catch (Exception $e) {
        echo json_encode(['agents' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Isolated SMS Function & API Endpoint ────────────────────────────────────
function sendSms($phoneNumber, $message) {
    try {
        $db = getSkykinDB();
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
        
        // Log the attempt initially as 'Pending'
        $s = $db->prepare("INSERT INTO skykin_sms_logs (phone_number, message, status) VALUES (:phone, :msg, :status)");
        $s->execute([
            ':phone' => $phoneNumber,
            ':msg' => $message,
            ':status' => 'Pending'
        ]);
        $smsId = $db->lastInsertId();
        
        // 1. Verify PHP cURL module is loaded
        if (!extension_loaded('curl')) {
            throw new Exception("PHP cURL extension is not enabled in this environment. Please enable extension=curl in php.ini.");
        }
        
        // Fetch environment variables
        $apiKey   = getenv('SMS_API_KEY');
        $username = getenv('SMS_API_USERNAME');
        $senderId = getenv('SMS_SENDER_ID');
        
        // Fallback: parse local .env if present in workspace/dashboard
        if (!$apiKey || !$username) {
            foreach ([__DIR__ . '/../../.env', __DIR__ . '/../.env', __DIR__ . '/.env'] as $envPath) {
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
        
        // 2. Verify credentials are configured
        if (!$apiKey || !$username) {
            throw new Exception("SMS credentials not configured. Please define SMS_API_KEY and SMS_API_USERNAME in your environment or .env file.");
        }
        
        $isSandbox = (strtolower($username) === 'sandbox');
        $endpoint = $isSandbox 
            ? 'https://api.sandbox.africastalking.com/version1/messaging' 
            : 'https://api.africastalking.com/version1/messaging';
            
        $postData = [
            'username' => $username,
            'to'       => $phoneNumber,
            'message'  => $message
        ];
        if (!empty($senderId)) {
            $postData['from'] = $senderId;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: {$apiKey}",
            "Accept: application/json",
            "Content-Type: application/x-www-form-urlencoded"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $success = false;
        if ($response === false) {
            $finalStatus = "Failed: Connection error (" . $curlError . ")";
            throw new Exception($finalStatus);
        } else {
            $result = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($result['SMSMessageData']['Recipients'][0])) {
                $recipient = $result['SMSMessageData']['Recipients'][0];
                $recStatus = $recipient['status'] ?? 'Failed';
                $recMsgId = $recipient['messageId'] ?? '';
                
                if (strtolower($recStatus) === 'success') {
                    $finalStatus = "Sent (ID: " . $recMsgId . ")";
                    $success = true;
                } else {
                    $finalStatus = "Failed: " . $recStatus;
                    throw new Exception($finalStatus);
                }
            } else {
                $errDetail = isset($result['errorMessage']) ? $result['errorMessage'] : $response;
                $finalStatus = "Failed: HTTP {$httpCode} - " . substr(trim(strip_tags($errDetail)), 0, 100);
                throw new Exception($finalStatus);
            }
        }
        
        $update = $db->prepare("UPDATE skykin_sms_logs SET status = :status WHERE sms_id = :id");
        $update->execute([':status' => $finalStatus, ':id' => $smsId]);
        
        return $success;
    } catch (Exception $e) {
        error_log("sendSms error: " . $e->getMessage());
        if (isset($db) && isset($smsId)) {
            try {
                $update = $db->prepare("UPDATE skykin_sms_logs SET status = :status WHERE sms_id = :id");
                $update->execute([':status' => 'Failed: ' . $e->getMessage(), ':id' => $smsId]);
            } catch(Exception $ignored) {}
        }
        throw $e;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'send_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $phone = $body['phone'] ?? '';
    $message = $body['message'] ?? '';
    
    if (!$phone || !$message) {
        echo json_encode(['ok' => false, 'error' => 'Phone and message are required.']);
        exit;
    }
    
    try {
        $ok = sendSms($phone, $message);
        echo json_encode(['ok' => $ok]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$agent_name = isset($_GET['agent']) ? htmlspecialchars($_GET['agent']) : 'Agent1';
$domain = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : 'client1.skykin.local';

// Detect if logged-in user is supervisor/admin
$is_supervisor = false;
try {
    $sess_user = $_SESSION['username'] ?? '';
    if ($sess_user) {
        $conf2 = '/etc/fusionpbx/config.conf';
        $dh='127.0.0.1';$dp='5432';$dn='fusionpbx';$du='fusionpbx';$dw='';
        if (file_exists($conf2)) foreach(file($conf2) as $ln2) {
            $ln2=trim($ln2);
            if(strpos($ln2,'database.0.host')!==false)     $dh=trim(explode('=',$ln2,2)[1]);
            if(strpos($ln2,'database.0.port')!==false)     $dp=trim(explode('=',$ln2,2)[1]);
            if(strpos($ln2,'database.0.name')!==false)     $dn=trim(explode('=',$ln2,2)[1]);
            if(strpos($ln2,'database.0.username')!==false) $du=trim(explode('=',$ln2,2)[1]);
            if(strpos($ln2,'database.0.password')!==false) $dw=trim(explode('=',$ln2,2)[1]);
        }
        $rdb = new PDO("pgsql:host={$dh};port={$dp};dbname={$dn}", $du, $dw, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $rs = $rdb->prepare("SELECT g.group_name FROM v_users u
            JOIN v_user_groups ug ON ug.user_uuid=u.user_uuid
            JOIN v_groups g ON g.group_uuid=ug.group_uuid
            WHERE LOWER(u.username)=LOWER(:u) LIMIT 5");
        $rs->execute([':u'=>$sess_user]);
        foreach($rs->fetchAll(PDO::FETCH_COLUMN) as $grp) {
            if (in_array(strtolower($grp), ['superadmin','admin','supervisor'])) {
                $is_supervisor = true; break;
            }
        }
    }
} catch(Exception $ignored){}

// Generate initials from agent name
preg_match('/([A-Za-z]+)(\d*)/', $agent_name, $m);
$initials = strtoupper(substr($m[1] ?? $agent_name, 0, 2));
if (!empty($m[2])) $initials = strtoupper($m[1][0]) . $m[2];

$today = date('Y-m-d');

// ?? Resolve the agent's extension from FusionPBX DB ??????????????????????????
$agent_ext      = '';
$agent_password = '';
// Reached through nginx on the page's own port so the socket reuses the
// dashboard certificate; FreeSWITCH's own cert on 7443 is rejected by browsers.
$agent_wss      = 'wss://' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) . '/wss/';
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
    $pdb = getSkykinDB();

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
} catch (Exception $e) { /* silent — JS will fall back to localStorage */ }

// ── Agent timing config + queue/tier assignments (any extension, read-only) ──
// NOTE: In v_call_center_agents, agent_name is a human label (e.g. "Agent 1003"),
//       NOT the extension number. The extension is encoded in agent_contact as
//       "user/1003@host". We match via agent_contact LIKE '%/EXT@%'.
$agent_timing = [];
$agent_queues = [];
if (!empty($agent_ext) && isset($pdb)) {
    try {
        // Timing rules — match by extension embedded in agent_contact SIP URI
        $st = $pdb->prepare(
            "SELECT agent_call_timeout, agent_no_answer_delay_time,
                    agent_wrap_up_time, agent_reject_delay_time, agent_busy_delay_time
             FROM v_call_center_agents
             WHERE agent_contact LIKE :pat
             LIMIT 1"
        );
        $st->execute([':pat' => '%/' . $agent_ext . '@%']);
        $agent_timing = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Assigned queues + tier — same contact pattern join
        $sq = $pdb->prepare(
            "SELECT q.queue_name, q.queue_extension,
                    t.tier_level, t.tier_position
             FROM v_call_center_tiers  t
             JOIN v_call_center_queues q ON q.call_center_queue_uuid = t.call_center_queue_uuid
             JOIN v_call_center_agents a ON a.call_center_agent_uuid = t.call_center_agent_uuid
             WHERE a.agent_contact LIKE :pat
             ORDER BY t.tier_level ASC, t.tier_position ASC"
        );
        $sq->execute([':pat' => '%/' . $agent_ext . '@%']);
        $agent_queues = $sq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* silent — display gracefully in UI */ }
}
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
.main { margin-top: 64px; padding: 20px; margin-bottom: 20px; transition: margin-right 0.3s ease; }

/* CRM slide-in panel */
.crm-panel {
    position: fixed; top: 64px; right: -420px; width: 400px;
    height: calc(100vh - 64px); background: #fff;
    box-shadow: -4px 0 24px rgba(0,0,0,0.2);
    z-index: 500; transition: right 0.3s ease;
    display: flex; flex-direction: column;
    border-left: 3px solid #0047AB;
}
.crm-panel.open { right: 0; }
.crm-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; background: #0047AB; color: #fff; flex-shrink: 0;
}
.crm-panel-header span { font-size: 12px; font-weight: 600; }
.crm-panel-header button {
    background: rgba(255,255,255,0.2); border: none; color: #fff;
    border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 11px;
}
.crm-panel-header button:hover { background: rgba(255,255,255,0.35); }
.crm-panel iframe { flex: 1; border: none; width: 100%; }

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
    position: fixed; bottom: 28px; right: 28px; z-index: 600;
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #0047AB, #00B4D8);
    border: none; cursor: pointer; color: white; font-size: 24px;
    box-shadow: 0 4px 20px rgba(0,71,171,0.45);
    display: flex; align-items: center; justify-content: center;
    transition: transform 0.2s, box-shadow 0.2s, right 0.3s ease;
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
    position: fixed; top: 60px; right: -320px; z-index: 601;
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
/* ?? Agent Configuration Strip ?? */
.agent-config-strip {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.agent-config-box {
    background: #fff;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
    border-top: 3px solid #0047AB;
}
.agent-config-box.queues  { border-top-color: #fd7e14; }
.agent-config-box.tier    { border-top-color: #6f42c1; }
.agent-config-title {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: #555; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.config-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 5px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px;
}
.config-row:last-child { border-bottom: none; }
.config-label { color: #777; }
.config-val   { font-weight: 600; color: #1e293b; }
.config-val.na { color: #aaa; font-weight: 400; font-style: italic; }
.queue-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff7ed; border: 1px solid #fed7aa;
    color: #c2410c; border-radius: 20px;
    padding: 4px 10px; font-size: 12px; font-weight: 600; margin-bottom: 6px;
}
.queue-badge .qext { color: #9a3412; font-size: 11px; opacity: .8; }
.tier-big {
    font-size: 36px; font-weight: 800; color: #6f42c1;
    text-align: center; margin: 8px 0 4px;
    line-height: 1;
}
.tier-pos { text-align: center; font-size: 12px; color: #999; }
.not-in-queue {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 10px 0; gap: 4px;
}
.not-in-queue .nq-icon { font-size: 28px; opacity: .35; }
.not-in-queue .nq-label { font-size: 12px; color: #aaa; font-style: italic; }

/* ?? Modal CSS Styling ?? */
.custom-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65);
    backdrop-filter: blur(4px); z-index: 1001; align-items: center; justify-content: center;
}
.custom-modal-overlay.show { display: flex; }
.custom-modal-box {
    background: white; border-radius: 12px; padding: 24px; width: 400px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25); display: flex; flex-direction: column; gap: 14px;
    animation: slideUp 0.2s ease;
}
.custom-modal-hdr {
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid #e9ecef; padding-bottom: 10px; margin-bottom: 4px;
}
.custom-modal-hdr h3 { font-size: 15px; color: #0047AB; font-weight: 700; margin: 0; }
.custom-modal-hdr .close-btn { background: none; border: none; font-size: 20px; color: #888; cursor: pointer; }
.custom-modal-body { display: flex; flex-direction: column; gap: 12px; }

/* ?? Lookup Customer Styling ?? */
.lookup-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
.profile-card { background: #fafbfc; border-radius: 8px; border: 1px solid #e2e8f0; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.profile-title { font-weight: 700; font-size: 15px; color: #1e293b; border-bottom: 1px solid #edf2f7; padding-bottom: 6px; }
.profile-item { display: flex; justify-content: space-between; font-size: 13px; border-bottom: 1px dashed #f1f5f9; padding: 4px 0; }
.profile-item span:first-child { color: #64748b; }
.profile-item span:last-child { font-weight: 600; color: #334155; }
.intransit-banner {
    background: #fff7ed; border-left: 4px solid #f97316; border-radius: 8px;
    padding: 14px; font-size: 13px; color: #c2410c; display: flex; flex-direction: column; gap: 6px;
    box-shadow: 0 2px 8px rgba(249,115,22,0.08);
}
.intransit-banner strong { color: #9a3412; }
.btn-action-sms { background: #0ea5e9; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: bold; }
.btn-action-sms:hover { background: #0284c7; }

/* ?? Highlight Callback Row ?? */
.callback-urgent { background-color: #fffbeb !important; border-left: 3px solid #f59e0b; }
.callback-urgent td { font-weight: 600; }
.btn-action-resolve { background: #10b981; color: white; border: none; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: bold; }
.btn-action-resolve:hover { background: #059669; }

/* ── Transfer Call Modal ──────────────────────────────────────────────────── */
.transfer-overlay {
    display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65);
    backdrop-filter: blur(4px); z-index: 1002; align-items: center; justify-content: center;
}
.transfer-overlay.show { display: flex; }
.transfer-modal {
    background: white; border-radius: 14px; width: 100%; max-width: 380px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden;
    animation: slideUp 0.2s ease;
}
.transfer-hdr {
    padding: 14px 18px; border-bottom: 1px solid #e9ecef;
    display: flex; align-items: center; justify-content: space-between;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
}
.transfer-hdr h3 { font-size: 14px; font-weight: 700; color: white; margin: 0; }
.transfer-hdr button {
    background: rgba(255,255,255,0.2); border: none; cursor: pointer;
    font-size: 16px; color: white; width: 26px; height: 26px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}
.transfer-body {
    padding: 16px; display: flex; flex-direction: column; gap: 12px;
    max-height: 400px; overflow-y: auto;
}
.transfer-ext-row { display: flex; gap: 8px; align-items: center; }
.transfer-ext-row input {
    flex: 1; padding: 8px 10px; border: 1px solid #ddd;
    border-radius: 8px; font-size: 14px; outline: none;
}
.transfer-ext-row input:focus { border-color: #6366f1; }
.transfer-ext-row button {
    background: #6366f1; color: white; border: none;
    padding: 8px 14px; border-radius: 8px; cursor: pointer;
    font-size: 13px; font-weight: bold; white-space: nowrap;
}
.transfer-ext-row button:hover { background: #4f46e5; }
.transfer-agents-list { display: flex; flex-direction: column; gap: 6px; }
.transfer-agent-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; border: 1px solid #e9ecef; border-radius: 8px;
    cursor: pointer; transition: all 0.15s;
}
.transfer-agent-item:hover { background: #f5f3ff; border-color: #6366f1; }
.transfer-agent-info { display: flex; flex-direction: column; }
.transfer-agent-name { font-size: 13px; font-weight: 600; color: #1e293b; }
.transfer-agent-ext { font-size: 11px; color: #64748b; }
.transfer-agent-badge {
    background: #d1fae5; color: #059669; font-size: 10px;
    font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.transfer-loading { text-align: center; color: #888; font-size: 13px; padding: 20px; }
.btn-transfer {
    background: #6366f1; border: none; color: white;
    padding: 10px 0; border-radius: 8px; cursor: pointer;
    font-size: 13px; font-weight: bold;
    grid-column: span 2; margin-top: 4px; display: none;
}
.btn-transfer:hover { background: #4f46e5; }
.btn-transfer.visible { display: block; }
</style>
</head>
<body>

<!-- ?? HEADER ?? -->
<div class="header">
    <div style="display:flex;align-items:center;gap:12px">
        <button onclick="toggleAgentSideMenu()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;border-radius:8px;font-size:20px;cursor:pointer;line-height:1;flex-shrink:0">&#9776;</button>
        <div class="logo">SKY<span>KIN</span> Technologies</div>
    </div>
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

<!-- ── Agent Side Menu ─────────────────────────────────────── -->
<div id="agentSideMenu" style="position:fixed;top:0;left:-260px;width:250px;height:100vh;background:#fff;box-shadow:4px 0 24px rgba(0,0,0,.18);z-index:500;transition:left .25s ease;display:flex;flex-direction:column">
    <div style="background:linear-gradient(135deg,#0047AB,#00B4D8);padding:20px;color:#fff;flex-shrink:0">
        <div style="font-size:17px;font-weight:700"><span style="color:#00e5ff">SKY</span>KIN Technologies</div>
        <div style="font-size:11px;opacity:.8;margin-top:3px">Agent Panel</div>
    </div>
    <div style="flex:1;overflow-y:auto;padding:8px 0">
        <?php if ($is_supervisor): ?>
        <a href="supervisor.php" style="display:flex;align-items:center;gap:12px;padding:14px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:18px">&#128202;</span> Supervisor View
        </a>
        <div style="height:1px;background:#eee;margin:6px 0"></div>
        <?php endif; ?>

        <div style="padding:8px 20px 4px;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.8px">Menu</div>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('dashboard')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128187;</span> Dashboard
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('callHistory')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128222;</span> Call History
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('recordings')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#127908;</span> Recordings
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('acw')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128203;</span> ACW History
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('escalation')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#127915;</span> New Ticket
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('lookup')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128269;</span> Customer Lookup
        </a>
        <a href="#" onclick="toggleAgentSideMenu();switchTab('callbacks')" style="display:flex;align-items:center;gap:12px;padding:11px 20px;color:#333;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#f8f9fa';this.style.borderColor='#0047AB'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128197;</span> Callbacks
        </a>

        <div style="height:1px;background:#eee;margin:6px 0"></div>
        <a href="/logout.php" style="display:flex;align-items:center;gap:12px;padding:12px 20px;color:#dc3545;text-decoration:none;font-size:14px;border-left:4px solid transparent" onmouseover="this.style.background='#fff5f5';this.style.borderColor='#dc3545'" onmouseout="this.style.background='';this.style.borderColor='transparent'">
            <span style="font-size:16px">&#128682;</span> Sign Out
        </a>
    </div>
</div>
<div id="agentSideMenuBackdrop" onclick="toggleAgentSideMenu()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.25);z-index:499"></div>

<!-- ?? MAIN ?? -->
<div class="main">

    <div class="full-section" id="sectionHistory">
        <div class="tab-bar">
            <button class="tab-btn active" id="tabDashboardBtn" onclick="switchTab('dashboard')">Dashboard</button>
            <button class="tab-btn" id="tabCallHistoryBtn" onclick="switchTab('callHistory')">Call History</button>
            <button class="tab-btn" id="tabRecordingsBtn" onclick="switchTab('recordings')">Recordings</button>
            <button class="tab-btn" id="tabAcwBtn" onclick="switchTab('acw')">ACW History</button>
            <button class="tab-btn" id="tabEscalationBtn" onclick="switchTab('escalation')">New Ticket</button>
            <button class="tab-btn" id="tabLookupBtn" onclick="switchTab('lookup')">Customer Lookup</button>
            <button class="tab-btn" id="tabCallbacksBtn" onclick="switchTab('callbacks')">Callbacks</button>
        </div>

        <!-- ?? Dashboard Tab (landing overview) ?? -->
        <div class="tab-panel active" id="tabDashboard">
    <!-- Summary Cards -->
    <div class="summary-grid" id="sectionKpi">
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

    <!-- Two Column Metrics: Status & Activity | Queue Status -->
    <div class="section-grid">
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

    <!-- ?? Agent Configuration Strip ?? -->
    <div class="agent-config-strip">

        <!-- Timing Controls -->
        <div class="agent-config-box">
            <div class="agent-config-title">Agent Timing Controls</div>
            <div id="timingContainer">
                <div class="config-row"><span class="config-label">Call Timeout</span><span class="config-val" id="valCallTimeout">--</span></div>
                <div class="config-row"><span class="config-label">No-Answer Delay</span><span class="config-val" id="valNoAnswerDelay">--</span></div>
                <div class="config-row"><span class="config-label">Wrap-Up Time</span><span class="config-val" id="valWrapUpTime">--</span></div>
                <div class="config-row"><span class="config-label">Reject Delay</span><span class="config-val" id="valRejectDelay">--</span></div>
                <div class="config-row"><span class="config-label">Busy Delay</span><span class="config-val" id="valBusyDelay">--</span></div>
            </div>
            <div class="not-in-queue" id="timingNoRecord" style="display:none;">
                <span class="nq-icon">&#128683;</span>
                <span class="nq-label">No agent record found</span>
            </div>
        </div>

        <!-- Assigned Queues -->
        <div class="agent-config-box queues">
            <div class="agent-config-title">Assigned Queue(s)</div>
            <div id="queuesContainer" style="padding: 5px 0;">
                <!-- Badges will be generated here -->
            </div>
            <div class="not-in-queue" id="queuesNoRecord" style="display:none;">
                <span class="nq-icon">&#128683;</span>
                <span class="nq-label">Not in a queue</span>
            </div>
        </div>

        <!-- Tier / Skill Level -->
        <div class="agent-config-box tier">
            <div class="agent-config-title">Tier / Skill Level</div>
            <div id="tierContainer">
                <div class="tier-big" id="valTierBig">--</div>
                <div class="tier-pos" id="valTierPos">Position: --</div>
                <div id="tierBreakdown" style="margin-top:10px;"></div>
            </div>
            <div class="not-in-queue" id="tierNoRecord" style="display:none;">
                <span class="nq-icon">&#128683;</span>
                <span class="nq-label">Not in a queue</span>
            </div>
        </div>

    </div>
        </div>

        <!-- ?? Call History Tab ?? -->
        <div class="tab-panel" id="tabCallHistory" style="display:none">
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

        <!-- Customer Lookup Tab -->
        <div class="tab-panel" id="tabLookup" style="display:none">
            <div style="display:flex; flex-direction:column; gap: 16px;">
                <!-- Search bar -->
                <div class="section-box" style="display:flex; gap:12px; align-items:center;">
                    <label style="font-weight:bold; font-size:13px; color:#555; white-space:nowrap;">Manual Lookup:</label>
                    <input type="text" id="lookupQuery" placeholder="Enter Phone Number or Order ID..." style="flex:1; padding:8px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px;">
                    <button class="btn-filter" onclick="performLookup(document.getElementById('lookupQuery').value)" style="padding:8px 20px; font-weight:bold;">Search</button>
                    <button class="btn-filter-clear" onclick="clearLookup()" style="padding:8px 12px;">Clear</button>
                </div>

                <!-- Lookup Results Grid -->
                <div class="lookup-grid">
                    <!-- Left Side: Customer CRM Info & Current In-Transit Status -->
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <!-- Customer Profile Card -->
                        <div class="section-box">
                            <div class="section-title"><span class="dot" style="background:#0047AB"></span> Customer Profile</div>
                            <div id="lookupProfileBox" class="profile-card">
                                <p style="color:#888; font-style:italic; font-size:13px;">No customer looked up yet.</p>
                            </div>
                        </div>

                        <!-- In-Transit Status Card -->
                        <div class="section-box" id="inTransitBox" style="display:none;">
                            <div class="section-title"><span class="dot" style="background:#fd7e14"></span> In-Transit Delivery</div>
                            <div id="inTransitDetails" class="intransit-banner">
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: History Tables (Deliveries and Tickets) -->
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <!-- Delivery History -->
                        <div class="section-box">
                            <div class="section-title"><span class="dot" style="background:#28a745"></span> Delivery History</div>
                            <div style="overflow-x:auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Date</th>
                                            <th>Address</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="lookupDeliveryBody">
                                        <tr><td colspan="4" style="text-align:center; color:#aaa; padding:12px;">No deliveries found.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Ticket History -->
                        <div class="section-box">
                            <div class="section-title"><span class="dot" style="background:#dc3545"></span> Past Tickets / Complaints</div>
                            <div style="overflow-x:auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Issue Type</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Description</th>
                                            <th>SMS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="lookupTicketsBody">
                                        <tr><td colspan="6" style="text-align:center; color:#aaa; padding:12px;">No tickets found.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scheduled Callbacks Tab -->
        <div class="tab-panel" id="tabCallbacks" style="display:none">
            <div class="section-box" style="padding: 16px;">
                <div class="section-title">
                    <span class="dot" style="background:#ffc107"></span> My Scheduled Callbacks
                    <button class="btn-filter" onclick="openCallbackModal()" style="margin-left:auto; font-size:11px; padding:4px 10px;">+ New Callback</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Scheduled Time</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Notes / Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="callbacksHistoryBody">
                            <tr><td colspan="6" class="rec-empty">No upcoming callbacks scheduled.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- New Ticket Tab -->
        <div class="tab-panel" id="tabEscalation" style="display:none">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Ticket Form -->
                <div class="section-box" style="padding: 16px;">
                    <div class="section-title"><span class="dot" style="background:#dc3545"></span> New Ticket</div>
                    <form id="escalationForm" onsubmit="submitCase(event)" style="display:flex; flex-direction:column; gap:12px;">
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Customer Name</label>
                            <input type="text" id="caseCustomerName" required placeholder="e.g. Abebe Girma" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Customer Phone</label>
                            <input type="text" id="caseCustomerPhone" required placeholder="e.g. +251911000001" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Order / Delivery ID</label>
                            <input type="text" id="caseOrderId" placeholder="e.g. ORD-123456" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Issue Type</label>
                            <select id="caseIssueType" onchange="autoAssignDepartment(this.value)" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; background:#fff;">
                                <option value="Not delivered">Not delivered</option>
                                <option value="Wrong item">Wrong item</option>
                                <option value="Damaged package">Damaged package</option>
                                <option value="Late delivery">Late delivery</option>
                                <option value="Billing issue">Billing issue</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Priority</label>
                            <select id="casePriority" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; background:#fff;">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Delivery Date</label>
                            <input type="date" id="caseDeliveryDate" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Department</label>
                            <select id="caseDepartment" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; background:#fff;">
                                <option value="Logistics">Logistics</option>
                                <option value="Warehouse">Warehouse</option>
                                <option value="Billing">Billing</option>
                                <option value="Customer Relations">Customer Relations</option>
                            </select>
                        </div>
                        <div class="form-group-escalation">
                            <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Description / Notes</label>
                            <textarea id="caseDescription" rows="3" placeholder="Details about the complaint..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; font-family:inherit; resize:vertical;"></textarea>
                        </div>
                        <button type="submit" class="btn-filter" style="width:100%; padding:10px; font-weight:bold; cursor:pointer;">Submit Ticket</button>
                    </form>
                </div>
                <!-- My Submitted Tickets Log -->
                <div class="section-box" style="padding: 16px;">
                    <div class="section-title"><span class="dot" style="background:#0047AB"></span> My Submitted Tickets</div>
                    <div class="date-filter">
                        <label>From:</label>
                        <input type="date" id="caseFilterFrom" value="<?php echo $today; ?>">
                        <label>To:</label>
                        <input type="date" id="caseFilterTo" value="<?php echo $today; ?>">
                        <button class="btn-filter" onclick="fetchCases()">Filter</button>
                        <span id="caseCount" style="font-size:12px;color:#aaa;margin-left:4px;"></span>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="data-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Order ID</th>
                                    <th>Issue</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Dept</th>
                                    <th>Agent</th>
                                    <th>SMS</th>
                                </tr>
                            </thead>
                            <tbody id="caseHistoryBody">
                                <tr><td colspan="10" class="rec-empty">No tickets found.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

<div class="footer">
    SkyKin Technologies &copy; <?php echo date('Y'); ?> | Agent Dashboard v2.0 |
    Auto-refresh: <span id="refreshCountdown">10</span>s &nbsp;|&nbsp;
    <a href="/app/agent_dashboard/tickets.php"    style="color:#888;text-decoration:none;font-weight:bold;color:#0047AB;">Department Tickets</a> &nbsp;|&nbsp;
    <a href="/app/agent_dashboard/supervisor.php" style="color:#888;text-decoration:none">Supervisor</a> &nbsp;|&nbsp;
    <a href="/app/agent_dashboard/reports.php"    style="color:#888;text-decoration:none">Reports</a> &nbsp;|&nbsp;
    <a href="/app/agent_dashboard/evaluation.php" style="color:#888;text-decoration:none">Evaluation</a>
</div>

<!-- legacy hidden overlay (kept for compatibility, not shown) -->
<div id="incomingOverlay" style="display:none">
    <div></div>
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
        <!-- Incoming call screen (shown instead of dialpad when call arrives) -->
        <div id="incomingScreen" style="display:none; text-align:center; padding:24px 16px;">
            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">&#128222; Incoming Call</div>
            <div id="incomingNumber" style="font-size:28px;font-weight:bold;color:#0047AB;margin-bottom:8px">Unknown</div>
            <div style="font-size:12px;color:#666;margin-bottom:24px" id="incomingCidName"></div>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="answerCall()" style="background:#28a745;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Answer</button>
                <button onclick="declineCall()" style="background:#dc3545;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Decline</button>
            </div>
        </div>
        <div id="callTimer" class="call-timer">00:00</div>
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
            <button class="btn-hold"   id="btnPhoneSms" onclick="openSmsModalFromPhone()" style="display:none; background:#0ea5e9; color:white; grid-column:span 2; margin-top:4px;">Send SMS</button>
            <button class="btn-hold"   id="btnPhoneCallback" onclick="openCallbackModalFromPhone()" style="display:none; background:#ffc107; color:#333; grid-column:span 2; margin-top:4px;">Schedule Callback</button>
            <button class="btn-transfer" id="btnTransfer" onclick="openTransferModal()">&#x21AA; Transfer Call</button>
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

// Auto-configure SIP from server on every page load ? no manual setup needed
if (serverExt)  localStorage.setItem('sip_ext',  serverExt);
if (serverPass) localStorage.setItem('sip_pass', serverPass);
localStorage.setItem('sip_server', location.hostname);
localStorage.removeItem('sip_port');
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

// Maps our dashboard status keys → FusionPBX v_call_center_agents.agent_status values
// These are the exact strings FreeSWITCH Call Center module reads
const FPBX_STATUS_MAP = {
    ready:  'Available',
    idle:   'Available',
    break:  'On Break',
    acw:    'On Break',   // Block new calls during After-Call Work
    logout: 'Logged Out',
    incall: 'Available',  // Call center handles in-call state internally
};

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

    // ── Sync to FusionPBX v_call_center_agents + ESL ──────────────────────
    const agentExt   = localStorage.getItem('sip_ext') || serverExt || '';
    const fpbxStatus = FPBX_STATUS_MAP[key] || 'Available';
    if (!agentExt) {
        console.warn('[Status] Cannot sync to FusionPBX: no extension configured.');
        return;
    }
    fetch('index.php?action=set_agent_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            agent_ext:  agentExt,
            new_status: fpbxStatus,
            domain:     domain
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            showToast('\u26A0 FusionPBX status update failed: ' + (data.error || 'unknown error'));
            console.error('[Status] Error:', data);
            return;
        }
        // DB was updated successfully
        console.log('[Status] DB updated \u2713 agent:', data.agent_name, '| uuid:', data.agent_uuid, '| status:', data.status);
        if (data.esl_connected) {
            // ESL command was sent — this is the real live FreeSWITCH update
            console.log('[Status] ESL \u2713 response:', data.esl_response || '(ok)', '| state:', data.esl_state_resp || '(n/a)');
            // Only show a toast if something looks wrong in the ESL response
            if (data.esl_response && data.esl_response.toLowerCase().includes('err')) {
                showToast('\u26A0 ESL command sent but FreeSWITCH returned: ' + data.esl_response);
            }
        } else if (data.esl_error) {
            // ESL unreachable — DB is updated but FreeSWITCH live state unchanged until next reload
            console.warn('[Status] ESL not reached (expected in local dev):', data.esl_error);
            showToast('\u26A0 DB updated to "' + fpbxStatus + '" but FreeSWITCH ESL unreachable. ' +
                      'Add ESL_HOST to .env to enable live updates, or deploy to VM.');
        }
    })
    .catch(() => {
        showToast('\u26A0 Could not reach local server to sync status to FusionPBX.');
    });
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.status-drop-wrap')) {
        document.getElementById('statusDropMenu').classList.remove('open');
    }
});

// ?? Tabs ???????????????????????????????????????????
function switchTab(tab) {
    ['dashboard','callHistory','recordings','acw','escalation','lookup','callbacks'].forEach(t => {
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
    if (tab === 'escalation') fetchCases();
    if (tab === 'callbacks')  fetchCallbacks();
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
        .then(data => {
            updateDashboard(data);
            if (typeof fetchCallbacks === 'function') fetchCallbacks();
        })
        .catch(() => {
            updateDashboard(getEmptyData());
            if (typeof fetchCallbacks === 'function') fetchCallbacks();
        });
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
        .catch(() => updateRecordings([]));
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

function setTxt(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function updateDashboard(d) {
    setTxt('totalCalls', d.total_calls || 0);
    setTxt('avgDuration', formatDurationHMS(d.avg_duration || 0));
    setTxt('totalTalk', formatDuration(d.total_talk || 0));
    setTxt('idleTime', formatDuration(d.idle_duration || 0));
    setTxt('missedCalls', d.missed_calls || 0);
    setTxt('transfers', d.transfers || 0);
    setTxt('holdTimes', formatDuration(d.hold_times || 0));

    setTxt('listeningDuration', formatDuration(d.listening_duration || 0));
    setTxt('internalCallTime', formatDuration(d.internal_call_time || 0));
    setTxt('outboundTime', formatDuration(d.outbound_time || 0));
    setTxt('hookOnTimes', d.hook_on_times || 0);
    setTxt('totalDuration', formatDuration(d.total_duration || 0));
    setTxt('acwDuration', formatDuration(d.acw_duration || 0));
    setTxt('ivrTransfer', d.ivr_transfer || 0);

    setTxt('busyDuration', formatDuration(d.busy_duration || 0));
    setTxt('restDuration', formatDuration(d.rest_duration || 0));
    setTxt('overRest', formatDurationHMS(d.over_rest || 0));
    setTxt('interceptions', d.interceptions || 0);
    setTxt('internalHelp', d.internal_help || 0);
    setTxt('loginCount', (d.login_count || 1) + ' / 0');
    setTxt('forceSignout', d.force_signout || 0);

    setTxt('listeningCount', d.listening_count || 0);
    setTxt('thirdPartyCount', d.third_party_count || 0);
    setTxt('forceAdvisorCount', d.force_advisor_count || 0);
    setTxt('handleOnBehalf', d.handle_on_behalf || 0);
    setTxt('askHelpCount', d.ask_help_count || 0);
    setTxt('callReasonCount', d.call_reason_count || 0);
    setTxt('forwardingTimes', d.forwarding_times || 0);

    setTxt('callsWaiting', d.queue_waiting || 0);
    setTxt('agentsOnline', d.agents_online || 0);
    setTxt('avgWait', (d.avg_wait || 0) + 's');
    setTxt('slaRate', (d.sla_rate || 0) + '%');

    const answerRate = d.total_calls > 0 ? Math.round((d.answered_calls / d.total_calls) * 100) : 0;
    const answerRateEl = document.getElementById('answerRate');
    if (answerRateEl) answerRateEl.textContent = answerRate + '%';
    const answerRateBarEl = document.getElementById('answerRateBar');
    if (answerRateBarEl) answerRateBarEl.style.width = answerRate + '%';

    const talkTotal  = (d.total_talk||0) + (d.idle_duration||0);
    const talkRatio  = talkTotal > 0 ? Math.round(((d.total_talk||0)/talkTotal)*100) : 0;
    const talkRatioEl = document.getElementById('talkRatio');
    if (talkRatioEl) talkRatioEl.textContent = talkRatio + '%';
    const talkRatioBarEl = document.getElementById('talkRatioBar');
    if (talkRatioBarEl) talkRatioBarEl.style.width = talkRatio + '%';

    const talkTargetRate = Math.min(100, Math.round(((d.total_calls||0)/30)*100));
    const targetRateEl = document.getElementById('targetRate');
    if (targetRateEl) targetRateEl.textContent = talkTargetRate + '%';
    const targetRateBarEl = document.getElementById('targetRateBar');
    if (targetRateBarEl) targetRateBarEl.style.width = talkTargetRate + '%';

    // ── Dynamic update of Agent Config elements ──
    const timing = d.agent_timing;
    if (timing) {
        document.getElementById('timingContainer').style.display = 'block';
        document.getElementById('timingNoRecord').style.display = 'none';
        
        document.getElementById('valCallTimeout').textContent = timing.agent_call_timeout !== null ? timing.agent_call_timeout + 's' : '--';
        document.getElementById('valNoAnswerDelay').textContent = timing.agent_no_answer_delay_time !== null ? timing.agent_no_answer_delay_time + 's' : '--';
        document.getElementById('valWrapUpTime').textContent = timing.agent_wrap_up_time !== null ? timing.agent_wrap_up_time + 's' : '--';
        document.getElementById('valRejectDelay').textContent = timing.agent_reject_delay_time !== null ? timing.agent_reject_delay_time + 's' : '--';
        document.getElementById('valBusyDelay').textContent = timing.agent_busy_delay_time !== null ? timing.agent_busy_delay_time + 's' : '--';
    } else {
        document.getElementById('timingContainer').style.display = 'none';
        document.getElementById('timingNoRecord').style.display = 'flex';
    }

    const queues = d.agent_queues || [];
    const queuesContainer = document.getElementById('queuesContainer');
    const queuesNoRecord = document.getElementById('queuesNoRecord');
    const tierContainer = document.getElementById('tierContainer');
    const tierNoRecord = document.getElementById('tierNoRecord');
    
    queuesContainer.innerHTML = '';
    
    if (queues.length > 0) {
        queuesNoRecord.style.display = 'none';
        tierNoRecord.style.display = 'none';
        tierContainer.style.display = 'block';
        
        queues.forEach(q => {
            const badge = document.createElement('div');
            badge.className = 'queue-badge';
            badge.textContent = q.queue_name;
            if (q.queue_extension) {
                const extSpan = document.createElement('span');
                extSpan.className = 'qext';
                extSpan.textContent = ` (${q.queue_extension})`;
                badge.appendChild(extSpan);
            }
            queuesContainer.appendChild(badge);
            queuesContainer.appendChild(document.createElement('br'));
        });
        
        const levels = queues.map(q => parseInt(q.tier_level) || 0);
        const minTier = Math.min(...levels);
        const bestQueue = queues.find(q => (parseInt(q.tier_level) || 0) === minTier);
        
        document.getElementById('valTierBig').textContent = minTier;
        document.getElementById('valTierPos').textContent = 'Position: ' + (bestQueue ? bestQueue.tier_position : '0');
        
        const breakdown = document.getElementById('tierBreakdown');
        breakdown.innerHTML = '';
        if (queues.length > 1) {
            queues.forEach(q => {
                const row = document.createElement('div');
                row.className = 'config-row';
                row.innerHTML = `<span class="config-label" style="font-size:11px;">${q.queue_name}</span>` +
                                `<span class="config-val" style="font-size:11px;">L${q.tier_level} / P${q.tier_position}</span>`;
                breakdown.appendChild(row);
            });
        }
    } else {
        queuesNoRecord.style.display = 'flex';
        tierNoRecord.style.display = 'flex';
        tierContainer.style.display = 'none';
    }

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

// On HTTPS the socket is routed through the nginx /wss/ proxy on the page's own
// port, so it reuses the certificate the browser already accepted for the
// dashboard. FreeSWITCH's self-signed cert on 7443 has no SAN, and a browser
// cannot prompt to accept a cert for a WebSocket, so it aborts with code 1006.
function buildSipWsUrl(host, port) {
    const cleanHost = String(host || location.hostname)
        .replace(/^wss?:\/\//i, '').replace(/\/.*$/, '').replace(/:\d+$/, '');
    if (location.protocol === 'https:') {
        return 'wss://' + cleanHost + (location.port ? ':' + location.port : '') + '/wss/';
    }
    return 'ws://' + cleanHost + ':' + (port || '5066');
}

function loadSipSettings() {
    const ext  = localStorage.getItem('sip_ext')  || serverExt  || '';
    const pass = localStorage.getItem('sip_pass') || serverPass || '';
    const dom  = localStorage.getItem('sip_domain') || '<?php echo $domain; ?>';
    
    // Retrieve stored server or default to hostname
    const rawServer = localStorage.getItem('sip_server') || location.hostname;
    const cleanHost = rawServer.replace(/^wss?:\/\//i,'').replace(/\/.*$/,'').replace(/:\d+$/,'');
    
    const isHttps = location.protocol === 'https:';
    const port    = localStorage.getItem('sip_port') || '5066';
    const wsUrl   = buildSipWsUrl(cleanHost, port);

    document.getElementById('sipExt').value    = ext;
    document.getElementById('sipPass').value   = pass;
    document.getElementById('sipServer').value = cleanHost;
    document.getElementById('sipPort').value   = isHttps ? (location.port || '443') : port;
    document.getElementById('sipDomain').value = dom;
    
    if (ext && pass) waitForSipBridge(() => initSIP(ext, pass, wsUrl, '', dom));
}

function waitForSipBridge(cb, tries) {
    tries = tries || 0;
    if (sipBridge.init) { cb(); return; }
    if (tries < 50) setTimeout(() => waitForSipBridge(cb, tries + 1), 200);
    else setSipStatus('failed', 'SIP module failed to load');
}

function saveSipSettings() {
    const ext  = document.getElementById('sipExt').value.trim();
    const pass = document.getElementById('sipPass').value.trim();
    const dom  = document.getElementById('sipDomain').value.trim();
    if (!ext || !pass) { alert('Please enter extension and password'); return; }
    const rawServer = document.getElementById('sipServer').value.trim() || '<?php echo $_SERVER["HTTP_HOST"]; ?>';
    const cleanHost = rawServer.replace(/^wss?:\/\//i,'').replace(/:\d+$/,'');
    const isHttps   = location.protocol === 'https:';
    // The port field only applies to plain ws; HTTPS always goes via the /wss/ proxy
    const port      = isHttps ? '' : (document.getElementById('sipPort').value.trim() || '5066');
    const wsUrl     = buildSipWsUrl(cleanHost, port);
    localStorage.setItem('sip_ext',    ext);
    localStorage.setItem('sip_pass',   pass);
    localStorage.setItem('sip_server', cleanHost);
    if (port) localStorage.setItem('sip_port', port); else localStorage.removeItem('sip_port');
    localStorage.setItem('sip_domain', dom);
    document.getElementById('settingsModal').classList.remove('show');
    waitForSipBridge(() => initSIP(ext, pass, wsUrl, port, dom));
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
        // Show hang-up button so caller can cancel before answer
        document.getElementById('btnCall').style.display   = 'none';
        document.getElementById('btnHangup').style.display = 'block';
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
    // Show incoming screen inside the phone panel, hide dial pad
    document.getElementById('incomingScreen').style.display = 'block';
    document.getElementById('dpPanel').style.display        = 'none';
    openPhonePopup();
    setSipStatus('ringing', 'Ringing: ' + callerNumber);
    startRingtone();
}

function answerCall() {
    document.getElementById('incomingOverlay').style.display = 'none';
    try {
        referenceTab = window.open('about:blank', '_blank');
    } catch(e) {
        console.error("Popup blocked or failed to open:", e);
    }
    if (sipBridge.answer) sipBridge.answer();
    openCrmPanel(); // open ahununu.com inside dashboard
}

function declineCall() {
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display        = '';
    stopRingtone();
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

// ?? Ringtone (Web Audio API ? no file needed) ??????????????????????????????
let _ringCtx = null, _ringNode = null, _ringInterval = null;
function startRingtone() {
    stopRingtone();
    function _ring() {
        try {
            _ringCtx = new (window.AudioContext || window.webkitAudioContext)();
            // Two short beeps: ring pattern
            [0, 0.15].forEach(offset => {
                const o = _ringCtx.createOscillator();
                const g = _ringCtx.createGain();
                o.connect(g); g.connect(_ringCtx.destination);
                o.type = 'sine'; o.frequency.value = 440;
                g.gain.setValueAtTime(0.4, _ringCtx.currentTime + offset);
                g.gain.exponentialRampToValueAtTime(0.001, _ringCtx.currentTime + offset + 0.12);
                o.start(_ringCtx.currentTime + offset);
                o.stop(_ringCtx.currentTime + offset + 0.13);
            });
        } catch(e) {}
    }
    _ring();
    _ringInterval = setInterval(_ring, 2500);
}
function stopRingtone() {
    if (_ringInterval) { clearInterval(_ringInterval); _ringInterval = null; }
    if (_ringCtx) { try { _ringCtx.close(); } catch(e) {} _ringCtx = null; }
}

function startCallUI(number) {
    window._callEnded = false; // Reset so endCall() works for this new call
    stopRingtone();
    setSipStatus('incall', 'In Call: ' + number);
    document.getElementById('btnCall').style.display   = 'none';
    document.getElementById('btnHangup').style.display = 'block';
    document.getElementById('btnHold').style.display   = 'block';
    document.getElementById('btnMute').style.display   = 'block';
    document.getElementById('btnRecord').classList.add('visible');
    document.getElementById('callTimer').style.display = 'block';
    document.getElementById('dialInput').value = number;
    // Hide incoming screen if still showing (edge case)
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = '';
    callStartTime = new Date();
    callTimerInterval = setInterval(updateCallTimer, 1000);
    
    // Show Call popup actions
    if (document.getElementById('btnPhoneSms')) document.getElementById('btnPhoneSms').style.display = 'block';
    if (document.getElementById('btnPhoneCallback')) document.getElementById('btnPhoneCallback').style.display = 'block';
    const btnTransfer = document.getElementById('btnTransfer');
    if (btnTransfer) { btnTransfer.style.display = 'block'; btnTransfer.classList.add('visible'); }
    
    // Auto-trigger customer/order lookup and switch tab
    if (number) {
        performLookup(number);
        switchTab('lookup');
    }
}

function updateCallTimer() {
    if (!callStartTime) return;
    const elapsed = Math.floor((new Date() - callStartTime) / 1000);
    document.getElementById('callTimer').textContent =
        String(Math.floor(elapsed/60)).padStart(2,'0') + ':' + String(elapsed%60).padStart(2,'0');
}

function hangupCall() {
    if (sipBridge.hangup) sipBridge.hangup();
    // Immediately reset the UI — do not wait for the async SIP Terminated event
    endCall();
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
    // Guard: prevent double-firing (hangupCall + SIP Terminated both call endCall)
    if (window._callEnded) return;
    window._callEnded = true;

    try { stopRingtone(); } catch(e) {}
    // closeCrmPanel may not always be defined — guard it
    if (typeof closeCrmPanel === 'function') { try { closeCrmPanel(); } catch(e) {} }

    const callDur  = callStartTime ? Math.floor((new Date() - callStartTime) / 1000) : 0;
    const callerNum = lastDialedNumber || document.getElementById('dialInput').value || '';
    const recFile   = (window.recordingCallId || '') ? window.recordingCallId + '.webm' : 'demo_recording.wav';

    // ── Critical UI reset (must always run) ────────────────────────────────
    try {
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
        document.getElementById('incomingScreen').style.display = 'none';
        document.getElementById('dpPanel').style.display = '';
    } catch(e) { console.error('endCall UI reset error:', e); }

    // ── Secondary UI (optional elements) ───────────────────────────────────
    try {
        if (document.getElementById('btnPhoneSms'))      document.getElementById('btnPhoneSms').style.display      = 'none';
        if (document.getElementById('btnPhoneCallback')) document.getElementById('btnPhoneCallback').style.display = 'none';
        const btnTransferEnd = document.getElementById('btnTransfer');
        if (btnTransferEnd) { btnTransferEnd.style.display = 'none'; btnTransferEnd.classList.remove('visible'); }
    } catch(e) {}

    try { setAgentStatus('acw'); } catch(e) {}

    // Small delay so recording upload completes before ACW modal opens
    setTimeout(() => { try { openAcwModal(callerNum, callDur, lastCallType, recFile); } catch(e) {} }, 800);
    setTimeout(() => { try { fetchData(); startCountdown(); } catch(e) {} }, 4000);
    setTimeout(() => { try { fetchData(); } catch(e) {} }, 8000);

    // Reset guard after a safe window (5 s) so future calls work normally
    setTimeout(() => { window._callEnded = false; }, 5000);
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

// ── Case Escalation Actions ────────────────────────────────────────────────
let referenceTab = null;

function autoAssignDepartment(issueType) {
    const mapping = {
        'Not delivered': 'Logistics',
        'Late delivery': 'Logistics',
        'Wrong item': 'Warehouse',
        'Damaged package': 'Warehouse',
        'Billing issue': 'Billing',
        'Other': 'Customer Relations'
    };
    const dept = mapping[issueType] || 'Customer Relations';
    const deptSelect = document.getElementById('caseDepartment');
    if (deptSelect) {
        deptSelect.value = dept;
    }
}

function autofillEscalationForm(phone) {
    if (!phone) return;
    document.getElementById('caseCustomerPhone').value = phone;
    document.getElementById('caseCustomerName').value = 'Loading...';
    
    fetch('crm.php?api=lookup&phone=' + encodeURIComponent(phone))
        .then(res => res.json())
        .then(data => {
            if (data && data.full_name) {
                document.getElementById('caseCustomerName').value = data.full_name;
            } else {
                document.getElementById('caseCustomerName').value = 'Customer (' + phone + ')';
            }
        })
        .catch(() => {
            document.getElementById('caseCustomerName').value = 'Customer (' + phone + ')';
        });
}

function escalateFromAcw() {
    const notes = document.getElementById('acwNotes').value.trim();
    
    // Copy data to escalation form
    document.getElementById('caseDescription').value = notes;
    document.getElementById('caseDeliveryDate').value = new Date().toISOString().slice(0,10);
    autofillEscalationForm(acwCallerId);
    
    // Submit ACW and close it
    submitAcw();
    
    // Switch to Escalation Tab
    switchTab('escalation');
}

function fetchCases() {
    const from = document.getElementById('caseFilterFrom').value;
    const to   = document.getElementById('caseFilterTo').value;
    const ext  = localStorage.getItem('sip_ext') || serverExt || '';
    
    fetch('index.php?action=case_history&from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to)+'&agent_id='+encodeURIComponent(ext))
        .then(r => r.json())
        .then(d => {
            const rows = d.records || [];
            document.getElementById('caseCount').textContent = rows.length + ' record(s)';
            if (!rows.length) {
                document.getElementById('caseHistoryBody').innerHTML =
                    '<tr><td colspan="10" class="rec-empty">No tickets found for this date range.</td></tr>';
                return;
            }
            const statusColors = {
                'Open': 'background: #fef3c7; color: #d97706;',
                'Received': 'background: #fef3c7; color: #d97706;',
                'In Progress': 'background: #e0f2fe; color: #0284c7;',
                'Resolved': 'background: #d1fae5; color: #059669;'
            };
            const priorityColors = {
                'High': 'background: #fee2e2; color: #dc2626;',
                'Medium': 'background: #ffedd5; color: #ea580c;',
                'Low': 'background: #f3f4f6; color: #4b5563;'
            };
            document.getElementById('caseHistoryBody').innerHTML = rows.map(r => {
                const sStyle = statusColors[r.status] || 'background: #f3f4f6; color: #4b5563;';
                const pStyle = priorityColors[r.priority] || 'background: #f3f4f6; color: #4b5563;';
                return `
                <tr>
                    <td>${r.formatted_date}</td>
                    <td>${r.customer_name}</td>
                    <td>${r.customer_phone}</td>
                    <td>${r.order_id||'-'}</td>
                    <td><span class="badge badge-missed">${r.issue_type}</span></td>
                    <td><span class="badge" style="${pStyle}">${r.priority || 'Medium'}</span></td>
                    <td><span class="badge" style="${sStyle}">${r.status || 'Open'}</span></td>
                    <td><span class="badge badge-transfer">${r.department}</span></td>
                    <td>${r.agent_id}</td>
                    <td><button class="btn-action-sms" onclick="openSmsModal('${r.customer_phone}')">SMS</button></td>
                </tr>`;
            }).join('');
        }).catch(() => {
            document.getElementById('caseHistoryBody').innerHTML =
                '<tr><td colspan="10" class="rec-empty">Error loading ticket history.</td></tr>';
        });
}

function submitCase(event) {
    event.preventDefault();
    const customer_name = document.getElementById('caseCustomerName').value.trim();
    const customer_phone = document.getElementById('caseCustomerPhone').value.trim();
    const order_id = document.getElementById('caseOrderId').value.trim();
    const issue_type = document.getElementById('caseIssueType').value;
    const priority = document.getElementById('casePriority').value;
    const delivery_date = document.getElementById('caseDeliveryDate').value;
    const department = document.getElementById('caseDepartment').value;
    const description = document.getElementById('caseDescription').value.trim();
    const agent_id = localStorage.getItem('sip_ext') || serverExt || '101';
    
    fetch('index.php?action=save_case', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            customer_name, customer_phone, order_id,
            issue_type, priority, delivery_date, department,
            description, agent_id
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.saved) {
            showToast('Ticket successfully submitted to ' + department);
            document.getElementById('escalationForm').reset();
            if (document.getElementById('caseIssueType')) {
                autoAssignDepartment(document.getElementById('caseIssueType').value);
            }
            // Reset delivery date to today (local date)
            const td = localDateStr();
            document.getElementById('caseDeliveryDate').value = td;
            // Pin the history filter to today so the new ticket is visible immediately
            document.getElementById('caseFilterFrom').value = td;
            document.getElementById('caseFilterTo').value   = td;
            fetchCases();
        } else {
            alert('Failed to save ticket: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Error saving ticket: ' + err.message);
    });
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

// ── CUSTOMER LOOKUP FLOW ────────────────────────────────────────────────────
function performLookup(query) {
    if (!query) return;
    document.getElementById('lookupQuery').value = query;
    
    // Set loading states
    document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#666; font-size:13px;">Loading profile...</p>';
    document.getElementById('lookupDeliveryBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#aaa; padding:12px;">Loading deliveries...</td></tr>';
    document.getElementById('lookupTicketsBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:12px;">Loading tickets...</td></tr>';
    document.getElementById('inTransitBox').style.display = 'none';

    fetch('index.php?action=lookup_customer&query=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                showToast('Error during lookup: ' + data.error);
                return;
            }
            
            // 1. Render Contact Profile
            const contact = data.contact;
            if (contact) {
                document.getElementById('lookupProfileBox').innerHTML = `
                    <div class="profile-title">${contact.full_name || 'Unknown Customer'}</div>
                    <div class="profile-item"><span>Phone:</span><span>${contact.phone || '-'}</span></div>
                    <div class="profile-item"><span>Alt Phone:</span><span>${contact.alt_phone || '-'}</span></div>
                    <div class="profile-item"><span>Email:</span><span>${contact.email || '-'}</span></div>
                    <div class="profile-item"><span>Company:</span><span>${contact.company || '-'}</span></div>
                    <div class="profile-item"><span>Language:</span><span>${contact.language || 'English'}</span></div>
                    <div class="profile-item"><span>Account Type:</span><span>${contact.account_type || 'Customer'}</span></div>
                    <div style="font-size:12px; margin-top:8px; color:#555; line-height:1.4;"><strong>Notes:</strong><br>${contact.notes || 'None'}</div>
                    <div style="display:flex; gap: 8px; margin-top: 12px;">
                        <button class="btn-filter" onclick="openSmsModal('${contact.phone}')" style="flex:1; padding: 6px; font-size:11px;">SMS Update</button>
                        <button class="btn-filter" onclick="openCallbackModal('${contact.phone}', '${contact.full_name}')" style="flex:1; padding: 6px; font-size:11px; background:#ffc107; color:#333;">Schedule Callback</button>
                    </div>
                `;
            } else {
                document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#888; font-style:italic; font-size:13px;">No customer profiles found.</p>';
            }

            // 2. Render In-Transit Delivery banner
            const inTransit = data.current_intransit;
            if (inTransit) {
                document.getElementById('inTransitBox').style.display = 'block';
                document.getElementById('inTransitDetails').innerHTML = `
                    <div><strong>Order ID:</strong> ${inTransit.order_id}</div>
                    <div><strong>Address:</strong> ${inTransit.delivery_address}</div>
                    <div><strong>Delivery Date:</strong> ${inTransit.delivery_date}</div>
                    <div><strong>Status:</strong> <span class="badge" style="background:#ffedd5; color:#ea580c; font-weight:bold;">${inTransit.status}</span></div>
                `;
            } else {
                document.getElementById('inTransitBox').style.display = 'none';
            }

            // 3. Render Delivery History
            const dels = data.deliveries || [];
            if (dels.length > 0) {
                document.getElementById('lookupDeliveryBody').innerHTML = dels.map(d => {
                    const statusStyle = d.status === 'Delivered' 
                        ? 'background:#d1fae5; color:#059669;' 
                        : (d.status === 'In Transit' || d.status === 'Pending' ? 'background:#ffedd5; color:#ea580c;' : 'background:#f3f4f6; color:#4b5563;');
                    return `
                        <tr>
                            <td><strong style="color:#0047AB; cursor:pointer;" onclick="performLookup('${d.order_id}')">${d.order_id}</strong></td>
                            <td>${d.delivery_date}</td>
                            <td>${d.delivery_address || '-'}</td>
                            <td><span class="badge" style="${statusStyle}">${d.status}</span></td>
                        </tr>
                    `;
                }).join('');
            } else {
                document.getElementById('lookupDeliveryBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#aaa; padding:12px;">No delivery history.</td></tr>';
            }

            // 4. Render Tickets
            const tix = data.tickets || [];
            if (tix.length > 0) {
                const statusColors = {
                    'Open': 'background: #fef3c7; color: #d97706;',
                    'Received': 'background: #fef3c7; color: #d97706;',
                    'In Progress': 'background: #e0f2fe; color: #0284c7;',
                    'Resolved': 'background: #d1fae5; color: #059669;'
                };
                const priorityColors = {
                    'High': 'background: #fee2e2; color: #dc2626;',
                    'Medium': 'background: #ffedd5; color: #ea580c;',
                    'Low': 'background: #f3f4f6; color: #4b5563;'
                };
                document.getElementById('lookupTicketsBody').innerHTML = tix.map(t => {
                    const sStyle = statusColors[t.status] || 'background: #f3f4f6; color: #4b5563;';
                    const pStyle = priorityColors[t.priority] || 'background: #f3f4f6; color: #4b5563;';
                    return `
                        <tr>
                            <td>${t.formatted_date}</td>
                            <td><span class="badge badge-missed">${t.issue_type}</span></td>
                            <td><span class="badge" style="${pStyle}">${t.priority}</span></td>
                            <td><span class="badge" style="${sStyle}">${t.status}</span></td>
                            <td style="max-width:200px; white-space:normal; font-size:12px; color:#555;">${t.description || '-'}</td>
                            <td><button class="btn-action-sms" onclick="openSmsModal('${t.customer_phone}')">SMS</button></td>
                        </tr>
                    `;
                }).join('');
            } else {
                document.getElementById('lookupTicketsBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:12px;">No tickets found.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#ef4444; font-size:13px;">Error loading profile.</p>';
            document.getElementById('lookupDeliveryBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#ef4444; padding:12px;">Error loading deliveries.</td></tr>';
            document.getElementById('lookupTicketsBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#ef4444; padding:12px;">Error loading tickets.</td></tr>';
        });
}

function clearLookup() {
    document.getElementById('lookupQuery').value = '';
    document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#888; font-style:italic; font-size:13px;">No customer looked up yet.</p>';
    document.getElementById('lookupDeliveryBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#aaa; padding:12px;">No deliveries found.</td></tr>';
    document.getElementById('lookupTicketsBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:12px;">No tickets found.</td></tr>';
    document.getElementById('inTransitBox').style.display = 'none';
}

// ── CALLBACK SCHEDULING FLOW ────────────────────────────────────────────────
function openCallbackModal(phone, name) {
    document.getElementById('cbPhone').value = phone || '';
    document.getElementById('cbName').value = name || '';
    
    // Set default callback date to today, time to +1hr
    const now = new Date();
    document.getElementById('cbDate').value = now.toISOString().slice(0,10);
    
    now.setHours(now.getHours() + 1);
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('cbTime').value = `${h}:${m}`;
    document.getElementById('cbNotes').value = '';
    
    document.getElementById('callbackModal').classList.add('show');
}

function closeCallbackModal() {
    document.getElementById('callbackModal').classList.remove('show');
}

function openCallbackModalFromPhone() {
    const num = document.getElementById('dialInput').value.trim();
    openCallbackModal(num, '');
}

function openCallbackModalFromAcw() {
    openCallbackModal(acwCallerId, '');
}

function submitCallback() {
    const customer_name = document.getElementById('cbName').value.trim();
    const customer_phone = document.getElementById('cbPhone').value.trim();
    const cbDate = document.getElementById('cbDate').value;
    const cbTime = document.getElementById('cbTime').value;
    const notes = document.getElementById('cbNotes').value.trim();
    const agent_id = localStorage.getItem('sip_ext') || serverExt || '101';
    
    if (!customer_phone || !cbDate || !cbTime) {
        alert('Phone number, date, and time are required.');
        return;
    }
    
    const callback_time = `${cbDate} ${cbTime}:00`;
    
    fetch('index.php?action=save_callback', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            customer_name, customer_phone, callback_time, notes, agent_id
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast('Callback scheduled successfully.');
            closeCallbackModal();
            fetchCallbacks();
        } else {
            alert('Failed to schedule callback: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => alert('Error saving callback: ' + err.message));
}

function fetchCallbacks() {
    const ext = localStorage.getItem('sip_ext') || serverExt || '101';
    fetch('index.php?action=list_callbacks&agent_id=' + encodeURIComponent(ext))
        .then(r => r.json())
        .then(data => {
            const list = data.records || [];
            if (list.length === 0) {
                document.getElementById('callbacksHistoryBody').innerHTML = 
                    '<tr><td colspan="6" class="rec-empty">No upcoming callbacks scheduled.</td></tr>';
                return;
            }
            
            // Sort by callback time ascending just in case, soonest first
            list.sort((a,b) => new Date(a.formatted_time) - new Date(b.formatted_time));
            
            document.getElementById('callbacksHistoryBody').innerHTML = list.map((c, idx) => {
                // Highlight the soonest callback (first item in sorted array)
                const urgentClass = (idx === 0) ? 'class="callback-urgent"' : '';
                return `
                    <tr ${urgentClass}>
                        <td>${c.formatted_time}</td>
                        <td>${c.customer_name || 'Unknown'}</td>
                        <td>${c.customer_phone}</td>
                        <td style="max-width:200px; white-space:normal; font-size:12px; color:#555;">${c.notes || '-'}</td>
                        <td><span class="badge" style="background:#ffedd5; color:#ea580c; font-weight:bold;">${c.status}</span></td>
                        <td>
                            <button class="btn-action-resolve" onclick="completeCallback(${c.callback_id})">Complete</button>
                        </td>
                    </tr>
                `;
            }).join('');
        })
        .catch(() => {
            document.getElementById('callbacksHistoryBody').innerHTML = 
                '<tr><td colspan="6" class="rec-empty">Error loading callbacks.</td></tr>';
        });
}

function completeCallback(id) {
    if (!confirm('Mark this callback as completed?')) return;
    
    fetch('index.php?action=update_callback_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ callback_id: id, status: 'Completed' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast('Callback completed.');
            fetchCallbacks();
        } else {
            alert('Error updating callback: ' + (data.error || 'Unknown'));
        }
    })
    .catch(err => alert('Error completing callback: ' + err.message));
}

// ── SMS NOTIFICATION FLOW ───────────────────────────────────────────────────
function openSmsModal(phone) {
    document.getElementById('smsPhone').value = phone || '';
    document.getElementById('smsTemplate').value = '';
    document.getElementById('smsMessage').value = '';
    document.getElementById('smsModal').classList.add('show');
}

function closeSmsModal() {
    document.getElementById('smsModal').classList.remove('show');
}

function openSmsModalFromPhone() {
    const num = document.getElementById('dialInput').value.trim();
    openSmsModal(num);
}

function openSmsModalFromAcw() {
    openSmsModal(acwCallerId);
}

function applySmsTemplate(val) {
    const textarea = document.getElementById('smsMessage');
    if (val === 'delivery_delay') {
        textarea.value = "We're looking into your issue and will update you within 24 hours.";
    } else if (val === 'replacement_date') {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const dateStr = tomorrow.toISOString().slice(0, 10);
        textarea.value = `Your replacement package will arrive by ${dateStr}.`;
    } else if (val === 'resolved') {
        textarea.value = "Your ticket has been resolved. Thank you for your patience!";
    } else {
        textarea.value = "";
    }
}

function submitSms() {
    const phone = document.getElementById('smsPhone').value.trim();
    const message = document.getElementById('smsMessage').value.trim();
    
    if (!phone || !message) {
        alert('Phone number and message are required.');
        return;
    }
    
    fetch('index.php?action=send_sms', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ phone, message })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast('SMS notification logged successfully.');
            closeSmsModal();
        } else {
            alert('Failed to send SMS: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => alert('Error sending SMS: ' + err.message));
}

// ── CALL TRANSFER FLOW ───────────────────────────────────────────────────────
function openTransferModal() {
    const modal = document.getElementById('transferModal');
    if (!modal) return;
    modal.classList.add('show');
    document.getElementById('transferExtInput').value = '';
    loadAvailableAgents();
}

function closeTransferModal() {
    const modal = document.getElementById('transferModal');
    if (modal) modal.classList.remove('show');
}

function loadAvailableAgents() {
    const myExt = localStorage.getItem('sip_ext') || serverExt || '';
    const list  = document.getElementById('transferAgentsList');
    list.innerHTML = '<div class="transfer-loading">Loading available agents...</div>';

    fetch('index.php?action=get_available_agents&domain=' + encodeURIComponent(domain) + '&my_ext=' + encodeURIComponent(myExt))
        .then(r => r.json())
        .then(data => {
            const agents = data.agents || [];
            if (!agents.length) {
                list.innerHTML = '<div class="transfer-loading">No available agents found at this time.</div>';
                return;
            }
            list.innerHTML = agents.map(a => `
                <div class="transfer-agent-item" onclick="executeTransfer('${a.extension}', '${a.name.replace(/'/g,'\\u0027')}')">
                    <div class="transfer-agent-info">
                        <span class="transfer-agent-name">${a.name}</span>
                        <span class="transfer-agent-ext">Ext. ${a.extension}</span>
                    </div>
                    <span class="transfer-agent-badge">Available</span>
                </div>
            `).join('');
        })
        .catch(() => {
            list.innerHTML = '<div class="transfer-loading" style="color:#ef4444;">Failed to load agents. Check server connection.</div>';
        });
}

function executeTransfer(ext, name) {
    if (!ext) return;
    const displayName = name ? name + ' (Ext. ' + ext + ')' : 'Ext. ' + ext;
    if (!confirm('Transfer current call to ' + displayName + '?')) return;
    closeTransferModal();
    if (window.sipBridge && window.sipBridge.transfer) {
        window.sipBridge.transfer(ext);
    } else {
        showToast('Transfer not available: SIP softphone not connected.');
    }
}

function executeManualTransfer() {
    const ext = document.getElementById('transferExtInput').value.trim();
    if (!ext) { alert('Please enter an extension number to transfer to.'); return; }
    if (!/^\d{2,6}$/.test(ext)) { alert('Extension must be a 2-6 digit number.'); return; }
    executeTransfer(ext, '');
}

// Init
setInterval(updateClock, 1000);
updateClock();
fetchData();
startCountdown();

// ── Ticket form date defaults (local date, not UTC) ──────────────────────
function localDateStr() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
const todayStr = localDateStr();
if (document.getElementById('caseFilterFrom')) document.getElementById('caseFilterFrom').value = todayStr;
if (document.getElementById('caseFilterTo'))   document.getElementById('caseFilterTo').value   = todayStr;
if (document.getElementById('caseDeliveryDate')) document.getElementById('caseDeliveryDate').value = todayStr;
if (document.getElementById('caseIssueType')) {
    autoAssignDepartment(document.getElementById('caseIssueType').value);
}
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

<!-- Scheduling Callback Modal -->
<div id="callbackModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-hdr">
            <h3>Schedule Callback</h3>
            <button class="close-btn" onclick="closeCallbackModal()">&times;</button>
        </div>
        <div class="custom-modal-body">
            <div class="form-group">
                <label>Customer Phone *</label>
                <input type="text" id="cbPhone" required placeholder="e.g. +251911000001">
            </div>
            <div class="form-group">
                <label>Customer Name</label>
                <input type="text" id="cbName" placeholder="e.g. Abebe Girma">
            </div>
            <div class="form-group">
                <label>Callback Date *</label>
                <input type="date" id="cbDate" required>
            </div>
            <div class="form-group">
                <label>Callback Time *</label>
                <input type="time" id="cbTime" required>
            </div>
            <div class="form-group">
                <label>Reason / Note</label>
                <textarea id="cbNotes" rows="3" placeholder="Why is the callback needed?"></textarea>
            </div>
            <button class="btn-filter" onclick="submitCallback()" style="width:100%; padding:10px; font-weight:bold; margin-top:10px;">Schedule</button>
        </div>
    </div>
</div>

<!-- Send SMS Modal -->
<div id="smsModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-hdr">
            <h3>Send SMS Update</h3>
            <button class="close-btn" onclick="closeSmsModal()">&times;</button>
        </div>
        <div class="custom-modal-body">
            <div class="form-group">
                <label>Phone Number *</label>
                <input type="text" id="smsPhone" required placeholder="e.g. +251911000001">
            </div>
            <div class="form-group">
                <label>Select Template</label>
                <select id="smsTemplate" onchange="applySmsTemplate(this.value)" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; background:#fff;">
                    <option value="">-- Custom Message --</option>
                    <option value="delivery_delay">We're looking into your issue and will update you within 24 hours.</option>
                    <option value="replacement_date">Your replacement package will arrive by [date].</option>
                    <option value="resolved">Your ticket has been resolved. Thank you for your patience!</option>
                </select>
            </div>
            <div class="form-group">
                <label>Message *</label>
                <textarea id="smsMessage" rows="4" required placeholder="Type custom message..."></textarea>
            </div>
            <button class="btn-filter" onclick="submitSms()" style="width:100%; padding:10px; font-weight:bold; margin-top:10px;">Send SMS</button>
        </div>
    </div>
</div>

<!-- Transfer Call Modal -->
<div id="transferModal" class="transfer-overlay">
    <div class="transfer-modal">
        <div class="transfer-hdr">
            <h3>&#x21AA; Transfer Call</h3>
            <button onclick="closeTransferModal()">&#x2715;</button>
        </div>
        <div class="transfer-body">
            <!-- Manual extension entry -->
            <div>
                <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase; display:block; margin-bottom:6px;">Transfer to Extension</label>
                <div class="transfer-ext-row">
                    <input type="tel" id="transferExtInput" placeholder="e.g. 102" maxlength="6"
                           onkeypress="if(event.key==='Enter') executeManualTransfer()">
                    <button onclick="executeManualTransfer()">Transfer</button>
                </div>
            </div>
            <!-- Available agents list -->
            <div style="border-top:1px solid #f0f0f0; padding-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label style="font-size:11px; font-weight:700; color:#555; text-transform:uppercase;">Available Agents</label>
                    <button onclick="loadAvailableAgents()" style="font-size:10px; background:none; border:1px solid #ddd; border-radius:4px; padding:2px 8px; cursor:pointer; color:#555;">&#x21BB; Refresh</button>
                </div>
                <div class="transfer-agents-list" id="transferAgentsList">
                    <div class="transfer-loading">Loading...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div id="sysToast"></div>

<!-- Remote audio for WebRTC calls -->
<audio id="remoteAudio" autoplay style="display:none"></audio>

<!-- CRM slide-in panel -->
<div class="crm-panel" id="crmPanel">
    <div class="crm-panel-header">
        <span>&#128100; Customer Info — ahununu.com</span>
        <button onclick="closeCrmPanel()">&#10005; Close</button>
    </div>
    <iframe id="crmFrame" src="about:blank" allow="camera;microphone"></iframe>
</div>

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
        const ext = localStorage.getItem('sip_ext') || '';
        const dom = localStorage.getItem('sip_domain') || '<?php echo $domain; ?>';
        // Upload to FastAPI then link to FusionPBX CDR for Evaluation badge
        try {
            const resp = await fetch('http://192.168.243.129:8001/api/recordings/upload?call_id=' + encodeURIComponent(id), { method:'POST', body:fd });
            if (resp.ok) {
                // Link recording filename to CDR so ?? badge shows in Evaluation
                fetch('index.php?action=link_recording&filename=' + encodeURIComponent(id+'.webm') + '&ext=' + encodeURIComponent(ext) + '&domain=' + encodeURIComponent(dom));
            }
        } catch(e) {}
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
            
            // Auto-open reference site on answered incoming call
            if (s instanceof Invitation) {
                if (referenceTab && !referenceTab.closed) {
                    referenceTab.location.href = 'https://ahununu.com/';
                } else {
                    try {
                        window.open('https://ahununu.com/', '_blank');
                    } catch(e) {
                        console.error("Failed to open reference tab:", e);
                    }
                }
                referenceTab = null;
            }
            
            // Pre-fill escalation form with the caller's details
            autofillEscalationForm(num);
        }
        if (state === SessionState.Terminated || state === SessionState.Terminating) {
            stopRec();
            if (referenceTab) {
                try {
                    if (referenceTab.location.href === 'about:blank' || referenceTab.location.href === '') {
                        referenceTab.close();
                    }
                } catch(e) {}
                referenceTab = null;
            }
            if (window.endCall) window.endCall();
        }
    });
}

window.sipBridge.init = function(ext, pass, server, port, dom) {
    if (ua) { try { reg?.unregister(); ua.stop(); } catch(e) {} }

    // Build WebSocket URI properly
    let wsUri = server;
    if (!wsUri.startsWith('wss://') && !wsUri.startsWith('ws://')) {
        const isHttps = location.protocol === 'https:';
        wsUri = (isHttps ? 'wss://' : 'ws://') + wsUri;
    }
    
    // If the WebSocket URI does not specify a port or sub-path, add one.
    // HTTPS gets the nginx /wss/ proxy path so the socket shares the page cert.
    const hostPart = wsUri.replace(/^wss?:\/\//i, '');
    if (!hostPart.includes('/') && !hostPart.includes(':')) {
        wsUri = location.protocol === 'https:'
            ? wsUri + '/wss/'
            : wsUri + ':' + (port || '5066');
    }

    ua = new UserAgent({
        uri: UserAgent.makeURI('sip:' + ext + '@' + dom),
        transportOptions: {
            server: wsUri
        },
        authorizationUsername: ext,
        authorizationPassword: pass
    });

    reg = new Registerer(ua);
    reg.stateChange.addListener(state => {
        if (state === 'Registered') {
            window.setSipStatus('registered', 'Registered (' + ext + ')');
        } else if (state === 'Unregistered') {
            window.setSipStatus('unregistered', 'Not Registered');
        } else if (state === 'Terminated') {
            window.setSipStatus('failed', 'Registration Failed');
        }
    });

    ua.delegate = {
        onInvite(inv) {
            session = inv;
            // Try multiple places in SIP.js to get the caller number
            const num = inv.remoteIdentity?.uri?.user
                || inv.remoteIdentity?.displayName
                || inv.request?.from?.uri?.user
                || inv.request?.getHeader?.('P-Asserted-Identity')?.match(/sip:(\+?[\d]+)@/)?.[1]
                || inv.request?.getHeader?.('From')?.match(/sip:(\+?[\d]+)@/)?.[1]
                || inv.request?.getHeader?.('From')?.match(/"?([^"<]+)"?\s*</)?.[1]
                || 'Unknown';
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

// ── Blind Transfer via SIP REFER ─────────────────────────────────────────────
// FreeSWITCH receives the REFER, bridges the customer to the new extension,
// then sends BYE to us. The customer ends up talking to the new agent.
window.sipBridge.transfer = function(targetExt) {
    if (!session) {
        window.showToast && window.showToast('No active call to transfer.');
        return;
    }
    const dom = pbxDomain();
    const targetURI = UserAgent.makeURI('sip:' + targetExt + '@' + dom);
    if (!targetURI) {
        window.showToast && window.showToast('Invalid transfer target: ' + targetExt);
        return;
    }
    // session.refer() sends SIP REFER — blind transfer (RFC 3515)
    session.refer(targetURI)
        .then(() => {
            window.showToast && window.showToast('\u2713 Call transferred to ext. ' + targetExt + '. Waiting for FreeSWITCH to complete...');
            // FreeSWITCH will send BYE after bridging; endCall() fires via stateChange listener.
            // Proactively null session so we don't double-hang-up.
            session = null;
        })
        .catch(err => {
            window.showToast && window.showToast('Transfer failed: ' + err.message);
        });
};
})();

loadSipSettings();
</script>
</body>
</html>
