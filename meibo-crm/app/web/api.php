<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const EDIT_WINDOW = 300; // a worker may correct his own value for 5 minutes after it was saved

function out($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function is_https() { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function current_user($db) {
    if (empty($_COOKIE['crm_sid']) || !is_string($_COOKIE['crm_sid'])) return null;
    $st = $db->prepare("SELECT u.id, u.username, u.role, u.staff_name FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND u.status = 'active'");
    $st->execute([hash('sha256', $_COOKIE['crm_sid'])]);
    $u = $st->fetch();
    return $u === false ? null : $u;
}
// fields a worker may fill in (the same structure as the sheet). Keys are checked here, not trusted from the page.
function allowed_field($f) {
    $fixed = ['send','note','lastBm','bmDays','bpU','bpL','pul','kt','oyatsu','rinyaku','bath','wash'];
    if (in_array($f, $fixed, true)) return true;
    if (preg_match('/^meal\.(朝|昼|夕)\.(a|b|x)$/u', $f)) return true;
    if (preg_match('/^med\.(朝|昼|夕|翌朝|貼り薬|寝る前)$/u', $f)) return true;
    if (preg_match('/^oral\.(朝|昼|夕)$/u', $f)) return true;
    if (preg_match('/^seiyo\.(髭剃り|爪切り)$/u', $f)) return true;
    if (preg_match('/^act\.[1-7]$/', $f)) return true;
    if (preg_match('/^bill\.(電気器具|洗濯)$/u', $f)) return true;
    return false;
}

function sync_from_meibo($db, $today) {
    $url = crm_cfg('MEIBO_URL', 'MEIBO_URL', '');
    $tok = crm_cfg('MEIBO_TOKEN', 'MEIBO_TOKEN', '');
    if ($url === '' || $tok === '' || $tok === 'CHANGE-ME-READ-TOKEN') return 'off';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
        'header' => "X-Read-Token: " . $tok . "\r\n"]]);
    $raw = @file_get_contents(rtrim($url, '/') . '/api_read.php?day=' . urlencode($today), false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['ok']) || !isset($j['people']) || !is_array($j['people'])) return 'error';
    $db->beginTransaction();
    $db->prepare('DELETE FROM day_people WHERE day = ?')->execute([$today]);
    $ins = $db->prepare('INSERT INTO day_people (day, person_key, name, kind) VALUES (?, ?, ?, ?)');
    foreach ($j['people'] as $p) {
        $ins->execute([$today, (string)$p['person_key'], mb_substr((string)$p['name'], 0, 255), $p['kind'] === 'visit' ? 'visit' : 'regular']);
    }
    $db->commit();
    return 'ok';
}
$db = crm_db();
$a = isset($_GET['a']) ? $_GET['a'] : '';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$me = current_user($db);
$today = date('Y-m-d');

if ($a === 'register') {
    $name = trim((string)($in['u'] ?? '')); $pass = (string)($in['p'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 32 || preg_match('/\s/u', $name)) out(['ok'=>false,'error'=>'name'], 400);
    if (strlen($pass) < 8) out(['ok'=>false,'error'=>'weak'], 400);
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?'); $st->execute([$name]);
    if ((int)$st->fetchColumn() > 0) out(['ok'=>false,'error'=>'exists'], 409);
    // the very first account becomes the administrator; everybody else waits for approval
    $first = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    $db->prepare('INSERT INTO users (username, pass_hash, role, status) VALUES (?, ?, ?, ?)')
       ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), $first ? 'admin' : 'worker', $first ? 'active' : 'pending']);
    out(['ok'=>true, 'status' => $first ? 'active' : 'pending']);
}
if ($a === 'login') {
    $st = $db->prepare('SELECT id, pass_hash, status FROM users WHERE username = ?');
    $st->execute([trim((string)($in['u'] ?? ''))]); $u = $st->fetch();
    if ($u === false || !password_verify((string)($in['p'] ?? ''), $u['pass_hash'])) { sleep(1); out(['ok'=>false,'error'=>'wrong'], 401); }
    if ($u['status'] !== 'active') out(['ok'=>false,'error'=>$u['status']], 403);
    $tok = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, user_id) VALUES (?, ?)')->execute([hash('sha256', $tok), $u['id']]);
    setcookie('crm_sid', $tok, ['expires' => time() + 10*365*86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    out(['ok'=>true]);
}
if ($a === 'logout') {
    if (!empty($_COOKIE['crm_sid'])) $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $_COOKIE['crm_sid'])]);
    setcookie('crm_sid', '', ['expires' => time()-3600, 'path' => '/']);
    out(['ok'=>true]);
}
if (!$me) out(['ok'=>false,'error'=>'auth'], 401);
$isAdmin = $me['role'] === 'admin';

if ($a === 'me') out(['ok'=>true, 'user'=>['username'=>$me['username'],'role'=>$me['role'],'staff_name'=>$me['staff_name']], 'day'=>$today]);

