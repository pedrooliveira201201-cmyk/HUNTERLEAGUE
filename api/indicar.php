<?php
/* O parceiro indica um cliente pelo app (com autorização do cliente).
   Obrigatórios: nome, telefone, CEP, número da casa, CPF e e-mail. O parceiro nunca vê esses dados de volta. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$p = parceiro_logado();
if (!$p) json_out(['erro' => 'Entre de novo para indicar.'], 401);
$e = entrada();
$t = fn($k, $max = 120) => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($e[$k] ?? '')))), 0, $max);
$nome = $t('nome'); $tel = tel_normal((string)($e['tel'] ?? '')); $cep = so_digitos($e['cep'] ?? ''); $num = $t('numero', 10);
$cpf = so_digitos($e['cpf'] ?? ''); $email = mb_strtolower($t('email'));
$c = [];
if (mb_strlen($nome) < 2) $c['nome'] = 'Escreva o nome do cliente.';
if (strlen($tel) < 10 || strlen($tel) > 11) $c['tel'] = 'Telefone com DDD.';
if (strlen($cep) !== 8) $c['cep'] = 'CEP com 8 números.';
if ($num === '') $c['numero'] = 'Número da casa (ou S/N).';
if (!cpf_valido($cpf)) $c['cpf'] = 'CPF inválido.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $c['email'] = 'E-mail inválido.';
if (empty($e['autorizou'])) $c['autorizou'] = 'Confirme que o cliente autorizou.';
if ($c) json_out(['erro' => 'Confira os campos marcados.', 'campos' => $c], 422);
if (travado('ind:' . $p['id'], 30, 86400) > 0) json_out(['erro' => 'Limite de indicações por dia atingido. Fale com a CallNet.'], 429);
$dup = indicacao_valida($cpf, $tel);
if ($dup) json_out(['erro' => $dup['status'] === 'Instalado' ? 'Este cliente já foi instalado por uma indicação anterior. A indicação NÃO foi registrada.' : 'Este cliente já foi indicado nos últimos ' . (int)conf('validade_dias', '60') . ' dias. A indicação NÃO foi registrada: vale a primeira.', 'duplicado' => true], 409);
errou('ind:' . $p['id']);
q('INSERT INTO indicacoes (parceiro_id, cliente_nome, cliente_cpf, cliente_tel, cliente_email, cliente_cep, cliente_numero, origem, status, criado_em, criado_por, atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
  [$p['id'], $nome, $cpf, $tel, $email, $cep, $num, 'app', 'Nova', agora(), 'parceiro:' . $p['id'], agora()]);
auditar('parceiro:' . $p['id'], 'indicou pelo app', $p['codigo'], primeiro_nome($nome));
json_out(['ok' => true, 'ind' => ['n' => primeiro_nome($nome), 'd' => date('d/m'), 's' => 'Nova', 'w' => null, 'i' => null, 'm' => true]]);
