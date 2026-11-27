<?php
/* CRM do Hunter League: prospecção das empresas (base da Casa dos Dados), mapa de conquista, ligações, agenda
   das visitas e a ligação com o funil do app (cadastro, QR ativo, venda). Só a equipe da CallNet (admin) usa. */
declare(strict_types=1);

const CRM_ETAPAS = ['mapeado' => 'Mapeado', 'agendado' => 'Agendado', 'visitado' => 'Visitado', 'afiliado' => 'Afiliado',
  'conquistado' => 'QR ativo', 'venda' => 'Com venda', 'perdido' => 'Perdido'];
const CRM_PARECERES = ['agendou' => 'Agendou', 'depois' => 'Interesse, mas não agora', 'nao_atendeu' => 'Não atendeu',
  'nao_quer' => 'Não quer', 'numero_errado' => 'Número errado', 'fechou' => 'Fechou'];
const CRM_SAFRA_DIAS = 7;          /* "interesse, mas não agora" volta na próxima safra */
const CRM_RETENTATIVAS = [1, 3, 7]; /* não atendeu: amanhã, em 3 dias, em 7 dias; depois encerra */

/* segmentos pelo CNAE (7 dígitos); o resto fica com a descrição do próprio CNAE */
const CRM_SEGMENTOS = ['9602501' => 'Salão de beleza', '9602502' => 'Manicure e estética', '4744099' => 'Material de construção',
  '4744001' => 'Material de construção', '4744005' => 'Material de construção', '9512600' => 'Assistência de celular',
  '9511800' => 'Assistência de computador', '4520001' => 'Oficina mecânica', '4520006' => 'Borracharia', '4520005' => 'Lava-jato'];

/* cria as tabelas na primeira vez (produção já instalada) */
function crm_migrar(): void {
  static $ok = false; if ($ok) return; $ok = true;
  if (conf('crm_v') === '1') return;
  $sq = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $id = $sq ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $ref = $sq ? 'INTEGER' : 'INT UNSIGNED';
  $eng = $sq ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $sqls = [
"CREATE TABLE IF NOT EXISTS empresas (
  id $id, cnpj VARCHAR(14) NULL, razao VARCHAR(160) NULL, fantasia VARCHAR(160) NULL, responsavel VARCHAR(120) NULL,
  cnae VARCHAR(7) NULL, cnae_desc VARCHAR(200) NULL, segmento VARCHAR(60) NULL, mei TINYINT NOT NULL DEFAULT 0,
  abertura DATE NULL, situacao VARCHAR(30) NULL, tel1 VARCHAR(11) NULL, tel2 VARCHAR(11) NULL, tel_ok VARCHAR(4) NULL, email VARCHAR(160) NULL,
  cep CHAR(8) NULL, logradouro VARCHAR(160) NULL, numero VARCHAR(20) NULL, complemento VARCHAR(120) NULL, bairro VARCHAR(100) NULL,
  cidade VARCHAR(80) NULL, uf CHAR(2) NULL, lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL, geo VARCHAR(10) NULL, celula VARCHAR(40) NULL,
  etapa VARCHAR(20) NOT NULL DEFAULT 'mapeado', motivo VARCHAR(80) NULL, proximo_em DATETIME NULL, tentativas INT NOT NULL DEFAULT 0,
  visita_dia DATE NULL, visita_turno VARCHAR(10) NULL, visita_quem VARCHAR(60) NULL, parceiro_id $ref NULL,
  origem VARCHAR(20) NOT NULL DEFAULT 'planilha', obs TEXT NULL, extra TEXT NULL, criado_em DATETIME NOT NULL, atualizado_em DATETIME NOT NULL
)$eng",
"CREATE TABLE IF NOT EXISTS empresa_hist (
  id $id, empresa_id $ref NOT NULL, em DATETIME NOT NULL, quem VARCHAR(60) NOT NULL, tipo VARCHAR(20) NOT NULL, texto VARCHAR(400) NULL
)$eng",
"CREATE TABLE IF NOT EXISTS regioes (
  id $id, nome VARCHAR(60) NOT NULL, cor VARCHAR(9) NOT NULL DEFAULT '#19F7FF', poligono TEXT NOT NULL, criado_em DATETIME NOT NULL
)$eng",
"CREATE TABLE IF NOT EXISTS geocache (
  chave VARCHAR(190) NOT NULL PRIMARY KEY, lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL, em DATETIME NOT NULL
)$eng"];
  foreach ($sqls as $s) db()->exec($s);
  foreach (["CREATE UNIQUE INDEX idx_emp_cnpj ON empresas (cnpj)", "CREATE INDEX idx_emp_tel ON empresas (tel1)", "CREATE INDEX idx_emp_etapa ON empresas (etapa, proximo_em)",
            "CREATE INDEX idx_emp_par ON empresas (parceiro_id)", "CREATE INDEX idx_eh ON empresa_hist (empresa_id, em)"] as $s) { try { db()->exec($s); } catch (Throwable $e) {} }
  q("INSERT INTO config (chave, valor) VALUES ('crm_v', '1')");
}

