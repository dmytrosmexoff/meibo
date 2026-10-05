<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($a, $code = 200) {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db_connect();
} catch (Throwable $e) {
    out(['error' => 'db'], 503);
}

$a = isset($_GET['a']) ? $_GET['a'] : '';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = []; }

try {
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
        $db->commit();
        out(['ok' => true, 'rev' => $new]);

    case 'delete_person':
        $id = (string)(isset($in['id']) ? $in['id'] : '');
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
        // mirror who attended, so reports can also be made with plain SQL
        $x = json_decode($data, true);
        $pr = (isset($x['present']) && is_array($x['present'])) ? $x['present'] : [];
        $ov = (isset($x['overnight']) && is_array($x['overnight'])) ? $x['overnight'] : [];
        $db->prepare('DELETE FROM attendance WHERE d = ?')->execute([$d]);
        $ins = $db->prepare('INSERT IGNORE INTO attendance (d, person_id, overnight) VALUES (?, ?, ?)');
        foreach ($pr as $pid) { $ins->execute([$d, (string)$pid, in_array($pid, $ov) ? 1 : 0]); }
        $db->commit();
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

    default:
        out(['error' => 'unknown'], 400);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    out(['error' => $e->getMessage()], 500);
}
