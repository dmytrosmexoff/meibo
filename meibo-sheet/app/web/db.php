<?php
// Connects to MySQL and creates tables on first use.
function db_connect() {
    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'meibo';
    $user = getenv('DB_USER') ?: 'meibo';
    $pass = getenv('DB_PASS') ?: '';
    $db = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $db->exec("CREATE TABLE IF NOT EXISTS meta (k VARCHAR(32) NOT NULL PRIMARY KEY, v BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS people (id VARCHAR(40) NOT NULL PRIMARY KEY, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS staff (pos INT NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS days (d DATE NOT NULL PRIMARY KEY, seq TINYINT NOT NULL, data JSON NOT NULL, rev INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS attendance (d DATE NOT NULL, person_id VARCHAR(40) NOT NULL, overnight TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (d, person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS log (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ts VARCHAR(40), type VARCHAR(16), target VARCHAR(64), field VARCHAR(64), old_v JSON NULL, new_v JSON NULL, d VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO meta (k, v) VALUES ('counter', 0)");
    return $db;
}
