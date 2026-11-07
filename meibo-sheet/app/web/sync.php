<?php
// Two databases kept equal: the newest information is copied to the place where it is older.
// A = built-in, B = manual connection (settings live in the table `settings` of A).

function settings_get($db) {
    $o = [];
    foreach ($db->query('SELECT k, v FROM settings') as $r) { $o[$r['k']] = $r['v']; }
    return $o;
}
function settings_put($db, $k, $v) { $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$k, (string)$v]); }
function meta_get($db, $k) {
    $st = $db->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}
function meta_put($db, $k, $v) { $db->prepare('REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$k, (int)$v]); }

// short human-readable statistics of one database
function db_stats($db) {
    return [
        'version' => (string)$db->query('SELECT VERSION()')->fetchColumn(),
        'db' => (string)$db->query('SELECT DATABASE()')->fetchColumn(),
        'people' => (int)$db->query('SELECT COUNT(*) FROM people')->fetchColumn(),
        'days' => (int)$db->query('SELECT COUNT(*) FROM days')->fetchColumn(),
        'users' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'newest' => (int)$db->query('SELECT GREATEST(IFNULL((SELECT MAX(upd) FROM people), 0), IFNULL((SELECT MAX(upd) FROM days), 0))')->fetchColumn(),
    ];
}

// test of a connection typed by hand (does not create anything)
function try_connect($host, $port, $name, $user, $pass, &$info) {
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4];
    try {
        $pdo = new PDO('mysql:host=' . $host . ';port=' . (int)$port . ';charset=utf8mb4', $user, $pass, $opt);
        $info['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$name]);
        $info['db_exists'] = ((int)$st->fetchColumn() > 0);
        return null;
    } catch (PDOException $e) {
        $code = (int)$e->getCode();
        if ($code === 1045) { return 'Неверный логин или пароль.'; }
        if ($code === 2002 || $code === 2003 || $code === 2006) { return 'Сервер базы данных недоступен. Проверьте хост и порт.'; }
        return 'Ошибка подключения: ' . $e->getMessage();
    }
}

// checks the fields typed by the administrator; returns an error text or null
function check_b_fields($in, &$f) {
    $f = [
        'host' => trim((string)(isset($in['host']) ? $in['host'] : '')),
        'port' => (int)(isset($in['port']) ? $in['port'] : 3306),
        'name' => trim((string)(isset($in['name']) ? $in['name'] : '')),
        'user' => (string)(isset($in['user']) ? $in['user'] : ''),
        'pass' => (string)(isset($in['pass']) ? $in['pass'] : ''),
    ];
    if ($f['host'] === '' || strlen($f['host']) > 255 || !preg_match('/^[A-Za-z0-9._:\-]+$/', $f['host'])) { return 'Проверьте поле «Хост».'; }
    if ($f['port'] < 1 || $f['port'] > 65535) { return 'Порт должен быть числом от 1 до 65535.'; }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $f['name'])) { return 'Имя базы: только латинские буквы, цифры и «_» (до 64 знаков).'; }
    if ($f['user'] === '' || strlen($f['user']) > 80) { return 'Укажите логин (для MySQL без пароля обычно root).'; }
    return null;
}

// connection B from the saved settings (throws when it is not reachable)
function db_open_b($A, $timeout = 4) {
    $s = settings_get($A);
    if (empty($s['b_enabled'])) { throw new Exception('Ручное подключение не настроено.'); }
    return db_open($s['b_host'], isset($s['b_port']) ? $s['b_port'] : 3306, $s['b_name'], $s['b_user'], isset($s['b_pass']) ? $s['b_pass'] : '', $timeout);
}

