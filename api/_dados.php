<?php
/* Tudo o que o app mostra para o parceiro logado. Nunca inclui CPF, telefone ou sobrenome do cliente. */
declare(strict_types=1);
require_once __DIR__ . '/_qr.php';

const NIVEIS = [['Recruta', 0], ['Hunter', 20], ['Hunter Elite', 60], ['Líder', 200]];
const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

function nivel_de(int $inst): array {
  $atual = NIVEIS[0]; $prox = null;
  foreach (NIVEIS as $i => $n) if ($inst >= $n[1]) { $atual = $n; $prox = NIVEIS[$i + 1] ?? null; }
  return ['nivel' => $atual[0], 'feitas' => $inst, 'meta' => $prox ? $prox[1] : $inst, 'prox' => $prox[0] ?? null];
}

/* ---------- bônus: valor do plano (100% da mensalidade) ----------
   Cada instalação vale o valor do plano vendido, liberado quando o cliente paga a 1ª fatura.
   Os valores ficam na Central (config 'bonus_planos', na ordem de PLANOS) e podem ser mudados lá. */
const BONUS_PLANOS_PADRAO = [55, 70, 85, 100];
function bonus_planos(): array {
  $v = array_map(fn($x) => (float)str_replace(',', '.', trim($x)), explode('|', (string)conf('bonus_planos', implode('|', BONUS_PLANOS_PADRAO))));
  $out = [];
  foreach (PLANOS as $k => $p) $out[$p] = ($v[$k] ?? 0) > 0 ? $v[$k] : (float)BONUS_PLANOS_PADRAO[$k];
  return $out;
}
function bonus_plano(?string $plano): float { return $plano !== null ? (bonus_planos()[$plano] ?? 0.0) : 0.0; }
/* grava o bônus de uma instalação pelo plano (as que já foram para saque não mudam) */
function definir_bonus(int $id): void {
  $r = one('SELECT status, plano, saque_id FROM indicacoes WHERE id = ?', [$id]);
  if (!$r || $r['saque_id']) return;
  q('UPDATE indicacoes SET comissao = ? WHERE id = ?', [$r['status'] === 'Instalado' ? bonus_plano($r['plano']) : null, $id]);
}
function semana_de(string $data): array { $t = strtotime(substr($data, 0, 10)); $seg = date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t)); return [$seg, date('Y-m-d', strtotime($seg . ' +6 days'))]; }
/* vendas da semana: só contagem (para o contador e o ranking); não muda o valor do bônus */
function semana_atual(int $pid): array {
  [$de, $ate] = semana_de(hoje());
  $r = one("SELECT COUNT(*) AS n, COALESCE(SUM(comissao), 0) AS t FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND instalado_em BETWEEN ? AND ?", [$pid, $de, $ate]);
  return ['n' => (int)($r['n'] ?? 0), 'total' => (float)($r['t'] ?? 0), 'fim' => date('d/m', strtotime($ate)), 'dias' => 7 - (int)date('N')];
}
function nome_mes(string $comp): string { [$a, $m] = explode('-', $comp); return MESES[(int)$m] . ' de ' . $a; }

