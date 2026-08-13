<?php
// SkyKin Technologies – Department Tickets Portal (Standalone)
// No authentication required for internal team use.

function getDB() {
    static $db = null;
    if ($db) return $db;
    $h = '127.0.0.1'; $p = '5432'; $n = 'fusionpbx'; $u = 'fusionpbx'; $pw = '';
    $conf = '/etc/fusionpbx/config.conf';
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
    // Try local config first, then remote FusionPBX server, with multiple credential sets
    foreach ([$h, '192.168.0.114'] as $_h) {
        foreach ([[$u, $pw], ['fusionpbx', 'vtEWIukU24Lbr9Zi5NxchwVF2g'], ['postgres', 'vtEWIukU24Lbr9Zi5NxchwVF2g']] as [$_u, $_pw]) {
            try {
                $db = new PDO("pgsql:host={$_h};port={$p};dbname={$n}", $_u, $_pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                return $db;
            } catch (Exception $ignored) {}
        }
    }
    // SQLite fallback for local development
    // Shared with agent_dashboard so tickets are visible in both portals
    $sqliteFile = __DIR__ . '/../agent_dashboard/skykin_local.db';
    $db = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL');
    return $db;
}

// ── API: Get Tickets ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_tickets') {
    error_reporting(0);
    header('Content-Type: application/json');
    
    $dept = $_GET['department'] ?? 'All';
    $status = $_GET['status'] ?? 'All';
    $priority = $_GET['priority'] ?? 'All';
    $search = $_GET['search'] ?? '';
    
    try {
        $db = getDB();
        $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

        // Ensure table exists for SQLite fallback
        if ($isSQLite) {
            $db->exec("CREATE TABLE IF NOT EXISTS skykin_cases (
                case_id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name TEXT, customer_phone TEXT, order_id TEXT,
                issue_type TEXT, description TEXT, delivery_date TEXT,
                department TEXT, agent_id TEXT,
                priority TEXT DEFAULT 'Medium', status TEXT DEFAULT 'Received',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }

        $conditions = [];
        $params = [];
        
        if ($dept !== 'All') {
            $conditions[] = "department = :dept";
            $params[':dept'] = $dept;
        }
        if ($status !== 'All') {
            $conditions[] = "status = :status";
            $params[':status'] = $status;
        }
        if ($priority !== 'All') {
            $conditions[] = "priority = :priority";
            $params[':priority'] = $priority;
        }
        if ($search !== '') {
            // SQLite LIKE is case-insensitive for ASCII by default; PostgreSQL needs ILIKE
            $likeOp = $isSQLite ? 'LIKE' : 'ILIKE';
            $conditions[] = "(customer_name {$likeOp} :search OR customer_phone {$likeOp} :search OR order_id {$likeOp} :search OR description {$likeOp} :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereSql = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $dateFmt = $isSQLite
            ? "strftime('%Y-%m-%d %H:%M', created_at)"
            : "to_char(created_at, 'YYYY-MM-DD HH24:MI')";
        $deliveryFmt = $isSQLite ? "date(delivery_date)" : "to_char(delivery_date, 'YYYY-MM-DD')";

        $sql = "SELECT case_id, {$dateFmt} as formatted_date,
                       customer_name, customer_phone, order_id, issue_type, description, 
                       {$deliveryFmt} as delivery_date, department, agent_id, priority, status
                FROM skykin_cases
                $whereSql
                ORDER BY created_at DESC LIMIT 300";
                
        $s = $db->prepare($sql);
        $s->execute($params);
        $records = $s->fetchAll(PDO::FETCH_ASSOC);
        
        // Count stats globally or filtered by dept
        $statWhere = ($dept !== 'All') ? 'WHERE department = :dept' : '';
        $statStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM skykin_cases {$statWhere} GROUP BY status");
        $statParams = ($dept !== 'All') ? [':dept' => $dept] : [];
        $statStmt->execute($statParams);
        $statsRaw = $statStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats = ['Received' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Total' => 0];
        foreach ($statsRaw as $row) {
            $stVal = $row['status'];
            if ($stVal === 'Open' || $stVal === 'Received') {
                $stats['Received'] += (int)$row['cnt'];
            } elseif ($stVal === 'In Progress') {
                $stats['In Progress'] += (int)$row['cnt'];
            } elseif ($stVal === 'Resolved') {
                $stats['Resolved'] += (int)$row['cnt'];
            }
            $stats['Total'] += (int)$row['cnt'];
        }
        
        echo json_encode([
            'ok' => true,
            'records' => $records,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ── API: Update Stage/Status ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $case_id = (int)($body['case_id'] ?? 0);
    $stage = $body['stage'] ?? '';
    
    if (!$case_id || !in_array($stage, ['Received', 'In Progress', 'Resolved', 'Open'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    try {
        $db = getDB();
        $s = $db->prepare("UPDATE skykin_cases SET status = :status WHERE case_id = :id");
        $s->execute([':status' => $stage, ':id' => $case_id]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// Build the absolute URL to this script for JS fetch() calls
$self_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Use the live page location so fetch() keeps the exact scheme, host AND port
         (e.g. :8088). Building this server-side from HTTP_HOST dropped the port and
         sent requests to :443 with a mismatched TLS cert -> "Failed to fetch". -->
    <script>const SELF_URL = window.location.pathname;</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Tickets - SkyKin Technologies</title>
    <style>
        :root {
            /* ── Light theme matching Agent Dashboard ── */
            --bg-base: #f0f2f5;
            --bg-surface: #ffffff;
            --bg-card: #ffffff;
            --border-color: #e9ecef;
            --text-primary: #333333;
            --text-secondary: #555555;
            --text-muted: #888888;

            --clr-primary: #0047AB;
            --clr-primary-glow: rgba(0, 71, 171, 0.15);

            --clr-received: #fd7e14;
            --clr-received-bg: rgba(253, 126, 20, 0.1);
            --clr-progress: #00B4D8;
            --clr-progress-bg: rgba(0, 180, 216, 0.1);
            --clr-resolved: #28a745;
            --clr-resolved-bg: rgba(40, 167, 69, 0.1);

            --clr-high: #dc3545;
            --clr-high-bg: rgba(220, 53, 69, 0.1);
            --clr-medium: #fd7e14;
            --clr-medium-bg: rgba(253, 126, 20, 0.1);
            --clr-low: #28a745;
            --clr-low-bg: rgba(40, 167, 69, 0.1);
        }

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

        /* Logo text matching agent dashboard: SKY<span>KIN</span> Technologies */
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

        .header-meta {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Refresh spinner & Countdown */
        .refresh-control {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            color: white;
            user-select: none;
        }

        .refresh-control input {
            cursor: pointer;
        }

        .refresh-circle {
            position: relative;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .refresh-circle svg {
            width: 18px;
            height: 18px;
            transform: rotate(-90deg);
        }

        .refresh-circle circle {
            fill: none;
            stroke-width: 2;
        }

        .refresh-circle .bg-ring {
            stroke: rgba(255,255,255,0.3);
        }

        .refresh-circle .progress-ring {
            stroke: #00e5ff;
            stroke-dasharray: 50.26;
            stroke-dashoffset: 0;
            transition: stroke-dashoffset 0.1s linear;
        }

        /* Main content area */
        main {
            flex: 1;
            width: 100%;
            padding: 20px 24px;
        }

        /* Tabs Navigation — matching agent dashboard tab style */
        .dept-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 24px;
            padding-bottom: 0;
            overflow-x: auto;
        }

        .dept-tab {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            padding: 8px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            border-radius: 4px 4px 0 0;
            position: relative;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .dept-tab:hover:not(.active) {
            color: #0047AB;
            background: #f8f9fa;
        }

        .dept-tab.active {
            color: #0047AB;
            border-bottom-color: #0047AB;
            background: #f0f5ff;
        }

        /* Filters section */
        .filter-panel {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .search-wrapper {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-input {
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            padding: 10px 16px;
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
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .filter-select {
            background: white;
            border: 1px solid #ddd;
            padding: 8px 14px;
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-select:focus {
            border-color: var(--clr-primary);
        }

        /* Stats Cards in filter */
        .stats-summary {
            display: flex;
            gap: 12px;
            margin-left: auto;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: white;
            border: 1px solid var(--border-color);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: var(--text-secondary);
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .stat-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .stat-badge.received .dot { background-color: var(--clr-received); }
        .stat-badge.progress .dot { background-color: var(--clr-progress); }
        .stat-badge.resolved .dot { background-color: var(--clr-resolved); }

        .stat-badge .val {
            font-weight: 700;
            color: var(--text-primary);
        }

        /* ── Ticket Table ─────────────────────────────────────────────── */
        .ticket-table-wrap {
            background: var(--bg-surface);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow-x: auto;
        }

        .ticket-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ticket-table th {
            background: #f8f9fa;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .ticket-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }

        .ticket-table tr:last-child td { border-bottom: none; }

        .ticket-table tr:hover td { background: #f8fbff; }

        /* Priority left-border accent via a 4px colored first-cell border */
        .ticket-table tr.prio-high-row   td:first-child { border-left: 4px solid #dc3545; }
        .ticket-table tr.prio-medium-row td:first-child { border-left: 4px solid #fd7e14; }
        .ticket-table tr.prio-low-row    td:first-child { border-left: 4px solid #28a745; }
        .ticket-table tr.prio-resolved-row td:first-child { border-left: 4px solid #00B4D8; }

        .ticket-id {
            font-family: monospace;
            font-size: 12px;
            font-weight: 600;
            color: #0047AB;
            background: #f0f5ff;
            padding: 2px 7px;
            border-radius: 4px;
            border: 1px solid #d0e0ff;
            white-space: nowrap;
        }

        .ticket-date { font-size: 12px; color: var(--text-muted); white-space: nowrap; }

        .customer-name { font-weight: 600; color: var(--text-primary); }

        .customer-phone {
            font-size: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .customer-phone:hover { color: #0047AB; }

        .badge {
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .badge.prio-high   { background-color: var(--clr-high-bg);   color: var(--clr-high); }
        .badge.prio-medium { background-color: var(--clr-medium-bg); color: var(--clr-medium); }
        .badge.prio-low    { background-color: var(--clr-low-bg);    color: var(--clr-low); }

        .issue-type {
            font-size: 12px;
            font-weight: 600;
            color: #333;
            background: #f8f9fa;
            padding: 2px 7px;
            border-radius: 5px;
            border: 1px solid #e9ecef;
            white-space: nowrap;
        }

        .delivery-pill {
            font-size: 11px;
            background: #f8f9fa;
            color: #555;
            border: 1px solid #e9ecef;
            padding: 2px 7px;
            border-radius: 5px;
            font-weight: 500;
            white-space: nowrap;
        }

        .desc-cell {
            font-size: 12px;
            color: #555;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dept-label {
            font-size: 12px;
            font-weight: 600;
            color: #0047AB;
        }

        .agent-id {
            font-family: monospace;
            background: #f0f5ff;
            padding: 1px 6px;
            border-radius: 4px;
            color: #0047AB;
            font-size: 12px;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-indicator.st-received { color: var(--clr-received); }
        .status-indicator.st-progress { color: var(--clr-progress); }
        .status-indicator.st-resolved { color: var(--clr-resolved); }

        .stage-select {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 600;
            padding: 5px 10px;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .stage-select:hover { border-color: #0047AB; background: #f0f5ff; }
        .stage-select.sel-received { border-color: rgba(253,126,20,0.5); color: var(--clr-received); }
        .stage-select.sel-progress { border-color: rgba(0,180,216,0.5);  color: var(--clr-progress); }
        .stage-select.sel-resolved { border-color: rgba(40,167,69,0.5);  color: var(--clr-resolved); }

        /* Empty / Error state */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background-color: white;
            border: 1px dashed #ddd;
            border-radius: 10px;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-state svg { width: 48px; height: 48px; color: #aaa; opacity: 0.5; }
        .empty-state-title { font-size: 16px; font-weight: 600; color: #555; }

        /* Toast Container */
        #sysToast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            background: white;
            color: #333;
            border: 1px solid #d0e0ff;
            border-left: 4px solid #0047AB;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            max-width: 350px;
            box-shadow: 0 8px 24px rgba(0,71,171,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: none;
        }

        #sysToast.show {
            transform: translateY(0);
            opacity: 1;
        }

        #sysToast .icon {
            width: 16px;
            height: 16px;
            color: #0047AB;
        }

        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #e9ecef 25%, #f5f7fa 50%, #e9ecef 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }

        .skeleton-text {
            height: 16px;
            width: 100%;
        }

        .skeleton-title {
            height: 20px;
            width: 60%;
            margin-bottom: 10px;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Quick-Action Buttons for Stage Transitions */
        .stage-btn-group {
            display: flex;
            gap: 6px;
        }

        .stage-btn {
            background: white;
            border: 1px solid #ddd;
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .stage-btn:hover {
            background: #f8f9fa;
            color: var(--text-primary);
        }

        .stage-btn.start-work {
            border-color: rgba(0, 180, 216, 0.4);
            color: var(--clr-progress);
        }
        .stage-btn.start-work:hover {
            background: var(--clr-progress-bg);
        }

        .stage-btn.resolve {
            border-color: rgba(40, 167, 69, 0.4);
            color: var(--clr-resolved);
        }
        .stage-btn.resolve:hover {
            background: var(--clr-resolved-bg);
        }

        .stage-btn.revert {
            border-color: rgba(253, 126, 20, 0.4);
            color: var(--clr-received);
        }
        .stage-btn.revert:hover {
            background: var(--clr-received-bg);
        }

        footer.site-footer {
            text-align: center;
            padding: 20px 30px;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            margin-top: auto;
            background: white;
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header>
        <div class="header-container">
            <div class="logo">SKY<span>KIN</span> Technologies</div>
            
            <div class="header-meta">
                <!-- Auto-Refresh Toggle -->
                <div class="refresh-control">
                    <input type="checkbox" id="chkAutoRefresh" checked onchange="toggleAutoRefresh()">
                    <label for="chkAutoRefresh" style="cursor:pointer;">Auto-Refresh</label>
                    <div class="refresh-circle">
                        <svg>
                            <circle class="bg-ring" cx="9" cy="9" r="8"></circle>
                            <circle class="progress-ring" id="refreshRing" cx="9" cy="9" r="8"></circle>
                        </svg>
                    </div>
                </div>
                
                <a href="/app/agent_dashboard/index.php" style="color: white; text-decoration: none; font-size: 13px; font-weight: 600; background: rgba(255,255,255,0.15); padding: 6px 16px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.3); transition: all 0.2s;">
                    Agent Dashboard
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN BODY -->
    <main>
        
        <!-- Department Tabs -->
        <div class="dept-tabs" id="deptTabs">
            <button class="dept-tab active" data-dept="All" onclick="selectDepartment('All')">All Departments</button>
            <button class="dept-tab" data-dept="Logistics" onclick="selectDepartment('Logistics')">Logistics</button>
            <button class="dept-tab" data-dept="Warehouse" onclick="selectDepartment('Warehouse')">Warehouse</button>
            <button class="dept-tab" data-dept="Billing" onclick="selectDepartment('Billing')">Billing</button>
            <button class="dept-tab" data-dept="Customer Relations" onclick="selectDepartment('Customer Relations')">Customer Relations</button>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <div class="search-wrapper">
                <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="txtSearch" class="search-input" placeholder="Search by customer, phone, order ID..." oninput="triggerSearch()">
            </div>

            <div class="filter-group">
                <label for="selPriority">Priority</label>
                <select id="selPriority" class="filter-select" onchange="fetchTickets()">
                    <option value="All">All Priorities</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="selStatus">Stage</label>
                <select id="selStatus" class="filter-select" onchange="fetchTickets()">
                    <option value="All">All Stages</option>
                    <option value="Received">Received</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Resolved">Resolved</option>
                </select>
            </div>

            <!-- Stats badges -->
            <div class="stats-summary" id="statsSummary">
                <div class="stat-badge received"><span class="dot"></span>Received: <span class="val" id="statRec">0</span></div>
                <div class="stat-badge progress"><span class="dot"></span>In Progress: <span class="val" id="statProg">0</span></div>
                <div class="stat-badge resolved"><span class="dot"></span>Resolved: <span class="val" id="statRes">0</span></div>
            </div>
        </div>

        <!-- Ticket Table -->
        <div class="ticket-table-wrap" id="ticketGrid">
            <table class="ticket-table">
                <thead>
                    <tr>
                        <th>Ticket ID</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Issue Type</th>
                        <th>Priority</th>
                        <th>Delivery Date</th>
                        <th>Description</th>
                        <th>Department</th>
                        <th>Agent</th>
                        <th>Stage</th>
                    </tr>
                </thead>
                <tbody id="ticketTableBody">
                    <tr>
                        <td colspan="11" style="text-align:center;padding:30px;color:#aaa;">
                            <div class="skeleton skeleton-text" style="height:14px;width:60%;margin:0 auto 8px;"></div>
                            <div class="skeleton skeleton-text" style="height:14px;width:80%;margin:0 auto 8px;"></div>
                            <div class="skeleton skeleton-text" style="height:14px;width:70%;margin:0 auto;"></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </main>

    <!-- FOOTER -->
    <footer class="site-footer">
        SkyKin Technologies &copy; <?php echo date('Y'); ?> | Department Ticket Portal (Standalone)
    </footer>

    <!-- TOAST POPUP -->
    <div id="sysToast">
        <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span id="toastMessage">Stage updated successfully!</span>
    </div>

    <!-- JS LOGIC -->
    <script>
        let currentDept = 'All';
        let refreshInterval = null;
        const countdownTime = 10; // 10 seconds refresh duration
        let countdownVal = countdownTime;
        let searchDebounceTimer = null;

        // Parse deep-linked department from URL
        function initFromUrl() {
            const params = new URLSearchParams(window.location.search);
            const deptParam = params.get('department') || params.get('dept');
            if (deptParam) {
                const normalized = ['Logistics', 'Warehouse', 'Billing', 'Customer Relations'].find(
                    d => d.toLowerCase() === deptParam.toLowerCase()
                );
                if (normalized) {
                    currentDept = normalized;
                    // Update active tab class
                    document.querySelectorAll('.dept-tab').forEach(btn => {
                        btn.classList.toggle('active', btn.getAttribute('data-dept') === normalized);
                    });
                }
            }
            fetchTickets();
            startAutoRefreshTimer();
        }

        // Select a department and update URL/view
        function selectDepartment(dept) {
            currentDept = dept;
            document.querySelectorAll('.dept-tab').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-dept') === dept);
            });
            
            // Update URL without full page reload
            const url = new URL(window.location);
            if (dept === 'All') {
                url.searchParams.delete('department');
            } else {
                url.searchParams.set('department', dept);
            }
            window.history.pushState({}, '', url);
            
            fetchTickets();
            resetCountdown();
        }

        // Debounced search trigger
        function triggerSearch() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                fetchTickets();
            }, 300);
        }

        // Main Fetch logic
        function fetchTickets() {
            const status = document.getElementById('selStatus').value;
            const priority = document.getElementById('selPriority').value;
            const search = document.getElementById('txtSearch').value.trim();

            const query = new URLSearchParams({
                action: 'get_tickets',
                department: currentDept,
                status: status,
                priority: priority,
                search: search
            });

            fetch(`${SELF_URL}?${query.toString()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.ok) {
                        updateStats(data.stats);
                        renderRows(data.records);
                    } else {
                        renderError(data.error || 'Failed to fetch tickets');
                    }
                })
                .catch(err => {
                    renderError(err.message || 'Network error occurred');
                });
        }

        function updateStats(stats) {
            document.getElementById('statRec').textContent = stats.Received || 0;
            document.getElementById('statProg').textContent = stats['In Progress'] || 0;
            document.getElementById('statRes').textContent = stats.Resolved || 0;
        }

        function renderRows(records) {
            const wrap = document.getElementById('ticketGrid');
            const tbody = document.getElementById('ticketTableBody');

            if (!records || records.length === 0) {
                wrap.outerHTML = `
                    <div id="ticketGrid" class="ticket-table-wrap">
                        <table class="ticket-table">
                            <thead><tr>
                                <th>Ticket ID</th><th>Date</th><th>Customer</th><th>Phone</th>
                                <th>Issue Type</th><th>Priority</th><th>Delivery Date</th>
                                <th>Description</th><th>Department</th><th>Agent</th><th>Stage</th>
                            </tr></thead>
                            <tbody><tr><td colspan="11" style="text-align:center;padding:40px;">
                                <div class="empty-state" style="border:none;box-shadow:none;padding:20px 0;">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                                    </svg>
                                    <div class="empty-state-title">No Tickets Found</div>
                                    <p style="font-size:13px; color:var(--text-muted);">There are no tickets matching your current active filters.</p>
                                </div>
                            </td></tr></tbody>
                        </table>
                    </div>`;
                return;
            }

            const priorityPills = { 'High': 'prio-high', 'Medium': 'prio-medium', 'Low': 'prio-low' };

            // Ensure the table wrapper exists (could have been replaced by empty state)
            if (!document.getElementById('ticketTableBody')) {
                wrap.innerHTML = `
                    <table class="ticket-table">
                        <thead><tr>
                            <th>Ticket ID</th><th>Date</th><th>Customer</th><th>Phone</th>
                            <th>Issue Type</th><th>Priority</th><th>Delivery Date</th>
                            <th>Description</th><th>Department</th><th>Agent</th><th>Stage</th>
                        </tr></thead>
                        <tbody id="ticketTableBody"></tbody>
                    </table>`;
            }

            const tbodyEl = document.getElementById('ticketTableBody');
            tbodyEl.innerHTML = records.map(r => {
                const displayStage = (r.status === 'Open') ? 'Received' : r.status;
                const statusColorClass = {
                    'Received': 'st-received', 'Open': 'st-received',
                    'In Progress': 'st-progress', 'Resolved': 'st-resolved'
                }[r.status] || 'st-received';
                const selBorderClass = {
                    'Received': 'sel-received', 'Open': 'sel-received',
                    'In Progress': 'sel-progress', 'Resolved': 'sel-resolved'
                }[r.status] || 'sel-received';
                const priorityClass = priorityPills[r.priority] || 'prio-medium';
                const rowClass = (r.status === 'Resolved')
                    ? 'prio-resolved-row'
                    : ({ 'High': 'prio-high-row', 'Medium': 'prio-medium-row', 'Low': 'prio-low-row' }[r.priority] || 'prio-medium-row');

                return `
                <tr class="${rowClass}" id="ticket-row-${r.case_id}">
                    <td><span class="ticket-id">#${r.case_id}</span></td>
                    <td><span class="ticket-date">${r.formatted_date || ''}</span></td>
                    <td><span class="customer-name">${escapeHtml(r.customer_name)}</span></td>
                    <td>
                        <a href="tel:${r.customer_phone}" class="customer-phone">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                            ${escapeHtml(r.customer_phone)}
                        </a>
                    </td>
                    <td><span class="issue-type">${escapeHtml(r.issue_type)}</span></td>
                    <td><span class="badge ${priorityClass}">${r.priority || 'Medium'}</span></td>
                    <td>${r.delivery_date ? `<span class="delivery-pill">${r.delivery_date}</span>` : '<span style="color:#ccc;">—</span>'}</td>
                    <td><span class="desc-cell" title="${escapeHtml(r.description)}">${escapeHtml(r.description)}</span></td>
                    <td><span class="dept-label">${escapeHtml(r.department)}</span></td>
                    <td><span class="agent-id">${escapeHtml(r.agent_id)}</span></td>
                    <td>
                        <select class="stage-select ${selBorderClass}" onchange="updateStage(${r.case_id}, this.value)">
                            <option value="Received" ${(r.status === 'Received' || r.status === 'Open') ? 'selected' : ''}>Received</option>
                            <option value="In Progress" ${r.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                            <option value="Resolved" ${r.status === 'Resolved' ? 'selected' : ''}>Resolved</option>
                        </select>
                    </td>
                </tr>`;
            }).join('');
        }

        function renderError(msg) {
            const wrap = document.getElementById('ticketGrid');
            wrap.innerHTML = `
                <div style="padding:20px;">
                    <div class="empty-state" style="border-color:#ef4444;">
                        <svg fill="none" stroke="#ef4444" viewBox="0 0 24 24" style="color:#ef4444;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <div class="empty-state-title" style="color:#f87171;">Query Error</div>
                        <p style="font-size:13px; color:var(--text-muted);">${escapeHtml(msg)}</p>
                    </div>
                </div>`;
        }

        // Update Stage API Call
        function updateStage(caseId, newStage) {
            fetch(`${SELF_URL}?action=update_stage`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ case_id: caseId, stage: newStage })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    showToast(`Ticket #${caseId} stage updated to ${newStage}`);
                    fetchTickets();
                } else {
                    alert('Error: ' + (data.error || 'Failed to update stage'));
                }
            })
            .catch(err => {
                alert('Connection error: ' + err.message);
            });
        }

        // Toast handling
        let toastTimer = null;
        function showToast(msg) {
            const toast = document.getElementById('sysToast');
            const messageEl = document.getElementById('toastMessage');
            messageEl.textContent = msg;
            
            toast.classList.add('show');
            clearTimeout(toastTimer);
            
            toastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Auto Refresh logic
        function startAutoRefreshTimer() {
            const ring = document.getElementById('refreshRing');
            const radius = ring.r.baseVal.value;
            const circumference = 2 * Math.PI * radius;
            
            ring.style.strokeDasharray = circumference;
            
            if (refreshInterval) clearInterval(refreshInterval);
            
            refreshInterval = setInterval(() => {
                if (!document.getElementById('chkAutoRefresh').checked) {
                    ring.style.strokeDashoffset = circumference;
                    return;
                }
                
                countdownVal -= 0.1;
                
                if (countdownVal <= 0) {
                    fetchTickets();
                    countdownVal = countdownTime;
                }
                
                const percent = countdownVal / countdownTime;
                const offset = circumference - (percent * circumference);
                ring.style.strokeDashoffset = offset;
                
            }, 100);
        }

        function toggleAutoRefresh() {
            const isChecked = document.getElementById('chkAutoRefresh').checked;
            if (isChecked) {
                resetCountdown();
            } else {
                const ring = document.getElementById('refreshRing');
                const radius = ring.r.baseVal.value;
                const circumference = 2 * Math.PI * radius;
                ring.style.strokeDashoffset = circumference;
            }
        }

        function resetCountdown() {
            countdownVal = countdownTime;
        }

        // Helper to escape HTML characters
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Initialize on load
        window.addEventListener('DOMContentLoaded', initFromUrl);
    </script>
</body>
</html>
