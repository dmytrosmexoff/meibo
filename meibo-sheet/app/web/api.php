<?php
require __DIR__ . '/db.php';
require __DIR__ . '/sync.php';
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
        $ins = $db->prepare("INSERT INTO users (username, pass_hash, role, status, upd) SELECT ?, ?, 'admin', 'active', ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users)");
        $ins->execute([$name, $hash, now_ms()]);
        if ($ins->rowCount() === 1) {
            start_session($db, (int)$db->lastInsertId());
            out(['ok' => true, 'status' => 'active', 'role' => 'admin']);
        }
        // everybody else waits until the administrator approves (once)
        $db->prepare("INSERT INTO users (username, pass_hash, role, status, upd) VALUES (?, ?, 'user', 'pending', ?)")->execute([$name, $hash, now_ms()]);
        out(['ok' => true, 'status' => 'pending', 'role' => 'user']);
    }

    if ($a === 'import_sql') {
        // first run only: a ready SQL dump (for example an export of another copy) can be loaded before the first account exists
        if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { out(['ok' => false, 'msg' => 'Загрузка возможна только до регистрации первого аккаунта.'], 403); }
        if (empty($_FILES['f']['tmp_name']) || !is_uploaded_file($_FILES['f']['tmp_name'])) { out(['ok' => false, 'msg' => 'Файл не получен. Допустимый размер — до 64 МБ.'], 400); }
        $sql = file_get_contents($_FILES['f']['tmp_name']);
        if ($sql === false || strlen($sql) < 20 || (stripos($sql, 'CREATE TABLE') === false && stripos($sql, 'INSERT INTO') === false)) { out(['ok' => false, 'msg' => 'Это не похоже на SQL-файл базы данных (.sql).'], 400); }
        try {
            @set_time_limit(300);
            // while the dump is being loaded, other requests must not re-create the empty tables
            $lock = sys_get_temp_dir() . '/meibo_import.lock';
            file_put_contents($lock, (string)time());
            sleep(2);
            // the application creates its empty tables at start-up; the dump brings its own tables,
            // so the empty ones are removed first (this is allowed only while there are no accounts)
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tb) {
                $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$tb) . '`');
            }
            $db->exec($sql);
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            @unlink($lock);
        } catch (Throwable $e) {
            @unlink($lock);
            out(['ok' => false, 'msg' => 'Не удалось загрузить файл: ' . $e->getMessage()], 400);
        }
        db_connect(); // makes sure that every table and column of this version exists
        out(['ok' => true, 'msg' => 'База загружена.']);
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

    if ($a === 'status') {
        out(['users' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()]);
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
            $db->prepare('INSERT INTO people (id, data, rev, upd) VALUES (?, ?, 1, ?)')->execute([$id, $data, now_ms()]);
            $new = 1;
        } elseif (!$force && (int)$cur !== $rev) {
            $db->rollBack();
            out(['conflict' => true]);
        } else {
            $new = (int)$cur + 1;
            $db->prepare('UPDATE people SET data = ?, rev = ?, upd = ? WHERE id = ?')->execute([$data, $new, now_ms(), $id]);
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
        tomb_put($db, 'person|' . $id, now_ms());
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
        meta_put($db, 'staff_ts', now_ms());
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
            $db->prepare('INSERT INTO days (d, seq, data, rev, upd) VALUES (?, ?, ?, 1, ?)')->execute([$d, $seq, $data, now_ms()]);
        } else {
            if (!$force && (int)$row['rev'] !== $rev) {
                $db->rollBack();
                out(['conflict' => true]);
            }
            $seq = (int)$row['seq'];
            $new = (int)$row['rev'] + 1;
            $db->prepare('UPDATE days SET data = ?, rev = ?, upd = ? WHERE d = ?')->execute([$data, $new, now_ms(), $d]);
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
            $db->prepare("UPDATE users SET status = 'active', fails = 0, upd = ? WHERE id = ?")->execute([now_ms(), $id]);
        } elseif ($op === 'block') {
            $db->prepare("UPDATE users SET status = 'blocked', upd = ? WHERE id = ? AND role <> 'admin'")->execute([now_ms(), $id]);
            $db->prepare("DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.id = ? AND u.role <> 'admin'")->execute([$id]);
        } elseif ($op === 'delete') {
            $st = $db->prepare("SELECT username FROM users WHERE id = ? AND role <> 'admin'");
            $st->execute([$id]);
            $un = $st->fetchColumn();
            if ($un !== false) { tomb_put($db, 'user|' . $un, now_ms()); }
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
        $fid = (int)(isset($in['id']) ? $in['id'] : 0);
        $st = $db->prepare('SELECT username, body, created_at FROM feedback WHERE id = ?');
        $st->execute([$fid]);
        $fr = $st->fetch();
        if ($fr !== false) { tomb_put($db, 'fb|' . fb_key($fr), now_ms()); }
        $db->prepare('DELETE FROM feedback WHERE id = ?')->execute([$fid]);
        out(['ok' => true]);

    case 'db_info':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $s = settings_get($db);
        $info = ['a' => db_stats($db), 'b' => ['enabled' => !empty($s['b_enabled'])]];
        $info['a']['host'] = (string)cfg('db_host', 'DB_HOST', 'localhost');
        if (!empty($s['b_enabled'])) {
            $info['b']['host'] = $s['b_host'];
            $info['b']['port'] = isset($s['b_port']) ? $s['b_port'] : '3306';
            $info['b']['name'] = $s['b_name'];
            $info['b']['user'] = $s['b_user'];
            try {
                $B = db_open_b($db, 3);
                $info['b']['ok'] = true;
                $info['b']['stats'] = db_stats($B);
            } catch (Throwable $e) {
                $info['b']['ok'] = false;
                $info['b']['error'] = $e->getMessage();
            }
        }
        $info['last_sync_at'] = isset($s['last_sync_at']) ? (int)$s['last_sync_at'] : 0;
        $info['last_sync_report'] = isset($s['last_sync_report']) ? json_decode($s['last_sync_report'], true) : null;
        $info['last_error'] = isset($s['last_error']) ? $s['last_error'] : '';
        out($info);

    case 'db2_test':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $f = [];
        $err = check_b_fields($in, $f);
        if ($err !== null) { out(['ok' => false, 'msg' => $err]); }
        $inf = [];
        $err = try_connect($f['host'], $f['port'], $f['name'], $f['user'], $f['pass'], $inf);
        if ($err !== null) { out(['ok' => false, 'msg' => $err]); }
        out(['ok' => true, 'msg' => 'Подключение успешно. MySQL ' . $inf['version'] . '. ' . ($inf['db_exists'] ? 'База «' . $f['name'] . '» найдена.' : 'База «' . $f['name'] . '» ещё не создана: она будет создана при подключении.')]);

    case 'db2_save':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $f = [];
        $err = check_b_fields($in, $f);
        if ($err !== null) { out(['ok' => false, 'msg' => $err]); }
        $inf = [];
        $err = try_connect($f['host'], $f['port'], $f['name'], $f['user'], $f['pass'], $inf);
        if ($err !== null) { out(['ok' => false, 'msg' => $err]); }
        if ($f['name'] === (string)cfg('db_name', 'DB_NAME', 'meibo') && $f['host'] === (string)cfg('db_host', 'DB_HOST', 'localhost') && (int)$f['port'] === (int)cfg('db_port', 'DB_PORT', '3306')) {
            out(['ok' => false, 'msg' => 'Это та же самая встроенная база. Укажите другую базу данных.']);
        }
        try {
            db_open($f['host'], $f['port'], $f['name'], $f['user'], $f['pass'], 4);
        } catch (Throwable $e) {
            out(['ok' => false, 'msg' => 'Не удалось подготовить базу: ' . $e->getMessage()]);
        }
        foreach (['host', 'port', 'name', 'user', 'pass'] as $k) { settings_put($db, 'b_' . $k, $f[$k]); }
        settings_put($db, 'b_enabled', '1');
        settings_put($db, 'last_sync_at', '0');
        out(['ok' => true, 'sync' => sync_try($db, true, true)]);

    case 'db2_remove':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        foreach (['b_host', 'b_port', 'b_name', 'b_user', 'b_pass', 'last_sync_report', 'last_error'] as $k) {
            $db->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
        }
        settings_put($db, 'b_enabled', '0');
        out(['ok' => true]);

    case 'db_sync':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        out(sync_try($db, true, true));

    case 'sync_tick':
        $res = sync_try($db, false, false);
        out(['ran' => !empty($res['ran'])]);

    case 'db_copy':
        if (!$isAdmin) { out(['error' => 'forbidden'], 403); }
        $dir = (string)(isset($in['dir']) ? $in['dir'] : '');
        if ($dir !== 'a2b' && $dir !== 'b2a') { out(['ok' => false, 'msg' => 'Неизвестное направление.'], 400); }
        $s = settings_get($db);
        if (empty($s['b_enabled'])) { out(['ok' => false, 'msg' => 'Подключённая база не настроена.']); }
        try {
            $B = db_open_b($db, 5);
        } catch (Throwable $e) {
            out(['ok' => false, 'msg' => 'Подключённая база недоступна: ' . $e->getMessage()]);
        }
        @set_time_limit(300);
        $counts = ($dir === 'a2b') ? db_copy_all($db, $B) : db_copy_all($B, $db);
        $total = array_sum($counts);
        out(['ok' => true, 'msg' => 'Готово: скопировано строк — ' . $total . '. ' . ($dir === 'a2b' ? 'Встроенная → подключённая.' : 'Подключённая → встроенная.')]);

    case 'set_seq':
        $d = (string)(isset($in['d']) ? $in['d'] : '');
        $n = (int)(isset($in['n']) ? $in['n'] : 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $n < 1 || $n > 4) { out(['ok' => false, 'msg' => 'bad'], 400); }
        $db->beginTransaction();
        $st = $db->prepare('SELECT d FROM days WHERE d = ? FOR UPDATE');
        $st->execute([$d]);
        if ($st->fetch() !== false) {
            // the sheet exists: its number becomes n, the next new sheet continues from n
            $db->prepare('UPDATE days SET seq = ?, rev = rev + 1, upd = ? WHERE d = ?')->execute([$n, now_ms(), $d]);
            meta_put($db, 'counter', $n);
        } else {
            // the sheet is not created yet: its first save takes the number n
            meta_put($db, 'counter', $n - 1);
        }
        $db->commit();
        out(['ok' => true]);

    case 'forms_xlsx':
        $kind = (string)(isset($in['kind']) ? $in['kind'] : '');
        if ($kind !== 'water' && $kind !== 'excr') { out(['ok' => false, 'msg' => 'Неизвестный бланк.'], 400); }
        $clean = [];
        foreach (array_slice((isset($in['days']) && is_array($in['days'])) ? $in['days'] : [], 0, 31) as $dd) {
            $dt = (string)(isset($dd['date']) ? $dd['date'] : '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) { out(['ok' => false, 'msg' => 'Неверная дата.'], 400); }
            $nm = (isset($dd['names']) && is_array($dd['names'])) ? array_values(array_slice(array_map('strval', $dd['names']), 0, 200)) : [];
            $clean[] = ['date' => $dt, 'names' => $nm];
        }
        if (count($clean) === 0) { out(['ok' => false, 'msg' => 'Нет дней для печати.'], 400); }
        $tmpIn = tempnam(sys_get_temp_dir(), 'frm');
        $tmpOut = $tmpIn . '.pdf';
        file_put_contents($tmpIn, json_encode(['kind' => $kind, 'days' => $clean], JSON_UNESCAPED_UNICODE));
        $cmd = 'python3 ' . escapeshellarg('/opt/meibo/forms/make_forms.py') . ' ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' 2>&1';
        $lines = [];
        $code = 0;
        @exec($cmd, $lines, $code);
        if ($code !== 0 || !is_file($tmpOut)) {
            @unlink($tmpIn);
            out(['ok' => false, 'msg' => 'Не удалось создать PDF: ' . implode(' ', array_slice($lines, -3))], 500);
        }
        $fname = $clean[0]['date'] . ($kind === 'water' ? '_水分表' : '_排泄表') . '.pdf';
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fname));
        header('Content-Length: ' . filesize($tmpOut));
        readfile($tmpOut);
        @unlink($tmpIn);
        @unlink($tmpOut);
        exit;

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
