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
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

$message = "";
$messageType = "info"; // info | error | success
$displayDate = "";
