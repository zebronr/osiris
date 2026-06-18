<?php
// db.php
$host = 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
$db   = 'osiris_db';
$user = '3Z9rykFbjFwxjaN.root'; // Change if your MySQL setup requires a password
$pass = 'P64DR34r7F4fF2Ta';     
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Append sslmode=verify-ca or sslmode=required directly into the connection string
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4;sslmode=required";
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];
    
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    // For debugging right now, let's output the error if it fails again
    echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    exit;
}

$message = "";
$messageType = "info"; // info | error | success
$displayDate = "";
