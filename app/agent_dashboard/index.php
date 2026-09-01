<?php
// SkyKin Technologies - Real-time Agent Dashboard
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

skykin_session_enforce_idle();

// Session expired / not logged in → login page (not a 404)
// API calls get JSON 401 so the browser can redirect cleanly
if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'ok'    => false,
            'error' => 'Session expired. Please log in again.',
            'login' => '/',
        ]);
        exit;
    }
    header('Location: /?path=' . urlencode($_SERVER['REQUEST_URI'] ?? '/app/agent_dashboard/index.php'));
    exit;
}

if (!isset($_GET['action'])) {
    skykin_session_touch();
}

// Release session lock so background polls / other tabs are not blocked
session_write_close();

// ── Shared Skykin DB: connects to PostgreSQL via skykin_pdo_fusionpbx() ─────
// FIX (2026-08-07): Removed silent SQLite fallback. If PostgreSQL is
// unreachable, skykin_pdo_fusionpbx() throws a RuntimeException so the
// problem is immediately visible instead of tickets going to the wrong DB.
function getSkykinDB(): PDO {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

// ?? If called as data API, serve JSON and exit immediately ???????????????????
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $agent_name  = isset($_GET['agent'])  ? $_GET['agent']  : 'Agent1';
    $domain      = skykin_domain_param($_GET['domain'] ?? null);
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
             'avg_wait'=>0,'sla_rate'=>0,'recent_calls'=>[],'waiting_callers'=>[]];

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
            $agent_sql = skykin_cdr_agent_sql(':e');
            $rep = skykin_cdr_reportable_sql();
            $miss = skykin_cdr_missed_sql();
            $s = $db->prepare("SELECT SUM(CASE WHEN {$rep} THEN 1 ELSE 0 END) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN {$miss} THEN 1 ELSE 0 END) as missed,
                COALESCE(AVG(CASE WHEN billsec>0 THEN billsec END),0) as avg_dur,
                COALESCE(SUM(billsec),0) as total_talk,
                COALESCE(SUM(duration),0) as total_dur,
                SUM(CASE WHEN direction='outbound' THEN billsec ELSE 0 END) as outbound_time,
                SUM(CASE WHEN direction='local' THEN billsec ELSE 0 END) as internal_time
                FROM v_xml_cdr WHERE domain_name=:d
                AND {$agent_sql}
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
                direction,caller_id_number,destination_number,caller_destination,billsec,duration,hangup_cause
                FROM v_xml_cdr WHERE domain_name=:d
                AND {$agent_sql}
                AND start_epoch>=:ts AND start_epoch<=:te
                AND " . skykin_cdr_reportable_sql() . "
                ORDER BY start_epoch DESC LIMIT 500");
            $s2->execute([':d'=>$domain,':e'=>$extension,':ts'=>$today_start,':te'=>$today_end]);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (skykin_cdr_is_hunt_leg($r)) {
                    continue;
                }
                $dest = (string)$r['destination_number'];
                $dir  = strtolower((string)($r['direction'] ?? ''));
                $digits = preg_replace('/\D+/', '', $dest);
                $in = $dir === 'inbound'
                    || $dest === $extension
                    || $dest === '8000'
                    || strpos($dest, '+') === 0
                    || (bool)preg_match('/11113875\d$/', (string)$digits);
                $bill = (int)$r['billsec'];
                if ($in) {
                    $cid = (string)($r['caller_id_number'] ?? '');
                    $cid_digits = preg_replace('/\D+/', '', $cid);
                    // Until Ethio sends CLIP, inbound From/RPID is anonymous or our DID/ext.
                    // Only show a number that looks like a customer mobile.
                    if (preg_match('/^(0?9\d{8}|2519\d{8})$/', (string)$cid_digits)) {
                        $raw_num = $cid;
                    } else {
                        $raw_num = 'Unknown';
                    }
                } else {
                    $raw_num = $dest;
                }
                $clean_num = preg_replace('/@.*$/', '', (string)$raw_num);
                if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $clean_num)) {
                    $clean_num = $clean_num !== '' ? $clean_num : 'Unknown';
                    if (!preg_match('/^[\+\d\(\)\-\s#\*]{2,}$/', $clean_num)) {
                        $clean_num = 'Unknown';
                    }
                }
                $data['recent_calls'][] = [
                    'time'       => $r['call_time'],
                    'type'       => $in ? 'Inbound' : 'Outbound',
                    'number'     => $clean_num,
                    'duration'   => floor($bill/60).':'.str_pad($bill%60,2,'0',STR_PAD_LEFT),
                    'status'     => skykin_cdr_result_label(['billsec'=>$bill,'direction'=>$r['direction']??'','duration'=>$r['duration']??0,'hangup_cause'=>$r['hangup_cause']??'']),
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

        $queue_exts = [];
        foreach (($data['agent_queues'] ?? []) as $q) {
            if (!empty($q['queue_extension'])) {
                $queue_exts[] = (string)$q['queue_extension'];
            }
        }
        try {
            $data['waiting_callers'] = skykin_cc_waiting_callers($domain, $queue_exts);
            $data['queue_waiting'] = count($data['waiting_callers']);
        } catch (Throwable $ignored) {
            $data['waiting_callers'] = [];
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

// ── Upload browser call recording (auto-started on every connected call) ────
if (isset($_GET['action']) && $_GET['action'] === 'upload_recording' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
    $call_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['call_id'] ?? ('call-' . time())));
    $ext     = preg_replace('/[^0-9]/', '', (string)($_GET['ext'] ?? ''));
    $caller  = preg_replace('/[^0-9+\-]/', '', (string)($_GET['caller'] ?? ''));

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        echo json_encode(['ok' => false, 'error' => 'No recording uploaded']);
        exit;
    }

    $base = '/var/lib/freeswitch/recordings/' . $domain_ . '/agent';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    $filename = $call_id . '.webm';
    $dest = $base . '/' . $filename;
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save recording']);
        exit;
    }
    @chmod($dest, 0644);

    // Best-effort: attach filename to the newest matching CDR for this agent.
    // CDR may not exist yet when the browser uploads, so also keep a sidecar
    // and retry once after a short delay if needed.
    $meta = [
        'filename' => $filename,
        'path' => $base,
        'ext' => $ext,
        'caller' => $caller,
        'domain' => $domain_,
        'uploaded_at' => date('c'),
    ];
    @file_put_contents($dest . '.json', json_encode($meta));

    $linked = false;
    try {
        $db = skykin_pdo_fusionpbx();
        if ($db && $ext !== '') {
            // Prefer an unanswered recording slot on the newest matching call.
            $s = $db->prepare(
                "UPDATE v_xml_cdr
                 SET record_path = :path, record_name = :name
                 WHERE xml_cdr_uuid = (
                    SELECT xml_cdr_uuid FROM v_xml_cdr
                    WHERE domain_name = :d
                      AND (caller_id_number = :ext OR destination_number = :ext
                           OR caller_id_number = :caller OR destination_number = :caller)
                      AND start_stamp > NOW() - INTERVAL '6 hours'
                      AND (record_name IS NULL OR record_name = '')
                    ORDER BY start_stamp DESC LIMIT 1
                 )"
            );
            $s->execute([
                ':path' => $base,
                ':name' => $filename,
                ':d' => $domain_,
                ':ext' => $ext,
                ':caller' => $caller !== '' ? $caller : $ext,
            ]);
            $linked = ($s->rowCount() > 0);
            if (!$linked) {
                // Fall back: overwrite the newest matching CDR even if already linked.
                $s2 = $db->prepare(
                    "UPDATE v_xml_cdr
                     SET record_path = :path, record_name = :name
                     WHERE xml_cdr_uuid = (
                        SELECT xml_cdr_uuid FROM v_xml_cdr
                        WHERE domain_name = :d
                          AND (caller_id_number = :ext OR destination_number = :ext
                               OR caller_id_number = :caller OR destination_number = :caller)
                          AND start_stamp > NOW() - INTERVAL '6 hours'
                        ORDER BY start_stamp DESC LIMIT 1
                     )"
                );
                $s2->execute([
                    ':path' => $base,
                    ':name' => $filename,
                    ':d' => $domain_,
                    ':ext' => $ext,
                    ':caller' => $caller !== '' ? $caller : $ext,
                ]);
                $linked = ($s2->rowCount() > 0);
            }
        }
    } catch (Exception $ignored) {}

    echo json_encode(['ok' => true, 'filename' => $filename, 'path' => $base, 'linked' => $linked]);
    exit;
}


