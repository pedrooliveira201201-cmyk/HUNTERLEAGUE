<?php
/* QR genéricos do adesivo A3: a lista de códigos, quem ocupou cada um e o QR para baixar ou imprimir.
   Na impressão saem só os QR livres, um por cartão, com o código embaixo. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require_once __DIR__ . '/../api/_qr.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (!admin_logado()) { header('Location: ./', true, 303); exit; }

$cods = qr_genericos();
$ocup = [];
if ($cods) {
  $marcas = implode(',', array_fill(0, count($cods), '?'));
  foreach (all("SELECT id, codigo, status, COALESCE(loja, nome) AS nome, criado_em FROM parceiros WHERE codigo IN ($marcas)", $cods) as $r) $ocup[$r['codigo']] = $r;
}
$livres = array_values(array_filter($cods, fn($c) => !isset($ocup[$c])));
$rot = ['pendente' => 'esperando aprovação', 'ativo' => 'ativo', 'recusado' => 'recusado', 'bloqueado' => 'bloqueado', 'desfiliado' => 'desfiliado'];
$site = preg_replace('#^https?://(www\.)?#', '', rtrim((string)conf('site_url', 'https://www.hunterleague.com.br'), '/'));
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>QR genéricos | Central Hunter League</title><link rel="icon" href="../icons/favicon-64.png">
<style>
*{box-sizing:border-box}
body{margin:0;background:#F4F6F5;color:#14412A;font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1100px;margin:0 auto;padding:24px 20px 48px}
h1{margin:0;font-size:26px}
.sub{margin:6px 0 0;color:#4A6355}
.topo{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between}
.btn{display:inline-block;padding:11px 16px;border-radius:10px;border:0;background:#14412A;color:#fff;font:700 15px system-ui,sans-serif;text-decoration:none;cursor:pointer}
.btn.g{background:#DDE7E1;color:#14412A}
h2{margin:30px 0 12px;font-size:18px}
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
.qr{background:#fff;border:1px solid #D5E0DA;border-radius:14px;padding:14px;text-align:center;break-inside:avoid}
.qr svg{display:block;width:100%;height:auto}
.qr b{display:block;margin-top:8px;font-size:18px;letter-spacing:.03em}
.qr small{display:block;color:#4A6355;font-size:12.5px;word-break:break-all}
.qr a{display:inline-block;margin-top:8px;color:#14412A;font-weight:700;font-size:13.5px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #D5E0DA;border-radius:12px;overflow:hidden}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #E6ECE8;font-size:14.5px}
th{background:#EEF3F0;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#4A6355}
.vazio{padding:16px;background:#fff;border:1px dashed #C5D3CA;border-radius:12px;color:#4A6355}
@media print{
  body{background:#fff}main{padding:0;max-width:none}
  .topo .btn,.ocupados,.sub,h2.livres{display:none}
  .grade{grid-template-columns:repeat(3,1fr);gap:8mm}
  .qr{border:1px solid #999;border-radius:0;padding:6mm}
  .qr a{display:none}
}
</style></head>
<body><main>
<div class="topo">
  <div><h1>QR genéricos: adesivo A3, placa A4 e testes</h1>
  <p class="sub"><?= count($livres) ?> livres de <?= count($cods) ?>. Na visita, o parceiro lê o QR do cartaz, toca em “Ative o QR com o seu cadastro” e preenche os dados. O código passa a ser dele e já funciona enquanto vocês aprovam.</p></div>
  <div><a class="btn g" href="./">Voltar para a Central</a> <button class="btn" type="button" onclick="print()">Imprimir os livres</button></div>
</div>

<h2 class="livres">Livres</h2>
<?php if ($livres): ?>
<div class="grade">
<?php foreach ($livres as $c): ?>
  <div class="qr"><?= str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', HLQR::svg(link_parceiro($c))) ?><b><?= h($c) ?></b><small style="font-weight:700"><?= h(qr_generico_tipo($c)) ?></small><small><?= h($site) ?>/i/<?= h($c) ?></small><a href="qr.php?c=<?= rawurlencode($c) ?>" download="qr-<?= h($c) ?>.svg">Baixar SVG</a></div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="vazio">Todos os códigos já foram ocupados. Para criar mais, aumente a lista “qr_genericos” na configuração.</p>
<?php endif; ?>

<div class="ocupados">
<h2>Ocupados</h2>
<?php if ($ocup): ?>
<table><thead><tr><th>Código</th><th>Peça</th><th>Parceiro</th><th>Situação</th><th>Cadastro em</th></tr></thead><tbody>
<?php foreach ($cods as $c): if (!isset($ocup[$c])) continue; $r = $ocup[$c]; ?>
  <tr><td><b><?= h($c) ?></b></td><td><?= h(qr_generico_tipo($c)) ?></td><td><?= h($r['nome']) ?></td><td><?= h($rot[$r['status']] ?? $r['status']) ?></td><td><?= h(data_br($r['criado_em'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<p class="vazio">Nenhum código ocupado ainda.</p>
<?php endif; ?>
</div>
</main></body></html>
