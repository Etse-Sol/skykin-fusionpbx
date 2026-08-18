<?php
// SkyKin Technologies – Call Billing System
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

$is_api = isset($_GET['api']) || isset($_GET['action']);
skykin_require_groups(['superadmin', 'admin', 'supervisor'], $is_api);

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = skykin_default_domain();
$domain  = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$today   = date('Y-m-d');

// ── DB helper ──────────────────────────────────────────────────────────────
function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

// ── V4 UUID generator helper ───────────────────────────────────────────────
function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// ── Database Table Auto-Setup & Seeding ────────────────────────────────────
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
        $domain_uuid = $_SESSION['domain_uuid'] ?? null;
        $stmt = $db->prepare("INSERT INTO billing_rates (billing_rate_uuid, domain_uuid, rate_name, talk_rate, wait_rate, flat_fee) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$rate_uuid, $domain_uuid, 'Global Rate', 0.05, 0.02, 0.10]);
    }
} catch (Exception $e) {
    if ($is_api) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database setup failed: ' . $e->getMessage()]);
        exit;
    } else {
        die('Database setup failed: ' . $e->getMessage());
    }
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

                // direction: 'inbound' = queue/IVR inbound call; 'local' = agent-initiated outbound call
                // For outbound/local calls waitsec is naturally 0 (no queue), so the same formula works for both.
                // Formula: cost = (talk_time_minutes × talk_rate) + (wait_time_minutes × wait_rate) + flat_fee
                $direction = $call['direction'] ?? 'inbound';
                $cost = ($talk_min * (float)$global_rate['talk_rate']) + ($wait_min * (float)$global_rate['wait_rate']) + (float)$global_rate['flat_fee'];

                $uuid = generate_uuid();
                $insert_stmt->execute([
                    ':uuid' => $uuid,
                    ':domain_uuid' => $call['domain_uuid'],
                    ':xml_cdr_uuid' => $call['xml_cdr_uuid'],
                    ':talk_rate' => $global_rate['talk_rate'],
                    ':wait_rate' => $global_rate['wait_rate'],
                    ':flat_fee' => $global_rate['flat_fee'],
                    ':talk_time' => $talk_min,
                    ':wait_time' => $wait_min,
                    ':cost' => $cost,
                    ':direction' => $direction
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

// ── JSON API endpoints ───────────────────────────────────────────────────────
if ($is_api) {
    error_reporting(0);
    header('Content-Type: application/json');

    $api = $_GET['api'] ?? $_GET['action'] ?? '';
    $dom = skykin_domain_param($_GET['domain'] ?? null);

    try {
        // GET / UPDATE Rates Config
        if ($api === 'rates') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $talk = (float)($_POST['talk_rate'] ?? 0.0);
                $wait = (float)($_POST['wait_rate'] ?? 0.0);
                $flat = (float)($_POST['flat_fee'] ?? 0.0);

                // Update global rate
                $stmt = $db->prepare("UPDATE billing_rates SET talk_rate = :talk, wait_rate = :wait, flat_fee = :flat, updated_at = CURRENT_TIMESTAMP WHERE agent_id IS NULL AND queue_id IS NULL");
                $stmt->execute([':talk' => $talk, ':wait' => $wait, ':flat' => $flat]);

                echo json_encode(['ok' => true]);
                exit;
            } else {
                $stmt = $db->prepare("SELECT talk_rate, wait_rate, flat_fee FROM billing_rates WHERE agent_id IS NULL AND queue_id IS NULL LIMIT 1");
                $stmt->execute();
                $rate = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($rate ?: ['talk_rate' => 0.0, 'wait_rate' => 0.0, 'flat_fee' => 0.0]);
                exit;
            }
        }

        // GET Billing report metrics
        if ($api === 'summary') {
            $date_from = $_GET['from'] ?? date('Y-m-d');
            $date_to   = $_GET['to'] ?? date('Y-m-d');
            
            $ts = strtotime($date_from . ' 00:00:00');
            $te = strtotime($date_to   . ' 23:59:59');

            // Sync missing billing records
            syncBilling($db, $dom, $ts, $te);

            // Query complete list of billing items joined with call records
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
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute KPIs
            $total_calls   = count($calls);
            $total_talk    = 0.0;
            $total_wait    = 0.0;
            $total_cost    = 0.0;
            $inbound_calls = 0;
            $inbound_cost  = 0.0;
            $outbound_calls = 0;
            $outbound_cost  = 0.0;

            foreach ($calls as $c) {
                $total_talk += (float)$c['talk_time_minutes'];
                $total_wait += (float)$c['wait_time_minutes'];
                $total_cost += (float)$c['calculated_cost'];
                $dir = $c['call_direction'] ?? $c['direction'] ?? 'inbound';
                if ($dir === 'inbound') {
                    $inbound_calls++;
                    $inbound_cost += (float)$c['calculated_cost'];
                } else {
                    // 'local' = agent-initiated outbound (dashboard, mobile app, callback)
                    $outbound_calls++;
                    $outbound_cost += (float)$c['calculated_cost'];
                }
            }

            // Compute Agent Summary
            $agent_data = [];
            foreach ($calls as $c) {
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

                if (!isset($agent_data[$agent])) {
                    $agent_data[$agent] = [
                        'agent'         => $agent,
                        'calls_count'   => 0,
                        'total_talk'    => 0.0,
                        'total_wait'    => 0.0,
                        'inbound_cost'  => 0.0,
                        'outbound_cost' => 0.0,
                        'total_cost'    => 0.0
                    ];
                }
                $agent_data[$agent]['calls_count']++;
                $agent_data[$agent]['total_talk'] += (float)$c['talk_time_minutes'];
                $agent_data[$agent]['total_wait'] += (float)$c['wait_time_minutes'];
                $agent_data[$agent]['total_cost'] += (float)$c['calculated_cost'];
                $ag_dir = $c['call_direction'] ?? $c['direction'] ?? 'inbound';
                if ($ag_dir === 'inbound') {
                    $agent_data[$agent]['inbound_cost'] += (float)$c['calculated_cost'];
                } else {
                    $agent_data[$agent]['outbound_cost'] += (float)$c['calculated_cost'];
                }
            }

            // Convert to indexed list & sort by cost DESC
            $agent_summary = array_values($agent_data);
            usort($agent_summary, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);

            // Format breakdown rows for JSON
            $breakdown = [];
            foreach ($calls as $c) {
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

                // Translate raw CDR direction to a human-readable label
                $raw_dir = $c['call_direction'] ?? $c['direction'] ?? 'inbound';
                $dir_label = ($raw_dir === 'local') ? 'Outbound' : ucfirst($raw_dir);

                $breakdown[] = [
                    'date'      => date('Y-m-d H:i:s', $c['start_epoch']),
                    'agent'     => $agent,
                    'direction' => $dir_label,
                    'talk_sec'  => (int)$c['billsec'],
                    'wait_sec'  => (int)$c['waitsec'],
                    'cost'      => (float)$c['calculated_cost']
                ];
            }

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
                'breakdown'     => $breakdown,
                'agent_summary' => $agent_summary
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkyKin – Call Billing Portal</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#333;min-height:100vh}

/* ─── Top Nav ─────────────────────────────── */
.topbar{background:linear-gradient(135deg,#0047AB,#00B4D8);border-bottom:1px solid #e0e0e0;padding:0 24px;height:56px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:20px}
.brand{font-weight:700;font-size:17px;color:#fff;letter-spacing:.5px}
.brand span{color:rgba(255,255,255,.8);font-weight:400}
.nav-links{display:flex;gap:4px}
.nav-links a{color:rgba(255,255,255,.75);text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;transition:.2s}
.nav-links a:hover,.nav-links a.active{background:rgba(255,255,255,.2);color:#fff}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:13px;color:rgba(255,255,255,.8)}
.user-pill{background:rgba(255,255,255,.15);padding:5px 12px;border-radius:20px;color:#fff;font-size:12px}

/* ─── Tabs Select ─────────────────────────── */
.tab-select-bar{background:#ffffff;border-bottom:1px solid #e0e0e0;padding:0 24px;display:flex;gap:24px;height:44px}
.tab-select-btn{background:none;border:none;border-bottom:3px solid transparent;color:#666;font-size:14px;font-weight:600;
  cursor:pointer;padding:0 4px;transition:.2s;height:100%}
.tab-select-btn:hover{color:#0047AB}
.tab-select-btn.active{border-color:#0047AB;color:#0047AB}

/* ─── Filters & Page layout ──────────────── */
.filters{background:#ffffff;border-bottom:1px solid #e0e0e0;padding:12px 24px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filters label{font-size:12px;color:#888}
.filters input{background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:6px 10px;border-radius:6px;font-size:13px}
.filters input:focus{outline:none;border-color:#58a6ff}
.btn-filter{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;
  cursor:pointer;font-size:13px;font-weight:500}
.btn-filter:hover{background:#2ea043}
.range-presets{display:flex;gap:6px}
.preset-btn{background:#f0f2f5;border:1px solid #e0e0e0;color:#888;padding:5px 10px;
  border-radius:6px;cursor:pointer;font-size:12px}
.preset-btn:hover,.preset-btn.active{background:#388bfd22;border-color:#58a6ff;color:#58a6ff}

.page{padding:20px 24px;max-width:1400px;margin:0 auto}
.tab-content{display:none}
.tab-content.active{display:block}

/* ─── KPI Cards ───────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.kpi-card{min-height:92px;background:#fff;border:1px solid #edf0f5;border-top:3px solid #0047AB;
  border-radius:10px;padding:14px 16px;box-shadow:0 1px 5px rgba(0,0,0,.05);
  display:flex;flex-direction:column;justify-content:center}
.kpi-value{font-size:26px;font-weight:700;line-height:1;color:#0047AB}
.kpi-label{order:2;font-size:11px;color:#777;margin-top:7px}
.kpi-sub{order:3;font-size:10px;color:#999;margin-top:3px}
.kpi-card.green{border-top-color:#28a745}.kpi-card.green .kpi-value{color:#28a745}
.kpi-card.red{border-top-color:#dc3545}.kpi-card.red .kpi-value{color:#dc3545}
.kpi-card.yellow{border-top-color:#fd7e14}.kpi-card.yellow .kpi-value{color:#e65100}

/* ─── Direction badges ────────────────────── */
.dir-badge{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.4px;
  padding:2px 8px;border-radius:12px;text-transform:uppercase}
.dir-badge.inbound{background:#dbeafe;color:#1d4ed8}
.dir-badge.outbound{background:#ffedd5;color:#c2410c}

/* ─── Grid breakdown ──────────────────────── */
.report-grid{display:grid;grid-template-columns:1fr;gap:20px}
@media (min-width: 992px) {
  .report-grid { grid-template-columns: 1.2fr 1.8fr; }
}
.card{background:#ffffff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;margin-bottom:20px}
.card-title{font-size:14px;font-weight:600;color:#333;margin-bottom:16px}
.table-wrap{max-height:500px;overflow-y:auto;border:1px solid #eee;border-radius:6px}

.data-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
.data-table th{background:#f8f9fa;color:#666;padding:10px 12px;text-align:left;
  font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;position:sticky;top:0;z-index:5}
.data-table td{padding:10px 12px;border-bottom:1px solid #eee;color:#444}
.data-table tr:hover td{background:#f8f9fa}
.data-table tr:last-child td{border-bottom:none}

/* ─── Rate settings form ────────────────── */
.rate-form-wrap{max-width:550px}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:6px}
.form-group input{width:100%;background:#f9fafb;border:1px solid #d1d5db;color:#333;
  padding:10px 12px;border-radius:6px;font-size:14px;transition:.2s}
.form-group input:focus{outline:none;border-color:#58a6ff;background:#fff;box-shadow:0 0 0 3px rgba(88,166,255,0.15)}
.btn-save{background:#0047AB;color:#fff;border:none;padding:10px 20px;border-radius:6px;
  cursor:pointer;font-size:14px;font-weight:600;transition:.2s;width:100%}
.btn-save:hover{background:#003c96}

.toast-message{display:none;background:#dcfce7;border:1px solid #86efac;color:#166534;
  padding:12px;border-radius:6px;font-size:13px;margin-bottom:16px}
.info-box{background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1;padding:14px;border-radius:8px;font-size:13px;line-height:1.5;margin-bottom:20px}
.alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:12px;border-radius:6px;font-size:12px}

</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <div class="brand">SkyKin<span> Technologies</span></div>
    <nav class="nav-links">
      <a href="/app/agent_dashboard/supervisor.php">Supervisor</a>
      <a href="/app/agent_dashboard/reports.php">Reports</a>
      <a href="/app/agent_dashboard/evaluation.php">Evaluation</a>
      <a href="/app/agent_dashboard/crm.php">CRM</a>
      <a href="/app/agent_dashboard/billing.php" class="active">Billing</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($domain); ?></div>
    <a href="/logout.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>

<div class="tab-select-bar">
  <button class="tab-select-btn active" onclick="switchTab('report')">Billing Report</button>
  <button class="tab-select-btn" onclick="switchTab('rates')">Rate Configurations</button>
</div>

<!-- Filters (only visible on Report Tab) -->
<div class="filters" id="billingFilters">
  <label>From</label>
  <input type="date" id="fFrom" value="<?php echo $today; ?>">
  <label>To</label>
  <input type="date" id="fTo" value="<?php echo $today; ?>">
  <div class="range-presets">
    <button class="preset-btn active" onclick="setRange(0,'today')">Today</button>
    <button class="preset-btn" onclick="setRange(7,'7d')">7 Days</button>
    <button class="preset-btn" onclick="setRange(30,'30d')">30 Days</button>
  </div>
  <button class="btn-filter" onclick="loadBillingSummary()">&#128200; Refresh</button>
  <span id="loadStatus" style="font-size:12px;color:#888"></span>
</div>

<div class="page">

  <!-- ================= TAB: REPORT ================= -->
  <div class="tab-content active" id="tab-report">
    
    <!-- KPI Summary Grid -->
    <div class="kpi-grid">
      <div class="kpi-card blue">
        <div class="kpi-value" id="kpiTotalCalls">0</div>
        <div class="kpi-label">Total Calls</div>
        <div class="kpi-sub">Calculated for period</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-value" id="kpiTotalTalk">0.0m</div>
        <div class="kpi-label">Total Talk Time</div>
        <div class="kpi-sub">Minutes of talk time</div>
      </div>
      <div class="kpi-card red">
        <div class="kpi-value" id="kpiTotalWait">0.0m</div>
        <div class="kpi-label">Total Wait/Hold Time</div>
        <div class="kpi-sub">Minutes of wait/hold time</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-value" id="kpiTotalCost">$0.00</div>
        <div class="kpi-label">Total Cost</div>
        <div class="kpi-sub">Sum of all billing calculations</div>
      </div>
      <div class="kpi-card" style="border-top-color:#1d4ed8">
        <div class="kpi-value" style="color:#1d4ed8" id="kpiInboundCalls">0</div>
        <div class="kpi-label">Inbound Calls</div>
        <div class="kpi-sub" id="kpiInboundCost" style="color:#1d4ed8;font-weight:600">$0.00</div>
      </div>
      <div class="kpi-card" style="border-top-color:#c2410c">
        <div class="kpi-value" style="color:#c2410c" id="kpiOutboundCalls">0</div>
        <div class="kpi-label">Outbound Calls</div>
        <div class="kpi-sub" id="kpiOutboundCost" style="color:#c2410c;font-weight:600">$0.00</div>
      </div>
    </div>

    <!-- Breakdown and Summary tables -->
    <div class="report-grid">
      
      <!-- Agent Cost Summary -->
      <div class="card">
        <div class="card-title">Per-Agent Cost Summary</div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Agent</th>
                <th style="text-align:center">Calls</th>
                <th>Total Talk</th>
                <th>Total Wait</th>
                <th>Inbound Cost</th>
                <th>Outbound Cost</th>
                <th>Total Cost</th>
              </tr>
            </thead>
            <tbody id="agentTbody">
              <tr>
                <td colspan="7" style="text-align:center;color:#999;padding:24px;">No summaries available.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Call Cost Breakdown -->
      <div class="card">
        <div class="card-title">Per-Call Cost Breakdown</div>
        <div class="table-wrap">
          <table class="data-table">
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
                <td colspan="6" style="text-align:center;color:#999;padding:24px;">No data loaded. Click Refresh to query database.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </div>

  <!-- ================= TAB: RATES ================= -->
  <div class="tab-content" id="tab-rates">
    
    <div class="card rate-form-wrap">
      <div class="card-title">Configure Global Billing Rates</div>
      
      <div class="toast-message" id="toastSaveSuccess">
        <strong>Success!</strong> Billing rates have been updated successfully.
      </div>

      <div class="info-box">
        Rates are applied to calls when cost calculation is sync'd. Changes will automatically apply to any new calls calculated going forward. Existing billing records maintain the rates active at that call's creation.
      </div>

      <form id="rateForm" onsubmit="saveRates(event)">
        <div class="form-group">
          <label for="talkRate">Talk Rate (per minute)</label>
          <input type="number" step="0.0001" min="0" id="talkRate" required placeholder="0.05">
        </div>
        <div class="form-group">
          <label for="waitRate">Wait/Hold Rate (per minute)</label>
          <input type="number" step="0.0001" min="0" id="waitRate" required placeholder="0.02">
        </div>
        <div class="form-group">
          <label for="flatFee">Flat Fee per Call</label>
          <input type="number" step="0.0001" min="0" id="flatFee" required placeholder="0.10">
        </div>
        <button type="submit" class="btn-save">Save Rate Configurations</button>
      </form>
    </div>

    <div class="card rate-form-wrap" style="margin-top:20px">
      <div class="card-title" style="color:#666">Future Overrides Preview</div>
      <p style="font-size:13px;color:#777;line-height:1.5;margin-bottom:12px">
        The database tables are designed to support agent-specific and queue-specific rate plans in the future.
      </p>
      <div class="alert-warning">
        Currently active: <strong>Global rate set</strong>. Single-agent or queue overrides can be added later by populating the nullable <code>agent_id</code> or <code>queue_id</code> fields in the <code>billing_rates</code> table.
      </div>
    </div>

  </div>

</div>

<script>
// --- State management ---
let activeTab = 'report';

function switchTab(tabName) {
  activeTab = tabName;
  
  // Update nav buttons active classes
  document.querySelectorAll('.tab-select-btn').forEach(btn => {
    btn.classList.remove('active');
  });
  const activeBtn = Array.from(document.querySelectorAll('.tab-select-btn')).find(b => b.textContent.toLowerCase().includes(tabName));
  if (activeBtn) activeBtn.classList.add('active');

  // Update tabs contents
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + tabName).classList.add('active');

  // Show/hide filters
  const filters = document.getElementById('billingFilters');
  if (tabName === 'report') {
    filters.style.display = 'flex';
  } else {
    filters.style.display = 'none';
    loadRatesConfig();
  }
}

function setRange(days, preset) {
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');

  const to = new Date();
  const from = new Date();
  from.setDate(to.getDate() - days);

  document.getElementById('fTo').value = to.toISOString().split('T')[0];
  document.getElementById('fFrom').value = from.toISOString().split('T')[0];
  
  loadBillingSummary();
}

function formatDuration(sec) {
  if (sec <= 0) return '0s';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function formatCost(num) {
  return '$' + parseFloat(num).toFixed(2);
}

// --- Fetch summary data ---
function loadBillingSummary() {
  const from = document.getElementById('fFrom').value;
  const to = document.getElementById('fTo').value;
  const loadStatus = document.getElementById('loadStatus');
  
  loadStatus.textContent = 'Calculating & Loading...';

  fetch(`billing.php?api=summary&from=${from}&to=${to}`)
    .then(res => res.json())
    .then(data => {
      loadStatus.textContent = '';
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }

      // Update KPIs
      document.getElementById('kpiTotalCalls').textContent  = data.kpis.total_calls;
      document.getElementById('kpiTotalTalk').textContent   = data.kpis.total_talk + 'm';
      document.getElementById('kpiTotalWait').textContent   = data.kpis.total_wait + 'm';
      document.getElementById('kpiTotalCost').textContent   = formatCost(data.kpis.total_cost);
      document.getElementById('kpiInboundCalls').textContent  = data.kpis.inbound_calls;
      document.getElementById('kpiInboundCost').textContent   = formatCost(data.kpis.inbound_cost);
      document.getElementById('kpiOutboundCalls').textContent = data.kpis.outbound_calls;
      document.getElementById('kpiOutboundCost').textContent  = formatCost(data.kpis.outbound_cost);

      // Render breakdown tbody
      const breakdownTbody = document.getElementById('breakdownTbody');
      if (data.breakdown.length === 0) {
        breakdownTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#999;padding:24px;">No completed calls found in this date range.</td></tr>';
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
            <td style="color:#28a745;font-weight:600">${formatCost(c.cost)}</td>
          </tr>`;
        }).join('');
      }

      // Render agent summary tbody
      const agentTbody = document.getElementById('agentTbody');
      if (data.agent_summary.length === 0) {
        agentTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;padding:24px;">No summaries available.</td></tr>';
      } else {
        agentTbody.innerHTML = data.agent_summary.map(a => `
          <tr>
            <td><strong>${a.agent}</strong></td>
            <td style="text-align:center">${a.calls_count}</td>
            <td>${parseFloat(a.total_talk).toFixed(1)}m</td>
            <td>${parseFloat(a.total_wait).toFixed(1)}m</td>
            <td style="color:#1d4ed8;font-weight:600">${formatCost(a.inbound_cost ?? 0)}</td>
            <td style="color:#c2410c;font-weight:600">${formatCost(a.outbound_cost ?? 0)}</td>
            <td style="color:#28a745;font-weight:600">${formatCost(a.total_cost)}</td>
          </tr>
        `).join('');
      }
    })
    .catch(err => {
      loadStatus.textContent = '';
      alert('Network error loading billing summary: ' + err);
    });
}

// --- Fetch rates config ---
function loadRatesConfig() {
  fetch('billing.php?api=rates')
    .then(res => res.json())
    .then(data => {
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }
      document.getElementById('talkRate').value = parseFloat(data.talk_rate).toFixed(4);
      document.getElementById('waitRate').value = parseFloat(data.wait_rate).toFixed(4);
      document.getElementById('flatFee').value = parseFloat(data.flat_fee).toFixed(4);
    })
    .catch(err => {
      alert('Network error loading rates configuration: ' + err);
    });
}

// --- Save rates config ---
function saveRates(event) {
  event.preventDefault();
  
  const talk_rate = document.getElementById('talkRate').value;
  const wait_rate = document.getElementById('waitRate').value;
  const flat_fee = document.getElementById('flatFee').value;

  const fd = new FormData();
  fd.append('talk_rate', talk_rate);
  fd.append('wait_rate', wait_rate);
  fd.append('flat_fee', flat_fee);

  fetch('billing.php?api=rates', {
    method: 'POST',
    body: fd
  })
    .then(res => res.json())
    .then(data => {
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }
      const toast = document.getElementById('toastSaveSuccess');
      toast.style.display = 'block';
      setTimeout(() => {
        toast.style.display = 'none';
      }, 4000);
    })
    .catch(err => {
      alert('Network error saving configuration: ' + err);
    });
}

// Default initialization
document.addEventListener('DOMContentLoaded', () => {
  loadBillingSummary();
});
</script>
<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
</body>
</html>
