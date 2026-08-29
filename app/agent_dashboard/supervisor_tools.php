<?php
// Embeddable agent tools for supervisor dashboard (lookup, tickets, callbacks)
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

skykin_require_groups(['superadmin', 'admin', 'supervisor'], false);

$tool   = $_GET['tool'] ?? 'lookup';
$domain = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$today  = date('Y-m-d');
$agent_label = $_SESSION['username'] ?? 'supervisor';
$allowed = ['lookup', 'callbacks', 'ticket'];
if (!in_array($tool, $allowed, true)) {
    $tool = 'lookup';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sky Connect – <?php echo htmlspecialchars(ucfirst($tool)); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f6f8fb;color:#333;font-size:14px;padding:16px}
.section-box{background:#fff;border:1px solid #e8edf3;border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 1px 4px rgba(15,23,42,.04)}
.section-title{font-size:13px;font-weight:700;color:#333;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #eee;font-size:11px;text-transform:uppercase}
.data-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.data-table tr:hover td{background:#fafbff}
.btn-filter{background:#0047AB;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600}
.btn-filter:hover{background:#003380}
.btn-filter-clear{background:#f0f2f5;color:#555;border:1px solid #ddd;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:13px}
.date-filter{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.date-filter label{font-size:12px;color:#666;font-weight:600}
.date-filter input[type=date]{padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:12px}
.lookup-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:16px}
.profile-title{font-size:18px;font-weight:700;color:#0047AB;margin-bottom:8px}
.profile-item{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f0f0f0}
.profile-item span:first-child{color:#888}
.form-group{margin-bottom:12px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-family:inherit;font-size:13px}
.ticket-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rec-empty{text-align:center;color:#aaa;padding:20px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.btn-action-resolve{background:#e8f5e9;color:#2e7d32;border:none;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:20px;width:min(420px,92vw);box-shadow:0 8px 32px rgba(0,0,0,.2)}
.modal-box h3{margin:0 0 14px;font-size:16px}
.modal-close{float:right;background:none;border:none;font-size:22px;cursor:pointer;color:#888}
@media(max-width:900px){.lookup-grid,.ticket-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php if ($tool === 'lookup'): ?>
<div class="section-box" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <label style="font-weight:700;font-size:13px;color:#555">Manual Lookup:</label>
    <input type="text" id="lookupQuery" placeholder="Phone number or Order ID..." style="flex:1;min-width:200px;padding:8px 12px;border:1px solid #ddd;border-radius:8px">
    <button class="btn-filter" type="button" onclick="performLookup()">Search</button>
    <button class="btn-filter-clear" type="button" onclick="clearLookup()">Clear</button>
</div>
<div class="lookup-grid">
    <div>
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#0047AB"></span> Customer Profile</div>
            <div id="lookupProfileBox"><p style="color:#888;font-style:italic;font-size:13px">No customer looked up yet.</p></div>
        </div>
        <div class="section-box" id="inTransitBox" style="display:none">
            <div class="section-title"><span class="dot" style="background:#fd7e14"></span> In-Transit Delivery</div>
            <div id="inTransitDetails"></div>
        </div>
    </div>
    <div>
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#28a745"></span> Delivery History</div>
            <table class="data-table">
                <thead><tr><th>Order ID</th><th>Date</th><th>Address</th><th>Status</th></tr></thead>
                <tbody id="lookupDeliveryBody"><tr><td colspan="4" class="rec-empty">No deliveries found.</td></tr></tbody>
            </table>
        </div>
        <div class="section-box">
            <div class="section-title"><span class="dot" style="background:#dc3545"></span> Past Tickets</div>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Issue</th><th>Priority</th><th>Status</th><th>Description</th></tr></thead>
                <tbody id="lookupTicketsBody"><tr><td colspan="5" class="rec-empty">No tickets found.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tool === 'callbacks'): ?>
<div class="section-box">
    <div class="section-title">
        <span class="dot" style="background:#ffc107"></span> Scheduled Callbacks
        <button class="btn-filter" type="button" onclick="openCallbackModal()" style="margin-left:auto;font-size:12px;padding:6px 12px">+ New Callback</button>
    </div>
    <table class="data-table">
        <thead><tr><th>Scheduled</th><th>Customer</th><th>Phone</th><th>Agent</th><th>Notes</th><th>Status</th><th></th></tr></thead>
        <tbody id="callbacksBody"><tr><td colspan="7" class="rec-empty">Loading...</td></tr></tbody>
    </table>
</div>
<div id="callbackModal" class="modal-overlay">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeCallbackModal()">&times;</button>
        <h3>Schedule Callback</h3>
        <div class="form-group"><label>Phone *</label><input type="text" id="cbPhone"></div>
        <div class="form-group"><label>Name</label><input type="text" id="cbName"></div>
        <div class="form-group"><label>Date *</label><input type="date" id="cbDate"></div>
        <div class="form-group"><label>Time *</label><input type="time" id="cbTime"></div>
        <div class="form-group"><label>Notes</label><textarea id="cbNotes" rows="3"></textarea></div>
        <button class="btn-filter" type="button" onclick="submitCallback()" style="width:100%;margin-top:8px">Schedule</button>
    </div>
</div>

<?php else: ?>
<div class="ticket-grid">
    <div class="section-box">
        <div class="section-title"><span class="dot" style="background:#dc3545"></span> New Ticket</div>
        <form id="ticketForm" onsubmit="submitTicket(event)">
            <div class="form-group"><label>Customer Name</label><input type="text" id="caseCustomerName" required></div>
            <div class="form-group"><label>Customer Phone</label><input type="text" id="caseCustomerPhone" required></div>
            <div class="form-group"><label>Order ID</label><input type="text" id="caseOrderId"></div>
            <div class="form-group"><label>Issue Type</label>
                <select id="caseIssueType" onchange="autoAssignDepartment(this.value)">
                    <option>Not delivered</option><option>Wrong item</option><option>Damaged package</option>
                    <option>Late delivery</option><option>Billing issue</option><option>Other</option>
                </select>
            </div>
            <div class="form-group"><label>Priority</label>
                <select id="casePriority"><option>Low</option><option selected>Medium</option><option>High</option></select>
            </div>
            <div class="form-group"><label>Delivery Date</label><input type="date" id="caseDeliveryDate" value="<?php echo $today; ?>" required></div>
            <div class="form-group"><label>Department</label>
                <select id="caseDepartment"><option>Logistics</option><option>Warehouse</option><option>Billing</option><option>Customer Relations</option></select>
            </div>
            <div class="form-group"><label>Description</label><textarea id="caseDescription" rows="3"></textarea></div>
            <button class="btn-filter" type="submit" style="width:100%">Submit Ticket</button>
        </form>
    </div>
    <div class="section-box">
        <div class="section-title"><span class="dot" style="background:#0047AB"></span> Submitted Tickets</div>
        <div class="date-filter">
            <label>From</label><input type="date" id="caseFilterFrom" value="<?php echo $today; ?>">
            <label>To</label><input type="date" id="caseFilterTo" value="<?php echo $today; ?>">
            <button class="btn-filter" type="button" onclick="fetchTickets()">Filter</button>
            <span id="caseCount" style="font-size:12px;color:#888"></span>
        </div>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Customer</th><th>Phone</th><th>Order</th><th>Issue</th><th>Priority</th><th>Status</th><th>Dept</th><th>Agent</th></tr></thead>
            <tbody id="caseHistoryBody"><tr><td colspan="9" class="rec-empty">No tickets found.</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
const API = '/app/agent_dashboard/index.php';
const AGENT_ID = <?php echo json_encode($agent_label); ?>;
const TOOL = <?php echo json_encode($tool); ?>;

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
}

<?php if ($tool === 'lookup'): ?>
function performLookup() {
    const query = document.getElementById('lookupQuery').value.trim();
    if (!query) return;
    document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#666">Loading...</p>';
    fetch(API + '?action=lookup_customer&query=' + encodeURIComponent(query), {credentials:'same-origin'})
        .then(r => r.json())
        .then(data => {
            const c = data.contact;
            if (c) {
                document.getElementById('lookupProfileBox').innerHTML =
                    '<div class="profile-title">' + esc(c.full_name) + '</div>' +
                    '<div class="profile-item"><span>Phone:</span><span>' + esc(c.phone) + '</span></div>' +
                    '<div class="profile-item"><span>Email:</span><span>' + esc(c.email || '-') + '</span></div>' +
                    '<div class="profile-item"><span>Company:</span><span>' + esc(c.company || '-') + '</span></div>' +
                    '<div class="profile-item"><span>Language:</span><span>' + esc(c.language || 'English') + '</span></div>' +
                    '<div style="font-size:12px;margin-top:8px;color:#555"><strong>Notes:</strong><br>' + esc(c.notes || 'None') + '</div>';
            } else {
                document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#888;font-style:italic">No profile found.</p>';
            }
            const inTransit = data.current_intransit;
            document.getElementById('inTransitBox').style.display = inTransit ? 'block' : 'none';
            if (inTransit) {
                document.getElementById('inTransitDetails').innerHTML =
                    '<div><strong>Order:</strong> ' + esc(inTransit.order_id) + '</div>' +
                    '<div><strong>Address:</strong> ' + esc(inTransit.delivery_address) + '</div>' +
                    '<div><strong>Status:</strong> ' + esc(inTransit.status) + '</div>';
            }
            const dels = data.deliveries || [];
            document.getElementById('lookupDeliveryBody').innerHTML = dels.length
                ? dels.map(d => '<tr><td>' + esc(d.order_id) + '</td><td>' + esc(d.delivery_date) + '</td><td>' + esc(d.delivery_address) + '</td><td>' + esc(d.status) + '</td></tr>').join('')
                : '<tr><td colspan="4" class="rec-empty">No deliveries found.</td></tr>';
            const tix = data.tickets || [];
            document.getElementById('lookupTicketsBody').innerHTML = tix.length
                ? tix.map(t => '<tr><td>' + esc(t.formatted_date) + '</td><td>' + esc(t.issue_type) + '</td><td>' + esc(t.priority) + '</td><td>' + esc(t.status) + '</td><td>' + esc(t.description) + '</td></tr>').join('')
                : '<tr><td colspan="5" class="rec-empty">No tickets found.</td></tr>';
        });
}
function clearLookup() {
    document.getElementById('lookupQuery').value = '';
    document.getElementById('lookupProfileBox').innerHTML = '<p style="color:#888;font-style:italic">No customer looked up yet.</p>';
    document.getElementById('inTransitBox').style.display = 'none';
    document.getElementById('lookupDeliveryBody').innerHTML = '<tr><td colspan="4" class="rec-empty">No deliveries found.</td></tr>';
    document.getElementById('lookupTicketsBody').innerHTML = '<tr><td colspan="5" class="rec-empty">No tickets found.</td></tr>';
}
document.getElementById('lookupQuery').addEventListener('keydown', e => { if (e.key === 'Enter') performLookup(); });

<?php elseif ($tool === 'callbacks'): ?>
function fetchCallbacks() {
    fetch(API + '?action=list_callbacks&agent_id=all', {credentials:'same-origin'})
        .then(r => r.json())
        .then(data => {
            const list = data.records || [];
            if (!list.length) {
                document.getElementById('callbacksBody').innerHTML = '<tr><td colspan="7" class="rec-empty">No scheduled callbacks.</td></tr>';
                return;
            }
            document.getElementById('callbacksBody').innerHTML = list.map(c =>
                '<tr><td>' + esc(c.formatted_time) + '</td><td>' + esc(c.customer_name) + '</td><td>' + esc(c.customer_phone) + '</td><td>' + esc(c.agent_id) + '</td><td>' + esc(c.notes) + '</td><td>' + esc(c.status) + '</td><td><button class="btn-action-resolve" type="button" onclick="completeCallback(' + c.callback_id + ')">Complete</button></td></tr>'
            ).join('');
        });
}
function openCallbackModal(phone, name) {
    const now = new Date();
    document.getElementById('cbPhone').value = phone || '';
    document.getElementById('cbName').value = name || '';
    document.getElementById('cbDate').value = now.toISOString().slice(0, 10);
    now.setHours(now.getHours() + 1);
    document.getElementById('cbTime').value = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    document.getElementById('cbNotes').value = '';
    document.getElementById('callbackModal').classList.add('show');
}
function closeCallbackModal() { document.getElementById('callbackModal').classList.remove('show'); }
function submitCallback() {
    const customer_phone = document.getElementById('cbPhone').value.trim();
    const cbDate = document.getElementById('cbDate').value;
    const cbTime = document.getElementById('cbTime').value;
    if (!customer_phone || !cbDate || !cbTime) { alert('Phone, date and time required.'); return; }
    fetch(API + '?action=save_callback', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            customer_name: document.getElementById('cbName').value.trim(),
            customer_phone,
            callback_time: cbDate + ' ' + cbTime + ':00',
            notes: document.getElementById('cbNotes').value.trim(),
            agent_id: AGENT_ID
        })
    }).then(r => r.json()).then(d => {
        if (d.ok) { closeCallbackModal(); fetchCallbacks(); }
        else alert(d.error || 'Failed');
    });
}
function completeCallback(id) {
    if (!confirm('Mark callback completed?')) return;
    fetch(API + '?action=update_callback_status', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({callback_id: id, status: 'Completed'})
    }).then(r => r.json()).then(d => { if (d.ok) fetchCallbacks(); });
}
fetchCallbacks();

<?php else: ?>
function autoAssignDepartment(issueType) {
    const map = {'Not delivered':'Logistics','Late delivery':'Logistics','Wrong item':'Warehouse','Damaged package':'Warehouse','Billing issue':'Billing','Other':'Customer Relations'};
    document.getElementById('caseDepartment').value = map[issueType] || 'Customer Relations';
}
function fetchTickets() {
    const from = document.getElementById('caseFilterFrom').value;
    const to = document.getElementById('caseFilterTo').value;
    fetch(API + '?action=case_history&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
            const rows = d.records || [];
            document.getElementById('caseCount').textContent = rows.length + ' record(s)';
            if (!rows.length) {
                document.getElementById('caseHistoryBody').innerHTML = '<tr><td colspan="9" class="rec-empty">No tickets found.</td></tr>';
                return;
            }
            document.getElementById('caseHistoryBody').innerHTML = rows.map(t =>
                '<tr><td>' + esc(t.formatted_date) + '</td><td>' + esc(t.customer_name) + '</td><td>' + esc(t.customer_phone) + '</td><td>' + esc(t.order_id) + '</td><td>' + esc(t.issue_type) + '</td><td>' + esc(t.priority) + '</td><td>' + esc(t.status) + '</td><td>' + esc(t.department) + '</td><td>' + esc(t.agent_id) + '</td></tr>'
            ).join('');
        });
}
function submitTicket(e) {
    e.preventDefault();
    fetch(API + '?action=save_case', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            customer_name: document.getElementById('caseCustomerName').value.trim(),
            customer_phone: document.getElementById('caseCustomerPhone').value.trim(),
            order_id: document.getElementById('caseOrderId').value.trim(),
            issue_type: document.getElementById('caseIssueType').value,
            priority: document.getElementById('casePriority').value,
            delivery_date: document.getElementById('caseDeliveryDate').value,
            department: document.getElementById('caseDepartment').value,
            description: document.getElementById('caseDescription').value.trim(),
            agent_id: AGENT_ID
        })
    }).then(r => r.json()).then(d => {
        if (d.saved) {
            document.getElementById('ticketForm').reset();
            document.getElementById('caseDeliveryDate').value = <?php echo json_encode($today); ?>;
            autoAssignDepartment(document.getElementById('caseIssueType').value);
            fetchTickets();
        } else alert(d.error || 'Failed to save ticket');
    });
}
autoAssignDepartment(document.getElementById('caseIssueType').value);
fetchTickets();
<?php endif; ?>
</script>
</body>
</html>
