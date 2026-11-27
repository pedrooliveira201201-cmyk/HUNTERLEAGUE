<?php
/* O app do parceiro (marcas + Nio). Só abre logado; os dados vão junto com a página. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/api/_dados.php';
$p = parceiro_sessao();
if (!$p) { header('Location: /', true, 302); exit; }
/* antes do portal: cadastro em análise, ou aprovado sem o QR da peça ativado → tela única de espera/ativação */
if ($p['status'] !== 'ativo' || !$p['codigo']) {
  $boot = ['estado' => $p['status'] === 'pendente' ? 'analise' : 'ativar', 'nome' => primeiro_nome($p['nome'])];
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: same-origin');
  echo str_replace('</head>', '<script>window.HL=' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script></head>', file_get_contents(__DIR__ . '/ativar.tpl.html'));
  exit;
}
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