// today's people: only those who came (通い / 泊まり) and home visits (訪問); a worker sees nothing else
if ($a === 'people') {
    $source = sync_from_meibo($db, $today);
    $st = $db->prepare('SELECT person_key, name, kind FROM day_people WHERE day = ? ORDER BY kind, name');
    $st->execute([$today]);
    $rows = $st->fetchAll();
    // a worker who is not on today's list may only look, so the list is still shown but read-only for him
    $onList = false;
    foreach ($rows as $r) if ($r['name'] === $me['staff_name']) $onList = true;
    out(['ok'=>true, 'source'=>$source, 'people'=>$rows, 'can_write'=>($isAdmin || ($me['staff_name'] !== '' && $onList))]);
}

if ($a === 'entries') {
    $st = $db->prepare('SELECT person_key, field, value, set_at, set_by FROM entries WHERE day = ? AND person_key = ?');
    $st->execute([$today, (string)($in['person'] ?? '')]);
    out(['ok'=>true, 'entries'=>$st->fetchAll()]);
}

if ($a === 'save') {
    $person = (string)($in['person'] ?? ''); $field = (string)($in['field'] ?? ''); $value = (string)($in['value'] ?? '');
    if (!allowed_field($field)) out(['ok'=>false,'error'=>'field'], 400);
    $st = $db->prepare('SELECT name, kind FROM day_people WHERE day = ? AND person_key = ?'); $st->execute([$today, $person]);
    $p = $st->fetch();
    if ($p === false) out(['ok'=>false,'error'=>'not_today'], 403);
    // worker: only the person who is linked to his account (staff_name) and only when the cell is empty or within the 5-minute window
    if (!$isAdmin) {
        if ($me['staff_name'] === '' || $me['staff_name'] !== $p['name']) out(['ok'=>false,'error'=>'not_yours'], 403);
    }
    $st = $db->prepare('SELECT value, set_at, set_by FROM entries WHERE day = ? AND person_key = ? AND field = ?');
    $st->execute([$today, $person, $field]); $old = $st->fetch();
    if (!$isAdmin && $old !== false && $old['value'] !== '') {
        $own_recent = (int)$old['set_by'] === (int)$me['id'] && (time() - (int)$old['set_at']) <= EDIT_WINDOW;
        if (!$own_recent) out(['ok'=>false,'error'=>'locked'], 403);
    }
    $db->prepare('INSERT INTO entries (day, person_key, field, value, set_by, set_at) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value), set_by = VALUES(set_by), set_at = VALUES(set_at)')
       ->execute([$today, $person, $field, $value, (int)$me['id'], time()]);
    out(['ok'=>true]);
}

// the sheet of today as meibo draws it: meibo's full snapshot (read-only) + worker entries merged into it for printing only
if ($a === 'sheet') {
    $url = crm_cfg('MEIBO_URL', 'MEIBO_URL', ''); $tok = crm_cfg('MEIBO_TOKEN', 'MEIBO_TOKEN', '');
    if ($url === '' || $tok === '' || $tok === 'CHANGE-ME-READ-TOKEN') out(['ok'=>false,'error'=>'meibo_off'], 503);
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true,
        'header' => "X-Read-Token: " . $tok . "\r\n"]]);
    $raw = @file_get_contents(rtrim($url, '/') . '/api_read.php?full=1&day=' . urlencode($today), false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['ok'])) out(['ok'=>false,'error'=>'meibo_read'], 503);
    // worker entries of today, grouped by person name (the key is the same as in the people list)
    $ent = [];
    $st = $db->prepare('SELECT person_key, field, value FROM entries WHERE day = ?'); $st->execute([$today]);
    $byKey = [];
    foreach ($st->fetchAll() as $e) { $byKey[$e['person_key']][$e['field']] = $e['value']; }
    foreach ((isset($j['snapshot']['people']) ? $j['snapshot']['people'] : []) as $p) {
        $name = '';
        foreach ((isset($p['v']) ? $p['v'] : []) as $ver) { if (isset($ver['s']['name'])) $name = $ver['s']['name']; }
        $key = md5($today . '|' . $name);
        if (isset($byKey[$key])) $ent[$name] = $byKey[$key];
    }
    out(['ok'=>true, 'day'=>$today, 'snapshot'=>$j['snapshot'], 'entries'=>(object)$ent]);
}

// administrator only: approve accounts, link a worker to his name, manage today's people
if ($isAdmin && $a === 'users') {
    out(['ok'=>true, 'users'=>$db->query('SELECT id, username, role, status, staff_name FROM users ORDER BY id')->fetchAll()]);
}
if ($isAdmin && $a === 'user_set') {
    $id = (int)($in['id'] ?? 0);
    if (isset($in['status'])) $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([(string)$in['status'], $id]);
    if (isset($in['staff_name'])) $db->prepare('UPDATE users SET staff_name = ? WHERE id = ?')->execute([mb_substr(trim((string)$in['staff_name']),0,255), $id]);
    out(['ok'=>true]);
}
if ($isAdmin && $a === 'day_add') {
    $name = trim((string)($in['name'] ?? '')); $kind = ($in['kind'] ?? '') === 'visit' ? 'visit' : 'regular';
    if ($name === '') out(['ok'=>false], 400);
    $db->prepare('INSERT IGNORE INTO day_people (day, person_key, name, kind) VALUES (?, ?, ?, ?)')
       ->execute([$today, md5($today.'|'.$name), $name, $kind]);
    out(['ok'=>true]);
}
out(['ok'=>false,'error'=>'unknown'], 400);
