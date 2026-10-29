<?php
require __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

// This page does not exist (404) for anyone who does not know the key in the address.
if (!isset($_GET['k']) || !hash_equals(admin_key(), (string)$_GET['k'])) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adm_https() { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function adm_token($exp, $secret) { return $exp . '.' . hash_hmac('sha256', 'admin|' . $exp, $secret); }
function adm_ok($secret) {
    if (empty($_COOKIE['meibo_admin'])) { return false; }
    $p = explode('.', (string)$_COOKIE['meibo_admin'], 2);
    if (count($p) !== 2 || !ctype_digit($p[0]) || (int)$p[0] < time()) { return false; }
    return hash_equals(adm_token($p[0], $secret), (string)$_COOKIE['meibo_admin']);
}
function adm_cookie($secret) {
    $exp = time() + 7200;
    setcookie('meibo_admin', adm_token($exp, $secret), ['expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => adm_https()]);
}
function page($body) {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>.</title>'
       . '<style>body{font:14px system-ui,sans-serif;margin:16px;color:#222}table{border-collapse:collapse;font-size:12px}td,th{border:1px solid #ccc;padding:3px 6px;vertical-align:top;text-align:left}th{background:#eee;position:sticky;top:0}'
       . 'input,button{font:inherit;padding:4px 8px}.n{color:#999}pre{white-space:pre-wrap;margin:4px 0;max-width:620px}details summary{cursor:pointer}.err{color:#b00}.sm{color:#777;font-size:12px}a{color:#2a5bd7}</style></head><body>' . $body . '</body></html>';
}

try { $db = db_connect(); } catch (Throwable $e) { http_response_code(503); echo 'DB error'; exit; }
if (admin_session($db) === null) { http_response_code(404); echo 'Not Found'; exit; }
$db->exec("CREATE TABLE IF NOT EXISTS admin_settings (k VARCHAR(32) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
function setting($db, $k) {
    $st = $db->prepare('SELECT v FROM admin_settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function put($db, $k, $v) { $db->prepare('REPLACE INTO admin_settings (k, v) VALUES (?, ?)')->execute([$k, (string)$v]); }
function locked($db) { return (int)setting($db, 'fails') >= 5 && time() - (int)setting($db, 'fail_ts') < 600; }
function failed($db) {
    $f = (int)setting($db, 'fails');
    if (time() - (int)setting($db, 'fail_ts') >= 600) { $f = 0; }
    put($db, 'fails', $f + 1);
    put($db, 'fail_ts', time());
}

$key = urlencode((string)$_GET['k']);
$self = basename(__FILE__) . '?k=' . $key;
$secret = setting($db, 'secret');
if ($secret === null) { $secret = bin2hex(random_bytes(32)); put($db, 'secret', $secret); }
$hash = setting($db, 'pw_hash');
$post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$msg = '';

// first visit: create the password of this page
if ($hash === null) {
    if ($post && isset($_POST['new'])) {
        $a = (string)$_POST['new'];
        $b = isset($_POST['new2']) ? (string)$_POST['new2'] : '';
        if (strlen($a) < 8) { $msg = 'Минимум 8 знаков.'; }
        elseif ($a !== $b) { $msg = 'Пароли не совпадают.'; }
        else {
            put($db, 'pw_hash', password_hash($a, PASSWORD_DEFAULT));
            adm_cookie($secret);
            header('Location: ' . $self);
            exit;
        }
    }
    page('<h3>Создайте пароль для этой страницы</h3><p class="err">' . h($msg) . '</p><form method="post" action="' . h($self) . '"><p><input type="password" name="new" placeholder="пароль (от 8 знаков)" autofocus></p><p><input type="password" name="new2" placeholder="повторите пароль"></p><p><button>Сохранить</button></p></form>');
    exit;
}

if (isset($_GET['logout'])) {
    setcookie('meibo_admin', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: ' . $self);
    exit;
}

if ($post && isset($_POST['pw'])) {
    if (locked($db)) { $msg = 'Слишком много попыток. Подождите 10 минут.'; }
    elseif (password_verify((string)$_POST['pw'], $hash)) {
        put($db, 'fails', 0);
        adm_cookie($secret);
        header('Location: ' . $self);
        exit;
    } else {
        failed($db);
        sleep(2);
        $msg = 'Неверный пароль.';
    }
}

if (!adm_ok($secret)) {
    page('<h3>Вход</h3><p class="err">' . h($msg) . '</p><form method="post" action="' . h($self) . '"><p><input type="password" name="pw" placeholder="пароль" autofocus> <button>Войти</button></p></form>');
    exit;
}

// change password
if ($post && isset($_POST['chg'])) {
    $cur = isset($_POST['cur']) ? (string)$_POST['cur'] : '';
    $a = isset($_POST['new']) ? (string)$_POST['new'] : '';
    $b = isset($_POST['new2']) ? (string)$_POST['new2'] : '';
    if (!password_verify($cur, $hash)) { $msg = 'Текущий пароль неверный.'; }
    elseif (strlen($a) < 8) { $msg = 'Новый пароль: минимум 8 знаков.'; }
    elseif ($a !== $b) { $msg = 'Новые пароли не совпадают.'; }
    else {
        put($db, 'pw_hash', password_hash($a, PASSWORD_DEFAULT));
        $secret = bin2hex(random_bytes(32));
        put($db, 'secret', $secret);
        adm_cookie($secret);
        $msg = 'Пароль изменён.';
    }
}

// tables (admin_settings is never shown)
$tables = [];
foreach ($db->query('SHOW TABLES') as $r) {
    $name = array_values($r)[0];
    if ($name !== 'admin_settings') { $tables[] = $name; }
}
$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (!in_array($t, $tables, true)) { $t = ''; }

if ($t === '') {
    $rows = '';
    foreach ($tables as $tb) {
        $c = $db->query('SELECT COUNT(*) FROM `' . $tb . '`')->fetchColumn();
        $rows .= '<tr><td><a href="' . h($self . '&t=' . urlencode($tb)) . '">' . h($tb) . '</a></td><td>' . (int)$c . '</td></tr>';
    }
    page('<h3>База данных (только чтение)</h3><p class="err">' . h($msg) . '</p><table><tr><th>Таблица</th><th>Строк</th></tr>' . $rows . '</table>'
        . '<p class="sm"><a href="' . h($self . '&logout=1') . '">Выйти</a></p>'
        . '<details><summary class="sm">Сменить пароль</summary><form method="post" action="' . h($self) . '"><input type="hidden" name="chg" value="1"><p><input type="password" name="cur" placeholder="текущий пароль"></p><p><input type="password" name="new" placeholder="новый (от 8 знаков)"></p><p><input type="password" name="new2" placeholder="повторите новый"></p><p><button>Сменить</button></p></form></details>');
    exit;
}

// one table
$cols = [];
foreach ($db->query('SHOW COLUMNS FROM `' . $t . '`') as $r) { $cols[] = $r['Field']; }
$order = '';
foreach (['id', 'saved_at', 'd', 'from_date'] as $c) {
    if (in_array($c, $cols, true)) { $order = ' ORDER BY `' . $c . '` DESC'; break; }
}
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$where = '';
$args = [];
if ($q !== '') {
    $parts = [];
    foreach ($cols as $c) { $parts[] = 'CAST(`' . $c . '` AS CHAR)'; }
    $where = " WHERE CONCAT_WS(' ', " . implode(', ', $parts) . ') LIKE ?';
    $args[] = '%' . $q . '%';
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $t . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);
    $st = $db->prepare('SELECT * FROM `' . $t . '`' . $where . $order . ' LIMIT 0, 200000');
    $st->execute($args);
    while ($r = $st->fetch(PDO::FETCH_NUM)) { fputcsv($out, $r); }
    exit;
}

function cell($v) {
    if ($v === null) { return '<span class="n">NULL</span>'; }
    $s = (string)$v;
    if (strlen($s) > 100) {
        $pretty = $s;
        $j = json_decode($s);
        if (is_array($j) || is_object($j)) { $pretty = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); }
        $short = function_exists('mb_substr') ? mb_substr($s, 0, 60, 'UTF-8') : substr($s, 0, 60);
        return '<details><summary>' . h($short) . '…</summary><pre>' . h($pretty) . '</pre></details>';
    }
    return h($s);
}

$per = 50;
$p = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$st = $db->prepare('SELECT COUNT(*) FROM `' . $t . '`' . $where);
$st->execute($args);
$total = (int)$st->fetchColumn();
$st = $db->prepare('SELECT * FROM `' . $t . '`' . $where . $order . ' LIMIT ' . (($p - 1) * $per) . ', ' . $per);
$st->execute($args);
$head = '';
foreach ($cols as $c) { $head .= '<th>' . h($c) . '</th>'; }
$body = '';
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $body .= '<tr>';
    foreach ($r as $v) { $body .= '<td>' . cell($v) . '</td>'; }
    $body .= '</tr>';
}
$base = $self . '&t=' . urlencode($t) . '&q=' . urlencode($q);
$pages = (int)ceil($total / $per);
$nav = '';
if ($p > 1) { $nav .= '<a href="' . h($base . '&p=' . ($p - 1)) . '">← назад</a> '; }
$nav .= 'стр. ' . $p . ' из ' . max(1, $pages) . ' ';
if ($p < $pages) { $nav .= '<a href="' . h($base . '&p=' . ($p + 1)) . '">вперёд →</a>'; }
page('<p><a href="' . h($self) . '">← все таблицы</a></p><h3>' . h($t) . ' <span class="sm">(' . $total . ' строк, только чтение)</span></h3>'
    . '<form method="get" action="' . h(basename(__FILE__)) . '"><input type="hidden" name="k" value="' . h((string)$_GET['k']) . '"><input type="hidden" name="t" value="' . h($t) . '"><input type="text" name="q" value="' . h($q) . '" placeholder="поиск"> <button>Найти</button> <a href="' . h($base . '&csv=1') . '">скачать CSV</a></form>'
    . '<p class="sm">' . $nav . '</p><table><tr>' . $head . '</tr>' . $body . '</table><p class="sm">' . $nav . '</p>');
