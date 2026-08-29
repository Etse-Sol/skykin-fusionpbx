<?php
// SkyKin Technologies – CRM (Customer Relationship Manager)
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

// Agents and supervisors both use CRM (caller lookup / contacts)
$is_api = isset($_GET['api']);
skykin_require_login($is_api);

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = skykin_default_domain();
$domain  = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$embed   = !empty($_GET['embed']);

function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

// ── Ensure CRM table exists ──────────────────────────────────────────────────
try {
    $db = getDB();
    $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    if ($isSQLite) {
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_contacts (
            contact_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            phone         TEXT NOT NULL UNIQUE,
            alt_phone     TEXT,
            full_name     TEXT NOT NULL,
            email         TEXT,
            company       TEXT,
            language      TEXT DEFAULT 'English',
            account_type  TEXT DEFAULT 'Customer',
            notes         TEXT,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS skykin_contacts (
            contact_id    SERIAL PRIMARY KEY,
            phone         TEXT NOT NULL UNIQUE,
            alt_phone     TEXT,
            full_name     TEXT NOT NULL,
            email         TEXT,
            company       TEXT,
            language      TEXT DEFAULT 'English',
            account_type  TEXT DEFAULT 'Customer',
            notes         TEXT,
            created_at    TIMESTAMP DEFAULT NOW(),
            updated_at    TIMESTAMP DEFAULT NOW()
        )");
    }
    // Sample contacts if empty
    $cnt = $db->query("SELECT COUNT(*) FROM skykin_contacts")->fetchColumn();
    if ($cnt == 0) {
        $db->exec("INSERT INTO skykin_contacts (phone,full_name,email,company,language,account_type,notes) VALUES
            ('+251911000001','Abebe Girma','abebe@example.com','TechCo','Amharic','VIP','Prefers callbacks in the morning'),
            ('+251922000002','Sara Mohammed','sara@example.com','BizGroup','English','Customer','Call history: 5 support tickets'),
            ('+251933000003','Dawit Bekele','dawit@example.com','RetailPlus','Oromo','Customer',''),
            ('0911000001','Abebe Girma','abebe@example.com','TechCo','Amharic','VIP',''),
            ('0922000002','Sara Mohammed','','BizGroup','English','Customer',''),
            ('0933000003','Dawit Bekele','','RetailPlus','Oromo','Customer','')
        ");
    }
} catch (Exception $e) { /* silent */ }

// ── API ──────────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    error_reporting(0);
    header('Content-Type: application/json');

    try {
        $db = getDB();

        // Lookup by phone (partial match) – used for caller ID popup
        if ($_GET['api'] === 'lookup') {
            $phone = $_GET['phone'] ?? '';
            if (!$phone) { echo json_encode(null); exit; }
            $row = skykin_crm_find_contact($db, $phone);
            if (!$row) {
                $norm = skykin_normalize_phone_storage($phone);
                if ($norm !== '' && $norm !== $phone) {
                    $row = skykin_crm_find_contact($db, $norm);
                }
            }
            // Also get call history for this contact
            if ($row) {
                $clean = preg_replace('/^(\+251|00251|0)/', '', $phone);
                $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
                $timeExpr = $isSQLite
                    ? "datetime(start_epoch, 'unixepoch', 'localtime')"
                    : "to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI')";
                $s2 = $db->prepare("SELECT
                    {$timeExpr} as call_time,
                    direction, billsec, destination_number, hangup_cause
                    FROM v_xml_cdr
                    WHERE (caller_id_number LIKE :q OR destination_number LIKE :q
                        OR caller_id_number LIKE :c OR destination_number LIKE :c)
                    ORDER BY start_epoch DESC LIMIT 5");
                $s2->execute([':q'=>'%'.$phone.'%',':c'=>'%'.$clean.'%']);
                $row['call_history'] = $s2->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($row);
            exit;
        }

        // List all contacts
        if ($_GET['api'] === 'list') {
            $search = $_GET['search'] ?? '';
            $where  = '1=1';
            $params = [];
            $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            $likeOp = $isSQLite ? 'LIKE' : 'ILIKE';
            if ($search) {
                $where = "full_name {$likeOp} :q OR phone {$likeOp} :q OR company {$likeOp} :q OR email {$likeOp} :q";
                $params[':q'] = '%'.$search.'%';
            }
            $s = $db->prepare("SELECT * FROM skykin_contacts WHERE $where ORDER BY full_name LIMIT 200");
            $s->execute($params);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // Save / update contact
        if ($_GET['api'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            $id   = $body['contact_id'] ?? null;
            $isSQLite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            $nowFunc = $isSQLite ? "datetime('now')" : "NOW()";
            if ($id) {
                $s = $db->prepare("UPDATE skykin_contacts SET
                    phone=:ph, alt_phone=:ap, full_name=:fn, email=:em,
                    company=:co, language=:la, account_type=:at, notes=:no,
                    updated_at={$nowFunc} WHERE contact_id=:id");
                $s->execute([':ph'=>skykin_normalize_phone_storage($body['phone']??''),':ap'=>skykin_normalize_phone_storage($body['alt_phone']??''),
                    ':fn'=>$body['full_name']??'',':em'=>$body['email']??'',
                    ':co'=>$body['company']??'',':la'=>$body['language']??'English',
                    ':at'=>$body['account_type']??'Customer',':no'=>$body['notes']??'',':id'=>$id]);
                echo json_encode(['ok'=>true,'action'=>'updated']);
            } else {
                $s = $db->prepare("INSERT INTO skykin_contacts
                    (phone,alt_phone,full_name,email,company,language,account_type,notes)
                    VALUES (:ph,:ap,:fn,:em,:co,:la,:at,:no)");
                $s->execute([':ph'=>skykin_normalize_phone_storage($body['phone']??''),':ap'=>skykin_normalize_phone_storage($body['alt_phone']??''),
                    ':fn'=>$body['full_name']??'',':em'=>$body['email']??'',
                    ':co'=>$body['company']??'',':la'=>$body['language']??'English',
                    ':at'=>$body['account_type']??'Customer',':no'=>$body['notes']??'']);
                echo json_encode(['ok'=>true,'action'=>'created']);
            }
            exit;
        }

        // Delete contact
        if ($_GET['api'] === 'delete' && isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Invalid contact id']);
                exit;
            }
            $s = $db->prepare('DELETE FROM skykin_contacts WHERE contact_id=:id');
            $s->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'deleted' => $s->rowCount()]);
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
<title>Sky Connect – CRM</title>
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

.toolbar{background:#ffffff;border-bottom:1px solid #e0e0e0;padding:12px 24px;
  display:flex;align-items:center;gap:12px}
.toolbar input{background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:7px 12px;border-radius:6px;font-size:13px;width:280px}
.toolbar input:focus{outline:none;border-color:#58a6ff}
.btn-primary{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;
  cursor:pointer;font-size:13px;font-weight:500}
.btn-primary:hover{background:#2ea043}
.btn-sec{background:#f0f2f5;color:#333;border:1px solid #e0e0e0;padding:7px 14px;
  border-radius:6px;cursor:pointer;font-size:13px}
.btn-sec:hover{background:#e0e0e0}

.layout{display:grid;grid-template-columns:1fr 400px;height:calc(100vh - 110px)}
.contact-list{overflow-y:auto;border-right:1px solid #e0e0e0}
.contact-panel{overflow-y:auto;background:#ffffff;padding:24px}

.contact-item{padding:14px 16px;border-bottom:1px solid #21262d;cursor:pointer;transition:.15s}
.contact-item:hover{background:#f0f2f5}
.contact-item.selected{background:#388bfd18;border-left:3px solid #58a6ff}
.ct-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;gap:8px}
.ct-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
.btn-icon-delete{background:transparent;border:none;color:#dc2626;cursor:pointer;padding:4px 6px;border-radius:6px;font-size:14px;line-height:1}
.btn-icon-delete:hover{background:#fee2e2}
.form-actions{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:4px;align-items:stretch}
.form-actions .btn-save{margin-top:0}
.form-actions .btn-delete{margin-top:0;width:auto;min-width:120px;white-space:nowrap}
.ct-name{font-size:14px;font-weight:600}
.ct-badge{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600}
.ct-badge.VIP{background:#d2992233;color:#d29922}
.ct-badge.Customer{background:#388bfd22;color:#58a6ff}
.ct-meta{font-size:11px;color:#888;display:flex;gap:12px}
.lang-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px}
.lang-Amharic{background:#f85149}
.lang-English{background:#3fb950}
.lang-Oromo{background:#58a6ff}
.lang-Other{background:#d29922}

/* Panel */
.panel-title{font-size:15px;font-weight:600;margin-bottom:16px}
.form-field{margin-bottom:14px}
.form-field label{display:block;font-size:11px;color:#888;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.form-field input,.form-field select,.form-field textarea{
  width:100%;background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:8px 10px;border-radius:6px;font-family:inherit;font-size:13px}
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{outline:none;border-color:#58a6ff}
.form-field textarea{resize:vertical;min-height:70px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn-save{width:100%;background:#238636;color:#fff;border:none;padding:11px;border-radius:8px;
  cursor:pointer;font-size:14px;font-weight:600;margin-top:4px}
.btn-save:hover{background:#2ea043}
.btn-delete{background:#da363322;color:#f85149;border:1px solid #da363344;padding:8px 14px;
  border-radius:6px;cursor:pointer;font-size:12px;margin-top:8px;width:100%}
.btn-delete:hover{background:#f8514922}

.call-history{margin-top:20px}
.call-history h4{font-size:12px;color:#888;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px}
.ch-row{display:flex;justify-content:space-between;align-items:center;
  padding:8px 10px;background:#f0f2f5;border-radius:6px;margin-bottom:6px;font-size:12px}
.ch-dir{padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600}
.ch-dir.inbound{background:#388bfd22;color:#58a6ff}
.ch-dir.outbound{background:#f0883e22;color:#f0883e}
.empty-state{text-align:center;padding:40px;color:#888;font-size:13px}
.count-badge{background:#f0f2f5;color:#888;padding:3px 10px;border-radius:10px;font-size:12px;margin-left:8px}

/* SkyKin light workspace */
:root{
  --sk-blue:#2563eb;--sk-blue-soft:#eff6ff;--sk-text:#172033;
  --sk-muted:#64748b;--sk-canvas:#f6f8fb;--sk-surface:#fff;
  --sk-line:#e8edf3;--sk-shadow:0 8px 28px rgba(15,23,42,.06)
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

.toolbar{min-height:62px;padding:11px 28px;background:var(--sk-canvas);border:0;gap:10px}
.toolbar input{height:38px;width:min(360px,45vw);background:#fff;border:1px solid #dbe3ec;color:var(--sk-text);padding:7px 12px;border-radius:9px}
.toolbar input:focus{outline:0;border-color:#93b4f4;box-shadow:0 0 0 3px rgba(37,99,235,.09)}
.btn-primary{height:38px;background:var(--sk-blue);padding:0 18px;border-radius:9px;font-weight:650;box-shadow:0 3px 8px rgba(37,99,235,.16)}
.btn-primary:hover{background:#1d4ed8}
.count-badge{margin-left:2px;background:#e8eef7;color:#526277;padding:5px 10px;font-weight:650}

.layout{grid-template-columns:minmax(0,1fr) 420px;gap:14px;height:calc(100vh - 126px);padding:0 20px 18px}
.contact-list,.contact-panel{background:var(--sk-surface);border:0;border-radius:14px;box-shadow:var(--sk-shadow)}
.contact-list{padding:8px;overflow-y:auto}
.contact-panel{padding:22px}
.contact-item{margin:3px 0;padding:13px 14px;border:0;border-radius:10px}
.contact-item:hover{background:#f8fafc}
.contact-item.selected{background:var(--sk-blue-soft);border:0;box-shadow:inset 3px 0 var(--sk-blue)}
.ct-name{color:var(--sk-text);font-size:13px}
.ct-meta{color:var(--sk-muted)}
.ct-badge{padding:3px 8px}
.ct-badge.VIP{background:#fff7ed;color:#c2410c}
.ct-badge.Customer{background:#eff6ff;color:#2563eb}
.ct-badge.Prospect{background:#f5f3ff;color:#7c3aed}
.ct-badge.Partner{background:#f0fdf4;color:#15803d}

.panel-title{font-size:17px;color:var(--sk-text);margin-bottom:20px}
.form-field{margin-bottom:15px}
.form-field label{color:var(--sk-muted);font-size:10px;font-weight:700}
.form-field input,.form-field select,.form-field textarea{background:#fff;border:1px solid #dbe3ec;color:var(--sk-text);padding:9px 11px;border-radius:9px}
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:#93b4f4;box-shadow:0 0 0 3px rgba(37,99,235,.09)}
.form-field textarea{min-height:82px}
.form-row{gap:12px}
.btn-save{background:var(--sk-blue);border-radius:10px;box-shadow:0 3px 8px rgba(37,99,235,.16)}
.btn-save:hover{background:#1d4ed8}
.btn-delete{background:#fff5f5;color:#dc2626;border:0;border-radius:10px}
.btn-delete:hover{background:#fee2e2}
.call-history{margin-top:24px;padding-top:0}
.call-history h4{color:var(--sk-muted);font-size:10px;font-weight:700}
.ch-row{background:#f8fafc;border-radius:9px;padding:9px 11px}
.empty-state{color:var(--sk-muted)}

body.embed-mode{background:var(--sk-canvas)}
body.embed-mode .topbar{display:none}
body.embed-mode .layout{height:calc(100vh - 62px)}
body.embed-mode .crm-footer{display:none}

@media(max-width:850px){
  .topbar{padding:0 14px}.brand span,.topbar-right .user-pill{display:none}
  .nav-links{overflow-x:auto}.nav-links a{white-space:nowrap;padding:8px}
  .toolbar{padding:10px 14px}.layout{grid-template-columns:1fr;height:auto;padding:0 10px 12px}
  .contact-list{max-height:42vh}.contact-panel{min-height:50vh}.form-row{grid-template-columns:1fr}
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
      <a href="/app/agent_dashboard/evaluation.php">Evaluation</a>
      <a href="/app/agent_dashboard/crm.php" class="active">CRM</a>
      <a href="/app/agent_dashboard/billing.php">Billing</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?></div>
    <a href="/logout.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>
<?php endif; ?>

<div class="toolbar">
  <input type="text" id="searchBox" placeholder="Search by name, phone, company..." oninput="loadContacts()" autofocus>
  <button class="btn-primary" onclick="newContact()">+ New Contact</button>
  <span id="contactCount" class="count-badge">0</span>
</div>

<div class="layout">
  <div class="contact-list" id="contactList">
    <div class="empty-state">Loading...</div>
  </div>

  <div class="contact-panel">
    <div id="panelEmpty" class="empty-state" style="margin-top:60px">
      <div style="font-size:32px;margin-bottom:12px">&#128100;</div>
      <div style="font-weight:600;color:#333;margin-bottom:6px">Select a contact</div>
      <div>Click a contact to view or edit</div>
    </div>
    <div id="panelForm" style="display:none">
      <div class="panel-title" id="panelTitle">Contact Details</div>
      <input type="hidden" id="fId">
      <div class="form-row">
        <div class="form-field"><label>Full Name *</label><input id="fName" placeholder="Full name"></div>
        <div class="form-field"><label>Account Type</label>
          <select id="fType"><option>Customer</option><option>VIP</option><option>Prospect</option><option>Partner</option></select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Phone *</label><input id="fPhone" placeholder="+251911000000"></div>
        <div class="form-field"><label>Alt Phone</label><input id="fAlt" placeholder="Optional"></div>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Email</label><input id="fEmail" type="email" placeholder="email@example.com"></div>
        <div class="form-field"><label>Company</label><input id="fCompany" placeholder="Company name"></div>
      </div>
      <div class="form-field"><label>Preferred Language</label>
        <select id="fLang">
          <option>English</option><option>Amharic</option><option>Oromo</option><option>Tigrinya</option><option>Other</option>
        </select>
      </div>
      <div class="form-field"><label>Notes</label><textarea id="fNotes" placeholder="Any notes about this customer..."></textarea></div>
      <div class="form-actions">
        <button class="btn-save" type="button" onclick="saveContact()">Save Contact</button>
        <button class="btn-delete" type="button" id="btnDelete" onclick="deleteContact()" style="display:none">Delete</button>
      </div>

      <!-- Call history for this contact -->
      <div class="call-history" id="callHistorySection" style="display:none">
        <h4>Recent Call History</h4>
        <div id="callHistoryList"></div>
      </div>
    </div>
  </div>
</div>
<div class="crm-footer" style="text-align:center;font-size:11px;color:#aaa;padding:16px 24px">Sky Connect &copy; <?php echo date('Y'); ?> | Powered by SkyKin Technology</div>

<script>
const DOMAIN = '<?php echo $domain; ?>';
let allContacts = [];

async function loadContacts() {
    const q = document.getElementById('searchBox').value.trim();
    const resp = await fetch(`crm.php?api=list&domain=${DOMAIN}&search=${encodeURIComponent(q)}`);
    allContacts = await resp.json();
    document.getElementById('contactCount').textContent = allContacts.length;
    const list = document.getElementById('contactList');
    if (!allContacts.length) { list.innerHTML='<div class="empty-state">No contacts found</div>'; return; }
    const langColors = {Amharic:'#f85149',English:'#3fb950',Oromo:'#58a6ff',Tigrinya:'#d29922',Other:'#8b949e'};
    list.innerHTML = allContacts.map(c => `
      <div class="contact-item" data-id="${c.contact_id}" onclick="editContactById(${c.contact_id})">
        <div class="ct-top">
          <span class="ct-name">${escapeHtml(c.full_name)}</span>
          <div class="ct-actions">
            <span class="ct-badge ${escapeHtml(c.account_type)}">${escapeHtml(c.account_type)}</span>
            <button type="button" class="btn-icon-delete" title="Delete contact"
              onclick="deleteContactById(${c.contact_id}, event)">&#128465;</button>
          </div>
        </div>
        <div class="ct-meta">
          <span>${escapeHtml(c.phone)}</span>
          <span><span class="lang-dot lang-${escapeHtml(c.language)}" style="background:${langColors[c.language]||'#8b949e'}"></span>${escapeHtml(c.language)}</span>
          ${c.company?`<span>${escapeHtml(c.company)}</span>`:''}
        </div>
      </div>`).join('');
}

function escapeHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function editContactById(id) {
    const c = allContacts.find(x => String(x.contact_id) === String(id));
    if (!c) return;
    editContact(c);
}

function newContact() {
    document.querySelectorAll('.contact-item').forEach(e=>e.classList.remove('selected'));
    document.getElementById('panelEmpty').style.display = 'none';
    document.getElementById('panelForm').style.display  = 'block';
    document.getElementById('panelTitle').textContent   = 'New Contact';
    document.getElementById('fId').value      = '';
    document.getElementById('fName').value    = '';
    document.getElementById('fPhone').value   = '';
    document.getElementById('fAlt').value     = '';
    document.getElementById('fEmail').value   = '';
    document.getElementById('fCompany').value = '';
    document.getElementById('fNotes').value   = '';
    document.getElementById('fType').value    = 'Customer';
    document.getElementById('fLang').value    = 'English';
    document.getElementById('btnDelete').style.display = 'none';
    document.getElementById('callHistorySection').style.display = 'none';
}

function editContact(c) {
    if (typeof c === 'string') {
        try { c = JSON.parse(c); } catch (e) { return; }
    }
    document.querySelectorAll('.contact-item').forEach(e => {
        e.classList.toggle('selected', String(e.dataset.id) === String(c.contact_id));
    });
    document.getElementById('panelEmpty').style.display = 'none';
    document.getElementById('panelForm').style.display  = 'block';
    document.getElementById('panelTitle').textContent   = 'Edit Contact';
    document.getElementById('fId').value      = c.contact_id || '';
    document.getElementById('fName').value    = c.full_name   || '';
    document.getElementById('fPhone').value   = c.phone       || '';
    document.getElementById('fAlt').value     = c.alt_phone   || '';
    document.getElementById('fEmail').value   = c.email       || '';
    document.getElementById('fCompany').value = c.company     || '';
    document.getElementById('fNotes').value   = c.notes       || '';
    document.getElementById('fType').value    = c.account_type || 'Customer';
    document.getElementById('fLang').value    = c.language    || 'English';
    document.getElementById('btnDelete').style.display = 'block';

    // Show call history if present
    if (c.call_history && c.call_history.length > 0) {
        const list = document.getElementById('callHistoryList');
        list.innerHTML = c.call_history.map(h =>
            `<div class="ch-row">
              <span>${h.call_time}</span>
              <span class="ch-dir ${h.direction}">${h.direction}</span>
              <span>${Math.floor(h.billsec/60)}m ${h.billsec%60}s</span>
              <span style="color:#888">${h.hangup_cause||''}</span>
            </div>`).join('');
        document.getElementById('callHistorySection').style.display = 'block';
    } else {
        document.getElementById('callHistorySection').style.display = 'none';
    }
}

async function saveContact() {
    const body = {
        contact_id:   document.getElementById('fId').value || null,
        full_name:    document.getElementById('fName').value.trim(),
        phone:        document.getElementById('fPhone').value.trim(),
        alt_phone:    document.getElementById('fAlt').value.trim(),
        email:        document.getElementById('fEmail').value.trim(),
        company:      document.getElementById('fCompany').value.trim(),
        language:     document.getElementById('fLang').value,
        account_type: document.getElementById('fType').value,
        notes:        document.getElementById('fNotes').value.trim(),
    };
    if (!body.full_name || !body.phone) { alert('Name and phone are required'); return; }
    const resp = await fetch(`crm.php?api=save&domain=${DOMAIN}`,{
        method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)
    });
    const r = await resp.json();
    if (r.ok) { loadContacts(); alert(r.action === 'created' ? 'Contact created!' : 'Contact updated!'); }
    else alert('Error: '+(r.error||'Unknown'));
}

async function deleteContactById(id, ev) {
    if (ev) { ev.stopPropagation(); ev.preventDefault(); }
    if (!id) return;
    const c = allContacts.find(x => String(x.contact_id) === String(id));
    const label = c ? (c.full_name + ' (' + c.phone + ')') : ('contact #' + id);
    if (!confirm('Delete ' + label + '?')) return;
    const resp = await fetch(`crm.php?api=delete&id=${encodeURIComponent(id)}&domain=${DOMAIN}`);
    const r = await resp.json().catch(() => ({}));
    if (!r.ok) { alert('Delete failed: ' + (r.error || 'Unknown error')); return; }
    document.getElementById('panelForm').style.display = 'none';
    document.getElementById('panelEmpty').style.display = 'block';
    loadContacts();
}

async function deleteContact() {
    const id = document.getElementById('fId').value;
    await deleteContactById(id);
}

loadContacts();
</script>
<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
</body>
</html>
