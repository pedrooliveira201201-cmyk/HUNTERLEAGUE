<?php
/* CRM do Hunter League: prospecção das empresas (base da Casa dos Dados), mapa de conquista, ligações, agenda
   das visitas e a ligação com o funil do app (cadastro, QR ativo, venda). Só a equipe da CallNet (admin) usa. */
declare(strict_types=1);

const CRM_ETAPAS = ['mapeado' => 'Mapeado', 'agendado' => 'Agendado', 'visitado' => 'Visitado', 'afiliado' => 'Afiliado',
  'conquistado' => 'QR ativo', 'venda' => 'Com venda', 'perdido' => 'Perdido'];
const CRM_PARECERES = ['agendou' => 'Agendou', 'depois' => 'Interesse, mas não agora', 'nao_atendeu' => 'Não atendeu',
  'aguardando_zap' => 'Mandei WhatsApp', 'nao_quer' => 'Não quer', 'numero_errado' => 'Número errado', 'fechou' => 'Fechou'];
/* fora do cabo (endereço achado pela rua e fora das células da Nio): guardada, não aparece */
const CRM_VISIVEL = "NOT (COALESCE(geo, '') = 'rua' AND COALESCE(celula, '') = '-')";
const CRM_SAFRA_DIAS = 7;          /* "interesse, mas não agora" volta na próxima safra */
const CRM_RETENTATIVAS = [1, 3, 7]; /* não atendeu: amanhã, em 3 dias, em 7 dias; depois encerra */
/* mandou WhatsApp e espera a resposta: sai da fila de ligação; sem resposta, volta sozinho depois deste prazo */
const CRM_ZAP_DIAS = 2;
function crm_msg_padrao(): string { return (string)conf('crm_msg_wa', "Oi, {nome}! Aqui é o {eu}, da CALLNET. Estamos abrindo em Araguari um programa de parceria para negócios como o seu: renda extra por indicação, sem investimento e sem estoque. Amanhã nosso representante está na cidade. Ele pode passar aí para te apresentar a oportunidade de manhã ou à tarde, por 15 minutos?"); }
const CRM_ZAP_ESPERA = 'Aguardando WhatsApp';
const CRM_ZAP_RESPONDEU = 'Respondeu no WhatsApp';

/* segmentos pelo CNAE (7 dígitos); o resto fica com a descrição do próprio CNAE */
const CRM_SEGMENTOS = ['9602501' => 'Salão de beleza', '9602502' => 'Manicure e estética', '4744099' => 'Material de construção',
  '4744001' => 'Material de construção', '4744005' => 'Material de construção', '9512600' => 'Assistência de celular',
  '9511800' => 'Assistência de computador', '4520001' => 'Oficina mecânica', '4520006' => 'Borracharia', '4520005' => 'Lava-jato'];

/* cria as tabelas na primeira vez (produção já instalada) */
/* põe o 9 nos celulares antigos já gravados (uma vez só) */
function crm_corrige_9(): void {
  $faz = function (string $tab, array $cols) {
    foreach ($cols as $c) foreach (all("SELECT id, $c AS t FROM $tab WHERE $c IS NOT NULL AND LENGTH($c) = 10") as $r) {
      $n = tel_9((string)$r['t']); if ($n !== $r['t']) { try { q("UPDATE $tab SET $c = ? WHERE id = ?", [$n, $r['id']]); } catch (Throwable $e) {} }
    }
  };
  try { $faz('empresas', ['tel1', 'tel2']); } catch (Throwable $e) {}
  try { $faz('indicacoes', ['cliente_tel']); } catch (Throwable $e) {}
  try { $faz('parceiros', ['whatsapp']); } catch (Throwable $e) {}
}
function crm_migrar(): void {
  static $ok = false; if ($ok) return; $ok = true;
  $v = (string)conf('crm_v', '');
  if ($v === '3') return;
  if ($v === '1' || $v === '2') {
    /* v2: a célula 40 vinha com o nome "40 (ARI)SECUNDÁRIA" no arquivo da Nio */
    q("UPDATE empresas SET celula = '40 (ARI)' WHERE celula = '40 (ARI)SECUNDÁRIA'");
    /* v3: celulares antigos sem o 9 (planilha da Casa dos Dados, indicações e cadastros) */
    crm_corrige_9();
    q("UPDATE config SET valor = '3' WHERE chave = 'crm_v'"); return; }
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
  q("INSERT INTO config (chave, valor) VALUES ('crm_v', '3')");
  crm_corrige_9();
}

