<?php
/* API do CRM (mapa de conquista). Só a equipe logada na Central. Toda escrita exige o token da sessão (X-CSRF). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/../api/_crm.php';
require __DIR__ . '/../api/_goto.php';
header('Cache-Control: no-store');

$a = admin_logado();
if (!$a) json_out(['erro' => 'Entre de novo.'], 401);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['erro' => 'Sessão expirada. Recarregue a página.'], 400);
$acao = (string)($_GET['a'] ?? '');
$e = str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
$s = fn(string $k, int $max = 200) => mb_substr(trim((string)($e[$k] ?? '')), 0, $max);
$quem = $a['usuario'];
crm_migrar();

function crm_empresa_ou_404(int $id): array { $x = one('SELECT * FROM empresas WHERE id = ?', [$id]); if (!$x) json_out(['erro' => 'Empresa não encontrada.'], 404); return $x; }
function crm_dist(float $a1, float $o1, float $a2, float $o2): float { $r = 6371000; $dl = deg2rad($a2 - $a1); $do = deg2rad($o2 - $o1);
  $h = sin($dl / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($do / 2) ** 2; return 2 * $r * asin(min(1, sqrt($h))); }
/* fila "ligar nesta região": a região do mapa é feita das células da Nio (o painel manda os nomes) */
function crm_cels_de(string $v): ?array { $c = array_values(array_filter(array_map('trim', explode('|', mb_substr($v, 0, 4000))))); return $c ?: null; }
function crm_regiao_poli(?int $id): ?array { if (!$id) return null; $r = one('SELECT poligono FROM regioes WHERE id = ?', [$id]); return $r ? json_decode($r['poligono'], true) : null; }

