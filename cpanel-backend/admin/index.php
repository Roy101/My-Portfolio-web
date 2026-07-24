<?php
/**
 * Content admin for palashroy.me
 * Login-protected editor that reads/writes the `content` MySQL table.
 * Security: PDO prepared statements, password_hash/verify, CSRF tokens,
 * login lockout, HttpOnly+Secure+SameSite session cookie.
 */
declare(strict_types=1);
require __DIR__ . '/../api/db.php';

// ---- secure session ----
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_csrf(string $token): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Bad CSRF token — reload the page.']);
        exit;
    }
}
function is_logged_in(): bool { return !empty($_SESSION['admin']); }
function json_out($data): void { header('Content-Type: application/json'); echo json_encode($data); exit; }

$action = $_GET['action'] ?? '';

// ---- logout ----
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ---- login ----
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    require_csrf($_POST['csrf'] ?? '');
    // Verify the CAPTCHA before touching the database (blocks bots + brute force).
    $captchaOk = isset($_SESSION['captcha']) && hash_equals((string)$_SESSION['captcha'], trim((string)($_POST['captcha'] ?? '')));
    unset($_SESSION['captcha'], $_SESSION['captcha_q']);
    if (!$captchaOk) {
        $_SESSION['login_error'] = 'Incorrect answer to the verification question.';
        header('Location: index.php');
        exit;
    }
    $err = 'Invalid username or password.';
    try {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $row = $stmt->fetch();
        $now = time();
        if ($row && (int)$row['locked_until'] > $now) {
            $err = 'Too many attempts. Try again in a few minutes.';
        } elseif ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $row['username'];
            db()->prepare("UPDATE admin_users SET failed_attempts=0, locked_until=0 WHERE id=?")->execute([$row['id']]);
            header('Location: index.php');
            exit;
        } elseif ($row) {
            $fa = (int)$row['failed_attempts'] + 1;
            $lock = $fa >= 5 ? $now + 600 : 0; // lock 10 min after 5 fails
            db()->prepare("UPDATE admin_users SET failed_attempts=?, locked_until=? WHERE id=?")->execute([$fa, $lock, $row['id']]);
        }
    } catch (Throwable $e) {
        $err = 'Server error. Check config.php / database.';
    }
    $_SESSION['login_error'] = $err;
    header('Location: index.php');
    exit;
}