function hl_dados(array $p): array {
  hl_migrar_etapas();
  $id = (int)$p['id'];
  $inicioMes = date('Y-m-01 00:00:00');
  $limite = date('Y-m-d 00:00:00', strtotime('-60 days'));
  $rows = all("SELECT cliente_nome, status, motivo, criado_em, instalado_em, fatura_em, saque_id FROM indicacoes
               WHERE parceiro_id = ? AND status <> 'Cancelado'
                 AND (criado_em >= ? OR status IN " . ETAPAS_SQL . ")
               ORDER BY criado_em DESC LIMIT 60", [$id, $limite]);
  $inds = array_map(function ($r) use ($inicioMes) {
    $motivo = $r['motivo'] === 'Outro' ? null : $r['motivo'];
    return [
      'n' => primeiro_nome($r['cliente_nome']),
      'd' => date('d/m', strtotime($r['criado_em'])),
      's' => $r['status'],
      'w' => $r['status'] === 'Não instalado' ? $motivo : null,
      'i' => $r['instalado_em'] ? date('d/m', strtotime($r['instalado_em'])) : null,
      'm' => $r['criado_em'] >= $inicioMes,
      'f' => $r['fatura_em'] ? date('d/m', strtotime($r['fatura_em'])) : null,
      'q' => $r['saque_id'] ? true : false,
    ];
  }, $rows);

  $instTotal = (int)(one("SELECT COUNT(*) AS n FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado'", [$id])['n'] ?? 0);
  $niv = nivel_de($instTotal);
  if ($niv['nivel'] !== $p['nivel']) q('UPDATE parceiros SET nivel = ? WHERE id = ?', [$niv['nivel'], $id]);

  // metas com prêmio (contam instalações com a 1ª fatura paga)
  verificar_premios($id);
  $conq = [];
  foreach (all('SELECT tipo, alvo, premio, conquistado_em, entregue_em FROM premios WHERE parceiro_id = ?', [$id]) as $r) $conq[$r['tipo'] . ':' . $r['alvo']] = $r;
  $metas = array_map(fn($m) => ['alvo' => $m[0], 'premio' => $m[1],
    'em' => isset($conq['meta:' . $m[0]]) ? date('d/m/Y', strtotime($conq['meta:' . $m[0]]['conquistado_em'])) : null,
    'entregue' => isset($conq['meta:' . $m[0]]['entregue_em']) && $conq['meta:' . $m[0]]['entregue_em'] ? date('d/m/Y', strtotime($conq['meta:' . $m[0]]['entregue_em'])) : null], metas_conf());
  $validadas = (int)(one("SELECT COUNT(*) AS n FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND fatura_em IS NOT NULL", [$id])['n'] ?? 0);

  // bônus: a liberar (instalado, esperando a 1ª fatura), disponível (liberado, sem saque), saques
  $item = fn($r) => ['n' => primeiro_nome($r['cliente_nome']), 'd' => date('d/m', strtotime($r['instalado_em'])), 'v' => (float)$r['comissao'],
                     'dias' => (int)floor((time() - strtotime($r['instalado_em'])) / 86400), 'f' => $r['fatura_em'] ? date('d/m', strtotime($r['fatura_em'])) : null];
  $aLib = array_map($item, all("SELECT cliente_nome, instalado_em, comissao, fatura_em FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND fatura_em IS NULL ORDER BY instalado_em", [$id]));
  $disp = array_map($item, all("SELECT cliente_nome, instalado_em, comissao, fatura_em FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND fatura_em IS NOT NULL AND saque_id IS NULL ORDER BY fatura_em", [$id]));
  $saques = [];
  foreach (all('SELECT * FROM saques WHERE parceiro_id = ? ORDER BY pedido_em DESC LIMIT 24', [$id]) as $sq) $saques[(int)$sq['id']] = [
    'id' => (int)$sq['id'], 'valor' => (float)$sq['valor'], 'n' => (int)$sq['n'], 'pedido' => date('d/m/Y', strtotime($sq['pedido_em'])),
    'pago_em' => $sq['pago_em'] ? date('d/m/Y', strtotime($sq['pago_em'])) : null, 'pix_id' => $sq['pix_id'], 'arquivo' => !empty($sq['arquivo']), 'itens' => []];
  if ($saques) foreach (all('SELECT saque_id, cliente_nome, instalado_em, comissao, fatura_em FROM indicacoes WHERE saque_id IN (' . implode(',', array_keys($saques)) . ') ORDER BY instalado_em') as $r)
    $saques[(int)$r['saque_id']]['itens'][] = $item($r);
  $recebido = (float)(one('SELECT SUM(valor) AS v FROM saques WHERE parceiro_id = ? AND pago_em IS NOT NULL', [$id])['v'] ?? 0);
  $bonus = [
    'semana' => semana_atual($id),
    'disponivel' => array_sum(array_column($disp, 'v')), 'disp_itens' => $disp,
    'a_liberar' => array_sum(array_column($aLib, 'v')), 'lib_itens' => $aLib,
    'saques' => array_values($saques), 'recebido' => $recebido, 'prazo' => conf('prazo_saque', 'até 3 dias úteis'),
  ];

  $link = link_parceiro((string)$p['codigo']);
  return [
    'perfil' => ['nome' => primeiro_nome($p['nome']), 'nivel' => $niv['nivel'], 'cidade' => $p['cidade'], 'tipo' => $p['tipo'], 'loja' => $p['loja'] ? strip_tags($p['loja']) : null,
                 'codigo' => $p['codigo'], 'doc' => mascara_doc($p['doc']), 'whatsapp' => mascara_tel($p['whatsapp']), 'pix' => mascara_pix((string)$p['pix'], $p['doc']), 'pix_tipo' => tipo_pix((string)$p['pix'], $p['doc']),
                 'desde' => date('d/m/Y', strtotime($p['aprovado_em'] ?: $p['criado_em']))],
    'ranking' => ranking_liga($id),
    'prog' => ['feitas' => $niv['feitas'], 'meta' => $niv['meta']],
    'metas' => $metas,
    'kit' => ['ativo' => kit_ativo(), 'nome' => kit_nome(), 'itens' => kit_itens(), 'regra' => kit_regra(), 'prog' => kit_progresso($id),
              'em' => isset($conq['kit:1']) ? date('d/m/Y', strtotime($conq['kit:1']['conquistado_em'])) : null,
              'entregue' => !empty($conq['kit:1']['entregue_em']) ? date('d/m/Y', strtotime($conq['kit:1']['entregue_em'])) : null],
    'validadas' => $validadas,
    'total' => $instTotal,
    'codigo' => $p['codigo'],
    'link' => $link,
    'wa' => so_digitos(conf('whatsapp', '553420281071')),
    'comissao' => min(bonus_planos()), 'planos_bonus' => array_map(fn($p, $v) => ['plano' => $p, 'valor' => $v], array_keys(bonus_planos()), array_values(bonus_planos())),
    'tabela' => cidade_promo((string)$p['cidade']) ? 'promo' : 'cheia',
    'inds' => $inds,
    'bonus' => $bonus,
    'caminho' => (int)(one("SELECT COUNT(*) AS n FROM indicacoes WHERE parceiro_id = ? AND status = 'Agendado'", [$id])['n'] ?? 0),
    'qr' => HLQR::svg($link),
    'sem_indicar' => (function () use ($id, $p) {
      $u = one('SELECT MAX(criado_em) AS u FROM indicacoes WHERE parceiro_id = ?', [$id])['u'] ?? null;
      return ['dias' => dias_desde($u ?: ($p['aprovado_em'] ?: $p['criado_em'])), 'nunca' => !$u, 'alerta' => DIAS_SEM_INDICAR];
    })(),
  ];
}

function mascara_doc(string $d): string { return strlen($d) === 11 ? '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**' : substr($d, 0, 2) . '.***.***/' . substr($d, 8, 4) . '-**'; }
function mascara_tel(string $t): string { return strlen($t) >= 10 ? '(' . substr($t, 0, 2) . ') ' . substr($t, 2, 1) . '****-' . substr($t, -4) : $t; }
function tipo_pix(string $k, string $doc = ''): string {
  $k = trim($k); $d = so_digitos($k);
  if ($d !== '' && $d === $doc) return strlen($d) === 14 ? 'CNPJ' : 'CPF';
  if (preg_match('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/', $k)) return 'CPF';
  if (str_contains($k, '@')) return 'E-mail';
  if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $k)) return 'Chave aleatória';
  if (strlen($d) === 11 && cpf_valido($d) && !preg_match('/^\(?\d{2}\)?\s?9/', $k)) return 'CPF';
  if (strlen($d) === 14 && cnpj_valido($d)) return 'CNPJ';
  if (strlen($d) >= 10 && strlen($d) <= 13) return 'Celular';
  return 'Chave';
}
function mascara_pix(string $k, string $doc = ''): string {
  $k = trim(strip_tags($k)); $t = tipo_pix($k, $doc); $d = so_digitos($k);
  if ($t === 'Celular') { $d = tel_normal($d); return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 1) . '••••-' . substr($d, -4); }
  if ($t === 'CNPJ') return substr($d, 0, 2) . '.•••.•••/' . substr($d, 8, 4) . '-••';
  if ($t === 'CPF') return '•••.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-••';
  if ($t === 'E-mail') { [$u, $dom] = explode('@', $k, 2); return mb_substr($u, 0, 2) . '•••@' . $dom; }
  $n = mb_strlen($k); return $n <= 6 ? str_repeat('•', $n) : mb_substr($k, 0, 3) . '••••' . mb_substr($k, -3);
}
function mascara_pix_antiga(string $k): string { $k = trim(strip_tags($k)); $n = mb_strlen($k); return $n <= 6 ? str_repeat('*', $n) : mb_substr($k, 0, 3) . str_repeat('*', min(6, $n - 6)) . mb_substr($k, -3); }
function nome_ranking(array $r): string {
  if (in_array($r['tipo'], ['lojista', 'pj', 'pf'], true) && $r['loja']) return mb_substr(trim(strip_tags($r['loja'])), 0, 28);
  $partes = preg_split('/\s+/', trim(strip_tags($r['nome'])));
  $ult = count($partes) > 1 ? ' ' . mb_strtoupper(mb_substr(end($partes), 0, 1)) . '.' : '';
  return primeiro_nome($r['nome']) . $ult;
}
/* ---------- ranking da liga ----------
   Divisões: Recruta, Hunter e Hunter Elite (Líder entra na Hunter Elite), pelo total de instalações.
   Períodos: semana (zera na segunda), temporada (o mês) e geral. Só conta instalação concluída.
   Mostra só o nome da loja, ou primeiro nome e inicial do vendedor. */
