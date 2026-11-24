<?php
/* Entrada no app com CPF ou CNPJ e senha. A trava de tentativas fica aqui no servidor. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$e = entrada();
$doc = so_digitos($e['doc'] ?? '');
$senha = (string)($e['senha'] ?? '');
if ($doc === '' || $senha === '') json_out(['erro' => 'Preencha o CPF ou CNPJ e a senha.'], 422);
if (!doc_valido($doc)) json_out(['erro' => 'CPF ou CNPJ inválido. Confira os números.'], 422);

$chave = 'app:' . $doc; $chaveIp = 'ip:' . ip_hash();
$espera = max(travado($chave, 5, 300), travado($chaveIp, 20, 900));
if ($espera > 0) json_out(['erro' => 'Muitas tentativas. Aguarde para tentar de novo.', 'travado' => $espera], 429);

$p = one('SELECT * FROM parceiros WHERE doc = ?', [$doc]);
$hash = $p['senha_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
if (!password_verify($senha, $hash) || !$p) {
  errou($chave); errou($chaveIp);
  $rest = 5 - count(all('SELECT id FROM tentativas WHERE chave = ? AND em >= ?', [$chave, date('Y-m-d H:i:s', time() - 300)]));
  if ($rest <= 0) json_out(['erro' => 'Muitas tentativas. Aguarde para tentar de novo.', 'travado' => travado($chave, 5, 300)], 429);
  json_out(['erro' => 'CPF/CNPJ ou senha incorretos.', 'restam' => $rest], 401);
}
if ($p['status'] === 'pendente') json_out(['erro' => 'Seu cadastro está em análise. Você recebe um aviso no WhatsApp quando for aprovado.'], 403);
if ($p['status'] !== 'ativo') json_out(['erro' => 'Este acesso está desativado. Fale com a CallNet.'], 403);

limpa_tentativas($chave);
if (password_needs_rehash($p['senha_hash'], PASSWORD_DEFAULT)) q('UPDATE parceiros SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $p['id']]);
sessao();
session_regenerate_id(true);
$_SESSION = ['parceiro_id' => (int)$p['id'], 'desde' => time()];
if (!empty($e['manter'])) {
  $prm = session_get_cookie_params();
  setcookie(session_name(), session_id(), ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $prm['secure'], 'httponly' => true, 'samesite' => 'Lax']);
}
auditar('parceiro:' . $p['id'], 'login');
json_out(['ok' => true, 'perfil' => ['nome' => primeiro_nome($p['nome']), 'nivel' => $p['nivel'], 'cidade' => $p['cidade']]]);
