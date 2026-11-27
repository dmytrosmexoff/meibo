<?php
// MeiboCrm's OWN database. It never writes to the meibo database.
function crm_cfg($key, $env, $default) {
    $e = getenv($env);
    return ($e !== false && $e !== '') ? $e : $default;
}
function crm_db() {
    static $db = null;
    if ($db) { return $db; }
    $dsn = 'mysql:host=' . crm_cfg('DB_HOST', 'DB_HOST', 'db') . ';port=' . crm_cfg('DB_PORT', 'DB_PORT', '3306') .
           ';dbname=' . crm_cfg('DB_NAME', 'DB_NAME', 'meibocrm') . ';charset=utf8mb4';
    $db = new PDO($dsn, crm_cfg('DB_USER', 'DB_USER', 'meibocrm'), crm_cfg('DB_PASS', 'DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL, role VARCHAR(12) NOT NULL DEFAULT 'worker', status VARCHAR(12) NOT NULL DEFAULT 'pending',
        staff_name VARCHAR(255) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $t");
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (token_hash CHAR(64) PRIMARY KEY, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $t");
    // people of the day (for now entered by the administrator; later loaded from meibo, read-only)
    $db->exec("CREATE TABLE IF NOT EXISTS day_people (day DATE NOT NULL, person_key VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL,
        kind VARCHAR(12) NOT NULL, PRIMARY KEY (day, person_key)) $t");
    // one value per (day, person, field); who wrote it and when
    $db->exec("CREATE TABLE IF NOT EXISTS entries (day DATE NOT NULL, person_key VARCHAR(64) NOT NULL, field VARCHAR(64) NOT NULL,
        value TEXT NOT NULL, set_by INT NOT NULL, set_at INT NOT NULL, PRIMARY KEY (day, person_key, field)) $t");
    return $db;
}
