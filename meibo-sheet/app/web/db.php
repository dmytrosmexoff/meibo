<?php
// Connects to MySQL and creates tables on first use.
// Value from an environment variable or from config.php.
function cfg($key, $env, $default) {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        $c = is_file($f) ? require $f : [];
        if (!is_array($c)) { $c = []; }
        // settings saved by setup.php (first-time setup in the browser) override config.php
        $g = __DIR__ . '/config.local.php';
        if (is_file($g)) {
            $l = require $g;
            if (is_array($l)) { $c = array_merge($c, $l); }
        }
    }
    $e = getenv($env);
    if ($e !== false && $e !== '') { return $e; }
    return isset($c[$key]) ? $c[$key] : $default;
}

function is_https() { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

function db_connect() {
    $host = cfg('db_host', 'DB_HOST', 'localhost');
    $name = cfg('db_name', 'DB_NAME', 'meibo');
    $user = cfg('db_user', 'DB_USER', 'meibo');
    $pass = cfg('db_pass', 'DB_PASS', '');
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $port = (int)cfg('db_port', 'DB_PORT', '3306');
    $dsn = 'mysql:host=' . $host . ';port=' . ($port > 0 ? $port : 3306) . ';dbname=' . $name . ';charset=utf8mb4';
    try {
        $db = new PDO($dsn, $user, $pass, $opt);
    } catch (PDOException $e) {
        // 1049 = the database does not exist yet: create it (needs the right to do so) and connect again
        if ((int)$e->getCode() !== 1049) { throw $e; }
        $tmp = new PDO('mysql:host=' . $host . ';port=' . ($port > 0 ? $port : 3306) . ';charset=utf8mb4', $user, $pass, $opt);
        $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4');
        $db = new PDO($dsn, $user, $pass, $opt);
    }
    $db->exec("SET time_zone = '" . (getenv('MEIBO_TZ') ?: '+09:00') . "'");
    $db->exec("CREATE TABLE IF NOT EXISTS people_history (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, person_id VARCHAR(40) NOT NULL, rev INT NOT NULL, data JSON NOT NULL, deleted TINYINT NOT NULL DEFAULT 0, saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_person (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS days_history (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, d DATE NOT NULL, rev INT NOT NULL, seq TINYINT NOT NULL, data JSON NOT NULL, saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_d (d)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS person_settings (person_id VARCHAR(40) NOT NULL, from_date VARCHAR(10) NOT NULL, name VARCHAR(255), kind VARCHAR(16), note TEXT, vital TINYINT, meds VARCHAR(255), meals VARCHAR(64), delivery VARCHAR(64), PRIMARY KEY (person_id, from_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS person_absence (person_id VARCHAR(40) NOT NULL, from_date VARCHAR(10) NOT NULL, to_date VARCHAR(10) NULL, PRIMARY KEY (person_id, from_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS staff_days (d DATE NOT NULL, role VARCHAR(8) NOT NULL, pos INT NOT NULL, prefix VARCHAR(16), name VARCHAR(255), PRIMARY KEY (d, role, pos)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS meta (k VARCHAR(32) NOT NULL PRIMARY KEY, v BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS people (id VARCHAR(40) NOT NULL PRIMARY KEY, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS staff (pos INT NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS days (d DATE NOT NULL PRIMARY KEY, seq TINYINT NOT NULL, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS attendance (d DATE NOT NULL, person_id VARCHAR(40) NOT NULL, overnight TINYINT NOT NULL DEFAULT 0, person_name VARCHAR(255) NULL, kind VARCHAR(16) NULL, PRIMARY KEY (d, person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS log (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ts VARCHAR(40), type VARCHAR(16), target VARCHAR(64), field VARCHAR(64), old_v JSON NULL, new_v JSON NULL, d VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL, pass_hash VARCHAR(255) NOT NULL, role VARCHAR(8) NOT NULL DEFAULT 'user', status VARCHAR(8) NOT NULL DEFAULT 'pending', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login TIMESTAMP NULL DEFAULT NULL, fails INT NOT NULL DEFAULT 0, fail_ts INT NOT NULL DEFAULT 0, UNIQUE KEY uq_username (username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS feedback (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, username VARCHAR(64) NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO meta (k, v) VALUES ('counter', 0)");
    // upgrade of an older database: add readable columns and fill history from the existing data (runs once)
    $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'person_name'")->fetchColumn();
    if ($has === 0) {
        $db->exec("ALTER TABLE attendance ADD COLUMN person_name VARCHAR(255) NULL");
        $db->exec("ALTER TABLE attendance ADD COLUMN kind VARCHAR(16) NULL");
        $db->exec("INSERT INTO people_history (person_id, rev, data) SELECT id, rev, data FROM people");
        $db->exec("INSERT INTO days_history (d, rev, seq, data) SELECT d, rev, seq, data FROM days");
        foreach ($db->query('SELECT id, data FROM people')->fetchAll() as $r) { mirror_person($db, $r['id'], $r['data']); }
        foreach ($db->query('SELECT d, data FROM days')->fetchAll() as $r) { mirror_day($db, $r['d'], $r['data']); }
    }
    return $db;
}


// ---------- readable mirrors and full history (best effort, never breaks saving) ----------
function pick_version($p, $d) {
    $s = null;
    $vs = (isset($p['v']) && is_array($p['v'])) ? $p['v'] : [];
    foreach ($vs as $i => $ver) {
        if (!is_array($ver) || !isset($ver['s'])) { continue; }
        if ($i === 0) { $s = $ver['s']; }
        if (isset($ver['from']) && $ver['from'] <= $d) { $s = $ver['s']; }
    }
    return is_array($s) ? $s : [];
}

function labels($map, $names) {
    if (is_string($map)) { $map = [$map => 1]; }
    if (!is_array($map)) { return ''; }
    $o = [];
    foreach ($names as $k => $label) {
        if (!empty($map[$k])) { $o[] = $label; }
    }
    return implode(',', $o);
}

function mirror_person($db, $id, $data) {
    try {
        $p = json_decode($data, true);
        if (!is_array($p)) { return; }
        $db->prepare('DELETE FROM person_settings WHERE person_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM person_absence WHERE person_id = ?')->execute([$id]);
        $medN = ['nextMorning' => '翌朝', 'morning' => '朝', 'patch' => '貼り薬', 'noon' => '昼', 'evening' => '夕', 'bedtime' => '寝る前', 'abx' => '抗生物質'];
        $mealN = ['b' => '朝', 'l' => '昼', 'd' => '夕'];
        $ins = $db->prepare('INSERT IGNORE INTO person_settings (person_id, from_date, name, kind, note, vital, meds, meals, delivery) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $vs = (isset($p['v']) && is_array($p['v'])) ? $p['v'] : [];
        foreach ($vs as $ver) {
            $s = (is_array($ver) && isset($ver['s']) && is_array($ver['s'])) ? $ver['s'] : [];
            $ins->execute([
                $id,
                (string)(isset($ver['from']) ? $ver['from'] : ''),
                (string)(isset($s['name']) ? $s['name'] : ''),
                (string)(isset($s['kind']) ? $s['kind'] : ''),
                (string)(isset($s['note']) ? $s['note'] : ''),
                !empty($s['vital']) ? 1 : 0,
                labels(isset($s['meds']) ? $s['meds'] : [], $medN),
                labels(isset($s['meals']) ? $s['meals'] : [], $mealN),
                labels(isset($s['delivery']) ? $s['delivery'] : [], $mealN)
            ]);
        }
        $ab = $db->prepare('INSERT IGNORE INTO person_absence (person_id, from_date, to_date) VALUES (?, ?, ?)');
        $offs = (isset($p['off']) && is_array($p['off'])) ? $p['off'] : [];
        foreach ($offs as $iv) {
            if (is_array($iv) && isset($iv[0])) {
                $to = (isset($iv[1]) && $iv[1] !== null) ? (string)$iv[1] : null;
                $ab->execute([$id, (string)$iv[0], $to]);
            }
        }
    } catch (Throwable $e) {
        error_log('mirror_person: ' . $e->getMessage());
    }
}

function mirror_day($db, $d, $data) {
    try {
        $x = json_decode($data, true);
        if (!is_array($x)) { return; }
        $pr = (isset($x['present']) && is_array($x['present'])) ? array_map('strval', $x['present']) : [];
        $ov = (isset($x['overnight']) && is_array($x['overnight'])) ? array_map('strval', $x['overnight']) : [];
        $people = [];
        foreach ($db->query('SELECT id, data FROM people') as $r) {
            $people[$r['id']] = json_decode($r['data'], true);
        }
        $db->prepare('DELETE FROM attendance WHERE d = ?')->execute([$d]);
        $ins = $db->prepare('INSERT IGNORE INTO attendance (d, person_id, overnight, person_name, kind) VALUES (?, ?, ?, ?, ?)');
        foreach ($pr as $pid) {
            $s = (isset($people[$pid]) && is_array($people[$pid])) ? pick_version($people[$pid], $d) : [];
            $mo = (isset($x['modes']) && is_array($x['modes']) && isset($x['modes'][$pid]))
                ? (string)$x['modes'][$pid]
                : ((isset($s['kind']) && $s['kind'] === 'visit') ? 'visit' : (in_array($pid, $ov, true) ? 'night' : 'day'));
            $ins->execute([
                $d, $pid, in_array($pid, $ov, true) ? 1 : 0,
                (string)(isset($s['name']) ? $s['name'] : ''),
                $mo
            ]);
        }
        $db->prepare('DELETE FROM staff_days WHERE d = ?')->execute([$d]);
        $sd = $db->prepare('INSERT IGNORE INTO staff_days (d, role, pos, prefix, name) VALUES (?, ?, ?, ?, ?)');
        $st = (isset($x['staff']) && is_array($x['staff'])) ? $x['staff'] : [];
        $roles = ['dawn' => '明', 'day' => '日勤', 'night' => '夜勤'];
        foreach ($roles as $k => $label) {
            $list = (isset($st[$k]) && is_array($st[$k])) ? array_values($st[$k]) : [];
            foreach ($list as $i => $e) {
                $e = (string)$e;
                $t = strpos($e, "\t");
                $pre = ($t === false) ? '' : substr($e, 0, $t);
                $nm = ($t === false) ? $e : substr($e, $t + 1);
                if ($nm === '') { continue; }
                $sd->execute([$d, $label, $i, $pre, $nm]);
            }
        }
    } catch (Throwable $e) {
        error_log('mirror_day: ' . $e->getMessage());
    }
}

// secret part of the address of the hidden database page
function admin_key() {
    $secret = (string)cfg('secret', 'MEIBO_SEED', '');
    if ($secret === '' || $secret === 'CHANGE-ME-LONG-RANDOM') {
        $secret = cfg('db_pass', 'DB_PASS', '') . '|' . cfg('db_name', 'DB_NAME', '');
    }
    return substr(hash_hmac('sha256', 'admin-url', $secret), 0, 20);
}

// ---------- accounts: long-lived login (no automatic logout) ----------
function current_user($db) {
    if (empty($_COOKIE['meibo_sid']) || !is_string($_COOKIE['meibo_sid'])) { return null; }
    $st = $db->prepare("SELECT u.id, u.username, u.role, u.status FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND u.status = 'active'");
    $st->execute([hash('sha256', $_COOKIE['meibo_sid'])]);
    $u = $st->fetch();
    return $u === false ? null : $u;
}
function admin_session($db) {
    $u = current_user($db);
    return ($u !== null && $u['role'] === 'admin') ? $u : null;
}
function start_session($db, $uid) {
    $tok = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, user_id) VALUES (?, ?)')->execute([hash('sha256', $tok), $uid]);
    setcookie('meibo_sid', $tok, ['expires' => time() + 10 * 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
}