// ---------- merge ----------
function tomb_map($db) {
    $o = [];
    foreach ($db->query('SELECT kind, k, upd FROM tombstones') as $r) { $o[$r['kind'] . '|' . $r['k']] = (int)$r['upd']; }
    return $o;
}
function tomb_put($db, $key, $upd) {
    $p = explode('|', $key, 2);
    $db->prepare('REPLACE INTO tombstones (kind, k, upd) VALUES (?, ?, ?)')->execute([$p[0], $p[1], $upd]);
}
function rows_map($db, $sql, $key) {
    $o = [];
    foreach ($db->query($sql) as $r) { $o[(string)$r[$key]] = $r; }
    return $o;
}
// which of two rows is newer: 'a' or 'b' (later update time, then the higher revision; A wins a tie)
function newer($a, $b) {
    $ua = isset($a['upd']) ? (int)$a['upd'] : 0;
    $ub = isset($b['upd']) ? (int)$b['upd'] : 0;
    if ($ua !== $ub) { return $ua > $ub ? 'a' : 'b'; }
    $ra = isset($a['rev']) ? (int)$a['rev'] : 0;
    $rb = isset($b['rev']) ? (int)$b['rev'] : 0;
    if ($ra !== $rb) { return $ra > $rb ? 'a' : 'b'; }
    return 'a';
}
function same_row($a, $b) {
    foreach (['h', 'rev', 'upd'] as $c) {
        if ((string)(isset($a[$c]) ? $a[$c] : '') !== (string)(isset($b[$c]) ? $b[$c] : '')) { return false; }
    }
    return true;
}
function dead($tomb, $key, $row) {
    return isset($tomb[$key]) && ($row === null || $tomb[$key] >= (int)$row['upd']);
}

