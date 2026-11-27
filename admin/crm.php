<?php
/* CRM do Hunter League: mapa de conquista de Araguari (prospecção, ligações, agenda e funil do app). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');
$a = admin_logado();
if (!$a) { header('Location: ./', true, 302); exit; }
$boot = json_encode(['token' => token_admin(), 'nome' => primeiro_nome($a['nome']), 'papel' => $a['papel']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
echo str_replace('/*__CRM_BOOT__*/', 'window.CRM=' . $boot . ';', file_get_contents(__DIR__ . '/crm.tpl.html'));
