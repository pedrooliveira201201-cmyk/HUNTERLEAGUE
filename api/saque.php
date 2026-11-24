<?php
/* O parceiro pede o saque de todo o bônus já liberado (instalações com a 1ª fatura paga). */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$p = parceiro_logado();
if (!$p) json_out(['erro' => 'Entre de novo no app.'], 401);
if (travado('saque:' . $p['id'], 3, 600) > 0) json_out(['erro' => 'Aguarde alguns minutos para pedir de novo.'], 429);
$itens = all("SELECT id, comissao FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND fatura_em IS NOT NULL AND saque_id IS NULL", [$p['id']]);
if (!$itens) json_out(['erro' => 'Ainda não tem bônus liberado para sacar.'], 422);
if (mb_strlen(trim((string)$p['pix'])) < 5) json_out(['erro' => 'Cadastre sua chave PIX com a CallNet antes de sacar.'], 422);
$valor = array_sum(array_map(fn($r) => (float)$r['comissao'], $itens));
q('INSERT INTO saques (parceiro_id, valor, n, chave_pix, pedido_em) VALUES (?,?,?,?,?)', [$p['id'], $valor, count($itens), (string)$p['pix'], agora()]);
$sid = (int)(one('SELECT id FROM saques WHERE parceiro_id = ? ORDER BY id DESC LIMIT 1', [$p['id']])['id']);
q('UPDATE indicacoes SET saque_id = ? WHERE id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $itens)) . ') AND saque_id IS NULL', [$sid]);
errou('saque:' . $p['id']);
auditar('parceiro:' . $p['id'], 'pediu saque', (string)$p['codigo'], 'R$ ' . number_format($valor, 2, ',', '.') . ' · ' . count($itens) . ' clientes');
json_out(['ok' => true, 'valor' => $valor, 'prazo' => conf('prazo_saque', 'até 3 dias úteis')]);
