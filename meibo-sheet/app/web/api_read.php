<?php
// READ-ONLY endpoint for MeiboCrm: the people of one day. It only SELECTs and never writes.
// Off unless READ_TOKEN is set in docker-compose.yml. Used by the MeiboCrm application.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$token = (string)getenv('READ_TOKEN');
if ($token === '' || $token === 'CHANGE-ME-READ-TOKEN') { http_response_code(404); echo '{"error":"off"}'; exit; }
$given = isset($_SERVER['HTTP_X_READ_TOKEN']) ? (string)$_SERVER['HTTP_X_READ_TOKEN'] : '';
if (!hash_equals($token, $given)) { http_response_code(403); echo '{"error":"token"}'; exit; }
$day = (isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'])) ? $_GET['day'] : date('Y-m-d');
$db = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_NAME') ?: 'meibo') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'meibo', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function pick_version($p, $d) {
    $s = null;
    foreach ((isset($p['v']) && is_array($p['v'])) ? $p['v'] : [] as $i => $ver) {
        if (!is_array($ver) || !isset($ver['s'])) continue;
        if ($i === 0) $s = $ver['s'];
        if (isset($ver['from']) && $ver['from'] <= $d) $s = $ver['s'];
    }
    return is_array($s) ? $s : null;
}
function is_off($p, $d) {
    foreach ((isset($p['off']) && is_array($p['off'])) ? $p['off'] : [] as $iv) {
        if (is_array($iv) && isset($iv[0]) && $d >= $iv[0] && (!isset($iv[1]) || $iv[1] === null || $d < $iv[1])) return true;
    }
    return false;
}
if (isset($_GET['full'])) {
    // full snapshot for MeiboCrm's sheet print: settings of all people, the day and the day before, shifts, sheet number.
    // SELECT only.
    $prev = date('Y-m-d', strtotime($day . ' -1 day'));
    $days = [];
    foreach ([$day, $prev] as $dd) {
        $st = $db->prepare('SELECT seq, data FROM days WHERE d = ?'); $st->execute([$dd]);
        $row = $st->fetch();
        if ($row !== false) { $j = json_decode((string)$row['data'], true); if (is_array($j)) { $j['seq'] = (int)$row['seq']; $days[$dd] = $j; } }
    }
    $people = [];
    foreach ($db->query('SELECT id, data FROM people') as $r) { $p = json_decode((string)$r['data'], true); if (is_array($p)) { $p['id'] = (string)$r['id']; $people[] = $p; } }
    $staff = [];
    foreach ($db->query('SELECT name, letter FROM staff ORDER BY pos') as $r) { $staff[] = ['name' => $r['name'], 'letter' => $r['letter']]; }
    $counter = (int)$db->query("SELECT v FROM meta WHERE k = 'counter'")->fetchColumn();
    echo json_encode(['ok' => true, 'day' => $day, 'snapshot' => ['people' => $people, 'days' => $days, 'staff' => $staff, 'counter' => $counter]], JSON_UNESCAPED_UNICODE);
    exit;
}
$st = $db->prepare('SELECT data FROM days WHERE d = ?'); $st->execute([$day]);
$x = json_decode((string)$st->fetchColumn(), true);
$present = (is_array($x) && isset($x['present']) && is_array($x['present'])) ? array_map('strval', $x['present']) : [];
$modes = (is_array($x) && isset($x['modes']) && is_array($x['modes'])) ? $x['modes'] : [];
$over = (is_array($x) && isset($x['overnight']) && is_array($x['overnight'])) ? array_map('strval', $x['overnight']) : [];
$out = [];
foreach ($db->query('SELECT id, data FROM people') as $r) {
    $id = (string)$r['id'];
    if (!in_array($id, $present, true)) continue;
    $p = json_decode($r['data'], true);
    if (!is_array($p) || is_off($p, $day)) continue;
    $s = pick_version($p, $day);
    if ($s === null || !isset($s['name'])) continue;
    $mode = isset($modes[$id]) ? (string)$modes[$id] : (((isset($s['kind']) ? $s['kind'] : '') === 'visit') ? 'visit' : (in_array($id, $over, true) ? 'night' : 'day'));
    $out[] = ['person_key' => md5($day . '|' . $s['name']), 'name' => (string)$s['name'], 'kind' => $mode === 'visit' ? 'visit' : 'regular', 'mode' => $mode];
}
echo json_encode(['ok' => true, 'day' => $day, 'people' => $out], JSON_UNESCAPED_UNICODE);
