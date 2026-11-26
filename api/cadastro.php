<?php
/* Cadastro de parceiro (lojista ou vendedor). Fica pendente até o admin aprovar. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
/* Versão do contrato aceita no cadastro. Mude junto com o texto do termo.html. */
const TERMO_VERSAO = '2.3-preliminar';
exige_post();
$e = entrada();
$t = fn($k, $max = 120) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($e[$k] ?? ''))), 0, $max);

if (travado('cad:' . ip_hash(), 5, 3600) > 0) json_out(['erro' => 'Muitos cadastros deste aparelho. Tente mais tarde.'], 429);

$tipo = in_array($e['tipo'] ?? '', ['pj', 'pf'], true) ? $e['tipo'] : '';
$nome = $t('nome'); $loja = $t('loja'); $doc = so_digitos($e['doc'] ?? '');
$wa = tel_normal((string)($e['whatsapp'] ?? '')); $email = $t('email'); $cidade = $t('cidade', 60); $pix = $t('pix');
$senha = (string)($e['senha'] ?? '');
$cidades = cidades_liberadas();

/* QR genérico do adesivo A3: o cadastro ocupa o código já impresso no cartaz (cadastro?r=CODIGO) */
$reserva = strtoupper((string)preg_replace('/[^A-Za-z0-9-]/', '', mb_substr((string)($e['reserva'] ?? ''), 0, 20)));
$ocupado = fn() => json_out(['erro' => "O QR {$reserva} já foi ativado em outro cadastro. Peça outro cartaz à equipe da CallNet."], 409);
if ($reserva !== '') {
  if (!qr_generico($reserva)) json_out(['erro' => "O QR {$reserva} não está na lista da CallNet. Fale com a equipe que te visitou."], 422);
  if (one('SELECT id FROM parceiros WHERE codigo = ?', [$reserva])) $ocupado();
}

$erros = [];
if ($tipo === '') $erros['tipo'] = 'Escolha Pessoa Jurídica ou Pessoa Física.';
if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) $erros['nome'] = 'Escreva seu nome completo.';
if ($tipo === 'pj' && mb_strlen($loja) < 2) $erros['loja'] = 'Escreva o nome da empresa.';
if ($tipo === 'pj' && !cnpj_valido($doc)) $erros['doc'] = 'CNPJ inválido. Confira os números.';
if ($tipo === 'pf' && !cpf_valido($doc)) $erros['doc'] = 'CPF inválido. Confira os números.';
if (strlen($wa) < 10 || strlen($wa) > 11) $erros['whatsapp'] = 'WhatsApp com DDD, só números.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido (serve para recuperar a senha).';
if (!in_array($cidade, $cidades, true)) $erros['cidade'] = 'Escolha sua cidade na lista.';
if (mb_strlen($pix) < 5) $erros['pix'] = 'Informe a chave PIX para receber.';
if (strlen($senha) < 8) $erros['senha'] = 'A senha precisa ter pelo menos 8 caracteres.';
if (empty($e['termo'])) $erros['termo'] = 'Para entrar, aceite o contrato.';
if ($erros) json_out(['erro' => 'Confira os campos marcados.', 'campos' => $erros], 422);

if (one('SELECT id FROM parceiros WHERE doc = ?', [$doc])) json_out(['erro' => 'Já existe um cadastro com este ' . (strlen($doc) === 11 ? 'CPF' : 'CNPJ') . '. Entre com sua senha ou fale com a CallNet.', 'campos' => ['doc' => 'Documento já cadastrado.']], 409);

errou('cad:' . ip_hash());
try {
  q('INSERT INTO parceiros (tipo, doc, nome, loja, whatsapp, email, cidade, pix, codigo, status, senha_hash, termo_versao, termo_aceito_em, termo_ip, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$tipo, $doc, $nome, $loja !== '' ? $loja : null, $wa, $email, $cidade, $pix, $reserva !== '' ? $reserva : null, 'pendente', password_hash($senha, PASSWORD_DEFAULT), TERMO_VERSAO, agora(), ip(), agora()]);
} catch (PDOException $ex) {
  /* dois cadastros no mesmo QR ao mesmo tempo: vale o primeiro */
  if ($reserva !== '' && $ex->getCode() === '23000' && one('SELECT id FROM parceiros WHERE codigo = ?', [$reserva])) $ocupado();
  throw $ex;
}
auditar('cadastro', 'novo parceiro', $doc, $tipo . ($reserva !== '' ? " · QR {$reserva}" : ''));
json_out(['ok' => true, 'nome' => primeiro_nome($nome), 'qr' => $reserva !== '' ? $reserva : null]);
