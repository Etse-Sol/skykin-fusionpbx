<?php
// SkyKin Technologies – Call Evaluation / Quality Scoring

$fpbx_session_path = '/var/lib/php/sessions';
if (is_dir($fpbx_session_path)) session_save_path($fpbx_session_path);
session_name('PHPSESSID');
session_start();

if (empty($_SESSION['user_uuid'])) {
    header('Location: /login/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$allowed = ['superadmin','admin','supervisor'];
$raw     = isset($_SESSION['groups']) ? $_SESSION['groups'] : [];
$groups  = [];
array_walk_recursive($raw, function($v) use (&$groups) {
    if (is_string($v)) foreach (array_map('trim', explode(',', $v)) as $g) if ($g !== '') $groups[] = strtolower($g);
});
if (empty(array_intersect($allowed, $groups))) { http_response_code(403); echo 'Access Denied'; exit; }

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = $_SESSION['domain_name'] ?? 'client1.skykin.local';
$domain  = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : $logged_in_domain;
$today   = date('Y-m-d');

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
    $dom  = $_GET['domain'] ?? 'client1.skykin.local';
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
                uuid as cdr_uuid,
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

    } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkyKin – Call Evaluation</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0d1117;color:#e6edf3;min-height:100vh}

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

.filters{background:#161b22;border-bottom:1px solid #30363d;padding:12px 24px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filters label{font-size:12px;color:#8b949e}
.filters input,.filters select{background:#0d1117;border:1px solid #30363d;color:#e6edf3;
  padding:6px 10px;border-radius:6px;font-size:13px}
.btn-filter{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px}

.layout{display:grid;grid-template-columns:1fr 400px;gap:0;height:calc(100vh - 110px)}
.call-list{overflow-y:auto;border-right:1px solid #30363d}
.eval-panel{overflow-y:auto;background:#161b22;padding:20px}

/* Call list */
.call-item{padding:12px 16px;border-bottom:1px solid #21262d;cursor:pointer;transition:.15s}
.call-item:hover{background:#21262d}
.call-item.selected{background:#388bfd18;border-left:3px solid #58a6ff}
.call-item.evaluated{border-left:3px solid #3fb950}
.ci-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.ci-nums{font-size:14px;font-weight:600}
.ci-time{font-size:11px;color:#8b949e}
.ci-meta{font-size:11px;color:#8b949e;display:flex;gap:12px}
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
.eval-title{font-size:15px;font-weight:600;margin-bottom:16px;color:#e6edf3}
.eval-meta{background:#21262d;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px}
.eval-meta div{margin-bottom:4px;color:#8b949e} .eval-meta span{color:#e6edf3;font-weight:500}

.criteria{margin-bottom:20px}
.criterion{margin-bottom:14px}
.crit-label{font-size:12px;color:#8b949e;margin-bottom:6px;display:flex;justify-content:space-between}
.crit-label strong{color:#e6edf3}
.stars{display:flex;gap:6px}
.star{width:32px;height:32px;border-radius:6px;background:#21262d;border:1px solid #30363d;
  cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:.15s;color:#30363d}
.star:hover,.star.active{background:#d29922;border-color:#d29922;color:#fff}

.eval-notes{width:100%;background:#21262d;border:1px solid #30363d;color:#e6edf3;
  padding:10px;border-radius:6px;font-family:inherit;font-size:13px;resize:vertical;min-height:80px;margin-bottom:16px}
.eval-notes:focus{outline:none;border-color:#58a6ff}

.score-display{background:#21262d;border-radius:8px;padding:14px;margin-bottom:16px;text-align:center}
.score-big{font-size:36px;font-weight:700;color:#d29922}
.score-sub{font-size:12px;color:#8b949e}
.score-bar-bg{background:#30363d;border-radius:4px;height:8px;margin:8px 0}
.score-bar-fill{height:8px;border-radius:4px;background:#d29922;transition:.5s}

.btn-save{width:100%;background:#238636;color:#fff;border:none;padding:12px;border-radius:8px;
  cursor:pointer;font-size:14px;font-weight:600}
.btn-save:hover{background:#2ea043}
.btn-save:disabled{background:#21262d;color:#8b949e;cursor:not-allowed}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:1px solid #30363d;margin-bottom:16px}
.tab-btn{padding:8px 16px;background:none;border:none;color:#8b949e;cursor:pointer;font-size:13px;border-bottom:2px solid transparent}
.tab-btn.active{color:#58a6ff;border-bottom-color:#58a6ff}
.tab-content{display:none}.tab-content.active{display:block}

.hist-row{padding:10px 12px;border-bottom:1px solid #21262d;font-size:12px}
.hist-top{display:flex;justify-content:space-between;margin-bottom:4px}
.hist-scores{display:flex;gap:8px;flex-wrap:wrap;color:#8b949e}
.hist-scores span{background:#21262d;padding:2px 6px;border-radius:4px}

.empty-state{text-align:center;padding:40px;color:#8b949e;font-size:13px}

/* Audio player */
.audio-wrap{margin-bottom:14px}
audio{width:100%;height:32px;border-radius:6px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <div class="brand">SkyKin<span> Technologies</span></div>
    <nav class="nav-links">
      <a href="/app/agent_dashboard/supervisor.php">Supervisor</a>
      <a href="/app/agent_dashboard/reports.php">Reports</a>
      <a href="/app/agent_dashboard/evaluation.php" class="active">Evaluation</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($domain); ?></div>
    <a href="/logout/index.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>

<div class="filters">
  <label>From</label><input type="date" id="fFrom" value="<?php echo $today; ?>">
  <label>To</label><input type="date" id="fTo" value="<?php echo $today; ?>">
  <input type="text" id="fSearch" placeholder="Search number..." style="width:160px">
  <button class="btn-filter" onclick="loadCalls()">&#128269; Search</button>
  <span id="callCount" style="font-size:12px;color:#8b949e"></span>
</div>

<div class="layout">
  <!-- Left: call list -->
  <div class="call-list" id="callList">
    <div class="empty-state">Loading calls...</div>
  </div>

  <!-- Right: eval panel -->
  <div class="eval-panel">
    <div id="evalEmpty" class="empty-state" style="margin-top:60px">
      <div style="font-size:32px;margin-bottom:12px">&#128203;</div>
      <div style="font-weight:600;color:#e6edf3;margin-bottom:6px">Select a call to evaluate</div>
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
          <div>Agent: <span id="emAgent">—</span></div>
          <div>Caller: <span id="emCaller">—</span></div>
          <div>Duration: <span id="emDuration">—</span></div>
        </div>

        <!-- Recording playback if available -->
        <div class="audio-wrap" id="audioWrap" style="display:none">
          <div style="font-size:11px;color:#8b949e;margin-bottom:6px">Recording</div>
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

<script>
const DOMAIN = '<?php echo $domain; ?>';
const EVALUATOR = '<?php echo htmlspecialchars($logged_in_user); ?>';
const scores = { greeting:0, knowledge:0, resolution:0, tone:0, procedure:0, closing:0 };
let selectedCall = null;

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
    list.innerHTML = rows.map(r => {
        const ev = r.eval;
        const evBadge = ev ? `<span class="grade-badge grade-${ev.grade.replace('+','\\+')}">${ev.grade}</span>` : '';
        return `<div class="call-item${ev?' evaluated':''}" onclick="selectCall(${JSON.stringify(JSON.stringify(r)).slice(1,-1)})">
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
}

function selectCall(jsonStr) {
    const r = JSON.parse(jsonStr);
    selectedCall = r;
    // Highlight
    document.querySelectorAll('.call-item').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');

    document.getElementById('evalEmpty').style.display = 'none';
    document.getElementById('evalForm').style.display  = 'block';

    document.getElementById('emCallTime').textContent = r.call_time;
    document.getElementById('emAgent').textContent    = r.destination_number;
    document.getElementById('emCaller').textContent   = r.caller_id_number + (r.caller_id_name?' ('+r.caller_id_name+')':'');
    document.getElementById('emDuration').textContent = fmtSecs(r.billsec);

    // Recording
    const aw = document.getElementById('audioWrap');
    if (r.record_name) {
        const url = '/app/recordings/index.php?filename='+encodeURIComponent(r.record_name)+'&path='+encodeURIComponent(r.record_path||'');
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
        btn.style.background = '#388bfd';
        event.currentTarget?.classList.add('evaluated');
        loadCalls(); // refresh
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
          ${r.notes?`<div style="font-size:11px;color:#8b949e;margin-top:4px">${r.notes}</div>`:''}
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
    document.getElementById('emAgent').textContent     = '';
    document.getElementById('emCaller').textContent    = '';
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

// Patch submitEval to support manual mode
const _origSubmit = window.submitEval;
window.submitEval = function() {
    if (document.getElementById('evalForm').dataset.manual === '1') {
        const agentVal  = document.getElementById('emAgent').textContent.trim();
        const callerVal = document.getElementById('emCaller').textContent.trim();
        if (!agentVal) { alert('Please enter the agent extension'); return; }
        selectedCall.destination_number = agentVal;
        selectedCall.caller_id_number   = callerVal;
        selectedCall.call_time          = new Date().toLocaleString();
        document.getElementById('evalForm').dataset.manual = '0';
    }
    if (_origSubmit) _origSubmit(); else submitEvalCore();
};

loadCalls();
</script>
</body>
</html>