function divisao_de(int $inst): string { $n = nivel_de($inst)['nivel']; return $n === 'Líder' ? 'Hunter Elite' : $n; }
function ordenar(array $cont): array {          // [id => n] → [id => posição], empates dividem a posição
  arsort($cont); $pos = []; $k = 0; $ult = null; $p = 0;
  foreach ($cont as $id => $n) { $k++; if ($n !== $ult) { $p = $k; $ult = $n; } $pos[$id] = $p; }
  return $pos;
}
function ranking_liga(int $eu): array {
  $ps = [];
  foreach (all("SELECT id, tipo, nome, loja FROM parceiros WHERE status = 'ativo'") as $r) $ps[(int)$r['id']] = $r + ['datas' => []];
  foreach (all("SELECT parceiro_id, instalado_em FROM indicacoes WHERE status = 'Instalado' AND instalado_em IS NOT NULL") as $r)
    if (isset($ps[(int)$r['parceiro_id']])) $ps[(int)$r['parceiro_id']]['datas'][] = $r['instalado_em'];
  $hoje = hoje(); $h7 = date('Y-m-d', strtotime('-7 days'));
  $seg = date('Y-m-d', strtotime('monday this week')); $segAnt = date('Y-m-d', strtotime('monday last week'));
  $mes = date('Y-m-01');
  $conta = fn(array $d, string $de, string $ate) => count(array_filter($d, fn($x) => $x >= $de && $x <= $ate));
  $periodos = [
    'semana'    => [[$seg, $hoje], [$segAnt, date('Y-m-d', strtotime($seg . ' -1 day'))]],
    'temporada' => [[$mes, $hoje], $h7 >= $mes ? [$mes, $h7] : null],
    'geral'     => [['0000-00-00', $hoje], ['0000-00-00', $h7]],
  ];
  $divs = ['Recruta' => [], 'Hunter' => [], 'Hunter Elite' => []];
  foreach ($ps as $id => $p) $divs[divisao_de(count($p['datas']))][] = $id;
  $minhaDiv = divisao_de(count($ps[$eu]['datas'] ?? []));
  $out = [];
  foreach ($periodos as $per => [$agora, $antes]) {
    foreach ($divs as $dv => $ids) {
      $cn = []; $ca = [];
      foreach ($ids as $id) {
        $n = $conta($ps[$id]['datas'], ...$agora); if ($n > 0) $cn[$id] = $n;
        if ($antes) { $m = $conta($ps[$id]['datas'], ...$antes); if ($m > 0) $ca[$id] = $m; }
      }
      $pos = ordenar($cn); $posA = $antes ? ordenar($ca) : [];
      $lista = [];
      foreach ($pos as $id => $p) {
        $lista[] = ['p' => $p, 'n' => nome_ranking($ps[$id]), 'i' => $cn[$id], 'eu' => $id === $eu,
                    'd' => $antes ? (isset($posA[$id]) ? $posA[$id] - $p : 'novo') : null];
        if (count($lista) >= 30 && !isset($pos[$eu])) break;
      }
      $out[$per][$dv] = ['lista' => array_slice($lista, 0, 30), 'eu' => null, 'cola' => null];
      if ($dv === $minhaDiv) {
        $meu = $cn[$eu] ?? 0;
        $me = $meu ? ['p' => $pos[$eu], 'i' => $meu, 'd' => $antes ? (isset($posA[$eu]) ? $posA[$eu] - $pos[$eu] : 'novo') : null] : ['p' => null, 'i' => 0, 'd' => null];
        $acima = null; $abaixo = null;
        foreach ($pos as $id => $p) {
          if ($id === $eu) continue;
          if ($cn[$id] > $meu) $acima = ['n' => nome_ranking($ps[$id]), 'i' => $cn[$id]];
          elseif ($meu && $cn[$id] <= $meu && !$abaixo && $p >= ($pos[$eu] ?? 0)) $abaixo = ['n' => nome_ranking($ps[$id]), 'i' => $cn[$id]];
        }
        $out[$per][$dv]['eu'] = $me; $out[$per][$dv]['cola'] = ['acima' => $acima, 'abaixo' => $abaixo];
      }
    }
  }
  // insígnias das temporadas que já fecharam: Alfa (1º) e Top 3 da divisão
  $ins = [];
  $meses = array_unique(array_map(fn($d) => substr($d, 0, 7), array_merge(...array_values(array_map(fn($p) => $p['datas'], $ps)) ?: [[]])));
  sort($meses);
  foreach ($meses as $cmp) {
    if ($cmp >= date('Y-m')) continue;
    $fim = date('Y-m-t', strtotime($cmp . '-01'));
    $minhaDivNaEpoca = divisao_de($conta($ps[$eu]['datas'] ?? [], '0000-00-00', $fim));
    $cn = [];
    foreach ($ps as $id => $p) {
      if (divisao_de($conta($p['datas'], '0000-00-00', $fim)) !== $minhaDivNaEpoca) continue;
      $n = $conta($p['datas'], $cmp . '-01', $fim); if ($n > 0) $cn[$id] = $n;
    }
    $pos = ordenar($cn);
    if (isset($pos[$eu]) && $pos[$eu] <= 3) $ins[] = ['t' => $pos[$eu] === 1 ? 'Alfa' : 'Top 3', 'p' => $pos[$eu], 'mes' => nome_mes($cmp), 'div' => $minhaDivNaEpoca];
  }
  [$a, $m] = [(int)date('Y'), (int)date('n')];
  return [
    'temporada' => ['nome' => ucfirst(MESES[$m]), 'fim' => date('t/m'), 'dias' => (int)date('t') - (int)date('j'), 'pct' => round((int)date('j') / (int)date('t'), 3)],
    'semana' => ['fim' => date('d/m', strtotime('sunday this week')), 'dias' => 7 - (int)date('N')],
    'divisao' => $minhaDiv,
    'periodos' => $out,
    'insignias' => array_reverse($ins),
  ];
}