// ── List recordings for the agent dashboard Recordings tab ───────────────────
if (isset($_GET['action']) && $_GET['action'] === 'recordings') {
    error_reporting(0);
    header('Content-Type: application/json');
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
    $ext     = preg_replace('/[^0-9]/', '', (string)($_GET['ext'] ?? ''));
    $from    = $_GET['from'] ?? date('Y-m-d');
    $to      = $_GET['to']   ?? date('Y-m-d');
    $ts = strtotime($from . ' 00:00:00');
    $te = strtotime($to   . ' 23:59:59');
    $out = [];
    $seen = [];

    try {
        $db = skykin_pdo_fusionpbx();
        if ($db) {
            skykin_link_archive_recordings($db, $domain_, $ts, $te);
            $where = "domain_name = :d AND start_epoch >= :ts AND start_epoch <= :te
                      AND ((record_name IS NOT NULL AND record_name <> '') OR (record_path IS NOT NULL AND record_path <> ''))";
            $params = [':d' => $domain_, ':ts' => $ts, ':te' => $te];
            if ($ext !== '') {
                $where .= ' AND ' . skykin_cdr_agent_sql(':ext');
                $params[':ext'] = $ext;
            }
            $s = $db->prepare(
                "SELECT to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as datetime,
                        caller_id_number, destination_number, direction, billsec,
                        record_path, record_name
                 FROM v_xml_cdr WHERE {$where}
                 ORDER BY start_epoch DESC LIMIT 200"
            );
            $s->execute($params);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $file = trim((string)($r['record_name'] ?? ''));
                $path = rtrim(trim((string)($r['record_path'] ?? '')), '/');
                if ($file === '') continue;
                // A CDR can name a recording that never captured audio, or one that
                // has since been removed from disk. Listing those hands the agent a
                // Play button that stays silent, so check the file first.
                if (!skykin_recording_playable(skykin_recording_path($file, $domain_, $path))) {
                    continue;
                }
                $remote = ($r['direction'] === 'inbound')
                    ? ($r['caller_id_number'] ?? '')
                    : ($r['destination_number'] ?? '');
                $b = (int)$r['billsec'];
                $filepath = '/app/agent_dashboard/play_recording.php?f=' . rawurlencode($file)
                    . '&d=' . rawurlencode($domain_)
                    . ($path !== '' ? '&path=' . rawurlencode($path) : '');
                $seen[$file] = true;
                $out[] = [
                    'datetime' => $r['datetime'],
                    'remote_number' => $remote,
                    'duration' => floor($b / 60) . ':' . str_pad((string)($b % 60), 2, '0', STR_PAD_LEFT),
                    'direction' => $r['direction'] ?: 'outbound',
                    'filename' => $file,
                    'filepath' => $filepath,
                ];
            }
        }
    } catch (Exception $e) {
        // Fall through to filesystem listing.
    }

    // Softphone auto-uploads land here even when CDR linking races the upload.
    $agentDir = '/var/lib/freeswitch/recordings/' . $domain_ . '/agent';
    if (is_dir($agentDir)) {
        foreach (glob($agentDir . '/call-*.webm') ?: [] as $path) {
            $mtime = filemtime($path);
            if ($mtime < $ts || $mtime > $te) {
                continue;
            }
            $file = basename($path);
            if (isset($seen[$file])) {
                continue;
            }
            $meta = [];
            $metaFile = $path . '.json';
            if (is_file($metaFile)) {
                $meta = json_decode((string)@file_get_contents($metaFile), true) ?: [];
            }
            if ($ext !== '' && !empty($meta['ext']) && (string)$meta['ext'] !== $ext) {
                continue;
            }
            $size = filesize($path) ?: 0;
            // Rough duration estimate from webm size (~8KB/s Opus mono).
            $secs = max(1, (int)round($size / 8000));
            $out[] = [
                'datetime' => date('Y-m-d H:i', $mtime),
                'remote_number' => (string)($meta['caller'] ?? ''),
                'duration' => floor($secs / 60) . ':' . str_pad((string)($secs % 60), 2, '0', STR_PAD_LEFT),
                'direction' => 'outbound',
                'filename' => $file,
                'filepath' => '/app/agent_dashboard/play_recording.php?f=' . rawurlencode($file)
                    . '&d=' . rawurlencode($domain_),
            ];
        }
    }

    // Legacy flat recordings saved directly under the recordings root by the
    // earlier dialplan (e.g. 2026-08-10-10-27-53_102_101_<uuid>.wav). These have
    // no CDR row and are not nested under {domain}/, so the queries above miss
    // them. Parse the timestamp and the dest/caller numbers from the filename.
    foreach (glob('/var/lib/freeswitch/recordings/*.wav') ?: [] as $path) {
        $file = basename($path);
        if (isset($seen[$file])) {
            continue;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})_(\d+)_(\d+)_/', $file, $m)) {
            continue;
        }
        $when = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        if ($when < $ts || $when > $te) {
            continue;
        }
        $dest = $m[7];
        $caller = $m[8];
        if ($ext !== '' && $caller !== $ext && $dest !== $ext) {
            continue;
        }
        if (!skykin_recording_playable($path)) {
            continue;
        }
        $remote = ($caller === $ext) ? $dest : $caller;
        $size = filesize($path) ?: 0;
        // WAV PCM 16-bit stereo 8kHz ~= 32KB/s; good enough for a rough length.
        $secs = max(1, (int)round($size / 32000));
        $seen[$file] = true;
        $out[] = [
            'datetime' => date('Y-m-d H:i', $when),
            'remote_number' => $remote,
            'duration' => floor($secs / 60) . ':' . str_pad((string)($secs % 60), 2, '0', STR_PAD_LEFT),
            'direction' => ($dest === $ext) ? 'inbound' : 'outbound',
            'filename' => $file,
            'filepath' => '/app/agent_dashboard/play_recording.php?f=' . rawurlencode($file)
                . '&d=' . rawurlencode($domain_),
        ];
    }

    usort($out, function ($a, $b) {
        return strcmp($b['datetime'], $a['datetime']);
    });

    echo json_encode(['recordings' => $out]);
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
            
            // Optional demo seed (off by default — enable seed_demo_data in skykin_local_config)
            $cnt = $db->query("SELECT COUNT(*) FROM skykin_deliveries")->fetchColumn();
            if (!empty(skykin_config()['seed_demo_data']) && $cnt == 0) {
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
            skykin_crm_ensure_contacts($db);
            $data['contact'] = skykin_crm_find_contact($db, $customerPhone);
            if (!$data['contact']) {
                $norm = skykin_normalize_phone_storage($customerPhone);
                if ($norm !== '' && $norm !== $customerPhone) {
                    $data['contact'] = skykin_crm_find_contact($db, $norm);
                }
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
    $scope_all = in_array($agent_id, ['all', '*', ''], true) && skykin_user_in_groups(['superadmin', 'admin', 'supervisor']);
    
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
        
        if ($scope_all) {
            $s = $db->prepare("SELECT callback_id, customer_name, customer_phone, {$timeFmt} as formatted_time, notes, status, agent_id
                FROM skykin_callbacks
                WHERE status = 'Scheduled'
                ORDER BY callback_time ASC LIMIT 200");
            $s->execute();
        } else {
            $s = $db->prepare("SELECT callback_id, customer_name, customer_phone, {$timeFmt} as formatted_time, notes, status, agent_id
                FROM skykin_callbacks
                WHERE agent_id = :agent AND status = 'Scheduled'
                ORDER BY callback_time ASC LIMIT 100");
            $s->execute([':agent' => $agent_id]);
        }
        
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
// ESL settings come from skykin_esl_settings(): FreeSWITCH's own
// event_socket.conf.xml, overridable via .env / ESL_* env vars.
// If ESL is unreachable (e.g. local dev with ACL blocking), the DB is still
// updated and the response includes esl_error for debugging.
if (isset($_GET['action']) && $_GET['action'] === 'set_agent_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $body       = json_decode(file_get_contents('php://input'), true) ?: [];
    $agent_ext  = trim($body['agent_ext']  ?? '');
    $new_status = trim($body['new_status'] ?? 'Available');
    $domain_    = skykin_domain_param($body['domain'] ?? null);

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
        // The domain passed in the URL may differ from the v_domains name
        // (hostname vs IP). Fallback ensures we always match.
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
        // FusionPBX stores agent_contact as e.g. "user/1003@domain".
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
        $esl_connected   = false;
        $esl_response    = '';
        $esl_state_resp  = '';
        $esl_error       = '';
        $esl = skykin_esl($esl_error);
        if ($esl) {
            $esl_connected = true;
            $q = $db->prepare(
                "SELECT queue_extension FROM v_call_center_queues WHERE domain_uuid = :domain_uuid"
            );
            $q->execute([':domain_uuid' => $domain_uuid]);
            $queues = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $d = $db->prepare("SELECT domain_name FROM v_domains WHERE domain_uuid = :u");
            $d->execute([':u' => $domain_uuid]);
            $cc_domain = (string)($d->fetchColumn() ?: $domain_);
            // Logged-in dashboard agents must exist in the queue with a WebRTC
            // contact before status is set, including 101 if they use the dashboard.
            if ($new_status === 'Available') {
                skykin_cc_ensure_agent($agent_uuid, $agent_ext, $cc_domain, $queues);
            }
            $res = $esl->request('api callcenter_config agent set status ' . $agent_uuid . " '" . $new_status . "'");
            $esl_response = is_array($res) ? ($res['$'] ?? implode(' | ', $res)) : (string)$res;

            if (stripos($esl_response, 'Agent not found') !== false) {
                if (skykin_cc_ensure_agent($agent_uuid, $agent_ext, $cc_domain, $queues)) {
                    $res = $esl->request('api callcenter_config agent set status ' . $agent_uuid . " '" . $new_status . "'");
                    $esl_response = is_array($res) ? ($res['$'] ?? implode(' | ', $res)) : (string)$res;
                }
            }

            // Set agent state to Waiting when becoming Available or Logged Out
            if ($new_status === 'Available' || $new_status === 'Logged Out') {
                $res2 = $esl->request('api callcenter_config agent set state ' . $agent_uuid . " 'Waiting'");
                $esl_state_resp = is_array($res2) ? ($res2['$'] ?? implode(' | ', $res2)) : (string)$res2;
            }
            // A leftover reject/busy delay leaves ready_time in the future, so
            // longest-idle skips this agent even after they click Ready.
            if ($new_status === 'Available') {
                $esl->request('api callcenter_config agent set wrap_up_time ' . $agent_uuid . ' 0');
                $esl->request('api callcenter_config agent set ready_time ' . $agent_uuid . ' 0');
            }
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

// ── Leave requests helpers / APIs ─────────────────────────────────────────────
function ensureLeaveRequestsTable($db) {
    $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    if ($isSQLite) {
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_leave_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL,
            agent_ext TEXT NOT NULL,
            agent_name TEXT,
            request_type TEXT NOT NULL,
            reason TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            resolved_by TEXT
        )");
    } else {
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
}

// Agent: request On Break (stays Available until supervisor approves).
// Logout is immediate — no supervisor approval.
if (isset($_GET['action']) && $_GET['action'] === 'request_leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $agent_ext  = trim($body['agent_ext'] ?? '');
    $agent_name = trim($body['agent_name'] ?? '');
    $domain_    = skykin_domain_param($body['domain'] ?? null);
    $req_type   = trim($body['request_type'] ?? '');
    $reason     = trim($body['reason'] ?? '');
    $allowed_types = ['On Break'];
    if (!$agent_ext || !in_array($req_type, $allowed_types, true)) {
        echo json_encode(['ok' => false, 'error' => 'agent_ext and request_type (On Break) required']);
        exit;
    }
    try {
        $db = getSkykinDB();
        ensureLeaveRequestsTable($db);
        $chk = $db->prepare("SELECT id FROM skykin_leave_requests
            WHERE domain = :d AND agent_ext = :e AND status = 'pending' LIMIT 1");
        $chk->execute([':d' => $domain_, ':e' => $agent_ext]);
        if ($chk->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'You already have a pending leave request']);
            exit;
        }
        $ins = $db->prepare("INSERT INTO skykin_leave_requests
            (domain, agent_ext, agent_name, request_type, reason, status)
            VALUES (:d, :e, :n, :t, :r, 'pending')");
        $ins->execute([
            ':d' => $domain_,
            ':e' => $agent_ext,
            ':n' => $agent_name ?: ('Ext ' . $agent_ext),
            ':t' => $req_type,
            ':r' => $reason !== '' ? $reason : null,
        ]);
        $id = $db->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $id, 'request_type' => $req_type, 'status' => 'pending']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Agent: poll own leave request (pending or latest resolved)
if (isset($_GET['action']) && $_GET['action'] === 'my_leave_request') {
    error_reporting(0);
    header('Content-Type: application/json');
    $agent_ext = trim($_GET['agent_ext'] ?? '');
    $domain_   = skykin_domain_param($_GET['domain'] ?? null);
    if (!$agent_ext) {
        echo json_encode(['ok' => false, 'error' => 'agent_ext required']);
        exit;
    }
    try {
        $db = getSkykinDB();
        ensureLeaveRequestsTable($db);
        $s = $db->prepare("SELECT id, agent_ext, agent_name, request_type, reason, status,
            requested_at, resolved_at, resolved_by
            FROM skykin_leave_requests
            WHERE domain = :d AND agent_ext = :e
            ORDER BY id DESC LIMIT 1");
        $s->execute([':d' => $domain_, ':e' => $agent_ext]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'request' => $row ?: null]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'request' => null]);
    }
    exit;
}

// Agent: cancel own pending leave request
if (isset($_GET['action']) && $_GET['action'] === 'cancel_leave_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $agent_ext = trim($body['agent_ext'] ?? '');
    $domain_   = skykin_domain_param($body['domain'] ?? null);
    $id        = (int)($body['id'] ?? 0);
    if (!$agent_ext) {
        echo json_encode(['ok' => false, 'error' => 'agent_ext required']);
        exit;
    }
    try {
        $db = getSkykinDB();
        ensureLeaveRequestsTable($db);
        if ($id > 0) {
            $s = $db->prepare("UPDATE skykin_leave_requests
                SET status = 'cancelled', resolved_at = CURRENT_TIMESTAMP, resolved_by = :by
                WHERE id = :id AND domain = :d AND agent_ext = :e AND status = 'pending'");
            $s->execute([':by' => $agent_ext, ':id' => $id, ':d' => $domain_, ':e' => $agent_ext]);
        } else {
            $s = $db->prepare("UPDATE skykin_leave_requests
                SET status = 'cancelled', resolved_at = CURRENT_TIMESTAMP, resolved_by = :by
                WHERE domain = :d AND agent_ext = :e AND status = 'pending'");
            $s->execute([':by' => $agent_ext, ':d' => $domain_, ':e' => $agent_ext]);
        }
        echo json_encode(['ok' => true, 'updated' => $s->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: Agent blacklist (block unwanted callers) ──────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['blacklist_list', 'blacklist_add', 'blacklist_del'], true)) {
    error_reporting(0);
    header('Content-Type: application/json');
    $domain_ = skykin_domain_param($_GET['domain'] ?? ($_POST['domain'] ?? null));
    $agent_  = trim((string)($_SESSION['username'] ?? ($_GET['agent'] ?? '')));
    if ($_GET['action'] === 'blacklist_list') {
        $rows = [];
        foreach (skykin_blacklist_load() as $r) {
            if (strcasecmp((string)($r['domain'] ?? ''), $domain_) === 0) {
                $rows[] = $r;
            }
        }
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($_GET['action'] === 'blacklist_add') {
        $number = trim((string)($body['number'] ?? $_GET['number'] ?? ''));
        $reason = trim((string)($body['reason'] ?? 'blocked by agent'));
        $digits = skykin_blacklist_digits($number);
        if (strlen($digits) < 7) {
            echo json_encode(['ok' => false, 'error' => 'Enter a valid phone number']);
            exit;
        }
        if (skykin_blacklist_match($number, $domain_)) {
            $rows = [];
            foreach (skykin_blacklist_load() as $r) {
                if (strcasecmp((string)($r['domain'] ?? ''), $domain_) === 0) {
                    $rows[] = $r;
                }
            }
            echo json_encode(['ok' => true, 'already' => true, 'rows' => $rows]);
            exit;
        }
        $rows = skykin_blacklist_load();
        $rows[] = [
            'domain' => $domain_,
            'digits' => $digits,
            'display' => $number,
            'reason' => $reason,
            'agent' => $agent_,
            'ts' => time(),
        ];
        $saved = skykin_blacklist_save($rows);
        echo json_encode([
            'ok' => $saved,
            'digits' => $digits,
            'rows' => $saved ? skykin_blacklist_load() : [],
            'error' => $saved ? '' : 'Could not save blacklist',
        ]);
        exit;
    }
    if ($_GET['action'] === 'blacklist_del') {
        $digits = skykin_blacklist_digits((string)($body['number'] ?? $_GET['number'] ?? ''));
        $kept = [];
        foreach (skykin_blacklist_load() as $r) {
            if (($r['digits'] ?? '') === $digits && (strcasecmp((string)($r['domain'] ?? ''), $domain_) === 0 || ($r['domain'] ?? '') === '*')) {
                continue;
            }
            $kept[] = $r;
        }
        echo json_encode(['ok' => skykin_blacklist_save($kept)]);
        exit;
    }
    exit;
}

// ── API: Get Available Agents (for call transfer target list) ────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_available_agents') {
    error_reporting(0);
    header('Content-Type: application/json');
    $domain_ = skykin_domain_param($_GET['domain'] ?? null);
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

// ── Outbound: B-leg state for answer (ACTIVE) and decline detection ─────────
// SKYKIN_SAFE_DECLINE_v3
if (isset($_GET['action']) && $_GET['action'] === 'outbound_live') {
    error_reporting(0);
    header('Content-Type: application/json');
    $ext = preg_replace('/\D+/', '', (string)($_GET['ext'] ?? ''));
    $dest = preg_replace('/\D+/', '', (string)($_GET['dest'] ?? ''));
    $destTail = strlen($dest) >= 9 ? substr($dest, -9) : $dest;
    $blegLive = false;
    $agentLive = false;
    $blegState = '';
    $agentUuid = '';
    $callUuid = '';
    $partnerUuid = '';
    $rows = [];
    if ($ext !== '' || $destTail !== '') {
        $json = json_decode(skykin_fs_api('show channels as json'), true);
        $rows = (is_array($json) ? ($json['rows'] ?? []) : []);
    }
    $isAgentRow = static function (array $row) use ($ext): bool {
        if ($ext === '') {
            return false;
        }
        $name = strtolower((string)($row['name'] ?? ''));
        $presence = strtolower((string)($row['presence_id'] ?? ''));
        $cid = preg_replace('/\D+/', '', (string)($row['cid_num'] ?? $row['cid_number'] ?? ''));
        $state = strtoupper((string)($row['callstate'] ?? ''));
        if (in_array($state, ['HANGUP', 'DOWN'], true)) {
            return false;
        }
        return (bool)preg_match('#(^|[/@])' . preg_quote($ext, '#') . '(@|$|-)#', $name)
            || strpos($presence, $ext . '@') !== false
            || $cid === $ext;
    };
    $isLiveRow = static function (array $row): bool {
        $state = strtoupper((string)($row['callstate'] ?? ''));
        return !in_array($state, ['HANGUP', 'DOWN'], true);
    };
    $markBleg = static function (array $row) use (&$blegLive, &$blegState): void {
        $blegLive = true;
        $blegState = strtoupper((string)($row['callstate'] ?? ''));
    };
    $destMatches = static function (array $row) use ($destTail): bool {
        if ($destTail === '' || strlen($destTail) < 9) {
            return false;
        }
        $fields = [
            (string)($row['dest'] ?? ''),
            (string)($row['callee_num'] ?? $row['callee_id_number'] ?? ''),
            (string)($row['application_data'] ?? ''),
            (string)($row['name'] ?? ''),
        ];
        foreach ($fields as $field) {
            $digits = preg_replace('/\D+/', '', $field);
            if ($digits === '') {
                continue;
            }
            if (str_ends_with($digits, $destTail) || str_ends_with($destTail, substr($digits, -9))) {
                return true;
            }
        }
        return false;
    };
    foreach ($rows as $row) {
        if (!is_array($row) || !$isLiveRow($row) || !$isAgentRow($row)) {
            continue;
        }
        $agentLive = true;
        $agentUuid = (string)($row['uuid'] ?? '');
        $callUuid = trim((string)($row['call_uuid'] ?? $agentUuid));
        $partnerUuid = trim((string)($row['b_uuid'] ?? $row['bridge_uuid'] ?? ''));
    }
    if ($partnerUuid !== '') {
        foreach ($rows as $row) {
            if (!is_array($row) || !$isLiveRow($row)) {
                continue;
            }
            if ((string)($row['uuid'] ?? '') === $partnerUuid) {
                $markBleg($row);
                break;
            }
        }
    }
    if (!$blegLive && $callUuid !== '') {
        foreach ($rows as $row) {
            if (!is_array($row) || !$isLiveRow($row) || $isAgentRow($row)) {
                continue;
            }
            if (trim((string)($row['call_uuid'] ?? '')) === $callUuid) {
                $markBleg($row);
                break;
            }
        }
    }
    if (!$blegLive) {
        foreach ($rows as $row) {
            if (!is_array($row) || !$isLiveRow($row) || $isAgentRow($row)) {
                continue;
            }
            $name = strtolower((string)($row['name'] ?? ''));
            $dir = strtolower((string)($row['direction'] ?? ''));
            $isGateway = (strpos($name, 'sofia/external/') !== false)
                || (strpos($name, 'sofia/gateway/') !== false)
                || (strpos($name, 'gateway/') !== false)
                || $dir === 'outbound';
            if ($isGateway && ($destTail === '' || $destMatches($row))) {
                $markBleg($row);
            }
        }
    }
    echo json_encode([
        'agent' => $agentLive,
        'bleg' => $blegLive,
        'bleg_state' => $blegState,
        'channels' => count($rows),
        'partner_uuid' => $partnerUuid,
    ]);
    exit;
}

// ── Kill FS legs when agent ends ring or mobile declined ────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'outbound_stop') {
    error_reporting(0);
    header('Content-Type: application/json');
    $ext = preg_replace('/\D+/', '', (string)($_GET['ext'] ?? ''));
    $dest = preg_replace('/\D+/', '', (string)($_GET['dest'] ?? ''));
    $destTail = strlen($dest) >= 9 ? substr($dest, -9) : $dest;
    $killed = [];
    $callUuid = '';
    if ($ext !== '') {
        $json = json_decode(skykin_fs_api('show channels as json'), true);
        $rows = (is_array($json) ? ($json['rows'] ?? []) : []);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string)($row['uuid'] ?? '');
            $name = strtolower((string)($row['name'] ?? ''));
            $presence = strtolower((string)($row['presence_id'] ?? ''));
            $cid = preg_replace('/\D+/', '', (string)($row['cid_num'] ?? $row['cid_number'] ?? ''));
            $isAgent = (bool)preg_match('#(^|[/@])' . preg_quote($ext, '#') . '(@|$|-)#', $name)
                || strpos($presence, $ext . '@') !== false
                || $cid === $ext;
            if ($isAgent && $uuid !== '') {
                $callUuid = trim((string)($row['call_uuid'] ?? $uuid));
                skykin_fs_api('uuid_kill ' . $uuid);
                $killed[] = $uuid;
            }
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string)($row['uuid'] ?? '');
            if ($uuid === '' || in_array($uuid, $killed, true)) {
                continue;
            }
            $sameCall = ($callUuid !== '' && trim((string)($row['call_uuid'] ?? '')) === $callUuid);
            $name = strtolower((string)($row['name'] ?? ''));
            $blob = preg_replace('/\D+/', '', $name . ($row['dest'] ?? '') . ($row['callee_num'] ?? ''));
            $isExternal = (strpos($name, 'external') !== false || strpos($name, 'gateway') !== false);
            $numMatch = ($destTail !== '' && strlen($destTail) >= 9 && strpos($blob, $destTail) !== false);
            if ($sameCall || ($isExternal && ($numMatch || $callUuid !== ''))) {
                skykin_fs_api('uuid_kill ' . $uuid);
                $killed[] = $uuid;
            }
        }
    }
    echo json_encode(['ok' => true, 'killed' => $killed]);
    exit;
}

// ── Softphone client diagnostics (temporary) ────────────────────────────────
// The browser is the only place that knows why answering a call failed, so it
// reports the error here for server-side troubleshooting.
if (isset($_GET['action']) && $_GET['action'] === 'client_log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $line = sprintf(
        "%s user=%s ext=%s event=%s name=%s message=%s extra=%s ua=%s\n",
        date('Y-m-d H:i:s'),
        $_SESSION['username'] ?? '-',
        substr((string)($body['ext'] ?? '-'), 0, 12),
        substr((string)($body['event'] ?? '-'), 0, 40),
        substr((string)($body['name'] ?? '-'), 0, 60),
        substr(str_replace(["\n", "\r"], ' ', (string)($body['message'] ?? '-')), 0, 300),
        substr(str_replace(["\n", "\r"], ' ', (string)($body['extra'] ?? '-')), 0, 300),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 60)
    );
    @error_log($line, 3, skykin_log_path('client.log'));
    echo json_encode(['ok' => true]);
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

$agent_name = trim((string)($_GET['agent'] ?? ''));
if ($agent_name === '') {
    $agent_name = trim((string)($_SESSION['username'] ?? 'Agent'));
}
$agent_name = htmlspecialchars($agent_name);
$domain = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));

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
// Same-origin /wss/ on :8088. Chrome blocks WSS to :5060 (ERR_UNSAFE_PORT)
// and the cloud firewall blocks :7443.
$agent_wss      = 'wss://' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) . ':8088/wss/';
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
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php echo skykin_favicon_tag(); ?>
<meta http-equiv="Cache-Control" content="no-store">
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
.main { margin-top: 64px; margin-left: 240px; padding: 20px; margin-bottom: 20px; transition: margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1), margin-right 0.3s ease; }
.main.sidebar-collapsed { margin-left: 0; }
@media (max-width: 768px) {
    .main { margin-left: 0 !important; }
}

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

/* ── Summary Cards ── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px; margin-bottom: 20px;
}
@media (min-width: 769px) {
    .main:not(.sidebar-collapsed) .summary-grid {
        grid-template-columns: repeat(4, 1fr);
    }
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

.wait-list { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #eee; }
.wait-list-title { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; }
.wait-empty { font-size: 12px; color: #aaa; padding: 6px 0; }
.wait-note { font-size: 11px; color: #aaa; margin-top: 8px; }
.wait-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px solid #f5f5f5; font-size: 13px; }
.wait-row:last-child { border-bottom: none; }
.wait-pos { width: 20px; height: 20px; border-radius: 50%; background: #fff4e5; color: #fd7e14; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wait-num { font-weight: 700; color: #333; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.wait-meta { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.wait-time { color: #fd7e14; font-weight: 700; font-size: 12px; }
.wait-state { font-size: 10px; font-weight: 700; color: #888; background: #f4f6f8; border-radius: 10px; padding: 2px 7px; }

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
.badge-failed   { background: #fff3cd; color: #856404; }
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
.phone-popup.ringing-inbound {
    z-index: 10050;
    box-shadow: -4px 0 28px rgba(253, 126, 20, 0.35);
}
.phone-popup.ringing-inbound .call-controls,
.phone-popup.ringing-inbound #callTimer,
.phone-popup.ringing-inbound .dp-panel { display: none !important; }
.phone-popup.ringing-inbound #incomingScreen { display: block !important; }

.pp-body { flex-shrink: 0; padding: 0; }
.phone-popup.call-active .pp-body { padding: 12px 16px; }
.dial-input-wrap { padding: 10px 16px 0; }
.dp-panel { flex-shrink: 0; }
/* Shift dashboard content when the dial pad is open (same as supervisor). */
body.phone-open .main,
body.phone-open .footer { margin-right: 300px; transition: margin-right 0.3s ease; }
.pp-header {
    background: linear-gradient(135deg, #0047AB, #00B4D8);
    color: white; padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between;
}
.pp-header-actions { display: flex; align-items: center; gap: 6px; }
.btn-settings-header {
    width: 30px; height: 30px; border: 0; border-radius: 50%;
    background: rgba(255,255,255,.16); color: #fff; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 15px; transition: background .15s ease;
}
.btn-settings-header:hover { background: rgba(255,255,255,.28); }
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
.pp-body { display: flex; flex-direction: column; gap: 10px; }
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
    grid-template-columns: repeat(12, 1fr);
    gap: 8px;
}
.btn-call {
    grid-column: 3 / span 8;
    background: #15803d; border: none; color: white;
    padding: 11px 0; border-radius: 9px; cursor: pointer;
    font-size: 13px; font-weight: 650;
}
#btnCall { display: none !important; }
.btn-call:hover { background: #166534; }
.btn-call:disabled { background: #ccc; cursor: not-allowed; }
.btn-hangup {
    grid-column: 3 / span 8;
    order: 4;
    background: #c92a3d; border: 1px solid #c92a3d; color: white;
    padding: 11px 0; border-radius: 9px; cursor: pointer;
    font-size: 13px; font-weight: 650; display: none;
    box-shadow: 0 2px 5px rgba(201,42,61,.18); transition: all .15s ease;
}
.btn-hangup:hover { background: #a91f31; border-color: #a91f31; }
.btn-hold, .btn-mute, .btn-record, .btn-keypad {
    grid-column: span 3;
    min-height: 54px; background: #fff; border: 1px solid #dfe5ec; color: #334155;
    padding: 8px 4px; border-radius: 9px; cursor: pointer;
    font-size: 11px; font-weight: 600; display: none;
    align-items: center; justify-content: center; flex-direction: column; gap: 4px;
    transition: all .15s ease;
}
.btn-hold:hover, .btn-mute:hover, .btn-record:hover, .btn-keypad:hover {
    background: #f8fafc; border-color: #b9c5d2; color: #0f3f79;
}
.btn-hold::before, .btn-mute::before, .btn-record::before, .btn-keypad::before {
    color: #64748b; font-size: 15px; font-weight: 700; line-height: 1;
}
#btnHold::before { content: "Ⅱ"; letter-spacing: -2px; }
#btnMute::before { content: "◉"; }
#btnRecord::before { content: "●"; color: #c92a3d; font-size: 12px; }
#btnKeypad::before { content: "⌨"; }
.btn-hold.active, .btn-mute.muted, .btn-keypad.active {
    background: #eef6ff; border-color: #8eb9e6; color: #0047ab;
}
.btn-mute.muted::before { color: #c92a3d; }
.btn-record {
    grid-column: span 3;
    cursor: default;
    pointer-events: none;
}
.btn-record.recording { background: #fff1f2; color: #9f1239; border-color: #fda4af; }
.btn-record.visible   { display: flex; }
.rec-dot { display: none; }
.btn-record.recording .rec-dot {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: #c92a3d; animation: pulse 1s infinite;
}
.call-timer {
    text-align: center; font-size: 22px; font-weight: bold;
    color: #0047AB; display: none; padding: 4px 0;
}
.btn-phone-action, .btn-transfer {
    min-height: 38px; background: #fff; border: 1px solid #dfe5ec; color: #334155;
    border-radius: 9px; padding: 9px 8px; cursor: pointer; display: none;
    font-size: 11px; font-weight: 600; transition: all .15s ease;
}
.btn-phone-action:hover, .btn-transfer:hover {
    background: #f8fafc; border-color: #9fb0c2; color: #0047ab;
}
.btn-phone-action { grid-column: span 6; order: 2; }
.btn-transfer { grid-column: span 12; margin-top: 0; order: 3; }
.btn-transfer.visible { display: block; }

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
    display: block; padding: 14px 22px 22px;
    background: #fff;
}
@keyframes fadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.dp-title {
    margin: 0 0 10px; color: #64748b; font-size: 10px; font-weight: 700;
    letter-spacing: 1.2px; text-align: center; text-transform: uppercase;
}
.dp-display {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 10px 38px 10px 14px; font-size: 20px; font-weight: 650; color: #0f3f79;
    text-align: center; letter-spacing: 2px; min-height: 46px; width: 100%;
    margin-bottom: 16px; box-sizing: border-box; outline: none;
    box-shadow: inset 0 1px 2px rgba(15,23,42,.03);
}
.dp-display:focus { border-color: #9dc5f0; }
.dp-display::placeholder { color: #94a3b8; font-size: 12px; letter-spacing: 0; font-weight: 500; }
.dp-grid {
    display: grid; grid-template-columns: repeat(3, 58px); gap: 10px 18px;
    justify-content: center; margin-bottom: 16px;
}
.dp-key {
    width: 58px; height: 58px; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 50%; padding: 0; font-size: 19px; font-weight: 650; color: #172b4d;
    cursor: pointer; text-align: center; transition: all .14s ease;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    line-height: 1.05; box-shadow: 0 2px 5px rgba(15,23,42,.06);
}
.dp-key:hover  { background: #eef6ff; border-color: #9dc5f0; color: #0047ab; transform: translateY(-1px); }
.dp-key:active { background: #dbeafe; transform: scale(.94); box-shadow: none; }
.dp-key .dp-sub {
    min-height: 8px; margin-top: 3px; color: #8290a5; font-size: 7px;
    font-weight: 700; letter-spacing: 1px;
}
.dp-row-actions {
    display: grid; grid-template-columns: 44px 1fr 44px; gap: 10px;
    align-items: center;
}
.dp-call {
    grid-column: 2; background: linear-gradient(135deg, #16a34a, #22c55e);
    color: white; border: none; border-radius: 24px; min-height: 44px;
    padding: 10px 18px; font-size: 14px; font-weight: 700; cursor: pointer;
    box-shadow: 0 5px 12px rgba(34,197,94,.22); transition: all .15s ease;
}
.dp-call:hover { transform: translateY(-1px); box-shadow: 0 7px 16px rgba(34,197,94,.3); }
.dp-del {
    grid-column: 3; grid-row: 1; width: 44px; height: 44px; background: transparent;
    color: #64748b; border: 1px solid #e2e8f0; border-radius: 50%;
    padding: 0; font-size: 18px; cursor: pointer; transition: all .14s ease;
}
.dp-del:hover { background: #fff1f2; border-color: #fecdd3; color: #e11d48; }

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
.rec-stop     { background: #c62828; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-left: 4px; }
.rec-stop:hover { background: #b71c1c; }
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
    grid-column: span 12;
}
.btn-transfer.visible { display: block; }

/* ── Hide old horizontal tab bar ─────────────────── */
.tab-bar { display: none !important; }

/* ── Left Sidebar ────────────────────────────────── */
#sidebarMenu {
    position: fixed;
    top: 64px;
    left: 0;
    width: 240px;
    height: calc(100vh - 64px);
    background: #ffffff;
    border-right: 1px solid #e2e8f0;
    box-shadow: 2px 0 12px rgba(0,71,171,0.07);
    z-index: 400;
    display: flex;
    flex-direction: column;
    transition: width 0.28s cubic-bezier(0.4,0,0.2,1), transform 0.28s cubic-bezier(0.4,0,0.2,1);
    overflow: hidden;
}
#sidebarMenu.collapsed { width: 0; }
@media (max-width: 768px) {
    #sidebarMenu {
        transform: translateX(-100%);
        width: 240px !important;
        z-index: 1001;
        box-shadow: 6px 0 24px rgba(0,0,0,0.18);
    }
    #sidebarMenu.mobile-open { transform: translateX(0); }
}

/* Sidebar section header */
.sb-section-label {
    font-size: 10px; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 1px;
    padding: 16px 20px 6px;
    white-space: nowrap;
}

/* Nav scroll area */
.sb-nav { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 8px 0; }
.sb-nav::-webkit-scrollbar { width: 3px; }
.sb-nav::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 2px; }

/* Nav items */
.sb-item {
    display: flex; align-items: center;
    padding: 10px 20px;
    color: #475569; font-size: 13.5px; font-weight: 500;
    border-left: 3px solid transparent;
    cursor: pointer;
    background: none; border-top: none; border-right: none; border-bottom: none;
    width: 100%; text-align: left; text-decoration: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
    white-space: nowrap;
    border-radius: 0;
    gap: 0;
    letter-spacing: 0.1px;
}
.sb-item:hover {
    background: #f0f5ff;
    border-left-color: #0047AB;
    color: #0047AB;
}
.sb-item.active {
    background: linear-gradient(90deg, #eef3ff 0%, #f8faff 100%);
    border-left-color: #0047AB;
    color: #0047AB;
    font-weight: 700;
}

/* Divider */
.sb-divider { height: 1px; background: #f1f5f9; margin: 6px 0; }

/* Footer */
.sb-footer { flex-shrink: 0; border-top: 1px solid #f1f5f9; padding: 8px 0; }
.sb-footer .sb-item { font-size: 13px; }
.sb-item.signout { color: #dc3545; }
.sb-item.signout:hover { background: #fff5f5; border-left-color: #dc3545; color: #dc3545; }

/* Mobile backdrop */
#sidebarBackdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.4); z-index: 1000;
}

</style>
</head>
<body>

<!-- ?? HEADER ?? -->
<div class="header">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="agent-sidebar-toggle" onclick="toggleAgentSideMenu()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;border-radius:8px;font-size:20px;cursor:pointer;line-height:1;flex-shrink:0">&#9776;</button>
        <div class="logo" onclick="switchTab('dashboard')" title="Go to Dashboard" style="cursor:pointer">SKY<span>KIN</span> Technologies</div>
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
                <div class="s-opt" id="optBreak" onclick="setAgentStatus('break')">
                    <span class="opt-dot" style="background:#0ea5e9"></span> On Break
                </div>
                <div class="s-opt" onclick="setAgentStatus('acw')">
                    <span class="opt-dot" style="background:#6366f1"></span> Wrap-up (ACW)
                </div>
                <div class="s-opt logout" id="optLogout" onclick="setAgentStatus('logout')">
                    <span class="opt-dot" style="background:#ef4444"></span> Logout
                </div>
                <div class="s-opt" id="optCancelLeave" style="display:none;color:#b45309" onclick="cancelPendingLeave()">
                    <span class="opt-dot" style="background:#f59e0b"></span> Cancel Leave Request
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Collapsible Left Sidebar ─────────────────────────── -->
<div id="sidebarMenu">
    <div class="sb-nav">
        <div class="sb-section-label">Menu</div>

        <button class="sb-item active" id="sbDashboardBtn"   onclick="sidebarNav('dashboard')">Dashboard</button>
        <button class="sb-item"        id="sbCallHistoryBtn" onclick="sidebarNav('callHistory')">Call History</button>
        <button class="sb-item"        id="sbRecordingsBtn"  onclick="sidebarNav('recordings')">Recordings</button>
        <button class="sb-item"        id="sbAcwBtn"         onclick="sidebarNav('acw')">ACW History</button>
        <button class="sb-item"        id="sbEscalationBtn"  onclick="sidebarNav('escalation')">New Ticket</button>
        <button class="sb-item"        id="sbLookupBtn"      onclick="sidebarNav('lookup')">Customer Lookup</button>
        <button class="sb-item"        id="sbCrmBtn"         onclick="sidebarNav('crm')">CRM</button>
        <button class="sb-item"        id="sbCallbacksBtn"   onclick="sidebarNav('callbacks')">Callbacks</button>
        <button class="sb-item"        id="sbBlacklistBtn"   onclick="sidebarNav('blacklist')">Blacklist</button>
        <button class="sb-item"        id="sbAhununuBtn"     onclick="sidebarNav('ahununu')">Ahununu.com</button>

        <?php if ($is_supervisor): ?>
        <div class="sb-divider"></div>
        <div class="sb-section-label">Management</div>
        <a class="sb-item" href="supervisor.php">Supervisor View</a>
        <?php endif; ?>
    </div>

    <div class="sb-footer">
        <button class="sb-item" onclick="document.getElementById('settingsModal').classList.add('show')">Phone Settings</button>
        <a class="sb-item signout" href="/logout.php">Sign Out</a>
    </div>
</div>

<!-- Mobile backdrop -->
<div id="sidebarBackdrop" onclick="toggleAgentSideMenu()"></div>


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
            <button class="tab-btn" id="tabCrmBtn" onclick="switchTab('crm')">CRM</button>
            <button class="tab-btn" id="tabCallbacksBtn" onclick="switchTab('callbacks')">Callbacks</button>
            <button class="tab-btn" id="tabBlacklistBtn" onclick="switchTab('blacklist')">Blacklist</button>
            <button class="tab-btn" id="tabAhununuBtn" onclick="switchTab('ahununu')">&#127760; Ahununu.com</button>
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
            <div class="wait-list">
                <div class="wait-list-title">Waiting customers</div>
                <div id="waitingCallers"><div class="wait-empty">No callers waiting</div></div>
                <div class="wait-note">Next caller is assigned automatically. This list is for visibility only.</div>
            </div>
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

        <!-- Blacklist Tab -->
        <div class="tab-panel" id="tabBlacklist" style="display:none">
            <div class="section-box" style="padding:16px">
                <div class="section-title"><span class="dot" style="background:#c62828"></span> Blocked callers</div>
                <p style="font-size:13px;color:#666;margin:0 0 12px">These numbers will not ring agents. Use Remove to allow them again.</p>
                <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
                    <input type="tel" id="blAddNumber" placeholder="Number e.g. 0902925776" style="flex:1;min-width:180px;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <input type="text" id="blAddReason" placeholder="Reason" style="flex:1;min-width:140px;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <button type="button" class="btn-filter" onclick="addBlacklistManual()">Block number</button>
                </div>
                <table class="data-table" style="width:100%">
                    <thead><tr><th>Number</th><th>Reason</th><th>Added by</th><th></th></tr></thead>
                    <tbody id="blacklistBody"><tr><td colspan="4" class="rec-empty">Loading…</td></tr></tbody>
                </table>
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

        <!-- CRM Tab -->
        <div class="tab-panel" id="tabCrm" style="display:none;padding:0">
            <iframe src="about:blank" id="crmTabFrame" title="CRM" style="width:100%;height:700px;border:none"></iframe>
        </div>

        <!-- ?? Ahununu.com Tab ?? -->
        <div class="tab-panel" id="tabAhununu" style="display:none;padding:0">
            <iframe src="about:blank" id="ahununuFrame" style="width:100%;height:700px;border:none" allow="camera;microphone"></iframe>
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
            <input type="text" id="sipServer" placeholder="same as this site" value="<?php echo htmlspecialchars(skykin_config()['sip_server']); ?>">
        </div>
        <div class="form-group">
            <label>WebSocket Port (default 5066)</label>
            <input type="text" id="sipPort" placeholder="5066" value="5066">
        </div>
        <div class="form-group">
            <label>Domain</label>
            <input type="text" id="sipDomain" placeholder="SIP domain" value="<?php echo $domain; ?>">
        </div>
        <button class="btn-save-settings" onclick="saveSipSettings()">Connect</button>
    </div>
</div>

<!-- Leave request modal (Break needs supervisor approval) -->
<div class="modal-overlay" id="leaveRequestModal">
    <div class="modal-box">
        <div class="modal-title" id="leaveRequestTitle">Request Leave</div>
        <p style="font-size:13px;color:#555;margin:0 0 14px;line-height:1.4">
            Your status stays <strong>Available</strong> until a supervisor approves.
        </p>
        <input type="hidden" id="leaveRequestType" value="">
        <div class="form-group">
            <label>Reason (optional)</label>
            <input type="text" id="leaveRequestReason" placeholder="e.g. lunch, end of shift" maxlength="200">
        </div>
        <div style="display:flex;gap:10px;margin-top:8px">
            <button class="btn-save-settings" style="flex:1;background:#64748b" onclick="closeLeaveRequestModal()">Cancel</button>
            <button class="btn-save-settings" style="flex:1" onclick="submitLeaveRequest()">Send Request</button>
        </div>
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
        <div id="sipCertHint" style="display:none"></div>
        <div class="pp-header-actions">
            <button class="btn-settings-header" onclick="document.getElementById('settingsModal').classList.add('show')" title="Phone settings" aria-label="Phone settings">&#9881;</button>
            <button class="pp-close" onclick="togglePhonePopup()" title="Close phone panel">&#x2715;</button>
        </div>
    </div>
    <div class="pp-body">
        <!-- Incoming call screen (shown instead of dialpad when call arrives) -->
        <div id="incomingScreen" style="display:none; text-align:center; padding:24px 16px;">
            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">&#128222; Incoming Call</div>
            <div id="incomingNumber" style="font-size:28px;font-weight:bold;color:#0047AB;margin-bottom:8px">Unknown</div>
            <div style="font-size:12px;color:#666;margin-bottom:24px" id="incomingCidName"></div>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <button onclick="answerCall()" style="background:#28a745;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Answer</button>
                <button onclick="declineCall()" style="background:#dc3545;color:#fff;border:none;padding:14px 32px;border-radius:30px;font-size:15px;font-weight:bold;cursor:pointer;flex:1">Decline</button>
            </div>
            <button type="button" onclick="blockCurrentCaller()" style="margin-top:14px;background:#fff;color:#c62828;border:1px solid #ef9a9a;padding:8px 16px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer">Block this number</button>
        </div>
        <div id="callTimer" class="call-timer">00:00</div>
        <div class="call-controls">
            <button class="btn-call"   id="btnCall"   onclick="makeCall()" disabled style="display:none">&#128222; Call</button>
            <button class="btn-hangup" id="btnHangup" onclick="hangupCall()">End call</button>
            <button class="btn-hold"   id="btnHold"   onclick="toggleHold()">Hold</button>
            <button class="btn-mute"   id="btnMute"   onclick="toggleMute()">Mute</button>
            <button class="btn-record" id="btnRecord" type="button" title="Calls are recorded automatically" disabled>
                <span class="rec-dot"></span> REC
            </button>
            <button class="btn-keypad" id="btnKeypad" onclick="toggleCallKeypad()">Keypad</button>
            <button class="btn-phone-action" id="btnPhoneSms" onclick="openSmsModalFromPhone()">Message</button>
            <button class="btn-phone-action" id="btnPhoneCallback" onclick="openCallbackModalFromPhone()">Callback</button>
            <button class="btn-phone-action" id="btnBlockCaller" onclick="blockCurrentCaller()">Block</button>
            <button class="btn-transfer" id="btnTransfer" onclick="openTransferModal()">Transfer call</button>
        </div>
    </div>

    <!-- Inline Dial Pad -->
    <div class="dp-panel" id="dpPanel">
        <div class="dp-title">Dial a number</div>
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
            <button class="dp-call" onclick="dpCall()" title="Start call">&#128222;&nbsp; Call</button>
            <button class="dp-del" onclick="dpDelete()" title="Delete last digit" aria-label="Delete last digit">&#9003;</button>
        </div>
    </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/socket.io-client@4.8.1/dist/socket.io.min.js"></script>
<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
<script>
const agentName  = '<?php echo $agent_name; ?>';
const domain     = '<?php echo $domain; ?>';
const serverExt  = '<?php echo $agent_ext; ?>';       // resolved server-side from DB
const serverPass = '<?php echo $agent_password; ?>';   // SIP password from DB
const serverWss  = '<?php echo $agent_wss; ?>';        // WSS server URL

// Auto-configure SIP from server on every page load ? no manual setup needed
if (serverExt)  localStorage.setItem('sip_ext',  serverExt);
if (serverPass) localStorage.setItem('sip_pass', serverPass);
localStorage.setItem('sip_server', (window.SKYKIN && SKYKIN.sipServer) || location.hostname);
localStorage.setItem('sip_domain', domain || ((window.SKYKIN && SKYKIN.domain) || location.hostname));
localStorage.removeItem('sip_port');
let loginTime   = new Date();
let refreshInterval = 10;
let countdown   = refreshInterval;

// Background API failures must never end the session. Sending the browser to
// login.php?switch=1 destroyed the session server-side, so one bad poll (tab
// sleep, FPM saturation) logged the agent out for real.
(function() {
    const _fetch = window.fetch.bind(window);
    let authFailCount = 0;
    window.fetch = function() {
        const args = arguments;
        const init = args[1] || {};
        if (!init.credentials) {
            args[1] = Object.assign({}, init, { credentials: 'same-origin' });
        }
        return _fetch.apply(this, args).then(function(res) {
            if (res.status === 401) {
                if (++authFailCount >= 4) {
                    authFailCount = 0;
                    window.location = '/login.php?expired=1';
                }
            } else {
                authFailCount = 0;
            }
            return res;
        }).catch(function(err) {
            // network/abort errors say nothing about auth
            throw err;
        });
    };
})();

// A single uncaught error aborts the rest of the script and silently breaks the
// softphone, so report crashes to the server instead of only the console.
(function() {
    function report(payload) {
        try {
            fetch('index.php?action=client_log', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).catch(function(){});
        } catch (e) {}
    }
    window.addEventListener('error', function(e) {
        report({
            event: 'js_error',
            name: (e.error && e.error.name) || 'Error',
            message: e.message || '',
            extra: (e.filename || '') + ':' + (e.lineno || 0) + ':' + (e.colno || 0),
            ext: localStorage.getItem('sip_ext') || ''
        });
    });
    window.addEventListener('unhandledrejection', function(e) {
        const r = e.reason || {};
        report({
            event: 'js_rejection',
            name: r.name || '',
            message: r.message || String(r),
            extra: '',
            ext: localStorage.getItem('sip_ext') || ''
        });
    });
})();

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

// ?? Status Dropdown + leave-request approval ?????????
let currentAgentStatus = 'ready';
let pendingLeaveRequest = null;
let leavePollTimer = null;
let leaveHandledId = null;

function toggleStatusMenu() {
    document.getElementById('statusDropMenu').classList.toggle('open');
}

const FPBX_STATUS_MAP = {
    ready:  'Available',
    idle:   'Available',
    break:  'On Break',
    acw:    'On Break',
    logout: 'Logged Out',
    incall: 'Available',
};

function agentDisplayName() {
    return <?php echo json_encode($agent_name); ?>;
}

function applyStatusUi(status, labelOverride) {
    const labels = { ready:'Available', idle:'Idle', break:'On Break', acw:'Wrap-up (ACW)', logout:'Logged Out', incall:'On Call', pending:'Pending Leave' };
    const colors  = { ready:'#10b981', idle:'#64748b', break:'#0ea5e9', acw:'#6366f1', logout:'#ef4444', incall:'#f59e0b', pending:'#f59e0b' };
    document.getElementById('statusLabel').textContent = labelOverride || labels[status] || status;
    const dot = document.getElementById('statusDot');
    dot.className = 'sdot ' + status;
    dot.style.background = colors[status] || '#888';
}

function updateLeaveMenuState() {
    const pending = pendingLeaveRequest && pendingLeaveRequest.status === 'pending';
    const optBreak = document.getElementById('optBreak');
    const optCancel = document.getElementById('optCancelLeave');
    if (optBreak) { optBreak.style.opacity = pending ? '0.4' : '1'; optBreak.style.pointerEvents = pending ? 'none' : ''; }
    // Logout is never gated by leave approval
    if (optCancel) optCancel.style.display = pending ? 'block' : 'none';
}

function openLeaveRequestModal(requestType) {
    document.getElementById('leaveRequestType').value = requestType || 'On Break';
    document.getElementById('leaveRequestReason').value = '';
    document.getElementById('leaveRequestTitle').textContent = 'Request Break';
    document.getElementById('leaveRequestModal').classList.add('show');
}

function closeLeaveRequestModal() {
    document.getElementById('leaveRequestModal').classList.remove('show');
}

function submitLeaveRequest() {
    const requestType = document.getElementById('leaveRequestType').value;
    const reason = document.getElementById('leaveRequestReason').value.trim();
    const agentExt = localStorage.getItem('sip_ext') || serverExt || '';
    if (!agentExt) {
        showToast('No extension configured — open Phone Settings first.');
        return;
    }
    fetch('index.php?action=request_leave', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            agent_ext: agentExt,
            agent_name: agentDisplayName(),
            domain: domain,
            request_type: requestType,
            reason: reason
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            showToast(data.error || 'Could not send leave request');
            return;
        }
        closeLeaveRequestModal();
        pendingLeaveRequest = { id: data.id, request_type: requestType, status: 'pending', reason: reason };
        leaveHandledId = null;
        applyStatusUi('pending', 'Pending Leave');
        updateLeaveMenuState();
        showToast('Leave request sent — waiting for supervisor approval');
        startLeavePolling();
    })
    .catch(() => showToast('Could not send leave request'));
}

function cancelPendingLeave() {
    document.getElementById('statusDropMenu').classList.remove('open');
    const agentExt = localStorage.getItem('sip_ext') || serverExt || '';
    if (!agentExt || !pendingLeaveRequest) return;
    fetch('index.php?action=cancel_leave_request', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ agent_ext: agentExt, domain: domain, id: pendingLeaveRequest.id || 0 })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast(data.error || 'Could not cancel request'); return; }
        pendingLeaveRequest = null;
        applyStatusUi('ready');
        currentAgentStatus = 'ready';
        updateLeaveMenuState();
        showToast('Leave request cancelled');
    })
    .catch(() => showToast('Could not cancel request'));
}

function syncStatusToFpbx(fpbxStatus) {
    const agentExt = localStorage.getItem('sip_ext') || serverExt || '';
    if (!agentExt) {
        console.warn('[Status] Cannot sync to FusionPBX: no extension configured.');
        return;
    }
    fetch('index.php?action=set_agent_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ agent_ext: agentExt, new_status: fpbxStatus, domain: domain })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            showToast('\u26A0 FusionPBX status update failed: ' + (data.error || 'unknown error'));
            return;
        }
        if (data.esl_connected && data.esl_response && data.esl_response.toLowerCase().includes('err')) {
            showToast('\u26A0 ESL command sent but FreeSWITCH returned: ' + data.esl_response);
        } else if (data.esl_error) {
            showToast('\u26A0 DB updated to "' + fpbxStatus + '" but FreeSWITCH ESL unreachable.');
        }
    })
    .catch(() => showToast('\u26A0 Could not reach local server to sync status to FusionPBX.'));
}

function handleResolvedLeave(req) {
    if (!req || !req.id) return;
    if (leaveHandledId === String(req.id)) return;
    leaveHandledId = String(req.id);
    pendingLeaveRequest = null;
    updateLeaveMenuState();
    stopLeavePolling();
    startLeavePolling(); // keep light polling for future requests

    if (req.status === 'denied') {
        applyStatusUi('ready');
        currentAgentStatus = 'ready';
        showToast('Leave request denied by supervisor — you remain Available');
        return;
    }
    if (req.status === 'cancelled') {
        applyStatusUi('ready');
        currentAgentStatus = 'ready';
        return;
    }
    if (req.status === 'approved') {
        applyStatusUi('break');
        currentAgentStatus = 'break';
        showToast('Break approved by supervisor');
    }
}

function pollMyLeaveRequest() {
    const agentExt = localStorage.getItem('sip_ext') || serverExt || '';
    if (!agentExt) return;
    fetch('index.php?action=my_leave_request&agent_ext=' + encodeURIComponent(agentExt) +
          '&domain=' + encodeURIComponent(domain))
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const req = data.request;
            if (!req) {
                if (pendingLeaveRequest) {
                    pendingLeaveRequest = null;
                    applyStatusUi('ready');
                    currentAgentStatus = 'ready';
                    updateLeaveMenuState();
                }
                return;
            }
            if (req.status === 'pending') {
                pendingLeaveRequest = req;
                applyStatusUi('pending', 'Pending Leave');
                updateLeaveMenuState();
                return;
            }
            const isFresh = req.resolved_at && (Date.now() - new Date(req.resolved_at.replace(' ', 'T')).getTime()) < 120000;
            if (pendingLeaveRequest || (isFresh && leaveHandledId !== String(req.id))) {
                handleResolvedLeave(req);
            }
        })
        .catch(() => {});
}

function startLeavePolling() {
    if (leavePollTimer) return;
    leavePollTimer = setInterval(pollMyLeaveRequest, 5000);
}

function stopLeavePolling() {
    if (leavePollTimer) { clearInterval(leavePollTimer); leavePollTimer = null; }
}

function setAgentStatus(status) {
    document.getElementById('statusDropMenu').classList.remove('open');

    // Break requires supervisor approval — stay Available until then
    if (status === 'break') {
        if (pendingLeaveRequest && pendingLeaveRequest.status === 'pending') {
            showToast('You already have a pending leave request');
            return;
        }
        openLeaveRequestModal('On Break');
        return;
    }

    // Logout is immediate — no supervisor approval
    if (status === 'logout') {
        currentAgentStatus = 'logout';
        applyStatusUi('logout');
        syncStatusToFpbx('Logged Out');
        showToast('Signing out…');
        setTimeout(function() { window.location = '/logout.php'; }, 600);
        return;
    }

    currentAgentStatus = status;
    applyStatusUi(status);
    syncStatusToFpbx(FPBX_STATUS_MAP[status] || 'Available');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.status-drop-wrap')) {
        document.getElementById('statusDropMenu').classList.remove('open');
    }
});

pollMyLeaveRequest();
startLeavePolling();
document.getElementById('leaveRequestModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeLeaveRequestModal();
});

// ── Sidebar toggle & navigation ─────────────────────────────
function toggleAgentSideMenu() {
    const sidebar  = document.getElementById('sidebarMenu');
    const backdrop = document.getElementById('sidebarBackdrop');
    const main     = document.querySelector('.main');
    if (!sidebar) return;

    const isMobile = window.innerWidth <= 768;
    if (isMobile) {
        const isOpen = sidebar.classList.contains('mobile-open');
        sidebar.classList.toggle('mobile-open', !isOpen);
        if (backdrop) backdrop.style.display = isOpen ? 'none' : 'block';
    } else {
        const isCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed', !isCollapsed);
        if (main) main.classList.toggle('sidebar-collapsed', !isCollapsed);
        try { localStorage.setItem('sidebarState', isCollapsed ? 'expanded' : 'collapsed'); } catch(e) {}
    }
}

function sidebarNav(tab) {
    // Activate tab content
    switchTab(tab);
    // Update sidebar active state
    const tabToSb = {
        dashboard: 'sbDashboardBtn', callHistory: 'sbCallHistoryBtn',
        recordings: 'sbRecordingsBtn', acw: 'sbAcwBtn',
        escalation: 'sbEscalationBtn', lookup: 'sbLookupBtn', crm: 'sbCrmBtn',
        callbacks: 'sbCallbacksBtn', blacklist: 'sbBlacklistBtn', ahununu: 'sbAhununuBtn'
    };
    document.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
    const activeBtn = document.getElementById(tabToSb[tab]);
    if (activeBtn) activeBtn.classList.add('active');
    // On mobile close sidebar after navigation
    if (window.innerWidth <= 768) {
        const sidebar  = document.getElementById('sidebarMenu');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (sidebar)  sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.style.display = 'none';
    }
}

// Restore sidebar state on page load
(function initSidebar() {
    try {
        const saved = localStorage.getItem('sidebarState');
        if (saved === 'collapsed') {
            const sidebar = document.getElementById('sidebarMenu');
            const main    = document.querySelector('.main');
            if (sidebar) sidebar.classList.add('collapsed');
            if (main)    main.classList.add('sidebar-collapsed');
        }
    } catch(e) {}
})();

function agentCrmUrl() {
    const d = (window.SKYKIN && SKYKIN.domain) ? SKYKIN.domain : (typeof domain !== 'undefined' ? domain : '');
    const params = new URLSearchParams();
    params.set('embed', '1');
    if (d) params.set('domain', d);
    return '/app/agent_dashboard/crm.php?' + params.toString();
}

// ?? Customer info panel (ahununu.com) ??????????????
// Slides in over the dashboard during a call; the Ahununu tab is the
// full-size view for browsing between calls.
function openCrmPanel(url) {
    const target = url || agentCrmUrl();
    if (target.indexOf('crm.php') !== -1) {
        switchTab('crm');
        sidebarNav('crm');
        const frame = document.getElementById('crmTabFrame');
        if (frame) frame.src = target;
        return;
    }
    const panel = document.getElementById('crmPanel');
    const frame = document.getElementById('crmFrame');
    if (!panel || !frame) return;
    frame.src = target;
    panel.classList.add('open');
}

function closeCrmPanel() {
    const panel = document.getElementById('crmPanel');
    if (panel) panel.classList.remove('open');
}

// ?? Tabs ???????????????????????????????????????????
function switchTab(tab) {
    ['dashboard','callHistory','recordings','acw','escalation','lookup','crm','callbacks','blacklist','ahununu'].forEach(t => {
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
    if (tab === 'blacklist')  fetchBlacklist();
    if (tab === 'crm') {
        const f = document.getElementById('crmTabFrame');
        if (f && (f.src === 'about:blank' || !f.src)) f.src = agentCrmUrl();
    }
    if (tab === 'ahununu') {
        const f = document.getElementById('ahununuFrame');
        if (f && f.src === 'about:blank') f.src = (window.SKYKIN && SKYKIN.ahununuUrl) || 'https://ahununu.com/';
    }
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

// Dial pad doubles as a DTMF keypad during an active call.
let dpNumber = '';
function dpKey(k) {
    if (callStartTime && sipBridge.sendDtmf) {
        try { sipBridge.sendDtmf(k); } catch(e) {}
        return;
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
function updateDpDisplay() {
    const inp = document.getElementById('dialInput');
    if (inp && inp.value !== dpNumber) inp.value = dpNumber;
}
function dpCall() {
    const num = document.getElementById('dialInput').value.trim() || dpNumber;
    if (!num) return;
    makeCall(num);
}
function toggleCallKeypad() {
    const panel = document.getElementById('dpPanel');
    const button = document.getElementById('btnKeypad');
    const opening = panel.style.display === 'none';
    panel.style.display = opening ? 'block' : 'none';
    button.classList.toggle('active', opening);
}

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
                <button class="rec-play" data-rec="${encodeURIComponent(r.filepath)}" onclick="toggleRecording(this)">&#9654; Play</button>
                <button class="rec-stop" onclick="stopRecording()" title="Stop">&#9632; Stop</button>
                <a href="${r.filepath}" download>
                    <button class="rec-download">&#8595; Save</button>
                </a>
            </td>
        </tr>`;
    });
    document.getElementById('recordingsBody').innerHTML = html;
}

let recAudio = null;
let recPlayBtn = null;

function stopRecording() {
    if (recAudio) {
        try { recAudio.pause(); recAudio.currentTime = 0; } catch (e) {}
        recAudio = null;
    }
    recPlayBtn = null;
}

function toggleRecording(btn) {
    const path = decodeURIComponent(btn.getAttribute('data-rec') || '');
    stopRecording();
    if (!path) return;
    recPlayBtn = btn;
    recAudio = new Audio(path);
    recAudio.addEventListener('ended', stopRecording);
    recAudio.addEventListener('error', function() {
        const code = recAudio && recAudio.error ? recAudio.error.code : 0;
        showToast(code === 4 ? 'Recording file is missing or unreadable' : 'Playback failed');
        stopRecording();
    });
    recAudio.play().catch(function(e) {
        showToast('Playback blocked: ' + (e.name || e.message));
        stopRecording();
    });
}

function playRecording(path) {
    const btn = document.querySelector('.rec-play[data-rec="' + encodeURIComponent(path) + '"]');
    if (btn) { toggleRecording(btn); return; }
    stopRecording();
    recAudio = new Audio(path);
    recAudio.addEventListener('ended', stopRecording);
    recAudio.play().catch(function(e) {
        showToast('Playback blocked: ' + (e.name || e.message));
    });
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
        recent_calls:[], waiting_callers:[]
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
                            c.status==='Failed'      ? 'badge-failed' :
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

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
}

function renderWaitingCallers(list) {
    const el = document.getElementById('waitingCallers');
    if (!el) return;
    if (!list.length) {
        el.innerHTML = '<div class="wait-empty">No callers waiting</div>';
        return;
    }
    el.innerHTML = list.map(function(c, i) {
        const number = escHtml(c.number || 'Unknown');
        const wait = escHtml(c.wait_fmt || (c.wait_seconds || 0) + 's');
        const state = escHtml(c.state || 'Waiting');
        return '<div class="wait-row">'
            + '<span class="wait-pos">' + (i + 1) + '</span>'
            + '<span class="wait-num">' + number + '</span>'
            + '<span class="wait-meta"><span class="wait-time">' + wait + '</span>'
            + '<span class="wait-state">' + state + '</span></span></div>';
    }).join('');
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
    renderWaitingCallers(d.waiting_callers || []);

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
let acwCallerId = '', acwDuration = 0, acwCallType = 'Outbound', acwRecordingFilename = '';

// SIP.js module will populate window.sipBridge when loaded
window.sipBridge = {}; var sipBridge = window.sipBridge;

// Same-origin /wss/ so the browser reuses the dashboard certificate.
// Chrome refuses WSS on :5060 (ERR_UNSAFE_PORT).
function buildSipWsUrl(host, port) {
    const cleanHost = String(host || location.hostname)
        .replace(/^wss?:\/\//i, '').replace(/\/.*$/, '').replace(/:\d+$/, '');
    const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
    const pagePort = location.port ? (':' + location.port) : '';
    return scheme + (cleanHost || location.hostname) + pagePort + '/wss/';
}

function resolveSipDomain() {
    const serverDom = ('<?php echo $domain; ?>' || '').trim();
    const saved     = (localStorage.getItem('sip_domain') || '').trim();
    const isIp = s => /^\d{1,3}(\.\d{1,3}){3}$/.test(s);
    // Extensions live under the SIP domain (FQDN). A bare IP saved from an
    // earlier attempt causes 403 (sip:ext@IP has no such user), so prefer the
    // server-provided FQDN and discard a stale IP / host-only value.
    let dom = serverDom;
    if (isIp(serverDom) || serverDom === '') {
        dom = (saved && !isIp(saved) && saved !== location.hostname) ? saved : (serverDom || saved || location.hostname);
    }
    if (dom && dom !== saved) localStorage.setItem('sip_domain', dom);
    return dom;
}

function loadSipSettings() {
    const ext  = localStorage.getItem('sip_ext')  || serverExt  || '';
    const pass = localStorage.getItem('sip_pass') || serverPass || '';
    const dom  = resolveSipDomain();
    
    // Retrieve stored server or default to hostname
    const rawServer = localStorage.getItem('sip_server') || location.hostname;
    const cleanHost = rawServer.replace(/^wss?:\/\//i,'').replace(/\/.*$/,'').replace(/:\d+$/,'');
    
    const port  = location.port || (location.protocol === 'https:' ? '443' : '80');
    const wsUrl = buildSipWsUrl(cleanHost, port);

    document.getElementById('sipExt').value    = ext;
    document.getElementById('sipPass').value   = pass;
    document.getElementById('sipServer').value = cleanHost;
    document.getElementById('sipPort').value   = port;
    document.getElementById('sipDomain').value = dom;
    
    if (ext && pass) waitForSipBridge(() => initSIP(ext, pass, wsUrl, '', dom));
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState !== 'visible') return;
        const text = (document.getElementById('sipStatusText') || {}).textContent || '';
        if (/Connecting|Failed|Not Registered|accept the warning/i.test(text) && ext && pass) {
            waitForSipBridge(() => initSIP(ext, pass, wsUrl, '', dom));
        }
    });
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
    const port      = document.getElementById('sipPort').value.trim() || location.port || '';
    const wsUrl     = buildSipWsUrl(cleanHost, port);
    localStorage.setItem('sip_ext',    ext);
    localStorage.setItem('sip_pass',   pass);
    localStorage.setItem('sip_server', cleanHost);
    localStorage.setItem('sip_port',   port || location.port || '');
    localStorage.setItem('sip_domain', dom);
    document.getElementById('settingsModal').classList.remove('show');
    waitForSipBridge(() => initSIP(ext, pass, wsUrl, port, dom));
}

function initSIP(ext, pass, server, port, dom) {
    setSipStatus('connecting', 'Connecting to phone…');
    if (sipBridge.init) {
        try {
            sipBridge.init(ext, pass, server, port, dom);
        } catch (e) {
            setSipStatus('failed', 'Phone error: ' + (e && e.message ? e.message : e));
        }
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
    const popup = document.getElementById('phonePopup');
    popup.classList.toggle('call-active', state === 'calling' || state === 'incall');
    dot.className = 'sip-dot'; badge.className = 'fab-badge'; fab.className = 'phone-fab';
    if (state === 'registered') {
        dot.classList.add('registered'); badge.classList.add('show');
        document.getElementById('btnCall').disabled = false;
        setAgentStatus('ready');
        const hintOk = document.getElementById('sipCertHint');
        if (hintOk) hintOk.style.display = 'none';
    } else if (state === 'calling') {
        dot.classList.add('calling'); badge.classList.add('show','calling');
        fab.classList.add('ringing'); openPhonePopup();
        // Outbound ring: status only — timer starts in startCallUI when answered.
        document.getElementById('btnCall').style.display   = 'none';
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
        openPhonePopup();
        document.getElementById('callTimer').style.display = 'none';
    } else if (state === 'connecting') {
        dot.classList.add('connecting');
    } else if (state === 'unregistered' || state === 'failed') {
        dot.classList.add('failed'); badge.classList.add('show','unreg');
        document.getElementById('btnCall').disabled = true;
        if (currentAgentStatus !== 'logout') {
            syncStatusToFpbx('Logged Out');
        }
    }
    document.getElementById('sipStatusText').textContent = text;
}

function crmDisplayName(contact) {
    if (!contact || !contact.full_name) return '';
    const n = String(contact.full_name).trim();
    if (contact.contact_id) return n;
    if (/^Customer\s*\(/i.test(n)) return '';
    return n;
}

function applyCrmNameToCallUi(phone, contact) {
    const num = String(phone || '').trim();
    const name = crmDisplayName(contact);
    const incEl = document.getElementById('incomingNumber');
    const cidEl = document.getElementById('incomingCidName');
    if (incEl) incEl.textContent = name || num || 'Unknown';
    if (cidEl) cidEl.textContent = (name && num) ? num : '';
    if (!name) return;
    const st = ((document.getElementById('sipStatusText') || {}).textContent || '');
    if (!/ringing|calling|in call/i.test(st)) return;
    let mode = 'incall';
    if (/ringing/i.test(st)) mode = 'ringing';
    else if (/calling/i.test(st)) mode = 'calling';
    const label = mode === 'ringing' ? 'Ringing' : (mode === 'calling' ? 'Calling' : 'In Call');
    setSipStatus(mode, label + ': ' + name);
}

function fetchCrmContact(phone, cb) {
    const q = String(phone || '').trim();
    if (!q || q === 'Unknown') {
        if (cb) cb(null);
        return;
    }
    fetch('crm.php?api=lookup&phone=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function(res) { return res.json(); })
        .then(function(c) {
            applyCrmNameToCallUi(q, c);
            if (cb) cb(c);
        })
        .catch(function() { if (cb) cb(null); });
}
window.fetchCrmContact = fetchCrmContact;

function showIncomingRingUi(callerNumber) {
    lastCallType = 'Inbound';
    window.lastIncomingNumber = callerNumber || '';
    window._callEnded = false;
    window._inboundRingActive = true;
    try { document.getElementById('acwModal').classList.remove('show'); } catch (e) {}
    const num = callerNumber || 'Unknown';
    document.getElementById('incomingNumber').textContent = num;
    const cidEl = document.getElementById('incomingCidName');
    if (cidEl) cidEl.textContent = '';
    document.getElementById('incomingScreen').style.display = 'block';
    document.getElementById('dpPanel').style.display = 'none';
    document.getElementById('callTimer').style.display = 'none';
    const ctrls = document.querySelector('#phonePopup .call-controls');
    if (ctrls) ctrls.style.display = 'none';
    const popup = document.getElementById('phonePopup');
    if (popup) popup.classList.add('ringing-inbound');
    openPhonePopup();
    setSipStatus('ringing', 'Ringing: ' + num);
    startRingtone();
}

function clearIncomingRingUi() {
    window._inboundRingActive = false;
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = 'block';
    const popup = document.getElementById('phonePopup');
    if (popup) popup.classList.remove('ringing-inbound');
    const ctrls = document.querySelector('#phonePopup .call-controls');
    if (ctrls) ctrls.style.display = '';
    stopRingtone();
}

function handleIncoming(callerNumber) {
    showIncomingRingUi(callerNumber);
    if (callerNumber) {
        fetchCrmContact(callerNumber);
        if (window.performLookup) {
            performLookup(callerNumber);
            switchTab('lookup');
        }
    }
    // Re-pin ring UI after lookup tab paints (phone panel stays on top).
    setTimeout(function() {
        if (!window._inboundRingActive) return;
        if (window.skykinHasRingingInvite && !window.skykinHasRingingInvite()) {
            clearIncomingRingUi();
            return;
        }
        openPhonePopup();
        const popup = document.getElementById('phonePopup');
        if (popup) popup.classList.add('ringing-inbound');
        document.getElementById('incomingScreen').style.display = 'block';
        document.getElementById('dpPanel').style.display = 'none';
        const ctrls = document.querySelector('#phonePopup .call-controls');
        if (ctrls) ctrls.style.display = 'none';
    }, 50);
}
window.handleIncoming = handleIncoming;

// A cancelled / missed ring must not open ACW or hide a newer incoming call.
function resetMissedRing() {
    try {
        if (window._inboundRingActive && session && session instanceof Invitation
            && session.state !== SessionState.Terminated) {
            return;
        }
        clearIncomingRingUi();
        const ext = localStorage.getItem('sip_ext') || '';
        setSipStatus('registered', 'Registered (' + ext + ')');
    } catch (e) {}
}
window.resetMissedRing = resetMissedRing;

function answerCall() {
    if (window.skykinHasRingingInvite && !window.skykinHasRingingInvite()) {
        showToast('No incoming call to answer.');
        clearIncomingRingUi();
        return;
    }
    document.getElementById('incomingOverlay').style.display = 'none';
    if (sipBridge.answer) sipBridge.answer();
    // Do not auto-open ahununu.com — agent opens it manually via the Ahununu tab
}

function declineCall() {
    clearIncomingRingUi();
    if (sipBridge.decline) sipBridge.decline();
    else if (sipBridge.hangup) sipBridge.hangup();
    currentSession = null;
    const ext = localStorage.getItem('sip_ext') || '';
    setSipStatus('registered', 'Registered (' + ext + ')');
}

function makeCall(number) {
    number = number || document.getElementById('dialInput').value.trim();
    if (!number) return;
    number = (window.skykinNormalizeEtDial && window.skykinNormalizeEtDial(number)) || number;
    document.getElementById('dialInput').value = number;
    lastDialedNumber = number;
    lastCallType = 'Outbound';
    fetchCrmContact(number);
    // Lookup tab opens when the call connects (startCallUI), not while ringing.
    if (sipBridge.makeCall) sipBridge.makeCall(number);
    else showToast('SIP not ready. Open Phone Settings to connect.');
}

// ?? Ringtone (Web Audio API ? no file needed) ??????????????????????????????
let _ringCtx = null, _ringNode = null, _ringInterval = null;
function startRingtone() {
    stopRingtone();
    // One context for the whole ring. Opening a fresh AudioContext per beep hit
    // the browser's per-page limit after a few cycles and the ringing went
    // silent partway through the call.
    try {
        _ringCtx = new (window.AudioContext || window.webkitAudioContext)();
        // Autoplay policy parks a new context in "suspended" until the page has
        // been interacted with; without this the ringtone is silent.
        if (_ringCtx.state === 'suspended') { _ringCtx.resume(); }
    } catch (e) { return; }
    function _ring() {
        if (!_ringCtx) return;
        try {
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

// ?? Ringback ? what the *caller* hears while the far end rings ???????????????
// FreeSWITCH does send ringback, but as early media on the 183 response, and
// attachAudio() only routes the remote stream to a speaker once the call is
// Established. So early media is never audible and the agent heard dead silence
// while waiting. Generate the tone locally instead, driven by the 180 Ringing.
let _rbCtx = null, _rbInterval = null;
function startRingback() {
    stopRingback();
    try {
        _rbCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (_rbCtx.state === 'suspended') { _rbCtx.resume(); }
    } catch (e) { return; }
    // One AudioContext reused for every pulse: browsers cap how many a page may
    // open, and a call can ring for 30s.
    function pulse() {
        if (!_rbCtx) return;
        try {
            const o = _rbCtx.createOscillator();
            const g = _rbCtx.createGain();
            o.connect(g); g.connect(_rbCtx.destination);
            o.type = 'sine'; o.frequency.value = 425;
            const t = _rbCtx.currentTime;
            // ~1s of tone, ramped at both ends so it does not click.
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.22, t + 0.05);
            g.gain.setValueAtTime(0.22, t + 0.95);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 1.0);
            o.start(t); o.stop(t + 1.05);
        } catch (e) {}
    }
    pulse();
    _rbInterval = setInterval(pulse, 3000);
}
function stopRingback() {
    if (_rbInterval) { clearInterval(_rbInterval); _rbInterval = null; }
    if (_rbCtx) { try { _rbCtx.close(); } catch(e) {} _rbCtx = null; }
}
window.stopRingback = stopRingback;
window.startRingback = startRingback;

function startCallUI(number) {
    number = number || window.lastDialedNumber || window.lastIncomingNumber || '';
    window._callEnded = false; // Reset so endCall() works for this new call
    window._outboundRingPhase = false;
    stopRingtone();
    stopRingback();
    setSipStatus('incall', 'In Call: ' + number);
    fetchCrmContact(number);
    document.getElementById('btnCall').style.display   = 'none';
    document.getElementById('btnHangup').style.display = 'block';
    document.getElementById('btnHold').style.display   = 'flex';
    document.getElementById('btnMute').style.display   = 'flex';
    // Recording starts automatically on every connected call — no agent action.
    isRecording = true;
    const btnRec = document.getElementById('btnRecord');
    btnRec.classList.add('visible', 'recording');
    btnRec.innerHTML = '<span class="rec-dot"></span> REC';
    document.getElementById('btnKeypad').style.display = 'flex';
    document.getElementById('callTimer').style.display = 'block';
    document.getElementById('dialInput').value = number;
    // Hide incoming screen if still showing (edge case)
    window._inboundRingActive = false;
    document.getElementById('phonePopup').classList.remove('ringing-inbound');
    document.getElementById('incomingScreen').style.display = 'none';
    document.getElementById('dpPanel').style.display = 'none';
    document.getElementById('btnKeypad').classList.remove('active');
    dpNumber = '';
    updateDpDisplay();
    callStartTime = new Date();
    callTimerInterval = setInterval(updateCallTimer, 1000);
    updateCallTimer();
    
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
    if (window._outboundRingPhase && window.endOutboundRing) {
        window.endOutboundRing(true);
        return;
    }
    if (sipBridge.hangup) sipBridge.hangup();
    // Immediately reset the UI — do not wait for the async SIP Terminated event
    endCall();
}

function currentCallerNumber() {
    const el = document.getElementById('incomingNumber');
    const incoming = ((el && el.textContent) || '').trim();
    if (incoming && incoming !== 'Unknown') return incoming;
    const last = String(window.lastIncomingNumber || '').trim();
    if (last && last !== 'Unknown') return last;
    const st = ((document.getElementById('sipStatusText') || {}).textContent || '');
    const m = st.match(/(\+?\d{7,15})/);
    if (m) return m[1];
    const wait = document.querySelector('.wait-num');
    if (wait && (wait.textContent || '').trim()) return wait.textContent.trim();
    return String(window.lastDialedNumber || '').trim();
}

function blockCurrentCaller() {
    const number = currentCallerNumber();
    if (!number) {
        showToast('No caller number to block');
        return;
    }
    if (!confirm('Block ' + number + '? They will not ring agents on this line again.')) return;
    declineCall();
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('ajax', '1');
    fd.append('number', number);
    fd.append('reason', 'blocked by agent');
    fd.append('domain', domain);
    fetch('block.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    }).then(r => r.json()).then(d => {
        if (d && d.ok) {
            showToast('Blocked ' + number);
            if (d.rows) renderBlacklist(d.rows);
            else fetchBlacklist();
        } else {
            showToast((d && d.error) || 'Call declined; could not save block');
        }
    }).catch(() => showToast('Call declined; could not save block'));
}

function renderBlacklist(rows) {
    const body = document.getElementById('blacklistBody');
    if (!body) return;
    rows = rows || [];
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4" class="rec-empty">No blocked numbers.</td></tr>';
        return;
    }
    body.innerHTML = rows.map(r =>
        '<tr><td>' + (r.display || r.digits) + '</td><td>' + (r.reason || '') + '</td><td>' + (r.agent || '') +
        '</td><td><button type="button" class="btn-filter-clear" onclick="delBlacklist(\'' +
        String(r.digits || '').replace(/'/g, '') + '\')">Remove</button></td></tr>'
    ).join('');
}

function fetchBlacklist() {
    fetch('blacklist.php?json=1&domain=' + encodeURIComponent(domain), { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            if (d && d.error && !(d.rows && d.rows.length)) {
                const body = document.getElementById('blacklistBody');
                if (body) body.innerHTML = '<tr><td colspan="4" class="rec-empty">' + d.error + '</td></tr>';
                return;
            }
            renderBlacklist((d && d.rows) || []);
        })
        .catch(err => {
            const body = document.getElementById('blacklistBody');
            if (body) body.innerHTML = '<tr><td colspan="4" class="rec-empty">Could not load blacklist</td></tr>';
        });
}

function addBlacklistManual() {
    const number = (document.getElementById('blAddNumber') || {}).value || '';
    const reason = (document.getElementById('blAddReason') || {}).value || 'blocked by agent';
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('ajax', '1');
    fd.append('number', number);
    fd.append('reason', reason);
    fd.append('domain', domain);
    fetch('blacklist.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    }).then(r => r.json()).then(d => {
        if (d && d.ok) {
            document.getElementById('blAddNumber').value = '';
            renderBlacklist((d && d.rows) || []);
            fetchBlacklist();
            showToast('Number blocked');
        } else showToast((d && d.error) || 'Could not block');
    });
}

function delBlacklist(number) {
    const fd = new FormData();
    fd.append('action', 'del');
    fd.append('ajax', '1');
    fd.append('number', number);
    fd.append('domain', domain);
    fetch('blacklist.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    }).then(r => r.json()).then(() => fetchBlacklist());
}

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

function toggleRecord() {
    // Recording is automatic. Kept as a no-op so old onclick handlers cannot break.
}

function markRecordingActive() {
    isRecording = true;
    const btn = document.getElementById('btnRecord');
    if (!btn) return;
    btn.classList.add('visible', 'recording');
    btn.innerHTML = '<span class="rec-dot"></span> REC';
}
window.markRecordingActive = markRecordingActive;

function endCall() {
    // Guard: prevent double-firing (hangupCall + SIP Terminated both call endCall)
    if (window._callEnded) return;
    window._callEnded = true;

    try { stopRingtone(); } catch(e) {}
    try { stopRingback(); } catch(e) {}
    // closeCrmPanel may not always be defined — guard it
    if (typeof closeCrmPanel === 'function') { try { closeCrmPanel(); } catch(e) {} }

    const callDur  = callStartTime ? Math.floor((new Date() - callStartTime) / 1000) : 0;
    const callerNum = lastDialedNumber || document.getElementById('dialInput').value || '';
    const recFile   = (window.recordingCallId || '') ? window.recordingCallId + '.webm' : '';

    // ── Critical UI reset (must always run) ────────────────────────────────
    try {
        currentSession = null; onHold = false; isRecording = false; isMuted = false;
        document.getElementById('phonePopup').classList.remove('call-active');
        clearInterval(callTimerInterval); callStartTime = null;
        document.getElementById('btnCall').style.display   = 'block';
        document.getElementById('btnHangup').style.display = 'none';
        document.getElementById('btnHold').style.display   = 'none';
        document.getElementById('btnMute').style.display   = 'none';
        document.getElementById('btnMute').textContent     = 'Mute';
        document.getElementById('btnMute').classList.remove('muted');
        document.getElementById('btnRecord').classList.remove('visible','recording');
        document.getElementById('btnKeypad').style.display = 'none';
        document.getElementById('btnKeypad').classList.remove('active');
        document.getElementById('btnRecord').innerHTML = '<span class="rec-dot"></span> REC';
        document.getElementById('btnHold').textContent = 'Hold';
        document.getElementById('btnHold').classList.remove('active');
        document.getElementById('callTimer').style.display = 'none';
        document.getElementById('callTimer').textContent   = '00:00';
        document.getElementById('incomingScreen').style.display = 'none';
        document.getElementById('dpPanel').style.display = 'block';
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
    acwRecordingFilename = recFilename || '';
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
    const url = (window.SKYKIN && SKYKIN.socketIoUrl) || '';
    if (!url) return; // optional helper service — disabled unless configured
    const socket = io(url, { transports: ['websocket','polling'] });
    socket.on('connect', () => showToast('Live events connected'));
    socket.on('call_bridged', function(data) {
        // CRM hint only — ring UI must come from the live SIP INVITE.
        if (!(window.skykinHasRingingInvite && window.skykinHasRingingInvite())) return;
        const callerNum = data.callerId || data.caller_id || '';
        lastCallType = 'Inbound';
        lastDialedNumber = callerNum;
        if (callerNum && window.fetchCrmContact) fetchCrmContact(callerNum);
        if (callerNum && window.performLookup) {
            performLookup(callerNum);
            switchTab('lookup');
        }
    });
    socket.on('call_ended', function() { endCall(); });
    socket.on('metrics_update', function() { fetchData(); });
})();

// ?? Event wiring ??????????????????????????????????
// btnAnswer/btnDecline live in the legacy overlay and no longer exist. Wiring
// them unguarded threw a TypeError that aborted the rest of this script, which
// left the dialpad and softphone half-initialised.
function bindEl(id, evt, fn) {
    const el = document.getElementById(id);
    if (el) el.addEventListener(evt, fn);
}

bindEl('btnAnswer', 'click', answerCall);
bindEl('btnDecline', 'click', declineCall);
bindEl('settingsModal', 'click', function(e) {
    if (e.target === this) this.classList.remove('show');
});
bindEl('acwModal', 'click', function(e) {
    if (e.target === this) closeAcwModal();
});
bindEl('dialInput', 'keypress', function(e) {
    if (e.key === 'Enter') makeCall();
});
bindEl('dialInput', 'input', function() {
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
                applyCrmNameToCallUi(query, contact);
                const cid = contact.contact_id ? Number(contact.contact_id) : 0;
                document.getElementById('lookupProfileBox').innerHTML = `
                    <div class="profile-title">${contact.full_name || 'Unknown Customer'}</div>
                    <div class="profile-item"><span>Phone:</span><span>${contact.phone || '-'}</span></div>
                    <div class="profile-item"><span>Alt Phone:</span><span>${contact.alt_phone || '-'}</span></div>
                    <div class="profile-item"><span>Email:</span><span>${contact.email || '-'}</span></div>
                    <div class="profile-item"><span>Company:</span><span>${contact.company || '-'}</span></div>
                    <div class="profile-item"><span>Language:</span><span>${contact.language || 'English'}</span></div>
                    <div class="profile-item"><span>Account Type:</span><span>${contact.account_type || 'Customer'}</span></div>
                    <div style="font-size:12px; margin-top:8px; color:#555; line-height:1.4;"><strong>Notes:</strong><br>${contact.notes || 'None'}</div>
                    <div style="display:flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
                        <button class="btn-filter" onclick="openSmsModal('${contact.phone}')" style="flex:1; padding: 6px; font-size:11px; min-width:90px;">SMS Update</button>
                        <button class="btn-filter" onclick="openCallbackModal('${contact.phone}', '${(contact.full_name || '').replace(/'/g, "\\'")}')" style="flex:1; padding: 6px; font-size:11px; background:#ffc107; color:#333; min-width:90px;">Schedule Callback</button>
                        ${cid ? `<button class="btn-filter" onclick="openCrmPanel()" style="flex:1; padding: 6px; font-size:11px; min-width:90px;">Open CRM</button>
                        <button class="btn-filter" onclick="deleteCrmContact(${cid})" style="flex:1; padding: 6px; font-size:11px; background:#fee2e2; color:#b91c1c; min-width:90px;">Delete Contact</button>` : ''}
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
window.performLookup = performLookup;

function deleteCrmContact(contactId) {
    if (!contactId) return;
    if (!confirm('Delete this CRM contact?')) return;
    const domain = (window.SKYKIN && SKYKIN.domain) ? SKYKIN.domain : '';
    fetch('crm.php?api=delete&id=' + encodeURIComponent(contactId) + '&domain=' + encodeURIComponent(domain), {
        credentials: 'same-origin'
    })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res || !res.ok) {
                showToast('Delete failed');
                return;
            }
            showToast('Contact deleted');
            const q = document.getElementById('lookupQuery').value.trim();
            if (q) performLookup(q);
            else clearLookup();
        })
        .catch(function() { showToast('Delete failed'); });
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
<script src="/app/agent_dashboard/js/sipjs.bundle.js?v=20260813m"></script>
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

window.skykinHasRingingInvite = function() {
    return session instanceof Invitation
        && session.state !== SessionState.Terminated
        && session.state !== SessionState.Terminating;
};

// SKYKIN_SAFE_DECLINE_v3
function stopOutboundPoll() {
    if (window._outboundPoll) { clearInterval(window._outboundPoll); window._outboundPoll = null; }
}
function resetOutboundRingUi() {
    stopOutboundPoll();
    window._outboundRingPhase = false;
    window._outSawBlegRing = false;
    window._outAnswerTicks = 0;
    window._outDeclineTicks = 0;
    window._outMaxCh = 0;
    window.stopRingback && window.stopRingback();
    window.stopRingtone && window.stopRingtone();
    try {
        document.getElementById('btnHangup').style.display = 'none';
        document.getElementById('btnCall').style.display = 'block';
        document.getElementById('phonePopup').classList.remove('call-active');
        clearInterval(callTimerInterval);
        callTimerInterval = null;
        callStartTime = null;
        document.getElementById('callTimer').style.display = 'none';
        document.getElementById('callTimer').textContent = '00:00';
    } catch (e) {}
    const ext = localStorage.getItem('sip_ext') || serverExt || '';
    window.setSipStatus && window.setSipStatus('registered', 'Registered (' + ext + ')');
}
function endOutboundRing(agentHangup) {
    const ext = localStorage.getItem('sip_ext') || serverExt || '';
    const dest = window._outboundPollDest || window.lastDialedNumber || '';
    fetch('index.php?action=outbound_stop&ext=' + encodeURIComponent(ext)
        + '&dest=' + encodeURIComponent(dest)
        + '&domain=' + encodeURIComponent(domain), { credentials: 'same-origin' }).catch(function() {});
    const s = session;
    try {
        if (s && !(s instanceof Invitation)
            && s.state !== SessionState.Terminated
            && s.state !== SessionState.Terminating) {
            try { s.bye(); } catch (e) { try { s.cancel && s.cancel(); } catch (e2) {} }
            try { s.dispose && s.dispose(); } catch (e) {}
        }
    } catch (e) {}
    if (session === s) {
        session = null;
    }
    if (agentHangup && callStartTime) {
        if (window.endCall) window.endCall();
    } else {
        resetOutboundRingUi();
    }
}
window.endOutboundRing = endOutboundRing;
function startOutboundPoll(ext, dest) {
    stopOutboundPoll();
    ext = ext || localStorage.getItem('sip_ext') || serverExt || '';
    dest = dest || window.lastDialedNumber || '';
    window._outboundPollDest = dest;
    window._outSawBlegRing = false;
    window._outAnswerTicks = 0;
    window._outDeclineTicks = 0;
    window._outMaxCh = 0;
    window._outboundPoll = setInterval(function() {
        if (!session || session instanceof Invitation) { stopOutboundPoll(); return; }
        if (session.state === SessionState.Terminated || session.state === SessionState.Terminating) {
            stopOutboundPoll();
            return;
        }
        if (!window._outboundRingPhase && callStartTime) { stopOutboundPoll(); return; }
        fetch('index.php?action=outbound_live&ext=' + encodeURIComponent(ext)
            + '&dest=' + encodeURIComponent(dest)
            + '&domain=' + encodeURIComponent(domain), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || d.ok === false) return;
                var bst = String(d.bleg_state || '').toUpperCase();
                var ch = (typeof d.channels === 'number') ? d.channels : 0;
                if (ch >= 2) {
                    window._outMaxCh = Math.max(window._outMaxCh || 0, ch);
                }
                if (d.bleg) {
                    window._outSawBlegRing = true;
                }
                var answered = !!(d.bleg && (bst === 'ACTIVE' || bst === 'ANSWER' || bst === 'EXECUTE'));
                if (answered && !callStartTime) {
                    window._outAnswerTicks = (window._outAnswerTicks || 0) + 1;
                    if (window._outAnswerTicks >= 2) {
                        window._outboundRingPhase = false;
                        stopOutboundPoll();
                        window.stopRingback && window.stopRingback();
                        enableSenders(session);
                        if (!session._skykinAudioAttached) {
                            attachAudio(session);
                        }
                        window.startCallUI && window.startCallUI(dest || window.lastDialedNumber || '');
                        window.showToast && window.showToast('Call connected');
                    }
                    return;
                }
                window._outAnswerTicks = 0;
                var partnerGone = window._outboundRingPhase && !answered && (
                    (window._outSawBlegRing && !d.bleg)
                    || (window._outMaxCh >= 2 && ch <= 1 && d.agent && !d.bleg)
                );
                if (partnerGone) {
                    window._outDeclineTicks = (window._outDeclineTicks || 0) + 1;
                    if (window._outDeclineTicks >= 1) {
                        endOutboundRing(false);
                    }
                } else if (!answered) {
                    window._outDeclineTicks = 0;
                }
            }).catch(function() {});
    }, 400);
}

// Chrome gathers ICE candidates from every network interface, and the agent PCs
// carry several virtual adapters (VMware/WSL/Hyper-V) whose STUN queries are
// never answered. SIP.js will not send an offer or an answer until gathering
// finishes, so those dead adapters stall the call for the full default timeout
// (5s) — once before the callee rings, and again after Answer is clicked.
// FreeSWITCH's default ICE ACL (wan.auto) rejects RFC1918 host candidates, so
// the offer must include a STUN srflx address or the answer is 488.
const ICE_GATHERING_TIMEOUT_MS = 3000;
const ICE_SERVERS = [
    { urls: 'stun:stun.cloudflare.com:3478' },
    { urls: 'stun:stun.l.google.com:19302' }
];
const ICE_PC_CONFIG = { iceServers: ICE_SERVERS };

function isVirtualHostIp(ip) {
    const p = ip.split('.').map(Number);
    if (p.length !== 4 || p.some(n => Number.isNaN(n))) return false;
    if (p[0] === 169 && p[1] === 254) return true;
    if (p[0] === 172 && p[1] >= 16 && p[1] <= 31) return true;
    if (p[0] === 192 && p[1] === 168 && (p[2] === 56 || p[2] === 59 || p[2] === 137 || p[2] === 211 || p[2] === 215 || p[2] === 221 || p[2] === 243)) return true;
    return false;
}

function stripVirtualNicModifier(description) {
    if (!description || !description.sdp) return Promise.resolve(description);
    const lines = description.sdp.split(/\r?\n/);
    const kept = lines.filter(function(line) {
        if (line.indexOf('a=candidate:') !== 0) return true;
        if (/\stcp\s/.test(line)) return false;
        const m = line.match(/\s(\d{1,3}(?:\.\d{1,3}){3})\s/);
        if (m && isVirtualHostIp(m[1])) return false;
        return true;
    });
    if (!kept.some(function(line) { return line.indexOf('a=candidate:') === 0; })) {
        return Promise.resolve(description);
    }
    description.sdp = kept.join('\r\n');
    if (!/\r\n$/.test(description.sdp)) description.sdp += '\r\n';
    return Promise.resolve(description);
}

// Keep rtpmap opus/48000/2 (Chrome rejects a /1 rewrite) but force mono in
// fmtp so FreeSWITCH's 1-channel Opus decoder actually gets frames.
function opusMonoFmtpModifier(description) {
    if (!description || !description.sdp) return Promise.resolve(description);
    description.sdp = description.sdp.replace(
        /^a=fmtp:(\d+) (.*)$/gm,
        function(line, pt, params) {
            if (!/minptime|useinbandfec|stereo/.test(params)) return line;
            if (/stereo=/.test(params)) {
                params = params.replace(/sprop-stereo=\d+/g, 'sprop-stereo=0')
                    .replace(/stereo=\d+/g, 'stereo=0');
                if (!/sprop-stereo=/.test(params)) params += ';sprop-stereo=0';
                if (!/(^|;)stereo=/.test(params)) params += ';stereo=0';
                return 'a=fmtp:' + pt + ' ' + params;
            }
            return 'a=fmtp:' + pt + ' ' + params + ';stereo=0;sprop-stereo=0';
        }
    );
    return Promise.resolve(description);
}

const MIC_CONSTRAINTS = { audio: { channelCount: 1, echoCancellation: true }, video: false };
const SDP_MODIFIERS = [stripVirtualNicModifier, opusMonoFmtpModifier];
const pbxDomain = () => localStorage.getItem('sip_domain') || (window.SKYKIN && SKYKIN.domain) || location.hostname;

function startRec(stream) {
    try {
        if (window.mediaRecorderRef && window.mediaRecorderRef.state !== 'inactive') {
            try { window.mediaRecorderRef.stop(); } catch (e) {}
        }
        const mr = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm' });
        window.recordingChunks = [];
        mr.ondataavailable = e => { if (e.data && e.data.size > 0) window.recordingChunks.push(e.data); };
        mr.start(1000);
        window.mediaRecorderRef = mr;
        window.recordingCallId = 'call-' + Date.now();
        window.markRecordingActive && window.markRecordingActive();
    } catch(e) {
        window.sipReport && window.sipReport('record_start_failed', e, '');
    }
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
        const caller = window.lastDialedNumber || '';
        try {
            // Prefer local dashboard upload so recordings work without the helper API.
            const resp = await fetch(
                'index.php?action=upload_recording'
                    + '&call_id=' + encodeURIComponent(id)
                    + '&ext=' + encodeURIComponent(ext)
                    + '&domain=' + encodeURIComponent(dom)
                    + '&caller=' + encodeURIComponent(caller),
                { method: 'POST', body: fd, credentials: 'same-origin' }
            );
            if (!resp.ok) {
                const base = (window.SKYKIN && SKYKIN.recordingsApiBase) || '';
                if (base) {
                    await fetch(base + '/api/recordings/upload?call_id=' + encodeURIComponent(id), { method:'POST', body:fd });
                }
            }
        } catch(e) {
            window.sipReport && window.sipReport('record_upload_failed', e, 'id=' + id);
        }
    };
    try { mr.stop(); } catch(e) {}
    window.mediaRecorderRef = null;
}