/* nome que aparece: fantasia; no MEI sem fantasia, o nome do dono */
function crm_nome(array $e): string { return (string)($e['fantasia'] ?: ($e['responsavel'] ?: ($e['razao'] ?? 'Empresa'))); }

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
    'responsavel' => ['responsavel', 'nome do socio', 'socio', 'socios'], 'cnae' => ['codigo da atividade principal', 'cnae principal codigo', 'codigo cnae', 'cnae principal', 'cnae', 'atividade principal codigo', 'codigo atividade principal'],
    'cnae_desc' => ['descricao da atividade principal', 'cnae principal descricao', 'descricao cnae', 'descricao atividade principal', 'atividade principal descricao', 'atividade principal'],
    'mei' => ['optante mei', 'opcao pelo mei', 'opcao mei', 'mei'], 'abertura' => ['data de abertura', 'data abertura', 'abertura', 'data inicio atividade', 'inicio atividade'],
    'situacao' => ['situacao cadastral', 'situacao'], 'tel1' => ['telefone 1', 'telefone1', 'telefone', 'ddd + telefone 1', 'telefones', 'contato telefonico', 'celular'],
    'tel2' => ['telefone 2', 'telefone2'], 'email' => ['e-mail', 'email', 'correio eletronico'], 'cep' => ['cep'],
    'logradouro' => ['logradouro', 'endereco', 'rua'], 'tipo_log' => ['tipo logradouro', 'tipo de logradouro'], 'numero' => ['numero', 'n'],
    'complemento' => ['complemento'], 'bairro' => ['bairro'], 'cidade' => ['municipio', 'cidade'], 'uf' => ['uf', 'estado']];
  $n = array_map(fn($c) => trim(preg_replace('/[^a-z0-9+ -]/', ' ', crm_sem_acento((string)$c))), $cab);
  $map = [];
  foreach ($alvo as $k => $nomes) foreach ($nomes as $nm) { $i = array_search($nm, $n, true); if ($i !== false && !in_array($i, $map, true)) { $map[$k] = $i; break; } }
  foreach ($alvo as $k => $nomes) if (!isset($map[$k])) foreach ($n as $i => $c) foreach ($nomes as $nm) {
    if (strlen($nm) <= 3 || !str_contains($c, $nm) || in_array($i, $map, true) || str_contains($c, 'secundaria')) continue;
    if ($k === 'cnae_desc' && str_contains($c, 'codigo')) continue;
    if ($k === 'cnae' && str_contains($c, 'descricao')) continue;
    $map[$k] = $i; break 2; }
  return $map;
}
function crm_nome_proprio(string $t): string {
  $t = mb_convert_case(mb_strtolower(trim($t)), MB_CASE_TITLE);
  return (string)preg_replace_callback('/ (D[aeo]s?|E) /u', fn($m) => mb_strtolower($m[0]), $t);
}
function crm_corta(string $t, int $max): string {
  $t = trim($t); if (mb_strlen($t) <= $max) return $t;
  $c = mb_substr($t, 0, $max - 1); $p = mb_strrpos($c, ' '); return rtrim($p > 20 ? mb_substr($c, 0, $p) : $c, ' ,;') . '…';
}
function crm_tel(string $t): string {
  $d = preg_replace('/\D/', '', $t);
  if (strlen($d) > 11 && str_starts_with($d, '55')) $d = substr($d, 2);
  if (strlen($d) === 8 || strlen($d) === 9) $d = '34' . $d;
  $d = tel_9($d);   /* celular antigo sem o 9: sem ele o WhatsApp não abre e a ligação não completa */
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
    'responsavel' => mb_substr(crm_nome_proprio($resp), 0, 120) ?: null,
    'cnae' => $cnae ?: null, 'cnae_desc' => mb_substr($desc, 0, 200) ?: null, 'segmento' => crm_corta($desc, 60) ?: (CRM_SEGMENTOS[$cnae] ?? null),
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
  /* sem CNPJ, o telefone identifica; com CNPJ, só o CNPJ (dois MEIs podem usar o mesmo telefone) */
  if (!$ja && !$e['cnpj'] && $e['tel1']) $ja = one('SELECT id FROM empresas WHERE tel1 = ? OR tel2 = ?', [$e['tel1'], $e['tel1']]);
  if ($ja) {
    $set = []; $par = [];
    foreach (['razao','fantasia','responsavel','cnae','cnae_desc','segmento','mei','abertura','situacao','email','cep','logradouro','numero','complemento','bairro','cidade','uf','extra'] as $k)
      if ($e[$k] !== null && $e[$k] !== '') { $set[] = "$k = ?"; $par[] = $e[$k]; }
    if ($e['tel2']) { $set[] = 'tel2 = COALESCE(tel2, ?)'; $par[] = $e['tel2']; }
    if ($set) q('UPDATE empresas SET ' . implode(', ', $set) . ', atualizado_em = ? WHERE id = ?', array_merge($par, [agora(), $ja['id']]));
    return 'atualizada';
  }
  $cols = array_keys($e);
  q('INSERT INTO empresas (' . implode(',', $cols) . ', etapa, origem, criado_em, atualizado_em) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ", 'mapeado', 'planilha', ?, ?)",
    array_merge(array_values($e), [agora(), agora()]));
  return 'nova';
}

