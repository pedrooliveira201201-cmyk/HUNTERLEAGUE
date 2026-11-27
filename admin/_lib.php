<?php
/* Funções comuns do admin (login, CSRF, formatos, regras). */
declare(strict_types=1);
require_once __DIR__ . '/../api/_boot.php';
require_once __DIR__ . '/../api/_dados.php';

function admin_logado(): ?array {
  sessao();
  $id = $_SESSION['admin_id'] ?? null;
  if (!$id) return null;
  if (time() - ($_SESSION['admin_visto'] ?? 0) > 12 * 3600) { unset($_SESSION['admin_id']); return null; }
  $_SESSION['admin_visto'] = time();
  return one('SELECT * FROM admins WHERE id = ? AND ativo = 1', [$id]);
}
function foto_admin_arq(int $id): string { return PASTA_ARQ . '/fotos/admin-' . $id . '.jpg'; }
function foto_admin_url(int $id): string { $f = foto_admin_arq($id); return is_file($f) ? 'foto.php?v=' . filemtime($f) : ''; }
function token_admin(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function data_br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function dh_br(?string $d): string { return $d ? date('d/m H:i', strtotime($d)) : ''; }
function tel_br(?string $t): string { $t = (string)$t; return strlen($t) === 11 ? sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 5), substr($t, 7)) : (strlen($t) === 10 ? sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 4), substr($t, 6)) : $t); }
function doc_br(?string $d): string { $d = (string)$d; return strlen($d) === 11 ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d) : preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d); }
function novo_codigo(string $cidade): string {
  $cidade = nome_cidade($cidade);
  $mapa = ['Araguari' => 'ARG', 'Uberlândia' => 'UDI', 'Uberaba' => 'UBA'];
  $pre = $mapa[$cidade] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $cidade) ?: 'HLG'), 0, 3));
  if (strlen($pre) < 3) $pre = str_pad($pre, 3, 'X');
  /* os QR genéricos (HL-ARG-9001 em diante) não entram na contagem da sequência normal */
  $gen = qr_genericos();
  $fora = $gen ? ' AND codigo NOT IN (' . implode(',', array_fill(0, count($gen), '?')) . ')' : '';
  $n = (int)(one("SELECT COUNT(*) AS n FROM parceiros WHERE codigo LIKE ?{$fora}", array_merge(["HL-{$pre}-%"], $gen))['n'] ?? 0);
  do { $n++; $c = sprintf('HL-%s-%04d', $pre, $n); } while (one('SELECT id FROM parceiros WHERE codigo = ?', [$c]) || qr_generico($c));
  return $c;
}
function duplicado(string $cpf, string $tel, int $exceto = 0): ?array { return indicacao_valida($cpf, $tel, $exceto); }

/* ---------- lista dos 20 contatos (passada pelo parceiro na visita) ---------- */
/* lê cartões de contato (vCard) ou linhas "Nome – telefone" (em qualquer ordem) e devolve [nome, tel] */
function lista_contatos(string $txt): array {
  $txt = str_replace(["\r\n", "\r"], "\n", $txt);
  $out = [];
  /* cartões de contato (vCard); o que sobrar fora deles é lido linha a linha */
  if (preg_match_all('/BEGIN:VCARD.*?END:VCARD/is', $txt, $cs)) {
    foreach ($cs[0] as $c) {
      if (!preg_match('/^TEL[^:]*:(.+)$/mi', $c, $t)) continue;
      $nome = preg_match('/^FN[^:]*:(.+)$/mi', $c, $n) ? trim($n[1]) : (preg_match('/^N[;:][^:]*?:?([^\n]+)$/mi', $c, $n2) ? trim(str_replace(';', ' ', $n2[1])) : '');
      $out[] = [$nome, tel_normal($t[1])];
    }
    $txt = preg_replace('/BEGIN:VCARD.*?END:VCARD/is', "\n", $txt);
  }
  $pend = '';
  foreach (explode("\n", $txt) as $l) {
    $l = trim(preg_replace('/^\s*(\d+[\).]|[-•*])\s+/u', '', $l)); if ($l === '') continue;
    if (preg_match('/(\+?55[\s.-]*)?\(?\d{2}\)?[\s.-]*9?[\s.-]*\d{4}[\s.-]*\d{4}/', $l, $m)) {
      $nome = trim(preg_replace('/\s+/', ' ', str_replace($m[0], ' ', $l)), " \t-–—:|,;.");
      $nome = preg_replace('/^(nome|contato|tel(efone)?|cel(ular)?|whats(app)?)\s*:?\s*/iu', '', $nome);
      if ($nome === '' && $pend !== '') $nome = $pend;
      $out[] = [$nome, tel_normal($m[0])]; $pend = '';
    } else $pend = trim(preg_replace('/^(nome|contato)\s*:\s*/iu', '', $l), " \t-–—:|,;.");
  }
  return $out;
}
/* prévia (gravar = false) ou gravação dos contatos como indicações do parceiro */
function lancar_lista(array $p, string $txt, bool $gravar, string $quem): array {
  $itens = []; $vistos = [];
  foreach (lista_contatos($txt) as [$nome, $tel]) {
    $nome = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($nome))), 0, 120);
    $st = 'ok'; $obs = '';
    if (strlen($tel) < 10 || strlen($tel) > 11) { $st = 'invalido'; $obs = 'telefone incompleto'; }
    elseif (isset($vistos[$tel])) { $st = 'repetido'; $obs = 'repetido na lista'; }
    elseif ($d = indicacao_valida('', $tel)) { $st = 'duplicado'; $obs = 'já indicado por ' . $d['codigo'] . ' em ' . date('d/m', strtotime($d['criado_em'])); }
    if ($tel) $vistos[$tel] = 1;
    $itens[] = ['nome' => $nome !== '' ? $nome : 'Contato sem nome', 'tel' => $tel, 'tel_br' => tel_br($tel), 'st' => $st, 'obs' => $obs];
  }
  $ok = array_values(array_filter($itens, fn($i) => $i['st'] === 'ok'));
  if ($gravar) {
    foreach ($ok as $i) q('INSERT INTO indicacoes (parceiro_id, cliente_nome, cliente_tel, status, origem, criado_em, criado_por, atualizado_em) VALUES (?,?,?,?,?,?,?,?)',
      [$p['id'], $i['nome'], $i['tel'], 'Nova', 'lista', agora(), $quem, agora()]);
    auditar(str_contains($quem, ':') ? $quem : 'admin:' . $quem, 'lançou a lista de contatos', (string)($p['codigo'] ?: $p['doc']), count($ok) . ' contatos');
  }
  return ['itens' => $itens, 'ok' => count($ok), 'total' => count($itens)];
}
