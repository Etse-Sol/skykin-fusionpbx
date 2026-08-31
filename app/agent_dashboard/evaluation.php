<?php
// SkyKin Technologies – Call Evaluation / Quality Scoring
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

$is_api = isset($_GET['api']);
skykin_require_groups(['superadmin', 'admin', 'supervisor'], $is_api);

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = skykin_default_domain();
$domain  = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$embed   = !empty($_GET['embed']);
$today   = date('Y-m-d');

function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

// ── Ensure evaluation table exists ──────────────────────────────────────────
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS skykin_evaluations (
        eval_id       SERIAL PRIMARY KEY,
        cdr_uuid      TEXT,
        call_uuid     TEXT,
        eval_date     TIMESTAMP DEFAULT NOW(),
        evaluator     TEXT,
        domain_name   TEXT,
        agent_ext     TEXT,
        agent_name    TEXT,
        caller_number TEXT,
        call_time     TEXT,
        duration      INT,
        -- Scoring criteria (1-5 each)
        score_greeting    SMALLINT DEFAULT 0,
        score_knowledge   SMALLINT DEFAULT 0,
        score_resolution  SMALLINT DEFAULT 0,
        score_tone        SMALLINT DEFAULT 0,
        score_procedure   SMALLINT DEFAULT 0,
        score_closing     SMALLINT DEFAULT 0,
        total_score       SMALLINT DEFAULT 0,
        max_score         SMALLINT DEFAULT 30,
        grade             TEXT,
        notes             TEXT
    )");
} catch (Exception $e) { /* silent */ }