function enableSenders(s) {
    const pc = s && s.sessionDescriptionHandler && s.sessionDescriptionHandler.peerConnection;
    if (!pc) return;
    pc.getTransceivers().forEach(function(t) {
        try { if (t.direction && t.direction !== 'sendrecv') t.direction = 'sendrecv'; } catch (e) {}
        if (t.sender && t.sender.track) t.sender.track.enabled = true;
        try {
            const p = t.sender && t.sender.getParameters();
            if (p && p.encodings && p.encodings.length) {
                p.encodings.forEach(function(e) { e.active = true; });
                t.sender.setParameters(p);
            }
        } catch (e) {}
    });
}

function attachAudio(s) {
    const sdh = s.sessionDescriptionHandler;
    if (!sdh?.peerConnection) return;
    enableSenders(s);
    if (s._skykinAudioAttached) return;
    s._skykinAudioAttached = true;
    const pc = sdh.peerConnection;
    const remote = new MediaStream();
    const el = document.getElementById('remoteAudio');
    const hook = function(track) {
        if (!track || track.kind !== 'audio') return;
        if (remote.getTracks().indexOf(track) >= 0) return;
        remote.addTrack(track);
        if (el) { el.srcObject = remote; el.play().catch(function(){}); }
    };
    pc.getReceivers().forEach(r => hook(r.track));
    pc.ontrack = function(ev) { hook(ev.track); };
    if (el) { el.srcObject = remote; el.play().catch(function(){}); }
}