function copy_person($src, $dst, $id) {
    $st = $src->prepare('SELECT id, data, rev, upd FROM people WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if ($r === false) { return; }
    $dst->prepare('INSERT INTO people (id, data, rev, upd) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), rev = VALUES(rev), upd = VALUES(upd)')->execute([$r['id'], $r['data'], $r['rev'], $r['upd']]);
    mirror_person($dst, $r['id'], $r['data']);
}
function del_person($db, $id) {
    foreach (['people' => 'id', 'person_settings' => 'person_id', 'person_absence' => 'person_id'] as $t => $c) {
        $db->prepare("DELETE FROM $t WHERE $c = ?")->execute([$id]);
    }
}
function copy_day($src, $dst, $d) {
    $st = $src->prepare('SELECT d, seq, data, rev, upd FROM days WHERE d = ?');
    $st->execute([$d]);
    $r = $st->fetch();
    if ($r === false) { return; }
    $dst->prepare('INSERT INTO days (d, seq, data, rev, upd) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE seq = VALUES(seq), data = VALUES(data), rev = VALUES(rev), upd = VALUES(upd)')->execute([$r['d'], $r['seq'], $r['data'], $r['rev'], $r['upd']]);
    mirror_day($dst, $r['d'], $r['data']);
}
function copy_staff($src, $dst) {
    $names = [];
    foreach ($src->query('SELECT name FROM staff ORDER BY pos') as $r) { $names[] = $r['name']; }
    $dst->beginTransaction();
    $dst->exec('DELETE FROM staff');
    $ins = $dst->prepare('INSERT INTO staff (pos, name) VALUES (?, ?)');
    foreach ($names as $i => $n) { $ins->execute([$i, $n]); }
    $dst->commit();
    meta_put($dst, 'staff_ts', meta_get($src, 'staff_ts'));
}
function copy_user($src, $dst, $name) {
    $st = $src->prepare('SELECT username, pass_hash, role, status, upd FROM users WHERE username = ?');
    $st->execute([$name]);
    $r = $st->fetch();
    if ($r === false) { return; }
    $dst->prepare('INSERT INTO users (username, pass_hash, role, status, upd) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash), role = VALUES(role), status = VALUES(status), upd = VALUES(upd)')->execute([$r['username'], $r['pass_hash'], $r['role'], $r['status'], $r['upd']]);
}
function del_user($db, $name) {
    $db->prepare('DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = ?')->execute([$name]);
    $db->prepare('DELETE FROM users WHERE username = ?')->execute([$name]);
}
function fb_key($r) { return md5($r['username'] . '|' . $r['created_at'] . '|' . $r['body']); }

// rows that exist only in one history table are copied to the other one
function copy_history($X, $Y, $kind, $recent, &$count) {
    if ($kind === 'people') {
        $cols = 'person_id, rev, data, deleted, saved_at'; $tab = 'people_history';
        $key = function ($r) { return $r['person_id'] . '|' . $r['rev'] . '|' . $r['deleted'] . '|' . $r['saved_at']; };
        $ins = 'INSERT INTO people_history (person_id, rev, data, deleted, saved_at) VALUES (?, ?, ?, ?, ?)';
        $vals = function ($r) { return [$r['person_id'], $r['rev'], $r['data'], $r['deleted'], $r['saved_at']]; };
    } else {
        $cols = 'd, rev, seq, data, saved_at'; $tab = 'days_history';
        $key = function ($r) { return $r['d'] . '|' . $r['rev'] . '|' . $r['saved_at']; };
        $ins = 'INSERT INTO days_history (d, rev, seq, data, saved_at) VALUES (?, ?, ?, ?, ?)';
        $vals = function ($r) { return [$r['d'], $r['rev'], $r['seq'], $r['data'], $r['saved_at']]; };
    }
    $where = $recent ? ' WHERE saved_at >= (NOW() - INTERVAL 3 DAY)' : '';
    $have = [];
    foreach ($Y->query("SELECT $cols FROM $tab$where") as $r) { $have[$key($r)] = 1; }
    $st = $Y->prepare($ins);
    foreach ($X->query("SELECT $cols FROM $tab$where") as $r) {
        if (!isset($have[$key($r)])) { $st->execute($vals($r)); $count++; }
    }
}

function sync_run($A, $B, $full) {
    $rep = ['to_b' => 0, 'to_a' => 0, 'deleted' => 0, 'history' => 0];

    // 1. deletions are copied both ways first
    $ta = tomb_map($A);
    $tb = tomb_map($B);
    $tomb = [];
    foreach (array_merge(array_keys($ta), array_keys($tb)) as $key) {
        $u = max(isset($ta[$key]) ? $ta[$key] : 0, isset($tb[$key]) ? $tb[$key] : 0);
        $tomb[$key] = $u;
        if (!isset($ta[$key]) || $ta[$key] < $u) { tomb_put($A, $key, $u); }
        if (!isset($tb[$key]) || $tb[$key] < $u) { tomb_put($B, $key, $u); }
    }

    // 2. people
    $sql = 'SELECT id, rev, upd, MD5(CAST(data AS CHAR)) AS h FROM people';
    $pa = rows_map($A, $sql, 'id');
    $pb = rows_map($B, $sql, 'id');
    foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $id) {
        $id = (string)$id;
        $a = isset($pa[$id]) ? $pa[$id] : null;
        $b = isset($pb[$id]) ? $pb[$id] : null;
        $tk = 'person|' . $id;
        if (isset($tomb[$tk]) && dead($tomb, $tk, $a) && dead($tomb, $tk, $b)) {
            if ($a !== null) { del_person($A, $id); $rep['deleted']++; }
            if ($b !== null) { del_person($B, $id); $rep['deleted']++; }
            continue;
        }
        if ($a === null) { copy_person($B, $A, $id); $rep['to_a']++; }
        elseif ($b === null) { copy_person($A, $B, $id); $rep['to_b']++; }
        elseif (!same_row($a, $b)) {
            if (newer($a, $b) === 'a') { copy_person($A, $B, $id); $rep['to_b']++; }
            else { copy_person($B, $A, $id); $rep['to_a']++; }
        }
    }

    // 3. staff list
    $sa = meta_get($A, 'staff_ts');
    $sb = meta_get($B, 'staff_ts');
    if ($sa > $sb) { copy_staff($A, $B); $rep['to_b']++; }
    elseif ($sb > $sa) { copy_staff($B, $A); $rep['to_a']++; }

    // 4. sheets of the days
    $sql = 'SELECT d, rev, upd, MD5(CAST(data AS CHAR)) AS h FROM days';
    $da = rows_map($A, $sql, 'd');
    $db_ = rows_map($B, $sql, 'd');
    foreach (array_unique(array_merge(array_keys($da), array_keys($db_))) as $d) {
        $d = (string)$d;
        $a = isset($da[$d]) ? $da[$d] : null;
        $b = isset($db_[$d]) ? $db_[$d] : null;
        if ($a === null) { copy_day($B, $A, $d); $rep['to_a']++; }
        elseif ($b === null) { copy_day($A, $B, $d); $rep['to_b']++; }
        elseif (!same_row($a, $b)) {
            if (newer($a, $b) === 'a') { copy_day($A, $B, $d); $rep['to_b']++; }
            else { copy_day($B, $A, $d); $rep['to_a']++; }
        }
    }

    // 5. sheet counter: the higher one
    $ca = meta_get($A, 'counter');
    $cb = meta_get($B, 'counter');
    if ($ca > $cb) { meta_put($B, 'counter', $ca); } elseif ($cb > $ca) { meta_put($A, 'counter', $cb); }

    // 6. accounts (by user name)
    $sql = 'SELECT username, role, status, upd, MD5(CONCAT(pass_hash, role, status)) AS h FROM users';
    $ua = rows_map($A, $sql, 'username');
    $ub = rows_map($B, $sql, 'username');
    foreach (array_unique(array_merge(array_keys($ua), array_keys($ub))) as $n) {
        $n = (string)$n;
        $a = isset($ua[$n]) ? $ua[$n] : null;
        $b = isset($ub[$n]) ? $ub[$n] : null;
        $tk = 'user|' . $n;
        if (isset($tomb[$tk]) && dead($tomb, $tk, $a) && dead($tomb, $tk, $b)) {
            if ($a !== null) { del_user($A, $n); $rep['deleted']++; }
            if ($b !== null) { del_user($B, $n); $rep['deleted']++; }
            continue;
        }
        if ($a === null) { copy_user($B, $A, $n); $rep['to_a']++; }
        elseif ($b === null) { copy_user($A, $B, $n); $rep['to_b']++; }
        elseif ((string)$a['h'] !== (string)$b['h'] || (int)$a['upd'] !== (int)$b['upd']) {
            if ((int)$b['upd'] > (int)$a['upd']) { copy_user($B, $A, $n); $rep['to_a']++; }
            else { copy_user($A, $B, $n); $rep['to_b']++; }
        }
    }

    // 7. comments for the developer (added in both, deleted ones are removed everywhere)
    $fa = [];
    foreach ($A->query('SELECT username, body, created_at FROM feedback') as $r) { $fa[fb_key($r)] = $r; }
    $fb = [];
    foreach ($B->query('SELECT username, body, created_at FROM feedback') as $r) { $fb[fb_key($r)] = $r; }
    $insA = $A->prepare('INSERT INTO feedback (user_id, username, body, created_at) VALUES (0, ?, ?, ?)');
    $insB = $B->prepare('INSERT INTO feedback (user_id, username, body, created_at) VALUES (0, ?, ?, ?)');
    foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $k) {
        $tk = 'fb|' . $k;
        if (isset($tomb[$tk])) {
            if (isset($fa[$k])) { $A->prepare('DELETE FROM feedback WHERE username = ? AND body = ? AND created_at = ?')->execute([$fa[$k]['username'], $fa[$k]['body'], $fa[$k]['created_at']]); }
            if (isset($fb[$k])) { $B->prepare('DELETE FROM feedback WHERE username = ? AND body = ? AND created_at = ?')->execute([$fb[$k]['username'], $fb[$k]['body'], $fb[$k]['created_at']]); }
            continue;
        }
        if (!isset($fa[$k])) { $r = $fb[$k]; $insA->execute([$r['username'], $r['body'], $r['created_at']]); $rep['to_a']++; }
        elseif (!isset($fb[$k])) { $r = $fa[$k]; $insB->execute([$r['username'], $r['body'], $r['created_at']]); $rep['to_b']++; }
    }

    // 8. history of changes (full compare after a manual start, only the last days on the automatic runs)
    $recent = !$full;
    copy_history($A, $B, 'people', $recent, $rep['history']);
    copy_history($B, $A, 'people', $recent, $rep['history']);
    copy_history($A, $B, 'days', $recent, $rep['history']);
    copy_history($B, $A, 'days', $recent, $rep['history']);
    return $rep;
}

