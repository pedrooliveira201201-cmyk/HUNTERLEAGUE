<?php
/* O parceiro troca a própria senha (precisa da senha atual). */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$p = parceiro_logado();
if (!$p) json_out(['erro' => 'Entre de novo para trocar a senha.'], 401);
$e = entrada();
$chave = 'senha:' . $p['id'];
if (travado($chave, 5, 900) > 0) json_out(['erro' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
if (!password_verify((string)($e['atual'] ?? ''), $p['senha_hash'])) { errou($chave); json_out(['erro' => 'A senha atual não confere.', 'campo' => 'atual'], 401); }
$nova = (string)($e['nova'] ?? '');
if (strlen($nova) < 8) json_out(['erro' => 'A senha nova precisa ter pelo menos 8 caracteres.', 'campo' => 'nova'], 422);
q('UPDATE parceiros SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $p['id']]);
limpa_tentativas($chave);
auditar('parceiro:' . $p['id'], 'trocou a senha');
json_out(['ok' => true]);
