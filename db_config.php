<?php
// db.php
// 1. We change the host to the dedicated port-443 server gateway
$host = 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
$port = '443'; 
$db   = 'osiris_db';
$user = '3Z9rykFbjFwxjaN.root'; 
$pass = 'P64DR34r7F4fF2Ta';     
$charset = 'utf8mb4';

try {
    // 2. Clear out the broken ssl parameters—port 443 forces TLS automatically
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    exit;
}

$message = "";
$messageType = "info"; 
$displayDate = "";