switch ($acao) {

case 'estado': {
  crm_sincronizar(); crm_marcar_celulas();
  $hoje = date('Y-m-d');
  $emp = array_map(fn($r) => [(int)$r['id'], crm_nome($r), (string)$r['segmento'], (string)$r['bairro'],
      $r['lat'] !== null ? (float)$r['lat'] : null, $r['lng'] !== null ? (float)$r['lng'] : null, $r['etapa'],
      $r['proximo_em'] ? ($r['proximo_em'] <= agora() ? 1 : 2) : 0, $r['celula'] && $r['celula'] !== '-' ? 1 : 0, $r['geo'] ?? '', $r['tel1'] ? 1 : 0, (int)$r['leit'], (int)$r['inds'], (string)$r['cnae'], $r['celula'] && $r['celula'] !== '-' ? (string)$r['celula'] : ''],
    all("SELECT e.id, e.fantasia, e.razao, e.responsavel, e.segmento, e.cnae, e.bairro, e.lat, e.lng, e.etapa, e.proximo_em, e.celula, e.geo, e.tel1,
      COALESCE(lc.n, 0) AS leit, COALESCE(ic.n, 0) AS inds
      FROM empresas e
      LEFT JOIN (SELECT parceiro_id, COUNT(*) AS n FROM leituras GROUP BY parceiro_id) lc ON lc.parceiro_id = e.parceiro_id
      LEFT JOIN (SELECT parceiro_id, COUNT(*) AS n FROM indicacoes GROUP BY parceiro_id) ic ON ic.parceiro_id = e.parceiro_id
      WHERE " . str_replace(['geo', 'celula'], ['e.geo', 'e.celula'], CRM_VISIVEL) . " ORDER BY e.id"));
  $lig = (int)(one("SELECT COUNT(*) AS n FROM empresa_hist WHERE tipo = 'ligacao' AND em >= ?", [$hoje . ' 00:00:00'])['n'] ?? 0);
  json_out(['empresas' => $emp, 'regioes' => array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'cor' => $r['cor'], 'poly' => json_decode($r['poligono'], true)], all('SELECT * FROM regioes ORDER BY nome')),
    'celulas' => array_map(fn($c) => ['nome' => $c['nome'], 'status' => $c['status'], 'hpv' => $c['hpv'], 'hc' => $c['hc'], 'polys' => $c['polys']], crm_celulas()),
    'geo' => crm_geo_status(), 'ligadas_hoje' => $lig, 'etapas' => CRM_ETAPAS, 'pareceres' => CRM_PARECERES, 'eu' => $a['nome'], 'safra' => CRM_SAFRA_DIAS,
    'equipe' => array_column(all('SELECT nome FROM admins WHERE ativo = 1 ORDER BY nome'), 'nome'),
    'msg_wa' => conf('crm_msg_wa', "Oi, {nome}! Aqui é o {eu}, da CALLNET. Estamos abrindo em Araguari um programa de parceria para negócios como o seu: renda extra por indicação, sem investimento e sem estoque. Amanhã nosso representante está na cidade. Ele pode passar aí para te apresentar a oportunidade de manhã ou à tarde, por 15 minutos?")]);
}

case 'empresa': {
  $x = crm_empresa_ou_404((int)($_GET['id'] ?? 0));
  $par = $x['parceiro_id'] ? one('SELECT id, codigo, status, nome, loja, aprovado_em, criado_em FROM parceiros WHERE id = ?', [$x['parceiro_id']]) : null;
  $app = null;
  if ($par) {
    $app = ['id' => (int)$par['id'], 'codigo' => $par['codigo'], 'status' => $par['status'], 'cadastro' => data_br($par['criado_em']),
      'leituras' => (int)(one('SELECT COUNT(*) AS n FROM leituras WHERE parceiro_id = ?', [$par['id']])['n'] ?? 0),
      'ult_leituras' => array_map(fn($l) => dh_br($l['em']), all('SELECT em FROM leituras WHERE parceiro_id = ? ORDER BY em DESC LIMIT 8', [$par['id']])),
      'indicados' => array_map(fn($i) => ['nome' => $i['cliente_nome'], 'tel' => tel_br($i['cliente_tel']), 'origem' => $i['origem'], 'status' => $i['status'], 'em' => data_br($i['criado_em'])],
        all('SELECT cliente_nome, cliente_tel, origem, status, criado_em FROM indicacoes WHERE parceiro_id = ? ORDER BY criado_em DESC LIMIT 60', [$par['id']]))];
  }
  $viz = [];
  if ($x['lat'] !== null && in_array($x['geo'], ['rua', 'manual', 'bairro', 'bairro_ok'], true))
    foreach (all("SELECT id, fantasia, razao, responsavel, lat, lng, etapa FROM empresas WHERE etapa IN ('afiliado','conquistado','venda') AND lat IS NOT NULL AND id <> ?", [$x['id']]) as $v) {
      $d = crm_dist((float)$x['lat'], (float)$x['lng'], (float)$v['lat'], (float)$v['lng']); if ($d <= 300) $viz[] = ['nome' => crm_nome($v), 'm' => (int)round($d)]; }
  usort($viz, fn($p, $q) => $p['m'] <=> $q['m']);
  $cel = null; if ($x['celula'] && $x['celula'] !== '-') foreach (crm_celulas() as $c) if ($c['nome'] === $x['celula']) $cel = ['nome' => $c['nome'], 'status' => $c['status'], 'ocup' => $c['ocup']];
  $x['extra'] = $x['extra'] ? json_decode($x['extra'], true) : null;
  /* conversa no WhatsApp da CallNet (GoTo) com os telefones da empresa e do parceiro */
  goto_migrar();
  $tels = array_values(array_unique(array_filter([goto_tel((string)$x['tel1']), goto_tel((string)$x['tel2']), $par ? goto_tel((string)(one('SELECT whatsapp FROM parceiros WHERE id = ?', [$par['id']])['whatsapp'] ?? '')) : ''], fn($t) => strlen($t) >= 10)));
  $conversa = $tels ? array_reverse(array_map(fn($m) => ['dir' => $m['dir'], 'texto' => mb_substr($m['texto'], 0, 600), 'em' => dh_br($m['em']), 'tel' => tel_br($m['tel'])],
    all('SELECT dir, texto, em, tel FROM goto_msgs WHERE tel IN (' . implode(',', array_fill(0, count($tels), '?')) . ') ORDER BY em DESC, id DESC LIMIT 40', $tels))) : [];
  json_out(['empresa' => $x, 'conversa' => $conversa, 'app' => $app, 'vizinhos' => array_slice($viz, 0, 5), 'celula' => $cel,
    'hist' => array_map(fn($h) => ['em' => dh_br($h['em']), 'quem' => $h['quem'], 'tipo' => $h['tipo'], 'texto' => $h['texto']], all('SELECT * FROM empresa_hist WHERE empresa_id = ? ORDER BY id DESC LIMIT 80', [$x['id']]))]);
}

case 'parecer': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $x = crm_empresa_ou_404((int)($e['id'] ?? 0));
  $antes = array_intersect_key($x, array_flip(['etapa','motivo','proximo_em','tentativas','visita_dia','visita_turno','visita_quem','tel_ok','tel1','tel2']));
  try { $txt = crm_parecer($x, $s('p', 20), $e, $quem); } catch (InvalidArgumentException $z) { json_out(['erro' => $z->getMessage()], 422); }
  $prox = crm_proximo(crm_regiao_poli((int)($e['regiao'] ?? 0) ?: null), (int)$x['id'], crm_cels_de((string)($e['cels'] ?? '')));
  $_SESSION['crm_desfazer'] = ['id' => (int)$x['id'], 'antes' => $antes, 'hist' => (int)(one('SELECT MAX(id) AS m FROM empresa_hist WHERE empresa_id = ?', [$x['id']])['m'] ?? 0), 'em' => time()];
  json_out(['ok' => true, 'msg' => $txt, 'proximo' => $prox ? (int)$prox['id'] : null, 'desfazer' => true]);
}