// ---- save a section (AJAX, logged-in only) ----
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode(file_get_contents('php://input'), true);
    require_csrf($body['csrf'] ?? '');
    $section = $body['section'] ?? '';
    if (!in_array($section, ALLOWED_SECTIONS, true)) { http_response_code(400); json_out(['error' => 'Unknown section']); }
    $json = json_encode($body['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { http_response_code(400); json_out(['error' => 'Invalid data']); }
    try {
        db()->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([$section, $json]);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ---- change password (AJAX, logged-in only) ----
if ($action === 'changepw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode(file_get_contents('php://input'), true);
    require_csrf($body['csrf'] ?? '');
    $cur = (string)($body['current'] ?? '');
    $new = (string)($body['new'] ?? '');
    if (strlen($new) < 10) { http_response_code(400); json_out(['error' => 'New password must be at least 10 characters']); }
    try {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            http_response_code(403); json_out(['error' => 'Current password is incorrect']);
        }
        db()->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $row['id']]);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ================= LOGIN PAGE =================
if (!is_logged_in()) {
    $err = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    // Fresh CAPTCHA for this login attempt.
    $ca = random_int(1, 9); $cb = random_int(1, 9);
    $_SESSION['captcha'] = (string)($ca + $cb);
    $_SESSION['captcha_q'] = "$ca + $cb = ?";
    $hasGD = function_exists('imagecreatetruecolor');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>Admin Login</title>
    <style>body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
    form{background:#181a22;border:1px solid #2d324b;padding:32px;border-radius:12px;width:320px}
    h1{font-size:20px;margin:0 0 20px}input{width:100%;box-sizing:border-box;padding:10px;margin-bottom:12px;background:#0c0d16;border:1px solid #2d324b;border-radius:8px;color:#fff}
    button{width:100%;padding:11px;background:linear-gradient(90deg,#35c7ff,#ff4081);border:0;border-radius:8px;color:#fff;font-weight:600;cursor:pointer}
    .err{color:#ff6b6b;font-size:13px;margin-bottom:12px}
    label{display:block;font-size:12px;color:#a2a5b9;margin:0 0 6px}
    .cap{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .cap img{border:1px solid #2d324b;border-radius:6px}
    .cap .q{font-size:15px;font-weight:600}
    .cap input{margin-bottom:0}</style></head><body>
    <form method="post" action="index.php?action=login">
      <h1>Content Admin</h1>
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input name="username" placeholder="Username" autocomplete="username" required autofocus>
      <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
      <label>Verification</label>
      <div class="cap">
        <?php if ($hasGD): ?>
          <img src="captcha.php?<?= time() ?>" width="150" height="46" alt="verification question">
        <?php else: ?>
          <span class="q"><?= "$ca + $cb = ?" ?></span>
        <?php endif; ?>
        <input name="captcha" inputmode="numeric" autocomplete="off" placeholder="Answer" required>
      </div>
      <button type="submit">Log in</button>
    </form></body></html><?php
    exit;
}

// ================= EDITOR =================
$content = [];
try {
    foreach (db()->query("SELECT section, data FROM content")->fetchAll() as $r) {
        $content[$r['section']] = json_decode($r['data'], true);
    }
} catch (Throwable $e) { $content = []; }

// field schemas: which keys each section's items have, and which are long text / images
$schemas = [
    'publications' => ['title'=>'text','authors'=>'text','venue'=>'text','year'=>'text','pages'=>'text','award'=>'text','doi'=>'text','description'=>'area','preprint'=>'text','bibtex'=>'area'],
    'highlights'   => ['title'=>'text','organization'=>'text','description'=>'area','link'=>'text','image'=>'image'],
    'news'         => ['date'=>'text','icon'=>'text','title'=>'text','description'=>'area','url'=>'url'],
    'media'        => ['outlet'=>'text','date'=>'text','title'=>'text','url'=>'url'],
    'gallery'      => ['image'=>'image','title'=>'text','description'=>'area','altText'=>'text'],
    'leadership'   => ['period'=>'text','role'=>'text','organization'=>'text','place'=>'text','link'=>'text'],
    'service'      => ['period'=>'text','role'=>'text','description'=>'area','venue'=>'text','venues'=>'text'],
    'references'   => ['name'=>'text','title'=>'text','image'=>'image','text'=>'area'],
];
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Content Admin · palashroy.me</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;margin:0}
.shell{display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:250px;flex-shrink:0;background:#12131c;border-right:1px solid #23263a;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.brand{padding:18px 20px;font-weight:700;font-size:16px;color:#7ec8e3;border-bottom:1px solid #23263a;display:flex;align-items:center;gap:8px}
.brand small{display:block;color:#6b6f85;font-weight:400;font-size:11px;margin-top:2px}
.side-scroll{flex:1;overflow-y:auto;padding:10px 0}
.grp{padding:14px 20px 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#5b6079}
.navlink{display:flex;align-items:center;gap:10px;padding:9px 20px;color:#c7cad8;text-decoration:none;font-size:14px;cursor:pointer;border-left:3px solid transparent}
.navlink:hover{background:#181a26}
.navlink.active{background:#181a26;border-left-color:#35c7ff;color:#fff;font-weight:600}
.navlink .ic{width:20px;text-align:center}
.side-foot{border-top:1px solid #23263a;padding:12px 20px}
.side-foot .who{font-size:12px;color:#6b6f85;margin-bottom:8px}
.logout{display:block;text-align:center;padding:9px;background:#181a22;border:1px solid #2d324b;border-radius:8px;color:#e8e8ef;text-decoration:none;font-size:13px}
.logout:hover{border-color:#ff6b6b;color:#ff6b6b}
/* Main */
main{flex:1;min-width:0}
.topbar{position:sticky;top:0;z-index:5;background:#0c0d16;border-bottom:1px solid #23263a;padding:16px 28px;display:flex;align-items:center;gap:12px}
.topbar h1{font-size:19px;margin:0}
.topbar .hint{color:#6b6f85;font-size:13px;margin-left:auto}
.wrap{max-width:860px;margin:0 auto;padding:22px 28px 90px}
.menu-btn{display:none;background:#181a22;border:1px solid #2d324b;color:#fff;padding:7px 10px;border-radius:8px;cursor:pointer}
/* Cards + fields */
.item{background:#181a22;border:1px solid #2d324b;border-radius:10px;padding:16px;margin-bottom:14px}
h2{font-size:16px;margin:0 0 4px}
.lead{color:#a2a5b9;font-size:13px;margin:0 0 16px}
label{display:block;font-size:12px;color:#a2a5b9;margin:10px 0 4px;text-transform:capitalize}
input,textarea{width:100%;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff;font-family:inherit;font-size:14px}
textarea{min-height:70px;resize:vertical}
.row{display:flex;gap:6px;margin-bottom:8px}
.item-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;gap:8px}
.item-head b{font-size:13px;color:#7ec8e3}
.acts{display:flex;gap:6px;flex-shrink:0}
.mv{background:#2d324b;padding:4px 10px;font-size:15px;line-height:1}
button{background:#2d324b;border:0;color:#fff;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px}
button.primary{background:linear-gradient(90deg,#35c7ff,#ff4081);font-weight:600}
button.del{background:#3a1420;color:#ff6b6b}
button.up{background:#1c2b3a;color:#7ec8e3;white-space:nowrap}
.bar{display:flex;gap:10px;margin:18px 0 6px;position:sticky;bottom:0;background:#0c0d16;padding:12px 0}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#181a22;border:1px solid #2d324b;padding:10px 18px;border-radius:8px;opacity:0;transition:.3s;z-index:50}
.toast.show{opacity:1}
img.thumb{max-height:70px;border-radius:6px;margin-top:8px;display:block;border:1px solid #2d324b}
.uprow{display:flex;gap:6px}
.uprow input[data-k]{flex:1}
small{color:#6b6f85;display:block;margin-top:6px}
code{background:#0c0d16;border:1px solid #2d324b;border-radius:4px;padding:1px 5px;font-size:12px;color:#8fd0ec}
.imgfield{margin-top:2px}
@media(max-width:760px){
  .sidebar{position:fixed;left:-260px;z-index:40;transition:left .2s;box-shadow:0 0 40px rgba(0,0,0,.5)}
  .sidebar.open{left:0}
  .menu-btn{display:inline-block}
}
</style></head><body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">🛠️ Content Admin<small>palashroy.me</small></div>
    <div class="side-scroll"><nav id="nav"></nav></div>
    <div class="side-foot">
      <div class="who">Signed in as <b><?= htmlspecialchars($_SESSION['admin']) ?></b></div>
      <a class="logout" href="index.php?action=logout">Log out</a>
    </div>
  </aside>
  <main>
    <div class="topbar">
      <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <h1 id="pageTitle">Home</h1>
      <span class="hint">Changes go live within ~1 minute of saving</span>
    </div>
    <div class="wrap" id="app"></div>
  </main>
</div>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode(csrf()) ?>;
const SCHEMAS = <?= json_encode($schemas) ?>;
const DATA = <?= json_encode($content, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const LABELS = {publications:'Publications',highlights:'Achievements',news:'News & Milestones',media:'In the News',gallery:'Pictures',leadership:'Leadership',service:'Academic Service',references:'References'};

// Sidebar navigation groups
const NAV = [
  {group:'Content', items:[
    {id:'hero',label:'Home / Hero',icon:'🏠'},
    {id:'about',label:'Biography',icon:'📝'},
    {id:'headings',label:'Sections & Headings',icon:'🏷️'},
    {id:'publications',label:'Publications',icon:'📚'},
    {id:'research',label:'Research Graph',icon:'🕸️'},
    {id:'highlights',label:'Achievements',icon:'🏅'},
    {id:'news',label:'News & Milestones',icon:'🗞️'},
    {id:'media',label:'In the News',icon:'📰'},
    {id:'gallery',label:'Pictures',icon:'🖼️'},
    {id:'leadership',label:'Leadership',icon:'🎖️'},
    {id:'service',label:'Academic Service',icon:'🤝'},
    {id:'references',label:'References',icon:'💬'},
  ]},
  {group:'Site', items:[
    {id:'layout',label:'Page Layout',icon:'⚙️'},
    {id:'settings',label:'Slider & Resume',icon:'🎚️'},
    {id:'metrics',label:'Research Metrics',icon:'📊'},
  ]},
  {group:'Account', items:[
    {id:'password',label:'Change Password',icon:'🔒'},
  ]},
];
const TITLES = {};
NAV.forEach(g=>g.items.forEach(i=>TITLES[i.id]=i.label));

let current = 'hero';

// Page-layout (section order) editing
const SECTION_LABELS = {about:'About / Biography',portfolio:'Publications',news:'News & Milestones',leadership:'Leadership',service:'Academic Service',highlights:'Awards',media:'In the News',pictures:'Pictures',references:'References',contact:'Contact'};
const DEFAULT_ORDER = ['about','portfolio','news','leadership','service','highlights','media','pictures','references','contact'];
let layoutOrder = (Array.isArray(DATA.sectionOrder) ? DATA.sectionOrder.filter(id=>SECTION_LABELS[id]) : DEFAULT_ORDER.slice());
DEFAULT_ORDER.forEach(id=>{ if(!layoutOrder.includes(id)) layoutOrder.push(id); });

// Sections whose headings/descriptions are editable, in page order
const HSECTIONS = [
  {id:'about',label:'About / Biography'},
  {id:'portfolio',label:'Publications'},
  {id:'news',label:'News & Milestones'},
  {id:'leadership',label:'Leadership'},
  {id:'service',label:'Academic Service'},
  {id:'highlights',label:'Awards'},
  {id:'media',label:'In the News'},
  {id:'pictures',label:'Pictures'},
  {id:'references',label:'References'},
  {id:'contact',label:'Contact'},
];
// Current live values — used to prefill the editor so a first save never blanks the site.
const DEFAULT_HEADINGS = {
  about:{nav:'About',badge:'',title:'Biography',subtitle:''},
  portfolio:{nav:'Publications',badge:'Publications',title:'My Latest Publications',subtitle:'See my Google Scholar for the latest details on the following work.'},
  news:{nav:'News',badge:'News & Milestones',title:"What's Happening",subtitle:'Recent milestones, awards, and research highlights.'},
  leadership:{nav:'Leadership',badge:'Leadership',title:"Titles I Didn't Ask For but Took Anyway",subtitle:'A curated list of leadership roles where I herded humans, orchestrated controlled chaos, and occasionally made important decisions while pretending to know what I was doing.'},
  service:{nav:'Service',badge:'Service',title:'Academic Service',subtitle:''},
  highlights:{nav:'Awards',badge:'Highlights',title:'Featured Highlights',subtitle:'Here are some awards, articles, documents, certificates, and whatever else I am proud of.'},
  media:{nav:'Media',badge:'In the News',title:'Featured Coverage',subtitle:'Selected media coverage and university announcements.'},
  pictures:{nav:'Gallery',badge:'Gallery',title:'Beyond the Research',subtitle:'From representing 4,500+ graduate students as GSA President to exploring the world with family and friends.'},
  references:{nav:'References',badge:'References',title:'References',subtitle:'Here are some of the amazing people who I have worked with in the past that I could reach out to for a reference if needed.',note:'I really need to update this to add all my amazing computer science references! But that will be for another day.',mentors:'I have had the privilege to work with [Dr. Kevin Schneider](https://artsandscience.usask.ca/profile/KSchneider), [Dr. Chanchal Roy](https://clones.usask.ca/), [Dr. Farouq Al-Omari](https://www.tru.ca/science/departments/engineering/Faculty.html), [Dr. Banani Roy](https://www.cs.usask.ca/people/faculty%20profiles/banani-roy.php), and [Dr. Cody Phillips](https://www.qut.edu.au/about/our-people/academic-profiles/cody.phillips).'},
  contact:{nav:'Contact',badge:'Contact',title:'Get in Touch',subtitle:"Have a question, an opportunity, or just want to say hi? Send me a message and I'll get back to you."},
};

const FMT_HINT = `<p class="lead" style="background:#12131c;border:1px solid #23263a;border-radius:8px;padding:10px 12px">✏️ <b style="color:#7ec8e3">Formatting:</b> link = <code>[text](https://link.com)</code> — put the URL in <b>(round brackets)</b>. Bold = <code>**word**</code>, italic = <code>*word*</code>. A bare address like <code>srlab.usask.ca</code> becomes a link automatically. Works in descriptions, paragraphs, hero text and section subtitles.</p>`;
function toast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);}
const escA = v => (v==null?'':String(v)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
function v(id){ const el=document.getElementById(id); return el?el.value.trim():''; }
const app = ()=>document.getElementById('app');

function renderNav(){
  document.getElementById('nav').innerHTML = NAV.map(g=>
    `<div class="grp">${g.group}</div>` + g.items.map(i=>
      `<a class="navlink ${i.id===current?'active':''}" onclick="switchTo('${i.id}')"><span class="ic">${i.icon}</span>${i.label}</a>`
    ).join('')
  ).join('');
}
window.switchTo = s => {
  current = s;
  document.getElementById('pageTitle').textContent = TITLES[s] || s;
  document.getElementById('sidebar').classList.remove('open');
  renderNav(); renderSection();
};

async function postSection(section,data,label){
  try{ const r=await fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,section,data})}); const j=await r.json(); toast(j.ok?(label+' saved ✓'):('Error: '+(j.error||'failed'))); return j.ok; }catch(e){ toast('Network error'); return false; }
}

// ---- file uploads (images + resume) ----
async function uploadFile(file, kind, cb){
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('kind',kind); fd.append('file',file);
  toast('Uploading…');
  try{
    const r=await fetch('upload.php',{method:'POST',body:fd});
    const j=await r.json();
    if(j.ok){ cb(j.url); toast('Uploaded ✓'); } else toast('Error: '+(j.error||'upload failed'));
  }catch(e){ toast('Upload failed'); }
}
window.onPickImage=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const wrap=fi.closest('.imgfield');
  const txt=wrap.querySelector('input[data-k]');
  uploadFile(f,'image',url=>{
    txt.value=url;
    let thumb=wrap.querySelector('img.thumb');
    if(!thumb){ thumb=document.createElement('img'); thumb.className='thumb'; wrap.appendChild(thumb); }
    thumb.src=url;
  });
  fi.value='';
};

// Fetch an article's title from its URL and fill in the Title (and Outlet, if empty).
window.fetchTitle=async(btn)=>{
  const item=btn.closest('.item');
  const urlInput=btn.parentNode.querySelector('input[data-k]');
  const url=(urlInput.value||'').trim();
  if(!url){ toast('Enter the article URL first'); return; }
  toast('Fetching title…');
  try{
    const r=await fetch('fetchtitle.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,url})});
    const j=await r.json();
    if(!j.ok){ toast('Error: '+(j.error||'could not fetch')); return; }
    const t=item.querySelector('input[data-k="title"]'); if(t){ t.value=j.title; }
    const o=item.querySelector('input[data-k="outlet"]'); if(o && !o.value.trim() && j.siteName){ o.value=j.siteName; }
    toast('Title fetched ✓');
  }catch(e){ toast('Network error'); }
};

// ---- Page Layout ----
function renderLayout(){
  app().innerHTML = `<h2>Page Layout</h2><p class="lead">Reorder the sections of your homepage. This also reorders the top navigation menu on the website.</p>`+
    `<div>`+layoutOrder.map((id,i)=>`<div class="item" style="display:flex;justify-content:space-between;align-items:center"><b style="color:#7ec8e3">${(i+1)+'. '+SECTION_LABELS[id]}</b><span class="acts"><button class="mv" onclick="moveSection(${i},-1)">↑</button><button class="mv" onclick="moveSection(${i},1)">↓</button></span></div>`).join('')+`</div>`+
    `<div class="bar"><button class="primary" onclick="saveLayout()">Save Layout</button></div>`;
}
window.moveSection=(i,dir)=>{const j=i+dir;if(j<0||j>=layoutOrder.length)return;[layoutOrder[i],layoutOrder[j]]=[layoutOrder[j],layoutOrder[i]];renderLayout();};
window.saveLayout=()=>postSection('sectionOrder',layoutOrder,'Layout');

// ---- Slider & Resume settings ----
function renderSettings(){
  const s = Object.assign({sliderSeconds:11,resumeUrl:''}, DATA.settings||{});
  app().innerHTML = `<h2>Slider & Resume</h2><p class="lead">Control how fast the sliders rotate and upload the resume people can download.</p>`+
    `<div class="item">`+
    `<label>Slider speed — seconds between slides</label><input id="st_slider" type="number" min="2" max="60" value="${escA(s.sliderSeconds||11)}">`+
    `<small>Applies to every rotating carousel (Publications, Leadership, Pictures, etc.).</small>`+
    `</div>`+
    `<div class="item imgfield">`+
    `<label>Resume / CV (PDF)</label>`+
    `<div class="uprow"><input data-k="resume" id="st_resume" value="${escA(s.resumeUrl)}" placeholder="/uploads/resume.pdf"><button type="button" class="up" onclick="this.nextElementSibling.click()">📤 Upload PDF</button><input type="file" accept="application/pdf" hidden onchange="onPickResume(this)"></div>`+
    (s.resumeUrl?`<small>Current: <a href="${escA(s.resumeUrl)}" target="_blank" style="color:#7ec8e3">${escA(s.resumeUrl)}</a></small>`:'')+
    `<small>A "Download Resume" button appears in your hero when this is set. Leave blank to hide it.</small>`+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="saveSettings()">Save</button></div>`;
}
window.onPickResume=(fi)=>{ const f=fi.files[0]; if(!f) return; uploadFile(f,'resume',url=>{ document.getElementById('st_resume').value=url; }); fi.value=''; };
window.saveSettings=()=>{
  const data={sliderSeconds:parseInt(document.getElementById('st_slider').value)||11,resumeUrl:v('st_resume')};
  DATA.settings=data; postSection('settings',data,'Settings');
};

// ---- Sections & Headings ----
function renderHeadings(){
  const H = DATA.headings || {};
  let html = `<h2>Sections & Headings</h2><p class="lead">Edit the badge, title and description shown above each section, plus the name that appears in the top navigation menu.</p>`+FMT_HINT;
  HSECTIONS.forEach(s=>{
    const h = Object.assign({}, DEFAULT_HEADINGS[s.id]||{}, H[s.id]||{});
    html += `<div class="item"><div class="item-head"><b>${s.label}</b></div>`+
      `<label>Menu label (top navigation)</label><input id="hd_${s.id}_nav" value="${escA(h.nav)}">`+
      `<label>Badge (small pill above the title)</label><input id="hd_${s.id}_badge" value="${escA(h.badge)}">`+
      `<label>Title (big heading)</label><input id="hd_${s.id}_title" value="${escA(h.title)}">`+
      `<label>Description (subtitle under the title)</label><textarea id="hd_${s.id}_subtitle">${escA(h.subtitle)}</textarea>`;
    if(s.id==='references'){
      html += `<label>Extra italic note</label><textarea id="hd_references_note">${escA(h.note)}</textarea>`+
        `<label>Mentors line ("I have had the privilege to work with…")</label><textarea id="hd_references_mentors">${escA(h.mentors)}</textarea>`;
    }
    html += `</div>`;
  });
  html += `<div class="bar"><button class="primary" onclick="saveHeadings()">Save Sections & Headings</button></div>`;
  app().innerHTML = html;
}
window.saveHeadings=()=>{
  const out={};
  HSECTIONS.forEach(s=>{
    const o={nav:v('hd_'+s.id+'_nav'),badge:v('hd_'+s.id+'_badge'),title:v('hd_'+s.id+'_title'),subtitle:v('hd_'+s.id+'_subtitle')};
    if(s.id==='references'){ o.note=v('hd_references_note'); o.mentors=v('hd_references_mentors'); }
    out[s.id]=o;
  });
  DATA.headings=out; postSection('headings',out,'Sections & Headings');
};

// ---- Research graph ----
function renderResearch(){
  const r = Object.assign({hubs:['Code Clones','Large Language Models'],leaves:['Clone Detection','Cross-language Clones','Green AI','Clone Refactoring']}, DATA.research||{});
  const h = r.hubs||[], lv = r.leaves||[];
  app().innerHTML = `<h2>Research Graph</h2><p class="lead">A network diagram of your research. The two big circles are your main research areas; the four smaller nodes are related topics they connect to. Keep labels short so they fit.</p>`+
    `<div class="item"><div class="item-head"><b>Main areas (big circles)</b></div>`+
    `<label>Main area 1</label><input id="rg_h0" value="${escA(h[0]||'')}">`+
    `<label>Main area 2</label><input id="rg_h1" value="${escA(h[1]||'')}"></div>`+
    `<div class="item"><div class="item-head"><b>Related topics (small nodes)</b></div>`+
    [0,1,2,3].map(i=>`<label>Topic ${i+1}</label><input class="rg_leaf" value="${escA(lv[i]||'')}">`).join('')+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="saveResearch()">Save Research Graph</button></div>`;
}
window.saveResearch=()=>{
  const hubs=[v('rg_h0'),v('rg_h1')].filter(Boolean);
  const leaves=[...document.querySelectorAll('.rg_leaf')].map(i=>i.value.trim()).filter(Boolean);
  const data={hubs,leaves}; DATA.research=data; postSection('research',data,'Research Graph');
};

// ---- Metrics ----
function renderMetrics(){
  const m = DATA.metrics || {citations:0,hIndex:0,works:0};
  app().innerHTML = `<h2>Research Metrics</h2><p class="lead">Set these to match your Google Scholar profile. They appear in the hero and the Publications section.</p>`+
    `<div class="item">`+
    `<label>Citations</label><input id="m_citations" type="number" value="${escA(m.citations??'')}">`+
    `<label>h-index</label><input id="m_hIndex" type="number" value="${escA(m.hIndex??'')}">`+
    `<label>Works / publications count</label><input id="m_works" type="number" value="${escA(m.works??'')}">`+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="saveMetrics()">Save Metrics</button></div>`+
    `<small>Tip: open your Google Scholar profile, copy the "Cited by" total and h-index here.</small>`;
}
window.saveMetrics=()=>{
  const prev = DATA.metrics||{};
  const data = Object.assign({}, prev, {
    citations: parseInt(document.getElementById('m_citations').value)||0,
    hIndex: parseInt(document.getElementById('m_hIndex').value)||0,
    works: parseInt(document.getElementById('m_works').value)||0,
    source: 'Google Scholar',
    profileUrl: 'https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en',
    updated: new Date().toISOString().slice(0,10)
  });
  DATA.metrics = data; postSection('metrics',data,'Metrics');
};

// ---- Change password ----
function renderPassword(){
  app().innerHTML = `<h2>Change Password</h2><p class="lead">Update your admin login password.</p>`+
    `<div class="item">`+
    `<label>Current password</label><input id="pw_cur" type="password" autocomplete="current-password">`+
    `<label>New password (10+ characters)</label><input id="pw_new" type="password" autocomplete="new-password">`+
    `<label>Confirm new password</label><input id="pw_new2" type="password" autocomplete="new-password">`+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="savePassword()">Update Password</button></div>`;
}
window.savePassword=async()=>{
  const cur=document.getElementById('pw_cur').value, nw=document.getElementById('pw_new').value, nw2=document.getElementById('pw_new2').value;
  if(nw.length<10){toast('New password must be 10+ characters');return;}
  if(nw!==nw2){toast('New passwords do not match');return;}
  try{
    const r=await fetch('index.php?action=changepw',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,current:cur,new:nw})});
    const j=await r.json();
    if(j.ok){ toast('Password updated ✓'); ['pw_cur','pw_new','pw_new2'].forEach(id=>document.getElementById(id).value=''); }
    else toast('Error: '+(j.error||'failed'));
  }catch(e){toast('Network error');}
};

// ---- Hero ----
const DEFAULT_HERO = {greeting:"👋 Hello, I'm",name:"Palash Roy,",line2:"Computer Science",line3:"PhD Researcher",pills:["🔬 Software Engineering Researcher","🤖 LLMs for Software Engineering","🏛️ GSA President"],badge:"Software Engineering Researcher",awardText:"ACM SIGSOFT Distinguished Paper Award",awardMeta:"FSE 2026",description:"I am a Software Engineering researcher at the Software Research Lab (SRLab) and ISELab, working on code clones, clone detection, refactoring, and large language models under Dr. Kevin Schneider. My goal is to turn research into tools that make software better.",stats:[{value:"8+",label:"Publications"},{value:"31+",label:"Citations"},{value:"4.5K+",label:"Students Led"}]};
function renderHero(){
  const h = DATA.hero || DEFAULT_HERO;
  app().innerHTML = `<h2>Home / Hero</h2><p class="lead">The top banner of your website.</p>`+FMT_HINT+
    `<div class="item">`+
    `<label>Greeting</label><input id="h_greeting" value="${escA(h.greeting)}">`+
    `<label>Name (line 1)</label><input id="h_name" value="${escA(h.name)}">`+
    `<label>Title line 2</label><input id="h_line2" value="${escA(h.line2)}">`+
    `<label>Title line 3</label><input id="h_line3" value="${escA(h.line3)}">`+
    `<label>Menu label for Home (top nav)</label><input id="h_navhome" value="${escA(h.navHome||'Home')}">`+
    `<label>Photo badge (the small tag on your photo)</label><input id="h_badge" value="${escA(h.badge)}">`+
    `<label>Short description</label><textarea id="h_desc">${escA(h.description)}</textarea>`+
    `<label>Award badge text (leave empty to hide the badge)</label><input id="h_award" value="${escA(h.awardText)}">`+
    `<label>Award detail (e.g. FSE 2026)</label><input id="h_awardmeta" value="${escA(h.awardMeta)}">`+
    `</div>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Role pills</h3><div id="h_pills">`+(h.pills||[]).map(p=>`<div class="row" data-r><input value="${escA(p)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addRow('h_pills',1)">+ Add pill</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Stats</h3><div id="h_stats">`+(h.stats||[]).map(s=>`<div class="row" data-r><input placeholder="Value" value="${escA(s.value)}" style="width:90px"><input placeholder="Label" value="${escA(s.label)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addRow('h_stats',2)">+ Add stat</button>`+
    `<div class="bar"><button class="primary" onclick="saveHero()">Save Home</button></div>`;
}
window.addRow=(id,cols)=>{const d=document.getElementById(id);const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.innerHTML=(cols===2?'<input placeholder="Value" style="width:90px"><input placeholder="Label" style="flex:1">':'<input style="flex:1">')+'<button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.saveHero=()=>{
  const pills=[...document.querySelectorAll('#h_pills [data-r]')].map(r=>r.querySelector('input').value.trim()).filter(Boolean);
  const stats=[...document.querySelectorAll('#h_stats [data-r]')].map(r=>{const ins=r.querySelectorAll('input');return {value:ins[0].value.trim(),label:ins[1].value.trim()};}).filter(s=>s.value||s.label);
  const data={greeting:v('h_greeting'),name:v('h_name'),line2:v('h_line2'),line3:v('h_line3'),navHome:v('h_navhome'),badge:v('h_badge'),description:v('h_desc'),awardText:v('h_award'),awardMeta:v('h_awardmeta'),pills,stats};
  DATA.hero=data; postSection('hero',data,'Home');
};

// ---- Biography ----
const DEFAULT_ABOUT = {
  paragraphs:[
    "Palash Ranjan Roy (also published as Palash Roy) is a PhD researcher in Computer Science at the University of Saskatchewan, Canada. He is a Software Engineering researcher whose work spans code clones, clone detection, refactoring, and the application of large language models to software engineering. He conducts his research in the Software Research Lab (SRLab) and the Interactive Software Engineering Lab (ISELab) under the supervision of Dr. Kevin Schneider.",
    "His research has been published in flagship software engineering conferences such as ASE, FSE, ICSME, and ESEM. His most recent paper, Carbon-Taxed Transformers, appeared at FSE 2026. He has received several honors for his work, including a Research Excellence Award and a Best Thesis Award.",
    "Beyond his research, Palash served as President of the University of Saskatchewan Graduate Students' Association (2025-26), where he represented more than 4,500 graduate students. He has contributed extensively to university governance as a member of the University Senate, the Provost Search Committee, and several College of Graduate and Postdoctoral Studies and award committees.",
    "Originally from Bangladesh, Palash completed his undergraduate studies at BRAC University before moving to Canada for his graduate studies."
  ],
  glance:[
    {icon:"🔬",text:"Software Engineering researcher at SRLab and ISELab"},
    {icon:"🎓",text:"PhD Researcher in Computer Science, University of Saskatchewan"},
    {icon:"🤖",text:"Works on code clones, clone detection, refactoring, and large language models"},
    {icon:"📄",text:"Newest paper \"Carbon-Taxed Transformers\" (FSE 2026)"},
    {icon:"🏛️",text:"President, Graduate Students' Association (2025-26)"},
    {icon:"🏅",text:"USask Senator and Provost Search Committee member"},
    {icon:"📍",text:"Saskatoon, Canada. Originally from Bangladesh 🇧🇩"}
  ],
  education:[
    {degree:"Ph.D. in Computer Science",place:"University of Saskatchewan | 2024 - Present",note:"In Progress"},
    {degree:"M.Sc. in Computer Science",place:"University of Saskatchewan | 2022 - 2024",note:"Completed"},
    {degree:"B.Sc. in Computer Science",place:"BRAC University | 2018 - 2021",note:"Completed"}
  ],
  expNote:"Currently serving concurrently as Graduate Teaching Assistant, Research Technician, and Graduate Peer Mentor at the University of Saskatchewan.",
  experience:[
    {role:"Graduate Teaching Assistant",place:"University of Saskatchewan | 2022 - Present",description:"Instructed undergraduate students in data structures, programming, and practical computing labs."},
    {role:"Research Technician",place:"iSE & SR Lab, University of Saskatchewan | 2022 - Present",description:"Supporting web-based systems, maintaining CFI equipment, and supporting the SOAR CREATE Program."},
    {role:"Graduate Peer Mentor",place:"University of Saskatchewan | 2026 - Present",description:"Helping new graduate students navigate grad life through the Peer Assisted Learning (PAL) program."},
    {role:"Visiting Research Student",place:"University of Saskatchewan | May 2022 - Aug 2022",description:"Developed multiple software engineering tools and published research in code clones and large language models."}
  ]
};
function renderAbout(){
  const a = DATA.about || DEFAULT_ABOUT;
  app().innerHTML = `<h2>Biography</h2><p class="lead">The About section: paragraphs, at-a-glance list, education and experience.</p>`+FMT_HINT+
    `<h3 style="margin:8px 0 6px;color:#7ec8e3;font-size:14px">Paragraphs</h3><div id="a_paras">`+(a.paragraphs||[]).map(p=>`<div class="row" data-r style="align-items:flex-start"><textarea style="flex:1">${escA(p)}</textarea><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addPara()">+ Add paragraph</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">At a Glance</h3><div id="a_glance">`+(a.glance||[]).map(g=>`<div class="row" data-r><input placeholder="Icon" value="${escA(g.icon)}" style="width:60px"><input placeholder="Text" value="${escA(g.text)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addGlance()">+ Add item</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Education</h3><div id="a_edu">`+(a.education||[]).map(e=>`<div class="item" data-r><input placeholder="Degree" value="${escA(e.degree)}"><input placeholder="Place | Years" value="${escA(e.place)}"><input placeholder="Note (optional)" value="${escA(e.note)}"><button class="del" onclick="this.parentNode.remove()">Remove</button></div>`).join('')+`</div><button onclick="addEdu()">+ Add education</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Experience</h3>`+
    `<label>Intro note</label><textarea id="a_expnote">${escA(a.expNote)}</textarea>`+
    `<div id="a_exp" style="margin-top:8px">`+(a.experience||[]).map(x=>`<div class="item" data-r><input placeholder="Role" value="${escA(x.role)}"><input placeholder="Place | Years" value="${escA(x.place)}"><textarea placeholder="Description">${escA(x.description)}</textarea><button class="del" onclick="this.parentNode.remove()">Remove</button></div>`).join('')+`</div><button onclick="addExp()">+ Add experience</button>`+
    `<div class="bar"><button class="primary" onclick="saveAbout()">Save Biography</button></div>`;
}
window.addEdu=()=>{const d=document.getElementById('a_edu');const el=document.createElement('div');el.className='item';el.setAttribute('data-r','');el.innerHTML='<input placeholder="Degree"><input placeholder="Place | Years"><input placeholder="Note (optional)"><button class="del" onclick="this.parentNode.remove()">Remove</button>';d.appendChild(el);};
window.addExp=()=>{const d=document.getElementById('a_exp');const el=document.createElement('div');el.className='item';el.setAttribute('data-r','');el.innerHTML='<input placeholder="Role"><input placeholder="Place | Years"><textarea placeholder="Description"></textarea><button class="del" onclick="this.parentNode.remove()">Remove</button>';d.appendChild(el);};
window.addPara=()=>{const d=document.getElementById('a_paras');const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.style.alignItems='flex-start';el.innerHTML='<textarea style="flex:1"></textarea><button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.addGlance=()=>{const d=document.getElementById('a_glance');const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.innerHTML='<input placeholder="Icon" style="width:60px"><input placeholder="Text" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.saveAbout=()=>{
  const paragraphs=[...document.querySelectorAll('#a_paras [data-r]')].map(r=>r.querySelector('textarea').value.trim()).filter(Boolean);
  const glance=[...document.querySelectorAll('#a_glance [data-r]')].map(r=>{const ins=r.querySelectorAll('input');return {icon:ins[0].value.trim(),text:ins[1].value.trim()};}).filter(g=>g.text);
  const education=[...document.querySelectorAll('#a_edu [data-r]')].map(r=>{const ins=r.querySelectorAll('input');return {degree:ins[0].value.trim(),place:ins[1].value.trim(),note:ins[2].value.trim()};}).filter(e=>e.degree);
  const experience=[...document.querySelectorAll('#a_exp [data-r]')].map(r=>{const ins=r.querySelectorAll('input');const ta=r.querySelector('textarea');return {role:ins[0].value.trim(),place:ins[1].value.trim(),description:ta?ta.value.trim():''};}).filter(x=>x.role);
  const data={paragraphs,glance,education,expNote:v('a_expnote'),experience};
  DATA.about=data; postSection('about',data,'Biography');
};

// ---- Generic list sections (publications, gallery, …) ----
function fieldHtml(section, idx, key, type, val){
  val = val == null ? '' : String(val);
  if(type==='area') return `<label>${key}</label><textarea data-k="${key}" oninput="mark()">${escA(val)}</textarea>`;
  if(type==='image'){
    const thumb = val ? `<img class="thumb" src="${escA(val)}">` : '';
    return `<div class="imgfield"><label>${key}</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(val)}" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">📤 Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>`+
      `${thumb}</div>`;
  }
  if(type==='url'){
    return `<label>${key} (article link)</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(val)}" placeholder="https://…" oninput="mark()"><button type="button" class="up" onclick="fetchTitle(this)">🔎 Fetch title</button></div>`+
      `<small>Paste the article link, then click Fetch title to auto-fill the Title field from the page.</small>`;
  }
  return `<label>${key}</label><input data-k="${key}" value="${escA(val)}" oninput="mark()">`;
}
function renderSection(){
  if(current==='hero'){ renderHero(); return; }
  if(current==='about'){ renderAbout(); return; }
  if(current==='headings'){ renderHeadings(); return; }
  if(current==='research'){ renderResearch(); return; }
  if(current==='layout'){ renderLayout(); return; }
  if(current==='settings'){ renderSettings(); return; }
  if(current==='metrics'){ renderMetrics(); return; }
  if(current==='password'){ renderPassword(); return; }
  const items = DATA[current] || [];
  app().innerHTML = `<h2>${LABELS[current]}</h2><p class="lead">Add, edit, reorder or remove items. Use ↑ ↓ to change the order.</p>` +
    `<div id="items">` + items.map((it,i)=>itemHtml(current,i,it)).join('') + `</div>` +
    `<div class="bar"><button onclick="addItem()">+ Add ${LABELS[current].replace(/s$/,'')}</button>` +
    `<button class="primary" onclick="save()">Save ${LABELS[current]}</button></div>`;
}
function itemHtml(section,i,it){
  const schema = SCHEMAS[section];
  const fields = Object.entries(schema).map(([k,t])=>fieldHtml(section,i,k,t,it[k])).join('');
  const title = it.title||it.name||it.role||it.date||('Item '+(i+1));
  return `<div class="item" data-i="${i}"><div class="item-head"><b>${((i+1)+'. '+title).replace(/</g,'&lt;')}</b>`+
    `<span class="acts">`+
    `<button class="mv" title="Move up" onclick="moveItem(${i},-1)">↑</button>`+
    `<button class="mv" title="Move down" onclick="moveItem(${i},1)">↓</button>`+
    `<button class="del" onclick="delItem(${i})">Remove</button></span></div>${fields}</div>`;
}
function collect(){
  return [...document.querySelectorAll('#items .item')].map(el=>{
    const o={};
    el.querySelectorAll('[data-k]').forEach(inp=>{ const val=inp.value.trim(); if(val!=='') o[inp.dataset.k]=val; });
    return o;
  });
}
window.addItem = ()=>{ DATA[current]=collect(); DATA[current].push({}); renderSection(); };
window.delItem = i=>{ DATA[current]=collect(); DATA[current].splice(i,1); renderSection(); };
window.moveItem = (i,dir)=>{ DATA[current]=collect(); const j=i+dir; if(j<0||j>=DATA[current].length) return; const a=DATA[current]; [a[i],a[j]]=[a[j],a[i]]; renderSection(); };
window.mark = ()=>{};
window.save = ()=>{ DATA[current]=collect(); postSection(current,DATA[current],LABELS[current]); };

renderNav(); switchTo('hero');
</script></body></html>
