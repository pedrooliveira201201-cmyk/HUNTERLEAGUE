<?php
/* API do painel do admin. Responde JSON. Toda escrita exige o token da sessão (cabeçalho X-CSRF). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/../api/_casadosdados.php';
header('X-Robots-Tag: noindex');
$a = admin_logado();
if (!$a) json_out(['erro' => 'Sua sessão expirou. Entre de novo.', 'sair' => true], 401);
$acao = (string)($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['erro' => 'Sessão expirada. Recarregue a página.'], 400);
$e = $post ? entrada() : [];
$s = fn($k, $max = 200) => mb_substr(trim((string)($e[$k] ?? '')), 0, $max);
$quem = 'admin:' . $a['usuario'];
$master = $a['papel'] === 'master';
hl_migrar_etapas();
$ABERTAS = ETAPAS_ABERTAS;
/* Perfis: master faz tudo. Atendimento só mexe em indicações (registra, muda etapa, edita dados básicos). */
const ACOES_ATENDIMENTO = ['resumo', 'indicacoes', 'parceiros', 'indicar', 'etapa', 'config', 'minha_senha', 'sair'];
if (!$master && !in_array($acao, ACOES_ATENDIMENTO, true)) json_out(['erro' => 'Seu acesso é de atendimento: só indicações. Peça ao master.'], 403);