case 'desfazer': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $u = $_SESSION['crm_desfazer'] ?? null;
  if (!$u || time() - $u['em'] > 60) json_out(['erro' => 'Não dá mais para desfazer.'], 409);
  $set = []; $par = []; foreach ($u['antes'] as $k => $v) { $set[] = "$k = ?"; $par[] = $v; }
  q('UPDATE empresas SET ' . implode(', ', $set) . ', atualizado_em = ? WHERE id = ?', array_merge($par, [agora(), $u['id']]));
  q('DELETE FROM empresa_hist WHERE id = ? AND empresa_id = ?', [$u['hist'], $u['id']]);
  unset($_SESSION['crm_desfazer']);
  json_out(['ok' => true, 'id' => $u['id'], 'msg' => 'Parecer desfeito.']);
}

case 'novidades': {
  /* leituras do QR depois da última que a tela já viu (pelo número da leitura): o ponto da empresa pisca no mapa */
  $ult = (int)($_GET['desde'] ?? -1);
  $max = (int)(one('SELECT MAX(id) AS m FROM leituras')['m'] ?? 0);
  if ($ult < 0) json_out(['ultimo' => $max, 'leituras' => []]);
  $r = all('SELECT l.id AS lid, l.em, e.id, e.fantasia, e.razao, e.responsavel FROM leituras l JOIN empresas e ON e.parceiro_id = l.parceiro_id WHERE l.id > ? ORDER BY l.id LIMIT 30', [$ult]);
  json_out(['ultimo' => $max, 'leituras' => array_map(fn($x) => ['id' => (int)$x['id'], 'nome' => crm_nome($x), 'em' => dh_br($x['em'])], $r)]);
}

case 'campo': {
  /* toques do pessoal de campo na visita */
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $x = crm_empresa_ou_404((int)($e['id'] ?? 0));
  $t = ['cadastrou' => 'Campo: cadastrou no app', 'adesivo' => 'Campo: colou o adesivo/entregou a placa', 'lista' => 'Campo: pegou os 20 contatos'][$s('o', 20)] ?? null;
  if (!$t) json_out(['erro' => 'Ação inválida.'], 422);
  crm_hist((int)$x['id'], $quem, 'campo', $t); json_out(['ok' => true, 'msg' => $t]);
}

case 'proximo': {
  $prox = crm_proximo(crm_regiao_poli((int)($_GET['regiao'] ?? 0) ?: null), (int)($_GET['exceto'] ?? 0), crm_cels_de((string)($_GET['cels'] ?? '')));
  json_out(['proximo' => $prox ? (int)$prox['id'] : null]);
}

