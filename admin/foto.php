<?php
/* Foto de perfil de quem está logado na Central. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$a = admin_logado();
if (!$a) { http_response_code(403); exit; }
$f = foto_admin_arq((int)$a['id']);
if (!is_file($f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=2592000'); header('X-Content-Type-Options: nosniff');
readfile($f);