function bindSession(s) {
    s.stateChange.addListener(state => {
        if (state === SessionState.Established || state === SessionState.Terminated
            || state === SessionState.Terminating) {
            if (s instanceof Invitation) {
                window.stopRingback && window.stopRingback();
            }
        }
        if (state === SessionState.Established) {
            s._skykinEstablished = true;
            window._callEnded = false;
            const num = s instanceof Invitation
                ? (s.remoteIdentity?.uri?.user || window.lastDialedNumber || '')
                : (window.lastDialedNumber || '');
            if (!(s instanceof Invitation)) {
                window._outboundRingPhase = true;
                startOutboundPoll(localStorage.getItem('sip_ext') || serverExt, num);
                return;
            }
            window.startCallUI && window.startCallUI(num);
            attachAudio(s);
            window.showToast && window.showToast('Call connected');

            // Pre-fill escalation form with the caller's details
            autofillEscalationForm(num);
        }
        if (state === SessionState.Terminated || state === SessionState.Terminating) {
            stopRec();
            // A lose-race / re-ring must not hang up a newer invite or open ACW.
            if (session && session !== s) {
                return;
            }
            if (session === s) {
                session = null;
            }
            if (!(s instanceof Invitation) && !callStartTime) {
                resetOutboundRingUi();
            } else if (s._skykinEstablished) {
                if (window.endCall) window.endCall();
            } else {
                window.stopRingtone && window.stopRingtone();
                if (s instanceof Invitation && !s._skykinEstablished) {
                    if (window.clearIncomingRingUi) window.clearIncomingRingUi();
                    if (window.showToast) window.showToast('Incoming call ended');
                }
                if (window.resetMissedRing) window.resetMissedRing();
            }
        }
    });
}

