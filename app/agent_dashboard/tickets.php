<?php
// SkyKin Technologies – Department Tickets Dashboard
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

$is_api = isset($_GET['action']);
skykin_require_groups(['superadmin', 'admin', 'supervisor'], $is_api);

$logged_in_user = $_SESSION['username'] ?? 'User';
$logged_in_domain = skykin_default_domain();
$domain = skykin_domain_param($_GET['domain'] ?? null);
$today = date('Y-m-d');

function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

// ── API: Update Ticket Status ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0);
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $case_id = (int)($body['case_id'] ?? 0);
    $status = $body['status'] ?? '';
    
    if (!$case_id || !in_array($status, ['Open', 'Received', 'In Progress', 'Resolved'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    try {
        $db = getDB();
        $s = $db->prepare("UPDATE skykin_cases SET status = :status WHERE case_id = :id");
        $s->execute([':status' => $status, ':id' => $case_id]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API: List Tickets ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'list_tickets') {
    error_reporting(0);
    header('Content-Type: application/json');
    $dept = $_GET['department'] ?? 'All';
    $status = $_GET['status'] ?? 'All';
    $from = $_GET['from'] ?? date('Y-m-d');
    $to = $_GET['to'] ?? date('Y-m-d');
    
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

        $where = $isSQLite
            ? "date(created_at) >= :df AND date(created_at) <= :dt"
            : "DATE(created_at) >= :df AND DATE(created_at) <= :dt";
        $params = [':df' => $from, ':dt' => $to];
        
        if ($dept !== 'All') {
            $where .= " AND department = :dept";
            $params[':dept'] = $dept;
        }
        if ($status !== 'All') {
            $where .= " AND status = :status";
            $params[':status'] = $status;
        }

        $dateFmt = $isSQLite
            ? "strftime('%Y-%m-%d %H:%M', created_at)"
            : "to_char(created_at,'YYYY-MM-DD HH24:MI')";

        $s = $db->prepare("SELECT case_id, {$dateFmt} as formatted_date,
            customer_name, customer_phone, order_id, issue_type, description, delivery_date, department, agent_id, priority, status
            FROM skykin_cases
            WHERE $where
            ORDER BY created_at DESC LIMIT 500");
        $s->execute($params);
        echo json_encode(['records' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['records' => [], 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Tickets Dashboard - SkyKin</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; }

/* Header */
.header {
    background: linear-gradient(135deg, #0047AB 0%, #00B4D8 100%);
    color: white; padding: 0 24px; height: 64px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    position: fixed; top: 0; left: 0; right: 0; z-index: 300;
}
.header .logo { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
.header .logo span { color: #00e5ff; }
.header-right { display: flex; align-items: center; gap: 20px; font-size: 14px; }
.header-right a { color: white; text-decoration: none; font-weight: 600; background: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 20px; transition: background 0.2s; }
.header-right a:hover { background: rgba(255,255,255,0.3); }

/* Main Layout */
.main { margin-top: 64px; padding: 24px; max-width: 1400px; margin-left: auto; margin-right: auto; }

.page-title { font-size: 20px; font-weight: bold; color: #0047AB; margin-bottom: 20px; }

/* Stats grid */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.card {
    background: white; border-radius: 10px; padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #0047AB;
}
.card.amber { border-left-color: #fd7e14; }
.card.teal { border-left-color: #00B4D8; }
.card.green { border-left-color: #28a745; }
.card-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.card-value { font-size: 28px; font-weight: bold; color: #0047AB; }
.card.amber .card-value { color: #ea580c; }
.card.teal .card-value { color: #0284c7; }
.card.green .card-value { color: #059669; }

/* Filter box */
.filter-box {
    background: white; border-radius: 10px; padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 24px;
    display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;
}
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-group label { font-size: 12px; font-weight: bold; color: #666; }
.filter-group select, .filter-group input {
    border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px;
    font-size: 13px; outline: none; background: #fff; color: #333; min-width: 160px;
}
.filter-group select:focus, .filter-group input:focus { border-color: #0047AB; }
.btn-action {
    background: #0047AB; color: white; border: none; padding: 9px 24px;
    border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: bold; transition: background 0.15s;
}
.btn-action:hover { background: #003a8c; }
.btn-clear {
    background: #e9ecef; color: #555; border: none; padding: 9px 18px;
    border-radius: 6px; cursor: pointer; font-size: 13px; transition: background 0.15s;
}
.btn-clear:hover { background: #dde1e5; }

/* Tickets Table Container */
.table-container {
    background: white; border-radius: 10px; padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th {
    background: #f8f9fa; padding: 12px; text-align: left;
    font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 0.5px;
    border-bottom: 2px solid #e9ecef;
}
.data-table td { padding: 12px; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
.data-table tr:hover td { background: #f8fbff; }

/* Badges */
.badge {
    padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;
    display: inline-block; text-align: center;
}
.badge-issue { background: #ffebee; color: #c62828; }

/* Actions */
.btn-status-change {
    background: #0284c7; color: white; border: none; padding: 4px 10px;
    border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;
    margin-right: 4px; transition: background 0.15s;
}
.btn-status-change:hover { background: #0369a1; }
.btn-status-change.resolve { background: #059669; }
.btn-status-change.resolve:hover { background: #047857; }

/* Toast */
#sysToast {
    position: fixed; bottom: 24px; right: 24px; z-index: 2000;
    background: #1e293b; color: #f1f5f9; padding: 12px 18px;
    border-radius: 10px; font-size: 13px; max-width: 320px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3); display: none;
}

.footer { text-align: center; font-size: 11px; color: #aaa; padding: 24px; }
.footer a { color: #888; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="logo">SKY<span>KIN</span> Technologies</div>
    <div class="header-right">
        <span>Logged in: <strong><?php echo htmlspecialchars($logged_in_user); ?></strong></span>
        <a href="/app/agent_dashboard/index.php">Back to Dashboard</a>
    </div>
</div>

<!-- MAIN -->
<div class="main">
    <div class="page-title">Department Ticket Management Dashboard</div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="card">
            <div class="card-label">Total Tickets</div>
            <div class="card-value" id="statTotal">0</div>
        </div>
        <div class="card amber">
            <div class="card-label">Received/Open Tickets</div>
            <div class="card-value" id="statOpen">0</div>
        </div>
        <div class="card teal">
            <div class="card-label">In Progress</div>
            <div class="card-value" id="statProgress">0</div>
        </div>
        <div class="card green">
            <div class="card-label">Resolved</div>
            <div class="card-value" id="statResolved">0</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-box">
        <div class="filter-group">
            <label>Assigned Department</label>
            <select id="filterDept">
                <option value="All" selected>All Departments</option>
                <option value="Logistics">Logistics</option>
                <option value="Warehouse">Warehouse</option>
                <option value="Billing">Billing</option>
                <option value="Customer Relations">Customer Relations</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Ticket Status</label>
            <select id="filterStatus">
                <option value="All" selected>All Statuses</option>
                <option value="Open">Open</option>
                <option value="Received">Received</option>
                <option value="In Progress">In Progress</option>
                <option value="Resolved">Resolved</option>
            </select>
        </div>
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" id="filterFrom" value="<?php echo $today; ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" id="filterTo" value="<?php echo $today; ?>">
        </div>
        <button class="btn-action" onclick="fetchTickets()">Apply Filters</button>
        <button class="btn-clear" onclick="clearFilters()">Today</button>
    </div>

    <!-- Table -->
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Date &amp; Time</th>
                    <th>Customer Details</th>
                    <th style="width: 100px;">Order ID</th>
                    <th>Issue details</th>
                    <th style="width: 80px;">Priority</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 130px;">Dept</th>
                    <th style="width: 80px;">Agent</th>
                    <th style="width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody id="ticketsBody">
                <tr><td colspan="9" style="text-align:center;color:#aaa;padding:30px;">Loading tickets...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="footer">
    SkyKin Technologies &copy; <?php echo date('Y'); ?> | Department Tickets Dashboard
</div>

<!-- Toast -->
<div id="sysToast"></div>

<script>
let toastTimer = null;
function showToast(msg) {
    const t = document.getElementById('sysToast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, 4000);
}

function clearFilters() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('filterFrom').value = today;
    document.getElementById('filterTo').value = today;
    document.getElementById('filterDept').value = 'All';
    document.getElementById('filterStatus').value = 'All';
    fetchTickets();
}

function fetchTickets() {
    const dept = document.getElementById('filterDept').value;
    const status = document.getElementById('filterStatus').value;
    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    
    fetch(`tickets.php?action=list_tickets&department=${encodeURIComponent(dept)}&status=${encodeURIComponent(status)}&from=${from}&to=${to}`)
        .then(r => r.json())
        .then(d => {
            const rows = d.records || [];
            updateStats(rows);
            renderTable(rows);
        })
        .catch(err => {
            document.getElementById('ticketsBody').innerHTML = 
                `<tr><td colspan="9" style="text-align:center;color:#dc3545;padding:30px;">Error loading tickets: ${err.message}</td></tr>`;
        });
}

function updateStats(rows) {
    let total = rows.length;
    let open = 0, progress = 0, resolved = 0;
    rows.forEach(r => {
        if (r.status === 'Open' || r.status === 'Received') open++;
        else if (r.status === 'In Progress') progress++;
        else if (r.status === 'Resolved') resolved++;
    });
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statOpen').textContent = open;
    document.getElementById('statProgress').textContent = progress;
    document.getElementById('statResolved').textContent = resolved;
}

function renderTable(rows) {
    const body = document.getElementById('ticketsBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#aaa;padding:30px;">No tickets found matching current filters.</td></tr>';
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

    body.innerHTML = rows.map(r => {
        const sStyle = statusColors[r.status] || 'background: #f3f4f6; color: #4b5563;';
        const pStyle = priorityColors[r.priority] || 'background: #f3f4f6; color: #4b5563;';
        
        let actionHtml = '';
        if (r.status === 'Open' || r.status === 'Received') {
            actionHtml = `
                <button class="btn-status-change" onclick="updateStatus(${r.case_id}, 'In Progress')">Start Work</button>
                <button class="btn-status-change resolve" onclick="updateStatus(${r.case_id}, 'Resolved')">Resolve</button>
            `;
        } else if (r.status === 'In Progress') {
            actionHtml = `
                <button class="btn-status-change resolve" onclick="updateStatus(${r.case_id}, 'Resolved')">Resolve</button>
            `;
        } else {
            actionHtml = '<span style="color: #059669; font-weight: bold;">&#x2713; Resolved</span>';
        }

        return `
            <tr>
                <td>${r.formatted_date}</td>
                <td>
                    <strong>${r.customer_name}</strong><br>
                    <span style="color: #666; font-size:11px;">${r.customer_phone}</span>
                </td>
                <td><code>${r.order_id || '-'}</code></td>
                <td>
                    <span class="badge badge-issue">${r.issue_type}</span><br>
                    <div style="margin-top: 6px; white-space: normal; word-break: break-word; color: #555; max-width: 300px;">
                        ${r.description}
                    </div>
                    ${r.delivery_date ? `<div style="margin-top: 4px; font-size: 11px; color: #888;">Delivery Date: ${r.delivery_date}</div>` : ''}
                </td>
                <td><span class="badge" style="${pStyle}">${r.priority || 'Medium'}</span></td>
                <td><span class="badge" style="${sStyle}">${r.status || 'Open'}</span></td>
                <td><span class="badge badge-transfer">${r.department}</span></td>
                <td><code>${r.agent_id}</code></td>
                <td>${actionHtml}</td>
            </tr>
        `;
    }).join('');
}

function updateStatus(caseId, status) {
    fetch('tickets.php?action=update_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ case_id: caseId, status: status })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast(`Ticket status updated to ${status}`);
            fetchTickets();
        } else {
            alert('Failed to update status: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Error updating status: ' + err.message);
    });
}

// Initial fetch
fetchTickets();
</script>
</body>
</html>
