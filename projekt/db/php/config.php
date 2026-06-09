<?php

session_start();


define('PC_ENV', 'development'); // development | production
define('DB_DRIVER', 'json');
define('PC_ROOT', dirname(__DIR__));
define('DB_PATH', PC_ROOT . '/db');
define('PC_VERSION', '4.0.0');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Content-Type: text/plain; charset=utf-8');
	echo $_SESSION['csrf_token'] ;
	exit;
}
/* if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	echo $_SESSION['csrf_token'] ;
	exit;
} */
if (empty($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id'       => 'usr_1',
        'jmeno'    => 'Admin',
        'level'    => 3,
        'lokalita' => 'Sychrov',
    ];
}