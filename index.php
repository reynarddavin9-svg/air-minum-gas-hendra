<?php
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
readfile(__DIR__ . '/index.html');
