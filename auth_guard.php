<?php
// auth_guard.php — include this at the very top of any page that should
// require a logged-in session (before any output is sent, since it may
// redirect). Currently used by log.php.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['osiris_authenticated']) || $_SESSION['osiris_authenticated'] !== true) {
    header('Location: index.php');
    exit;
}