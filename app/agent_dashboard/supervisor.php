<?php
// SkyKin Technologies – Supervisor Dashboard
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

// API endpoints must return JSON on expiry/forbidden (not an HTML login redirect)
$is_api = isset($_GET['action']);
skykin_require_groups(['superadmin', 'admin', 'supervisor'], $is_api);

// Release session lock early so Live Status polls do not block other tabs
session_write_close();

// ── Pull identity from session ────────────────────────────────────────────────
$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = skykin_default_domain();

$domain  = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$sup_ext = isset($_GET['ext'])    ? htmlspecialchars($_GET['ext'])     : '';

// Auto-detect supervisor extension + SIP password for softphone registration
$sup_password = '';
try {
    $db_se = getDB();
    if ($logged_in_user) {
        $se = $db_se->prepare("
            SELECT e.extension, e.password FROM v_extensions e
            JOIN v_extension_users eu ON eu.extension_uuid=e.extension_uuid
            JOIN v_users u ON u.user_uuid=eu.user_uuid
            JOIN v_domains d ON d.domain_uuid=e.domain_uuid
            WHERE u.username=:u AND d.domain_name=:d LIMIT 1");
        $se->execute([':u'=>$logged_in_user,':d'=>$domain]);
        $row = $se->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!$sup_ext) $sup_ext = $row['extension'];
            $sup_password = (string)($row['password'] ?? '');
        }
    }
    if ($sup_ext && $sup_password === '') {
        $sp = $db_se->prepare("
            SELECT e.password FROM v_extensions e
            JOIN v_domains d ON d.domain_uuid=e.domain_uuid
            WHERE e.extension=:e AND d.domain_name=:d LIMIT 1");
        $sp->execute([':e'=>$sup_ext, ':d'=>$domain]);
        $sup_password = (string)($sp->fetchColumn() ?: '');
    }
} catch (Exception $ignored) {}
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
    $db = skykin_pdo_fusionpbx();
    if (!$db) {
        throw new Exception('Database unavailable');
    }
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

    $esl_connected = false;
    $esl_response = '';
    $esl_error = '';
    $esl = skykin_esl($esl_error);
    if ($esl) {
        $esl_connected = true;
        $res = $esl->request('api callcenter_config agent set status ' . $agent_uuid . " '" . $new_status . "'");
        $esl_response = is_array($res) ? ($res['$'] ?? implode(' | ', $res)) : (string)$res;
        if ($new_status === 'Available' || $new_status === 'Logged Out') {
            $esl->request('api callcenter_config agent set state ' . $agent_uuid . " 'Waiting'");
        }
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
    $domain = skykin_domain_param($_GET['domain'] ?? null);
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

        // Per-extension CDR stats (same attribution as agent dashboard — includes inbound).
        $stats = [];
        foreach ($exts as $e) {
            $stats[$e['extension']] = skykin_cdr_agent_stats(
                $db, $domain, (string)$e['extension'], $today_start, $today_end
            );
        }

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
            $fs_agents = skykin_fs_api('callcenter_config agent list');
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

        // SIP registrations come from FreeSWITCH, not PostgreSQL.
        $registered = [];
        try {
            $registered = skykin_fs_registrations($domain);
        } catch(Exception $ignored){}

        // ── Active calls from FreeSWITCH live channels ───────────────────────
        // CDR only written after call ends, so use fs_cli show channels instead
        $activeCalls = [];
        try {
            $ch_out = skykin_fs_api('show channels as json');
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
            // Not SIP-registered → Offline (ignore stale CC "Available").
            // Registered + CC Available / Waiting → Ready
            // Active channel → In Call
            $status = 'offline';
            $cc_label = $cc['agent_status'] ?? 'Unknown';
            $s_map = [
                'Available' => 'ready', 'Logged Out' => 'offline', 'On Break' => 'break',
                'In Queue Call' => 'incall', 'On Call' => 'incall',
                'Idle' => 'ready', 'Waiting' => 'ready', 'Receiving' => 'incall',
                'In a queue call' => 'incall',
            ];
            $is_reg = isset($registered[$ext]);

            if ($ac) {
                $status = 'incall';
            } elseif (!$is_reg) {
                $status = 'offline';
            } elseif ($fsCc) {
                $fs_status = trim((string)($fsCc['status'] ?? ''));
                $fs_state  = trim((string)($fsCc['state'] ?? ''));
                if ($fs_status !== '') $cc_label = $fs_status;
                if (stripos($fs_state, 'In a queue call') !== false || stripos($fs_state, 'Receiving') !== false) {
                    $status = 'incall';
                } elseif ($fs_status !== '') {
                    $status = $s_map[$fs_status] ?? strtolower(str_replace(' ', '_', $fs_status));
                } else {
                    $status = 'ready';
                }
            } elseif ($cc) {
                $status = $s_map[$cc['agent_status']] ?? strtolower(str_replace(' ', '_', $cc['agent_status']));
            } else {
                $status = 'ready';
                $cc_label = 'Registered';
            }

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
    $domain = skykin_domain_param($_GET['domain'] ?? null);
    $today_start = strtotime(date('Y-m-d').' 00:00:00');
    $today_end   = strtotime(date('Y-m-d').' 23:59:59');
    try {
        $db = getDB();
        $queues = [];
        $waiting_callers = [];
        try {
            // FusionPBX uses v_call_center_queues (not v_call_queues). Waiting
            // callers live in FreeSWITCH mod_callcenter, not a Postgres table.
            $s = $db->prepare(
                "SELECT q.queue_name AS name, q.queue_extension AS extension
                 FROM v_call_center_queues q
                 JOIN v_domains d ON d.domain_uuid = q.domain_uuid
                 WHERE d.domain_name = :d
                 ORDER BY q.queue_extension, q.queue_name"
            );
            $s->execute([':d' => $domain]);
            $queue_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $queue_exts = [];
            foreach ($queue_rows as $qr) {
                $qext = trim((string)($qr['extension'] ?? ''));
                if ($qext !== '') {
                    $queue_exts[] = $qext;
                }
            }
            $waiting_callers = skykin_cc_waiting_callers($domain, $queue_exts);
            $by_queue = [];
            foreach ($waiting_callers as $c) {
                $qe = (string)($c['queue'] ?? '');
                $by_queue[$qe] = ($by_queue[$qe] ?? 0) + 1;
            }
            foreach ($queue_rows as $qr) {
                $qext = (string)($qr['extension'] ?? '');
                $queues[] = [
                    'name' => (string)($qr['name'] ?? ''),
                    'extension' => $qext,
                    'waiting' => (int)($by_queue[$qext] ?? 0),
                ];
            }
        } catch(Exception $ignored){}

        // Today totals (hunt legs collapsed to one missed per caller attempt)
        $today_rows = skykin_cdr_fetch_period($db, $domain, $today_start, $today_end);
        $tm = skykin_cdr_period_metrics($today_rows);
        $total    = (int)($tm['total'] ?? 0);
        $answered = (int)($tm['answered'] ?? 0);
        $missed   = (int)($tm['missed'] ?? 0);
        $sla      = $total>0 ? min(100,round(($answered/$total)*95)) : 100;

        // Agents online — SIP registrations, which only FreeSWITCH knows about.
        $online_count = 0;
        try {
            $online_count = count(skykin_fs_registrations($domain));
        } catch(Exception $ignored){}
        $online = ['cnt' => $online_count];

        echo json_encode([
            'queues'           => $queues,
            'waiting_callers'  => $waiting_callers ?? [],
            'total_today'      => $total,
            'answered_today'   => $answered,
            'missed_today'     => $missed,
            'avg_talk'         => (int)($tm['avg_dur'] ?? 0),
            'avg_wait'         => 0,
            'sla'              => $sla,
            'agents_online'    => (int)($online['cnt']??0),
        ]);
    } catch(Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: leaderboard ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='leaderboard') {
    error_reporting(0); header('Content-Type: application/json');
    $domain = skykin_domain_param($_GET['domain'] ?? null);
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        $sn = $db->prepare("SELECT e.extension, e.effective_caller_id_name
            FROM v_extensions e JOIN v_domains d ON d.domain_uuid=e.domain_uuid
            WHERE d.domain_name=:d ORDER BY e.extension");
        $sn->execute([':d'=>$domain]);
        $ext_rows = $sn->fetchAll(PDO::FETCH_ASSOC);

        $rows = [];
        foreach ($ext_rows as $er) {
            $st = skykin_cdr_agent_stats($db, $domain, (string)$er['extension'], $ts, $te);
            $answered = (int)($st['answered'] ?? 0);
            if ($answered < 1 && (int)($st['total'] ?? 0) < 1) {
                continue;
            }
            $rows[] = [
                'ext' => $er['extension'],
                'name' => $er['effective_caller_id_name'] ?: ('Ext ' . $er['extension']),
                'total' => (int)($st['total'] ?? 0),
                'answered' => $answered,
                'missed' => (int)($st['missed'] ?? 0),
                'total_talk' => (int)($st['total_talk'] ?? 0),
                'avg_dur' => (int)($st['avg_dur'] ?? 0),
                'max_dur' => (int)($st['max_dur'] ?? 0),
            ];
        }
        usort($rows, static function ($a, $b) {
            return $b['answered'] <=> $a['answered'];
        });
        $rows = array_slice($rows, 0, 20);
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
        $s = $db->prepare("SELECT " . skykin_db_time_sql('YYYY-MM-DD HH24:MI', 'created_at') . " as created_at,
            agent_id,caller_id,call_type,duration,disposition,call_reason,notes
            FROM skykin_acw WHERE DATE(created_at)>=:f AND DATE(created_at)<=:t
            ORDER BY created_at DESC LIMIT 200");
        $s->execute([':f'=>$from,':t'=>$to]);
        echo json_encode(['records'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { echo json_encode(['records'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: monitor (eavesdrop) ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='monitor') {
    error_reporting(0); header('Content-Type: application/json');
    $mode      = $_GET['mode']      ?? 'listen';
    $agent_ext = preg_replace('/[^0-9]/','',$_GET['agent_ext'] ?? '');
    $sup_ext_  = preg_replace('/[^0-9]/','',$_GET['sup_ext']   ?? '');
    $domain_   = preg_replace('/[^a-zA-Z0-9.\-]/','',skykin_domain_param($_GET['domain'] ?? null));

    if (!$agent_ext || !$sup_ext_) { echo json_encode(['ok'=>false,'error'=>'Missing extension']); exit; }

    // eavesdrop() spies on one specific channel, so it needs that channel's uuid;
    // handing it an extension number just fails. Find the agent's live channel.
    $target_uuid = '';
    $chans = json_decode(skykin_fs_api('show channels as json'), true);
    foreach (($chans['rows'] ?? []) as $ch) {
        $name = (string)($ch['name'] ?? '');
        $pres = (string)($ch['presence_id'] ?? '');
        $cid  = (string)($ch['cid_num'] ?? '');
        $hit  = preg_match('#/(?:sip:)?' . preg_quote($agent_ext, '#') . '@#i', $name)
             || strpos($pres, $agent_ext . '@') === 0
             || $cid === $agent_ext;
        if ($hit && !empty($ch['uuid'])) {
            $target_uuid = (string)$ch['uuid'];
            break;
        }
    }
    if ($target_uuid === '') {
        echo json_encode(['ok'=>false,'error'=>'Agent ' . $agent_ext . ' is not on a call right now']);
        exit;
    }

    // listen  = hear both legs, stay muted (the eavesdrop default)
    // whisper = supervisor is audible to the agent only  (ED_MUX_READ)
    // barge   = supervisor is audible to both parties    (three-way)
    $vars = ['eavesdrop_enable_dtmf=true'];
    if ($mode === 'whisper') {
        $vars[] = 'eavesdrop_whisper_aleg=true';
    } elseif ($mode === 'barge') {
        $vars[] = 'eavesdrop_whisper_aleg=true';
        $vars[] = 'eavesdrop_whisper_bleg=true';
    }
    $vars[] = 'origination_caller_id_name=Monitor ' . $agent_ext;

    // Dial the supervisor through user/ so FreeSWITCH resolves the registered
    // contact from the directory. sofia/internal/<ext>@<domain> instead treats the
    // SIP domain as a hostname to route to, which never reaches a WebRTC client.
    $cmd = 'originate {' . implode(',', $vars) . '}user/' . $sup_ext_ . '@' . $domain_
         . ' &eavesdrop(' . $target_uuid . ')';
    $res = trim(skykin_fs_api($cmd));

    if (stripos($res, '+OK') !== false) {
        echo json_encode(['ok'=>true,'result'=>$res]);
    } else {
        // USER_NOT_REGISTERED is the common case: the supervisor softphone must be
        // connected, because the monitored audio is delivered to it as a call.
        $err = $res !== '' ? $res : 'Could not reach FreeSWITCH';
        if (stripos($err, 'USER_NOT_REGISTERED') !== false) {
            $err = 'Your softphone (ext ' . $sup_ext_ . ') is not registered — connect the phone first';
        }
        echo json_encode(['ok'=>false,'error'=>$err]);
    }
    exit;
}

// ── API: force_status ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='force_status') {
    error_reporting(0); header('Content-Type: application/json');
    $agent_ext = $_GET['agent_ext'] ?? '';
    $new_status= $_GET['status']    ?? 'Available';
    $domain_   = skykin_domain_param($_GET['domain'] ?? null);
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
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
    try {
        $db = getDB();
        ensureLeaveRequestsTable($db);
        $s = $db->prepare("SELECT id, agent_ext, agent_name, request_type, reason, status,
            " . skykin_db_time_sql('YYYY-MM-DD HH24:MI', 'requested_at') . " as requested_at
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
    $domain_ = trim($body['domain'] ?? skykin_domain_param($_GET['domain'] ?? null));
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
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
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
        if ($search) { $where.=" AND (caller_id_number LIKE :q OR destination_number LIKE :q OR caller_destination LIKE :q OR last_arg LIKE :q)"; $params[':q']='%'.$search.'%'; }
        $s = $db->prepare("SELECT start_epoch,
            " . skykin_cdr_time_sql('YYYY-MM-DD HH24:MI') . " as call_time,
            caller_id_number, destination_number, caller_destination, direction, billsec, duration,
            hangup_cause, last_arg, cc_agent, cc_agent_bridged
            FROM v_xml_cdr WHERE $where ORDER BY start_epoch DESC LIMIT 1500");
        $s->execute($params);
        $raw = skykin_cdr_collapse_hunt_legs($s->fetchAll(PDO::FETCH_ASSOC));
        $raw = array_slice($raw, 0, 500);
        $busy_token_map = [];
        try {
            $bx = $db->prepare("SELECT extension FROM v_extensions e JOIN v_domains d ON d.domain_uuid=e.domain_uuid WHERE d.domain_name=:d ORDER BY extension");
            $bx->execute([':d' => $domain_]);
            $busy_token_map = skykin_fs_sip_contact_tokens(
                $domain_,
                array_map(static fn($r) => (string)$r['extension'], $bx->fetchAll(PDO::FETCH_ASSOC))
            );
        } catch (Exception $ignore) {}
        $rows = [];
        $agent_label = [];
        try {
            $sa = $db->prepare("SELECT agent_name, agent_id, agent_contact FROM v_call_center_agents ca
                JOIN v_domains d ON d.domain_uuid=ca.domain_uuid WHERE d.domain_name=:d");
            $sa->execute([':d'=>$domain_]);
            foreach ($sa->fetchAll(PDO::FETCH_ASSOC) as $ag) {
                $lab = trim((string)($ag['agent_name'] ?? ''));
                if ($ag['agent_id'] !== '' && $ag['agent_id'] !== null) {
                    $agent_label[(string)$ag['agent_id']] = $lab !== '' ? $lab : (string)$ag['agent_id'];
                }
                if (preg_match('/(?:user\/)?(\d+)@/i', (string)($ag['agent_contact'] ?? ''), $m)) {
                    $agent_label[$m[1]] = $lab !== '' ? $lab.' ('.$m[1].')' : $m[1];
                }
            }
        } catch (Exception $ignore) {}
        $agent_guess = [];
        $parsed = [];
        foreach ($raw as $r) {
            $b = (int)$r['billsec'];
            $caller = preg_replace('/@.*$/', '', (string)$r['caller_id_number']);
            $dest   = trim(preg_replace('/@.*$/', '', (string)$r['destination_number']));
            $cdest  = preg_replace('/@.*$/', '', (string)($r['caller_destination'] ?? ''));
            $arg    = (string)($r['last_arg'] ?? '');
            $cc     = (string)($r['cc_agent'] ?? '');
            $uuid_map = [
                '64c5f323-cd40-48ef-a97f-22d546be8b57'=>'101',
                '031ab55a-74f4-4c4a-9252-faaa4a1f4e5e'=>'102',
            ];
            if (isset($uuid_map[strtolower($cc)])) $cc = $uuid_map[strtolower($cc)];
            if (preg_match('/(?:user\/)?(\d{2,4})@/', $cc, $m)) $cc = $m[1];
            if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $caller))
                $caller = $uname_ext[strtolower($caller)] ?? $caller;
            $dest_digits = preg_replace('/\D+/', '', $dest);
            $agent_ext = '';
            if (preg_match('/user\/(\d{2,4})@/', $arg, $m)) $agent_ext = $m[1];
            elseif (preg_match('/^1\d{2}$/', $cc)) $agent_ext = $cc;
            elseif (preg_match('/^(101|102)$/', $dest_digits)) $agent_ext = $dest_digits;
            elseif (preg_match('/^1\d{2}$/', $dest)) $agent_ext = $dest;
            elseif (preg_match('/^1\d{2}$/', $caller)) $agent_ext = $caller;
            elseif (preg_match('/(?:user\/)?(\d{2,4})@/', (string)($r['cc_agent_bridged'] ?? ''), $m)) $agent_ext = $m[1];
            if ($agent_ext === '') {
                $agent_ext = skykin_cdr_busy_row_agent_ext($r, $busy_token_map);
            }
            $ck = preg_replace('/\D+/', '', $caller);
            if (strlen($ck) >= 9) $ck = substr($ck, -9);
            $bucket = (string)intdiv((int)($r['start_epoch'] ?? 0), 60);
            if ($agent_ext !== '') {
                $didk = preg_replace('/\D+/', '', $cdest);
                if ($didk === '' && preg_match('/11113875[56]$/', $dest_digits)) $didk = $dest_digits;
                if (strlen($didk) >= 9) $didk = substr($didk, -9);
                if ($ck !== '') {
                    $agent_guess[$ck.'|'.$bucket] = $agent_ext;
                    $agent_guess[$ck.'|'.(string)($r['call_time'] ?? '')] = $agent_ext;
                }
                if ($didk !== '') {
                    $agent_guess['did|'.$didk.'|'.$bucket] = $agent_ext;
                    $agent_guess['did|'.$didk.'|'.(string)($r['call_time'] ?? '')] = $agent_ext;
                }
                $agent_guess['t|'.$bucket] = $agent_ext;
                $agent_guess['t|'.(string)($r['call_time'] ?? '')] = $agent_ext;
            }
            $parsed[] = [
                'r'=>$r,'b'=>$b,'caller'=>$caller,'dest'=>$dest,'cdest'=>$cdest,
                'agent_ext'=>$agent_ext,'dest_digits'=>$dest_digits,'ck'=>$ck,'bucket'=>$bucket,
            ];
        }
        foreach ($parsed as $p) {
            $r = $p['r'];
            $b = $p['b']; $caller = $p['caller']; $dest = $p['dest'];
            $cdest = $p['cdest']; $agent_ext = $p['agent_ext'];
            $dest_digits = $p['dest_digits'];
            if (preg_match('/^(101|102)$/', $dest_digits)) {
                $cdest_digits = preg_replace('/\D+/', '', $cdest);
                if (preg_match('/^[\+\d\(\)\-\s#\*]{3,}$/', $cdest) && !preg_match('/^(101|102)$/', $cdest_digits))
                    $dest = $cdest;
                else
                    continue;
            }
            if ($agent_ext === '') {
                $didk = $dest_digits;
                if (strlen($didk) >= 9) $didk = substr($didk, -9);
                $agent_ext = $agent_guess[$p['ck'].'|'.$p['bucket']]
                    ?? $agent_guess[$p['ck'].'|'.(string)($r['call_time'] ?? '')]
                    ?? $agent_guess['did|'.$didk.'|'.$p['bucket']]
                    ?? $agent_guess['did|'.$didk.'|'.(string)($r['call_time'] ?? '')]
                    ?? $agent_guess['t|'.$p['bucket']]
                    ?? $agent_guess['t|'.(string)($r['call_time'] ?? '')]
                    ?? '';
            }
            $dir = strtolower(trim((string)($r['direction'] ?? '')));
            $is_did = (bool)preg_match('/11113875[56]$/', $dest_digits)
                || (bool)preg_match('/11113875[56]$/', preg_replace('/\D+/', '', $cdest));
            if ($dir === '' || $dir === 'null') {
                if ($is_did || $dest === '8000') $dir = 'inbound';
                elseif (preg_match('/^1\d{2}$/', $caller) && !preg_match('/^1\d{2}$/', $dest)) $dir = 'outbound';
                else $dir = 'inbound';
            }
            $is_busy = !empty($r['_busy_rep']) || skykin_cdr_is_agent_busy_leg($r);
            if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $dest)) {
                if ($is_busy) {
                    if ($agent_ext === '') {
                        $agent_ext = skykin_cdr_busy_row_agent_ext($r, $busy_token_map);
                    }
                    $dest = $agent_ext !== '' ? ('Ext ' . $agent_ext) : 'Agent busy';
                } elseif (preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $cdest)) $dest = $cdest;
                else continue;
            }
            if (strcasecmp($dest, 'unknown') === 0) {
                if (preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $cdest)) $dest = $cdest;
                else continue;
            }
            $agent = '';
            if ($agent_ext !== '') {
                $agent = trim((string)($agent_label[$agent_ext] ?? ''));
                if ($agent === '') $agent = $agent_ext;
            }
            $rows[] = ['time'=>$r['call_time'],'caller'=>$caller !== '' ? $caller : '—',
                'destination'=>$dest,'agent'=>$agent !== '' ? $agent : '—',
                'direction'=>$dir,
                'duration'=>floor($b/60).':'.str_pad($b%60,2,'0',STR_PAD_LEFT),
                'status'=>skykin_cdr_result_label($r),
                'cause'=>$r['hangup_cause']??''];
        }
        echo json_encode(['rows'=>$rows]);
    } catch(Exception $e) { echo json_encode(['rows'=>[],'error'=>$e->getMessage()]); }
    exit;
}

// ── API: recordings_all ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action']==='recordings_all') {
    error_reporting(0); header('Content-Type: application/json');
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
    $from    = $_GET['from']   ?? date('Y-m-d');
    $to      = $_GET['to']     ?? date('Y-m-d');
    $search  = $_GET['search'] ?? '';
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        skykin_link_archive_recordings($db, $domain_, $ts, $te);
        $where  = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te AND (record_path IS NOT NULL OR record_name IS NOT NULL)";
        $params = [':d'=>$domain_,':ts'=>$ts,':te'=>$te];
        if ($search) { $where.=" AND (caller_id_number LIKE :q OR destination_number LIKE :q)"; $params[':q']='%'.$search.'%'; }
        $s = $db->prepare("SELECT " . skykin_cdr_time_sql('YYYY-MM-DD HH24:MI') . " as call_time,
            caller_id_number, destination_number, direction, billsec,
            record_path, record_name, hangup_cause
            FROM v_xml_cdr WHERE $where ORDER BY start_epoch DESC LIMIT 300");
        $s->execute($params);
        $rows = [];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = (int)$r['billsec'];
            $file = trim($r['record_name'] ?? '');
            $path = trim($r['record_path'] ?? '');
            $play_url = '';
            if ($file) {
                $play_url = '/app/agent_dashboard/play_recording.php?f='.rawurlencode($file)
                    .'&d='.rawurlencode($domain_)
                    .($path !== '' ? '&path='.rawurlencode(rtrim($path,'/')) : '');
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
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
    $from    = $_GET['from']   ?? date('Y-m-d');
    $to      = $_GET['to']     ?? date('Y-m-d');
    $ts = strtotime($from.' 00:00:00');
    $te = strtotime($to.' 23:59:59');
    try {
        $db = getDB();
        $s = $db->prepare("SELECT
            " . skykin_cdr_time_sql('YYYY-MM-DD HH24:MI') . " as call_time,
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

if (isset($_GET['action']) && $_GET['action'] === 'get_settings') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'session_idle_minutes' => skykin_idle_timeout_minutes(),
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $minutes = (int) ($body['session_idle_minutes'] ?? 0);
    if ($minutes < 0) {
        $minutes = 0;
    }
    if ($minutes > 1440) {
        $minutes = 1440;
    }
    try {
        $who = (string) ($_SESSION['username'] ?? '');
        skykin_setting_set('session_idle_minutes', (string) $minutes, $who);
        echo json_encode(['ok' => true, 'session_idle_minutes' => $minutes]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php echo skykin_favicon_tag(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sky Connect Supervisor – <?php echo $domain; ?></title>
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
.wait-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f5f5f5;font-size:13px}
.wait-row:last-child{border-bottom:none}
.wait-pos{width:22px;height:22px;border-radius:50%;background:#fff4e5;color:#fd7e14;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.wait-num{font-weight:700;color:#333;flex:1}
.wait-time{color:#fd7e14;font-weight:700;font-size:12px}
.wait-state{font-size:10px;font-weight:700;color:#888;background:#f4f6f8;border-radius:10px;padding:2px 7px}
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
.badge-failed{background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-missed{background:#ffebee;color:#c62828;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-busy{background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700}
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
/* Supervisor softphone */
.phone-fab{position:fixed;bottom:28px;right:28px;z-index:600;width:58px;height:58px;border-radius:50%;
background:linear-gradient(135deg,#0047AB,#00B4D8);border:none;cursor:pointer;color:#fff;font-size:24px;
box-shadow:0 4px 20px rgba(0,71,171,.45);display:flex;align-items:center;justify-content:center;
transition:transform .2s,box-shadow .2s}
.phone-fab:hover{transform:scale(1.08)}
.phone-fab.ringing{background:linear-gradient(135deg,#28a745,#1e7e34);animation:fabPulse .6s infinite}
@keyframes fabPulse{0%,100%{box-shadow:0 4px 20px rgba(0,71,171,.45)}50%{box-shadow:0 4px 32px rgba(0,71,171,.7),0 0 0 10px rgba(0,71,171,.1)}}
.fab-badge{position:absolute;top:-2px;right:-2px;width:16px;height:16px;border-radius:50%;background:#28a745;border:2px solid #fff;display:none}
.fab-badge.show{display:block}.fab-badge.unreg{background:#888}.fab-badge.calling{background:#ffc107}
.phone-popup{position:fixed;top:60px;right:-320px;z-index:601;width:300px;max-height:calc(100vh - 60px);
background:#fff;border-left:1px solid #e0e0e0;box-shadow:-4px 0 20px rgba(0,0,0,.12);display:flex;flex-direction:column;
overflow-y:auto;transition:right .3s ease}
.phone-popup.open{right:0}
body.phone-open .main{margin-right:300px;transition:margin-right .3s ease}
.pp-header{background:linear-gradient(135deg,#0047AB,#00B4D8);color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
.pp-header-actions{display:flex;align-items:center;gap:6px}
.btn-settings-header{width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;cursor:pointer;font-size:15px}
.pp-status{display:flex;align-items:center;gap:8px;font-size:13px}
.sip-dot{width:10px;height:10px;border-radius:50%;background:#888;flex-shrink:0}
.sip-dot.registered{background:#28a745;animation:pulse 2s infinite}
.sip-dot.calling{background:#ffc107;animation:pulse .5s infinite}
.sip-dot.ringing{background:#fd7e14;animation:pulse .4s infinite}
.sip-dot.failed{background:#dc3545}.sip-dot.connecting{background:#aaa;animation:pulse 1s infinite}
.pp-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer}
.pp-body{padding:0;display:flex;flex-direction:column;gap:10px}
.phone-popup.call-active .pp-body{padding:12px 16px}
.call-controls{display:grid;grid-template-columns:repeat(12,1fr);gap:8px}
.btn-hangup{grid-column:3 / span 8;background:#c92a3d;border:1px solid #c92a3d;color:#fff;padding:11px 0;border-radius:9px;cursor:pointer;font-size:13px;font-weight:650;display:none}
.btn-hold,.btn-mute,.btn-keypad{grid-column:span 4;min-height:54px;background:#fff;border:1px solid #dfe5ec;color:#334155;padding:8px 4px;border-radius:9px;cursor:pointer;font-size:11px;font-weight:600;display:none;align-items:center;justify-content:center;flex-direction:column;gap:4px}
.btn-hold.active,.btn-mute.muted,.btn-keypad.active{background:#eef6ff;border-color:#8eb9e6;color:#0047ab}
.btn-transfer{grid-column:span 12;min-height:38px;background:#fff;border:1px solid #dfe5ec;color:#334155;border-radius:9px;padding:9px 8px;cursor:pointer;display:none;font-size:12px;font-weight:700}
.btn-transfer.visible,.btn-transfer.show-xfer{display:block}
.btn-transfer:hover{background:#eef6ff;border-color:#8eb9e6;color:#0047ab}
.transfer-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:1100;align-items:center;justify-content:center}
.transfer-overlay.show{display:flex}
.transfer-modal{background:#fff;border-radius:14px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
.transfer-hdr{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#6366f1,#4f46e5)}
.transfer-hdr h3{font-size:14px;font-weight:700;color:#fff;margin:0}
.transfer-hdr button{background:rgba(255,255,255,.2);border:none;cursor:pointer;font-size:16px;color:#fff;width:26px;height:26px;border-radius:50%}
.transfer-body{padding:16px;display:flex;flex-direction:column;gap:12px;max-height:400px;overflow-y:auto}
.transfer-ext-row{display:flex;gap:8px;align-items:center}
.transfer-ext-row input{flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-size:14px}
.transfer-ext-row button{background:#6366f1;color:#fff;border:none;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700}
.transfer-agent-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid #e9ecef;border-radius:8px;cursor:pointer}
.transfer-agent-item:hover{background:#f5f3ff;border-color:#6366f1}
.transfer-agent-name{font-size:13px;font-weight:600;color:#1e293b;display:block}
.transfer-agent-ext{font-size:11px;color:#64748b}
.transfer-agent-badge{background:#d1fae5;color:#059669;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px}
.transfer-loading{text-align:center;color:#888;font-size:13px;padding:20px}
.call-timer{text-align:center;font-size:22px;font-weight:bold;color:#0047AB;display:none;padding:4px 0}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:700;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,.2)}
.modal-title{font-size:16px;font-weight:bold;color:#0047AB;margin-bottom:20px}
.form-group{margin-bottom:14px}
.form-group label{font-size:12px;color:#666;display:block;margin-bottom:4px}
.form-group input{width:100%;border:1px solid #ddd;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box}
.btn-save-settings{background:#0047AB;color:#fff;border:none;padding:10px 24px;border-radius:6px;cursor:pointer;font-size:14px;width:100%;margin-top:8px}
.dial-input-wrap{display:flex;gap:6px;margin-bottom:8px}
.dial-input{flex:1;border:1px solid #ddd;border-radius:8px;padding:9px 12px;font-size:15px;letter-spacing:2px;outline:none;color:#0047AB;box-sizing:border-box}
.dial-input:focus{border-color:#0047AB}
.dial-input::placeholder{color:#ccc;letter-spacing:0;font-size:13px}
.btn-dialpad{background:#f0f2f5;border:1px solid #ddd;border-radius:8px;width:40px;cursor:pointer;font-size:18px;color:#555;display:flex;align-items:center;justify-content:center}
.btn-dialpad:hover{background:#e2e8f0}
.dp-panel{display:block;padding:14px 22px 22px;background:#fff}
.dp-title{margin:0 0 10px;color:#64748b;font-size:10px;font-weight:700;letter-spacing:1.2px;text-align:center;text-transform:uppercase}
.dp-display{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;font-size:20px;font-weight:650;color:#0f3f79;text-align:center;letter-spacing:2px;min-height:46px;width:100%;margin-bottom:16px;box-sizing:border-box;outline:none}
.dp-display:focus{border-color:#9dc5f0}
.dp-display::placeholder{color:#94a3b8;font-size:12px;letter-spacing:0;font-weight:500}
.dp-grid{display:grid;grid-template-columns:repeat(3,58px);gap:10px 18px;justify-content:center;margin-bottom:16px}
.dp-key{width:58px;height:58px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:50%;font-size:19px;font-weight:650;color:#172b4d;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center}
.dp-key .dp-sub{font-size:8px;color:#94a3b8;letter-spacing:.5px;margin-top:2px}
.dp-row-actions{display:flex;gap:10px;justify-content:center}
.dp-call{flex:1;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer}
.dp-del{width:52px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;cursor:pointer;font-size:18px;color:#64748b}



/* Static management sidebar on desktop; all sections live in the side menu. */
.sup-side-label{padding:8px 20px 4px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px}
.sup-side-link{
    display:flex;align-items:center;padding:10px 20px;color:#475569;text-decoration:none;
    font-size:13.5px;font-weight:500;border-left:3px solid transparent;
    transition:background .15s,border-color .15s,color .15s,box-shadow .15s
}
.sup-side-link:hover{background:#f0f5ff;border-left-color:#0047AB;color:#0047AB}
.sup-side-link.active{
    background:linear-gradient(90deg,#eef3ff 0%,#f8faff 100%);
    border-left-color:#0047AB;color:#0047AB;font-weight:700;
    box-shadow:inset 0 0 0 1px rgba(0,71,171,.08)
}
.sup-side-signout{display:flex;align-items:center;padding:10px 20px;color:#dc3545;text-decoration:none;font-size:13.5px;font-weight:500;border-left:3px solid transparent}
.sup-side-signout:hover{background:#fff5f5;border-left-color:#dc3545}
.tab-bar{display:none !important}
@media (min-width:901px) {
    #sideMenu {
        top:60px !important; left:0 !important; height:calc(100vh - 60px) !important;
        box-shadow:2px 0 10px rgba(15,23,42,.08) !important; z-index:250 !important;
    }
    #sideMenu .supervisor-sidebar-brand,
    #sideMenuBackdrop,
    .supervisor-sidebar-toggle { display:none !important; }
    .main { margin-left:250px; margin-right:0; max-width:none; }
}
@media (max-width:900px) {
    #sideMenu { top:60px !important; height:calc(100vh - 60px) !important; }
    .main { margin-left:0; }
}

</style>
</head>
<body>

<div class="header">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="supervisor-sidebar-toggle" onclick="toggleSideMenu()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;border-radius:8px;font-size:20px;cursor:pointer;line-height:1">&#9776;</button>
        <div class="logo"><span>Sky</span> Connect <span class="role-badge">SUPERVISOR</span></div>
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
    <div class="supervisor-sidebar-brand" style="background:linear-gradient(135deg,#0047AB,#00B4D8);padding:20px;color:#fff;flex-shrink:0">
        <div style="font-size:17px;font-weight:700"><span style="color:#00e5ff">Sky</span> Connect</div>
        <div style="font-size:11px;opacity:.8;margin-top:3px">Supervisor Panel</div>
    </div>
    <nav style="flex:1;padding:8px 0;overflow-y:auto">
        <div class="sup-side-label">Operations</div>
        <a href="#" class="sup-side-link active" data-side-tab="dashboard" onclick="event.preventDefault();toggleSideMenu();showTab('dashboard')">Dashboard</a>
        <a href="#" class="sup-side-link" data-side-tab="leaderboard" onclick="event.preventDefault();toggleSideMenu();showTab('leaderboard')">Leaderboard</a>
        <a href="#" class="sup-side-link" data-side-tab="callhistory" onclick="event.preventDefault();toggleSideMenu();showTab('callhistory')">All Call History</a>
        <a href="#" class="sup-side-link" data-side-tab="acwall" onclick="event.preventDefault();toggleSideMenu();showTab('acwall')">ACW Review</a>
        <a href="#" class="sup-side-link" data-side-tab="recordings" onclick="event.preventDefault();toggleSideMenu();showTab('recordings')">Call Recordings</a>
        <a href="#" class="sup-side-link" data-side-tab="voicequality" onclick="event.preventDefault();toggleSideMenu();showTab('voicequality')">Voice Quality</a>
        <a href="#" class="sup-side-link" data-side-tab="skills" onclick="event.preventDefault();toggleSideMenu();showTab('skills')">Agent Skills</a>
        <a href="#" class="sup-side-link" data-side-tab="blacklist" onclick="event.preventDefault();toggleSideMenu();showTab('blacklist')">Blacklist</a>
        <a href="#" class="sup-side-link" data-side-tab="lookup" onclick="event.preventDefault();toggleSideMenu();showTab('lookup')">Customer Lookup</a>
        <a href="#" class="sup-side-link" data-side-tab="ticket" onclick="event.preventDefault();toggleSideMenu();showTab('ticket')">New Ticket</a>
        <a href="#" class="sup-side-link" data-side-tab="callbacks" onclick="event.preventDefault();toggleSideMenu();showTab('callbacks')">Callbacks</a>
        <a href="#" class="sup-side-link" data-side-tab="ahununu" onclick="event.preventDefault();toggleSideMenu();showTab('ahununu')">Ahununu.com</a>
        <div style="height:1px;background:#eee;margin:8px 0"></div>
        <div class="sup-side-label">Management</div>
        <a href="#" class="sup-side-link" data-side-tab="reports" onclick="event.preventDefault();toggleSideMenu();showTab('reports')">Reports</a>
        <a href="#" class="sup-side-link" data-side-tab="evaluation" onclick="event.preventDefault();toggleSideMenu();showTab('evaluation')">Evaluation</a>
        <a href="#" class="sup-side-link" data-side-tab="crm" onclick="event.preventDefault();toggleSideMenu();showTab('crm')">CRM</a>
        <a href="#" class="sup-side-link" data-side-tab="settings" onclick="event.preventDefault();toggleSideMenu();showTab('settings')">Settings</a>
        <div style="height:1px;background:#eee;margin:6px 0"></div>
        <a href="/logout.php" class="sup-side-signout">Sign Out</a>
    </nav>
    <div style="padding:12px 20px;border-top:1px solid #f0f0f0;font-size:11px;color:#bbb;flex-shrink:0">Sky Connect &copy; <?php echo date('Y'); ?><br>Powered by SkyKin Technology</div>
</div>
<div id="sideMenuBackdrop" onclick="toggleSideMenu()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.25);z-index:499"></div>

<div class="main">

    <!-- Main content panels (navigation is in the left sidebar) -->
    <div class="bottom-section">

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

                <div class="live-agents-panel" id="waitingCallersPanel" style="margin-bottom:14px">
                    <div class="live-agents-header">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:#333">
                            Waiting Customers
                            <span id="waitingCallerCount" style="background:#fd7e14;color:#fff;font-size:11px;border-radius:10px;padding:1px 8px;display:none">0</span>
                        </div>
                        <span style="font-size:11px;color:#aaa">Longest wait is offered next</span>
                    </div>
                    <div id="waitingCallersList" style="padding:12px 16px;color:#aaa;font-size:13px">No callers waiting.</div>
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
                    <th>Time</th><th>Caller</th><th>Destination</th><th>Agent</th>
                    <th>Direction</th><th>Duration</th><th>Status</th><th>Cause</th>
                </tr></thead>
                <tbody id="chBody"><tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">Loading...</td></tr></tbody>
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
            <div id="recPlayerWrap" style="display:none;align-items:center;gap:8px;margin-top:12px;">
                <button onclick="stopRec()"
                    style="background:#c62828;color:#fff;border:none;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:11px;font-weight:700">
                    &#9632; Stop</button>
                <span id="recPlayingName" style="font-size:11px;color:#888;flex:1"></span>
            </div>
            <audio id="recPlayer" controls style="width:100%;margin-top:8px;display:none"></audio>
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

        <div class="tab-content" id="tab-settings" style="padding:24px;max-width:560px">
            <h4 style="margin:0 0 8px;color:#333;font-size:16px">Login session</h4>
            <p style="font-size:13px;color:#666;margin:0 0 18px;line-height:1.5">
                Agents and supervisors are logged out after this many minutes with no mouse, keyboard, or active call.
                Background dashboard refresh does not count as activity. Set <strong>0</strong> to disable auto-logout.
                Closing the browser still ends the session.
            </p>
            <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:6px">Idle logout (minutes)</label>
            <input type="number" id="idleMinutes" min="0" max="1440" step="1" value="<?php echo (int) skykin_idle_timeout_minutes(); ?>"
                   style="width:160px;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px">
            <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
                <button class="btn-save-settings" style="width:auto;margin:0;padding:10px 22px" onclick="saveIdleSettings()">Save</button>
                <span id="idleSaveMsg" style="font-size:12px;color:#64748b"></span>
            </div>
        </div>

        <div class="tab-content" id="tab-blacklist">
            <h4 style="margin:0 0 6px;color:#333;font-size:15px">Blocked callers</h4>
            <p style="font-size:12px;color:#666;margin:0 0 14px">Agents can block a caller from the phone. Those numbers never ring the queue.</p>
            <div class="date-filter" style="margin-bottom:0">
                <input type="tel" class="search-input" id="supBlNumber" placeholder="Phone number">
                <input type="text" class="search-input" id="supBlReason" placeholder="Reason">
                <button class="btn-filter" type="button" onclick="supAddBlacklist()">Block</button>
            </div>
            <table class="data-table" style="margin-top:14px">
                <thead><tr><th>Number</th><th>Reason</th><th>Agent</th><th></th></tr></thead>
                <tbody id="supBlacklistBody"><tr><td colspan="4">Loading…</td></tr></tbody>
            </table>
        </div>
        <div class="tab-content" id="tab-lookup" style="padding:0;height:700px">
            <iframe src="about:blank" id="lookupFrame" title="Customer Lookup" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-ticket" style="padding:0;height:700px">
            <iframe src="about:blank" id="ticketFrame" title="New Ticket" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-callbacks" style="padding:0;height:700px">
            <iframe src="about:blank" id="callbacksFrame" title="Callbacks" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-reports" style="padding:0;height:700px">
            <iframe src="about:blank" id="reportsFrame" title="Reports" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-crm" style="padding:0;height:700px">
            <iframe src="about:blank" id="crmFrame" title="CRM" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-evaluation" style="padding:0;height:700px">
            <iframe src="about:blank" id="evaluationFrame" title="Evaluation" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px"></iframe>
        </div>
        <div class="tab-content" id="tab-ahununu" style="padding:0;height:700px">
            <iframe src="about:blank" id="ahununuFrame" style="width:100%;height:100%;border:none;border-radius:0 0 8px 8px" allow="camera;microphone"></iframe>
        </div>

    </div>
</div>

<!-- Supervisor softphone -->
<div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
        <div class="modal-title">Supervisor Phone Settings</div>
        <div class="form-group"><label>Extension Number</label><input type="text" id="sipExt" placeholder="e.g. 200"></div>
        <div class="form-group"><label>SIP Password</label><input type="password" id="sipPass" placeholder="Extension password"></div>
        <div class="form-group"><label>SIP Server</label><input type="text" id="sipServer" value="<?php echo htmlspecialchars(skykin_config()['sip_server']); ?>"></div>
        <div class="form-group"><label>WebSocket Port</label><input type="text" id="sipPort" value="5066"></div>
        <div class="form-group"><label>Domain</label><input type="text" id="sipDomain" value="<?php echo htmlspecialchars($domain); ?>"></div>
        <button class="btn-save-settings" onclick="saveSipSettings()">Connect</button>
    </div>
</div>
<button class="phone-fab" id="phoneFab" onclick="togglePhonePopup()" title="Supervisor Phone">
    &#128222;<span class="fab-badge unreg" id="fabBadge"></span>
</button>
<div class="phone-popup" id="phonePopup">
    <div class="pp-header">
        <div class="pp-status"><div class="sip-dot" id="sipDot"></div><span id="sipStatusText">Not Connected</span></div>
        <div class="pp-header-actions">
            <button class="btn-settings-header" onclick="document.getElementById('settingsModal').classList.add('show')" title="Phone settings">&#9881;</button>
            <button class="pp-close" onclick="togglePhonePopup()">&#x2715;</button>
        </div>
    </div>
    <div class="pp-body">
        <div id="incomingScreen" style="display:none;text-align:center;padding:24px 16px">
            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Incoming / Transfer</div>
            <div id="incomingNumber" style="font-size:28px;font-weight:bold;color:#0047AB;margin-bottom:24px">Unknown</div>
            <div style="display:flex;gap:12px;justify-content:center">
                <button onclick="answerCall()" style="background:#28a745;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Answer</button>
                <button onclick="declineCall()" style="background:#dc3545;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Decline</button>
            </div>
        </div>
        <div id="callTimer" class="call-timer">00:00</div>
        <div class="call-controls">
            <button class="btn-hangup" id="btnHangup" onclick="hangupCall()">End call</button>
            <button class="btn-hold" id="btnHold" onclick="toggleHold()">Hold</button>
            <button class="btn-mute" id="btnMute" onclick="toggleMute()">Mute</button>
            <button class="btn-keypad" id="btnKeypad" onclick="toggleCallKeypad()">Keypad</button>
            <button class="btn-transfer" id="btnTransfer" onclick="openTransferModal()">Transfer call</button>
        </div>
    </div>
    <div class="dp-panel" id="dpPanel">
        <div class="dp-title">Dial number</div>
        <input type="tel" class="dp-display" id="dialInput" placeholder="Enter number..." maxlength="20" autocomplete="off" inputmode="tel">
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
            <button class="dp-call" onclick="dpCall()">&#128222;&nbsp; Call</button>
            <button class="dp-del" onclick="dpDelete()" aria-label="Delete">&#9003;</button>
        </div>
    </div>
</div>
<audio id="remoteAudio" autoplay style="display:none"></audio>

<div id="transferModal" class="transfer-overlay">
    <div class="transfer-modal">
        <div class="transfer-hdr">
            <h3>&#x21AA; Transfer Call</h3>
            <button type="button" onclick="closeTransferModal()">&#x2715;</button>
        </div>
        <div class="transfer-body">
            <div>
                <label style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase;display:block;margin-bottom:6px">Transfer to Extension</label>
                <div class="transfer-ext-row">
                    <input type="tel" id="transferExtInput" placeholder="e.g. 102" maxlength="6"
                           onkeypress="if(event.key==='Enter') executeManualTransfer()">
                    <button type="button" onclick="executeManualTransfer()">Transfer</button>
                </div>
            </div>
            <div style="border-top:1px solid #f0f0f0;padding-top:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <label style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase">Available Agents</label>
                    <button type="button" onclick="loadAvailableAgents()" style="font-size:10px;background:none;border:1px solid #ddd;border-radius:4px;padding:2px 8px;cursor:pointer;color:#555">Refresh</button>
                </div>
                <div id="transferAgentsList"><div class="transfer-loading">Loading...</div></div>
            </div>
        </div>
    </div>
</div>

<div id="supToast"></div>

<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
<script>
const domain   = '<?php echo $domain; ?>';
const supExt   = '<?php echo $sup_ext; ?>' || localStorage.getItem('sup_ext') || '';
const serverExt  = <?php echo json_encode($sup_ext); ?>;
const serverPass = <?php echo json_encode($sup_password); ?>;
if (serverExt && !localStorage.getItem('sup_ext')) localStorage.setItem('sup_ext', serverExt);
if (serverExt) localStorage.setItem('sup_sip_ext', serverExt);
if (serverPass) localStorage.setItem('sup_sip_pass', serverPass);
localStorage.setItem('sup_sip_server', (window.SKYKIN && SKYKIN.sipServer) || location.hostname);
localStorage.setItem('sup_sip_domain', domain || ((window.SKYKIN && SKYKIN.domain) || location.hostname));
const today    = '<?php echo $today; ?>';
let agentTimers = {};
window.sipBridge = {};
var sipBridge = window.sipBridge;

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
            renderWaitingCallers(d.waiting_callers||[]);
        }).catch(()=>{});
}

function renderWaitingCallers(list){
    const box = document.getElementById('waitingCallersList');
    const badge = document.getElementById('waitingCallerCount');
    if (!box) return;
    const n = (list||[]).length;
    if (badge) {
        badge.textContent = String(n);
        badge.style.display = n ? 'inline-block' : 'none';
    }
    if (!n) {
        box.innerHTML = 'No callers waiting.';
        box.style.color = '#aaa';
        return;
    }
    box.style.color = '#333';
    box.innerHTML = list.map((c,i)=>{
        const number = String(c.number||'Unknown').replace(/[&<>"']/g, ch=>({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[ch]));
        const wait = String(c.wait_fmt||((c.wait_seconds||0)+'s'));
        const state = String(c.state||'Waiting');
        return `<div class="wait-row"><span class="wait-pos">${i+1}</span><span class="wait-num">${number}</span><span class="wait-time">${wait}</span><span class="wait-state">${state}</span></div>`;
    }).join('');
}

// ── Agent Cards ────────────────────────────────────────────────────────────
function fetchAgents(){
    fetch('supervisor.php?action=agents&domain='+encodeURIComponent(domain), {credentials:'same-origin'})
        .then(r=>{
            // Do not hard-logout on a single 401 (tab switch / brief session lock).
            // Never use switch=1 here — that destroys the session server-side.
            if (r.status === 401) {
                window._authFailCount = (window._authFailCount || 0) + 1;
                if (window._authFailCount >= 4) {
                    window._authFailCount = 0;
                    window.location.href = '/login.php?expired=1';
                }
                return null;
            }
            window._authFailCount = 0;
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

function fetchSupBlacklist(){
    const body = document.getElementById('supBlacklistBody');
    if (!body) return;
    fetch('index.php?action=blacklist_list&domain=' + encodeURIComponent(domain), { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            const rows = (d && d.rows) || [];
            if (!rows.length) { body.innerHTML = '<tr><td colspan="4">No blocked numbers.</td></tr>'; return; }
            body.innerHTML = rows.map(r => '<tr><td>'+escHtml(r.display||r.digits)+'</td><td>'+escHtml(r.reason||'')+'</td><td>'+escHtml(r.agent||'')+
                '</td><td><button type="button" onclick="supDelBlacklist(\''+escHtml(r.digits||'')+'\')">Remove</button></td></tr>').join('');
        }).catch(() => { body.innerHTML = '<tr><td colspan="4">Could not load.</td></tr>'; });
}
function supAddBlacklist(){
    const number = (document.getElementById('supBlNumber')||{}).value || '';
    const reason = (document.getElementById('supBlReason')||{}).value || 'blocked by supervisor';
    fetch('index.php?action=blacklist_add&domain=' + encodeURIComponent(domain), {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ number: number, reason: reason })
    }).then(r => r.json()).then(d => { if (d && d.ok) fetchSupBlacklist(); else alert((d && d.error) || 'Failed'); });
}
function supDelBlacklist(number){
    fetch('index.php?action=blacklist_del&domain=' + encodeURIComponent(domain), {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ number: number })
    }).then(r => r.json()).then(() => fetchSupBlacklist());
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
    let myExt = localStorage.getItem('sup_sip_ext') || supExt || localStorage.getItem('sup_ext') || '';
    if(!myExt){
        myExt = prompt('Enter YOUR supervisor extension (softphone that will ring):');
        if(!myExt){toast('Cancelled - no supervisor extension set','#c62828');return;}
        localStorage.setItem('sup_ext', myExt);
        localStorage.setItem('sup_sip_ext', myExt);
    }
    openPhonePopup();
    toast('Connecting '+mode+' - softphone (ext '+myExt+') will ring...');
    fetch('supervisor.php?action=monitor&mode='+mode+'&agent_ext='+encodeURIComponent(agentExt)+'&sup_ext='+encodeURIComponent(myExt)+'&domain='+encodeURIComponent(domain))
        .then(r=>r.json()).then(d=>{
            if(d.ok) toast(mode.charAt(0).toUpperCase()+mode.slice(1)+' started - answer on the softphone','#2e7d32');
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
            const rows=(d.rows||[]).filter(r=>!/^(101|102)$/.test(String(r.destination||'').trim()));
            document.getElementById('chCount').textContent=rows.length+' records';
            if(!rows.length){document.getElementById('chBody').innerHTML='<tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">No calls found.</td></tr>';return;}
            document.getElementById('chBody').innerHTML=rows.map(r=>`<tr>
                <td>${r.time}</td>
                <td>${r.caller}</td>
                <td>${r.destination}</td>
                <td>${r.agent||'—'}</td>
                <td><span class="badge-${r.direction==='outbound'?'out':'in'}">${r.direction||'—'}</span></td>
                <td>${r.duration}</td>
                <td><span class="badge-${r.status==='Answered'?'answered':r.status==='Failed'?'failed':r.status==='Agent Busy'?'busy':'missed'}">${r.status}</span></td>
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
function supervisorEmbedUrl(page) {
    const params = new URLSearchParams();
    params.set('embed', '1');
    if (domain) params.set('domain', domain);
    return '/app/agent_dashboard/' + page + '?' + params.toString();
}

function supervisorToolsUrl(tool) {
    const params = new URLSearchParams();
    params.set('embed', '1');
    params.set('tool', tool);
    if (domain) params.set('domain', domain);
    return '/app/agent_dashboard/supervisor_tools.php?' + params.toString();
}

function loadSupervisorEmbed(frameId, page) {
    const f = document.getElementById(frameId);
    if (!f) return;
    const url = page.indexOf('?') >= 0 ? page : supervisorEmbedUrl(page);
    if (f.src === 'about:blank' || !f.src || f.dataset.embedPage !== url) {
        f.src = url;
        f.dataset.embedPage = url;
    }
}

function setSideNavActive(name) {
    document.querySelectorAll('#sideMenu [data-side-tab]').forEach(function(el) {
        el.classList.remove('active');
    });
    if (!name) return;
    const link = document.querySelector('#sideMenu [data-side-tab="' + name + '"]');
    if (link) link.classList.add('active');
}

function showTab(name){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    const panel = document.getElementById('tab-'+name);
    if (panel) panel.classList.add('active');
    setSideNavActive(name);
    if(name==='leaderboard') fetchLeaderboard();
    if(name==='callhistory') fetchCallHistory();
    if(name==='acwall')      fetchAcwAll();
    if(name==='recordings')  fetchRecordings();
    if(name==='voicequality') fetchVoiceQuality();
    if(name==='skills') fetchSkillsAgents();
    if(name==='blacklist') fetchSupBlacklist();
    if(name==='settings') loadIdleSettings();
    if(name==='reports') loadSupervisorEmbed('reportsFrame', 'reports.php');
    if(name==='crm') loadSupervisorEmbed('crmFrame', 'crm.php');
    if(name==='evaluation') loadSupervisorEmbed('evaluationFrame', 'evaluation.php');
    if(name==='lookup') loadSupervisorEmbed('lookupFrame', supervisorToolsUrl('lookup'));
    if(name==='ticket') loadSupervisorEmbed('ticketFrame', supervisorToolsUrl('ticket'));
    if(name==='callbacks') loadSupervisorEmbed('callbacksFrame', supervisorToolsUrl('callbacks'));
    if(name==='ahununu') {
        const f = document.getElementById('ahununuFrame');
        if (f.src === 'about:blank') f.src = (window.SKYKIN && SKYKIN.ahununuUrl) || 'https://ahununu.com/';
    }
}

function showTabDirect(name){
    document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
    const panel = document.getElementById('tab-'+name);
    if (panel) panel.classList.add('active');
    setSideNavActive(name);
    if(name==='leaderboard') fetchLeaderboard();
    if(name==='callhistory') fetchCallHistory();
    if(name==='acwall')      fetchAcwAll();
    if(name==='recordings')  fetchRecordings();
    if(name==='voicequality') fetchVoiceQuality();
    if(name==='skills') fetchSkillsAgents();
    if(name==='blacklist') fetchSupBlacklist();
    if(name==='settings') loadIdleSettings();
    if(name==='reports') loadSupervisorEmbed('reportsFrame', 'reports.php');
    if(name==='crm') loadSupervisorEmbed('crmFrame', 'crm.php');
    if(name==='evaluation') loadSupervisorEmbed('evaluationFrame', 'evaluation.php');
    if(name==='lookup') loadSupervisorEmbed('lookupFrame', supervisorToolsUrl('lookup'));
    if(name==='ticket') loadSupervisorEmbed('ticketFrame', supervisorToolsUrl('ticket'));
    if(name==='callbacks') loadSupervisorEmbed('callbacksFrame', supervisorToolsUrl('callbacks'));
    if(name==='ahununu') {
        const f = document.getElementById('ahununuFrame');
        if (f && f.src === 'about:blank') f.src = (window.SKYKIN && SKYKIN.ahununuUrl) || 'https://ahununu.com/';
    }
}

function loadIdleSettings(){
    fetch('supervisor.php?action=get_settings', {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) return;
            var el = document.getElementById('idleMinutes');
            if (el) el.value = d.session_idle_minutes;
        }).catch(function(){});
}

function saveIdleSettings(){
    var el = document.getElementById('idleMinutes');
    var msg = document.getElementById('idleSaveMsg');
    var minutes = parseInt(el && el.value, 10);
    if (isNaN(minutes) || minutes < 0) minutes = 0;
    if (minutes > 1440) minutes = 1440;
    if (msg) msg.textContent = 'Saving...';
    fetch('supervisor.php?action=save_settings', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({session_idle_minutes: minutes})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (!d || !d.ok) {
            if (msg) msg.textContent = (d && d.error) ? d.error : 'Save failed';
            return;
        }
        if (el) el.value = d.session_idle_minutes;
        if (window.SKYKIN) SKYKIN.idleTimeoutMinutes = d.session_idle_minutes;
        if (msg) msg.textContent = d.session_idle_minutes === 0
            ? 'Saved. Auto-logout is off.'
            : 'Saved. Logout after ' + d.session_idle_minutes + ' minutes idle.';
    }).catch(function(){
        if (msg) msg.textContent = 'Save failed';
    });
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
    const wrap=document.getElementById('recPlayerWrap');
    const label=document.getElementById('recPlayingName');
    const domain = (window.SKYKIN && SKYKIN.domain) || location.hostname;
    let url = '/app/agent_dashboard/play_recording.php?f='+encodeURIComponent(file)
        +'&d='+encodeURIComponent(domain);
    if (path) { url += '&path='+encodeURIComponent(path.replace(/\/+$/,'')); }
    player.onended = () => stopRec();
    player.src=url; player.style.display='block';
    if (wrap) wrap.style.display='flex';
    if (label) label.textContent = 'Playing: ' + decodeURIComponent(file);
    player.play().catch(()=>{ toast('Could not play recording. File may have moved.','#c62828'); });
}

function stopRec(){
    const player=document.getElementById('recPlayer');
    const wrap=document.getElementById('recPlayerWrap');
    const label=document.getElementById('recPlayingName');
    if (player) {
        player.pause();
        player.currentTime = 0;
        player.removeAttribute('src');
        player.load();
        player.style.display='none';
    }
    if (wrap) wrap.style.display='none';
    if (label) label.textContent='';
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

// ── Supervisor softphone ───────────────────────────────────────────────────
let phoneOpen = false, lastDialedNumber = '', isMuted = false, onHold = false;
let callStartTime = null, callTimerInterval = null, dpNumber = '';
let _ringCtx = null, _ringInterval = null;

function showToast(msg, color){ toast(msg, color || '#333'); }

function buildSipWsUrl(host, port) {
    const cleanHost = String(host || location.hostname).replace(/^wss?:\/\//i,'').replace(/\/.*$/,'').replace(/:\d+$/,'');
    const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
    const pagePort = location.port ? (':' + location.port) : '';
    if (!host || cleanHost === location.hostname) {
        return scheme + location.hostname + pagePort + '/wss/';
    }
    if (port && port !== location.port && port !== '80' && port !== '443' && port !== '8088') {
        return scheme + cleanHost + ':' + port;
    }
    return scheme + cleanHost + pagePort + '/wss/';
}

function resolveSipDomain() {
    const serverDom = (domain || '').trim();
    const saved     = (localStorage.getItem('sup_sip_domain') || '').trim();
    const isIp = s => /^\d{1,3}(\.\d{1,3}){3}$/.test(s);
    let dom = serverDom;
    if (isIp(serverDom) || serverDom === '') {
        dom = (saved && !isIp(saved) && saved !== location.hostname) ? saved : (serverDom || saved || location.hostname);
    }
    if (dom && dom !== saved) localStorage.setItem('sup_sip_domain', dom);
    return dom;
}

function loadSipSettings() {
    const ext  = localStorage.getItem('sup_sip_ext')  || serverExt  || '';
    const pass = localStorage.getItem('sup_sip_pass') || serverPass || '';
    const dom  = resolveSipDomain();
    const rawServer = localStorage.getItem('sup_sip_server') || location.hostname;
    const cleanHost = rawServer.replace(/^wss?:\/\//i,'').replace(/\/.*$/,'').replace(/:\d+$/,'');
    const port = location.port || (location.protocol === 'https:' ? '443' : '80');
    const wsUrl = buildSipWsUrl(cleanHost, port);
    const el = (id) => document.getElementById(id);
    if (el('sipExt')) el('sipExt').value = ext;
    if (el('sipPass')) el('sipPass').value = pass;
    if (el('sipServer')) el('sipServer').value = cleanHost;
    if (el('sipPort')) el('sipPort').value = port;
    if (el('sipDomain')) el('sipDomain').value = dom;
    if (ext && pass) waitForSipBridge(() => initSIP(ext, pass, wsUrl, '', dom));
    else setSipStatus('failed', 'Open phone settings to connect');
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
    if (!ext || !pass) { alert('Enter extension and password'); return; }
    const rawServer = document.getElementById('sipServer').value.trim() || location.hostname;
    const cleanHost = rawServer.replace(/^wss?:\/\//i,'').replace(/:\d+$/,'');
    const port = document.getElementById('sipPort').value.trim() || location.port || '';
    const wsUrl = buildSipWsUrl(cleanHost, port);
    localStorage.setItem('sup_sip_ext', ext);
    localStorage.setItem('sup_sip_pass', pass);
    localStorage.setItem('sup_sip_server', cleanHost);
    localStorage.setItem('sup_sip_domain', dom);
    localStorage.setItem('sup_ext', ext);
    localStorage.setItem('sup_sip_port', port || location.port || '');
    document.getElementById('settingsModal').classList.remove('show');
    waitForSipBridge(() => initSIP(ext, pass, wsUrl, port, dom));
}

function initSIP(ext, pass, server, port, dom) {
    setSipStatus('connecting', 'Connecting...');
    if (sipBridge.init) sipBridge.init(ext, pass, server, port, dom);
    else setSipStatus('failed', 'SIP library not loaded');
}

function togglePhonePopup() {
    phoneOpen = !phoneOpen;
    document.getElementById('phonePopup').classList.toggle('open', phoneOpen);
    document.body.classList.toggle('phone-open', phoneOpen);
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
    const dot = document.getElementById('sipDot');
    const badge = document.getElementById('fabBadge');
    const fab = document.getElementById('phoneFab');
    const popup = document.getElementById('phonePopup');
    if (!dot || !badge || !fab || !popup) return;
    popup.classList.toggle('call-active', state === 'calling' || state === 'incall');
    dot.className = 'sip-dot'; badge.className = 'fab-badge'; fab.className = 'phone-fab';
    if (state === 'registered') {
        dot.classList.add('registered'); badge.classList.add('show');
    } else if (state === 'calling') {
        dot.classList.add('calling'); badge.classList.add('show','calling');
        fab.classList.add('ringing'); openPhonePopup();
        document.getElementById('btnHangup').style.display = 'block';
        document.getElementById('dpPanel').style.display = 'none';
        clearInterval(callTimerInterval);
        callTimerInterval = null;
        callStartTime = null;
        document.getElementById('callTimer').style.display = 'none';
        document.getElementById('callTimer').textContent = '00:00';
    } else if (state === 'incall') {
        dot.classList.add('registered'); badge.classList.add('show'); fab.classList.add('ringing');
    } else if (state === 'ringing') {
        dot.classList.add('ringing'); badge.classList.add('show'); fab.classList.add('ringing');
        document.getElementById('callTimer').style.display = 'none';
    } else if (state === 'connecting') {
        dot.classList.add('connecting');
    } else if (state === 'unregistered' || state === 'failed') {
        dot.classList.add('failed'); badge.classList.add('show','unreg');
    }
    document.getElementById('sipStatusText').textContent = text;
}

function handleIncoming(callerNumber) {
    lastDialedNumber = callerNumber || '';
    document.getElementById('incomingNumber').textContent = callerNumber;
    document.getElementById('incomingScreen').style.display = 'block';
    document.getElementById('dpPanel').style.display = 'none';
    openPhonePopup();
    setSipStatus('ringing', 'Ringing: ' + callerNumber);
    startRingtone();
}
function answerCall() { if (sipBridge.answer) sipBridge.answer(); }
function declineCall() {
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = 'block';
    stopRingtone();
    if (sipBridge.decline) sipBridge.decline();
    else if (sipBridge.hangup) sipBridge.hangup();
}
function makeCall(number) {
    number = number || document.getElementById('dialInput').value.trim() || dpNumber;
    if (!number) return;
    number = (window.skykinNormalizeEtDial && window.skykinNormalizeEtDial(number)) || number;
    document.getElementById('dialInput').value = number;
    lastDialedNumber = number;
    fetch('/app/agent_dashboard/crm.php?api=lookup&phone=' + encodeURIComponent(number), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.full_name) {
                toast('CRM: ' + data.full_name, '#0047AB');
            }
        }).catch(function() {});
    if (sipBridge.makeCall) sipBridge.makeCall(number);
    else toast('SIP not ready - open phone settings', '#c62828');
}
function startRingtone() {
    stopRingtone();
    function _ring() {
        try {
            _ringCtx = new (window.AudioContext || window.webkitAudioContext)();
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
    window._callEnded = false;
    stopRingtone();
    setSipStatus('incall', 'In Call: ' + number);
    document.getElementById('btnHangup').style.display = 'block';
    document.getElementById('btnHold').style.display = 'flex';
    document.getElementById('btnMute').style.display = 'flex';
    document.getElementById('btnKeypad').style.display = 'flex';
    const btnTransfer = document.getElementById('btnTransfer');
    if (btnTransfer) { btnTransfer.style.display = 'block'; btnTransfer.classList.add('visible'); }
    document.getElementById('callTimer').style.display = 'block';
    document.getElementById('dialInput').value = number || '';
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = 'none';
    document.getElementById('btnKeypad').classList.remove('active');
    callStartTime = new Date();
    clearInterval(callTimerInterval);
    callTimerInterval = setInterval(updateCallTimer, 1000);
    updateCallTimer();
    if (number) {
        fetch('/app/agent_dashboard/crm.php?api=lookup&phone=' + encodeURIComponent(number), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.full_name) {
                    window.showToast && window.showToast('CRM: ' + data.full_name);
                }
            }).catch(function() {});
    }
}
function updateCallTimer() {
    if (!callStartTime) return;
    const elapsed = Math.floor((new Date() - callStartTime) / 1000);
    document.getElementById('callTimer').textContent =
        String(Math.floor(elapsed/60)).padStart(2,'0') + ':' + String(elapsed%60).padStart(2,'0');
}
function hangupCall() { if (sipBridge.hangup) sipBridge.hangup(); endCall(); }
function toggleHold() {
    if (onHold) {
        if (sipBridge.unhold) sipBridge.unhold(); onHold = false;
        document.getElementById('btnHold').textContent = 'Hold';
        document.getElementById('btnHold').classList.remove('active');
    } else {
        if (sipBridge.hold) sipBridge.hold(); onHold = true;
        document.getElementById('btnHold').textContent = 'Resume';
        document.getElementById('btnHold').classList.add('active');
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
function endCall() {
    if (window._callEnded) return;
    window._callEnded = true;
    stopRingtone();
    onHold = false; isMuted = false;
    document.getElementById('phonePopup').classList.remove('call-active');
    clearInterval(callTimerInterval); callStartTime = null;
    document.getElementById('btnHangup').style.display = 'none';
    document.getElementById('btnHold').style.display = 'none';
    document.getElementById('btnMute').style.display = 'none';
    document.getElementById('btnMute').textContent = 'Mute';
    document.getElementById('btnMute').classList.remove('muted');
    document.getElementById('btnKeypad').style.display = 'none';
    document.getElementById('btnKeypad').classList.remove('active');
    const btnTransferEnd = document.getElementById('btnTransfer');
    if (btnTransferEnd) { btnTransferEnd.style.display = 'none'; btnTransferEnd.classList.remove('visible'); }
    document.getElementById('btnHold').textContent = 'Hold';
    document.getElementById('btnHold').classList.remove('active');
    document.getElementById('callTimer').style.display = 'none';
    document.getElementById('callTimer').textContent = '00:00';
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = 'block';
    const ext = localStorage.getItem('sup_sip_ext') || serverExt || '';
    if (ext) setSipStatus('registered', 'Registered (' + ext + ')');
    setTimeout(() => { window._callEnded = false; }, 3000);
}
function updateDpDisplay() {
    const inp = document.getElementById('dialInput');
    if (inp && inp.value !== dpNumber) inp.value = dpNumber;
}
function dpKey(k) {
    if (callStartTime && document.getElementById('btnKeypad').classList.contains('active') && sipBridge.sendDtmf) {
        sipBridge.sendDtmf(k); return;
    }
    if (dpNumber.length >= 20) return;
    dpNumber += k;
    updateDpDisplay();
}
function dpDelete() {
    if (callStartTime) return;
    dpNumber = dpNumber.slice(0, -1);
    updateDpDisplay();
}
function dpCall() { makeCall(); }
function toggleCallKeypad() {
    const btn = document.getElementById('btnKeypad');
    const panel = document.getElementById('dpPanel');
    const opening = panel.style.display === 'none';
    panel.style.display = opening ? 'block' : 'none';
    btn.classList.toggle('active', opening);
}
function openTransferModal() {
    const modal = document.getElementById('transferModal');
    if (!modal) return;
    modal.classList.add('show');
    const inp = document.getElementById('transferExtInput');
    if (inp) inp.value = '';
    loadAvailableAgents();
}
function closeTransferModal() {
    const modal = document.getElementById('transferModal');
    if (modal) modal.classList.remove('show');
}
function loadAvailableAgents() {
    const myExt = localStorage.getItem('sup_sip_ext') || serverExt || '';
    const list = document.getElementById('transferAgentsList');
    if (!list) return;
    list.innerHTML = '<div class="transfer-loading">Loading available agents...</div>';
    fetch('index.php?action=get_available_agents&domain=' + encodeURIComponent(domain) + '&my_ext=' + encodeURIComponent(myExt), {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data) {
            const agents = (data && data.agents) || [];
            if (!agents.length) {
                list.innerHTML = '<div class="transfer-loading">No available agents found.</div>';
                return;
            }
            list.innerHTML = agents.map(function(a) {
                const name = String(a.name || '').replace(/'/g, '\\u0027');
                return '<div class="transfer-agent-item" onclick="executeTransfer(\'' + a.extension + '\',\'' + name + '\')">'
                    + '<div><span class="transfer-agent-name">' + (a.name || a.extension) + '</span>'
                    + '<span class="transfer-agent-ext">Ext. ' + a.extension + '</span></div>'
                    + '<span class="transfer-agent-badge">Available</span></div>';
            }).join('');
        })
        .catch(function() {
            list.innerHTML = '<div class="transfer-loading" style="color:#ef4444">Failed to load agents.</div>';
        });
}
function executeTransfer(ext, name) {
    if (!ext) return;
    const displayName = name ? name + ' (Ext. ' + ext + ')' : 'Ext. ' + ext;
    if (!confirm('Transfer current call to ' + displayName + '?')) return;
    closeTransferModal();
    if (window.sipBridge && window.sipBridge.transfer) window.sipBridge.transfer(ext);
    else showToast('Transfer not available: SIP not connected.');
}
function executeManualTransfer() {
    const ext = (document.getElementById('transferExtInput').value || '').trim();
    if (!ext) { alert('Enter an extension number.'); return; }
    if (!/^\d{2,6}$/.test(ext)) { alert('Extension must be 2-6 digits.'); return; }
    executeTransfer(ext, '');
}
window.startCallUI = startCallUI;
window.endCall = endCall;
window.handleIncoming = handleIncoming;
window.setSipStatus = setSipStatus;
window.showToast = showToast;
setTimeout(loadSipSettings, 400);

const dialInputEl = document.getElementById('dialInput');
if (dialInputEl) {
    dialInputEl.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') makeCall();
    });
    dialInputEl.addEventListener('input', function() {
        dpNumber = this.value;
    });
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
<script src="/app/agent_dashboard/js/sipjs.bundle.js"></script>
<script>
(function() {
'use strict';
if (typeof SIPjs === 'undefined') {
    console.error('SIPjs bundle missing');
    return;
}
const { UserAgent, Registerer, Inviter, Invitation, SessionState, Web } = SIPjs;
let ua = null, reg = null, session = null;
const pbxDomain = () => localStorage.getItem('sup_sip_domain') || (window.SKYKIN && SKYKIN.domain) || location.hostname;

function attachAudio(s) {
    const sdh = s.sessionDescriptionHandler;
    if (!sdh || !sdh.peerConnection) return;
    const remote = new MediaStream();
    sdh.peerConnection.getReceivers().forEach(r => { if (r.track) remote.addTrack(r.track); });
    const el = document.getElementById('remoteAudio');
    if (el) { el.srcObject = remote; el.play().catch(()=>{}); }
}

function bindSession(s) {
    s.stateChange.addListener(state => {
        if (state === SessionState.Established) {
            const num = s instanceof Invitation
                ? (s.remoteIdentity && s.remoteIdentity.uri && s.remoteIdentity.uri.user) || window.lastDialedNumber || ''
                : (window.lastDialedNumber || '');
            window.startCallUI && window.startCallUI(num);
            attachAudio(s);
            window.showToast && window.showToast('Call connected');
        }
        if (state === SessionState.Terminated || state === SessionState.Terminating) {
            if (window.endCall) window.endCall();
        }
    });
}

window.sipBridge.init = function(ext, pass, server, port, dom) {
    if (ua) { try { reg && reg.unregister(); ua.stop(); } catch(e) {} }
    let wsUri = server;
    if (!wsUri.startsWith('wss://') && !wsUri.startsWith('ws://')) {
        wsUri = (location.protocol === 'https:' ? 'wss://' : 'ws://') + wsUri;
    }
    const hostPart = wsUri.replace(/^wss?:\/\//i, '');
    if (!hostPart.includes('/') && !hostPart.includes(':')) {
        const pagePort = location.port ? (':' + location.port) : '';
        wsUri = wsUri.replace(/^(wss?:\/\/)([^/:]+)$/i, '$1$2' + pagePort) + '/wss/';
    } else if (!hostPart.includes('/') && hostPart.endsWith(':' + (port || '5066'))) {
        wsUri = (location.protocol === 'https:' ? 'wss://' : 'ws://')
            + location.hostname + (location.port ? ':' + location.port : '') + '/wss/';
    }
    ua = new UserAgent({
        uri: UserAgent.makeURI('sip:' + ext + '@' + dom),
        transportOptions: { server: wsUri, traceSip: false },
        authorizationUsername: ext,
        authorizationPassword: pass,
        logLevel: 'error',
        logConfiguration: false
    });
    reg = new Registerer(ua, { logConfiguration: false });
    reg.stateChange.addListener(state => {
        if (state === 'Registered') {
            window.setSipStatus('registered', 'Registered (' + ext + ')');
            window.ensureMic && window.ensureMic().catch(function(){});
        } else if (state === 'Unregistered') {
            window.setSipStatus('unregistered', 'Not Registered');
        } else if (state === 'Terminated') {
            window.setSipStatus('failed', 'Registration Failed');
        }
    });
    ua.delegate = {
        onInvite(inv) {
            session = inv;
            const num = (inv.remoteIdentity && inv.remoteIdentity.uri && inv.remoteIdentity.uri.user)
                || (inv.remoteIdentity && inv.remoteIdentity.displayName)
                || 'Unknown';
            window.lastDialedNumber = num;
            try { inv.progress({ statusCode: 180 }); } catch (e) {}
            window.handleIncoming && window.handleIncoming(num);
            bindSession(inv);
        }
    };
    ua.start().then(() => reg.register()).catch(err => {
        window.setSipStatus('failed', 'Error: ' + err.message);
    });
};

window.ensureMic = function() {
    if (window._micStream) return Promise.resolve(window._micStream);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return Promise.reject(new Error('Microphone needs HTTPS'));
    }
    return navigator.mediaDevices.getUserMedia({ audio: true, video: false })
        .then(function(stream) { window._micStream = stream; return stream; });
};

window.sipBridge.makeCall = function(number) {
    if (!ua) { window.showToast && window.showToast('SIP not initialized'); return; }
    number = (window.skykinNormalizeEtDial && window.skykinNormalizeEtDial(number)) || number;
    const uri = UserAgent.makeURI('sip:' + number + '@' + pbxDomain());
    if (!uri) return;
    if (session) { try { session.dispose && session.dispose(); } catch(e) {} session = null; }
    window.ensureMic().then(function() {
        const inv = new Inviter(ua, uri, {
            sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } }
        });
        session = inv;
        window.setSipStatus && window.setSipStatus('calling', 'Calling ' + number);
        bindSession(inv);
        return inv.invite().catch(function(err) {
            window.setSipStatus && window.setSipStatus('failed', 'Call failed');
            window.endCall && window.endCall();
        });
    }).catch(function() {
        window.showToast && window.showToast('Microphone blocked - allow mic and reload');
    });
};

window.sipBridge.hangup = function() {
    if (!session) return;
    const st = session.state;
    try {
        if (st === SessionState.Initial || st === SessionState.Establishing) {
            session instanceof Invitation ? session.reject() : session.cancel && session.cancel();
        } else { session.bye(); }
    } catch(e) {}
    session = null;
};

window.sipBridge.answer = function() {
    if (!(session instanceof Invitation)) {
        window.showToast && window.showToast('No incoming call');
        return;
    }
    const inv = session;
    window.ensureMic().then(function() {
        return inv.accept({
            sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } }
        });
    }).catch(function(e) {
        window.showToast && window.showToast('Answer failed - check microphone permission');
    });
};

window.sipBridge.hold = function() {
    session && session.invite({ sessionDescriptionHandlerModifiers: [Web.holdModifier] }).catch(function(){});
};
window.sipBridge.unhold = function() {
    session && session.invite({ sessionDescriptionHandlerModifiers: [] }).catch(function(){});
};
window.sipBridge.mute = function() {
    if (!session) return;
    const pc = session.sessionDescriptionHandler && session.sessionDescriptionHandler.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track && s.track.kind === 'audio') s.track.enabled = false; });
};
window.sipBridge.unmute = function() {
    if (!session) return;
    const pc = session.sessionDescriptionHandler && session.sessionDescriptionHandler.peerConnection;
    if (pc) pc.getSenders().forEach(s => { if (s.track && s.track.kind === 'audio') s.track.enabled = true; });
};
window.sipBridge.sendDtmf = function(tone) {
    if (!session) return;
    try { session.sendDTMF(tone, {duration:100, interToneGap:500}); } catch(e) {}
};
window.sipBridge.transfer = function(targetExt) {
    if (!session) {
        window.showToast && window.showToast('No active call to transfer.');
        return;
    }
    const targetURI = UserAgent.makeURI('sip:' + targetExt + '@' + pbxDomain());
    if (!targetURI) {
        window.showToast && window.showToast('Invalid transfer target: ' + targetExt);
        return;
    }
    session.refer(targetURI)
        .then(function() {
            window.showToast && window.showToast('Call transferred to ext. ' + targetExt);
            session = null;
            if (window.endCall) window.endCall();
        })
        .catch(function(err) {
            window.showToast && window.showToast('Transfer failed: ' + (err && err.message ? err.message : err));
        });
};
})();
</script>

</body>
</html>