window.sipBridge.init = function(ext, pass, server, port, dom) {
    if (ua) { try { reg?.unregister(); ua.stop(); } catch(e) {} }

    let wsUri = (location.protocol === 'https:' ? 'wss://' : 'ws://')
        + location.hostname + (location.port ? ':' + location.port : '') + '/wss/';

    const sipUri = UserAgent.makeURI('sip:' + ext + '@' + dom);
    if (!sipUri) {
        window.setSipStatus('failed', 'Bad SIP address: ' + ext + '@' + dom);
        return;
    }

    ua = new UserAgent({
        uri: sipUri,
        transportOptions: {
            server: wsUri,
            connectionTimeout: 8,
            traceSip: false
        },
        authorizationUsername: ext,
        authorizationPassword: pass,
        contactParams: { transport: 'wss' },
        logLevel: 'error',
        logConfiguration: false,
        sessionDescriptionHandlerFactoryOptions: {
            iceGatheringTimeout: ICE_GATHERING_TIMEOUT_MS,
            peerConnectionConfiguration: ICE_PC_CONFIG,
            modifiers: SDP_MODIFIERS
        }
    });

    reg = new Registerer(ua, { expires: 300, logConfiguration: false });
    const armFail = setTimeout(function() {
        window.setSipStatus('failed', 'Phone sign-in timed out. Refresh the page.');
    }, 20000);
    reg.stateChange.addListener(state => {
        if (state === 'Registered') {
            clearTimeout(armFail);
            window.setSipStatus('registered', 'Registered (' + ext + ')');
            window.ensureMic && window.ensureMic().catch(function(){});
        } else if (state === 'Unregistered') {
            window.setSipStatus('unregistered', 'Not Registered');
        } else if (state === 'Terminated') {
            clearTimeout(armFail);
            window.setSipStatus('failed', 'Registration Failed');
        }
    });

    ua.delegate = {
        onInvite(inv) {
            // Already talking (or outbound still connecting): 486 so the
            // other Ready agent can take this inbound. Do not steal the live call.
            if (session && session !== inv
                && (session.state === SessionState.Established
                    || (session.state === SessionState.Establishing
                        && !(session instanceof Invitation)))) {
                try { inv.reject({ statusCode: 486 }); } catch (e) {}
                return;
            }
            if (session && session !== inv
                && session.state !== SessionState.Established
                && session.state !== SessionState.Terminated) {
                try { session.reject(); } catch (e) {}
            }
            session = inv;
            window._callEnded = false;
            try { document.getElementById('acwModal').classList.remove('show'); } catch (e) {}
            const num = inv.remoteIdentity?.uri?.user
                || inv.remoteIdentity?.displayName
                || inv.request?.from?.uri?.user
                || inv.request?.getHeader?.('P-Asserted-Identity')?.match(/sip:(\+?[\d]+)@/)?.[1]
                || inv.request?.getHeader?.('From')?.match(/sip:(\+?[\d]+)@/)?.[1]
                || inv.request?.getHeader?.('From')?.match(/"?([^"<]+)"?\s*</)?.[1]
                || 'Unknown';
            window.lastDialedNumber = num; window.lastCallType = 'Inbound';
            // WebRTC B-leg must send 183+SDP or FreeSWITCH drops the originate in ~20ms
            // (NO_ANSWER). Caller A-leg stays on 180 — this does not pre-answer Ethio.
            window.ensureMic().then(function() {
                return inv.progress({
                    statusCode: 183,
                    sessionDescriptionHandlerOptions: {
                        constraints: MIC_CONSTRAINTS,
                        iceGatheringTimeout: ICE_GATHERING_TIMEOUT_MS,
                        peerConnectionConfiguration: ICE_PC_CONFIG
                    },
                    sessionDescriptionHandlerModifiers: SDP_MODIFIERS
                });
            }).catch(function(e) {
                window.sipReport && window.sipReport('invite_progress_failed', e, '');
                try { inv.progress({ statusCode: 180 }).catch(function() {}); } catch (ex) {}
            });
            window.handleIncoming && window.handleIncoming(num);
            bindSession(inv);
        }
    };

    ua.start().then(() => reg.register()).catch(err => {
        clearTimeout(armFail);
        window.setSipStatus('failed', 'Error: ' + (err && err.message ? err.message : 'WebSocket failed'));
    });
};

