<?php
// SkyKin Technologies - Real-time Agent Dashboard
session_start();

// FusionPBX DB connection
$db_host = '127.0.0.1';
$db_name = 'fusionpbx';
$db_user = 'fusionpbx';
$db_pass = file_get_contents('/etc/fusionpbx/config.php') ? '' : '';

// Read DB password from FusionPBX config
$config_file = '/etc/fusionpbx/config.php';
if (file_exists($config_file)) {
    $config = file_get_contents($config_file);
    preg_match("/database_password.*?'(.*?)'/s", $config, $m);
    if (!empty($m[1])) $db_pass = $m[1];
    preg_match("/database_username.*?'(.*?)'/s", $config, $u);
    if (!empty($u[1])) $db_user = $u[1];
}

$agent_name = isset($_GET['agent']) ? htmlspecialchars($_GET['agent']) : 'Agent1';
$domain = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : 'client1.skykin.local';

// Generate initials from agent name
preg_match('/([A-Za-z]+)(\d*)/', $agent_name, $m);
$initials = strtoupper(substr($m[1] ?? $agent_name, 0, 2));
if (!empty($m[2])) $initials = strtoupper($m[1][0]) . $m[2];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkyKin Agent Dashboard - <?php echo $agent_name; ?></title>
<script src="/app/agent_dashboard/js/jssip.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; }