function crm_hist(int $eid, string $quem, string $tipo, string $texto = ''): void {
  q('INSERT INTO empresa_hist (empresa_id, em, quem, tipo, texto) VALUES (?,?,?,?,?)', [$eid, agora(), $quem, $tipo, mb_substr($texto, 0, 400)]);
}

/* ---------- planilha (CSV ou Excel) ---------- */
function crm_sem_acento(string $t): string {
  $t = mb_strtolower(trim($t));
  return strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c','º'=>'o','ª'=>'a']);
}
/* lê CSV (; ou ,) ou XLSX e devolve as linhas (a 1ª é o cabeçalho) */
function crm_ler_planilha(string $arq, string $nome): array {
  if (preg_match('/\.xlsx$/i', $nome)) return crm_ler_xlsx($arq);
  $txt = (string)file_get_contents($arq);
  if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
  $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
  $l1 = strtok($txt, "\n");
  $sep = substr_count((string)$l1, ';') >= substr_count((string)$l1, ',') ? ';' : ',';
  if (substr_count((string)$l1, "\t") > substr_count((string)$l1, $sep)) $sep = "\t";
  $f = fopen('php://memory', 'r+'); fwrite($f, $txt); rewind($f);
  $rows = []; while (($r = fgetcsv($f, 0, $sep, '"', '\\')) !== false) if ($r !== [null]) $rows[] = $r;
  fclose($f); return $rows;
}
function crm_ler_xlsx(string $arq): array {
  $z = new ZipArchive(); if ($z->open($arq) !== true) throw new RuntimeException('Não consegui abrir o Excel. Salve como .xlsx ou CSV.');
  $ss = [];
  if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
    $sx = simplexml_load_string($x); foreach ($sx->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $ss[] = $t; }
  }
  $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
  if ($sheet === false) { for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheet = $z->getFromName($n); break; } } }
  $z->close(); if (!$sheet) throw new RuntimeException('O Excel não tem planilha.');
  $sx = simplexml_load_string($sheet); $rows = [];
  foreach ($sx->sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
      $ref = (string)$c['r']; preg_match('/^([A-Z]+)/', $ref, $m); $col = 0; foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + ord($ch) - 64; $col--;
      $t = (string)$c['t']; $v = isset($c->v) ? (string)$c->v : (isset($c->is->t) ? (string)$c->is->t : '');
      if ($t === 's') $v = $ss[(int)$v] ?? '';
      $r[$col] = $v;
    }
    if ($r) { $max = max(array_keys($r)); $linha = []; for ($i = 0; $i <= $max; $i++) $linha[] = $r[$i] ?? ''; $rows[] = $linha; }
  }
  return $rows;
}
/* reconhece as colunas pelo nome (Casa dos Dados e outras exportações) */
function crm_mapear_colunas(array $cab): array {
  $alvo = [
    'cnpj' => ['cnpj'], 'razao' => ['razao social', 'razao'], 'fantasia' => ['nome fantasia', 'fantasia'],
    'responsavel' => ['socio', 'responsavel', 'nome do socio', 'socios'], 'cnae' => ['cnae principal codigo', 'codigo cnae', 'cnae principal', 'cnae', 'atividade principal codigo', 'codigo atividade principal'],
    'cnae_desc' => ['cnae principal descricao', 'descricao cnae', 'atividade principal', 'descricao atividade principal', 'atividade principal descricao'],
    'mei' => ['mei', 'optante mei', 'opcao pelo mei', 'opcao mei'], 'abertura' => ['data de abertura', 'data abertura', 'abertura', 'data inicio atividade', 'inicio atividade'],
    'situacao' => ['situacao cadastral', 'situacao'], 'tel1' => ['telefone 1', 'telefone1', 'telefone', 'ddd + telefone 1', 'telefones', 'contato telefonico', 'celular'],
    'tel2' => ['telefone 2', 'telefone2'], 'email' => ['e-mail', 'email', 'correio eletronico'], 'cep' => ['cep'],
    'logradouro' => ['logradouro', 'endereco', 'rua'], 'tipo_log' => ['tipo logradouro', 'tipo de logradouro'], 'numero' => ['numero', 'n'],
    'complemento' => ['complemento'], 'bairro' => ['bairro'], 'cidade' => ['municipio', 'cidade'], 'uf' => ['uf', 'estado']];
  $n = array_map(fn($c) => trim(preg_replace('/[^a-z0-9+ -]/', ' ', crm_sem_acento((string)$c))), $cab);
  $map = [];
  foreach ($alvo as $k => $nomes) foreach ($nomes as $nm) { $i = array_search($nm, $n, true); if ($i !== false && !in_array($i, $map, true)) { $map[$k] = $i; break; } }
  foreach ($alvo as $k => $nomes) if (!isset($map[$k])) foreach ($n as $i => $c) foreach ($nomes as $nm) if (strlen($nm) > 3 && str_contains($c, $nm) && !in_array($i, $map, true)) { $map[$k] = $i; break 2; }
  return $map;
}
function crm_tel(string $t): string {
  $d = preg_replace('/\D/', '', $t);
  if (strlen($d) > 11 && str_starts_with($d, '55')) $d = substr($d, 2);
  if (strlen($d) === 8 || strlen($d) === 9) $d = '34' . $d;
  return strlen($d) === 10 || strlen($d) === 11 ? $d : '';
}
function crm_data(string $t): ?string {
  $t = trim($t); if ($t === '') return null;
  if (is_numeric($t) && (float)$t > 20000) return date('Y-m-d', (int)(((float)$t - 25569) * 86400));
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $t, $m)) return "$m[3]-$m[2]-$m[1]";
  if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m)) return "$m[1]-$m[2]-$m[3]";
  return null;
}
/* uma linha da planilha → empresa normalizada */
function crm_linha(array $r, array $map, array $cab): array {
  $g = fn($k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
  $tels = []; foreach (preg_split('/[\/,;|]+/', $g('tel1') . ',' . $g('tel2')) as $t) { $t = crm_tel($t); if ($t && !in_array($t, $tels, true)) $tels[] = $t; }
  $cnae = substr(preg_replace('/\D/', '', $g('cnae')), 0, 7);
  $desc = $g('cnae_desc'); if ($desc === '' && preg_match('/\d[\d.\/-]+\s*[-–]\s*(.+)/u', $g('cnae'), $m)) $desc = $m[1];
  $log = trim($g('tipo_log') . ' ' . $g('logradouro'));
  $mei = crm_sem_acento($g('mei'));
  $extra = []; foreach ($cab as $i => $c) { $v = trim((string)($r[$i] ?? '')); if ($v !== '' && !in_array($i, $map, true)) $extra[(string)$c] = mb_substr($v, 0, 300); }
  $razao = mb_substr($g('razao'), 0, 160);
  $resp = $g('responsavel');
  if ($resp === '' && preg_match('/^\d{2}\.?\d{3}\.?\d{3}\s+(.+)$/u', $razao, $m)) $resp = $m[1]; /* MEI: "12.345.678 FULANO DE TAL" */
  return [
    'cnpj' => substr(preg_replace('/\D/', '', $g('cnpj')), 0, 14) ?: null, 'razao' => $razao ?: null, 'fantasia' => mb_substr($g('fantasia'), 0, 160) ?: null,
    'responsavel' => mb_substr(mb_convert_case(mb_strtolower($resp), MB_CASE_TITLE), 0, 120) ?: null,
    'cnae' => $cnae ?: null, 'cnae_desc' => mb_substr($desc, 0, 200) ?: null, 'segmento' => CRM_SEGMENTOS[$cnae] ?? (mb_substr($desc, 0, 60) ?: null),
    'mei' => in_array($mei, ['sim', 's', 'true', '1', 'optante', 'x'], true) || ($mei === '' && str_contains(crm_sem_acento($razao), ' mei')) ? 1 : 0,
    'abertura' => crm_data($g('abertura')), 'situacao' => mb_substr(mb_strtoupper($g('situacao')), 0, 30) ?: null,
    'tel1' => $tels[0] ?? null, 'tel2' => $tels[1] ?? null, 'email' => mb_substr(mb_strtolower($g('email')), 0, 160) ?: null,
    'cep' => substr(preg_replace('/\D/', '', $g('cep')), 0, 8) ?: null, 'logradouro' => mb_substr($log, 0, 160) ?: null, 'numero' => mb_substr($g('numero'), 0, 20) ?: null,
    'complemento' => mb_substr($g('complemento'), 0, 120) ?: null, 'bairro' => mb_substr(mb_convert_case(mb_strtolower($g('bairro')), MB_CASE_TITLE), 0, 100) ?: null,
    'cidade' => mb_substr(mb_convert_case(mb_strtolower($g('cidade')), MB_CASE_TITLE), 0, 80) ?: null, 'uf' => mb_strtoupper(substr($g('uf'), 0, 2)) ?: null,
    'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null];
}
/* grava (não repete pelo CNPJ nem pelo telefone; atualiza sem perder o histórico) */
function crm_gravar_empresa(array $e, string $quem): string {
  $ja = null;
  if ($e['cnpj']) $ja = one('SELECT id FROM empresas WHERE cnpj = ?', [$e['cnpj']]);
  if (!$ja && $e['tel1']) $ja = one('SELECT id FROM empresas WHERE tel1 = ? OR tel2 = ?', [$e['tel1'], $e['tel1']]);
  if ($ja) {
    $set = []; $par = [];
    foreach (['razao','fantasia','responsavel','cnae','cnae_desc','segmento','abertura','situacao','tel2','email','cep','logradouro','numero','complemento','bairro','cidade','uf','extra'] as $k)
      if ($e[$k] !== null && $e[$k] !== '') { $set[] = "$k = COALESCE($k, ?)"; $par[] = $e[$k]; }
    if ($set) q('UPDATE empresas SET ' . implode(', ', $set) . ', atualizado_em = ? WHERE id = ?', array_merge($par, [agora(), $ja['id']]));
    return 'atualizada';
  }
  $cols = array_keys($e);
  q('INSERT INTO empresas (' . implode(',', $cols) . ', etapa, origem, criado_em, atualizado_em) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ", 'mapeado', 'planilha', ?, ?)",
    array_merge(array_values($e), [agora(), agora()]));
  return 'nova';
}

/* ---------- mapa: endereço → ponto (OpenStreetMap/Nominatim, 1 por segundo, com cache) ---------- */
function crm_pausa(): void { if (empty(cfg()['geo_teste'])) usleep(1100000); }
/* devolve [lat, lng], null (endereço não achado) ou false (sem conexão com o serviço: tenta depois) */
function crm_geocodificar(string $consulta): array|false|null {
  $ch = mb_substr(crm_sem_acento($consulta), 0, 190);
  if (!empty(cfg()['geo_teste'])) { $hsh = crc32($consulta); return [round(-18.635 - ($hsh % 300) / 10000, 6), round(-48.165 - (intdiv($hsh, 300) % 450) / 10000, 6)]; } /* só no teste local */
  if (!function_exists('curl_init')) return false;
  $c = one('SELECT lat, lng FROM geocache WHERE chave = ?', [$ch]);
  if ($c) return $c['lat'] !== null ? [(float)$c['lat'], (float)$c['lng']] : null;
  $u = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $consulta, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'br',
    'viewbox' => '-48.30,-18.56,-48.08,-18.74', 'bounded' => 1]);
  $h = curl_init($u);
  curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['User-Agent: HunterLeague-CRM/1.0 (ti@callnetbrasil.com.br)', 'Accept-Language: pt-BR']]);
  $txt = curl_exec($h); $cod = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE); curl_close($h);
  if ($txt === false || $cod !== 200) return false; /* falha de rede: não grava no cache, tenta de novo depois */
  $j = json_decode((string)$txt, true); $p = $j[0] ?? null;
  $ll = $p ? [round((float)$p['lat'], 6), round((float)$p['lon'], 6)] : null;
  q('INSERT INTO geocache (chave, lat, lng, em) VALUES (?,?,?,?)', [$ch, $ll[0] ?? null, $ll[1] ?? null, agora()]);
  return $ll;
}
/* um lote: primeiro os bairros (rápido, já dá a contagem por região), depois as ruas */
function crm_geo_lote(int $max = 8, int $prazo = 22): array {
  $ini = time(); $feitas = 0; $cidade = 'Araguari, MG';
  while ($feitas < $max && time() - $ini < $prazo) {
    $e = one("SELECT id, logradouro, bairro, cep, geo FROM empresas WHERE (geo IS NULL OR geo = 'bairro') AND (logradouro IS NOT NULL OR bairro IS NOT NULL OR cep IS NOT NULL) ORDER BY CASE WHEN geo IS NULL THEN 0 ELSE 1 END, id LIMIT 1");
    if (!$e) break;
    if ($e['geo'] === null) {
      /* etapa 1: centro do bairro (ou do CEP) para todas as empresas daquele bairro */
      $alvo = $e['bairro'] ? $e['bairro'] . ', ' . $cidade : null;
      $ll = $alvo ? crm_geocodificar($alvo) : null; crm_pausa(); $feitas++;
      if ($ll === false) return crm_geo_status() + ['erro' => 'O serviço de mapas não respondeu. Tente de novo em alguns minutos.'];
      if (!$ll && $e['cep']) { $ll = crm_geocodificar(substr($e['cep'], 0, 5) . '-' . substr($e['cep'], 5) . ', ' . $cidade); crm_pausa(); $feitas++; if ($ll === false) return crm_geo_status() + ['erro' => 'O serviço de mapas não respondeu. Tente de novo em alguns minutos.']; }
      if ($ll) q("UPDATE empresas SET lat = ?, lng = ?, geo = 'bairro', celula = NULL WHERE geo IS NULL AND " . ($e['bairro'] ? 'bairro = ?' : 'id = ?'), [$ll[0] + 0, $ll[1] + 0, $e['bairro'] ?: $e['id']]);
      else q("UPDATE empresas SET geo = 'falhou' WHERE id = ?", [$e['id']]);
      continue;
    }
    /* etapa 2: a rua (todas da mesma rua e bairro de uma vez) */
    if (!$e['logradouro']) { q("UPDATE empresas SET geo = 'bairro_ok' WHERE id = ?", [$e['id']]); continue; }
    $ll = crm_geocodificar($e['logradouro'] . ', ' . ($e['bairro'] ? $e['bairro'] . ', ' : '') . $cidade); crm_pausa(); $feitas++;
    if ($ll === false) return crm_geo_status() + ['erro' => 'O serviço de mapas não respondeu. Tente de novo em alguns minutos.'];
    if ($ll) q("UPDATE empresas SET lat = ?, lng = ?, geo = 'rua', celula = NULL WHERE geo = 'bairro' AND logradouro = ? AND COALESCE(bairro, '') = ?", [$ll[0], $ll[1], $e['logradouro'], (string)$e['bairro']]);
    else q("UPDATE empresas SET geo = 'bairro_ok' WHERE geo = 'bairro' AND logradouro = ? AND COALESCE(bairro, '') = ?", [$e['logradouro'], (string)$e['bairro']]);
  }
  return crm_geo_status();
}
function crm_geo_status(): array {
  $r = one("SELECT COUNT(*) AS t, SUM(CASE WHEN geo IS NULL THEN 1 ELSE 0 END) AS sem, SUM(CASE WHEN geo = 'bairro' THEN 1 ELSE 0 END) AS bairro,
    SUM(CASE WHEN geo IN ('rua', 'manual') THEN 1 ELSE 0 END) AS rua, SUM(CASE WHEN geo = 'bairro_ok' THEN 1 ELSE 0 END) AS so_bairro,
    SUM(CASE WHEN geo = 'falhou' THEN 1 ELSE 0 END) AS falhou FROM empresas");
  return array_map('intval', $r ?: []);
}

/* ---------- células da Nio (KMZ) e ponto dentro do polígono ---------- */
function crm_celulas(): array { static $c = null; return $c ??= (require __DIR__ . '/../admin/_celulas.php'); }
function crm_dentro(float $lat, float $lng, array $poly): bool {
  $o = false; $n = count($poly);
  for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) { [$yi, $xi] = $poly[$i]; [$yj, $xj] = $poly[$j];
    if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $o = !$o; }
  return $o;
}
function crm_celula_de(?float $lat, ?float $lng): ?string {
  if ($lat === null || $lng === null) return null;
  foreach (crm_celulas() as $c) foreach ($c['polys'] as $p) if (crm_dentro($lat, $lng, $p)) return $c['nome'];
  return null;
}
function crm_marcar_celulas(): void {
  foreach (all("SELECT id, lat, lng FROM empresas WHERE lat IS NOT NULL AND (celula IS NULL OR celula = '') LIMIT 3000") as $e)
    q('UPDATE empresas SET celula = ? WHERE id = ?', [crm_celula_de((float)$e['lat'], (float)$e['lng']) ?? '-', $e['id']]);
}