/* ---------- metas com prêmio ----------
   "20|Air Fryer" por linha (configurável no admin). Contam só instalações com a 1ª fatura paga.
   O prêmio conquistado fica registrado para o admin entregar. */
const METAS_PADRAO = "20|Air Fryer ou R$ 350\n60|Smart TV 50\" ou R$ 2.000\n200|iPhone 17 ou R$ 6.000";
function metas_conf(): array {
  $m = [];
  $txt = (string)conf('metas', METAS_PADRAO);
  if (preg_replace('/\s+/', '', $txt) === preg_replace('/\s+/', '', "20|Air Fryer\n60|Smart TV 50\"\n200|iPhone 17")) $txt = METAS_PADRAO;   // metas antigas, sem a opção em dinheiro
  $txt = str_replace('Air Fryer ou R$ 250', 'Air Fryer ou R$ 350', $txt);   // valor corrigido em 26/11/2026
  foreach (preg_split('/\R/', $txt) as $l) {
    [$a, $b] = array_pad(array_map('trim', explode('|', $l, 2)), 2, '');
    if ((int)$a > 0 && $b !== '') $m[] = [(int)$a, mb_substr(strip_tags($b), 0, 80)];
  }
  usort($m, fn($x, $y) => $x[0] <=> $y[0]);
  return $m;
}
/* Kit completo (bandeirola, acrílico etc.): por mérito, quando o parceiro passou os contatos e gerou as vendas
   (padrão: 20 contatos e 2 vendas; regra de 27/11/2026). A peça da visita (adesivo A3 ou placa A4) sai na hora e não entra aqui.
   Contato = indicação registrada (menos as canceladas). Venda = link seguro aprovado e instalação agendada (Agendado ou Instalado). */