/* ---------- mapa: endereço → ponto (OpenStreetMap/Nominatim, 1 por segundo, com cache) ---------- */
function crm_pausa(float $seg = 1.1): void { if (empty(cfg()['geo_teste'])) usleep((int)($seg * 1000000)); }
const CRM_BBOX = [-18.76, -18.54, -48.33, -48.04]; /* Araguari e arredores: resultado fora disso é descartado */
function crm_no_bbox(?array $ll): ?array { return $ll && $ll[0] >= CRM_BBOX[0] && $ll[0] <= CRM_BBOX[1] && $ll[1] >= CRM_BBOX[2] && $ll[1] <= CRM_BBOX[3] ? [round($ll[0], 6), round($ll[1], 6)] : null; }
/* GET com tempo curto (a Hostinger encerra a requisição em ~30 s) */
function crm_http_json(string $url, int $t = 5): array {
  if (!function_exists('curl_init')) return [0, null];
  $h = curl_init($url);
  curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $t, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ['User-Agent: HunterLeague-CRM/1.1 (ti@callnetbrasil.com.br)', 'Accept-Language: pt-BR', 'Referer: https://www.hunterleague.com.br/']]);
  $txt = curl_exec($h); $cod = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE); curl_close($h);
  return [$txt === false ? 0 : $cod, $txt === false ? null : json_decode((string)$txt, true)];
}
function crm_cache(string $ch): ?array { $c = one('SELECT lat, lng FROM geocache WHERE chave = ?', [$ch]); return $c ? ['ll' => $c['lat'] !== null ? [(float)$c['lat'], (float)$c['lng']] : null] : null; }
function crm_cache_grava(string $ch, ?array $ll): void { try { q('INSERT INTO geocache (chave, lat, lng, em) VALUES (?,?,?,?)', [$ch, $ll[0] ?? null, $ll[1] ?? null, agora()]); } catch (Throwable $e) {} }
/* endereço em texto → [lat, lng] | null (não achou) | false (serviços fora do ar). Tenta o OpenStreetMap (Nominatim) e, se ele recusar, o Photon. */
function crm_geocodificar(string $consulta): array|false|null {
  if (!empty(cfg()['geo_teste'])) { $hsh = crc32($consulta); return [round(-18.635 - ($hsh % 300) / 10000, 6), round(-48.165 - (intdiv($hsh, 300) % 450) / 10000, 6)]; } /* só no teste local */
  $ch = mb_substr('t:' . crm_sem_acento($consulta), 0, 190);
  if ($c = crm_cache($ch)) return $c['ll'];
  static $nominatimOff = false; $falhas = 0;
  if (!$nominatimOff) {
    [$cod, $j] = crm_http_json('https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $consulta, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'br',
      'viewbox' => '-48.33,-18.54,-48.04,-18.76', 'bounded' => 1]));
    crm_pausa();
    if ($cod === 200 && is_array($j)) { $ll = isset($j[0]) ? crm_no_bbox([(float)$j[0]['lat'], (float)$j[0]['lon']]) : null; if ($ll) { crm_cache_grava($ch, $ll); return $ll; } }
    else { $falhas++; if (in_array($cod, [0, 403, 429], true)) $nominatimOff = true; }
  }
  [$cod, $j] = crm_http_json('https://photon.komoot.io/api/?' . http_build_query(['q' => $consulta, 'limit' => 1, 'lat' => -18.6475, 'lon' => -48.1875]));
  crm_pausa(.25);
  if ($cod === 200 && is_array($j)) {
    $f = $j['features'][0] ?? null; $ll = $f ? crm_no_bbox([(float)$f['geometry']['coordinates'][1], (float)$f['geometry']['coordinates'][0]]) : null;
    crm_cache_grava($ch, $ll); return $ll;
  }
  return $falhas ? false : null;
}
/* CEP → ponto (BrasilAPI). Em Araguari o CEP é por rua; o CEP geral da cidade (terminado em 000) não serve. */
function crm_geo_cep(?string $cep): array|false|null {
  if (!$cep || strlen($cep) !== 8 || str_ends_with($cep, '000')) return null;
  if (!empty(cfg()['geo_teste'])) return null;
  $ch = 'cep:' . $cep; if ($c = crm_cache($ch)) return $c['ll'];
  [$cod, $j] = crm_http_json('https://brasilapi.com.br/api/cep/v2/' . $cep); crm_pausa(.2);
  if ($cod === 0 || $cod >= 500) return false;
  $co = $j['location']['coordinates'] ?? null;
  $ll = $cod === 200 && is_array($co) && isset($co['latitude'], $co['longitude']) && $co['latitude'] !== '' ? crm_no_bbox([(float)$co['latitude'], (float)$co['longitude']]) : null;
  crm_cache_grava($ch, $ll); return $ll;
}
/* um lote curto (cabe no limite de tempo da hospedagem): primeiro os bairros, depois cada rua (pelo CEP e, se não der, pelo endereço) */
function crm_geo_lote(int $max = 6, int $prazo = 10): array {
  $ini = time(); $feitas = 0; $cidade = 'Araguari, MG'; $off = 0;
  while ($feitas < $max && time() - $ini < $prazo) {
    $e = one("SELECT id, logradouro, bairro, cep, geo FROM empresas WHERE (geo IS NULL OR geo = 'bairro') AND (logradouro IS NOT NULL OR bairro IS NOT NULL OR cep IS NOT NULL) ORDER BY CASE WHEN geo IS NULL THEN 0 ELSE 1 END, id LIMIT 1");
    if (!$e) break;
    if ($e['geo'] === null) {
      $ll = $e['bairro'] ? crm_geocodificar($e['bairro'] . ', ' . $cidade) : null; $feitas++;
      if ($ll === null && $e['cep']) { $ll = crm_geo_cep($e['cep']); $feitas++; }
      if ($ll === false) { if (++$off >= 2) return crm_geo_status() + ['erro' => 'Os serviços de mapa não responderam agora. Vou tentar de novo sozinho.']; continue; }
      if ($ll) q("UPDATE empresas SET lat = ?, lng = ?, geo = 'bairro', celula = NULL WHERE geo IS NULL AND " . ($e['bairro'] ? 'bairro = ?' : 'id = ?'), [$ll[0], $ll[1], $e['bairro'] ?: $e['id']]);
      else q("UPDATE empresas SET geo = 'falhou' WHERE id = ?", [$e['id']]);
      continue;
    }
    if (!$e['logradouro'] && !$e['cep']) { q("UPDATE empresas SET geo = 'bairro_ok' WHERE id = ?", [$e['id']]); continue; }
    $ll = crm_geo_cep($e['cep']); $feitas++;
    if ($ll) { q("UPDATE empresas SET lat = ?, lng = ?, geo = 'rua', celula = NULL WHERE geo = 'bairro' AND cep = ?", [$ll[0], $ll[1], $e['cep']]); continue; }
    if ($e['logradouro']) { $ll = crm_geocodificar($e['logradouro'] . ', ' . ($e['bairro'] ? $e['bairro'] . ', ' : '') . $cidade); $feitas++; }
    if ($ll === false) { if (++$off >= 2) return crm_geo_status() + ['erro' => 'Os serviços de mapa não responderam agora. Vou tentar de novo sozinho.']; continue; }
    if ($ll) q("UPDATE empresas SET lat = ?, lng = ?, geo = 'rua', celula = NULL WHERE geo = 'bairro' AND COALESCE(logradouro, '') = ? AND COALESCE(bairro, '') = ?", [$ll[0], $ll[1], (string)$e['logradouro'], (string)$e['bairro']]);
    else q("UPDATE empresas SET geo = 'bairro_ok' WHERE geo = 'bairro' AND COALESCE(logradouro, '') = ? AND COALESCE(bairro, '') = ?", [(string)$e['logradouro'], (string)$e['bairro']]);
  }
  return crm_geo_status();
}
function crm_geo_status(): array {
  $r = one("SELECT COUNT(*) AS t, SUM(CASE WHEN geo IS NULL THEN 1 ELSE 0 END) AS sem, SUM(CASE WHEN geo = 'bairro' THEN 1 ELSE 0 END) AS bairro,
    SUM(CASE WHEN geo IN ('rua', 'manual') THEN 1 ELSE 0 END) AS rua, SUM(CASE WHEN geo = 'bairro_ok' THEN 1 ELSE 0 END) AS so_bairro,
    SUM(CASE WHEN geo = 'falhou' THEN 1 ELSE 0 END) AS falhou, SUM(CASE WHEN geo = 'rua' AND celula = '-' THEN 1 ELSE 0 END) AS fora FROM empresas");
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

/* ---------- parceiro no mapa: o lugar vem do GPS do celular na hora de ativar o QR (ou de um endereço) ---------- */
function crm_parceiro_no_mapa(array $p, float $lat, float $lng, string $quem, array $end = []): ?int {
  crm_migrar();
  $ll = crm_no_bbox([$lat, $lng]); if (!$ll) return null;   /* fora da área atendida: não põe */
  $cel = crm_celula_de($ll[0], $ll[1]) ?? '-'; $agora = agora();
  $etapa = $p['status'] === 'ativo' && $p['codigo'] ? 'conquistado' : 'afiliado';
  $e = one('SELECT id FROM empresas WHERE parceiro_id = ? ORDER BY id LIMIT 1', [$p['id']]);
  if (!$e && strlen((string)$p['doc']) === 14) $e = one('SELECT id FROM empresas WHERE cnpj = ? AND parceiro_id IS NULL ORDER BY id LIMIT 1', [$p['doc']]);
  if (!$e && $p['whatsapp']) $e = one('SELECT id FROM empresas WHERE (tel1 = ? OR tel2 = ?) AND parceiro_id IS NULL ORDER BY id LIMIT 1', [$p['whatsapp'], $p['whatsapp']]);
  if ($e) {
    q("UPDATE empresas SET lat = ?, lng = ?, geo = 'gps', celula = ?, parceiro_id = ?, atualizado_em = ? WHERE id = ?", [$ll[0], $ll[1], $cel, $p['id'], $agora, $e['id']]);
    foreach (['logradouro', 'numero', 'bairro', 'cep'] as $k) if (!empty($end[$k])) q("UPDATE empresas SET $k = ? WHERE id = ?", [$end[$k], $e['id']]);
    crm_hist((int)$e['id'], $quem, 'sistema', 'Lugar no mapa atualizado' . ($quem === 'app' ? ' pelo GPS na ativação do QR' : ''));
    return (int)$e['id'];
  }
  q("INSERT INTO empresas (cnpj, fantasia, responsavel, tel1, cidade, uf, logradouro, numero, bairro, cep, lat, lng, geo, celula, etapa, parceiro_id, origem, criado_em, atualizado_em)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'gps',?,?,?,'app',?,?)",
    [strlen((string)$p['doc']) === 14 ? $p['doc'] : null, mb_substr((string)($p['loja'] ?: $p['nome']), 0, 160), mb_substr((string)$p['nome'], 0, 120), $p['whatsapp'] ?: null,
     $p['cidade'] ?: 'Araguari', 'MG', $end['logradouro'] ?? null, $end['numero'] ?? null, $end['bairro'] ?? null, $end['cep'] ?? null, $ll[0], $ll[1], $cel, $etapa, $p['id'], $agora, $agora]);
  $id = (int)db()->lastInsertId();
  crm_hist($id, $quem, 'sistema', 'Entrou no mapa como parceiro' . ($quem === 'app' ? ' (GPS na ativação do QR)' : ''));
  return $id;
}

/* ---------- ligação com o app: a empresa que se cadastra no Hunter League muda de cor sozinha ---------- */
function crm_sincronizar(): void {
  /* liga pelo CNPJ (doc do parceiro) ou pelo WhatsApp do cadastro */
  /* rápido com muita gente: carrega as lojas livres uma vez e casa na memória (antes era uma busca por parceiro) */
  $ps = all("SELECT p.id, p.doc, p.whatsapp FROM parceiros p WHERE p.status IN ('pendente', 'ativo') AND NOT EXISTS (SELECT 1 FROM empresas e WHERE e.parceiro_id = p.id) LIMIT 500");
  if ($ps) {
    $porCnpj = []; $porTel = [];
    foreach (all('SELECT id, cnpj, tel1, tel2 FROM empresas WHERE parceiro_id IS NULL ORDER BY id') as $r) {
      if ($r['cnpj']) $porCnpj[$r['cnpj']] ??= (int)$r['id'];
      foreach ([$r['tel1'], $r['tel2']] as $t) if ($t) $porTel[$t] ??= (int)$r['id'];
    }
    $usadas = [];
    foreach ($ps as $p) {
      $id = strlen((string)$p['doc']) === 14 ? ($porCnpj[$p['doc']] ?? null) : null;
      if (!$id && $p['whatsapp']) $id = $porTel[$p['whatsapp']] ?? null;
      if (!$id || isset($usadas[$id])) continue; $usadas[$id] = 1;
      q('UPDATE empresas SET parceiro_id = ?, atualizado_em = ? WHERE id = ? AND parceiro_id IS NULL', [$p['id'], agora(), $id]); crm_hist($id, 'sistema', 'app', 'Cadastrou no app Hunter League');
    }
  }
  /* parceiro que não casou com nenhuma loja da base (CPF, motorista, telefone diferente, QR ativado antes do GPS):
     entra no CRM mesmo assim, para os números do mapa baterem com os do painel; o ponto se ajusta clicando no mapa */
  foreach (all("SELECT p.id, p.doc, p.nome, p.loja, p.whatsapp, p.cidade FROM parceiros p WHERE p.status IN ('pendente', 'ativo') AND NOT EXISTS (SELECT 1 FROM empresas e WHERE e.parceiro_id = p.id) LIMIT 500") as $p) {
    q("INSERT INTO empresas (cnpj, fantasia, responsavel, tel1, cidade, uf, etapa, parceiro_id, origem, criado_em, atualizado_em) VALUES (?,?,?,?,?,?,'afiliado',?,'app',?,?)",
      [strlen((string)$p['doc']) === 14 ? $p['doc'] : null, mb_substr((string)($p['loja'] ?: $p['nome']), 0, 160), mb_substr((string)$p['nome'], 0, 120), $p['whatsapp'] ?: null,
       preg_replace('/\s*-\s*[A-Z]{2}$/', '', (string)($p['cidade'] ?: 'Araguari')), 'MG', $p['id'], agora(), agora()]);
    crm_hist((int)db()->lastInsertId(), 'sistema', 'app', 'Cadastrou no app Hunter League (sem lugar no mapa: clique no mapa para marcar)');
  }
  /* etapa segue o app: cadastrado → afiliado; QR ativo → conquistado; venda (agendada ou instalada) → venda */
  foreach (all("SELECT e.id, e.etapa, p.status, p.codigo, COALESCE(v.n, 0) AS vendas
      FROM empresas e JOIN parceiros p ON p.id = e.parceiro_id
      LEFT JOIN (SELECT parceiro_id, COUNT(*) AS n FROM indicacoes WHERE status IN ('Agendado', 'Instalado') GROUP BY parceiro_id) v ON v.parceiro_id = p.id") as $r) {
    $nova = $r['vendas'] > 0 ? 'venda' : ($r['status'] === 'ativo' && $r['codigo'] ? 'conquistado' : 'afiliado');
    if ($nova !== $r['etapa']) { q('UPDATE empresas SET etapa = ?, proximo_em = NULL, atualizado_em = ? WHERE id = ?', [$nova, agora(), $r['id']]); crm_hist((int)$r['id'], 'sistema', 'etapa', CRM_ETAPAS[$nova]); }
  }
}

/* ---------- parecer da ligação ---------- */
function crm_parecer(array $e, string $p, array $d, string $quem): string {
  $id = (int)$e['id']; $agora = agora();
  $tel = in_array($d['tel'] ?? '', ['1', '2'], true) ? $d['tel'] : null;
  /* qualquer resultado novo tira o "aguardando / respondeu no WhatsApp" */
  if ($p !== 'aguardando_zap' && in_array($e['motivo'] ?? null, [CRM_ZAP_ESPERA, CRM_ZAP_RESPONDEU], true))
    q('UPDATE empresas SET motivo = NULL WHERE id = ? AND motivo IN (?, ?)', [$id, CRM_ZAP_ESPERA, CRM_ZAP_RESPONDEU]);
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
    case 'aguardando_zap':
      q('UPDATE empresas SET motivo = ?, proximo_em = ?, atualizado_em = ? WHERE id = ?', [CRM_ZAP_ESPERA, date('Y-m-d H:i:s', strtotime('+' . CRM_ZAP_DIAS . ' days')), $agora, $id]);
      $txt = 'Mandou WhatsApp: aguardando resposta (sem resposta, volta para a fila em ' . CRM_ZAP_DIAS . ' dias)'; break;
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
function crm_proximo(?array $regiao, int $exceto = 0, ?array $cels = null): ?array {
  $base = "SELECT id, lat, lng, celula FROM empresas WHERE etapa = 'mapeado' AND id <> ? AND (tel1 IS NOT NULL) AND (proximo_em IS NULL OR proximo_em <= ?) AND " . CRM_VISIVEL;
  $rows = all($base . " ORDER BY CASE WHEN proximo_em IS NULL THEN 1 ELSE 0 END, proximo_em, CASE WHEN celula IS NOT NULL AND celula <> '-' THEN 0 ELSE 1 END, id LIMIT 4000", [$exceto, agora()]);
  if ($cels) { $set = array_flip($cels);
    foreach ($rows as $r) { $c = (string)$r['celula'];
      if ($c !== '' && $c !== '-' ? isset($set[$c]) : ($regiao && $r['lat'] !== null && crm_dentro((float)$r['lat'], (float)$r['lng'], $regiao))) return $r; }
    return null; }
  if ($regiao) { foreach ($rows as $r) if ($r['lat'] !== null && crm_dentro((float)$r['lat'], (float)$r['lng'], $regiao)) return $r; return null; }
  return $rows[0] ?? null;
}