case 'visita': {
  /* resultado da visita do representante */
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $x = crm_empresa_ou_404((int)($e['id'] ?? 0)); $r = $s('r', 20);
  if ($r === 'fechou') { q("UPDATE empresas SET etapa = 'visitado', motivo = NULL, proximo_em = NULL, atualizado_em = ? WHERE id = ?", [agora(), $x['id']]); $t = 'Visita: fechou, aguarda o cadastro no app'; }
  elseif ($r === 'pensar') { q("UPDATE empresas SET etapa = 'visitado', proximo_em = ?, atualizado_em = ? WHERE id = ?", [date('Y-m-d 09:00:00', strtotime('+' . CRM_SAFRA_DIAS . ' days')), agora(), $x['id']]); $t = 'Visita: vai pensar (retorno em ' . CRM_SAFRA_DIAS . ' dias)'; }
  elseif ($r === 'nao_quis') { $m = $s('motivo', 80) ?: 'Não quis na visita'; q("UPDATE empresas SET etapa = 'perdido', motivo = ?, proximo_em = NULL, atualizado_em = ? WHERE id = ?", [$m, agora(), $x['id']]); $t = 'Visita: não quis · ' . $m; }
  elseif ($r === 'nao_estava') { q("UPDATE empresas SET etapa = 'mapeado', visita_dia = NULL, visita_turno = NULL, proximo_em = ?, atualizado_em = ? WHERE id = ?", [date('Y-m-d 09:00:00'), agora(), $x['id']]); $t = 'Visita: não estava, ligar para reagendar'; }
  elseif ($r === 'cancelar') { /* desmarcou (ou foi teste): volta para "a agendar", sem resultado de visita */
    if ($x['etapa'] !== 'agendado') json_out(['erro' => 'Esta empresa não está agendada.'], 422);
    q("UPDATE empresas SET etapa = 'mapeado', visita_dia = NULL, visita_turno = NULL, visita_quem = NULL, proximo_em = NULL, motivo = NULL, atualizado_em = ? WHERE id = ?", [agora(), $x['id']]); $t = 'Agendamento cancelado'; }
  else json_out(['erro' => 'Resultado inválido.'], 422);
  crm_hist((int)$x['id'], $quem, 'visita', $t . ($s('obs', 300) !== '' ? ' · ' . $s('obs', 300) : ''));
  json_out(['ok' => true, 'msg' => $t]);
}

case 'agenda': {
  $dia = crm_data((string)($_GET['dia'] ?? '')) ?: date('Y-m-d', strtotime('+1 day'));
  $itens = all("SELECT id, fantasia, razao, responsavel, segmento, tel1, logradouro, numero, bairro, lat, lng, visita_turno, visita_quem, etapa FROM empresas WHERE visita_dia = ? AND etapa IN ('agendado','visitado') ORDER BY visita_turno, bairro, logradouro", [$dia]);
  json_out(['dia' => $dia, 'itens' => array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => crm_nome($r), 'resp' => $r['responsavel'], 'seg' => $r['segmento'], 'tel' => $r['tel1'], 'tel_br' => tel_br($r['tel1']),
    'end' => trim($r['logradouro'] . ($r['numero'] ? ', ' . $r['numero'] : '') . ($r['bairro'] ? ' · ' . $r['bairro'] : '')), 'lat' => $r['lat'], 'lng' => $r['lng'], 'turno' => $r['visita_turno'], 'quem' => $r['visita_quem'], 'etapa' => $r['etapa']], $itens)]);
}

case 'obs': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $x = crm_empresa_ou_404((int)($e['id'] ?? 0)); $t = $s('texto', 400); if ($t === '') json_out(['erro' => 'Escreva a observação.'], 422);
  crm_hist((int)$x['id'], $quem, 'obs', $t); json_out(['ok' => true]);
}