/* Header */
.header {
    background: linear-gradient(135deg, #0047AB 0%, #00B4D8 100%);
    color: white;
    padding: 0 24px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    gap: 16px;
}
.header .logo { font-size: 20px; font-weight: bold; letter-spacing: 1px; white-space: nowrap; flex-shrink: 0; }
.header .logo span { color: #00e5ff; }
.header .agent-info {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.15);
    border-radius: 30px;
    padding: 6px 14px 6px 8px;
    flex-shrink: 0;
}
.agent-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255,255,255,0.3);
    display: flex; align-items: center; justify-content: center;
    font-weight: bold; font-size: 13px;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.5);
}
.agent-text-info { display: flex; flex-direction: column; }
.agent-text-info .agent-name { font-weight: bold; font-size: 13px; line-height: 1.2; white-space: nowrap; }
.agent-text-info .agent-domain { font-size: 10px; opacity: 0.75; white-space: nowrap; }
.status-badge {
    padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold;
    background: #28a745; color: white; white-space: nowrap; flex-shrink: 0;
}
.status-badge.busy { background: #dc3545; }
.status-badge.idle { background: #ffc107; color: #333; }
.header-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.clock { font-size: 13px; opacity: 0.9; white-space: nowrap; }
.logout-btn {
    background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4);
    color: white; padding: 6px 16px; border-radius: 4px; cursor: pointer; font-size: 13px;
}
.logout-btn:hover { background: rgba(255,255,255,0.3); }

/* Layout */
.main { margin-top: 60px; padding: 20px; margin-bottom: 80px; }

/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.card {
    background: white;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border-left: 4px solid #0047AB;
    transition: transform 0.2s;
}
.card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,71,171,0.12); }
.card.green { border-left-color: #28a745; }
.card.orange { border-left-color: #fd7e14; }
.card.red { border-left-color: #dc3545; }
.card.teal { border-left-color: #00B4D8; }
.card.purple { border-left-color: #6f42c1; }
.card.yellow { border-left-color: #ffc107; }
.card-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.card-value { font-size: 26px; font-weight: bold; color: #0047AB; }
.card.green .card-value { color: #28a745; }
.card.orange .card-value { color: #fd7e14; }
.card.red .card-value { color: #dc3545; }
.card.teal .card-value { color: #00B4D8; }
.card.purple .card-value { color: #6f42c1; }
.card.yellow .card-value { color: #e6a800; }
.card-sub { font-size: 11px; color: #aaa; margin-top: 4px; }

/* Sections */
.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.section-box {
    background: white;
    border-radius: 10px;
    padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.section-title {
    font-size: 14px; font-weight: bold; color: #0047AB;
    border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
}
.section-title .dot {
    width: 8px; height: 8px; border-radius: 50%; background: #0047AB; display: inline-block;
}

/* Metric rows */
.metric-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; border-bottom: 1px solid #f5f5f5; font-size: 13px;
}
.metric-row:last-child { border-bottom: none; }
.metric-name { color: #555; }
.metric-val { font-weight: bold; color: #333; }
.metric-val.good { color: #28a745; }
.metric-val.warn { color: #fd7e14; }
.metric-val.bad { color: #dc3545; }

/* Activity Timeline */
.timeline { margin-top: 4px; }
.timeline-item {
    display: flex; gap: 10px; padding: 8px 0;
    border-bottom: 1px solid #f5f5f5; font-size: 12px;
}
.timeline-item:last-child { border-bottom: none; }
.tl-time { color: #888; min-width: 55px; }
.tl-icon {
    width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; flex-shrink: 0;
}
.tl-icon.call { background: #d4edda; color: #28a745; }
.tl-icon.missed { background: #f8d7da; color: #dc3545; }
.tl-icon.transfer { background: #d1ecf1; color: #00B4D8; }
.tl-icon.hold { background: #fff3cd; color: #e6a800; }
.tl-text { color: #444; }

/* Progress bars */
.progress-wrap { margin-top: 6px; }
.progress-label { display: flex; justify-content: space-between; font-size: 11px; color: #888; margin-bottom: 3px; }
.progress-bar { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 3px; transition: width 1s; }
.progress-fill.blue { background: #0047AB; }
.progress-fill.green { background: #28a745; }
.progress-fill.orange { background: #fd7e14; }

/* Full width section */
.full-section {
    background: white; border-radius: 10px; padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px;
}

/* Table */
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th {
    background: #f8f9fa; padding: 10px 12px; text-align: left;
    font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 0.5px;
    border-bottom: 2px solid #e9ecef;
}
.data-table td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; }
.data-table tr:hover td { background: #f8fbff; }
.badge {
    padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;
}
.badge-in { background: #d4edda; color: #28a745; }
.badge-out { background: #d1ecf1; color: #00B4D8; }
.badge-missed { background: #f8d7da; color: #dc3545; }
.badge-transfer { background: #e2d9f3; color: #6f42c1; }

/* Live indicator */
.live-dot {
    display: inline-block; width: 8px; height: 8px;
    background: #28a745; border-radius: 50%;
    animation: pulse 1.5s infinite;
    margin-right: 6px;
}
@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(40,167,69,0.5); }
    70% { box-shadow: 0 0 0 6px rgba(40,167,69,0); }
    100% { box-shadow: 0 0 0 0 rgba(40,167,69,0); }
}

/* Softphone Bar */
.softphone-bar {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
    background: linear-gradient(135deg, #0a0a2e 0%, #0047AB 100%);
    color: white; padding: 10px 24px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: 0 -4px 20px rgba(0,71,171,0.3);
}
.softphone-status {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; min-width: 140px;
}
.sip-dot { width: 10px; height: 10px; border-radius: 50%; background: #888; flex-shrink: 0; }
.sip-dot.registered { background: #28a745; animation: pulse 2s infinite; }
.sip-dot.calling { background: #ffc107; animation: pulse 0.5s infinite; }
.sip-dot.incall { background: #28a745; }
.sip-dot.ringing { background: #fd7e14; animation: pulse 0.4s infinite; }
.dial-input-wrap { display: flex; gap: 6px; flex: 1; max-width: 280px; }
.dial-input {
    background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3);
    color: white; padding: 8px 12px; border-radius: 6px; font-size: 15px;
    letter-spacing: 2px; flex: 1; outline: none;
}
.dial-input::placeholder { color: rgba(255,255,255,0.4); letter-spacing: 0; }
.dial-input:focus { border-color: #00B4D8; background: rgba(255,255,255,0.15); }
.btn-call {
    background: #28a745; border: none; color: white;
    padding: 8px 20px; border-radius: 6px; cursor: pointer;
    font-size: 14px; font-weight: bold; transition: background 0.2s;
}
.btn-call:hover { background: #218838; }
.btn-call:disabled { background: #555; cursor: not-allowed; }
.btn-hangup {
    background: #dc3545; border: none; color: white;
    padding: 8px 20px; border-radius: 6px; cursor: pointer;
    font-size: 14px; font-weight: bold; display: none;
}
.btn-hangup:hover { background: #c82333; }
.btn-hold {
    background: #ffc107; border: none; color: #333;
    padding: 8px 16px; border-radius: 6px; cursor: pointer;
    font-size: 13px; display: none;
}
.call-timer { font-size: 18px; font-weight: bold; color: #00B4D8; min-width: 60px; display: none; }
.softphone-setup { margin-left: auto; }
.btn-settings {
    background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3);
    color: white; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;
}

/* Incoming call overlay */
.incoming-overlay {
    display: none; position: fixed; top: 80px; right: 20px; z-index: 300;
    background: white; border-radius: 12px; padding: 20px 24px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2); min-width: 260px;
    border-top: 4px solid #28a745; animation: slideIn 0.3s ease;
}
@keyframes slideIn { from { transform: translateX(100px); opacity:0; } to { transform: translateX(0); opacity:1; } }
.incoming-title { font-size: 12px; color: #888; text-transform: uppercase; margin-bottom: 4px; }
.incoming-number { font-size: 22px; font-weight: bold; color: #0047AB; margin-bottom: 16px; }
.incoming-actions { display: flex; gap: 10px; }
.btn-answer { background: #28a745; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; flex: 1; }
.btn-decline { background: #dc3545; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; flex: 1; }

/* Settings modal */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-box { background: white; border-radius: 12px; padding: 28px; width: 360px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
.modal-title { font-size: 16px; font-weight: bold; color: #0047AB; margin-bottom: 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
.form-group input { width: 100%; border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px; font-size: 14px; }
.btn-save-settings { background: #0047AB; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; width: 100%; margin-top: 8px; }
    text-align: center; font-size: 11px; color: #aaa; padding: 16px;
}

@media (max-width: 768px) {
    .section-grid { grid-template-columns: 1fr; }
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<div class="header">
    <div class="logo">SKY<span>KIN</span> Technologies</div>
    <div class="agent-info">
        <div class="agent-avatar"><?php echo $initials; ?></div>
        <div class="agent-text-info">
            <span class="agent-name"><?php echo $agent_name; ?></span>
            <span class="agent-domain"><?php echo $domain; ?></span>
        </div>
        <span class="status-badge" id="agentStatus">Available</span>
    </div>
    <div class="header-right">
        <span class="live-dot"></span>
        <span style="font-size:12px;">Live</span>
        <div class="clock" id="liveClock"></div>
        <button class="logout-btn" onclick="window.location='/logout.php'">Logout</button>
    </div>
</div>

<div class="main">

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="card green">
            <div class="card-label">Total Calls Today</div>
            <div class="card-value" id="totalCalls">--</div>
            <div class="card-sub">Inbound + Outbound</div>
        </div>
        <div class="card teal">
            <div class="card-label">Avg Call Duration</div>
            <div class="card-value" id="avgDuration">--</div>
            <div class="card-sub">Minutes:Seconds</div>
        </div>
        <div class="card">
            <div class="card-label">Total Talk Time</div>
            <div class="card-value" id="totalTalk">--</div>
            <div class="card-sub">Today</div>
        </div>
        <div class="card orange">
            <div class="card-label">Idle Duration</div>
            <div class="card-value" id="idleTime">--</div>
            <div class="card-sub">Between calls</div>
        </div>
        <div class="card red">
            <div class="card-label">Missed Calls</div>
            <div class="card-value" id="missedCalls">--</div>
            <div class="card-sub">Unanswered</div>
        </div>
        <div class="card purple">
            <div class="card-label">Transfers</div>
            <div class="card-value" id="transfers">--</div>
            <div class="card-sub">Forwarded calls</div>
        </div>
        <div class="card yellow">
            <div class="card-label">Hold Times</div>
            <div class="card-value" id="holdTimes">--</div>
            <div class="card-sub">Total hold duration</div>
        </div>
        <div class="card green">
            <div class="card-label">Working Duration</div>
            <div class="card-value" id="workDuration">--</div>
            <div class="card-sub">Since login</div>
        </div>
    </div>

    <!-- Two Column Section -->
    <div class="section-grid">

        <!-- Call Time Metrics -->
        <div class="section-box">
            <div class="section-title"><span class="dot"></span> Call Time Metrics</div>
            <div class="metric-row">
                <span class="metric-name">Listening Duration</span>
                <span class="metric-val" id="listeningDuration">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Internal Call Times</span>
                <span class="metric-val" id="internalCallTime">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Making Calls Times</span>
                <span class="metric-val" id="outboundTime">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Hook-on Times</span>
                <span class="metric-val" id="hookOnTimes">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Total Call Duration</span>
                <span class="metric-val" id="totalDuration">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Arranging State (ACW)</span>
                <span class="metric-val" id="acwDuration">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Transfer to IVR Times</span>
                <span class="metric-val" id="ivrTransfer">--</span>
            </div>
        </div>

        <!-- Status & Activity Metrics -->
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#28a745"></span> Status & Activity</div>
            <div class="metric-row">
                <span class="metric-name">Busy Duration</span>
                <span class="metric-val warn" id="busyDuration">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Rest Duration</span>
                <span class="metric-val" id="restDuration">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Over-Rest Duration</span>
                <span class="metric-val bad" id="overRest">00:00</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Interception Times</span>
                <span class="metric-val" id="interceptions">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Internal Help Times</span>
                <span class="metric-val" id="internalHelp">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Login / Logout Count</span>
                <span class="metric-val" id="loginCount">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Force Sign-out Times</span>
                <span class="metric-val bad" id="forceSignout">0</span>
            </div>
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
            <div class="section-title"><span class="dot" style="background:#6f42c1"></span> Monitoring & Supervision</div>
            <div class="metric-row">
                <span class="metric-name">Listening Count</span>
                <span class="metric-val" id="listeningCount">0</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Listen as Third-Party</span>
                <span class="metric-val" id="thirdPartyCount">0</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Force Advisor Count</span>
                <span class="metric-val" id="forceAdvisorCount">0</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Handle Call on Behalf</span>
                <span class="metric-val" id="handleOnBehalf">0</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Ask Help (Chat/Tool)</span>
                <span class="metric-val" id="askHelpCount">0</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Call Reason Count</span>
                <span class="metric-val" id="callReasonCount">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Forwarding Times</span>
                <span class="metric-val" id="forwardingTimes">--</span>
            </div>
        </div>

        <!-- Queue Status -->
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#fd7e14"></span> Queue Status</div>
            <div class="metric-row">
                <span class="metric-name">Queue Name</span>
                <span class="metric-val" id="queueName">Support Queue</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Calls Waiting</span>
                <span class="metric-val warn" id="callsWaiting">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Agents Online</span>
                <span class="metric-val good" id="agentsOnline">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">Avg Wait Time</span>
                <span class="metric-val" id="avgWait">--</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">My Position</span>
                <span class="metric-val" id="myPosition">Active</span>
            </div>
            <div class="metric-row">
                <span class="metric-name">SLA (Target &lt;30s)</span>
                <span class="metric-val good" id="slaRate">--%</span>
            </div>
        </div>
    </div>

    <!-- Recent Call Activity -->
    <div class="full-section">
        <div class="section-title"><span class="dot"></span> Recent Call Activity</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Number</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Disposition</th>
                </tr>
            </thead>
            <tbody id="callHistory">
                <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px;">Loading call history...</td></tr>
            </tbody>
        </table>
    </div>

</div>

<div class="footer">
    SkyKin Technologies &copy; <?php echo date('Y'); ?> | Agent Dashboard v1.0 | 
    Auto-refresh: <span id="refreshCountdown">10</span>s
</div>

<script>
const agentName = '<?php echo $agent_name; ?>';
const domain = '<?php echo $domain; ?>';
let loginTime = new Date();
let refreshInterval = 10;
let countdown = refreshInterval;

// Live clock
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('liveClock').textContent = h+':'+m+':'+s;

    // Working duration
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

// Fetch data from API
function fetchData() {
    fetch('data.php?agent='+encodeURIComponent(agentName)+'&domain='+encodeURIComponent(domain))
        .then(r => r.json())
        .then(data => updateDashboard(data))
        .catch(() => {
            // If API fails, show demo data
            updateDashboard(getDemoData());
        });
}

function getDemoData() {
    return {
        total_calls: 24,
        answered_calls: 21,
        missed_calls: 3,
        avg_duration: 187,
        total_talk: 4488,
        total_duration: 5200,
        listening_duration: 4488,
        internal_call_time: 420,
        outbound_time: 1820,
        hook_on_times: 24,
        hold_times: 340,
        transfers: 5,
        forwarding_times: 3,
        acw_duration: 280,
        ivr_transfer: 2,
        busy_duration: 4488,
        rest_duration: 1200,
        over_rest: 0,
        idle_duration: 2800,
        interceptions: 1,
        internal_help: 2,
        login_count: 1,
        force_signout: 0,
        listening_count: 0,
        third_party_count: 0,
        force_advisor_count: 0,
        handle_on_behalf: 0,
        ask_help_count: 0,
        call_reason_count: 18,
        queue_waiting: 2,
        agents_online: 3,
        avg_wait: 18,
        sla_rate: 92,
        recent_calls: [
            {time:'10:45', type:'Inbound', number:'+251911234567', duration:'3:42', status:'Answered', disposition:'Resolved'},
            {time:'10:32', type:'Outbound', number:'+251922345678', duration:'2:15', status:'Answered', disposition:'Callback'},
            {time:'10:18', type:'Inbound', number:'+251933456789', duration:'0:00', status:'Missed', disposition:'Voicemail'},
            {time:'10:05', type:'Transfer', number:'Ext 102', duration:'1:30', status:'Transferred', disposition:'Internal'},
            {time:'09:52', type:'Inbound', number:'+251944567890', duration:'5:10', status:'Answered', disposition:'Resolved'},
        ]
    };
}

function updateDashboard(d) {
    // Summary cards
    document.getElementById('totalCalls').textContent = d.total_calls || 0;
    document.getElementById('avgDuration').textContent = formatDurationHMS(d.avg_duration || 0);
    document.getElementById('totalTalk').textContent = formatDuration(d.total_talk || 0);
    document.getElementById('idleTime').textContent = formatDuration(d.idle_duration || 0);
    document.getElementById('missedCalls').textContent = d.missed_calls || 0;
    document.getElementById('transfers').textContent = d.transfers || 0;
    document.getElementById('holdTimes').textContent = formatDuration(d.hold_times || 0);

    // Call time metrics
    document.getElementById('listeningDuration').textContent = formatDuration(d.listening_duration || 0);
    document.getElementById('internalCallTime').textContent = formatDuration(d.internal_call_time || 0);
    document.getElementById('outboundTime').textContent = formatDuration(d.outbound_time || 0);
    document.getElementById('hookOnTimes').textContent = d.hook_on_times || 0;
    document.getElementById('totalDuration').textContent = formatDuration(d.total_duration || 0);
    document.getElementById('acwDuration').textContent = formatDuration(d.acw_duration || 0);
    document.getElementById('ivrTransfer').textContent = d.ivr_transfer || 0;

    // Status metrics
    document.getElementById('busyDuration').textContent = formatDuration(d.busy_duration || 0);
    document.getElementById('restDuration').textContent = formatDuration(d.rest_duration || 0);
    document.getElementById('overRest').textContent = formatDurationHMS(d.over_rest || 0);
    document.getElementById('interceptions').textContent = d.interceptions || 0;
    document.getElementById('internalHelp').textContent = d.internal_help || 0;
    document.getElementById('loginCount').textContent = (d.login_count || 1) + ' / 0';
    document.getElementById('forceSignout').textContent = d.force_signout || 0;

    // Monitoring
    document.getElementById('listeningCount').textContent = d.listening_count || 0;
    document.getElementById('thirdPartyCount').textContent = d.third_party_count || 0;
    document.getElementById('forceAdvisorCount').textContent = d.force_advisor_count || 0;
    document.getElementById('handleOnBehalf').textContent = d.handle_on_behalf || 0;
    document.getElementById('askHelpCount').textContent = d.ask_help_count || 0;
    document.getElementById('callReasonCount').textContent = d.call_reason_count || 0;
    document.getElementById('forwardingTimes').textContent = d.forwarding_times || 0;

    // Queue
    document.getElementById('callsWaiting').textContent = d.queue_waiting || 0;
    document.getElementById('agentsOnline').textContent = d.agents_online || 0;
    document.getElementById('avgWait').textContent = (d.avg_wait || 0) + 's';
    document.getElementById('slaRate').textContent = (d.sla_rate || 0) + '%';

    // Progress bars
    const answerRate = d.total_calls > 0 ? Math.round((d.answered_calls / d.total_calls) * 100) : 0;
    document.getElementById('answerRate').textContent = answerRate + '%';
    document.getElementById('answerRateBar').style.width = answerRate + '%';

    const talkTotal = (d.total_talk || 0) + (d.idle_duration || 0);
    const talkRatio = talkTotal > 0 ? Math.round(((d.total_talk || 0) / talkTotal) * 100) : 0;
    document.getElementById('talkRatio').textContent = talkRatio + '%';
    document.getElementById('talkRatioBar').style.width = talkRatio + '%';

    const targetRate = Math.min(100, Math.round(((d.total_calls || 0) / 30) * 100));
    document.getElementById('targetRate').textContent = targetRate + '%';
    document.getElementById('targetRateBar').style.width = targetRate + '%';

    // Recent calls
    if (d.recent_calls && d.recent_calls.length > 0) {
        let html = '';
        d.recent_calls.forEach(c => {
            const typeBadge = c.type === 'Inbound' ? 'badge-in' :
                              c.type === 'Outbound' ? 'badge-out' :
                              c.type === 'Transfer' ? 'badge-transfer' : 'badge-missed';
            const statusBadge = c.status === 'Missed' ? 'badge-missed' :
                                c.status === 'Transferred' ? 'badge-transfer' : 'badge-in';
            html += `<tr>
                <td>${c.time}</td>
                <td><span class="badge ${typeBadge}">${c.type}</span></td>
                <td>${c.number}</td>
                <td>${c.duration}</td>
                <td><span class="badge ${statusBadge}">${c.status}</span></td>
                <td>${c.disposition}</td>
            </tr>`;
        });
        document.getElementById('callHistory').innerHTML = html;
    }
}

// Countdown and refresh
function startCountdown() {
    countdown = refreshInterval;
    const timer = setInterval(() => {
        countdown--;
        document.getElementById('refreshCountdown').textContent = countdown;
        if (countdown <= 0) {
            clearInterval(timer);
            fetchData();
            startCountdown();
        }
    }, 1000);
}

// Init
setInterval(updateClock, 1000);
updateClock();
fetchData();
startCountdown();
</script>
<!-- Incoming Call Overlay -->
<div class="incoming-overlay" id="incomingOverlay">
    <div class="incoming-title">📞 Incoming Call</div>
    <div class="incoming-number" id="incomingNumber">Unknown</div>
    <div class="incoming-actions">
        <button class="btn-answer" onclick="answerCall()">Answer</button>
        <button class="btn-decline" onclick="declineCall()">Decline</button>
    </div>
</div>

<!-- Settings Modal -->
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
            <label>Domain</label>
            <input type="text" id="sipDomain" placeholder="client1.skykin.local" value="<?php echo $domain; ?>">
        </div>
        <button class="btn-save-settings" onclick="saveSipSettings()">Connect</button>
    </div>
</div>

<!-- Softphone Bar -->
<div class="softphone-bar">
    <div class="softphone-status">
        <div class="sip-dot" id="sipDot"></div>
        <span id="sipStatusText">Not Connected</span>
    </div>
    <div class="dial-input-wrap">
        <input type="tel" class="dial-input" id="dialInput" placeholder="Enter number to call..." maxlength="20">
        <button class="btn-call" id="btnCall" onclick="makeCall()" disabled>📞 Call</button>
        <button class="btn-hangup" id="btnHangup" onclick="hangupCall()">📵 Hang up</button>
        <button class="btn-hold" id="btnHold" onclick="toggleHold()">⏸ Hold</button>
    </div>
    <div class="call-timer" id="callTimer">00:00</div>
    <div class="softphone-setup">
        <button class="btn-settings" onclick="document.getElementById('settingsModal').classList.add('show')">⚙ Setup Phone</button>
    </div>
</div>

<script src="/app/agent_dashboard/js/jssip.min.js"></script>
<script>
// ===== WebRTC Softphone =====
let ua = null;
let currentSession = null;
let callStartTime = null;
let callTimerInterval = null;
let onHold = false;
let remoteAudio = new Audio();
remoteAudio.autoplay = true;

// Load saved settings
function loadSipSettings() {
    const ext = localStorage.getItem('sip_ext') || '';
    const pass = localStorage.getItem('sip_pass') || '';
    const server = localStorage.getItem('sip_server') || '192.168.243.129';
    const domain = localStorage.getItem('sip_domain') || '<?php echo $domain; ?>';
    document.getElementById('sipExt').value = ext;
    document.getElementById('sipPass').value = pass;
    document.getElementById('sipServer').value = server;
    document.getElementById('sipDomain').value = domain;
    if (ext && pass) initSIP(ext, pass, server, domain);
}

function saveSipSettings() {
    const ext = document.getElementById('sipExt').value.trim();
    const pass = document.getElementById('sipPass').value.trim();
    const server = document.getElementById('sipServer').value.trim();
    const domain = document.getElementById('sipDomain').value.trim();
    if (!ext || !pass) { alert('Please enter extension and password'); return; }
    localStorage.setItem('sip_ext', ext);
    localStorage.setItem('sip_pass', pass);
    localStorage.setItem('sip_server', server);
    localStorage.setItem('sip_domain', domain);
    document.getElementById('settingsModal').classList.remove('show');
    initSIP(ext, pass, server, domain);
}

function initSIP(ext, pass, server, domain) {
    if (ua) { try { ua.stop(); } catch(e) {} }
    setSipStatus('connecting', 'Connecting...');
    try {
        const socket = new JsSIP.WebSocketInterface('ws://' + server + ':5066');
        const config = {
            sockets: [socket],
            uri: 'sip:' + ext + '@' + domain,
            password: pass,
            display_name: '<?php echo $agent_name; ?>',
            register: true,
            register_expires: 300,
            connection_recovery_min_interval: 2,
            connection_recovery_max_interval: 30
        };
        ua = new JsSIP.UA(config);

        ua.on('registered', () => setSipStatus('registered', 'Registered (' + ext + ')'));
        ua.on('unregistered', () => setSipStatus('unregistered', 'Not Registered'));
        ua.on('registrationFailed', (e) => setSipStatus('failed', 'Reg Failed: ' + (e.cause || 'error')));

        ua.on('newRTCSession', (data) => {
            const session = data.session;
            if (session.direction === 'incoming') {
                handleIncoming(session);
            } else {
                handleOutgoing(session);
            }
        });

        ua.start();
    } catch(e) {
        setSipStatus('failed', 'Error: ' + e.message);
    }
}

function setSipStatus(state, text) {
    const dot = document.getElementById('sipDot');
    const statusText = document.getElementById('sipStatusText');
    dot.className = 'sip-dot';
    if (state === 'registered') {
        dot.classList.add('registered');
        document.getElementById('btnCall').disabled = false;
        document.getElementById('agentStatus').textContent = 'Available';
        document.getElementById('agentStatus').className = 'status-badge';
    } else if (state === 'calling') {
        dot.classList.add('calling');
    } else if (state === 'incall') {
        dot.classList.add('incall');
    } else if (state === 'ringing') {
        dot.classList.add('ringing');
    }
    statusText.textContent = text;
}

function handleIncoming(session) {
    currentSession = session;
    const callerNumber = session.remote_identity.uri.user;
    document.getElementById('incomingNumber').textContent = callerNumber;
    document.getElementById('incomingOverlay').style.display = 'block';
    setSipStatus('ringing', 'Ringing: ' + callerNumber);

    session.on('ended', endCall);
    session.on('failed', endCall);
}

function answerCall() {
    if (!currentSession) return;
    document.getElementById('incomingOverlay').style.display = 'none';
    const options = {
        mediaConstraints: { audio: true, video: false },
        pcConfig: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
    };
    currentSession.answer(options);
    currentSession.connection.addEventListener('addstream', (e) => {
        remoteAudio.srcObject = e.streams[0];
    });
    startCallUI(currentSession.remote_identity.uri.user);
}

function declineCall() {
    if (currentSession) currentSession.terminate();
    document.getElementById('incomingOverlay').style.display = 'none';
    currentSession = null;
}

function makeCall() {
    const number = document.getElementById('dialInput').value.trim();
    if (!number || !ua) return;
    const domain = localStorage.getItem('sip_domain') || '<?php echo $domain; ?>';
    const options = {
        mediaConstraints: { audio: true, video: false },
        pcConfig: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
    };
    currentSession = ua.call('sip:' + number + '@' + domain, options);
    handleOutgoing(currentSession);
}

function handleOutgoing(session) {
    session.on('connecting', () => setSipStatus('calling', 'Calling...'));
    session.on('progress', () => setSipStatus('ringing', 'Ringing...'));
    session.on('confirmed', () => startCallUI(session.remote_identity.uri.user));
    session.on('ended', endCall);
    session.on('failed', (e) => { endCall(); setSipStatus('registered', 'Call Failed: ' + (e.cause || '')); });
    session.connection?.addEventListener('addstream', (e) => { remoteAudio.srcObject = e.streams[0]; });
    setSipStatus('calling', 'Calling ' + document.getElementById('dialInput').value);
}

function startCallUI(number) {
    setSipStatus('incall', 'In Call: ' + number);
    document.getElementById('btnCall').style.display = 'none';
    document.getElementById('btnHangup').style.display = 'block';
    document.getElementById('btnHold').style.display = 'block';
    document.getElementById('callTimer').style.display = 'block';
    document.getElementById('agentStatus').textContent = 'On Call';
    document.getElementById('agentStatus').className = 'status-badge busy';
    callStartTime = new Date();
    callTimerInterval = setInterval(updateCallTimer, 1000);
}

function updateCallTimer() {
    if (!callStartTime) return;
    const elapsed = Math.floor((new Date() - callStartTime) / 1000);
    const m = Math.floor(elapsed / 60);
    const s = elapsed % 60;
    document.getElementById('callTimer').textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function hangupCall() {
    if (currentSession) { try { currentSession.terminate(); } catch(e) {} }
    endCall();
}

function toggleHold() {
    if (!currentSession) return;
    if (onHold) {
        currentSession.unhold();
        onHold = false;
        document.getElementById('btnHold').textContent = '⏸ Hold';
        document.getElementById('btnHold').style.background = '#ffc107';
    } else {
        currentSession.hold();
        onHold = true;
        document.getElementById('btnHold').textContent = '▶ Resume';
        document.getElementById('btnHold').style.background = '#28a745';
        document.getElementById('btnHold').style.color = 'white';
    }
}

function endCall() {
    currentSession = null;
    onHold = false;
    clearInterval(callTimerInterval);
    callStartTime = null;
    document.getElementById('btnCall').style.display = 'block';
    document.getElementById('btnHangup').style.display = 'none';
    document.getElementById('btnHold').style.display = 'none';
    document.getElementById('callTimer').style.display = 'none';
    document.getElementById('callTimer').textContent = '00:00';
    document.getElementById('btnHold').textContent = '⏸ Hold';
    document.getElementById('agentStatus').textContent = 'Available';
    document.getElementById('agentStatus').className = 'status-badge';
    setSipStatus('registered', 'Registered');
    fetchData();
}

// Close settings modal on outside click
document.getElementById('settingsModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});

// Allow Enter key to dial
document.getElementById('dialInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') makeCall();
});

loadSipSettings();
</script>


