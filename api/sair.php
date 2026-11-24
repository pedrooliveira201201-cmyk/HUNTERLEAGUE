<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
sessao();
$_SESSION = [];
$prm = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $prm['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: /', true, 303);