case 'salvar': {
  /* nova empresa (achada na rua) ou edição; o ponto pode vir do clique no mapa */
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $id = (int)($e['id'] ?? 0);
  $d = ['fantasia' => $s('fantasia', 160) ?: null, 'razao' => $s('razao', 160) ?: null, 'responsavel' => $s('responsavel', 120) ?: null, 'segmento' => $s('segmento', 60) ?: null,
    'cnpj' => substr(preg_replace('/\D/', '', $s('cnpj', 20)), 0, 14) ?: null, 'tel1' => crm_tel($s('tel1', 20)) ?: null, 'tel2' => crm_tel($s('tel2', 20)) ?: null, 'email' => $s('email', 160) ?: null,
    'logradouro' => $s('logradouro', 160) ?: null, 'numero' => $s('numero', 20) ?: null, 'bairro' => $s('bairro', 100) ?: null, 'cep' => substr(preg_replace('/\D/', '', $s('cep', 9)), 0, 8) ?: null];
  if (!$d['fantasia'] && !$d['razao']) json_out(['erro' => 'Coloque o nome da empresa.'], 422);
  $lat = is_numeric($e['lat'] ?? null) ? round((float)$e['lat'], 6) : null; $lng = is_numeric($e['lng'] ?? null) ? round((float)$e['lng'], 6) : null;
  if ($id) {
    $x = crm_empresa_ou_404($id); $set = []; $par = [];
    foreach ($d as $k => $v) if (array_key_exists($k, $e)) { $set[] = "$k = ?"; $par[] = $v; }
    if ($lat !== null && $lng !== null) { $set[] = "lat = ?, lng = ?, geo = 'manual', celula = NULL"; array_push($par, $lat, $lng); }
    if ($set) q('UPDATE empresas SET ' . implode(', ', $set) . ', atualizado_em = ? WHERE id = ?', array_merge($par, [agora(), $id]));
    crm_hist($id, $quem, 'edicao', $lat !== null ? 'Ajustou o ponto no mapa' : 'Editou os dados');
    json_out(['ok' => true, 'id' => $id]);
  }
  if ($d['cnpj'] && one('SELECT id FROM empresas WHERE cnpj = ?', [$d['cnpj']])) json_out(['erro' => 'Esse CNPJ já está na base.'], 409);
  if ($d['tel1'] && one('SELECT id FROM empresas WHERE tel1 = ? OR tel2 = ?', [$d['tel1'], $d['tel1']])) json_out(['erro' => 'Esse telefone já está na base.'], 409);
  q("INSERT INTO empresas (fantasia, razao, responsavel, segmento, cnpj, tel1, tel2, email, logradouro, numero, bairro, cep, cidade, uf, lat, lng, geo, etapa, origem, criado_em, atualizado_em)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'Araguari', 'MG', ?, ?, ?, 'mapeado', 'manual', ?, ?)",
    array_merge(array_values($d), [$lat, $lng, $lat !== null ? 'manual' : null, agora(), agora()]));
  $nid = (int)db()->lastInsertId(); crm_hist($nid, $quem, 'cadastro', 'Cadastrada à mão'); json_out(['ok' => true, 'id' => $nid]);
}

case 'regiao': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  if (($e['o'] ?? '') === 'excluir') { q('DELETE FROM regioes WHERE id = ?', [(int)$e['id']]); auditar('admin:' . $quem, 'excluiu região', (string)(int)$e['id']); json_out(['ok' => true]); }
  $nome = $s('nome', 60); $poly = $e['poly'] ?? null;
  if ($nome === '') json_out(['erro' => 'Dê um nome à região.'], 422);
  if (!is_array($poly) || count($poly) < 3) json_out(['erro' => 'Desenhe pelo menos 3 pontos.'], 422);
  $poly = array_map(fn($p) => [round((float)$p[0], 6), round((float)$p[1], 6)], array_slice($poly, 0, 400));
  $cor = preg_match('/^#[0-9A-Fa-f]{6}$/', $s('cor', 7)) ? $s('cor', 7) : '#19F7FF';
  if ((int)($e['id'] ?? 0)) {
    q('UPDATE regioes SET nome = ?, cor = ?, poligono = ? WHERE id = ?', [$nome, $cor, json_encode($poly), (int)$e['id']]);
    /* região automática editada à mão vira manual: não é apagada quando refizerem as automáticas */
    $au = array_diff(array_filter(array_map('intval', explode(',', (string)conf('crm_reg_auto', '')))), [(int)$e['id']]);
    q("UPDATE config SET valor = ? WHERE chave = 'crm_reg_auto'", [implode(',', $au)]);
  }
  else q('INSERT INTO regioes (nome, cor, poligono, criado_em) VALUES (?,?,?,?)', [$nome, $cor, json_encode($poly), agora()]);
  auditar('admin:' . $quem, 'salvou região', $nome); json_out(['ok' => true]);
}