// runs the synchronisation when it is due (every 2 minutes) or when it is forced
function sync_try($A, $force, $full) {
    $s = settings_get($A);
    if (empty($s['b_enabled'])) { return ['ok' => false, 'ran' => false, 'msg' => 'Ручное подключение не настроено.']; }
    $last = isset($s['last_sync_at']) ? (int)$s['last_sync_at'] : 0;
    if (!$force && time() - $last < 120) { return ['ok' => true, 'ran' => false]; }
    if ((int)$A->query("SELECT GET_LOCK('meibo_sync', 0)")->fetchColumn() !== 1) { return ['ok' => true, 'ran' => false, 'busy' => true]; }
    try {
        $B = db_open_b($A, 4);
        $rep = sync_run($A, $B, $full || $last === 0);
        settings_put($A, 'last_sync_at', time());
        settings_put($A, 'last_sync_report', json_encode($rep));
        settings_put($A, 'last_error', '');
        return ['ok' => true, 'ran' => true, 'report' => $rep];
    } catch (Throwable $e) {
        settings_put($A, 'last_sync_at', time());
        settings_put($A, 'last_error', $e->getMessage());
        return ['ok' => false, 'ran' => true, 'msg' => 'Синхронизация не удалась: ' . $e->getMessage()];
    } finally {
        $A->query("SELECT RELEASE_LOCK('meibo_sync')")->fetchColumn();
    }
}
