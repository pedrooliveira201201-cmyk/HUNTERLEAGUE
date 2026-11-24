<?php
/* Cria a senha nova a partir do link do e-mail. O link vale 1 hora e uma vez só. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$e = entrada();
$tok = preg_replace('/[^0-9a-f]/', '', (string)($e['t'] ?? ''));
$nova = (string)($e['nova'] ?? '');
if (travado('nova-ip:' . ip_hash(), 10, 3600) > 0) json_out(['erro' => 'Muitas tentativas. Tente mais tarde.'], 429);
$t = strlen($tok) === 64 ? one('SELECT * FROM senha_tokens WHERE hash = ?', [hash('sha256', $tok)]) : null;
if (!$t || $t['usado_em'] || strtotime($t['expira']) < time()) { errou('nova-ip:' . ip_hash()); json_out(['erro' => 'Este link expirou ou já foi usado. Peça outro em "Esqueci a senha".'], 410); }
if (strlen($nova) < 8) json_out(['erro' => 'A senha precisa ter pelo menos 8 caracteres.', 'campo' => 'nova'], 422);
$p = one('SELECT * FROM parceiros WHERE id = ?', [$t['parceiro_id']]);
q('UPDATE parceiros SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $t['parceiro_id']]);
q('UPDATE senha_tokens SET usado_em = ? WHERE id = ?', [agora(), $t['id']]);
limpa_tentativas('app:' . ($p['doc'] ?? ''));
auditar('parceiro:' . $t['parceiro_id'], 'criou senha nova pelo e-mail');
json_out(['ok' => true]);
