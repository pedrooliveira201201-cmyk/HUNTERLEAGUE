<?php
/* QR genéricos (adesivo A3, placa A4 e testes): a lista, quem ativou cada um, vincular ou desvincular à mão e o QR para baixar ou imprimir.
   Na impressão saem só os QR livres, um por cartão, com o código embaixo. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require_once __DIR__ . '/../api/_qr.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (!admin_logado()) { header('Location: ./', true, 303); exit; }

$cods = qr_genericos();
$a = admin_logado();
$aviso = ''; $erroMsg = '';
/* vincular ou desvincular à mão: para quando o parceiro não consegue ler o QR, ou para dar um código de teste
   a quem não tem onde colar a peça */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(token_admin(), (string)($_POST['t'] ?? ''))) $erroMsg = 'A página expirou. Abra de novo e repita.';
  else {
    $c = qr_codigo_lido((string)($_POST['c'] ?? '')); $quem = 'admin:' . $a['usuario'];
    if (!qr_generico($c)) $erroMsg = "O código {$c} não está na lista.";
    elseif (($_POST['o'] ?? '') === 'desvincular') {
      $d = parceiro_por_codigo($c);
      if ($d) { q('UPDATE parceiros SET codigo = NULL WHERE id = ?', [$d['id']]); auditar($quem, 'desvinculou QR', $c, $d['nome']); $aviso = "QR {$c} livre de novo. " . primeiro_nome($d['nome']) . ' volta para a tela de ativação.'; }
    } elseif (($_POST['o'] ?? '') === 'vincular') {
      $alvo = one("SELECT * FROM parceiros WHERE id = ? AND status = 'ativo' AND codigo IS NULL", [(int)($_POST['p'] ?? 0)]);
      if (!$alvo) $erroMsg = 'Escolha um parceiro aprovado que ainda não ativou o QR.';
      elseif (parceiro_por_codigo($c)) $erroMsg = "O QR {$c} já está ativado em outro parceiro.";
      else { q('UPDATE parceiros SET codigo = ? WHERE id = ? AND codigo IS NULL', [$c, $alvo['id']]); auditar($quem, 'vinculou QR', $c, $alvo['nome']); $aviso = "QR {$c} vinculado a " . ($alvo['loja'] ?: $alvo['nome']) . '.'; }
    }
  }
}
$ocup = [];
if ($cods) {
  $marcas = implode(',', array_fill(0, count($cods), '?'));
  foreach (all("SELECT id, codigo, status, COALESCE(loja, nome) AS nome, criado_em FROM parceiros WHERE codigo IN ($marcas)", $cods) as $r) $ocup[$r['codigo']] = $r;
  foreach (all("SELECT alvo, MAX(em) AS em FROM auditoria WHERE acao IN ('ativou QR', 'vinculou QR') AND alvo IN ($marcas) GROUP BY alvo", $cods) as $r) if (isset($ocup[$r['alvo']])) $ocup[$r['alvo']]['em'] = $r['em'];
}
$semQr = all("SELECT id, COALESCE(loja, nome) AS nome, nome AS resp FROM parceiros WHERE status = 'ativo' AND codigo IS NULL ORDER BY aprovado_em DESC");
$livres = array_values(array_filter($cods, fn($c) => !isset($ocup[$c])));
$rot = ['pendente' => 'em análise', 'ativo' => 'ativo', 'recusado' => 'recusado', 'bloqueado' => 'bloqueado', 'desfiliado' => 'desfiliado'];
$tk = h(token_admin());
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
.msg{margin:18px 0 0;padding:12px 14px;border-radius:10px;font-weight:700}.msg.ok{background:#E3F5E4}.msg.bad{background:#FCE4E4}
.vinc{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px;background:#fff;border:1px solid #D5E0DA;border-radius:12px}
.vinc select{padding:10px;border:1px solid #C5D3CA;border-radius:10px;font:600 15px system-ui,sans-serif;min-width:180px}
.btn.sm{padding:7px 12px;font-size:13.5px}
.vazio{padding:16px;background:#fff;border:1px dashed #C5D3CA;border-radius:12px;color:#4A6355}
@media print{
  body{background:#fff}main{padding:0;max-width:none}
  .topo .btn,.ocupados,.sub,h2.livres,.msg{display:none}
  .grade{grid-template-columns:repeat(3,1fr);gap:8mm}
  .qr{border:1px solid #999;border-radius:0;padding:6mm}
  .qr a{display:none}
}
</style></head>
<body><main>
<div class="topo">
  <div><h1>QR genéricos: adesivo A3, placa A4 e testes</h1>
  <p class="sub"><?= count($livres) ?> livres de <?= count($cods) ?>. Fluxo: cadastro → análise → vocês aprovam → o parceiro toca em “Ativar QR Code” no app e lê o QR da peça. O código do QR passa a ser o código dele.</p></div>
  <div><a class="btn g" href="./">Voltar para a Central</a> <button class="btn" type="button" onclick="print()">Imprimir os livres</button></div>
</div>

<?php if ($aviso): ?><p class="msg ok"><?= h($aviso) ?></p><?php endif; ?>
<?php if ($erroMsg): ?><p class="msg bad"><?= h($erroMsg) ?></p><?php endif; ?>
<?php if ($semQr): ?>
<div class="ocupados"><h2>Aprovados sem QR ativado (<?= count($semQr) ?>)</h2>
<p class="sub">Se o parceiro não conseguir ler o QR, ou não tiver onde colar a peça (use um código de teste), vincule aqui.</p>
<form method="post" class="vinc"><input type="hidden" name="t" value="<?= $tk ?>"><input type="hidden" name="o" value="vincular">
 <select name="p" required><?php foreach ($semQr as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['nome']) ?><?= $r['nome'] !== $r['resp'] ? ' · ' . h($r['resp']) : '' ?></option><?php endforeach; ?></select>
 <select name="c" required><?php foreach ($livres as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select>
 <button class="btn" type="submit" onclick="return confirm('Vincular este QR ao parceiro? O código passa a ser o dele.')">Vincular</button></form></div>
<?php endif; ?>

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
<h2>Ativados</h2>
<?php if ($ocup): ?>
<table><thead><tr><th>Código</th><th>Peça</th><th>Parceiro</th><th>Situação</th><th>Ativado em</th><th></th></tr></thead><tbody>
<?php foreach ($cods as $c): if (!isset($ocup[$c])) continue; $r = $ocup[$c]; ?>
  <tr><td><b><?= h($c) ?></b></td><td><?= h(qr_generico_tipo($c)) ?></td><td><?= h($r['nome']) ?></td><td><?= h($rot[$r['status']] ?? $r['status']) ?></td><td><?= h(isset($r['em']) ? dh_br($r['em']) : '') ?></td><td><form method="post" style="margin:0"><input type="hidden" name="t" value="<?= $tk ?>"><input type="hidden" name="o" value="desvincular"><input type="hidden" name="c" value="<?= h($c) ?>"><button class="btn g sm" type="submit" onclick="return confirm('Desvincular <?= h($c) ?>? O QR volta a ficar livre e o parceiro volta para a tela de ativação.')">Desvincular</button></form></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<p class="vazio">Nenhum QR ativado ainda.</p>
<?php endif; ?>
</div>
</main></body></html>