// ── API endpoints ────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    error_reporting(0);
    header('Content-Type: application/json');
    $dom  = skykin_domain_param($_GET['domain'] ?? null);
    $from = $_GET['from']   ?? date('Y-m-d');
    $to   = $_GET['to']     ?? date('Y-m-d');
    $ts   = strtotime($from.' 00:00:00');
    $te   = strtotime($to.' 23:59:59');

    try {
        $db = getDB();

        // List calls to evaluate (from CDR)
        if ($_GET['api'] === 'calls') {
            $ext    = $_GET['ext']    ?? '';
            $search = $_GET['search'] ?? '';
            $where  = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te AND billsec>0";
            $params = [':d'=>$dom,':ts'=>$ts,':te'=>$te];
            if ($ext)    { $where.=" AND (caller_id_number=:e OR destination_number=:e)"; $params[':e']=$ext; }
            if ($search) { $where.=" AND (caller_id_number LIKE :q OR destination_number LIKE :q)"; $params[':q']='%'.$search.'%'; }
            $s = $db->prepare("SELECT
                xml_cdr_uuid as cdr_uuid,
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as call_time,
                caller_id_number, caller_id_name, destination_number,
                direction, billsec, hangup_cause, record_name, record_path
                FROM v_xml_cdr WHERE $where ORDER BY start_epoch DESC LIMIT 200");
            $s->execute($params);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            // Attach existing eval scores
            $uuids = array_filter(array_column($rows,'cdr_uuid'));
            $evalMap = [];
            if ($uuids) {
                $placeholders = implode(',', array_map(fn($i)=>":u$i", array_keys($uuids)));
                $params2 = [];
                foreach (array_values($uuids) as $i => $uid) $params2[":u$i"] = $uid;
                $s2 = $db->prepare("SELECT cdr_uuid, total_score, max_score, grade FROM skykin_evaluations WHERE cdr_uuid IN ($placeholders)");
                $s2->execute($params2);
                foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $e) $evalMap[$e['cdr_uuid']] = $e;
            }
            foreach ($rows as &$row) {
                $row['eval'] = $evalMap[$row['cdr_uuid']] ?? null;
            }
            echo json_encode($rows);
            exit;
        }

        // Save evaluation
        if ($_GET['api'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            $criteria = ['greeting','knowledge','resolution','tone','procedure','closing'];
            $total = 0;
            foreach ($criteria as $c) $total += max(0,min(5,(int)($body["score_$c"]??0)));
            $max = count($criteria)*5;
            $pct = $max ? round($total/$max*100) : 0;
            $grade = $pct>=90?'A+':($pct>=80?'A':($pct>=70?'B':($pct>=60?'C':'D')));
            $s = $db->prepare("INSERT INTO skykin_evaluations
                (cdr_uuid,call_uuid,evaluator,domain_name,agent_ext,agent_name,caller_number,call_time,duration,
                 score_greeting,score_knowledge,score_resolution,score_tone,score_procedure,score_closing,
                 total_score,max_score,grade,notes)
                VALUES (:cu,:cuu,:ev,:d,:ae,:an,:cn,:ct,:dur,
                        :sg,:sk,:sr,:st,:sp,:sc,:ts,:ms,:gr,:nt)
                ON CONFLICT DO NOTHING");
            $s->execute([
                ':cu'=>$body['cdr_uuid']??'',':cuu'=>$body['call_uuid']??'',
                ':ev'=>$logged_in_user,':d'=>$dom,
                ':ae'=>$body['agent_ext']??'',':an'=>$body['agent_name']??'',
                ':cn'=>$body['caller_number']??'',':ct'=>$body['call_time']??'',
                ':dur'=>(int)($body['duration']??0),
                ':sg'=>(int)($body['score_greeting']??0),':sk'=>(int)($body['score_knowledge']??0),
                ':sr'=>(int)($body['score_resolution']??0),':st'=>(int)($body['score_tone']??0),
                ':sp'=>(int)($body['score_procedure']??0),':sc'=>(int)($body['score_closing']??0),
                ':ts'=>$total,':ms'=>$max,':gr'=>$grade,':nt'=>$body['notes']??'',
            ]);
            echo json_encode(['ok'=>true,'grade'=>$grade,'total'=>$total,'max'=>$max,'pct'=>$pct]);
            exit;
        }

        // List evaluations done
        if ($_GET['api'] === 'history') {
            $s = $db->prepare("SELECT * FROM skykin_evaluations
                WHERE domain_name=:d AND eval_date>=to_timestamp(:ts) AND eval_date<=to_timestamp(:te)
                ORDER BY eval_date DESC LIMIT 300");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // Agent QA summary — average scores per agent across evaluated calls
        if ($_GET['api'] === 'agent_summary') {
            $s = $db->prepare("SELECT
                agent_ext,
                MAX(NULLIF(agent_name, '')) AS agent_name,
                COUNT(*) AS eval_count,
                ROUND(AVG(total_score::numeric / NULLIF(max_score, 0) * 100)) AS avg_pct,
                ROUND(AVG(total_score::numeric), 1) AS avg_score,
                ROUND(AVG(score_greeting::numeric), 1) AS avg_greeting,
                ROUND(AVG(score_knowledge::numeric), 1) AS avg_knowledge,
                ROUND(AVG(score_resolution::numeric), 1) AS avg_resolution,
                ROUND(AVG(score_tone::numeric), 1) AS avg_tone,
                ROUND(AVG(score_procedure::numeric), 1) AS avg_procedure,
                ROUND(AVG(score_closing::numeric), 1) AS avg_closing,
                MAX(eval_date) AS last_eval
                FROM skykin_evaluations
                WHERE domain_name = :d
                  AND eval_date >= to_timestamp(:ts)
                  AND eval_date <= to_timestamp(:te)
                  AND COALESCE(agent_ext, '') <> ''
                GROUP BY agent_ext
                ORDER BY avg_pct DESC NULLS LAST, eval_count DESC");
            $s->execute([':d'=>$dom, ':ts'=>$ts, ':te'=>$te]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $pct = (int)($row['avg_pct'] ?? 0);
                $row['avg_grade'] = $pct >= 90 ? 'A+' : ($pct >= 80 ? 'A' : ($pct >= 70 ? 'B' : ($pct >= 60 ? 'C' : 'D')));
                $row['eval_count'] = (int)$row['eval_count'];
            }
            unset($row);
            echo json_encode($rows);
            exit;
        }

    } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php echo skykin_favicon_tag(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sky Connect – Call Evaluation</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#333;min-height:100vh}

.topbar{background:linear-gradient(135deg,#0047AB,#00B4D8);border-bottom:1px solid #e0e0e0;padding:0 24px;height:56px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:20px}
.brand{font-weight:700;font-size:17px;color:#58a6ff;letter-spacing:.5px}
.brand span{color:#333;font-weight:400}
.nav-links{display:flex;gap:4px}
.nav-links a{color:#888;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;transition:.2s}
.nav-links a:hover,.nav-links a.active{background:#f0f2f5;color:#333}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:13px;color:#888}
.user-pill{background:#f0f2f5;padding:5px 12px;border-radius:20px;color:#333;font-size:12px}

.filters{background:#ffffff;border-bottom:1px solid #e0e0e0;padding:12px 24px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filters label{font-size:12px;color:#888}
.filters input,.filters select{background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:6px 10px;border-radius:6px;font-size:13px}
.btn-filter{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px}

.layout{display:grid;grid-template-columns:1fr 400px;gap:0;height:calc(100vh - 110px)}
.call-list{overflow-y:auto;border-right:1px solid #e0e0e0}
.eval-panel{overflow-y:auto;background:#ffffff;padding:20px}

/* Call list */
.call-item{padding:12px 16px;border-bottom:1px solid #21262d;cursor:pointer;transition:.15s}
.call-item:hover{background:#f0f2f5}
.call-item.selected{background:#388bfd18;border-left:3px solid #58a6ff}
.call-item.evaluated{border-left:3px solid #3fb950}
.ci-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.ci-nums{font-size:14px;font-weight:600}
.ci-time{font-size:11px;color:#888}
.ci-meta{font-size:11px;color:#888;display:flex;gap:12px}
.ci-badge{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600}
.ci-badge.inbound{background:#388bfd22;color:#58a6ff}
.ci-badge.outbound{background:#f0883e22;color:#f0883e}
.ci-badge.local{background:#3fb95022;color:#3fb950}
.grade-badge{padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.grade-A\+{background:#3fb95033;color:#3fb950}
.grade-A{background:#3fb95022;color:#56d364}
.grade-B{background:#d2992222;color:#d29922}
.grade-C{background:#f0883e22;color:#f0883e}
.grade-D{background:#f8514922;color:#f85149}

/* Eval panel */
.eval-title{font-size:15px;font-weight:600;margin-bottom:16px;color:#333}
.eval-meta{background:#f0f2f5;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px}
.eval-meta div{margin-bottom:4px;color:#888} .eval-meta span{color:#333;font-weight:500}

.criteria{margin-bottom:20px}
.criterion{margin-bottom:14px}
.crit-label{font-size:12px;color:#888;margin-bottom:6px;display:flex;justify-content:space-between}
.crit-label strong{color:#333}
.stars{display:flex;gap:6px}
.star{width:32px;height:32px;border-radius:6px;background:#f0f2f5;border:1px solid #e0e0e0;
  cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:.15s;color:#e0e0e0}
.star:hover,.star.active{background:#d29922;border-color:#d29922;color:#fff}

.eval-notes{width:100%;background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:10px;border-radius:6px;font-family:inherit;font-size:13px;resize:vertical;min-height:80px;margin-bottom:16px}
.eval-notes:focus{outline:none;border-color:#58a6ff}

.score-display{background:#f0f2f5;border-radius:8px;padding:14px;margin-bottom:16px;text-align:center}
.score-big{font-size:36px;font-weight:700;color:#d29922}
.score-sub{font-size:12px;color:#888}
.score-bar-bg{background:#e0e0e0;border-radius:4px;height:8px;margin:8px 0}
.score-bar-fill{height:8px;border-radius:4px;background:#d29922;transition:.5s}

.btn-save{width:100%;background:#238636;color:#fff;border:none;padding:12px;border-radius:8px;
  cursor:pointer;font-size:14px;font-weight:600}
.btn-save:hover{background:#2ea043}
.btn-save:disabled{background:#f0f2f5;color:#888;cursor:not-allowed}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:1px solid #e0e0e0;margin-bottom:16px}
.tab-btn{padding:8px 16px;background:none;border:none;color:#888;cursor:pointer;font-size:13px;border-bottom:2px solid transparent}
.tab-btn.active{color:#58a6ff;border-bottom-color:#58a6ff}
.tab-content{display:none}.tab-content.active{display:block}

.hist-row{padding:10px 12px;border-bottom:1px solid #21262d;font-size:12px}
.hist-top{display:flex;justify-content:space-between;margin-bottom:4px}
.hist-scores{display:flex;gap:8px;flex-wrap:wrap;color:#888}
.hist-scores span{background:#f0f2f5;padding:2px 6px;border-radius:4px}

.empty-state{text-align:center;padding:40px;color:#888;font-size:13px}

.view-toggle{display:flex;gap:8px;margin-left:auto}
.view-toggle button{background:#f0f2f5;color:#555;border:1px solid #e0e0e0;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:13px}
.view-toggle button.active{background:#2563eb;color:#fff;border-color:#2563eb}

.agent-summary-panel{display:none;padding:16px 24px 24px}
.agent-summary-panel.active{display:block}
.agent-summary-panel .summary-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e8edf3;border-radius:12px;overflow:hidden}
.agent-summary-panel .summary-table th{background:#f8fafc;padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;border-bottom:2px solid #eee}
.agent-summary-panel .summary-table td{padding:12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.agent-summary-panel .summary-table tr:hover td{background:#fafbff}
.agent-summary-panel .rank-pill{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#eef2ff;color:#2563eb;font-weight:700;font-size:12px}
.agent-summary-panel .agent-name{font-weight:600;color:#222}
.agent-summary-panel .agent-ext{font-size:11px;color:#888}
.agent-summary-panel .mini-bar{background:#e8edf3;border-radius:999px;height:8px;min-width:90px;overflow:hidden}
.agent-summary-panel .mini-bar > span{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#22c55e);border-radius:999px}
.agent-summary-panel .crit-avg{font-size:11px;color:#666;white-space:nowrap}

.layout.hidden{display:none}

/* Audio player */
.audio-wrap{margin-bottom:14px}
audio{width:100%;height:32px;border-radius:6px}

/* SkyKin light workspace */
:root{
  --sk-blue:#2563eb;--sk-blue-soft:#eff6ff;--sk-green:#16a34a;
  --sk-text:#172033;--sk-muted:#64748b;--sk-canvas:#f6f8fb;
  --sk-surface:#fff;--sk-line:#e8edf3;--sk-shadow:0 8px 28px rgba(15,23,42,.06)
}
body{background:var(--sk-canvas);color:var(--sk-text);font-size:14px}
.topbar{height:60px;padding:0 24px;background:linear-gradient(135deg,#0047ab,#00b4d8);border:0;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.topbar-left{gap:28px}
.brand{color:#fff;font-size:18px;font-weight:700;letter-spacing:1px;white-space:nowrap}
.brand .brand-sky{color:#00e5ff}
.brand .role-badge{margin-left:10px;padding:2px 10px;background:rgba(255,255,255,.2);border-radius:20px;color:#fff;font-size:11px;font-weight:600;letter-spacing:0}
.nav-links{gap:6px}
.nav-links a{color:rgba(255,255,255,.82);padding:8px 12px;border-radius:7px;font-size:12px;font-weight:600}
.nav-links a:hover{background:rgba(255,255,255,.12);color:#fff}
.nav-links a.active{background:rgba(255,255,255,.2);color:#fff}
.topbar-right{color:rgba(255,255,255,.86)}
.user-pill{background:rgba(255,255,255,.14);color:#fff;padding:7px 12px;border-radius:9px}
.topbar-right a{color:#fff!important;font-weight:600;opacity:.85}
.topbar-right a:hover{opacity:1}

.filters{min-height:62px;padding:11px 28px;background:var(--sk-canvas);border:0;gap:10px}
.filters label{color:var(--sk-muted);font-size:11px;font-weight:600}
.filters input,.filters select{height:38px;background:#fff;border:1px solid #dbe3ec;color:var(--sk-text);padding:7px 11px;border-radius:9px}
.filters input:focus,.filters select:focus{outline:0;border-color:#93b4f4;box-shadow:0 0 0 3px rgba(37,99,235,.09)}
.btn-filter{height:38px;background:var(--sk-blue);padding:0 18px;border-radius:9px;font-weight:650;box-shadow:0 3px 8px rgba(37,99,235,.16)}
.btn-filter:hover{background:#1d4ed8}

.layout{grid-template-columns:minmax(0,1fr) 420px;gap:14px;height:calc(100vh - 126px);padding:0 20px 18px}
.call-list,.eval-panel{background:var(--sk-surface);border:0;border-radius:14px;box-shadow:var(--sk-shadow)}
.call-list{padding:8px;overflow-y:auto}
.eval-panel{padding:20px 22px}
.call-item{margin:3px 0;padding:13px 14px;border:0;border-radius:10px}
.call-item:hover{background:#f8fafc}
.call-item.selected{background:var(--sk-blue-soft);border:0;box-shadow:inset 3px 0 var(--sk-blue)}
.call-item.evaluated:not(.selected){border:0;box-shadow:inset 3px 0 #22c55e}
.ci-nums{color:var(--sk-text);font-size:13px}
.ci-time,.ci-meta{color:var(--sk-muted)}
.ci-badge.inbound{background:#eff6ff;color:#2563eb}
.ci-badge.outbound{background:#fff7ed;color:#ea580c}
.ci-badge.local{background:#f0fdf4;color:#16a34a}

.tabs{gap:6px;border:0;margin-bottom:20px;background:#f1f5f9;padding:4px;border-radius:10px}
.tab-btn{flex:1;padding:8px;border:0!important;border-radius:7px;color:var(--sk-muted);font-weight:600}
.tab-btn.active{background:#fff;color:var(--sk-blue);box-shadow:0 1px 4px rgba(15,23,42,.08)}
.eval-title{color:var(--sk-text);font-size:17px;margin-bottom:14px}
.eval-meta{display:grid;grid-template-columns:1fr 1fr;gap:9px 16px;background:#f8fafc;padding:14px;border-radius:11px}
.eval-meta div{margin:0;color:var(--sk-muted);font-size:11px}
.eval-meta span{color:var(--sk-text)}
.eval-meta input{height:30px!important;background:#fff!important;border:1px solid #dbe3ec!important;border-radius:7px!important}
.criterion{margin-bottom:15px}
.crit-label{color:var(--sk-muted);margin-bottom:7px}
.crit-label strong{color:#334155;font-weight:650}
.star{width:34px;height:34px;background:#f8fafc;border:0;border-radius:9px;color:#d7dee8}
.star:hover,.star.active{background:#f59e0b;border:0;color:#fff;transform:translateY(-1px)}
.score-display{background:#f8fafc;border-radius:11px;padding:12px}
.score-bar-bg{background:#e5eaf1}
.eval-notes{background:#fff;border:1px solid #dbe3ec;border-radius:10px}
.btn-save{background:var(--sk-blue);border-radius:10px;box-shadow:0 3px 8px rgba(37,99,235,.16)}
.btn-save:hover{background:#1d4ed8}
.hist-row{margin-bottom:8px;padding:12px;background:#f8fafc;border:0;border-radius:10px}
.empty-state{color:var(--sk-muted)}
.audio-wrap{background:#f8fafc;padding:10px 12px;border-radius:11px}

body.embed-mode{background:var(--sk-canvas)}
body.embed-mode .topbar{display:none}
body.embed-mode .layout{height:calc(100vh - 62px)}

@media(max-width:850px){
  .topbar{padding:0 14px}.brand span,.topbar-right .user-pill{display:none}
  .nav-links{overflow-x:auto}.nav-links a{white-space:nowrap;padding:8px}
  .filters{padding:10px 14px}.layout{grid-template-columns:1fr;height:auto;padding:0 10px 12px}
  .call-list{max-height:42vh}.eval-panel{min-height:50vh}.eval-meta{grid-template-columns:1fr}
}
</style>
</head>
<body<?php echo $embed ? ' class="embed-mode"' : ''; ?>>

<?php if (!$embed): ?>
<div class="topbar">
  <div class="topbar-left">
    <div class="brand"><span class="brand-sky">Sky</span> Connect <span class="role-badge">SUPERVISOR</span></div>
    <nav class="nav-links">
      <a href="/app/agent_dashboard/supervisor.php">Supervisor</a>
      <a href="/app/agent_dashboard/reports.php">Reports</a>
      <a href="/app/agent_dashboard/evaluation.php" class="active">Evaluation</a>
      <a href="/app/agent_dashboard/crm.php">CRM</a>
      <a href="/app/agent_dashboard/billing.php">Billing</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($domain); ?></div>
    <a href="/logout.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>
<?php endif; ?>

<div class="filters">
  <label>From</label><input type="date" id="fFrom" value="<?php echo $today; ?>">
  <label>To</label><input type="date" id="fTo" value="<?php echo $today; ?>">
  <input type="text" id="fSearch" placeholder="Search number..." style="width:160px">
  <button class="btn-filter" onclick="loadCalls()">&#128269; Search</button>
  <span id="callCount" style="font-size:12px;color:#888"></span>
  <div class="view-toggle">
    <button type="button" id="btnViewEval" class="active" onclick="setMainView('eval')">Evaluate Calls</button>
    <button type="button" id="btnViewAgents" onclick="setMainView('agents')">Agent QA Summary</button>
  </div>
</div>

<div class="agent-summary-panel" id="agentSummaryPanel">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:12px;flex-wrap:wrap">
    <div>
      <div style="font-size:16px;font-weight:700;color:#222">Agent QA Summary</div>
      <div style="font-size:12px;color:#888;margin-top:4px">Average quality scores per agent from evaluated calls in this period</div>
    </div>
    <button class="btn-filter" onclick="loadAgentSummary()">Refresh</button>
  </div>
  <table class="summary-table">
    <thead>
      <tr>
        <th style="width:56px">Rank</th>
        <th>Agent</th>
        <th>Evaluated Calls</th>
        <th>Avg Score</th>
        <th>Grade</th>
        <th>Avg Criteria (1–5)</th>
        <th>Last Evaluated</th>
      </tr>
    </thead>
    <tbody id="agentSummaryBody">
      <tr><td colspan="7" class="empty-state">Loading...</td></tr>
    </tbody>
  </table>
</div>

<div class="layout" id="evalLayout">
  <!-- Left: call list -->
  <div class="call-list" id="callList">
    <div class="empty-state">Loading calls...</div>
  </div>

  <!-- Right: eval panel -->
  <div class="eval-panel">
    <div id="evalEmpty" class="empty-state" style="margin-top:60px">
      <div style="font-size:32px;margin-bottom:12px">&#128203;</div>
      <div style="font-weight:600;color:#333;margin-bottom:6px">Select a call to evaluate</div>
      <div>Click any call from the list to score it</div>
      <div style="margin-top:20px">
        <button onclick="openManualEval()" style="background:#2563eb;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-size:13px;cursor:pointer;margin-top:8px">
          + Manual Evaluation
        </button>
        <div style="font-size:11px;color:#666;margin-top:8px">Score an agent without a CDR record</div>
      </div>
    </div>
    <div id="evalForm" style="display:none">
      <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('score')">Score Call</button>
        <button class="tab-btn" onclick="switchTab('history')">History</button>
      </div>

      <div class="tab-content active" id="tab-score">
        <div class="eval-title">Call Evaluation</div>
        <div class="eval-meta">
          <div>Call Time: <span id="emCallTime">—</span></div>
          <div>Agent Extension: <input id="emAgent" type="text" placeholder="e.g. 101" style="background:#f8f9fa;border:1px solid #e0e0e0;color:#333;border-radius:4px;padding:3px 8px;font-size:13px;width:100px;margin-left:4px"></div>
          <div>Caller Number: <input id="emCaller" type="text" placeholder="e.g. 102 or +251..." style="background:#f8f9fa;border:1px solid #e0e0e0;color:#333;border-radius:4px;padding:3px 8px;font-size:13px;width:150px;margin-left:4px"></div>
          <div>Duration: <span id="emDuration">—</span></div>
        </div>

        <!-- Recording playback if available -->
        <div class="audio-wrap" id="audioWrap" style="display:none">
          <div style="font-size:11px;color:#888;margin-bottom:6px">Recording</div>
          <audio id="evalAudio" controls></audio>
        </div>

        <div class="criteria">
          <!-- Greeting -->
          <div class="criterion">
            <div class="crit-label"><strong>1. Greeting &amp; Introduction</strong><span id="sl_greeting">0 / 5</span></div>
            <div class="stars" id="stars_greeting" data-key="greeting">
              <div class="star" onclick="setStar('greeting',1)">&#9733;</div>
              <div class="star" onclick="setStar('greeting',2)">&#9733;</div>
              <div class="star" onclick="setStar('greeting',3)">&#9733;</div>
              <div class="star" onclick="setStar('greeting',4)">&#9733;</div>
              <div class="star" onclick="setStar('greeting',5)">&#9733;</div>
            </div>
          </div>
          <!-- Knowledge -->
          <div class="criterion">
            <div class="crit-label"><strong>2. Product / Service Knowledge</strong><span id="sl_knowledge">0 / 5</span></div>
            <div class="stars" id="stars_knowledge" data-key="knowledge">
              <div class="star" onclick="setStar('knowledge',1)">&#9733;</div>
              <div class="star" onclick="setStar('knowledge',2)">&#9733;</div>
              <div class="star" onclick="setStar('knowledge',3)">&#9733;</div>
              <div class="star" onclick="setStar('knowledge',4)">&#9733;</div>
              <div class="star" onclick="setStar('knowledge',5)">&#9733;</div>
            </div>
          </div>
          <!-- Resolution -->
          <div class="criterion">
            <div class="crit-label"><strong>3. Issue Resolution</strong><span id="sl_resolution">0 / 5</span></div>
            <div class="stars" id="stars_resolution" data-key="resolution">
              <div class="star" onclick="setStar('resolution',1)">&#9733;</div>
              <div class="star" onclick="setStar('resolution',2)">&#9733;</div>
              <div class="star" onclick="setStar('resolution',3)">&#9733;</div>
              <div class="star" onclick="setStar('resolution',4)">&#9733;</div>
              <div class="star" onclick="setStar('resolution',5)">&#9733;</div>
            </div>
          </div>
          <!-- Tone -->
          <div class="criterion">
            <div class="crit-label"><strong>4. Tone &amp; Professionalism</strong><span id="sl_tone">0 / 5</span></div>
            <div class="stars" id="stars_tone" data-key="tone">
              <div class="star" onclick="setStar('tone',1)">&#9733;</div>
              <div class="star" onclick="setStar('tone',2)">&#9733;</div>
              <div class="star" onclick="setStar('tone',3)">&#9733;</div>
              <div class="star" onclick="setStar('tone',4)">&#9733;</div>
              <div class="star" onclick="setStar('tone',5)">&#9733;</div>
            </div>
          </div>
          <!-- Procedure -->
          <div class="criterion">
            <div class="crit-label"><strong>5. Procedure Compliance</strong><span id="sl_procedure">0 / 5</span></div>
            <div class="stars" id="stars_procedure" data-key="procedure">
              <div class="star" onclick="setStar('procedure',1)">&#9733;</div>
              <div class="star" onclick="setStar('procedure',2)">&#9733;</div>
              <div class="star" onclick="setStar('procedure',3)">&#9733;</div>
              <div class="star" onclick="setStar('procedure',4)">&#9733;</div>
              <div class="star" onclick="setStar('procedure',5)">&#9733;</div>
            </div>
          </div>
          <!-- Closing -->
          <div class="criterion">
            <div class="crit-label"><strong>6. Proper Closing</strong><span id="sl_closing">0 / 5</span></div>
            <div class="stars" id="stars_closing" data-key="closing">
              <div class="star" onclick="setStar('closing',1)">&#9733;</div>
              <div class="star" onclick="setStar('closing',2)">&#9733;</div>
              <div class="star" onclick="setStar('closing',3)">&#9733;</div>
              <div class="star" onclick="setStar('closing',4)">&#9733;</div>
              <div class="star" onclick="setStar('closing',5)">&#9733;</div>
            </div>
          </div>
        </div>

        <!-- Score summary -->
        <div class="score-display">
          <div class="score-big" id="scorePct">0%</div>
          <div class="score-sub" id="scoreLabel">0 / 30 &nbsp;–&nbsp; Grade: —</div>
          <div class="score-bar-bg"><div class="score-bar-fill" id="scoreBar" style="width:0%"></div></div>
        </div>

        <textarea class="eval-notes" id="evalNotes" placeholder="Evaluator notes (optional)..."></textarea>
        <button class="btn-save" id="btnSave" onclick="saveEval()">Save Evaluation</button>
      </div>

      <div class="tab-content" id="tab-history">
        <div id="historyList"><div class="empty-state">No evaluations yet</div></div>
      </div>
    </div>
  </div>
</div>
<div style="text-align:center;font-size:11px;color:#aaa;padding:16px 24px">Sky Connect &copy; <?php echo date('Y'); ?> | Powered by SkyKin Technology</div>

<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
<script>
const DOMAIN = '<?php echo $domain; ?>';
const EVALUATOR = '<?php echo htmlspecialchars($logged_in_user); ?>';
const scores = { greeting:0, knowledge:0, resolution:0, tone:0, procedure:0, closing:0 };
let selectedCall = null;
let mainView = 'eval';

function setMainView(view) {
    mainView = view;
    document.getElementById('btnViewEval').classList.toggle('active', view === 'eval');
    document.getElementById('btnViewAgents').classList.toggle('active', view === 'agents');
    document.getElementById('agentSummaryPanel').classList.toggle('active', view === 'agents');
    document.getElementById('evalLayout').classList.toggle('hidden', view === 'agents');
    if (view === 'agents') loadAgentSummary();
    else loadCalls();
}

function gradeClass(grade) {
    return 'grade-' + String(grade || 'D').replace('+', '\\+');
}

function fmtCritAvg(row) {
    const parts = [
        ['G', row.avg_greeting],
        ['K', row.avg_knowledge],
        ['R', row.avg_resolution],
        ['T', row.avg_tone],
        ['P', row.avg_procedure],
        ['C', row.avg_closing],
    ];
    return parts.map(([k, v]) => `${k}:${Number(v || 0).toFixed(1)}`).join(' · ');
}

async function loadAgentSummary() {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    const body = document.getElementById('agentSummaryBody');
    body.innerHTML = '<tr><td colspan="7" class="empty-state">Loading...</td></tr>';
    const resp = await fetch(`evaluation.php?api=agent_summary&domain=${DOMAIN}&from=${from}&to=${to}`);
    const rows = await resp.json();
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" class="empty-state">No evaluated calls in this period</td></tr>';
        return;
    }
    body.innerHTML = rows.map((r, i) => {
        const pct = Number(r.avg_pct || 0);
        const name = r.agent_name && r.agent_name !== r.agent_ext ? r.agent_name : ('Ext ' + r.agent_ext);
        const last = r.last_eval ? String(r.last_eval).replace('T', ' ').slice(0, 16) : '—';
        return `<tr>
          <td><span class="rank-pill">${i + 1}</span></td>
          <td><div class="agent-name">${name}</div><div class="agent-ext">Ext ${r.agent_ext}</div></td>
          <td>${r.eval_count}</td>
          <td>
            <div style="font-weight:700;color:#2563eb">${pct}%</div>
            <div class="mini-bar"><span style="width:${Math.max(0, Math.min(100, pct))}%"></span></div>
            <div style="font-size:11px;color:#888;margin-top:4px">${Number(r.avg_score || 0).toFixed(1)} / 30 avg</div>
          </td>
          <td><span class="grade-badge ${gradeClass(r.avg_grade)}">${r.avg_grade}</span></td>
          <td class="crit-avg">${fmtCritAvg(r)}</td>
          <td style="font-size:12px;color:#666">${last}</td>
        </tr>`;
    }).join('');
}

function switchTab(t) {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', (t==='score'&&i===0)||(t==='history'&&i===1)));
    document.getElementById('tab-score').classList.toggle('active', t==='score');
    document.getElementById('tab-history').classList.toggle('active', t==='history');
    if (t==='history') loadHistory();
}

function setStar(key, val) {
    scores[key] = val;
    const stars = document.querySelectorAll(`#stars_${key} .star`);
    stars.forEach((s,i) => s.classList.toggle('active', i < val));
    document.getElementById('sl_'+key).textContent = val+' / 5';
    updateScoreDisplay();
}

function updateScoreDisplay() {
    const total = Object.values(scores).reduce((a,b)=>a+b,0);
    const max   = 30;
    const pct   = Math.round(total/max*100);
    const grade = pct>=90?'A+':pct>=80?'A':pct>=70?'B':pct>=60?'C':'D';
    const colors = {'A+':'#3fb950','A':'#56d364','B':'#d29922','C':'#f0883e','D':'#f85149'};
    document.getElementById('scorePct').textContent   = pct + '%';
    document.getElementById('scorePct').style.color   = colors[grade]||'#d29922';
    document.getElementById('scoreLabel').textContent = `${total} / ${max}  –  Grade: ${grade}`;
    document.getElementById('scoreBar').style.width   = pct + '%';
    document.getElementById('scoreBar').style.background = colors[grade]||'#d29922';
}

function fmtSecs(s) {
    s=parseInt(s)||0;
    const m=Math.floor(s/60), sec=s%60;
    return m+'m '+String(sec).padStart(2,'0')+'s';
}

async function loadCalls() {
    const from   = document.getElementById('fFrom').value;
    const to     = document.getElementById('fTo').value;
    const search = document.getElementById('fSearch').value.trim();
    const list   = document.getElementById('callList');
    list.innerHTML = '<div class="empty-state">Loading...</div>';
    const resp = await fetch(`evaluation.php?api=calls&domain=${DOMAIN}&from=${from}&to=${to}&search=${encodeURIComponent(search)}`);
    const rows = await resp.json();
    document.getElementById('callCount').textContent = rows.length + ' calls';
    if (!rows.length) { list.innerHTML='<div class="empty-state">No answered calls found</div>'; return; }
    list.innerHTML = rows.map((r, i) => {
        const ev = r.eval;
        const evBadge = ev ? `<span class="grade-badge grade-${ev.grade.replace('+','\\+')}">${ev.grade}</span>` : '';
        return `<div class="call-item${ev?' evaluated':''}" data-idx="${i}">
          <div class="ci-top">
            <span class="ci-nums">${r.caller_id_number} &rarr; ${r.destination_number}</span>
            <span class="ci-time">${r.call_time}</span>
          </div>
          <div class="ci-meta">
            <span class="ci-badge ${r.direction}">${r.direction}</span>
            <span>${fmtSecs(r.billsec)}</span>
            ${r.record_name?'<span>&#127900; Rec</span>':''}
            ${evBadge}
          </div>
        </div>`;
    }).join('');
    // attach click handlers via event delegation
    window._evalRows = rows;
    list.querySelectorAll('.call-item').forEach(el => {
        el.addEventListener('click', function() { selectCall(window._evalRows[+this.dataset.idx]); });
    });
}

function selectCall(r) {
    selectedCall = r;
    // Highlight
    document.querySelectorAll('.call-item').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');

    document.getElementById('evalEmpty').style.display = 'none';
    document.getElementById('evalForm').style.display  = 'block';

    document.getElementById('emCallTime').textContent = r.call_time;
    document.getElementById('emAgent').value    = r.destination_number;
    document.getElementById('emCaller').value   = r.caller_id_number + (r.caller_id_name?' ('+r.caller_id_name+')':'');
    document.getElementById('emDuration').textContent = fmtSecs(r.billsec);

    // Recording
    const aw = document.getElementById('audioWrap');
    if (r.record_name) {
        const fname = r.record_name;
        const domain = (window.SKYKIN && SKYKIN.domain) || location.hostname;
        let url = '/app/agent_dashboard/play_recording.php?f='+encodeURIComponent(fname)
            +'&d='+encodeURIComponent(domain);
        if (r.record_path) { url += '&path='+encodeURIComponent(r.record_path.replace(/\/+$/,'')); }
        document.getElementById('evalAudio').src = url;
        aw.style.display = 'block';
    } else { aw.style.display = 'none'; }

    // Reset scores
    Object.keys(scores).forEach(k => setStar(k,0));
    document.getElementById('evalNotes').value = '';
    document.getElementById('btnSave').disabled = false;
    document.getElementById('btnSave').textContent = 'Save Evaluation';

    switchTab('score');
}

async function saveEval() {
    if (!selectedCall) return;
    const btn = document.getElementById('btnSave');
    btn.disabled = true; btn.textContent = 'Saving...';

    // For manual evaluations, read agent/caller from input fields
    if (document.getElementById('evalForm').dataset.manual === '1') {
        const agentVal  = document.getElementById('emAgent').value.trim();
        const callerVal = document.getElementById('emCaller').value.trim();
        if (!agentVal) { alert('Please enter the agent extension'); btn.disabled=false; btn.textContent='Save Evaluation'; return; }
        selectedCall.destination_number = agentVal;
        selectedCall.caller_id_number   = callerVal;
        selectedCall.call_time          = new Date().toLocaleString();
        selectedCall.cdr_uuid           = '';
    }

    const body = {
        cdr_uuid:       selectedCall.cdr_uuid || '',
        agent_ext:      selectedCall.destination_number,
        agent_name:     selectedCall.destination_number,
        caller_number:  selectedCall.caller_id_number,
        call_time:      selectedCall.call_time,
        duration:       selectedCall.billsec,
        notes:          document.getElementById('evalNotes').value,
        ...Object.fromEntries(Object.entries(scores).map(([k,v])=>['score_'+k,v]))
    };
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    const resp = await fetch(`evaluation.php?api=save&domain=${DOMAIN}&from=${from}&to=${to}`,{
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    const r = await resp.json();
    if (r.ok) {
        btn.textContent = `Saved! Grade: ${r.grade} (${r.pct}%)`;
        btn.style.background = '#2ea043';
        btn.style.color = '#fff';
        // Show toast
        const toast = document.createElement('div');
        toast.textContent = `Evaluation saved — Grade ${r.grade} (${r.pct}%)`;
        toast.style.cssText = 'position:fixed;top:20px;right:20px;background:#2ea043;color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.3)';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
        loadCalls(); // refresh left list with grade badge
    } else {
        btn.disabled = false; btn.textContent = 'Save Evaluation';
        alert('Error: ' + (r.error||'Unknown'));
    }
}

async function loadHistory() {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    const resp = await fetch(`evaluation.php?api=history&domain=${DOMAIN}&from=${from}&to=${to}`);
    const rows = await resp.json();
    const div  = document.getElementById('historyList');
    if (!Array.isArray(rows)||rows.length===0){ div.innerHTML='<div class="empty-state">No evaluations in this period</div>'; return; }
    div.innerHTML = rows.map(r => {
        const pct = Math.round(r.total_score/r.max_score*100);
        return `<div class="hist-row">
          <div class="hist-top">
            <span>${r.call_time} &mdash; ${r.agent_ext} &larr; ${r.caller_number}</span>
            <span class="grade-badge grade-${r.grade.replace('+','\\+')}">${r.grade} (${pct}%)</span>
          </div>
          <div class="hist-scores">
            <span>Greet: ${r.score_greeting}</span>
            <span>Know: ${r.score_knowledge}</span>
            <span>Res: ${r.score_resolution}</span>
            <span>Tone: ${r.score_tone}</span>
            <span>Proc: ${r.score_procedure}</span>
            <span>Close: ${r.score_closing}</span>
          </div>
          ${r.notes?`<div style="font-size:11px;color:#888;margin-top:4px">${r.notes}</div>`:''}
          <div style="font-size:10px;color:#6e7681;margin-top:2px">by ${r.evaluator}</div>
        </div>`;
    }).join('');
}

function openManualEval() {
    // Clear any selected call
    document.querySelectorAll('.call-item').forEach(el => el.classList.remove('selected'));
    selectedCall = {
        cdr_uuid: null, call_uuid: null,
        call_time: new Date().toLocaleString(),
        destination_number: '', caller_id_number: '', caller_id_name: '',
        billsec: 0, record_name: null
    };

    document.getElementById('evalEmpty').style.display = 'none';
    document.getElementById('evalForm').style.display  = 'block';
    document.getElementById('emCallTime').textContent  = 'Manual Evaluation — ' + new Date().toLocaleDateString();
    document.getElementById('emAgent').value     = '';
    document.getElementById('emCaller').value    = '';
    document.getElementById('emDuration').textContent  = '—';

    // Make agent/caller fields editable for manual entry
    const agentEl  = document.getElementById('emAgent');
    const callerEl = document.getElementById('emCaller');
    agentEl.contentEditable  = 'true';
    callerEl.contentEditable = 'true';
    agentEl.style.border     = '1px dashed #444';
    agentEl.style.padding    = '2px 6px';
    agentEl.style.borderRadius = '4px';
    agentEl.style.minWidth   = '60px';
    agentEl.style.display    = 'inline-block';
    callerEl.style.border    = '1px dashed #444';
    callerEl.style.padding   = '2px 6px';
    callerEl.style.borderRadius = '4px';
    callerEl.style.display   = 'inline-block';
    agentEl.setAttribute('placeholder', 'e.g. 101');
    callerEl.setAttribute('placeholder', 'e.g. +251911...');

    // Override submit to pick up manual values
    document.getElementById('evalForm').dataset.manual = '1';
    document.getElementById('audioWrap') && (document.getElementById('audioWrap').style.display = 'none');
}

if (new URLSearchParams(location.search).get('view') === 'agents') setMainView('agents');
else loadCalls();
</script>
</body>
</html>