/* ---------- ligação com o app: a empresa que se cadastra no Hunter League muda de cor sozinha ---------- */
function crm_sincronizar(): void {
  /* liga pelo CNPJ (doc do parceiro) ou pelo WhatsApp do cadastro */
  foreach (all("SELECT p.id, p.doc, p.whatsapp FROM parceiros p WHERE p.status IN ('pendente', 'ativo') AND NOT EXISTS (SELECT 1 FROM empresas e WHERE e.parceiro_id = p.id) LIMIT 500") as $p) {
    $e = null;
    if (strlen((string)$p['doc']) === 14) $e = one('SELECT id FROM empresas WHERE cnpj = ? AND parceiro_id IS NULL', [$p['doc']]);
    if (!$e && $p['whatsapp']) $e = one('SELECT id FROM empresas WHERE (tel1 = ? OR tel2 = ?) AND parceiro_id IS NULL', [$p['whatsapp'], $p['whatsapp']]);
    if ($e) { q('UPDATE empresas SET parceiro_id = ?, atualizado_em = ? WHERE id = ?', [$p['id'], agora(), $e['id']]); crm_hist((int)$e['id'], 'sistema', 'app', 'Cadastrou no app Hunter League'); }
  }
  /* etapa segue o app: cadastrado → afiliado; QR ativo → conquistado; venda (agendada ou instalada) → venda */
  foreach (all("SELECT e.id, e.etapa, p.status, p.codigo,
      (SELECT COUNT(*) FROM indicacoes i WHERE i.parceiro_id = p.id AND i.status IN ('Agendado', 'Instalado')) AS vendas
      FROM empresas e JOIN parceiros p ON p.id = e.parceiro_id") as $r) {
    $nova = $r['vendas'] > 0 ? 'venda' : ($r['status'] === 'ativo' && $r['codigo'] ? 'conquistado' : 'afiliado');
    if ($nova !== $r['etapa']) { q('UPDATE empresas SET etapa = ?, proximo_em = NULL, atualizado_em = ? WHERE id = ?', [$nova, agora(), $r['id']]); crm_hist((int)$r['id'], 'sistema', 'etapa', CRM_ETAPAS[$nova]); }
  }
}

/* ---------- parecer da ligação ---------- */
function crm_parecer(array $e, string $p, array $d, string $quem): string {
  $id = (int)$e['id']; $agora = agora();
  $tel = in_array($d['tel'] ?? '', ['1', '2'], true) ? $d['tel'] : null;
  switch ($p) {
    case 'agendou':
      $dia = crm_data((string)($d['dia'] ?? '')) ?: date('Y-m-d', strtotime('+1 day'));
      $turno = ($d['turno'] ?? '') === 'tarde' ? 'tarde' : 'manha';
      q("UPDATE empresas SET etapa = 'agendado', visita_dia = ?, visita_turno = ?, visita_quem = ?, proximo_em = NULL, motivo = NULL, tel_ok = COALESCE(?, tel_ok), atualizado_em = ? WHERE id = ?",
        [$dia, $turno, mb_substr((string)($d['quem'] ?? ''), 0, 60) ?: null, $tel, $agora, $id]);
      $txt = 'Agendou visita: ' . date('d/m', strtotime($dia)) . ' · ' . ($turno === 'tarde' ? 'tarde' : 'manhã'); break;
    case 'depois':
      q('UPDATE empresas SET proximo_em = ?, tentativas = 0, tel_ok = COALESCE(?, tel_ok), atualizado_em = ? WHERE id = ?', [date('Y-m-d 09:00:00', strtotime('+' . CRM_SAFRA_DIAS . ' days')), $tel, $agora, $id]);
      $txt = 'Interesse, mas não agora: volta na próxima safra (' . CRM_SAFRA_DIAS . ' dias)'; break;
    case 'nao_atendeu':
      $n = (int)$e['tentativas'] + 1;
      if ($n > count(CRM_RETENTATIVAS)) { q("UPDATE empresas SET etapa = 'perdido', motivo = 'Sem resposta', tentativas = ?, proximo_em = NULL, atualizado_em = ? WHERE id = ?", [$n, $agora, $id]); $txt = "Não atendeu ({$n}ª): encerrado sem resposta"; }
      else { q('UPDATE empresas SET tentativas = ?, proximo_em = ?, atualizado_em = ? WHERE id = ?', [$n, date('Y-m-d 09:00:00', strtotime('+' . CRM_RETENTATIVAS[$n - 1] . ' days')), $agora, $id]); $txt = "Não atendeu ({$n}ª tentativa): tenta de novo em " . CRM_RETENTATIVAS[$n - 1] . ' dia(s)'; }
      break;
    case 'nao_quer':
      $m = mb_substr((string)($d['motivo'] ?? ''), 0, 80) ?: 'Não quer';
      q("UPDATE empresas SET etapa = 'perdido', motivo = ?, proximo_em = NULL, tel_ok = COALESCE(?, tel_ok), atualizado_em = ? WHERE id = ?", [$m, $tel, $agora, $id]); $txt = 'Não quer · ' . $m; break;
    case 'numero_errado':
      if ($e['tel2'] && ($d['tel'] ?? '1') === '1') { q('UPDATE empresas SET tel1 = tel2, tel2 = NULL, atualizado_em = ? WHERE id = ?', [$agora, $id]); $txt = 'Número errado: passou a usar o telefone 2'; }
      else { q("UPDATE empresas SET etapa = 'perdido', motivo = 'Número errado', proximo_em = NULL, atualizado_em = ? WHERE id = ?", [$agora, $id]); $txt = 'Número errado'; }
      break;
    case 'fechou':
      q("UPDATE empresas SET etapa = 'perdido', motivo = 'Empresa fechou', proximo_em = NULL, atualizado_em = ? WHERE id = ?", [$agora, $id]); $txt = 'Empresa fechou'; break;
    default: throw new InvalidArgumentException('Parecer inválido.');
  }
  $obs = trim(mb_substr((string)($d['obs'] ?? ''), 0, 300));
  crm_hist($id, $quem, 'ligacao', $txt . ($obs !== '' ? ' · ' . $obs : ''));
  return $txt;
}
/* o próximo da fila: retornos vencidos primeiro, depois quem nunca foi ligado; mesma região quando houver */
function crm_proximo(?array $regiao, int $exceto = 0): ?array {
  $base = "SELECT id, lat, lng FROM empresas WHERE etapa = 'mapeado' AND id <> ? AND (tel1 IS NOT NULL) AND (proximo_em IS NULL OR proximo_em <= ?)";
  $rows = all($base . " ORDER BY CASE WHEN proximo_em IS NULL THEN 1 ELSE 0 END, proximo_em, CASE WHEN celula IS NOT NULL AND celula <> '-' THEN 0 ELSE 1 END, id LIMIT 4000", [$exceto, agora()]);
  if ($regiao) foreach ($rows as $r) if ($r['lat'] !== null && crm_dentro((float)$r['lat'], (float)$r['lng'], $regiao)) return $r;
  return $rows[0] ?? null;
}
