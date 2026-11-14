<?php
/* =====================================================================
   db.php: dedicated database connection module (PDO)
   index.php loads this with require_once and uses the $pdo handle.
   ===================================================================== */

$host     = 'localhost';
$dbname   = 'task_db';
$username = 'root';
$password = 'Orange112515';          // Laragon default is an empty password; if iba password ng sql niu, lagay nio pass d2

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    // Throw exceptions on SQL errors instead of failing silently
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Rows come back as associative arrays (column name => value)
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Use real prepared statements, not emulated ones (stronger against SQL injection)
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
