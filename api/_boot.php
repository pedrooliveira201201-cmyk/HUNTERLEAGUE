<?php
/* Hunter League — núcleo do servidor: configuração, banco, sessão e utilidades.
   Todo arquivo PHP do sistema começa com: require __DIR__ . '/_boot.php'; */
declare(strict_types=1);

const HL_ROOT = __DIR__ . '/..';
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

function cfg(): array {
  static $c = null;
  if ($c !== null) return $c;
  $f = HL_ROOT . '/config.php';
  if (!is_file($f)) { http_response_code(503); exit('Sistema não instalado. Abra /instalar.php'); }
  $c = require $f;
  return $c;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  if (($c['db_driver'] ?? 'mysql') === 'sqlite') {
    $pdo = new PDO('sqlite:' . $c['db_file']);
    $pdo->exec('PRAGMA foreign_keys = ON');
  } else {
    $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'],
      [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'"]);
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function agora(): string { return date('Y-m-d H:i:s'); }
function hoje(): string { return date('Y-m-d'); }

/* configurações editáveis no admin (tabela config) */
function conf(string $k, ?string $padrao = null): ?string {
  static $cache = null;
  if ($cache === null) { $cache = []; foreach (all('SELECT chave, valor FROM config') as $r) $cache[$r['chave']] = $r['valor']; }
  return $cache[$k] ?? $padrao;
}

/* sessão: cookie só do site, sem acesso por JavaScript */
function sessao(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('hlsess');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
  session_start();
}

function json_out($dados, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function entrada(): array {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : $_POST;
}

/* só aceita POST vindo do próprio site (proteção contra envio de outro site) */
function exige_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
  $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origem !== '') {
    $host = parse_url($origem, PHP_URL_HOST);
    if ($host !== ($_SERVER['HTTP_HOST'] ?? '') && $host !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) json_out(['erro' => 'Origem não permitida.'], 403);
  }
}

function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function ip_hash(): string { return substr(hash('sha256', ip() . (cfg()['segredo'] ?? '')), 0, 32); }

/* documentos e telefones */
function so_digitos(?string $s): string { return preg_replace('/\D+/', '', (string)$s); }
function cpf_valido(string $c): bool {
  if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
  for ($t = 9; $t < 11; $t++) {
    $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * (($t + 1) - $i);
    $d = ((10 * $s) % 11) % 10; if ((int)$c[$t] !== $d) return false;
  }
  return true;
}
function cnpj_valido(string $c): bool {
  if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
  $p1 = [5,4,3,2,9,8,7,6,5,4,3,2]; $p2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s = 0; for ($i = 0; $i < 12; $i++) $s += (int)$c[$i] * $p1[$i];
  $d1 = $s % 11 < 2 ? 0 : 11 - $s % 11; if ((int)$c[12] !== $d1) return false;
  $s = 0; for ($i = 0; $i < 13; $i++) $s += (int)$c[$i] * $p2[$i];
  $d2 = $s % 11 < 2 ? 0 : 11 - $s % 11; return (int)$c[13] === $d2;
}
function doc_valido(string $d): bool { return strlen($d) === 11 ? cpf_valido($d) : cnpj_valido($d); }
/* celular sem o 9 (número antigo de 8 dígitos): depois do DDD, começa com 6, 7, 8 ou 9 → ganha o 9. Fixo (2 a 5) fica igual. */
function tel_9(string $d): string { return strlen($d) === 10 && strpos('6789', $d[2]) !== false ? substr($d, 0, 2) . '9' . substr($d, 2) : $d; }
function tel_normal(string $t): string { $t = so_digitos($t); if (strlen($t) >= 12 && str_starts_with($t, '55')) $t = substr($t, 2); return tel_9($t); }
function primeiro_nome(string $n): string { $n = trim((string)preg_replace(["/[^\\p{L}\\p{M}\\s'-]+/u", '/\\s+/u'], ['', ' '], strip_tags($n))); $p = explode(' ', $n)[0] ?? ''; return mb_convert_case(mb_strtolower($p), MB_CASE_TITLE); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function auditar(string $quem, string $acao, string $alvo = '', string $detalhe = ''): void {
  q('INSERT INTO auditoria (quem, acao, alvo, detalhe, ip, em) VALUES (?,?,?,?,?,?)', [$quem, $acao, $alvo, mb_substr($detalhe, 0, 500), ip(), agora()]);
}

/* trava de tentativas (login do app e do admin) */
function travado(string $chave, int $max = 5, int $janela = 300): int {
  $desde = date('Y-m-d H:i:s', time() - $janela);
  $r = all('SELECT em FROM tentativas WHERE chave = ? AND em >= ? ORDER BY em ASC', [$chave, $desde]);
  if (count($r) < $max) return 0;
  return max(0, strtotime($r[count($r) - $max]['em']) + $janela - time());
}
function errou(string $chave): void { q('INSERT INTO tentativas (chave, em) VALUES (?, ?)', [$chave, agora()]); }
function limpa_tentativas(string $chave): void { q('DELETE FROM tentativas WHERE chave = ?', [$chave]); }

/* parceiro logado no app */
function parceiro_logado(): ?array {
  $p = parceiro_sessao();
  return $p && $p['status'] === 'ativo' ? $p : null;
}
/* quem está logado no app, aprovado ou ainda em análise (o cadastro em análise vê só a tela de espera) */
function parceiro_sessao(): ?array {
  sessao();
  $id = $_SESSION['parceiro_id'] ?? null;
  if (!$id) return null;
  $p = one('SELECT * FROM parceiros WHERE id = ?', [$id]);
  if (!$p || !in_array($p['status'], ['ativo', 'pendente'], true)) { unset($_SESSION['parceiro_id']); return null; }
  return $p;
}
/* abre a sessão do parceiro (no login e logo depois do cadastro) */
function entrar_parceiro(array $p, bool $manter = false): void {
  sessao();
  session_regenerate_id(true);
  $_SESSION = ['parceiro_id' => (int)$p['id'], 'desde' => time()];
  if ($manter) {
    $prm = session_get_cookie_params();
    setcookie(session_name(), session_id(), ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $prm['secure'], 'httponly' => true, 'samesite' => 'Lax']);
  }
}

const ETAPAS = ['Nova', 'Em contato', 'Em negociação', 'Agendado', 'Instalado', 'Não instalado', 'Cancelado'];
/* etapas em andamento (funil do CRM). "1ª fatura paga" é o Instalado com fatura_em preenchida. */
const ETAPAS_ABERTAS = ['Nova', 'Em contato', 'Em negociação', 'Agendado'];
const ETAPAS_SQL = "('Nova','Em contato','Em negociação','Agendado')";
/* parceiro ativo sem indicar há este número de dias entra no alerta do admin */
const DIAS_SEM_INDICAR = 7;

/* parceiros ativos parados: sem nenhuma indicação nos últimos DIAS_SEM_INDICAR dias
   (conta a partir da última indicação ou, se nunca indicou, da aprovação) */
function parceiros_parados(): array {
  $lim = date('Y-m-d H:i:s', strtotime('-' . DIAS_SEM_INDICAR . ' days'));
  return all("SELECT * FROM (SELECT p.id, p.codigo, COALESCE(p.loja, p.nome) AS nome, p.nome AS resp, p.whatsapp, p.aprovado_em,
                (SELECT MAX(i.criado_em) FROM indicacoes i WHERE i.parceiro_id = p.id) AS ultima
              FROM parceiros p WHERE p.status = 'ativo') t
              WHERE COALESCE(ultima, aprovado_em) < ? ORDER BY COALESCE(ultima, aprovado_em) LIMIT 300", [$lim]);
}
function dias_desde(?string $dt): ?int { return $dt ? (int)floor((time() - strtotime($dt)) / 86400) : null; }

/* migração única: as etapas antigas viram as novas (26/11/2026) */
/* rapidez com muita gente: índices para o que mais cresce (leituras do QR, indicações por etapa, histórico do CRM). Roda uma vez. */
function hl_indices(): void {
  static $feito = false; if ($feito) return; $feito = true;
  if (conf('idx_v') !== '2') {
    foreach (['CREATE INDEX idx_leit_em ON leituras (em)', 'CREATE INDEX idx_ind_st ON indicacoes (status, instalado_em)', 'CREATE INDEX idx_par_wa ON parceiros (whatsapp)',
              'CREATE INDEX idx_emp_tel2 ON empresas (tel2)', 'CREATE INDEX idx_eh_tipo ON empresa_hist (tipo, em)', 'CREATE INDEX idx_ind_par_st ON indicacoes (parceiro_id, status)'] as $sql) { try { db()->exec($sql); } catch (Throwable $e) { /* já existe ou a tabela ainda não foi criada */ } }
    try { if (one("SELECT chave FROM config WHERE chave = 'idx_v'")) q("UPDATE config SET valor = '2' WHERE chave = 'idx_v'"); else q("INSERT INTO config (chave, valor) VALUES ('idx_v', '2')"); } catch (Throwable $e) {}
  }
  if (conf('limpeza_dia') !== date('Y-m-d')) {
    try { q('DELETE FROM tentativas WHERE em < ?', [date('Y-m-d H:i:s', strtotime('-3 days'))]);
      if (one("SELECT chave FROM config WHERE chave = 'limpeza_dia'")) q("UPDATE config SET valor = ? WHERE chave = 'limpeza_dia'", [date('Y-m-d')]); else q("INSERT INTO config (chave, valor) VALUES ('limpeza_dia', ?)", [date('Y-m-d')]); } catch (Throwable $e) {}
  }
}
/* mensagem do WhatsApp de cada pessoa da equipe (Meu perfil); vazia = usa a padrão do CRM */
function conf_set(string $k, string $v): void {
  if (one('SELECT chave FROM config WHERE chave = ?', [$k])) q('UPDATE config SET valor = ? WHERE chave = ?', [$v, $k]);
  else q('INSERT INTO config (chave, valor) VALUES (?, ?)', [$k, $v]);
}
function msg_wa_de(int $admin): string { return (string)(one('SELECT valor FROM config WHERE chave = ?', ['msg_wa_a' . $admin])['valor'] ?? ''); }
function hl_migrar_etapas(): void {
  static $feito = false;
  if ($feito) return; $feito = true;
  hl_indices();
  try {
    /* 27/11/2026: a equipe toda com o mesmo acesso (todos master), uma vez só */
    if (conf('equipe_master') !== '1') { q("UPDATE admins SET papel = 'master'"); q("INSERT INTO config (chave, valor) VALUES ('equipe_master', '1')"); }
    /* 27/11/2026: a Gláucia ativou antes de existir o GPS na ativação; o lugar dela vem do endereço (Av. Batalhão Mauá, 1772), uma vez só */
    if (conf('mapa_glaucia') !== '1') {
      $gl = one("SELECT * FROM parceiros WHERE (nome LIKE 'Glaucia%' OR nome LIKE 'Gláucia%' OR nome LIKE 'GLAUCIA%' OR nome LIKE 'GLÁUCIA%') AND status = 'ativo' ORDER BY id LIMIT 1");
      if ($gl) { require_once __DIR__ . '/_crm.php';
        crm_parceiro_no_mapa($gl, -18.660271, -48.203347, 'sistema', ['logradouro' => 'Avenida Batalhão Mauá', 'numero' => '1772', 'bairro' => 'Industrial', 'cep' => '38440227']);
        conf_set('mapa_glaucia', '1'); }
    }
    /* 27/11/2026: a mensagem de agendamento do Erick no perfil dele, uma vez só */
    if (conf('msg_erick') !== '1') {
      $er = one("SELECT id FROM admins WHERE nome LIKE 'Erick%' ORDER BY id LIMIT 1");
      if ($er && msg_wa_de((int)$er['id']) === '') conf_set('msg_wa_a' . $er['id'], "Oi, {nome}! Aqui é o Erick, da CALLNET!\n\nEstamos selecionando alguns negócios em Araguari para participar de um programa de parceria que pode gerar uma renda extra significativa, chegando a ajudar com custos como aluguel ou salário de funcionários.\n\nAmanhã estaremos na cidade e gostaríamos de fazer uma visita rápida ao seu negócio para apresentar a oportunidade.\n\nVocê consegue nos receber de manhã ou à tarde?");
      conf_set('msg_erick', '1');
    }
    $v = (string)conf('etapas_v', '');
    if ($v === '3') return;
    if ($v !== '2') {
      q("UPDATE indicacoes SET status = 'Em contato' WHERE status = 'Em atendimento'");
      q("UPDATE indicacoes SET status = 'Agendado' WHERE status = 'Aguardando instalação'");
    }
    /* v3: data da venda (quando a instalação foi marcada), para o bônus seguir o preço da época */
    try { db()->exec('ALTER TABLE indicacoes ADD COLUMN vendido_em DATETIME NULL'); } catch (Throwable $x) { /* já existe */ }
    q("UPDATE indicacoes SET vendido_em = atualizado_em WHERE vendido_em IS NULL AND status IN ('Agendado', 'Instalado')");
    if (one("SELECT chave FROM config WHERE chave = 'etapas_v'")) q("UPDATE config SET valor = '3' WHERE chave = 'etapas_v'");
    else q("INSERT INTO config (chave, valor) VALUES ('etapas_v', '3')");
  } catch (Throwable $e) { /* tenta de novo na próxima requisição */ }
}
const MOTIVOS = ['Restrição de CPF', 'Sem cabeamento', 'Desistiu', 'Sem contato', 'Endereço fora da cobertura', 'Outro'];
const PLANOS = ['600 Mega', '800 Mega', '1 Giga', 'Ultra Mesh'];

/* link de contatos do parceiro: /contatos/ID-CHAVE (a chave é uma assinatura do ID com um segredo do servidor; não dá para adivinhar) */
function contatos_segredo(): string {
  static $s = null; if ($s !== null) return $s;
  $s = (string)(one("SELECT valor FROM config WHERE chave = 'contatos_segredo'")['valor'] ?? '');
  if ($s === '') { $s = bin2hex(random_bytes(24)); q("INSERT INTO config (chave, valor) VALUES ('contatos_segredo', ?)", [$s]); }
  return $s;
}
function contatos_chave(int $pid): string { return substr(hash_hmac('sha256', 'contatos:' . $pid, contatos_segredo()), 0, 16); }
function link_contatos(int $pid): string { return rtrim(conf('site_url', 'https://www.hunterleague.com.br'), '/') . '/contatos/' . $pid . '-' . contatos_chave($pid); }
function link_parceiro(string $codigo): string { return rtrim(conf('site_url', 'https://www.hunterleague.com.br'), '/') . '/i/' . $codigo; }

/* QR genéricos: os códigos já impressos no adesivo A3 (estabelecimento) e na placa A4 (motorista).
   Fluxo (26/11/2026): cadastro → análise → aprovação → o parceiro toca em "Ativar QR Code" e lê o QR da peça
   que recebeu na visita. O código lido passa a ser o código do parceiro (parceiros.codigo) e o link dele (/i/CODIGO).
   Os códigos de teste servem também para quem não tem onde colar a peça: a equipe vincula pela Central.
   A lista fica na Central (config 'qr_genericos', separada por vírgula). Padrão:
   HL-ARG-9001 a HL-ARG-9050 (adesivo A3 dos estabelecimentos), HL-UBR-ARG-0001 a HL-UBR-ARG-0020 (placa A4 dos motoristas)
   e HL-TESTE-01 a HL-TESTE-04 (testes da equipe). */
function qr_genericos(): array {
  $cfg = trim((string)conf('qr_genericos', ''));
  $lista = $cfg !== '' ? explode(',', $cfg) : array_merge(
    array_map(fn($n) => sprintf('HL-ARG-%04d', $n), range(9001, 9050)),
    array_map(fn($n) => sprintf('HL-UBR-ARG-%04d', $n), range(1, 20)),
    array_map(fn($n) => sprintf('HL-TESTE-%02d', $n), range(1, 4)));
  return array_values(array_unique(array_filter(array_map(fn($c) => strtoupper((string)preg_replace('/[^A-Za-z0-9-]/', '', $c)), $lista))));
}
/* peça impressa de cada QR genérico */
function qr_generico_tipo(string $c): string { return str_starts_with($c, 'HL-UBR-') ? 'Placa A4 · motorista' : (str_starts_with($c, 'HL-TESTE-') ? 'Teste da equipe' : 'Adesivo A3 · estabelecimento'); }
function qr_generico(?string $codigo): bool { return $codigo !== null && $codigo !== '' && in_array(strtoupper($codigo), qr_genericos(), true); }
/* o que a câmera leu (o endereço completo do QR) ou o que a pessoa digitou → o código */
function qr_codigo_lido(string $txt): string {
  $txt = trim($txt);
  if (preg_match('#/i/([A-Za-z0-9-]{3,16})/?(?:[?\#].*)?$#', $txt, $m)) $txt = $m[1];
  return strtoupper(mb_substr((string)preg_replace('/[^A-Za-z0-9-]/', '', $txt), 0, 16));
}
/* parceiro dono de um código (o código do parceiro é o do QR que ele ativou) */
function parceiro_por_codigo(string $c): ?array { return $c === '' ? null : one('SELECT * FROM parceiros WHERE codigo = ?', [strtoupper($c)]); }

/* Cliente já indicado? Bloqueia se já foi instalado (por qualquer parceiro) ou se foi indicado
   nos últimos N dias (config validade_dias, padrão 60). Depois disso, sem instalação, pode ser indicado de novo. */
function indicacao_valida(string $cpf, string $tel, int $exceto = 0): ?array {
  $cond = []; $par = [];
  if ($cpf !== '') { $cond[] = 'i.cliente_cpf = ?'; $par[] = $cpf; }
  if ($tel !== '') { $cond[] = 'i.cliente_tel = ?'; $par[] = $tel; }
  if (!$cond) return null;
  $dias = max(1, (int)conf('validade_dias', '60'));
  $par[] = date('Y-m-d H:i:s', strtotime("-{$dias} days")); $par[] = $exceto;
  return one("SELECT i.criado_em, i.status, p.codigo, COALESCE(p.loja, p.nome) AS pnome FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id
              WHERE (" . implode(' OR ', $cond) . ") AND i.status <> 'Cancelado' AND (i.status = 'Instalado' OR i.criado_em >= ?) AND i.id <> ?
              ORDER BY i.criado_em ASC LIMIT 1", $par) ?: null;
}

/* comprovante anexado (PDF, JPG, PNG ou WEBP, até 5 MB). Fica numa pasta que o navegador não abre. */
const PASTA_ARQ = __DIR__ . '/../arquivos';
function guardar_comprovante(array $f): string {
  if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Não recebi o arquivo. Tente de novo.');
  if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Arquivo maior que 5 MB.');
  $tipos = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!isset($tipos[$mime])) throw new RuntimeException('Envie PDF, JPG ou PNG.');
  $dir = PASTA_ARQ . '/comprovantes';
  if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Não consegui criar a pasta dos comprovantes.');
  $nome = date('Ym') . '-' . bin2hex(random_bytes(12)) . '.' . $tipos[$mime];
  if (!move_uploaded_file($f['tmp_name'], "$dir/$nome")) throw new RuntimeException('Não consegui salvar o arquivo.');
  return $nome;
}
function enviar_comprovante(?string $nome, string $baixar): never {
  $f = PASTA_ARQ . '/comprovantes/' . basename((string)$nome);
  if (!$nome || !is_file($f)) { http_response_code(404); exit('Comprovante não encontrado.'); }
  $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
  $ext = pathinfo($f, PATHINFO_EXTENSION);
  header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
  header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $baixar) . '.' . $ext . '"');
  header('Content-Length: ' . filesize($f)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
  readfile($f); exit;
}

/* ---------- e-mail (SMTP do Zoho, configurado pelo master na Central) ---------- */
function email_configurado(): bool { return conf('smtp_usuario', '') !== '' && conf('smtp_senha', '') !== ''; }
function enviar_email(string $para, string $assunto, string $html, string $texto): void {
  if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatário inválido.');
  $host = conf('smtp_host', 'smtp.zoho.com'); $porta = (int)conf('smtp_porta', '465');
  $user = (string)conf('smtp_usuario', ''); $pass = (string)conf('smtp_senha', ''); $nome = (string)conf('smtp_nome', 'Hunter League');
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
  $tls = $porta === 465;
  $s = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $host . ':' . $porta, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new RuntimeException("Sem conexão com {$host}:{$porta} ({$es}).");
  stream_set_timeout($s, 15);
  $ler = function () use ($s): string { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function (?string $c, array $ok) use ($s, $ler): string { if ($c !== null) fwrite($s, $c . "\r\n"); $r = $ler(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim(substr($r, 0, 200))); return $r; };
  $cmd(null, [220]); $cmd('EHLO hunterleague.com.br', [250]);
  if (!$tls) { $cmd('STARTTLS', [220]); if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Falha no TLS.'); $cmd('EHLO hunterleague.com.br', [250]); }
  $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($user), [334]); $cmd(base64_encode($pass), [235]);
  $cmd('MAIL FROM:<' . $user . '>', [250]); $cmd('RCPT TO:<' . $para . '>', [250, 251]); $cmd('DATA', [354]);
  $b = 'hl' . bin2hex(random_bytes(8));
  $h = 'From: =?UTF-8?B?' . base64_encode($nome) . "?= <{$user}>\r\nTo: <{$para}>\r\nSubject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n"
     . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@hunterleague.com.br>' . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n\r\n";
  $corpo = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
     . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--{$b}--\r\n";
  $cmd($h . str_replace("\n.", "\n..", $corpo) . "\r\n.", [250]);
  fwrite($s, "QUIT\r\n"); fclose($s);
}

/* ---------- cidades ----------
   O parceiro escolhe a cidade da lista Nio (formato "Araguari - MG"). Config "cidades" = "todas" ou uma lista separada por vírgula. */
require_once __DIR__ . '/_cidades.php';
function cidades_liberadas(): array {
  $todas = array_map(fn($c) => $c[0] . ' - ' . $c[1], CIDADES_NIO);
  $cfg = trim((string)conf('cidades', 'todas'));
  if ($cfg === '' || mb_strtolower($cfg) === 'todas') return $todas;
  $quer = array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $cfg));
  return array_values(array_filter($todas, fn($c) => in_array(mb_strtolower($c), $quer, true) || in_array(mb_strtolower(explode(' - ', $c)[0]), $quer, true)));
}
function cidade_promo(string $cidade): bool {
  foreach (CIDADES_NIO as $c) if ($c[0] . ' - ' . $c[1] === $cidade) return (bool)$c[2];
  $extra = array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)conf('cidades_promo', '')));
  return in_array(mb_strtolower(explode(' - ', $cidade)[0]), $extra, true);
}
function nome_cidade(string $cidade): string { return explode(' - ', $cidade)[0]; }