switch ($acao) {

case 'resumo': {
  $n = fn($sql, $p = []) => (int)(one($sql, $p)['n'] ?? 0);
  $saq = one('SELECT COUNT(*) AS n, SUM(valor) AS v FROM saques WHERE pago_em IS NULL');
  $tar = [
    'paradas' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE status IN " . ETAPAS_SQL . " AND atualizado_em < ?", [date('Y-m-d H:i:s', strtotime('-2 days'))]),
    'novas' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE status = 'Nova'"),
  ];
  $parados = array_map(fn($r) => ['id' => (int)$r['id'], 'codigo' => $r['codigo'], 'nome' => $r['nome'], 'resp' => primeiro_nome($r['resp']),
    'wa_d' => $r['whatsapp'], 'dias' => dias_desde($r['ultima'] ?: $r['aprovado_em']), 'nunca' => !$r['ultima']], parceiros_parados());
  if ($master) $tar += [
    'pendentes' => $n("SELECT COUNT(*) AS n FROM parceiros WHERE status = 'pendente'"),
    'saques' => ['n' => (int)($saq['n'] ?? 0), 'total' => (float)($saq['v'] ?? 0)],
    'faturas' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE status = 'Instalado' AND fatura_em IS NULL AND instalado_em <= ?", [date('Y-m-d', strtotime('-25 days'))]),
    'premios' => $n("SELECT COUNT(*) AS n FROM premios WHERE entregue_em IS NULL"),
  ];
  json_out([
    'me' => ['nome' => primeiro_nome($a['nome']), 'papel' => $a['papel']],
    'tarefas' => $tar,
    'parados' => $parados, 'dias_alerta' => DIAS_SEM_INDICAR,
    'stats' => [
      'ind_hoje' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE criado_em >= ?", [date('Y-m-d 00:00:00')]),
      'ind_mes' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE criado_em >= ?", [date('Y-m-01 00:00:00')]),
      'inst_mes' => $n("SELECT COUNT(*) AS n FROM indicacoes WHERE status = 'Instalado' AND instalado_em >= ?", [date('Y-m-01')]),
      'leit_hoje' => $n("SELECT COUNT(*) AS n FROM leituras WHERE em >= ?", [date('Y-m-d 00:00:00')]),
      'ativos' => $n("SELECT COUNT(*) AS n FROM parceiros WHERE status = 'ativo'"),
    ],
    'atividade' => $master ? array_map(fn($r) => ['em' => dh_br($r['em']), 'quem' => preg_replace('/^admin:/', '', $r['quem']), 'acao' => $r['acao'], 'alvo' => $r['alvo']],
      all("SELECT quem, acao, alvo, em FROM auditoria WHERE acao NOT IN ('login','logout') ORDER BY id DESC LIMIT 8")) : [],
  ]);
}

case 'indicacoes': {
  $rows = all("SELECT i.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome, g.pago_em AS sq_pago
               FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id LEFT JOIN saques g ON g.id = i.saque_id
               WHERE i.status IN " . ETAPAS_SQL . " OR i.atualizado_em >= ?
               ORDER BY i.criado_em DESC LIMIT 600", [date('Y-m-d H:i:s', strtotime('-60 days'))]);
  json_out(['itens' => array_map(fn($r) => [
    'id' => (int)$r['id'], 'nome' => $r['cliente_nome'], 'tel' => tel_br($r['cliente_tel']), 'tel_d' => $r['cliente_tel'], 'cpf' => $r['cliente_cpf'] ? doc_br($r['cliente_cpf']) : '',
    'email' => $r['cliente_email'] ?? '', 'cep' => $r['cliente_cep'] ? substr($r['cliente_cep'], 0, 5) . '-' . substr($r['cliente_cep'], 5) : '', 'num' => $r['cliente_numero'] ?? '',
    'plano' => $r['plano'] ?? '', 'status' => $r['status'], 'motivo' => $r['motivo'] ?? '', 'inst' => data_br($r['instalado_em']), 'origem' => $r['origem'] ?? 'qr',
    'criado' => dh_br($r['criado_em']), 'dias' => (int)floor((time() - strtotime($r['atualizado_em'])) / 86400),
    'bonus' => $r['status'] === 'Instalado' ? (float)$r['comissao'] : null, 'fatura' => data_br($r['fatura_em']),
    'saque' => $r['saque_id'] ? ($r['sq_pago'] ? 'pago' : 'pedido') : '', 'pago' => (bool)$r['saque_id'] || (bool)$r['fatura_em'],
    'parceiro' => ['codigo' => $r['codigo'], 'nome' => $r['pnome']],
  ], $rows)]);
}

case 'parceiros': {
  $rows = all("SELECT p.*, (SELECT COUNT(*) FROM indicacoes i WHERE i.parceiro_id = p.id) AS ninds,
                 (SELECT COUNT(*) FROM indicacoes i WHERE i.parceiro_id = p.id AND i.status = 'Instalado') AS ninst,
                 (SELECT COUNT(*) FROM leituras l WHERE l.parceiro_id = p.id) AS nleit,
                 (SELECT MAX(i.criado_em) FROM indicacoes i WHERE i.parceiro_id = p.id) AS ultima
               FROM parceiros p ORDER BY CASE p.status WHEN 'pendente' THEN 0 ELSE 1 END, p.criado_em DESC LIMIT 1000");
  if (!$master) json_out(['itens' => array_values(array_map(fn($p) => ['id' => (int)$p['id'], 'tipo' => $p['tipo'], 'nome' => $p['nome'], 'loja' => $p['loja'], 'codigo' => $p['codigo'],
    'status' => $p['status'], 'cidade' => $p['cidade'], 'sem_ind' => $p['status'] === 'ativo' ? dias_desde($p['ultima'] ?: $p['aprovado_em']) : null], array_filter($rows, fn($p) => $p['codigo'])))]);
  json_out(['itens' => array_map(fn($p) => [
    'id' => (int)$p['id'], 'tipo' => $p['tipo'], 'nome' => $p['nome'], 'loja' => $p['loja'], 'doc' => doc_br($p['doc']), 'wa' => tel_br($p['whatsapp']), 'wa_d' => $p['whatsapp'],
    'email' => $p['email'] ?? '', 'cidade' => $p['cidade'], 'pix' => $p['pix'], 'codigo' => $p['codigo'], 'status' => $p['status'], 'nivel' => $p['nivel'],
    'desde' => data_br($p['criado_em']), 'termo' => 'v' . $p['termo_versao'] . ' em ' . data_br($p['termo_aceito_em']), 'obs' => $p['obs'] ?? '',
    'sem_ind' => $p['status'] === 'ativo' ? dias_desde($p['ultima'] ?: $p['aprovado_em']) : null, 'ultima' => data_br($p['ultima']),
    'sem_qr' => $p['status'] === 'ativo' && !$p['codigo'], 'leit' => (int)$p['nleit'], 'ind' => (int)$p['ninds'], 'inst' => (int)$p['ninst'], 'link' => $p['codigo'] ? link_parceiro($p['codigo']) : '',
  ], $rows)]);
}

case 'bonus': {
  $item = fn($r) => ['id' => (int)$r['id'], 'cliente' => $r['cliente_nome'], 'parceiro' => $r['pnome'], 'codigo' => $r['codigo'], 'inst' => data_br($r['instalado_em']),
    'dias' => (int)floor((time() - strtotime($r['instalado_em'])) / 86400), 'v' => (float)$r['comissao'], 'plano' => $r['plano'] ?? '', 'fatura' => data_br($r['fatura_em'])];
  $faturas = array_map($item, all("SELECT i.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id
    WHERE i.status = 'Instalado' AND i.fatura_em IS NULL ORDER BY i.instalado_em LIMIT 500"));
  $liberados = array_map($item, all("SELECT i.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id
    WHERE i.status = 'Instalado' AND i.fatura_em IS NOT NULL AND i.saque_id IS NULL ORDER BY p.codigo, i.fatura_em LIMIT 500"));
  $sq = fn($r) => ['id' => (int)$r['id'], 'parceiro' => $r['pnome'], 'codigo' => $r['codigo'], 'pix' => $r['chave_pix'], 'valor' => (float)$r['valor'], 'n' => (int)$r['n'],
    'pedido' => dh_br($r['pedido_em']), 'dias' => (int)floor((time() - strtotime($r['pedido_em'])) / 86400), 'pago_em' => data_br($r['pago_em']), 'pix_id' => $r['pix_id'] ?? '',
    'arq' => $r['arquivo'] ? 'arquivo.php?id=' . (int)$r['id'] : '', 'por' => $r['pago_por'] ?? '', 'itens' => []];
  $pedidos = []; foreach (all("SELECT g.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome FROM saques g JOIN parceiros p ON p.id = g.parceiro_id WHERE g.pago_em IS NULL ORDER BY g.pedido_em") as $r) $pedidos[(int)$r['id']] = $sq($r);
  if ($pedidos) foreach (all('SELECT saque_id, cliente_nome, instalado_em, comissao FROM indicacoes WHERE saque_id IN (' . implode(',', array_keys($pedidos)) . ') ORDER BY instalado_em') as $r)
    $pedidos[(int)$r['saque_id']]['itens'][] = ['n' => primeiro_nome($r['cliente_nome']), 'd' => data_br($r['instalado_em']), 'v' => (float)$r['comissao']];
  $pagos = array_map($sq, all("SELECT g.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome FROM saques g JOIN parceiros p ON p.id = g.parceiro_id WHERE g.pago_em IS NOT NULL AND g.pago_em >= ? ORDER BY g.pago_em DESC, g.id DESC", [date('Y-m-d', strtotime('-60 days'))]));
  $premios = array_map(fn($r) => ['id' => (int)$r['id'], 'parceiro' => $r['pnome'], 'codigo' => $r['codigo'], 'wa' => tel_br($r['whatsapp']), 'wa_d' => $r['whatsapp'],
    'conquista' => $r['tipo'] === 'kit' ? kit_regra()['contatos'] . ' contatos e ' . kit_regra()['vendas'] . ' vendas' : $r['alvo'] . ' instalações validadas', 'premio' => $r['premio'], 'em' => data_br($r['conquistado_em']), 'entregue' => data_br($r['entregue_em']), 'kit' => $r['tipo'] === 'kit',
    'pode' => $r['tipo'] === 'kit' || strtotime($r['conquistado_em'] . ' +30 days') <= time(), 'libera' => date('d/m/Y', strtotime($r['conquistado_em'] . ' +30 days'))],
    all("SELECT r.*, p.codigo, p.whatsapp, COALESCE(p.loja, p.nome) AS pnome FROM premios r JOIN parceiros p ON p.id = r.parceiro_id WHERE r.tipo IN ('meta', 'kit') AND (r.entregue_em IS NULL OR r.entregue_em >= ?) ORDER BY r.entregue_em IS NOT NULL, r.conquistado_em", [date('Y-m-d', strtotime('-60 days'))]));
  json_out(['pedidos' => array_values($pedidos), 'faturas' => $faturas, 'liberados' => $liberados, 'pagos' => $pagos, 'premios' => $premios,
    'tot' => ['pedidos' => array_sum(array_column($pedidos, 'valor')), 'faturas' => array_sum(array_column($faturas, 'v')), 'liberados' => array_sum(array_column($liberados, 'v')),
              'pagos30' => array_sum(array_map(fn($x) => $x['valor'], array_filter($pagos, fn($x) => true)))],
    'regra' => ['planos' => bonus_planos(), 'prazo' => conf('prazo_saque', 'até 3 dias úteis')]]);
}

case 'config': {
  $c = []; foreach (all('SELECT chave, valor FROM config') as $r) $c[$r['chave']] = $r['valor'];
  $c['smtp_tem_senha'] = ($c['smtp_senha'] ?? '') !== ''; unset($c['smtp_senha']);
  $ck = cdd_chave(); $c['cdd_fim'] = $ck !== '' ? mb_substr($ck, -4) : ''; $c['cdd_no_arquivo'] = (string)(cfg()['casadosdados_key'] ?? '') !== ''; unset($c['casadosdados_key']);
  if (!$master) $c = [];
  json_out(['config' => $c, 'master' => $master,
    'equipe' => $master ? array_map(fn($u) => ['id' => (int)$u['id'], 'nome' => $u['nome'], 'usuario' => $u['usuario'], 'papel' => $u['papel'], 'ativo' => (bool)$u['ativo'], 'eu' => (int)$u['id'] === (int)$a['id']], all('SELECT * FROM admins ORDER BY nome')) : [],
    'historico' => $master ? array_map(fn($r) => ['em' => dh_br($r['em']), 'quem' => $r['quem'], 'acao' => $r['acao'], 'alvo' => $r['alvo'], 'detalhe' => $r['detalhe']], all('SELECT * FROM auditoria ORDER BY id DESC LIMIT 200')) : []]);
}

/* ---------- escritas ---------- */
case 'indicar': {
  $codigo = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $s('codigo', 20)));
  $p = parceiro_por_codigo($codigo);
  $nome = trim(preg_replace('/\s+/', ' ', strip_tags($s('nome', 120))));
  $cpf = so_digitos($s('cpf', 20)); $tel = tel_normal($s('tel', 20)); $plano = in_array($s('plano'), PLANOS, true) ? $s('plano') : null;
  if (!$p) json_out(['erro' => "Código {$codigo} não encontrado. Confira na mensagem do cliente.", 'campo' => 'codigo'], 422);
  if ($p['status'] !== 'ativo') json_out(['erro' => "O parceiro {$codigo} não está ativo. Indicação NÃO registrada.", 'campo' => 'codigo'], 422);
  if (mb_strlen($nome) < 2) json_out(['erro' => 'Escreva o nome do cliente.', 'campo' => 'nome'], 422);
  if (strlen($tel) < 10) json_out(['erro' => 'Telefone do cliente com DDD.', 'campo' => 'tel'], 422);
  if ($cpf !== '' && !cpf_valido($cpf) && !cnpj_valido($cpf)) json_out(['erro' => 'CPF do cliente inválido.', 'campo' => 'cpf'], 422);
  if ($d = duplicado($cpf, $tel)) {
    auditar($quem, 'duplicidade barrada', $codigo, "já indicado por {$d['codigo']}");
    json_out(['erro' => "NÃO REGISTRADA. Este cliente já foi indicado por {$d['codigo']} ({$d['pnome']}) em " . data_br($d['criado_em']) . '. Vale a primeira indicação.', 'duplicado' => true], 409);
  }
  q('INSERT INTO indicacoes (parceiro_id, cliente_nome, cliente_cpf, cliente_tel, plano, status, origem, criado_em, criado_por, atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$p['id'], $nome, $cpf ?: null, $tel, $plano, 'Nova', 'qr', agora(), $a['usuario'], agora()]);
  auditar($quem, 'registrou indicação', $codigo, primeiro_nome($nome));
  json_out(['ok' => true, 'msg' => "Indicação registrada para {$codigo} (" . ($p['loja'] ?: $p['nome']) . ').']);
}

case 'etapa': {
  $i = one('SELECT i.*, p.codigo FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id WHERE i.id = ?', [(int)($e['id'] ?? 0)]);
  if (!$i) json_out(['erro' => 'Indicação não encontrada.'], 404);
  $st = $s('status', 30) ?: $i['status']; $mot = $s('motivo', 60); $dt = $s('inst', 10);
  $plano = array_key_exists('plano', $e) ? (in_array($s('plano'), PLANOS, true) ? $s('plano') : null) : $i['plano'];
  $cpf = array_key_exists('cpf', $e) ? so_digitos($s('cpf', 20)) : (string)$i['cliente_cpf'];
  $nome = array_key_exists('nome', $e) ? trim(preg_replace('/\s+/', ' ', strip_tags($s('nome', 120)))) : $i['cliente_nome'];
  $tel = array_key_exists('tel', $e) ? tel_normal($s('tel', 20)) : (string)$i['cliente_tel'];
  $email = array_key_exists('email', $e) ? $s('email', 120) : (string)$i['cliente_email'];
  if (!in_array($st, ETAPAS, true)) json_out(['erro' => 'Etapa inválida.'], 422);
  if (mb_strlen($nome) < 2) json_out(['erro' => 'Escreva o nome do cliente.'], 422);
  if ($tel !== '' && strlen($tel) < 10) json_out(['erro' => 'Telefone do cliente com DDD.'], 422);
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['erro' => 'E-mail do cliente inválido.'], 422);
  if (($cpf !== '' && $cpf !== (string)$i['cliente_cpf']) || ($tel !== (string)$i['cliente_tel'] && $tel !== '')) {
    if ($cpf !== '' && $cpf !== (string)$i['cliente_cpf'] && !cpf_valido($cpf) && !cnpj_valido($cpf)) json_out(['erro' => 'CPF inválido.'], 422);
    $d = duplicado($cpf !== (string)$i['cliente_cpf'] ? $cpf : '', $tel !== (string)$i['cliente_tel'] ? $tel : '', (int)$i['id']);
    if ($d && $d['codigo'] !== $i['codigo']) json_out(['erro' => "Este cliente já foi indicado por {$d['codigo']} em " . data_br($d['criado_em']) . '.'], 409);
  }
  $mudaFin = $st !== $i['status'] || ($st === 'Instalado' && $dt && $dt !== (string)$i['instalado_em']);
  if ($i['saque_id'] && $mudaFin) json_out(['erro' => 'O bônus desta instalação já foi pedido no saque. Não dá para mudar a etapa nem a data.'], 409);
  if ($i['fatura_em'] && $mudaFin && !$master) json_out(['erro' => 'Bônus já liberado: só o master muda a etapa ou a data.'], 403);
  $inst = $i['instalado_em']; $fat = $i['fatura_em'];
  if ($st === 'Instalado') {
    $dt = $dt ?: ($inst ?: hoje());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) || strtotime($dt) > time()) json_out(['erro' => 'Data da instalação inválida (não pode ser no futuro).'], 422);
    $inst = $dt;
  } else { $inst = null; $fat = null; }
  if ($st === 'Não instalado' && !in_array($mot, MOTIVOS, true)) json_out(['erro' => 'Escolha o motivo.'], 422);
  if ($st === 'Instalado' && !$plano) json_out(['erro' => 'Escolha o plano vendido: o bônus é o valor do plano.'], 422);
  q('UPDATE indicacoes SET status = ?, motivo = ?, instalado_em = ?, competencia = ?, fatura_em = ?, comissao = ?, plano = ?, cliente_cpf = ?, cliente_nome = ?, cliente_tel = ?, cliente_email = ?, atualizado_em = ? WHERE id = ?',
    [$st, $st === 'Não instalado' ? $mot : null, $inst, $inst ? substr($inst, 0, 7) : null, $fat, $inst ? $i['comissao'] : null, $plano, $cpf ?: null, $nome, $tel ?: null, $email ?: null, agora(), $i['id']]);
  definir_bonus((int)$i['id']);
  $mud = $st !== $i['status'] ? "{$i['status']} → {$st}" . ($st === 'Não instalado' ? " ({$mot})" : '') . ($inst ? " em {$inst}" : '') : 'editou dados';
  auditar($quem, 'etapa', $i['codigo'] . ' #' . $i['id'], $mud);
  verificar_premios((int)$i['parceiro_id']);
  $v = $inst ? (float)(one('SELECT comissao FROM indicacoes WHERE id = ?', [$i['id']])['comissao'] ?? 0) : 0;
  json_out(['ok' => true, 'msg' => primeiro_nome($nome) . ($st !== $i['status'] ? ": {$st}." : ': dados salvos.') . ($st === 'Instalado' && $st !== $i['status'] ? ' Bônus de R$ ' . number_format($v, 2, ',', '.') . " (plano {$plano}), liberado após a 1ª fatura paga." : '')]);
}

case 'liberar': {
  $i = one('SELECT i.*, p.codigo FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id WHERE i.id = ?', [(int)($e['id'] ?? 0)]);
  if (!$i || $i['status'] !== 'Instalado') json_out(['erro' => 'Só instalação concluída pode ter o bônus liberado.'], 422);
  if ($i['saque_id']) json_out(['erro' => 'Este bônus já está num saque.'], 409);
  $desfaz = !empty($e['desfazer']);
  $dt = $desfaz ? null : ($s('data', 10) ?: hoje());
  if ($dt && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) || strtotime($dt) > time() || $dt < $i['instalado_em'])) json_out(['erro' => 'Data da 1ª fatura inválida.'], 422);
  q('UPDATE indicacoes SET fatura_em = ?, atualizado_em = ? WHERE id = ?', [$dt, agora(), $i['id']]);
  auditar($quem, $desfaz ? 'desfez liberação' : '1ª fatura paga · bônus liberado', $i['codigo'] . ' #' . $i['id'], primeiro_nome($i['cliente_nome']) . ' R$ ' . number_format((float)$i['comissao'], 2, ',', '.'));
  verificar_premios((int)$i['parceiro_id']);
  json_out(['ok' => true, 'msg' => $desfaz ? 'Liberação desfeita.' : primeiro_nome($i['cliente_nome']) . ': bônus de R$ ' . number_format((float)$i['comissao'], 2, ',', '.') . ' liberado. O parceiro já pode pedir o saque.']);
}

case 'parceiro': {
  $p = one('SELECT * FROM parceiros WHERE id = ?', [(int)($e['id'] ?? 0)]);
  if (!$p) json_out(['erro' => 'Parceiro não encontrado.'], 404);
  $o = $s('o', 20); $alvo = $p['codigo'] ?: $p['doc'];
  if ($o === 'aprovar' && in_array($p['status'], ['pendente', 'recusado'], true)) {
    /* o código do parceiro nasce quando ele ativa o QR da peça (adesivo A3 ou placa A4) no app */
    q('UPDATE parceiros SET status = ?, aprovado_em = ?, aprovado_por = ? WHERE id = ?', ['ativo', agora(), $a['usuario'], $p['id']]);
    auditar($quem, 'aprovou parceiro', $p['codigo'] ?: $p['doc'], $p['nome']);
    $nome = primeiro_nome($p['nome']);
    $txt = "Olá, {$nome}! Seu cadastro no Hunter League foi aprovado. Abra o app e toque em \"Ativar QR Code\" para ler o QR do seu kit de ativação: " . rtrim((string)conf('site_url', 'https://www.hunterleague.com.br'), '/') . '/app';
    $mail = false;
    if ($p['email'] && email_configurado()) {
      try { enviar_email($p['email'], 'Hunter League: cadastro aprovado', '<p>' . h($txt) . '</p>', $txt); $mail = true; }
      catch (Throwable $x) { auditar('sistema', 'e-mail de aprovação falhou', $p['doc'], mb_substr($x->getMessage(), 0, 180)); }
    }
    json_out(['ok' => true, 'codigo' => $p['codigo'], 'wa' => 'https://wa.me/55' . $p['whatsapp'] . '?text=' . rawurlencode($txt),
      'msg' => $p['codigo'] ? "Aprovado de novo. Código {$p['codigo']}." : "Aprovado. Agora {$nome} entra no app e ativa o QR do adesivo." . ($mail ? ' O aviso foi por e-mail.' : '') . ' Avise também no WhatsApp.']);
  }
  if (in_array($o, ['recusar', 'bloquear', 'desfiliar', 'reativar'], true)) {
    if ($o === 'reativar' && !$p['aprovado_em']) json_out(['erro' => 'Use Aprovar para este cadastro.'], 422);
    $novo = ['recusar' => 'recusado', 'bloquear' => 'bloqueado', 'desfiliar' => 'desfiliado', 'reativar' => 'ativo'][$o];
    /* recusou quem ocupou um QR genérico e nunca foi aprovado: o código volta a ficar livre para outro cartaz */
    $solta = $o === 'recusar' && !$p['aprovado_em'] && qr_generico($p['codigo']);
    q('UPDATE parceiros SET status = ?' . ($solta ? ', codigo = NULL' : '') . ' WHERE id = ?', [$novo, $p['id']]);
    auditar($quem, $o . ' parceiro', $alvo, $solta ? "QR {$p['codigo']} liberado" : '');
    json_out(['ok' => true, 'msg' => 'Parceiro ' . $novo . '.' . ($solta ? " O QR {$p['codigo']} voltou a ficar livre." : '')]);
  }
  if ($o === 'senha') {
    $nova = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
    q('UPDATE parceiros SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $p['id']]);
    limpa_tentativas('app:' . $p['doc']);
    auditar($quem, 'nova senha parceiro', $alvo);
    json_out(['ok' => true, 'senha' => $nova, 'msg' => 'Senha nova criada.']);
  }
  if ($o === 'editar') {
    $wa = tel_normal($s('wa', 20)); $pix = $s('pix', 120);
    if (strlen($wa) < 10 || mb_strlen($pix) < 5) json_out(['erro' => 'Confira WhatsApp e PIX.'], 422);
    q('UPDATE parceiros SET whatsapp = ?, pix = ?, obs = ? WHERE id = ?', [$wa, $pix, $s('obs', 1000) ?: null, $p['id']]);
    auditar($quem, 'editou parceiro', $alvo);
    json_out(['ok' => true, 'msg' => 'Dados salvos.']);
  }
  json_out(['erro' => 'Ação inválida.'], 422);
}

case 'pagar_saque': {
  $g = one('SELECT g.*, p.codigo FROM saques g JOIN parceiros p ON p.id = g.parceiro_id WHERE g.id = ?', [(int)($e['id'] ?? 0)]);
  if (!$g) json_out(['erro' => 'Saque não encontrado.'], 404);
  if ($g['pago_em']) json_out(['erro' => 'Este saque já estava pago.'], 409);
  $pix = $s('pix', 160); $arq = null;
  if (!empty($_FILES['arquivo']['name'])) { try { $arq = guardar_comprovante($_FILES['arquivo']); } catch (RuntimeException $x) { json_out(['erro' => $x->getMessage()], 422); } }
  q('UPDATE saques SET pago_em = ?, pix_id = ?, arquivo = ?, pago_por = ? WHERE id = ?', [hoje(), $pix ?: null, $arq, $a['usuario'], $g['id']]);
  auditar($quem, 'pagou saque', $g['codigo'], 'R$ ' . number_format((float)$g['valor'], 2, ',', '.') . ($pix ? " · {$pix}" : ''));
  json_out(['ok' => true, 'msg' => 'Saque pago. O parceiro já vê o comprovante no app.']);
}

case 'anexar': {
  $g = one('SELECT g.*, p.codigo FROM saques g JOIN parceiros p ON p.id = g.parceiro_id WHERE g.id = ?', [(int)($e['id'] ?? 0)]);
  if (!$g || !$g['pago_em']) json_out(['erro' => 'Saque pago não encontrado.'], 404);
  if (empty($_FILES['arquivo']['name'])) json_out(['erro' => 'Escolha o arquivo do comprovante.'], 422);
  try { $arq = guardar_comprovante($_FILES['arquivo']); } catch (RuntimeException $x) { json_out(['erro' => $x->getMessage()], 422); }
  if ($g['arquivo']) @unlink(PASTA_ARQ . '/comprovantes/' . basename($g['arquivo']));
  q('UPDATE saques SET arquivo = ? WHERE id = ?', [$arq, $g['id']]);
  auditar($quem, 'comprovante anexado', $g['codigo'], 'saque #' . $g['id']);
  json_out(['ok' => true, 'msg' => 'Comprovante anexado. O parceiro já pode abrir no app.']);
}

case 'entregar': {
  $r = one('SELECT r.*, p.codigo FROM premios r JOIN parceiros p ON p.id = r.parceiro_id WHERE r.id = ?', [(int)($e['id'] ?? 0)]);
  if (!$r || $r['entregue_em']) json_out(['erro' => 'Prêmio não encontrado ou já entregue.'], 422);
  q('UPDATE premios SET entregue_em = ?, entregue_por = ? WHERE id = ?', [hoje(), $a['usuario'], $r['id']]);
  auditar($quem, 'prêmio entregue', $r['codigo'], "{$r['tipo']} {$r['alvo']}: {$r['premio']}");
  json_out(['ok' => true, 'msg' => 'Entrega registrada: ' . $r['premio'] . '.']);
}

case 'salvar_config': {
  if (!$master) json_out(['erro' => 'Só o master muda as configurações.'], 403);
  $metas = [];
  foreach (preg_split('/\R/', $s('metas', 2000)) as $l) { [$x, $y] = array_pad(array_map('trim', explode('|', $l, 2)), 2, ''); if ((int)$x > 0 && $y !== '') $metas[] = (int)$x . '|' . mb_substr(strip_tags($y), 0, 80); }
  $v = [
    'bonus_planos' => implode('|', array_map(fn($k) => (string)max(0, (float)str_replace(',', '.', $s('bonus_pl_' . $k, 10))), array_keys(PLANOS))),
    'prazo_saque' => mb_substr(strip_tags($s('prazo_saque', 60)), 0, 60) ?: 'até 3 dias úteis',
    'whatsapp' => so_digitos($s('whatsapp', 20)), 'mensagem_wa' => $s('mensagem_wa', 300),
    'cidades' => implode(', ', array_filter(array_map('trim', explode(',', $s('cidades', 1000))))),
    'cidades_promo' => implode(', ', array_filter(array_map('trim', explode(',', $s('cidades_promo', 1000))))),
    'pixel_meta' => so_digitos($s('pixel_meta', 30)), 'metas' => implode("\n", $metas),
    'validade_dias' => (string)min(365, max(15, (int)$s('validade_dias', 3))),
    'kit_ativo' => !empty($e['kit_ativo']) ? '1' : '0',
    'kit_nome' => mb_substr(strip_tags($s('kit_nome', 60)), 0, 60) ?: 'Kit completo do parceiro',
    'kit_itens' => mb_substr(strip_tags($s('kit_itens', 300)), 0, 300),
    'kit_contatos' => (string)max(1, min(500, (int)$s('kit_contatos', 4) ?: 20)),
    'kit_vendas' => (string)max(1, min(100, (int)$s('kit_vendas', 4) ?: 2)),
  ];
  if (!array_key_exists('kit_nome', $e)) unset($v['kit_ativo'], $v['kit_nome'], $v['kit_itens'], $v['kit_contatos'], $v['kit_vendas']);   // formulário sem a parte do kit não mexe nele
  foreach (explode('|', $v['bonus_planos']) as $x) if ((float)$x <= 0) json_out(['erro' => 'Informe o bônus de todos os planos.'], 422);
  if (strlen($v['whatsapp']) < 12 || !str_contains($v['mensagem_wa'], '{codigo}')) json_out(['erro' => 'WhatsApp com 55 + DDD, e a mensagem precisa ter {codigo}.'], 422);
  foreach ($v as $k => $x) { if (one('SELECT chave FROM config WHERE chave = ?', [$k])) q('UPDATE config SET valor = ? WHERE chave = ?', [$x, $k]); else q('INSERT INTO config (chave, valor) VALUES (?, ?)', [$k, $x]); }
  auditar($quem, 'configurações');
  json_out(['ok' => true, 'msg' => 'Configurações salvas.']);
}

case 'integracoes': {
  /* token da Casa dos Dados: só entra, nunca sai (a tela mostra os 4 últimos caracteres) */
  $o = $s('o');
  if ($o === 'testar') { $r = cdd_testar(); auditar($quem, 'testou Casa dos Dados', $r['ok'] ? 'ok' : 'erro'); json_out(['ok' => $r['ok'], 'msg' => $r['msg']]); }
  if ($o === 'apagar') { q("DELETE FROM config WHERE chave = 'casadosdados_key'"); auditar($quem, 'apagou o token da Casa dos Dados'); json_out(['ok' => true, 'msg' => 'Token apagado.']); }
  $k = preg_replace('/\s+/', '', (string)($e['casadosdados_key'] ?? ''));
  if ($k === '') json_out(['erro' => 'Cole o token.'], 422);
  if (strlen($k) < 16 || strlen($k) > 400) json_out(['erro' => 'Esse token não parece completo. Copie de novo no portal da Casa dos Dados.'], 422);
  if (one("SELECT chave FROM config WHERE chave = 'casadosdados_key'")) q("UPDATE config SET valor = ? WHERE chave = 'casadosdados_key'", [$k]); else q("INSERT INTO config (chave, valor) VALUES ('casadosdados_key', ?)", [$k]);
  auditar($quem, 'salvou o token da Casa dos Dados', '…' . mb_substr($k, -4));
  $r = cdd_testar();
  json_out(['ok' => true, 'msg' => 'Token salvo. ' . $r['msg']]);
}
case 'email': {
  if ($s('o') === 'teste') {
    if (!email_configurado()) json_out(['erro' => 'Salve o usuário e a senha do e-mail antes de testar.'], 422);
    $para = $s('para', 120) ?: (string)conf('smtp_usuario', '');
    try { enviar_email($para, 'Hunter League: teste de e-mail', '<p>Funcionou! A recuperação de senha por e-mail está ativa.</p>', 'Funcionou! A recuperação de senha por e-mail está ativa.'); }
    catch (Throwable $x) { json_out(['erro' => 'Não enviou: ' . mb_substr($x->getMessage(), 0, 180)], 502); }
    auditar($quem, 'teste de e-mail', $para);
    json_out(['ok' => true, 'msg' => "E-mail de teste enviado para {$para}."]);
  }
  $v = ['smtp_host' => preg_replace('/[^a-z0-9.\-]/i', '', $s('smtp_host', 80)) ?: 'smtp.zoho.com', 'smtp_porta' => (string)((int)$s('smtp_porta', 5) ?: 465),
        'smtp_usuario' => $s('smtp_usuario', 120), 'smtp_nome' => mb_substr(strip_tags($s('smtp_nome', 80)), 0, 80) ?: 'Hunter League'];
  if ($v['smtp_usuario'] !== '' && !filter_var($v['smtp_usuario'], FILTER_VALIDATE_EMAIL)) json_out(['erro' => 'O usuário é o endereço de e-mail completo.'], 422);
  if ((string)($e['smtp_senha'] ?? '') !== '') $v['smtp_senha'] = (string)$e['smtp_senha'];
  foreach ($v as $k => $x) { if (one('SELECT chave FROM config WHERE chave = ?', [$k])) q('UPDATE config SET valor = ? WHERE chave = ?', [$x, $k]); else q('INSERT INTO config (chave, valor) VALUES (?, ?)', [$k, $x]); }
  auditar($quem, 'configurou e-mail', $v['smtp_usuario']);
  json_out(['ok' => true, 'msg' => 'E-mail salvo. Use "Enviar teste" para conferir.']);
}

case 'equipe': {
  if (!$master) json_out(['erro' => 'Só o master mexe na equipe.'], 403);
  if ($s('o') === 'criar') {
    $u = strtolower($s('usuario', 40)); $pw = (string)($e['senha'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u) || strlen($pw) < 10 || one('SELECT id FROM admins WHERE usuario = ?', [$u])) json_out(['erro' => 'Usuário inválido ou já existe; senha com 10 ou mais caracteres.'], 422);
    q('INSERT INTO admins (usuario, nome, papel, senha_hash, ativo, criado_em) VALUES (?,?,?,?,1,?)', [$u, $s('nome', 80) ?: $u, $s('papel') === 'master' ? 'master' : 'atendimento', password_hash($pw, PASSWORD_DEFAULT), agora()]);
    auditar($quem, 'criou usuário', $u);
    json_out(['ok' => true, 'msg' => 'Usuário criado.']);
  }
  $id = (int)($e['id'] ?? 0);
  if ($id === (int)$a['id']) json_out(['erro' => 'Você não pode desativar a si mesmo.'], 422);
  q('UPDATE admins SET ativo = 1 - ativo WHERE id = ?', [$id]); auditar($quem, 'ativou/desativou usuário', (string)$id);
  json_out(['ok' => true, 'msg' => 'Feito.']);
}

case 'minha_senha': {
  if (!password_verify((string)($e['atual'] ?? ''), $a['senha_hash']) || strlen((string)($e['nova'] ?? '')) < 10) json_out(['erro' => 'Senha atual errada ou nova com menos de 10 caracteres.'], 422);
  q('UPDATE admins SET senha_hash = ? WHERE id = ?', [password_hash((string)$e['nova'], PASSWORD_DEFAULT), $a['id']]);
  auditar($quem, 'trocou a própria senha');
  json_out(['ok' => true, 'msg' => 'Senha trocada.']);
}

case 'sair': {
  auditar($quem, 'logout'); unset($_SESSION['admin_id'], $_SESSION['csrf']);
  json_out(['ok' => true]);
}
}
json_out(['erro' => 'Ação desconhecida.'], 404);
