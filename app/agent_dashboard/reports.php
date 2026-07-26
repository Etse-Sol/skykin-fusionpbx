<?php
// SkyKin Technologies – Reports Dashboard

$fpbx_session_path = '/var/lib/php/sessions';
if (is_dir($fpbx_session_path)) session_save_path($fpbx_session_path);
session_name('PHPSESSID');
session_start();

if (empty($_SESSION['user_uuid'])) {
    header('Location: /login/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$allowed_groups = ['superadmin','admin','supervisor'];
$raw_groups     = isset($_SESSION['groups']) ? $_SESSION['groups'] : [];
$user_groups    = [];
array_walk_recursive($raw_groups, function($v) use (&$user_groups) {
    if (is_string($v)) foreach (array_map('trim', explode(',', $v)) as $g) if ($g !== '') $user_groups[] = strtolower($g);
});
if (empty(array_intersect($allowed_groups, $user_groups))) {
    http_response_code(403); echo 'Access Denied'; exit;
}

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = $_SESSION['domain_name'] ?? 'client1.skykin.local';
$domain  = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : $logged_in_domain;
$today   = date('Y-m-d');

// ── DB helper ──────────────────────────────────────────────────────────────
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
    $db = new PDO("pgsql:host={$h};port={$p};dbname={$n}",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    return $db;
}

// ── JSON API endpoints ───────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    error_reporting(0);
    header('Content-Type: application/json');
    $api    = $_GET['api'];
    $dom    = $_GET['domain']  ?? 'client1.skykin.local';
    $from   = $_GET['from']    ?? date('Y-m-d', strtotime('-7 days'));
    $to     = $_GET['to']      ?? date('Y-m-d');
    $ts     = strtotime($from.' 00:00:00');
    $te     = strtotime($to.' 23:59:59');

    try {
        $db = getDB();

        // ── Daily call volume ──────────────────────────────────────────────
        if ($api === 'daily_volume') {
            $s = $db->prepare("SELECT
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD') as day,
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN direction='inbound'  THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                GROUP BY day ORDER BY day");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Hourly heatmap ────────────────────────────────────────────────
        if ($api === 'hourly_heatmap') {
            $s = $db->prepare("SELECT
                EXTRACT(DOW FROM to_timestamp(start_epoch))::int as dow,
                EXTRACT(HOUR FROM to_timestamp(start_epoch))::int as hour,
                COUNT(*) as total
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                GROUP BY dow, hour ORDER BY dow, hour");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Agent performance ─────────────────────────────────────────────
        if ($api === 'agent_performance') {
            // Get all extensions for this domain
            $s = $db->prepare("SELECT e.extension,
                COALESCE(e.effective_caller_id_name, e.extension) as name
                FROM v_extensions e JOIN v_domains d ON d.domain_uuid=e.domain_uuid
                WHERE d.domain_name=:d ORDER BY e.extension");
            $s->execute([':d'=>$dom]);
            $exts = $s->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($exts as $ex) {
                $ext = $ex['extension'];
                $s2 = $db->prepare("SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                    COALESCE(SUM(billsec),0) as total_talk,
                    ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur,
                    SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound,
                    SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound
                    FROM v_xml_cdr WHERE domain_name=:d
                    AND (caller_id_number=:e OR destination_number=:e)
                    AND start_epoch>=:ts AND start_epoch<=:te");
                $s2->execute([':d'=>$dom,':e'=>$ext,':ts'=>$ts,':te'=>$te]);
                $r = $s2->fetch(PDO::FETCH_ASSOC);
                $answered = (int)($r['answered'] ?? 0);
                $total    = (int)($r['total']    ?? 0);
                $rows[] = [
                    'ext'        => $ext,
                    'name'       => $ex['name'],
                    'total'      => $total,
                    'answered'   => $answered,
                    'missed'     => (int)($r['missed']     ?? 0),
                    'inbound'    => (int)($r['inbound']    ?? 0),
                    'outbound'   => (int)($r['outbound']   ?? 0),
                    'total_talk' => (int)($r['total_talk'] ?? 0),
                    'avg_dur'    => (int)($r['avg_dur']    ?? 0),
                    'answer_rate'=> $total > 0 ? round($answered/$total*100,1) : 0,
                ];
            }
            // Sort by total calls desc
            usort($rows, fn($a,$b) => $b['total'] - $a['total']);
            echo json_encode($rows);
            exit;
        }

        // ── Summary KPIs ──────────────────────────────────────────────────
        if ($api === 'summary') {
            $s = $db->prepare("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                COALESCE(SUM(billsec),0) as total_talk,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur,
                ROUND(AVG(CASE WHEN billsec=0 THEN 1 ELSE 0 END)*100::numeric,1) as abandon_rate
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetch(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Queue SLA ─────────────────────────────────────────────────────
        if ($api === 'queue_sla') {
            $s = $db->prepare("SELECT
                destination_number as queue_num,
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur
                FROM v_xml_cdr WHERE domain_name=:d
                AND start_epoch>=:ts AND start_epoch<=:te
                AND (destination_number ~ '^[89][0-9]{3}$' OR destination_number LIKE '800%')
                GROUP BY destination_number ORDER BY total DESC LIMIT 20");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── CSV export of all CDRs ────────────────────────────────────────
        if ($api === 'export_csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="skykin_cdr_'.date('Ymd').'.csv"');
            $s = $db->prepare("SELECT
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI:SS') as call_time,
                caller_id_name, caller_id_number, destination_number,
                direction, duration, billsec, hangup_cause,
                record_name
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                ORDER BY start_epoch DESC LIMIT 5000");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            $out = fopen('php://output','w');
            fputcsv($out,['Time','Caller Name','Caller Number','Destination','Direction','Duration(s)','Billsec(s)','Hangup Cause','Recording']);
            while ($r = $s->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($r));
            fclose($out);
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
<title>SkyKin – Reports</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0d1117;color:#e6edf3;min-height:100vh}

/* ─── Top Nav ─────────────────────────────── */
.topbar{background:#161b22;border-bottom:1px solid #30363d;padding:0 24px;height:56px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:20px}
.brand{font-weight:700;font-size:17px;color:#58a6ff;letter-spacing:.5px}
.brand span{color:#e6edf3;font-weight:400}
.nav-links{display:flex;gap:4px}
.nav-links a{color:#8b949e;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;transition:.2s}
.nav-links a:hover,.nav-links a.active{background:#21262d;color:#e6edf3}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:13px;color:#8b949e}
.user-pill{background:#21262d;padding:5px 12px;border-radius:20px;color:#e6edf3;font-size:12px}

/* ─── Filters ─────────────────────────────── */
.filters{background:#161b22;border-bottom:1px solid #30363d;padding:12px 24px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filters label{font-size:12px;color:#8b949e}
.filters input,.filters select{background:#0d1117;border:1px solid #30363d;color:#e6edf3;
  padding:6px 10px;border-radius:6px;font-size:13px}
.filters input:focus,.filters select:focus{outline:none;border-color:#58a6ff}
.btn-filter{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;
  cursor:pointer;font-size:13px;font-weight:500}
.btn-filter:hover{background:#2ea043}
.btn-export{background:#21262d;color:#58a6ff;border:1px solid #30363d;padding:7px 14px;
  border-radius:6px;cursor:pointer;font-size:13px}
.btn-export:hover{background:#30363d}
.range-presets{display:flex;gap:6px}
.preset-btn{background:#21262d;border:1px solid #30363d;color:#8b949e;padding:5px 10px;
  border-radius:6px;cursor:pointer;font-size:12px}
.preset-btn:hover,.preset-btn.active{background:#388bfd22;border-color:#58a6ff;color:#58a6ff}

/* ─── Page layout ─────────────────────────── */
.page{padding:20px 24px;max-width:1400px;margin:0 auto}

/* ─── KPI cards ───────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px}
.kpi-label{font-size:11px;color:#8b949e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:28px;font-weight:700;color:#e6edf3;line-height:1}
.kpi-sub{font-size:11px;color:#8b949e;margin-top:4px}
.kpi-card.green .kpi-value{color:#3fb950}
.kpi-card.red .kpi-value{color:#f85149}
.kpi-card.blue .kpi-value{color:#58a6ff}
.kpi-card.yellow .kpi-value{color:#d29922}

/* ─── Chart cards ─────────────────────────── */
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.chart-card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:20px}
.chart-card.full{grid-column:1/-1}
.chart-title{font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:16px}
.chart-wrap{position:relative;height:240px}

/* ─── Agent table ─────────────────────────── */
.section-title{font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:12px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{background:#21262d;color:#8b949e;padding:10px 12px;text-align:left;
  font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.data-table td{padding:10px 12px;border-bottom:1px solid #21262d;color:#e6edf3;vertical-align:middle}
.data-table tr:hover td{background:#21262d22}
.data-table tr:last-child td{border-bottom:none}
.bar-cell{display:flex;align-items:center;gap:8px}
.bar-bg{flex:1;background:#21262d;border-radius:4px;height:6px;min-width:60px}
.bar-fill{height:6px;border-radius:4px;background:#58a6ff;transition:.5s}
.bar-fill.green{background:#3fb950}
.rank-badge{width:24px;height:24px;border-radius:50%;background:#21262d;color:#8b949e;
  font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
.rank-badge.gold{background:#d29922;color:#0d1117}
.rank-badge.silver{background:#8b949e;color:#0d1117}
.rank-badge.bronze{background:#bf8700;color:#0d1117}
.answer-rate{font-size:12px;font-weight:600}
.answer-rate.good{color:#3fb950}
.answer-rate.ok{color:#d29922}
.answer-rate.bad{color:#f85149}

/* ─── Queue table ─────────────────────────── */
.queue-table-wrap{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:20px;margin-bottom:20px}

/* ─── Loading ─────────────────────────────── */
.loading-overlay{display:flex;align-items:center;justify-content:center;height:80px;color:#8b949e;font-size:13px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <div class="brand">SkyKin<span> Technologies</span></div>
    <nav class="nav-links">
      <a href="/app/agent_dashboard/supervisor.php">Supervisor</a>
      <a href="/app/agent_dashboard/reports.php" class="active">Reports</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($domain); ?></div>
    <a href="/logout/index.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>

<!-- Filters -->
<div class="filters">
  <label>From</label>
  <input type="date" id="fFrom" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
  <label>To</label>
  <input type="date" id="fTo" value="<?php echo $today; ?>">
  <div class="range-presets">
    <button class="preset-btn" onclick="setRange(0,'today')">Today</button>
    <button class="preset-btn active" onclick="setRange(7,'7d')">7 Days</button>
    <button class="preset-btn" onclick="setRange(30,'30d')">30 Days</button>
    <button class="preset-btn" onclick="setRange(90,'90d')">90 Days</button>
  </div>
  <button class="btn-filter" onclick="loadAll()">&#128200; Refresh</button>
  <button class="btn-export" onclick="exportCSV()">&#11015; Export CSV</button>
  <span id="loadStatus" style="font-size:12px;color:#8b949e"></span>
</div>

<div class="page">

  <!-- KPI Summary -->
  <div class="kpi-grid" id="kpiGrid">
    <div class="kpi-card blue"><div class="kpi-label">Total Calls</div><div class="kpi-value" id="kpiTotal">—</div><div class="kpi-sub">in selected period</div></div>
    <div class="kpi-card green"><div class="kpi-label">Answered</div><div class="kpi-value" id="kpiAnswered">—</div><div class="kpi-sub" id="kpiAnswerRate">—%</div></div>
    <div class="kpi-card red"><div class="kpi-label">Missed</div><div class="kpi-value" id="kpiMissed">—</div><div class="kpi-sub" id="kpiAbandon">—% abandon</div></div>
    <div class="kpi-card"><div class="kpi-label">Inbound</div><div class="kpi-value" id="kpiInbound">—</div></div>
    <div class="kpi-card"><div class="kpi-label">Outbound</div><div class="kpi-value" id="kpiOutbound">—</div></div>
    <div class="kpi-card yellow"><div class="kpi-label">Avg Duration</div><div class="kpi-value" id="kpiAvgDur">—</div><div class="kpi-sub">seconds</div></div>
    <div class="kpi-card"><div class="kpi-label">Total Talk</div><div class="kpi-value" id="kpiTalkHrs">—</div><div class="kpi-sub">hours</div></div>
  </div>

  <!-- Charts row -->
  <div class="chart-grid">
    <div class="chart-card full">
      <div class="chart-title">Daily Call Volume</div>
      <div class="chart-wrap"><canvas id="chartVolume"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-title">Call Direction Split</div>
      <div class="chart-wrap"><canvas id="chartDirection"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-title">Answer vs Missed</div>
      <div class="chart-wrap"><canvas id="chartAM"></canvas></div>
    </div>
  </div>

  <!-- Agent Performance -->
  <div style="background:#161b22;border:1px solid #30363d;border-radius:10px;padding:20px;margin-bottom:20px">
    <div class="section-title">Agent Performance</div>
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Agent</th><th>Ext</th><th>Total</th>
        <th>Answered</th><th>Missed</th><th>Inbound</th><th>Outbound</th>
        <th>Talk Time</th><th>Avg Duration</th><th>Answer Rate</th>
      </tr></thead>
      <tbody id="agentBody"><tr><td colspan="11" class="loading-overlay">Loading...</td></tr></tbody>
    </table>
  </div>

  <!-- Queue SLA -->
  <div class="queue-table-wrap">
    <div class="section-title">Queue / IVR Statistics</div>
    <table class="data-table">
      <thead><tr><th>Number</th><th>Total</th><th>Answered</th><th>Answer Rate</th><th>Avg Duration</th></tr></thead>
      <tbody id="queueBody"><tr><td colspan="5" class="loading-overlay">Loading...</td></tr></tbody>
    </table>
  </div>

</div><!-- /page -->

<script>
const DOMAIN = '<?php echo $domain; ?>';
let charts = {};
let activePreset = '7d';

function setRange(days, preset) {
    activePreset = preset;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    const to = new Date();
    const from = new Date(); from.setDate(from.getDate() - days);
    document.getElementById('fTo').value   = to.toISOString().slice(0,10);
    document.getElementById('fFrom').value = from.toISOString().slice(0,10);
    loadAll();
}

function api(endpoint, extra) {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    return fetch(`reports.php?api=${endpoint}&domain=${DOMAIN}&from=${from}&to=${to}${extra||''}`).then(r=>r.json());
}

function fmtDur(secs) {
    if (!secs) return '0s';
    const h = Math.floor(secs/3600), m = Math.floor((secs%3600)/60), s = secs%60;
    if (h) return h+'h '+m+'m';
    if (m) return m+'m '+s+'s';
    return s+'s';
}

function mkChart(id, type, data, opts) {
    if (charts[id]) charts[id].destroy();
    const ctx = document.getElementById(id);
    if (!ctx) return;
    charts[id] = new Chart(ctx, { type, data, options: { responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ labels:{ color:'#8b949e', font:{size:11} } } },
        scales: type==='bar'||type==='line' ? {
            x:{ ticks:{color:'#8b949e',font:{size:10}}, grid:{color:'#21262d'} },
            y:{ ticks:{color:'#8b949e',font:{size:10}}, grid:{color:'#21262d'} }
        } : {}, ...opts } });
}

async function loadSummary() {
    const r = await api('summary');
    if (r.error) return;
    document.getElementById('kpiTotal').textContent    = r.total    || 0;
    document.getElementById('kpiAnswered').textContent = r.answered || 0;
    document.getElementById('kpiMissed').textContent   = r.missed   || 0;
    document.getElementById('kpiInbound').textContent  = r.inbound  || 0;
    document.getElementById('kpiOutbound').textContent = r.outbound || 0;
    document.getElementById('kpiAvgDur').textContent   = r.avg_dur  || 0;
    const talkHrs = Math.round((r.total_talk||0)/3600 * 10)/10;
    document.getElementById('kpiTalkHrs').textContent  = talkHrs + 'h';
    const t = parseInt(r.total)||0, a = parseInt(r.answered)||0;
    document.getElementById('kpiAnswerRate').textContent = t ? Math.round(a/t*100)+'% answer rate' : '—';
    document.getElementById('kpiAbandon').textContent    = r.abandon_rate ? r.abandon_rate+'% abandon' : '0% abandon';
}

async function loadVolume() {
    const rows = await api('daily_volume');
    if (!Array.isArray(rows)) return;
    const labels    = rows.map(r => r.day.slice(5)); // MM-DD
    const answered  = rows.map(r => parseInt(r.answered)||0);
    const missed    = rows.map(r => parseInt(r.missed)||0);
    const avgDur    = rows.map(r => parseInt(r.avg_dur)||0);

    mkChart('chartVolume','bar',{
        labels,
        datasets:[
            { label:'Answered', data:answered, backgroundColor:'#238636bb', borderColor:'#3fb950', borderWidth:1 },
            { label:'Missed',   data:missed,   backgroundColor:'#da363388', borderColor:'#f85149', borderWidth:1 },
        ]
    });

    // Direction pie
    const inArr  = rows.map(r => parseInt(r.inbound)||0);
    const outArr = rows.map(r => parseInt(r.outbound)||0);
    const inSum  = inArr.reduce((a,b)=>a+b,0);
    const outSum = outArr.reduce((a,b)=>a+b,0);
    mkChart('chartDirection','doughnut',{
        labels:['Inbound','Outbound'],
        datasets:[{ data:[inSum,outSum], backgroundColor:['#388bfd','#f0883e'], borderWidth:0 }]
    });

    // Answer vs Missed pie
    const totA = answered.reduce((a,b)=>a+b,0);
    const totM = missed.reduce((a,b)=>a+b,0);
    mkChart('chartAM','doughnut',{
        labels:['Answered','Missed'],
        datasets:[{ data:[totA,totM], backgroundColor:['#3fb950','#f85149'], borderWidth:0 }]
    });
}

async function loadAgents() {
    const rows = await api('agent_performance');
    const tbody = document.getElementById('agentBody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:30px;color:#8b949e">No call data found</td></tr>';
        return;
    }
    const maxTotal = rows[0].total || 1;
    const rankColors = ['gold','silver','bronze'];
    tbody.innerHTML = rows.map((r,i) => {
        const rc = rankColors[i] || '';
        const ar = r.answer_rate;
        const arClass = ar>=80?'good':ar>=50?'ok':'bad';
        return `<tr>
          <td><div class="rank-badge ${rc}">${i+1}</div></td>
          <td><strong>${r.name}</strong></td>
          <td style="color:#8b949e">${r.ext}</td>
          <td>
            <div class="bar-cell">
              <div class="bar-bg"><div class="bar-fill" style="width:${Math.round(r.total/maxTotal*100)}%"></div></div>
              <span>${r.total}</span>
            </div>
          </td>
          <td style="color:#3fb950">${r.answered}</td>
          <td style="color:#f85149">${r.missed}</td>
          <td>${r.inbound}</td>
          <td>${r.outbound}</td>
          <td>${fmtDur(r.total_talk)}</td>
          <td>${r.avg_dur}s</td>
          <td><span class="answer-rate ${arClass}">${ar}%</span></td>
        </tr>`;
    }).join('');
}

async function loadQueues() {
    const rows = await api('queue_sla');
    const tbody = document.getElementById('queueBody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#8b949e">No queue data found</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        const total    = parseInt(r.total)||0;
        const answered = parseInt(r.answered)||0;
        const rate     = total ? Math.round(answered/total*100) : 0;
        return `<tr>
          <td><strong>${r.queue_num}</strong></td>
          <td>${total}</td>
          <td>${answered}</td>
          <td><span class="answer-rate ${rate>=80?'good':rate>=50?'ok':'bad'}">${rate}%</span></td>
          <td>${r.avg_dur||0}s</td>
        </tr>`;
    }).join('');
}

function exportCSV() {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    window.location = `reports.php?api=export_csv&domain=${DOMAIN}&from=${from}&to=${to}`;
}

async function loadAll() {
    const status = document.getElementById('loadStatus');
    status.textContent = 'Loading...';
    await Promise.all([loadSummary(), loadVolume(), loadAgents(), loadQueues()]);
    status.textContent = 'Updated ' + new Date().toLocaleTimeString();
}

// Initial load
loadAll();
</script>
</body>
</html>
