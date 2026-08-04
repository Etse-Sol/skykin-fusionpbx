<?php
// SkyKin Technologies – CRM (Customer Relationship Manager)

$fpbx_session_path = '/var/lib/php/sessions';
if (is_dir($fpbx_session_path)) session_save_path($fpbx_session_path);
session_name('PHPSESSID');
session_start();

if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
    header('Location: /?path=' . urlencode($_SERVER['REQUEST_URI'] ?? '/app/agent_dashboard/crm.php'));
    exit;
}

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = $_SESSION['domain_name'] ?? 'client1.skykin.local';
$domain  = isset($_GET['domain']) ? htmlspecialchars($_GET['domain']) : $logged_in_domain;

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
    try {
        $db = new PDO("pgsql:host={$h};port={$p};dbname={$n};connect_timeout=2",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        return $db;
    } catch(Exception $e) {}
    
    // SQLite fallback for local development
    $sqliteFile = __DIR__ . '/skykin_local.db';
    $db = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL');
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
            // Strip common prefixes for flexible matching
            $clean = preg_replace('/^(\+251|00251|0)/', '', $phone);
            $s = $db->prepare("SELECT * FROM skykin_contacts
                WHERE phone LIKE :q OR alt_phone LIKE :q
                   OR phone LIKE :c OR alt_phone LIKE :c
                ORDER BY contact_id LIMIT 1");
            $s->execute([':q'=>'%'.$phone.'%', ':c'=>'%'.$clean.'%']);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            // Also get call history for this contact
            if ($row) {
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
                $s->execute([':ph'=>$body['phone']??'',':ap'=>$body['alt_phone']??'',
                    ':fn'=>$body['full_name']??'',':em'=>$body['email']??'',
                    ':co'=>$body['company']??'',':la'=>$body['language']??'English',
                    ':at'=>$body['account_type']??'Customer',':no'=>$body['notes']??'',':id'=>$id]);
                echo json_encode(['ok'=>true,'action'=>'updated']);
            } else {
                $s = $db->prepare("INSERT INTO skykin_contacts
                    (phone,alt_phone,full_name,email,company,language,account_type,notes)
                    VALUES (:ph,:ap,:fn,:em,:co,:la,:at,:no)");
                $s->execute([':ph'=>$body['phone']??'',':ap'=>$body['alt_phone']??'',
                    ':fn'=>$body['full_name']??'',':em'=>$body['email']??'',
                    ':co'=>$body['company']??'',':la'=>$body['language']??'English',
                    ':at'=>$body['account_type']??'Customer',':no'=>$body['notes']??'']);
                echo json_encode(['ok'=>true,'action'=>'created']);
            }
            exit;
        }

        // Delete contact
        if ($_GET['api'] === 'delete' && isset($_GET['id'])) {
            $s = $db->prepare("DELETE FROM skykin_contacts WHERE contact_id=:id");
            $s->execute([':id'=>(int)$_GET['id']]);
            echo json_encode(['ok'=>true]);
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
<title>SkyKin – CRM</title>
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
.ct-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
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
      <a href="/app/agent_dashboard/crm.php" class="active">CRM</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?></div>
    <a href="/logout.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>

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
      <button class="btn-save" onclick="saveContact()">Save Contact</button>
      <button class="btn-delete" id="btnDelete" onclick="deleteContact()" style="display:none">Delete Contact</button>

      <!-- Call history for this contact -->
      <div class="call-history" id="callHistorySection" style="display:none">
        <h4>Recent Call History</h4>
        <div id="callHistoryList"></div>
      </div>
    </div>
  </div>
</div>

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
      <div class="contact-item" onclick='editContact(${JSON.stringify(JSON.stringify(c)).slice(1,-1)})'>
        <div class="ct-top">
          <span class="ct-name">${c.full_name}</span>
          <span class="ct-badge ${c.account_type}">${c.account_type}</span>
        </div>
        <div class="ct-meta">
          <span>${c.phone}</span>
          <span><span class="lang-dot lang-${c.language}" style="background:${langColors[c.language]||'#8b949e'}"></span>${c.language}</span>
          ${c.company?`<span>${c.company}</span>`:''}
        </div>
      </div>`).join('');
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

function editContact(jsonStr) {
    const c = JSON.parse(jsonStr);
    document.querySelectorAll('.contact-item').forEach(e=>e.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
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

async function deleteContact() {
    const id = document.getElementById('fId').value;
    if (!id) return;
    if (!confirm('Delete this contact?')) return;
    await fetch(`crm.php?api=delete&id=${id}&domain=${DOMAIN}`);
    document.getElementById('panelForm').style.display = 'none';
    document.getElementById('panelEmpty').style.display = 'block';
    loadContacts();
}

loadContacts();
</script>
</body>
</html>
