<?php
/* Cadastro de parceiro (lojista ou vendedor). Fica pendente até o admin aprovar. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
exige_post();
$e = entrada();
$t = fn($k, $max = 120) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($e[$k] ?? ''))), 0, $max);

if (travado('cad:' . ip_hash(), 5, 3600) > 0) json_out(['erro' => 'Muitos cadastros deste aparelho. Tente mais tarde.'], 429);

$tipo = in_array($e['tipo'] ?? '', ['lojista', 'vendedor'], true) ? $e['tipo'] : '';
$nome = $t('nome'); $loja = $t('loja'); $doc = so_digitos($e['doc'] ?? '');
$wa = tel_normal((string)($e['whatsapp'] ?? '')); $email = $t('email'); $cidade = $t('cidade', 60); $pix = $t('pix');
$senha = (string)($e['senha'] ?? '');
$cidades = cidades_liberadas();

$erros = [];
if ($tipo === '') $erros['tipo'] = 'Escolha se você é lojista ou vendedor.';
if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) $erros['nome'] = 'Escreva seu nome completo.';
if (mb_strlen($loja) < 2) $erros['loja'] = 'Escreva o nome da empresa (nome fantasia).';
if (!cnpj_valido($doc)) $erros['doc'] = 'CNPJ inválido. Confira os números do CNPJ.';
if (strlen($wa) < 10 || strlen($wa) > 11) $erros['whatsapp'] = 'WhatsApp com DDD, só números.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido (serve para recuperar a senha).';
if (!in_array($cidade, $cidades, true)) $erros['cidade'] = 'Escolha sua cidade na lista.';
if (mb_strlen($pix) < 5) $erros['pix'] = 'Informe a chave PIX para receber.';
if (strlen($senha) < 8) $erros['senha'] = 'A senha precisa ter pelo menos 8 caracteres.';
if (empty($e['termo'])) $erros['termo'] = 'Para entrar, aceite o contrato.';
if ($erros) json_out(['erro' => 'Confira os campos marcados.', 'campos' => $erros], 422);

if (one('SELECT id FROM parceiros WHERE doc = ?', [$doc])) json_out(['erro' => 'Já existe um cadastro com este CNPJ. Entre com sua senha ou fale com a CallNet.', 'campos' => ['doc' => 'Documento já cadastrado.']], 409);

errou('cad:' . ip_hash());
q('INSERT INTO parceiros (tipo, doc, nome, loja, whatsapp, email, cidade, pix, status, senha_hash, termo_versao, termo_aceito_em, termo_ip, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
  [$tipo, $doc, $nome, $loja, $wa, $email, $cidade, $pix, 'pendente', password_hash($senha, PASSWORD_DEFAULT), conf('termo_versao', '1.0'), agora(), ip(), agora()]);
auditar('cadastro', 'novo parceiro', $doc, $tipo);
json_out(['ok' => true, 'nome' => primeiro_nome($nome)]);
