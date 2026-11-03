<?php
// First launch: enter the MySQL connection (host, port, database name, login, password) and test it.
// Step 2 is the registration on the site: the first registered account becomes the administrator.
// The page works only until the connection is saved (config.local.php), when config.php was edited by hand,
// or when the database already has accounts.
require __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function jout($a, $code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
$local = __DIR__ . '/config.local.php';
$envSet = (getenv('DB_HOST') !== false && getenv('DB_HOST') !== '');

if (!setup_needed()) {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>.</title></head><body style="font:15px system-ui,sans-serif;margin:24px">'
       . '<h3>Настройка подключения закрыта</h3><p>Подключение к базе данных уже настроено. Чтобы настроить заново, удалите файл <code>config.local.php</code> на сервере.</p>'
       . '<p><a href="index.html">Открыть сайт</a></p></body></html>';
    exit;
}

// protection against requests from other sites
if (empty($_COOKIE['meibo_setup'])) {
    $tok = bin2hex(random_bytes(16));
    setcookie('meibo_setup', $tok, ['expires' => time() + 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => is_https()]);
} else {
    $tok = (string)$_COOKIE['meibo_setup'];
}

function try_connect($host, $port, $name, $user, $pass, &$info) {
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
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

if (isset($_GET['a'])) {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) { $in = []; }
    if (!isset($in['t']) || !hash_equals($tok, (string)$in['t'])) { jout(['ok' => false, 'msg' => 'Обновите страницу и повторите.'], 403); }
    $act = (string)$_GET['a'];
    if ($act === 'use_env' && $envSet) {
        // built-in database (Umbrel / Docker): the values come from the server, the password never goes to the browser
        $host = (string)cfg('db_host', 'DB_HOST', 'localhost');
        $port = (int)cfg('db_port', 'DB_PORT', '3306');
        $name = (string)cfg('db_name', 'DB_NAME', 'meibo');
        $user = (string)cfg('db_user', 'DB_USER', 'meibo');
        $pass = (string)cfg('db_pass', 'DB_PASS', '');
        $act = 'save';
    } else {
        $host = trim((string)(isset($in['host']) ? $in['host'] : ''));
        $port = (int)(isset($in['port']) ? $in['port'] : 3306);
        $name = trim((string)(isset($in['name']) ? $in['name'] : ''));
        $user = (string)(isset($in['user']) ? $in['user'] : '');
        $pass = (string)(isset($in['pass']) ? $in['pass'] : '');
        if ($host === '' || strlen($host) > 255 || !preg_match('/^[A-Za-z0-9._:\-]+$/', $host)) { jout(['ok' => false, 'msg' => 'Проверьте поле «Хост».']); }
        if ($port < 1 || $port > 65535) { jout(['ok' => false, 'msg' => 'Порт должен быть числом от 1 до 65535.']); }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) { jout(['ok' => false, 'msg' => 'Имя базы: только латинские буквы, цифры и «_» (до 64 знаков).']); }
        if ($user === '' || strlen($user) > 80) { jout(['ok' => false, 'msg' => 'Укажите пользователя базы данных (для MySQL без пароля обычно root).']); }
    }
    $info = [];
    $err = try_connect($host, $port, $name, $user, $pass, $info);
    if ($err !== null) { jout(['ok' => false, 'msg' => $err]); }
    if ($act === 'test') {
        $m = 'Подключение успешно. MySQL ' . $info['version'] . '. ';
        $m .= $info['db_exists'] ? 'База «' . $name . '» найдена.' : 'База «' . $name . '» ещё не создана: она будет создана при сохранении.';
        jout(['ok' => true, 'msg' => $m]);
    }
    if ($act === 'save') {
        if (!$info['db_exists']) {
            try {
                $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4');
            } catch (PDOException $e) {
                jout(['ok' => false, 'msg' => 'У пользователя нет прав создать базу «' . $name . '». Создайте её вручную и повторите.']);
            }
        }
        $cfgArr = ['db_host' => $host, 'db_port' => $port, 'db_name' => $name, 'db_user' => $user, 'db_pass' => $pass];
        $php = "<?php\n// Written by setup.php\nreturn " . var_export($cfgArr, true) . ";\n";
        $written = (@file_put_contents($local, $php, LOCK_EX) !== false);
        if ($written) { @chmod($local, 0640); }
        jout(['ok' => true, 'written' => $written, 'php' => $written ? '' : $php]);
    }
    jout(['ok' => false, 'msg' => 'Неизвестное действие.'], 400);
}
$dHost = (string)cfg('db_host', 'DB_HOST', 'localhost');
$dPort = (string)cfg('db_port', 'DB_PORT', '3306');
$dName = (string)cfg('db_name', 'DB_NAME', 'meibo');
$dUser = $envSet ? (string)cfg('db_user', 'DB_USER', 'meibo') : '';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Первый запуск: база данных</title>
<style>body{font:15px system-ui,sans-serif;background:#e9ebef;margin:0;padding:24px;color:#1c1e21}.box{background:#fff;max-width:480px;margin:0 auto;padding:22px 26px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.12)}
label{display:block;margin:12px 0 4px;font-weight:600}input{width:100%;box-sizing:border-box;font:inherit;padding:8px 10px;border:1px solid #bbb;border-radius:6px}button{font:inherit;padding:8px 14px;border:1px solid #99a;border-radius:6px;background:#f5f6f8;cursor:pointer;margin:0 8px 8px 0}button.p{background:#2f6fed;color:#fff;border-color:#2f6fed}button:disabled{opacity:.45;cursor:default}
#msg{margin:14px 0;padding:10px;border-radius:6px;display:none}.ok{background:#e4f6e8;color:#14532d}.er{background:#fde8e8;color:#8a1c1c}.sm{color:#667;font-size:13px}textarea{width:100%;box-sizing:border-box;height:150px;font:12px monospace}hr{border:0;border-top:1px solid #ddd;margin:16px 0}</style></head><body><div class="box">
<h2 style="margin-top:0">Первый запуск</h2>
<p class="sm"><b>Шаг 1 из 2: подключение к базе данных MySQL.</b> Шаг 2: регистрация на сайте (первый зарегистрированный аккаунт станет администратором).</p>
<?php if ($envSet): ?><p><button class="p" id="be">Использовать встроенную базу данных</button></p><p class="sm">Подходит для Umbrel и Docker: данные подключения заданы самим сервером. Или введите данные своей базы ниже.</p><hr><?php endif; ?>
<p class="sm">Если MySQL без логина и пароля (XAMPP, WAMP, Laragon), укажите пользователя <b>root</b> и оставьте пароль пустым. Базу создавать не нужно: она будет создана сама.</p>
<label>Хост</label><input id="host" value="<?php echo h($dHost); ?>">
<label>Порт</label><input id="port" value="<?php echo h($dPort); ?>">
<label>Имя базы</label><input id="name" value="<?php echo h($dName); ?>">
<label>Логин (пользователь)</label><input id="user" value="<?php echo h($dUser); ?>">
<label>Пароль</label><input id="pass" type="password" value="" autocomplete="off">
<div id="msg"></div>
<p><button id="bt">Проверить подключение</button><button class="p" id="bs" disabled>Сохранить и продолжить</button></p>
<div id="manual" style="display:none"><p class="sm">Файл нельзя записать автоматически. Создайте файл <b>config.local.php</b> рядом с этой страницей и вставьте в него:</p><textarea id="php" readonly></textarea><p><a href="index.html">Открыть сайт</a></p></div>
</div>
<script>
var T=<?php echo json_encode($tok); ?>;
function v(id){return document.getElementById(id).value}
function body(){return JSON.stringify({t:T,host:v('host'),port:v('port'),name:v('name'),user:v('user'),pass:v('pass')})}
function show(ok,t){var m=document.getElementById('msg');m.style.display='block';m.className=ok?'ok':'er';m.textContent=t}
function post(a,b){return fetch('setup.php?a='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:b||body()}).then(function(r){return r.json()})}
function done(r){
 if(!r.ok){show(false,r.msg);return}
 if(r.written){show(true,'Сохранено. Открываю сайт: зарегистрируйтесь, первый аккаунт станет администратором.');setTimeout(function(){location.href='index.html'},1400)}
 else{show(true,'Подключение проверено.');document.getElementById('manual').style.display='block';document.getElementById('php').value=r.php}
}
['host','port','name','user','pass'].forEach(function(id){document.getElementById(id).addEventListener('input',function(){document.getElementById('bs').disabled=true})});
document.getElementById('bt').onclick=function(){show(true,'Проверяю…');post('test').then(function(r){show(r.ok,r.msg);document.getElementById('bs').disabled=!r.ok}).catch(function(){show(false,'Не удалось связаться со страницей настройки.')})};
document.getElementById('bs').onclick=function(){post('save').then(done).catch(function(){show(false,'Не удалось сохранить.')})};
var be=document.getElementById('be');if(be){be.onclick=function(){show(true,'Проверяю и сохраняю…');post('use_env',JSON.stringify({t:T})).then(done).catch(function(){show(false,'Не удалось сохранить.')})}}
</script></body></html>
