<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($a, $code = 200) {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$a = isset($_GET['a']) ? $_GET['a'] : '';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = []; }

try {
    $db = db_connect();
} catch (Throwable $e) {
    out(['error' => 'db'], 503);
}

try {
    // ---------- actions that do not need a login ----------
    if ($a === 'register') {
        $name = trim((string)(isset($in['u']) ? $in['u'] : ''));
        $pass = (string)(isset($in['p']) ? $in['p'] : '');
        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($len < 2 || $len > 32 || preg_match('/\s/u', $name)) { out(['ok' => false, 'error' => 'name']); }
        if (strlen($pass) < 8) { out(['ok' => false, 'error' => 'weak']); }
        $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$name]);
        if ((int)$st->fetchColumn() > 0) { out(['ok' => false, 'error' => 'exists']); }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        // the very first account becomes the administrator and is active at once (atomic)
        $ins = $db->prepare("INSERT INTO users (username, pass_hash, role, status) SELECT ?, ?, 'admin', 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users)");
        $ins->execute([$name, $hash]);
        if ($ins->rowCount() === 1) {
            start_session($db, (int)$db->lastInsertId());
            out(['ok' => true, 'status' => 'active', 'role' => 'admin']);
        }
        // everybody else waits until the administrator approves (once)
        $db->prepare("INSERT INTO users (username, pass_hash, role, status) VALUES (?, ?, 'user', 'pending')")->execute([$name, $hash]);
        out(['ok' => true, 'status' => 'pending', 'role' => 'user']);
    }

    if ($a === 'login') {
        $name = trim((string)(isset($in['u']) ? $in['u'] : ''));
        $pass = (string)(isset($in['p']) ? $in['p'] : '');
        $st = $db->prepare('SELECT id, pass_hash, role, status, fails, fail_ts FROM users WHERE username = ?');
        $st->execute([$name]);
        $u = $st->fetch();
        if ($u !== false && (int)$u['fails'] >= 8 && time() - (int)$u['fail_ts'] < 600) { out(['ok' => false, 'error' => 'locked']); }
        if ($u === false || !password_verify($pass, $u['pass_hash'])) {
            if ($u !== false) {
                $f = (time() - (int)$u['fail_ts'] >= 600) ? 1 : (int)$u['fails'] + 1;
                $db->prepare('UPDATE users SET fails = ?, fail_ts = ? WHERE id = ?')->execute([$f, time(), (int)$u['id']]);
            }
            sleep(1);
            out(['ok' => false, 'error' => 'wrong']);
        }
        if ($u['status'] === 'pending') { out(['ok' => false, 'error' => 'pending']); }
        if ($u['status'] !== 'active') { out(['ok' => false, 'error' => 'blocked']); }
        $db->prepare('UPDATE users SET fails = 0, last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$u['id']]);
        start_session($db, (int)$u['id']);
        out(['ok' => true, 'user' => ['username' => $name, 'role' => $u['role']]]);
    }

    if ($a === 'logout') {
        if (!empty($_COOKIE['meibo_sid']) && is_string($_COOKIE['meibo_sid'])) {
            $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $_COOKIE['meibo_sid'])]);
        }
        setcookie('meibo_sid', '', ['expires' => time() - 3600, 'path' => '/']);
        out(['ok' => true]);
    }

    // ---------- everything below needs an approved, logged-in user ----------
    $user = current_user($db);
    if ($user === null) { out(['error' => 'auth'], 401); }
    $isAdmin = ($user['role'] === 'admin');

    if ($a === 'me') {
        out(['ok' => true, 'user' => ['username' => $user['username'], 'role' => $user['role']]]);
    }

    switch ($a) {

    case 'load':
        $people = [];
        foreach ($db->query('SELECT id, data, rev FROM people') as $r) {
            $people[] = ['id' => $r['id'], 'rev' => (int)$r['rev'], 'data' => $r['data']];
        }
        $staff = [];
        foreach ($db->query('SELECT name FROM staff ORDER BY pos') as $r) {
            $staff[] = $r['name'];
        }
        $days = [];
        foreach ($db->query('SELECT d, seq, rev, data FROM days ORDER BY d') as $r) {
            $days[] = ['d' => $r['d'], 'seq' => (int)$r['seq'], 'rev' => (int)$r['rev'], 'data' => $r['data']];
        }
        $counter = (int)$db->query("SELECT v FROM meta WHERE k = 'counter'")->fetchColumn();
        out(['people' => $people, 'staff' => $staff, 'days' => $days, 'counter' => $counter]);

    case 'save_person':
        $id = (string)(isset($in['id']) ? $in['id'] : '');
        $data = (string)(isset($in['data']) ? $in['data'] : '');
        $rev = (int)(isset($in['rev']) ? $in['rev'] : 0);
        $force = !empty($in['force']);
        if ($id === '' || json_decode($data) === null) { out(['error' => 'bad'], 400); }
        $db->beginTransaction();
        $st = $db->prepare('SELECT rev FROM people WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            $db->prepare('INSERT INTO people (id, data, rev) VALUES (?, ?, 1)')->execute([$id, $data]);
            $new = 1;
        } elseif (!$force && (int)$cur !== $rev) {
            $db->rollBack();
            out(['conflict' => true]);
        } else {
            $new = (int)$cur + 1;
            $db->prepare('UPDATE people SET data = ?, rev = ? WHERE id = ?')->execute([$data, $new, $id]);
        }
        $db->prepare('INSERT INTO people_history (person_id, rev, data, deleted) VALUES (?, ?, ?, 0)')->execute([$id, $new, $data]);
        $db->commit();
        mirror_person($db, $id, $data);
        out(['ok' => true, 'rev' => $new]);

    case 'delete_person':
        $id = (string)(isset($in['id']) ? $in['id'] : '');
        $st = $db->prepare('SELECT data, rev FROM people WHERE id = ?');
        $st->execute([$id]);
        $last = $st->fetch();
        if ($last !== false) {
            $db->prepare('INSERT INTO people_history (person_id, rev, data, deleted) VALUES (?, ?, ?, 1)')->execute([$id, (int)$last['rev'] + 1, $last['data']]);
        }
        $db->prepare('DELETE FROM person_settings WHERE person_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM person_absence WHERE person_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
        $db->prepare('DELETE FROM attendance WHERE person_id = ?')->execute([$id]);
        out(['ok' => true]);

    case 'save_staff':
        $names = (isset($in['names']) && is_array($in['names'])) ? array_values($in['names']) : [];
        $db->beginTransaction();
        $db->exec('DELETE FROM staff');
        $ins = $db->prepare('INSERT INTO staff (pos, name) VALUES (?, ?)');
        foreach ($names as $i => $nm) { $ins->execute([$i, (string)$nm]); }
        $db->commit();
        out(['ok' => true]);

    case 'save_day':
        $d = (string)(isset($in['d']) ? $in['d'] : '');
        $data = (string)(isset($in['data']) ? $in['data'] : '');
        $rev = (int)(isset($in['rev']) ? $in['rev'] : 0);
        $force = !empty($in['force']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || json_decode($data) === null) { out(['error' => 'bad'], 400); }
        $db->beginTransaction();
        $st = $db->prepare('SELECT rev, seq FROM days WHERE d = ? FOR UPDATE');
        $st->execute([$d]);
        $row = $st->fetch();
        if ($row === false) {
            // first save of this date: take the next sheet number 1,2,3,4,1,... atomically
            $db->exec("UPDATE meta SET v = LAST_INSERT_ID(v + 1) WHERE k = 'counter'");
            $n = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $seq = (($n - 1) % 4) + 1;
            $new = 1;
            $db->prepare('INSERT INTO days (d, seq, data, rev) VALUES (?, ?, ?, 1)')->execute([$d, $seq, $data]);
        } else {
            if (!$force && (int)$row['rev'] !== $rev) {
                $db->rollBack();
                out(['conflict' => true]);
            }
            $seq = (int)$row['seq'];
            $new = (int)$row['rev'] + 1;
            $db->prepare('UPDATE days SET data = ?, rev = ? WHERE d = ?')->execute([$data, $new, $d]);
        }
        $db->prepare('INSERT INTO days_history (d, rev, seq, data) VALUES (?, ?, ?, ?)')->execute([$d, $new, $seq, $data]);
        $db->commit();
        mirror_day($db, $d, $data);
        out(['ok' => true, 'rev' => $new, 'seq' => $seq]);

    case 'log':
        $es = (isset($in['entries']) && is_array($in['entries'])) ? $in['entries'] : [];
        $ins = $db->prepare('INSERT INTO log (ts, type, target, field, old_v, new_v, d) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $db->beginTransaction();
        foreach ($es as $e) {
            $ins->execute([
                (string)(isset($e['ts']) ? $e['ts'] : ''),
                (string)(isset($e['type']) ? $e['type'] : ''),
                (string)(isset($e['id']) ? $e['id'] : ''),
                (string)(isset($e['f']) ? $e['f'] : ''),
                json_encode(isset($e['o']) ? $e['o'] : null, JSON_UNESCAPED_UNICODE),
                json_encode(isset($e['n']) ? $e['n'] : null, JSON_UNESCAPED_UNICODE),
                (string)(isset($e['date']) ? $e['date'] : '')
            ]);
        }
        $db->commit();
        out(['ok' => true]);

    case 'comment_add':
        // every user may write; only the administrator can read (see comments_list)
        $text = trim((string)(isset($in['text']) ? $in['text'] : ''));
        if ($text === '') { out(['ok' => false]); }
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 2000, 'UTF-8') : substr($text, 0, 2000);
        $db->prepare('INSERT INTO feedback (user_id, username, body) VALUES (?, ?, ?)')->execute([(int)$user['id'], $user['username'], $text]);
        out(['ok' => true]);

    case 'users_list':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $list = [];
        foreach ($db->query('SELECT id, username, role, status, created_at, last_login FROM users ORDER BY id') as $r) { $list[] = $r; }
        out(['users' => $list]);

    case 'user_set':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $id = (int)(isset($in['id']) ? $in['id'] : 0);
        $op = (string)(isset($in['op']) ? $in['op'] : '');
        if ($op === 'approve') {
            $db->prepare("UPDATE users SET status = 'active', fails = 0 WHERE id = ?")->execute([$id]);
        } elseif ($op === 'block') {
            $db->prepare("UPDATE users SET status = 'blocked' WHERE id = ? AND role <> 'admin'")->execute([$id]);
            $db->prepare("DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.id = ? AND u.role <> 'admin'")->execute([$id]);
        } elseif ($op === 'delete') {
            $db->prepare("DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.id = ? AND u.role <> 'admin'")->execute([$id]);
            $db->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'")->execute([$id]);
        }
        out(['ok' => true]);

    case 'comments_list':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $c = [];
        foreach ($db->query('SELECT id, username, body, created_at FROM feedback ORDER BY id DESC LIMIT 500') as $r) { $c[] = $r; }
        out(['comments' => $c]);

    case 'comment_delete':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $db->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int)(isset($in['id']) ? $in['id'] : 0)]);
        out(['ok' => true]);

    case 'db_test':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $ver = (string)$db->query('SELECT VERSION()')->fetchColumn();
        $tc = (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        out(['ok' => true, 'version' => $ver, 'db' => (string)cfg('db_name', 'DB_NAME', 'meibo'), 'host' => (string)cfg('db_host', 'DB_HOST', 'localhost'), 'tables' => $tc]);

    case 'admin_link':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        out(['url' => 'kanri.php?k=' . admin_key()]);

    default:
        out(['error' => 'unknown'], 400);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    out(['error' => $e->getMessage()], 500);
}