case 'regioes_auto': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  $k = max(3, min(12, (int)($e['k'] ?? 7)));
  $pts = all("SELECT lat, lng, bairro FROM empresas WHERE lat IS NOT NULL AND etapa <> 'perdido' AND " . CRM_VISIVEL);
  if (count($pts) < 12) json_out(['erro' => 'Mapeie mais empresas antes (pelo menos 12 no mapa).'], 422);
  $k = min($k, max(3, intdiv(count($pts), 12)));
  $P = array_map(fn($r) => [(float)$r['lat'], (float)$r['lng'], (string)$r['bairro']], $pts);
  /* sementes espalhadas: a 1ª é o ponto mais denso; as próximas, as mais distantes das já escolhidas */
  $d2 = fn($a, $b) => ($a[0] - $b[0]) ** 2 + (($a[1] - $b[1]) * 0.95) ** 2;
  $c = [$P[0]]; $best = -1;
  foreach ($P as $p) { $n = 0; foreach ($P as $q2) if ($d2($p, $q2) < 0.00004) $n++; if ($n > $best) { $best = $n; $c[0] = $p; } }
  while (count($c) < $k) { $far = null; $fd = -1; foreach ($P as $p) { $m = INF; foreach ($c as $cc) $m = min($m, $d2($p, $cc)); if ($m > $fd) { $fd = $m; $far = $p; } } $c[] = $far; }
  $grupo = [];
  for ($it = 0; $it < 30; $it++) {
    $grupo = array_fill(0, $k, []);
    foreach ($P as $p) { $bi = 0; $bd = INF; foreach ($c as $i => $cc) { $d = $d2($p, $cc); if ($d < $bd) { $bd = $d; $bi = $i; } } $grupo[$bi][] = $p; }
    $mov = 0; foreach ($grupo as $i => $g) if ($g) { $nc = [array_sum(array_column($g, 0)) / count($g), array_sum(array_column($g, 1)) / count($g)]; $mov += $d2($nc, $c[$i]); $c[$i] = $nc; }
    if ($mov < 1e-12) break;
  }
  /* contorno: fecho convexo do grupo, aberto ~250 m para fora */
  $hull = function (array $pts): array {
    $pts = array_values(array_unique(array_map(fn($p) => round($p[0], 5) . ',' . round($p[1], 5), $pts)));
    $pts = array_map(fn($s) => array_map('floatval', explode(',', $s)), $pts);
    usort($pts, fn($a, $b) => $a[1] <=> $b[1] ?: $a[0] <=> $b[0]);
    if (count($pts) < 3) return $pts;
    $cr = fn($o, $a, $b) => ($a[1] - $o[1]) * ($b[0] - $o[0]) - ($a[0] - $o[0]) * ($b[1] - $o[1]);
    $lo = []; foreach ($pts as $p) { while (count($lo) >= 2 && $cr($lo[count($lo) - 2], $lo[count($lo) - 1], $p) <= 0) array_pop($lo); $lo[] = $p; }
    $up = []; foreach (array_reverse($pts) as $p) { while (count($up) >= 2 && $cr($up[count($up) - 2], $up[count($up) - 1], $p) <= 0) array_pop($up); $up[] = $p; }
    array_pop($lo); array_pop($up); return array_merge($lo, $up);
  };
  $cores = ['#19F7FF', '#FFB547', '#B47CFF', '#32E000', '#FF5C7A', '#5B8CFF', '#FFD84D', '#FF8A3D', '#3DFFB4', '#F06BFF', '#9BE15D', '#7FD4FF'];
  $velhas = array_filter(array_map('intval', explode(',', (string)conf('crm_reg_auto', ''))));
  if ($velhas) q('DELETE FROM regioes WHERE id IN (' . implode(',', $velhas) . ')');
  $novas = []; $ordem = 0; $usados = [];
  foreach (all('SELECT nome FROM regioes') as $rr) $usados[mb_strtolower($rr['nome'])] = 1;
  usort($grupo, fn($a, $b) => count($b) <=> count($a));
  foreach ($grupo as $g) {
    if (count($g) < 3) continue;
    $cx = array_sum(array_column($g, 0)) / count($g); $cy = array_sum(array_column($g, 1)) / count($g);
    $h = $hull($g);
    if (count($h) < 3) { $h = []; for ($a = 0; $a < 8; $a++) $h[] = [$cx + 0.003 * sin($a * M_PI / 4), $cy + 0.0032 * cos($a * M_PI / 4)]; }
    $h = array_map(function ($p) use ($cx, $cy) { $dy = $p[0] - $cx; $dx = $p[1] - $cy; $n = sqrt($dy * $dy + $dx * $dx) ?: 1; return [round($p[0] + $dy / $n * 0.0023, 6), round($p[1] + $dx / $n * 0.0024, 6)]; }, $h);
    $b = []; foreach ($g as $p) if ($p[2] !== '') $b[$p[2]] = ($b[$p[2]] ?? 0) + 1; arsort($b);
    /* nome sem repetir: o bairro com mais empresas que ainda não deu nome; se todos já deram, o bairro + o lado da cidade */
    $nome = '';
    foreach (array_keys($b) as $bn) { $t = 'Região ' . crm_nome_proprio((string)$bn); if (!isset($usados[mb_strtolower($t)])) { $nome = $t; break; } }
    if ($nome === '') { $lado = abs($cx + 18.6475) > abs($cy + 48.1875) * .95 ? ($cx > -18.6475 ? 'Norte' : 'Sul') : ($cy > -48.1875 ? 'Leste' : 'Oeste');
      $nome = 'Região ' . ($b ? crm_nome_proprio((string)array_key_first($b)) . ' ' . $lado : $lado); $n2 = 2; $base = $nome;
      while (isset($usados[mb_strtolower($nome)])) $nome = $base . ' ' . $n2++; }
    $usados[mb_strtolower($nome)] = 1;
    q('INSERT INTO regioes (nome, cor, poligono, criado_em) VALUES (?,?,?,?)', [mb_substr($nome, 0, 60), $cores[$ordem % count($cores)], json_encode($h), agora()]);
    $novas[] = (int)db()->lastInsertId(); $ordem++;
  }
  if (one("SELECT chave FROM config WHERE chave = 'crm_reg_auto'")) q("UPDATE config SET valor = ? WHERE chave = 'crm_reg_auto'", [implode(',', $novas)]);
  else q("INSERT INTO config (chave, valor) VALUES ('crm_reg_auto', ?)", [implode(',', $novas)]);
  auditar('admin:' . $quem, 'gerou regiões automáticas', count($novas) . ' regiões');
  json_out(['ok' => true, 'msg' => count($novas) . ' regiões criadas pela concentração das empresas. As que vocês desenharam à mão continuam.']);
}

