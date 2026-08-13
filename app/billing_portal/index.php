<?php
// SkyKin Technologies – Standalone Call Billing Portal
// No authentication required for internal team use.

// ── V4 UUID generator helper ───────────────────────────────────────────────
if (!function_exists('generate_uuid')) {
    function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ── Database Connection (PostgreSQL with SQLite fallback) ─────────────────
function getDB() {
    static $db = null;
    if ($db) return $db;

    // Read credentials from FusionPBX resources/config.php (single source of truth)
    $h = '192.168.1.7'; $p = '5432'; $n = 'fusionpbx'; $u = 'fusionpbx'; $pw = '';
    $fpbxConfig = dirname(__DIR__, 2) . '/resources/config.php';
    if (is_file($fpbxConfig)) {
        @include $fpbxConfig;
        if (!empty($db_host))     $h  = $db_host;
        if (!empty($db_port))     $p  = $db_port;
        if (!empty($db_name))     $n  = $db_name;
        if (!empty($db_username)) $u  = $db_username;
        if (isset($db_password))  $pw = $db_password;
    }

    try {
        $db = new PDO(
            "pgsql:host={$h};port={$p};dbname={$n};connect_timeout=5",
            $u, $pw,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $db;
    } catch (Exception $ignored) {}

    // SQLite fallback for fully-offline local development
    $sqliteFile = __DIR__ . '/../agent_dashboard/skykin_local.db';
    $db = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL');
    return $db;
}

// ── Domain Resolver ────────────────────────────────────────────────────────
function resolveDomain(PDO $db) {
    if (!empty($_GET['domain'])) {
        return $_GET['domain'];
    }
    
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dom = preg_replace('/:\d+$/', '', $host);

    try {
        $stmt = $db->query("SELECT DISTINCT domain_name FROM v_xml_cdr WHERE domain_name IS NOT NULL AND domain_name != ''");
        $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($domains) > 0) {
            if (in_array($dom, $domains)) {
                return $dom;
            }
            // Auto-heal local mapping to stored DB domains (like 192.168.1.7)
            return $domains[0];
        }
    } catch (Exception $e) {}

    return $dom;
}

// ── Database Schema Auto-Setup & Seeding ──────────────────────────────────
try {
    $db = getDB();
    $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

    if ($isSQLite) {
        $db->exec("CREATE TABLE IF NOT EXISTS billing_rates (
            billing_rate_uuid TEXT PRIMARY KEY,
            domain_uuid TEXT,
            rate_name TEXT NOT NULL,
            talk_rate REAL DEFAULT 0.0,
            wait_rate REAL DEFAULT 0.0,
            flat_fee REAL DEFAULT 0.0,
            agent_id TEXT,
            queue_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS call_billing (
            call_billing_uuid TEXT PRIMARY KEY,
            domain_uuid TEXT,
            xml_cdr_uuid TEXT UNIQUE,
            talk_rate REAL DEFAULT 0.0,
            wait_rate REAL DEFAULT 0.0,
            flat_fee REAL DEFAULT 0.0,
            talk_time_minutes REAL DEFAULT 0.0,
            wait_time_minutes REAL DEFAULT 0.0,
            calculated_cost REAL DEFAULT 0.0,
            direction TEXT DEFAULT 'inbound',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Migration: add direction column to existing SQLite installs
        try { $db->exec("ALTER TABLE call_billing ADD COLUMN direction TEXT DEFAULT 'inbound'"); } catch (Exception $ignored) {}
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS billing_rates (
            billing_rate_uuid UUID PRIMARY KEY,
            domain_uuid UUID,
            rate_name VARCHAR(100) NOT NULL,
            talk_rate NUMERIC(10, 4) DEFAULT 0.0000,
            wait_rate NUMERIC(10, 4) DEFAULT 0.0000,
            flat_fee NUMERIC(10, 4) DEFAULT 0.0000,
            agent_id VARCHAR(50),
            queue_id VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS call_billing (
            call_billing_uuid UUID PRIMARY KEY,
            domain_uuid UUID,
            xml_cdr_uuid UUID UNIQUE,
            talk_rate NUMERIC(10, 4) DEFAULT 0.0000,
            wait_rate NUMERIC(10, 4) DEFAULT 0.0000,
            flat_fee NUMERIC(10, 4) DEFAULT 0.0000,
            talk_time_minutes NUMERIC(10, 4) DEFAULT 0.0000,
            wait_time_minutes NUMERIC(10, 4) DEFAULT 0.0000,
            calculated_cost NUMERIC(10, 4) DEFAULT 0.0000,
            direction TEXT DEFAULT 'inbound',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Migration: add direction column to existing PostgreSQL installs (safe — IF NOT EXISTS)
        try { $db->exec("ALTER TABLE call_billing ADD COLUMN IF NOT EXISTS direction TEXT DEFAULT 'inbound'"); } catch (Exception $ignored) {}
        // Backfill: populate direction for any existing rows that have NULL direction
        try {
            $db->exec("UPDATE call_billing b SET direction = c.direction FROM v_xml_cdr c WHERE c.xml_cdr_uuid = b.xml_cdr_uuid AND b.direction IS NULL");
        } catch (Exception $ignored) {}
    }

    // Seed default Global Rate if empty
    $cnt = $db->query("SELECT COUNT(*) FROM billing_rates")->fetchColumn();
    if ($cnt == 0) {
        $rate_uuid = generate_uuid();
        $stmt = $db->prepare("INSERT INTO billing_rates (billing_rate_uuid, domain_uuid, rate_name, talk_rate, wait_rate, flat_fee) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$rate_uuid, null, 'Global Rate', 0.05, 0.02, 0.10]);
    }
} catch (Exception $e) {
    die('Database initialization failed: ' . $e->getMessage());
}

// ── Call sync / cost calculation logic ──────────────────────────────────────
function syncBilling(PDO $db, string $domain, int $ts, int $te) {
    // 1. Get the global billing rate
    $stmt = $db->prepare("SELECT talk_rate, wait_rate, flat_fee FROM billing_rates WHERE agent_id IS NULL AND queue_id IS NULL LIMIT 1");
    $stmt->execute();
    $global_rate = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$global_rate) {
        $global_rate = ['talk_rate' => 0.05, 'wait_rate' => 0.02, 'flat_fee' => 0.10];
    }

    // 2. Fetch calls inside date range that do not yet have a record in call_billing
    //    Both 'inbound' and 'local' (outbound/agent-initiated) calls are billed.
    $query = "SELECT c.xml_cdr_uuid, c.domain_uuid, c.billsec, c.waitsec, c.direction
              FROM v_xml_cdr c
              LEFT JOIN call_billing b ON b.xml_cdr_uuid = c.xml_cdr_uuid
              WHERE c.domain_name = :domain
                AND c.start_epoch >= :ts
                AND c.start_epoch <= :te
                AND b.call_billing_uuid IS NULL";
    $stmt = $db->prepare($query);
    $stmt->execute([':domain' => $domain, ':ts' => $ts, ':te' => $te]);
    $missing_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($missing_calls) > 0) {
        $db->beginTransaction();
        try {
            $insert_stmt = $db->prepare("INSERT INTO call_billing 
                (call_billing_uuid, domain_uuid, xml_cdr_uuid, talk_rate, wait_rate, flat_fee, talk_time_minutes, wait_time_minutes, calculated_cost, direction)
                VALUES (:uuid, :domain_uuid, :xml_cdr_uuid, :talk_rate, :wait_rate, :flat_fee, :talk_time, :wait_time, :cost, :direction)");
            
            foreach ($missing_calls as $call) {
                $talk_sec = (float)($call['billsec'] ?? 0);
                $talk_min = $talk_sec / 60.0;

                $wait_sec = (float)($call['waitsec'] ?? 0);
                $wait_min = $wait_sec / 60.0;

                // direction: 'inbound' = queue/IVR call; 'local' = agent-initiated outbound (dashboard, mobile app)
                // For outbound/local calls waitsec is naturally 0, so the same formula works for both.
                // Formula: cost = (talk_time_minutes x talk_rate) + (wait_time_minutes x wait_rate) + flat_fee
                $direction = $call['direction'] ?? 'inbound';
                $cost = ($talk_min * (float)$global_rate['talk_rate']) + ($wait_min * (float)$global_rate['wait_rate']) + (float)$global_rate['flat_fee'];

                $uuid = generate_uuid();
                $insert_stmt->execute([
                    ':uuid'         => $uuid,
                    ':domain_uuid'  => $call['domain_uuid'],
                    ':xml_cdr_uuid' => $call['xml_cdr_uuid'],
                    ':talk_rate'    => $global_rate['talk_rate'],
                    ':wait_rate'    => $global_rate['wait_rate'],
                    ':flat_fee'     => $global_rate['flat_fee'],
                    ':talk_time'    => $talk_min,
                    ':wait_time'    => $wait_min,
                    ':cost'         => $cost,
                    ':direction'    => $direction
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

// ── JSON API Endpoints ───────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    error_reporting(0);
    header('Content-Type: application/json');
    $api = $_GET['api'];
    $dom = resolveDomain($db);

    try {
        if ($api === 'rates') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $talk = (float)($_POST['talk_rate'] ?? 0.0);
                $wait = (float)($_POST['wait_rate'] ?? 0.0);
                $flat = (float)($_POST['flat_fee'] ?? 0.0);

                $stmt = $db->prepare("UPDATE billing_rates SET talk_rate = :talk, wait_rate = :wait, flat_fee = :flat, updated_at = CURRENT_TIMESTAMP WHERE agent_id IS NULL AND queue_id IS NULL");
                $stmt->execute([':talk' => $talk, ':wait' => $wait, ':flat' => $flat]);

                echo json_encode(['ok' => true]);
                exit;
            } else {
                $stmt = $db->prepare("SELECT talk_rate, wait_rate, flat_fee FROM billing_rates WHERE agent_id IS NULL AND queue_id IS NULL LIMIT 1");
                $stmt->execute();
                $rate = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($rate ?: ['talk_rate' => 0.05, 'wait_rate' => 0.02, 'flat_fee' => 0.10]);
                exit;
            }
        }

        if ($api === 'summary') {
            $date_from = $_GET['from'] ?? date('Y-m-d');
            $date_to   = $_GET['to'] ?? date('Y-m-d');
            $search    = trim($_GET['search'] ?? '');

            $ts = strtotime($date_from . ' 00:00:00');
            $te = strtotime($date_to   . ' 23:59:59');

            // Trigger sync calculation for queried range
            syncBilling($db, $dom, $ts, $te);

            // Fetch call details
            $query = "SELECT 
                b.talk_rate, b.wait_rate, b.flat_fee,
                b.talk_time_minutes, b.wait_time_minutes, b.calculated_cost,
                COALESCE(b.direction, c.direction, 'inbound') AS call_direction,
                c.start_epoch, c.caller_id_number, c.destination_number, c.direction, 
                c.cc_agent, c.cc_agent_bridged, c.billsec, c.waitsec
                FROM call_billing b
                JOIN v_xml_cdr c ON c.xml_cdr_uuid = b.xml_cdr_uuid
                WHERE c.domain_name = :domain
                  AND c.start_epoch >= :ts
                  AND c.start_epoch <= :te
                ORDER BY c.start_epoch DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute([':domain' => $dom, ':ts' => $ts, ':te' => $te]);
            $raw_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Filter & aggregate in PHP for maximum flexibility
            $filtered_breakdown = [];
            $agent_summary = [];

            // Totals trackers
            $total_calls    = 0;
            $total_talk     = 0.0;
            $total_wait     = 0.0;
            $total_cost     = 0.0;
            $inbound_calls  = 0;
            $inbound_cost   = 0.0;
            $outbound_calls = 0;
            $outbound_cost  = 0.0;

            foreach ($raw_calls as $c) {
                // Agent identification rules
                $agent = '';
                if (!empty($c['cc_agent'])) {
                    $agent = $c['cc_agent'];
                } elseif (!empty($c['cc_agent_bridged'])) {
                    $agent = $c['cc_agent_bridged'];
                } else {
                    if ($c['direction'] === 'outbound' || $c['direction'] === 'local') {
                        $agent = $c['caller_id_number'];
                    } else {
                        $agent = $c['destination_number'];
                    }
                }
                if ($agent === '') {
                    $agent = 'Unknown';
                }

                // If searching, check if query matches agent or extension number
                if ($search !== '') {
                    if (stripos($agent, $search) === false && 
                        stripos($c['caller_id_number'], $search) === false && 
                        stripos($c['destination_number'], $search) === false) {
                        continue;
                    }
                }

                // Call matches filter — accumulate totals
                $total_calls++;
                $total_talk += (float)$c['talk_time_minutes'];
                $total_wait += (float)$c['wait_time_minutes'];
                $total_cost += (float)$c['calculated_cost'];
                $ag_dir = $c['call_direction'] ?? $c['direction'] ?? 'inbound';
                if ($ag_dir === 'inbound') {
                    $inbound_calls++;
                    $inbound_cost += (float)$c['calculated_cost'];
                } else {
                    $outbound_calls++;
                    $outbound_cost += (float)$c['calculated_cost'];
                }

                // Direction label for UI
                $raw_dir = $c['call_direction'] ?? $c['direction'] ?? 'inbound';
                $dir_label = ($raw_dir === 'local') ? 'Outbound' : ucfirst($raw_dir);

                // Append to breakdown
                $filtered_breakdown[] = [
                    'date'      => date('Y-m-d H:i:s', $c['start_epoch']),
                    'agent'     => $agent,
                    'direction' => $dir_label,
                    'talk_sec'  => (int)$c['billsec'],
                    'wait_sec'  => (int)$c['waitsec'],
                    'cost'      => (float)$c['calculated_cost']
                ];

                // Append to agent summary map
                if (!isset($agent_summary[$agent])) {
                    $agent_summary[$agent] = [
                        'agent'         => $agent,
                        'calls_count'   => 0,
                        'total_talk'    => 0.0,
                        'total_wait'    => 0.0,
                        'inbound_cost'  => 0.0,
                        'outbound_cost' => 0.0,
                        'total_cost'    => 0.0
                    ];
                }
                $agent_summary[$agent]['calls_count']++;
                $agent_summary[$agent]['total_talk'] += (float)$c['talk_time_minutes'];
                $agent_summary[$agent]['total_wait'] += (float)$c['wait_time_minutes'];
                $agent_summary[$agent]['total_cost'] += (float)$c['calculated_cost'];
                if ($ag_dir === 'inbound') {
                    $agent_summary[$agent]['inbound_cost'] += (float)$c['calculated_cost'];
                } else {
                    $agent_summary[$agent]['outbound_cost'] += (float)$c['calculated_cost'];
                }
            }

            // Convert summary map to list and sort by cost
            $agent_summary_list = array_values($agent_summary);
            usort($agent_summary_list, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);

            echo json_encode([
                'kpis' => [
                    'total_calls'    => $total_calls,
                    'total_talk'     => round($total_talk, 2),
                    'total_wait'     => round($total_wait, 2),
                    'total_cost'     => round($total_cost, 2),
                    'inbound_calls'  => $inbound_calls,
                    'inbound_cost'   => round($inbound_cost, 2),
                    'outbound_calls' => $outbound_calls,
                    'outbound_cost'  => round($outbound_cost, 2),
                ],
                'breakdown'     => $filtered_breakdown,
                'agent_summary' => $agent_summary_list
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$resolved_domain = resolveDomain($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Billing Portal - SkyKin Technologies</title>
    <style>
        :root {
            /* ── Light theme matching Agent Dashboard & Department Portal ── */
            --bg-base: #f0f2f5;
            --bg-surface: #ffffff;
            --bg-card: #ffffff;
            --border-color: #e9ecef;
            --text-primary: #333333;
            --text-secondary: #555555;
            --text-muted: #888888;
            --clr-primary: #0047AB;
            --clr-primary-glow: rgba(0, 71, 171, 0.15);
            --clr-success: #28a745;
            --clr-warning: #fd7e14;
            --clr-danger: #dc3545;
        }

        /* Direction badges */
        .dir-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
        }
        .dir-badge.inbound  { background: #dbeafe; color: #1d4ed8; }
        .dir-badge.outbound { background: #ffedd5; color: #c2410c; }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-primary);
            overflow-x: hidden;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header matching Agent Dashboard ── */
        header {
            background: linear-gradient(135deg, #0047AB 0%, #00B4D8 100%);
            color: white;
            height: 64px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            position: sticky;
            top: 0;
            z-index: 300;
        }

        .header-container {
            width: 100%;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }

        .logo {
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 1px;
            white-space: nowrap;
            color: white;
        }

        .logo span {
            color: #00e5ff;
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-btn {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            background: rgba(255,255,255,0.15);
            padding: 6px 16px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.2s;
        }

        .header-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .header-btn.active {
            background: white;
            color: var(--clr-primary);
            border-color: white;
        }

        /* Main content area */
        main {
            flex: 1;
            width: 100%;
            padding: 20px 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Tabs Navigation */
        .portal-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 24px;
        }

        .portal-tab {
            background: none;
            border: none;
            color: var(--text-secondary);
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }

        .portal-tab:hover {
            color: var(--clr-primary);
        }

        .portal-tab.active {
            color: var(--clr-primary);
            border-bottom-color: var(--clr-primary);
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        /* Filter Panel */
        .filter-panel {
            background: var(--bg-surface);
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }

        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-input {
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            padding: 9px 16px;
            padding-left: 40px;
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: var(--clr-primary);
            box-shadow: 0 0 0 3px rgba(0, 71, 171, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            width: 16px;
            height: 16px;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .filter-input {
            background: white;
            border: 1px solid #ddd;
            padding: 8px 12px;
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
            outline: none;
            transition: all 0.2s;
        }

        .filter-input:focus {
            border-color: var(--clr-primary);
        }

        .range-presets {
            display: flex;
            gap: 6px;
        }

        .preset-btn {
            background: white;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .preset-btn:hover, .preset-btn.active {
            background: var(--clr-primary-glow);
            border-color: var(--clr-primary);
            color: var(--clr-primary);
        }

        .btn-refresh {
            background: var(--clr-primary);
            color: white;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-refresh:hover {
            background: #003685;
        }

        /* KPI cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            border-top: 4px solid var(--clr-primary);
        }

        .kpi-card.green { border-top-color: var(--clr-success); }
        .kpi-card.warning { border-top-color: var(--clr-warning); }
        .kpi-card.danger { border-top-color: var(--clr-danger); }

        .kpi-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .kpi-val {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.1;
        }

        .kpi-card.green .kpi-val { color: var(--clr-success); }
        .kpi-card.warning .kpi-val { color: #d35400; }
        .kpi-card.danger .kpi-val { color: var(--clr-danger); }

        .kpi-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Dashboard Split Grid */
        .portal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 992px) {
            .portal-grid {
                grid-template-columns: 1.2fr 1.8fr;
            }
        }

        .panel-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 16px 20px;
            background: #fafbfc;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table-container {
            overflow-x: auto;
            max-height: 480px;
        }

        .portal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .portal-table th {
            background: #fafbfc;
            color: var(--text-secondary);
            font-weight: 600;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .portal-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .portal-table tr:hover td {
            background-color: #f8f9fa;
        }

        .portal-table tr:last-child td {
            border-bottom: none;
        }

        /* Configuration view styles */
        .config-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 24px;
            max-width: 550px;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .config-group {
            margin-bottom: 20px;
        }

        .config-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .config-input-wrap {
            position: relative;
        }

        .config-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .config-input {
            width: 100%;
            background: #fafafa;
            border: 1px solid #d1d5db;
            padding: 10px 14px;
            padding-left: 28px;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .config-input:focus {
            border-color: var(--clr-primary);
            background: white;
            box-shadow: 0 0 0 3px var(--clr-primary-glow);
        }

        .btn-save {
            background: var(--clr-primary);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s;
        }

        .btn-save:hover {
            background: #003685;
        }

        .success-box {
            display: none;
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .info-box {
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            color: #0369a1;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .notice-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #b45309;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 16px;
            line-height: 1.4;
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header>
        <div class="header-container">
            <div class="logo">SKY<span>KIN</span> Technologies</div>
            
            <div class="header-links">
                <a href="/app/department_tickets/index.php" class="header-btn">Department Tickets</a>
                <a href="/app/billing_portal/index.php" class="header-btn active">Billing Portal</a>
                <a href="/app/agent_dashboard/index.php" class="header-btn">Agent Dashboard</a>
            </div>
        </div>
    </header>

    <!-- MAIN BODY -->
    <main>
        
        <!-- Portal Tabs -->
        <div class="portal-tabs">
            <button class="portal-tab active" id="tabBtnReport" onclick="switchTab('report')">Billing Report</button>
            <button class="portal-tab" id="tabBtnRates" onclick="switchTab('rates')">Rate Configurations</button>
        </div>

        <!-- ================= TAB CONTENT: REPORT ================= -->
        <div class="tab-panel active" id="panelReport">
            
            <!-- Filters -->
            <div class="filter-panel">
                <div class="filter-group">
                    <label for="fFrom">From</label>
                    <input type="date" id="fFrom" class="filter-input" value="<?php echo $today; ?>">
                </div>

                <div class="filter-group">
                    <label for="fTo">To</label>
                    <input type="date" id="fTo" class="filter-input" value="<?php echo $today; ?>">
                </div>

                <div class="range-presets">
                    <button class="preset-btn active" onclick="setRange(0)">Today</button>
                    <button class="preset-btn" onclick="setRange(7)">7 Days</button>
                    <button class="preset-btn" onclick="setRange(30)">30 Days</button>
                </div>

                <div class="search-wrapper">
                    <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" id="txtSearch" class="search-input" placeholder="Search by agent name or extension..." oninput="triggerSearch()">
                </div>

                <button class="btn-refresh" onclick="loadBillingSummary()">Refresh</button>
                <span id="loadStatus" style="font-size:12px;color:var(--text-muted);"></span>
            </div>

                <!-- KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-label">Total Calls</div>
                    <div class="kpi-val" id="lblTotalCalls">0</div>
                    <div class="kpi-sub">Processed in period</div>
                </div>
                <div class="kpi-card warning">
                    <div class="kpi-label">Total Talk Time</div>
                    <div class="kpi-val" id="lblTotalTalk">0.0m</div>
                    <div class="kpi-sub">Total talking duration</div>
                </div>
                <div class="kpi-card danger">
                    <div class="kpi-label">Total Wait Time</div>
                    <div class="kpi-val" id="lblTotalWait">0.0m</div>
                    <div class="kpi-sub">Wait &amp; hold duration</div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-label">Total Cost</div>
                    <div class="kpi-val" id="lblTotalCost">$0.00</div>
                    <div class="kpi-sub">Generated billing total</div>
                </div>
                <div class="kpi-card" style="border-top:3px solid #1d4ed8">
                    <div class="kpi-label">Inbound Calls</div>
                    <div class="kpi-val" style="color:#1d4ed8" id="lblInboundCalls">0</div>
                    <div class="kpi-sub" id="lblInboundCost" style="color:#1d4ed8;font-weight:600">$0.00</div>
                </div>
                <div class="kpi-card" style="border-top:3px solid #c2410c">
                    <div class="kpi-label">Outbound Calls</div>
                    <div class="kpi-val" style="color:#c2410c" id="lblOutboundCalls">0</div>
                    <div class="kpi-sub" id="lblOutboundCost" style="color:#c2410c;font-weight:600">$0.00</div>
                </div>
            </div>

            <!-- Split Tables Layout -->
            <div class="portal-grid">
                
                <!-- Left Column: Agent Summary -->
                <div class="panel-card">
                    <div class="panel-header">
                        <div class="panel-title">Per-Agent Cost Summary</div>
                    </div>
                    <div class="table-container">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th style="text-align:center">Calls</th>
                                    <th>Talk Min</th>
                                    <th>Wait Min</th>
                                    <th style="color:#1d4ed8">Inbound</th>
                                    <th style="color:#c2410c">Outbound</th>
                                    <th>Total Cost</th>
                                </tr>
                            </thead>
                            <tbody id="agentTbody">
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted)">Loading summaries...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right Column: Detailed Breakdown -->
                <div class="panel-card">
                    <div class="panel-header">
                        <div class="panel-title">Per-Call Cost Breakdown</div>
                    </div>
                    <div class="table-container">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Agent / Number</th>
                                    <th>Direction</th>
                                    <th>Talk Time</th>
                                    <th>Wait Time</th>
                                    <th>Calculated Cost</th>
                                </tr>
                            </thead>
                            <tbody id="breakdownTbody">
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Loading call data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>

        <!-- ================= TAB CONTENT: RATES ================= -->
        <div class="tab-panel" id="panelRates">
            
            <div class="config-card">
                <div class="panel-title" style="margin-bottom: 20px; font-size: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">Configure Global Billing Rates</div>
                
                <div class="success-box" id="successToast">
                    <strong>Success!</strong> Rate configurations saved successfully.
                </div>

                <div class="info-box">
                    Rates configured here are stored dynamically. Any calls synced in reports will compute using these active rates. Historical data remains locked with the rate active at calculation time.
                </div>

                <form id="frmRates" onsubmit="saveRatesConfig(event)">
                    <div class="config-group">
                        <label for="txtTalkRate">Talk Time Rate (per minute)</label>
                        <div class="config-input-wrap">
                            <span class="config-prefix">$</span>
                            <input type="number" step="0.0001" min="0" id="txtTalkRate" class="config-input" required placeholder="0.05">
                        </div>
                    </div>

                    <div class="config-group">
                        <label for="txtWaitRate">Wait & Hold Rate (per minute)</label>
                        <div class="config-input-wrap">
                            <span class="config-prefix">$</span>
                            <input type="number" step="0.0001" min="0" id="txtWaitRate" class="config-input" required placeholder="0.02">
                        </div>
                    </div>

                    <div class="config-group">
                        <label for="txtFlatFee">Flat Fee (per call surcharge)</label>
                        <div class="config-input-wrap">
                            <span class="config-prefix">$</span>
                            <input type="number" step="0.0001" min="0" id="txtFlatFee" class="config-input" required placeholder="0.10">
                        </div>
                    </div>

                    <button type="submit" class="btn-save">Save Rate Configurations</button>
                </form>

                <div class="notice-box">
                    <strong>Database Structure Note:</strong> nullable columns <code>agent_id</code> and <code>queue_id</code> exist in the database table schema, allowing for future expansion to per-agent or per-queue custom rates without schema redesign.
                </div>
            </div>

        </div>

    </main>

    <script>
        let searchTimeout = null;

        function switchTab(tabId) {
            document.querySelectorAll('.portal-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

            if (tabId === 'report') {
                document.getElementById('tabBtnReport').classList.add('active');
                document.getElementById('panelReport').classList.add('active');
            } else {
                document.getElementById('tabBtnRates').classList.add('active');
                document.getElementById('panelRates').classList.add('active');
                loadRatesConfig();
            }
        }

        function setRange(days) {
            document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            event.target.classList.add('active');

            const to = new Date();
            const from = new Date();
            from.setDate(to.getDate() - days);

            document.getElementById('fTo').value = to.toISOString().split('T')[0];
            document.getElementById('fFrom').value = from.toISOString().split('T')[0];

            loadBillingSummary();
        }

        function triggerSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadBillingSummary();
            }, 300);
        }

        function formatDuration(sec) {
            if (sec <= 0) return '0s';
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return m > 0 ? `${m}m ${s}s` : `${s}s`;
        }

        function formatCost(val) {
            return '$' + parseFloat(val).toFixed(2);
        }

        function loadBillingSummary() {
            const from = document.getElementById('fFrom').value;
            const to = document.getElementById('fTo').value;
            const search = document.getElementById('txtSearch').value;
            const status = document.getElementById('loadStatus');

            status.textContent = 'Recalculating...';

            fetch(`index.php?api=summary&from=${from}&to=${to}&search=${encodeURIComponent(search)}`)
                .then(res => res.json())
                .then(data => {
                    status.textContent = '';
                    if (data.error) {
                        alert('Sync failed: ' + data.error);
                        return;
                    }

                    // Update KPIs
                    document.getElementById('lblTotalCalls').textContent   = data.kpis.total_calls;
                    document.getElementById('lblTotalTalk').textContent    = data.kpis.total_talk + 'm';
                    document.getElementById('lblTotalWait').textContent    = data.kpis.total_wait + 'm';
                    document.getElementById('lblTotalCost').textContent    = formatCost(data.kpis.total_cost);
                    document.getElementById('lblInboundCalls').textContent  = data.kpis.inbound_calls;
                    document.getElementById('lblInboundCost').textContent   = formatCost(data.kpis.inbound_cost);
                    document.getElementById('lblOutboundCalls').textContent = data.kpis.outbound_calls;
                    document.getElementById('lblOutboundCost').textContent  = formatCost(data.kpis.outbound_cost);

                    // Render Breakdown
                    const breakdownTbody = document.getElementById('breakdownTbody');
                    if (data.breakdown.length === 0) {
                        breakdownTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">No call records found.</td></tr>';
                    } else {
                        breakdownTbody.innerHTML = data.breakdown.map(c => {
                            const isOut = c.direction === 'Outbound';
                            const badge = `<span class="dir-badge ${isOut ? 'outbound' : 'inbound'}">${c.direction}</span>`;
                            return `
                            <tr>
                                <td>${c.date}</td>
                                <td><strong>${c.agent}</strong></td>
                                <td>${badge}</td>
                                <td>${formatDuration(c.talk_sec)}</td>
                                <td>${formatDuration(c.wait_sec)}</td>
                                <td style="font-weight:700;color:var(--clr-success)">${formatCost(c.cost)}</td>
                            </tr>`;
                        }).join('');
                    }

                    // Render Summary
                    const agentTbody = document.getElementById('agentTbody');
                    if (data.agent_summary.length === 0) {
                        agentTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">No summaries available.</td></tr>';
                    } else {
                        agentTbody.innerHTML = data.agent_summary.map(a => `
                            <tr>
                                <td><strong>${a.agent}</strong></td>
                                <td style="text-align:center">${a.calls_count}</td>
                                <td>${parseFloat(a.total_talk).toFixed(1)}m</td>
                                <td>${parseFloat(a.total_wait).toFixed(1)}m</td>
                                <td style="font-weight:700;color:#1d4ed8">${formatCost(a.inbound_cost ?? 0)}</td>
                                <td style="font-weight:700;color:#c2410c">${formatCost(a.outbound_cost ?? 0)}</td>
                                <td style="font-weight:700;color:var(--clr-success)">${formatCost(a.total_cost)}</td>
                            </tr>
                        `).join('');
                    }
                })
                .catch(err => {
                    status.textContent = '';
                    alert('Request failed: ' + err);
                });
        }

        function loadRatesConfig() {
            fetch('index.php?api=rates')
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert('Failed to load rates: ' + data.error);
                        return;
                    }
                    document.getElementById('txtTalkRate').value = parseFloat(data.talk_rate).toFixed(4);
                    document.getElementById('txtWaitRate').value = parseFloat(data.wait_rate).toFixed(4);
                    document.getElementById('txtFlatFee').value = parseFloat(data.flat_fee).toFixed(4);
                })
                .catch(err => {
                    alert('Network error loading rates: ' + err);
                });
        }

        function saveRatesConfig(e) {
            e.preventDefault();

            const talk = document.getElementById('txtTalkRate').value;
            const wait = document.getElementById('txtWaitRate').value;
            const flat = document.getElementById('txtFlatFee').value;

            const fd = new FormData();
            fd.append('talk_rate', talk);
            fd.append('wait_rate', wait);
            fd.append('flat_fee', flat);

            fetch('index.php?api=rates', {
                method: 'POST',
                body: fd
            })
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert('Save failed: ' + data.error);
                        return;
                    }
                    const toast = document.getElementById('successToast');
                    toast.style.display = 'block';
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 4000);
                })
                .catch(err => {
                    alert('Network error saving rates: ' + err);
                });
        }

        // Initialize report summary on load
        document.addEventListener('DOMContentLoaded', () => {
            loadBillingSummary();
        });
    </script>
</body>
</html>
