<?php
require __DIR__ . '/../src/bootstrap.php';
if (!App\Env::configured()) {
    header('Location: migrate.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/landing/index.html');
