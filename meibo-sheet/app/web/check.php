<?php
// Open /check.php to see whether PHP and MySQL are connected correctly.
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');
echo "PHP " . PHP_VERSION . "\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . "\n";
try {
    $db = db_connect();
    echo "MySQL connection: OK (" . $db->query('SELECT VERSION()')->fetchColumn() . ")\n";
    foreach (['people', 'staff', 'days', 'attendance', 'log', 'people_history', 'days_history', 'person_settings', 'person_absence', 'staff_days'] as $t) {
        echo $t . ": " . $db->query("SELECT COUNT(*) FROM $t")->fetchColumn() . " rows\n";
    }
    echo "Sheet counter: " . $db->query("SELECT v FROM meta WHERE k = 'counter'")->fetchColumn() . "\n";
    echo "Admin page: kanri.php?k=" . admin_key() . "\n";
    echo "ALL GOOD\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
