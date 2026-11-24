<?php
/* "Esqueci a senha": manda um link para o e-mail do cadastro. A resposta é sempre a mesma,
   exista ou não o cadastro, para não revelar quem está na liga. Sem e-mail configurado, orienta o WhatsApp. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$e = entrada();
$doc = so_digitos($e['doc'] ?? '');
if (!doc_valido($doc)) json_out(['erro' => 'Confira o CNPJ (ou CPF) digitado.'], 422);
if (!email_configurado()) json_out(['erro' => 'Recuperação por e-mail ainda não está ativa. Fale com a CallNet no WhatsApp.', 'wa' => true], 503);
$chIp = 'rec-ip:' . ip_hash(); $chDoc = 'rec:' . $doc;
if (travado($chIp, 8, 3600) > 0 || travado($chDoc, 3, 3600) > 0) json_out(['erro' => 'Muitos pedidos. Tente de novo mais tarde.'], 429);
errou($chIp); errou($chDoc);
$generica = ['ok' => true, 'msg' => 'Se este documento tiver cadastro com e-mail, enviamos um link para criar uma senha nova. Ele vale por 1 hora. Olhe também o spam.'];
$p = one("SELECT * FROM parceiros WHERE doc = ? AND status = 'ativo'", [$doc]);
if (!$p || !filter_var((string)$p['email'], FILTER_VALIDATE_EMAIL)) json_out($generica);
$tok = bin2hex(random_bytes(32));
q('UPDATE senha_tokens SET usado_em = ? WHERE parceiro_id = ? AND usado_em IS NULL', [agora(), $p['id']]);
q('INSERT INTO senha_tokens (parceiro_id, hash, expira) VALUES (?,?,?)', [$p['id'], hash('sha256', $tok), date('Y-m-d H:i:s', time() + 3600)]);
$link = rtrim(conf('site_url', 'https://www.hunterleague.com.br'), '/') . '/senha-nova?t=' . $tok;
$nome = htmlspecialchars(primeiro_nome($p['nome']));
$html = "<div style=\"font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#111\"><h2 style=\"color:#0079FD\">Hunter League</h2>"
  . "<p>Oi, {$nome}! Recebemos um pedido para criar uma senha nova no app.</p>"
  . "<p><a href=\"{$link}\" style=\"display:inline-block;background:#19F7FF;color:#050505;padding:14px 22px;font-weight:bold;text-decoration:none\">Criar senha nova</a></p>"
  . "<p style=\"color:#555;font-size:13px\">O link vale por 1 hora e só funciona uma vez. Se não foi você, ignore este e-mail: sua senha continua a mesma.</p>"
  . "<p style=\"color:#555;font-size:13px\">CALLNET BRASIL · Hunter League</p></div>";
$texto = "Oi, {$nome}! Para criar uma senha nova no app Hunter League, abra: {$link}\nO link vale por 1 hora. Se não foi você, ignore este e-mail.";
try { enviar_email((string)$p['email'], 'Hunter League: criar senha nova', $html, $texto); auditar('parceiro:' . $p['id'], 'pediu senha nova por e-mail'); }
catch (Throwable $x) { auditar('sistema', 'falha no e-mail', (string)$p['codigo'], mb_substr($x->getMessage(), 0, 200)); json_out(['erro' => 'Não conseguimos enviar o e-mail agora. Fale com a CallNet no WhatsApp.', 'wa' => true], 502); }
json_out($generica);
