<?php
// Read-only database viewer. Hidden: no link on the site except a tiny dot inside the hidden window.
// Extra password (set on the first visit) is required on top of the normal site login.
require __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page($body) {
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>管理</title><style>'
       . 'body{font:14px system-ui,"Yu Gothic",Meiryo,sans-serif;margin:16px;color:#1c1e21}a{color:#2f6fed}'
       . 'table{border-collapse:collapse;margin:8px 0}td,th{border:1px solid #ccd;padding:3px 7px;vertical-align:top;text-align:left;font-size:13px}th{background:#eef}'
       . 'pre{white-space:pre-wrap;margin:4px 0;max-width:620px;font-size:12px}.m{color:#667;font-size:12px}.w{background:#fff3cd;padding:6px;border-radius:5px}'
       . 'input[type=text],input[type=password]{padding:5px;font:inherit}button{padding:5px 12px;font:inherit}'
       . '</style></head><body>' . $body . '</body></html>';
    exit;
}

$is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

// 1) when the site itself has a password login (VPS version), the site login is required first
if (function_exists('auth_ok')) {
    $sitepw = (string)cfg('password', 'MEIBO_PASSWORD', '');
    if ($sitepw === '' || $sitepw === 'CHANGE-ME-PASSWORD' || !auth_ok($sitepw)) {
        http_response_code(403);
        page('<p>先にサイトにログインしてください。</p>');
    }
}

try {
    $db = db_connect();
} catch (Throwable $e) {
    page('<p class="w">データベースに接続できません。</p>');
}

// 2) separate admin password
$self = basename(__FILE__);
$hash = $db->query("SELECT v FROM settings WHERE k = 'admin_hash'")->fetchColumn();
$secret = (($hash === false) ? '' : $hash) . '|admin';
function atoken($exp, $secret) { return $exp . '.' . hash_hmac('sha256', 'admin|' . $exp, $secret); }
function aok($secret) {
    if (empty($_COOKIE['meibo_admin'])) { return false; }
    $parts = explode('.', (string)$_COOKIE['meibo_admin'], 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int)$parts[0] < time()) { return false; }
    return hash_equals(atoken($parts[0], $secret), (string)$_COOKIE['meibo_admin']);
}
function setck($secret, $is_https) {
    $exp = time() + 7200;
    setcookie('meibo_admin', atoken($exp, $secret), ['expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $is_https]);
}

if (isset($_GET['out'])) {
    setcookie('meibo_admin', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: ' . $self);
    exit;
}

$msg = '';
if ($hash === false) {
    // first visit: choose the admin password
    if (isset($_POST['pw'])) {
        $p1 = (string)$_POST['pw'];
        $p2 = isset($_POST['pw2']) ? (string)$_POST['pw2'] : '';
        if (strlen($p1) < 8) {
            $msg = 'パスワードは8文字以上にしてください。';
        } elseif ($p1 !== $p2) {
            $msg = '2つのパスワードが一致しません。';
        } else {
            $newhash = password_hash($p1, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO settings (k, v) VALUES ('admin_hash', ?)")->execute([$newhash]);
            setck($newhash . '|admin', $is_https);
            header('Location: ' . $self);
            exit;
        }
    }
    page('<h3>管理パスワードを設定</h3>' . ($msg ? '<p class="w">' . h($msg) . '</p>' : '')
       . '<form method="post"><p><input type="password" name="pw" placeholder="パスワード（8文字以上）" autofocus></p>'
       . '<p><input type="password" name="pw2" placeholder="もう一度"></p><p><button>設定</button></p></form>');
}
if (!aok($secret)) {
    if (isset($_POST['pw'])) {
        if (password_verify((string)$_POST['pw'], $hash)) {
            setck($secret, $is_https);
            header('Location: ' . $self);
            exit;
        }
        sleep(1);
        $msg = 'パスワードが違います。';
    }
    page('<h3>管理</h3>' . ($msg ? '<p class="w">' . h($msg) . '</p>' : '')
       . '<form method="post"><p><input type="password" name="pw" placeholder="パスワード" autofocus></p><p><button>ログイン</button></p></form>');
}

// 3) viewer (read only)
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$per = 50;
$pg = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;

if ($t !== '' && !in_array($t, $tables, true)) { $t = ''; }

if ($t === '') {
    $rows = '';
    foreach ($tables as $name) {
        $n = (int)$db->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $name) . '`')->fetchColumn();
        $rows .= '<tr><td><a href="' . h($self) . '?t=' . urlencode($name) . '">' . h($name) . '</a></td><td>' . $n . '</td></tr>';
    }
    page('<h3>データベース（閲覧のみ）</h3><table><tr><th>テーブル</th><th>件数</th></tr>' . $rows . '</table>'
       . '<p class="m"><a href="' . h($self) . '?out=1">ログアウト</a></p>');
}

$tq = '`' . str_replace('`', '', $t) . '`';
$cols = [];
foreach ($db->query('SHOW COLUMNS FROM ' . $tq)->fetchAll() as $c) { $cols[] = $c['Field']; }
$parts = [];
foreach ($cols as $c) { $parts[] = "IFNULL(CAST(`" . str_replace('`', '', $c) . "` AS CHAR), '')"; }
$where = '';
$params = [];
if ($q !== '') {
    $where = " WHERE CONCAT_WS(' ', " . implode(', ', $parts) . ") LIKE ?";
    $params[] = '%' . $q . '%';
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_]/', '', $t) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cols);
    $st = $db->prepare('SELECT * FROM ' . $tq . $where . ' ORDER BY 1 DESC');
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_NUM)) { fputcsv($out, $r); }
    fclose($out);
    exit;
}

$st = $db->prepare('SELECT COUNT(*) FROM ' . $tq . $where);
$st->execute($params);
$total = (int)$st->fetchColumn();
$off = ($pg - 1) * $per;
$st = $db->prepare('SELECT * FROM ' . $tq . $where . ' ORDER BY 1 DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$off);
$st->execute($params);

$html = '<p><a href="' . h($self) . '">← テーブル一覧</a></p><h3>' . h($t) . '（' . $total . '件）</h3>'
      . '<form method="get"><input type="hidden" name="t" value="' . h($t) . '"><input type="text" name="q" value="' . h($q) . '" placeholder="検索"> <button>検索</button> '
      . '<a href="' . h($self) . '?t=' . urlencode($t) . '&q=' . urlencode($q) . '&csv=1">CSV</a></form>'
      . '<table><tr>';
foreach ($cols as $c) { $html .= '<th>' . h($c) . '</th>'; }
$html .= '</tr>';
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $html .= '<tr>';
    foreach ($r as $v) {
        if ($v === null) { $html .= '<td class="m">NULL</td>'; continue; }
        $v = (string)$v;
        if (mb_strlen($v) > 90) {
            $html .= '<td><details><summary>' . h(mb_substr($v, 0, 70)) . '…</summary><pre>' . h($v) . '</pre></details></td>';
        } else {
            $html .= '<td>' . h($v) . '</td>';
        }
    }
    $html .= '</tr>';
}
$html .= '</table>';
$last = max(1, (int)ceil($total / $per));
$base = h($self) . '?t=' . urlencode($t) . '&q=' . urlencode($q) . '&p=';
$html .= '<p>';
if ($pg > 1) { $html .= '<a href="' . $base . ($pg - 1) . '">← 前へ</a> '; }
$html .= $pg . ' / ' . $last;
if ($pg < $last) { $html .= ' <a href="' . $base . ($pg + 1) . '">次へ →</a>'; }
$html .= '</p><p class="m"><a href="' . h($self) . '?out=1">ログアウト</a></p>';
page($html);