window.sipBridge.makeCall = function(number) {
    if (!ua) { window.showToast && window.showToast('SIP not initialized'); return; }
    number = (window.skykinNormalizeEtDial && window.skykinNormalizeEtDial(number)) || number;
    const uri = UserAgent.makeURI('sip:' + number + '@' + pbxDomain());
    if (!uri) return;

    // A previous failed attempt leaves a session whose peer connection is already
    // closed. Reusing it makes the next offer fail with "Peer connection closed".
    if (session) {
        try { session.dispose && session.dispose(); } catch (e) {}
        session = null;
    }

    // The SDP offer needs the microphone, so a denied device fails the call
    // before any INVITE is sent — surface that instead of a generic failure.
    window.ensureMic().then(function() {
        const inv = new Inviter(ua, uri, {
            sessionDescriptionHandlerOptions: {
                constraints: MIC_CONSTRAINTS,
                iceGatheringTimeout: ICE_GATHERING_TIMEOUT_MS,
                peerConnectionConfiguration: ICE_PC_CONFIG
            },
            sessionDescriptionHandlerModifiers: SDP_MODIFIERS
        });
        session = inv;
        window.lastDialedNumber = number;
        window.lastCallType = 'Outbound';
        window._outboundRingPhase = true;
        window._outMaxCh = 0;
        window.setSipStatus && window.setSipStatus('calling', 'Calling ' + number);
        bindSession(inv);
        startOutboundPoll(localStorage.getItem('sip_ext') || serverExt, number);
        return inv.invite({
            // 180/183 means the far end is actually alerting, so only start the
            // ringback then rather than the moment Call is pressed.
            requestDelegate: {
                onProgress: function() {
                    window.setSipStatus && window.setSipStatus('calling', 'Ringing ' + number);
                    window.startRingback && window.startRingback();
                    enableSenders(inv);
                    startOutboundPoll(localStorage.getItem('sip_ext') || serverExt, number);
                },
                onAccept: function() {
                    // pre_answer 200 — keep media; poll switches UI when B-leg ACTIVE.
                    enableSenders(inv);
                    if (!inv._skykinAudioAttached) {
                        attachAudio(inv);
                    }
                    startOutboundPoll(localStorage.getItem('sip_ext') || serverExt, number);
                }
            }
        }).catch(function(err) {
            window.stopRingback && window.stopRingback();
            window.sipReport('invite_failed', err, 'to=' + number + ' state=' + inv.state);
            window.setSipStatus && window.setSipStatus('failed', 'Call failed: ' + err.message);
            window.showToast && window.showToast('Call failed: ' + (err.message || err.name));
            window.endCall && window.endCall();
        });
    }).catch(function(e) {
        window.sipReport('call_mic_failed', e, 'to=' + number);
        window.setSipStatus && window.setSipStatus('failed', 'Microphone unavailable');
        window.showToast && window.showToast(micErrorMessage(e));
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

// Decline an inbound ring with 603 so FreeSWITCH aborts the whole caller
// (does not roll the call to the other agent). 486 = already in a call.
window.sipBridge.decline = function() {
    if (!session) return;
    try {
        if (session instanceof Invitation
            && (session.state === SessionState.Initial
                || session.state === SessionState.Establishing)) {
            session.reject({ statusCode: 603 });
        } else if (window.sipBridge.hangup) {
            window.sipBridge.hangup();
            return;
        }
    } catch (e) {}
    session = null;
};

// Report softphone problems to the server so failures can be diagnosed without
// asking the agent to read the browser console.
window.sipReport = function(event, err, extra) {
    try {
        fetch('index.php?action=client_log', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                event: event,
                name: (err && err.name) || '',
                message: (err && (err.message || String(err))) || '',
                extra: extra || '',
                ext: localStorage.getItem('sip_ext') || ''
            })
        }).catch(function(){});
    } catch (e) {}
};