function kit_ativo(): bool { return conf('kit_ativo', '1') === '1'; }
function kit_nome(): string { return conf('kit_nome', 'Kit completo do parceiro') ?: 'Kit completo do parceiro'; }
function kit_itens(): string { return conf('kit_itens', 'Bandeirola, display de acrílico com QR e panfletos'); }
function kit_regra(): array { return ['contatos' => max(1, (int)conf('kit_contatos', '20')), 'vendas' => max(1, (int)conf('kit_vendas', '2'))]; }
function kit_progresso(int $pid): array {
  $r = one("SELECT COUNT(*) AS c, SUM(CASE WHEN status IN ('Agendado', 'Instalado') THEN 1 ELSE 0 END) AS v FROM indicacoes WHERE parceiro_id = ? AND status <> 'Cancelado'", [$pid]);
  return ['contatos' => (int)($r['c'] ?? 0), 'vendas' => (int)($r['v'] ?? 0)];
}
/* dia em que bateu as duas metas: o mais tarde entre o N-ésimo contato e a M-ésima venda */
function kit_liberado_em(int $pid): ?string {
  $g = kit_regra();
  $c = all("SELECT criado_em FROM indicacoes WHERE parceiro_id = ? AND status <> 'Cancelado' ORDER BY criado_em LIMIT " . $g['contatos'], [$pid]);
  $v = all("SELECT COALESCE(instalado_em, atualizado_em, criado_em) AS d FROM indicacoes WHERE parceiro_id = ? AND status IN ('Agendado', 'Instalado') ORDER BY d LIMIT " . $g['vendas'], [$pid]);
  if (count($c) < $g['contatos'] || count($v) < $g['vendas']) return null;
  return max(end($c)['criado_em'], end($v)['d']);
}
function verificar_premios(int $pid): void {
  $datas = array_column(all("SELECT fatura_em FROM indicacoes WHERE parceiro_id = ? AND status = 'Instalado' AND fatura_em IS NOT NULL ORDER BY fatura_em", [$pid]), 'fatura_em');
  $total = count($datas); $quer = [];
  foreach (metas_conf() as [$alvo, $premio]) if ($total >= $alvo) $quer['meta:' . $alvo] = ['meta', $alvo, $premio, $datas[$alvo - 1]];
  if (kit_ativo() && ($kd = kit_liberado_em($pid))) $quer['kit:1'] = ['kit', 1, kit_nome(), $kd];
  $tem = [];
  foreach (all('SELECT id, tipo, alvo, entregue_em FROM premios WHERE parceiro_id = ?', [$pid]) as $r) $tem[$r['tipo'] . ':' . $r['alvo']] = $r;
  foreach ($quer as $k => [$tipo, $alvo, $premio, $d]) if (!isset($tem[$k])) {
    q('INSERT INTO premios (parceiro_id, tipo, alvo, premio, conquistado_em) VALUES (?,?,?,?,?)', [$pid, $tipo, $alvo, $premio, substr($d, 0, 10)]);
    auditar('sistema', 'prêmio conquistado', 'parceiro:' . $pid, "$tipo $alvo: $premio");
  }
  foreach ($tem as $k => $r) if (!isset($quer[$k]) && !$r['entregue_em']) q('DELETE FROM premios WHERE id = ?', [$r['id']]);   // liberação desfeita antes da entrega
}
