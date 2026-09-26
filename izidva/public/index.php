<?php
require __DIR__ . '/../src/bootstrap.php';
header('Location: ' . (App\Env::configured() ? 'admin/' : 'migrate.php'));