// Ask for the microphone once, up front. Requesting it only at answer time
// meant the permission prompt appeared mid-ring and the call timed out.
window.ensureMic = function() {
    if (window._micStream) return Promise.resolve(window._micStream);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        const e = new Error('getUserMedia unavailable — the page must be served over HTTPS');
        e.name = 'InsecureContext';
        window.sipReport('mic_unavailable', e, 'protocol=' + location.protocol);
        return Promise.reject(e);
    }
    return navigator.mediaDevices.getUserMedia(MIC_CONSTRAINTS)
        .then(function(stream) {
            window._micStream = stream;
            return stream;
        })
        .catch(function(err) {
            window.sipReport('mic_denied', err, 'protocol=' + location.protocol);
            throw err;
        });
};

function micErrorMessage(e) {
    const name = (e && e.name) || '';
    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'Microphone blocked. Click the padlock in the address bar → Microphone → Allow, then reload.';
    }
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return 'No microphone found. Plug in a headset and reload.';
    }
    if (name === 'NotReadableError' || name === 'AbortError') {
        return 'Microphone is in use by another app. Close it and try again.';
    }
    if (name === 'InsecureContext') {
        return 'Microphone needs HTTPS. Open this dashboard over https://';
    }
    return 'Answer failed: ' + ((e && (e.message || e.name)) || 'unknown error');
}

window.sipBridge.answer = function() {
    if (!window.skykinHasRingingInvite()) {
        window.showToast && window.showToast('No incoming call to answer.');
        if (window.clearIncomingRingUi) window.clearIncomingRingUi();
        return;
    }
    const inv = session;
    window._inboundRingActive = false;
    window.ensureMic()
        .then(function(stream) {
            const el = document.getElementById('remoteAudio');
            if (el) el.play().catch(function(){});
            return inv.accept({
                sessionDescriptionHandlerOptions: {
                    constraints: MIC_CONSTRAINTS,
                    iceGatheringTimeout: ICE_GATHERING_TIMEOUT_MS,
                    peerConnectionConfiguration: ICE_PC_CONFIG
                },
                sessionDescriptionHandlerModifiers: SDP_MODIFIERS
            });
        })
        .catch(function(e) {
            console.error('answer failed', e);
            window.sipReport('answer_failed', e, 'state=' + (inv && inv.state));
            window.showToast && window.showToast(micErrorMessage(e));
        });
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
