<?php
/* O app do parceiro (marcas + Nio). Só abre logado; os dados vão junto com a página. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/api/_dados.php';
$p = parceiro_logado();
if (!$p) { header('Location: /', true, 302); exit; }
$d = hl_dados($p);
$qr = $d['qr']; unset($d['qr']);
$html = file_get_contents(__DIR__ . '/app.tpl.html');
$json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$html = str_replace(['__QR__', '__QRURL__', '<!--HL-DATA-->'], [$qr, h($d['link']), '<script>window.HL=' . $json . ';</script>'], $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
echo $html;
