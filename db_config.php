<?php
// db.php
$host = 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
$port = '4000'; // Default TiDB port
$db   = 'osiris_db';
$user = '3Z9rykFbjFwxjaN.root'; 
$pass = 'P64DR34r7F4fF2Ta';     
$charset = 'utf8mb4';

try {
    // We explicitly map the variables defined right above into the connection string
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset;sslmode=required";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    // Using $user and $pass to establish the connection
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    // Keeps error handling clean and readable
    echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    exit;
}

$message = "";
$messageType = "info"; // info | error | success
$displayDate = "";
