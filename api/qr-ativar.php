<?php
/* Ativar QR Code: o parceiro aprovado lê o QR do adesivo A3 ou da placa A4 (ou digita o código impresso).
   O código passa a ser o dele: quem ler a peça cai no WhatsApp da CallNet com esse código e a indicação conta para ele. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$p = parceiro_sessao();
if (!$p) json_out(['erro' => 'Entre de novo para ativar.'], 401);
if ($p['status'] !== 'ativo') json_out(['erro' => 'Seu cadastro ainda está em análise. Assim que a CallNet aprovar, o botão libera.'], 403);
if ($p['codigo']) json_out(['erro' => "Sua conta já tem o QR {$p['codigo']} ativado.", 'codigo' => $p['codigo']], 409);
if (travado('qr:' . $p['id'], 10, 600) > 0) json_out(['erro' => 'Muitas tentativas. Espere alguns minutos ou fale com a equipe.'], 429);

$e = entrada();
$c = qr_codigo_lido((string)($e['codigo'] ?? ''));
if ($c === '') json_out(['erro' => 'Leia o QR ou digite o código impresso embaixo dele.'], 422);
if (!qr_generico($c)) { errou('qr:' . $p['id']); json_out(['erro' => "Este QR não é da CallNet ({$c}). Confira o código impresso embaixo do QR."], 422); }
$dono = parceiro_por_codigo($c);
if ($dono && (int)$dono['id'] !== (int)$p['id']) { errou('qr:' . $p['id']); json_out(['erro' => "O QR {$c} já está ativado em outro parceiro. Fale com a equipe."], 409); }

try { q('UPDATE parceiros SET codigo = ? WHERE id = ? AND codigo IS NULL', [$c, $p['id']]); }
catch (PDOException $x) { json_out(['erro' => "O QR {$c} acabou de ser ativado em outro parceiro. Fale com a equipe."], 409); }
auditar('parceiro:' . $p['id'], 'ativou QR', $c, qr_generico_tipo($c));
json_out(['ok' => true, 'codigo' => $c, 'peca' => qr_generico_tipo($c), 'msg' => "QR {$c} ativado. Bem-vindo ao Hunter League!"]);
