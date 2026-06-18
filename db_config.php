<?php
// db.php
$host = 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
$port = '4000'; 
$db   = 'osiris_db';
$user = '3Z9rykFbjFwxjaN.root'; 
$pass = 'P64DR34r7F4fF2Ta';     
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // 1011 is the hardcoded system ID for MYSQL_ATTR_SSL_CA
        1011                         => true,
        // 1014 is the hardcoded system ID for MYSQL_ATTR_SSL_VERIFY_SERVER_CERT
        1014                         => false
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