case 'importar': {
  /* 1º envio: prévia (colunas reconhecidas, amostra, contagem); 2º envio com "gravar": grava */
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  @set_time_limit(300);
  $tmpDir = sys_get_temp_dir();
  if (!empty($_FILES['arq']['tmp_name'])) {
    $nome = (string)$_FILES['arq']['name'];
    if (!preg_match('/\.(csv|txt|xlsx)$/i', $nome)) json_out(['erro' => 'Envie a planilha em .xlsx ou .csv.'], 422);
    if ((int)$_FILES['arq']['size'] > 40 * 1024 * 1024) json_out(['erro' => 'Planilha maior que 40 MB.'], 422);
    $tok = bin2hex(random_bytes(10)); $dest = "$tmpDir/hlcrm-$tok-" . (preg_match('/\.xlsx$/i', $nome) ? 'p.xlsx' : 'p.csv');
    if (!move_uploaded_file($_FILES['arq']['tmp_name'], $dest)) json_out(['erro' => 'Não consegui receber o arquivo.'], 500);
    $_SESSION['crm_imp'] = $dest;
  } else { $dest = (string)($_SESSION['crm_imp'] ?? ''); if (!$dest || !is_file($dest)) json_out(['erro' => 'Envie a planilha de novo.'], 422); }
  try { $rows = crm_ler_planilha($dest, $dest); } catch (Throwable $z) { json_out(['erro' => $z->getMessage()], 422); }
  if (count($rows) < 2) json_out(['erro' => 'A planilha está vazia.'], 422);
  $cab = array_shift($rows); $map = crm_mapear_colunas($cab);
  if (!isset($map['cnpj']) && !isset($map['tel1'])) json_out(['erro' => 'Não achei a coluna de CNPJ nem de telefone. Confira o cabeçalho da planilha.', 'cabecalho' => $cab], 422);
  $soCidade = ($e['so_cidade'] ?? '1') !== '0'; $soAtiva = ($e['so_ativa'] ?? '1') !== '0';
  $lin = []; $fora = 0; $inat = 0;
  foreach ($rows as $r) { if (!array_filter($r, fn($v) => trim((string)$v) !== '')) continue; $x = crm_linha($r, $map, $cab);
    if ($soCidade && $x['cidade'] && crm_sem_acento($x['cidade']) !== 'araguari') { $fora++; continue; }
    if ($soAtiva && $x['situacao'] && !str_contains($x['situacao'], 'ATIVA')) { $inat++; continue; }
    $lin[] = $x; }
  $cont = []; foreach ($lin as $x) if ($x['email']) $cont[$x['email']] = ($cont[$x['email']] ?? 0) + 1;
  foreach ($lin as &$x) if ($x['email'] && ($cont[$x['email']] >= 3 || preg_match('/^(abertura|contato|contabil|contabilidade)@|maismei/i', $x['email']))) $x['email'] = null; unset($x);
  if (($e['gravar'] ?? '') !== '1') {
    $ja = 0; foreach ($lin as $x) if (($x['cnpj'] && one('SELECT id FROM empresas WHERE cnpj = ?', [$x['cnpj']])) || ($x['tel1'] && one('SELECT id FROM empresas WHERE tel1 = ? OR tel2 = ?', [$x['tel1'], $x['tel1']]))) $ja++;
    $seg = []; foreach ($lin as $x) { $k = $x['segmento'] ?: 'Sem CNAE'; $seg[$k] = ($seg[$k] ?? 0) + 1; } arsort($seg);
    json_out(['previa' => true, 'total' => count($lin), 'ja' => $ja, 'fora_cidade' => $fora, 'inativas' => $inat,
      'sem_tel' => count(array_filter($lin, fn($x) => !$x['tel1'])), 'colunas' => array_map(fn($i) => (string)$cab[$i], $map),
      'nao_achou' => array_values(array_diff(['cnpj','fantasia','razao','cnae','tel1','logradouro','numero','bairro','cep'], array_keys($map))),
      'segmentos' => array_slice($seg, 0, 15, true), 'amostra' => array_map(fn($x) => [crm_nome($x), $x['segmento'], tel_br($x['tel1']), trim($x['logradouro'] . ', ' . $x['numero'] . ' · ' . $x['bairro'])], array_slice($lin, 0, 6))]);
  }
  $n = ['nova' => 0, 'atualizada' => 0]; db()->beginTransaction();
  try { foreach ($lin as $x) $n[crm_gravar_empresa($x, $quem)]++; db()->commit(); } catch (Throwable $z) { db()->rollBack(); json_out(['erro' => 'Falhou ao gravar: ' . $z->getMessage()], 500); }
  @unlink($dest); unset($_SESSION['crm_imp']);
  auditar('admin:' . $quem, 'importou planilha de empresas', "{$n['nova']} novas", "{$n['atualizada']} atualizadas");
  json_out(['ok' => true, 'msg' => "{$n['nova']} empresas novas e {$n['atualizada']} atualizadas. Agora clique em Mapear endereços.", 'geo' => crm_geo_status()]);
}

case 'geo': {
  if (!$post) json_out(['erro' => 'Use POST.'], 405);
  @set_time_limit(60);
  $st = crm_geo_lote(); crm_marcar_celulas(); if (isset($st['erro'])) json_out(['erro' => $st['erro'], 'geo' => $st], 503); json_out(['ok' => true, 'geo' => $st]);
}

default: json_out(['erro' => 'Ação desconhecida.'], 404);
}
