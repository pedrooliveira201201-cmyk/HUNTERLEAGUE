<?php
/* Planilhas para a contabilidade (CSV que abre direto no Excel: separador ; e acentos certos).
   ?t=indicacoes  todas as indicações (ou só do mês de criação, com &c=AAAA-MM)
   ?t=pagamentos  saques pagos no mês (&c=AAAA-MM), uma linha por saque, com CNPJ e PIX do parceiro
   ?t=instalacoes instalações de um mês (&c=AAAA-MM), uma linha por cliente, com bônus, 1ª fatura e saque */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$a = admin_logado();
if (!$a || $a['papel'] !== 'master') { http_response_code(403); exit('Só o master baixa planilhas.'); }
$t = (string)($_GET['t'] ?? '');
$c = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['c'] ?? '')) ? $_GET['c'] : '';
$din = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, ',', '');
$dt = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
$dth = fn($v) => $v ? date('d/m/Y H:i', strtotime($v)) : '';

if ($t === 'indicacoes') {
  $sql = "SELECT i.*, p.codigo, p.tipo, COALESCE(p.loja, p.nome) AS pnome, p.doc AS pdoc FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id";
  $rows = $c ? all("$sql WHERE i.criado_em >= ? AND i.criado_em < ? ORDER BY i.criado_em", [$c . '-01 00:00:00', date('Y-m-01 00:00:00', strtotime($c . '-01 +1 month'))]) : all("$sql ORDER BY i.criado_em");
  $cab = ['Criada em', 'Cliente', 'Telefone', 'CPF do cliente', 'E-mail', 'CEP', 'Número', 'Plano', 'Etapa', 'Motivo', 'Instalada em', '1ª fatura paga em', 'Bônus (R$)', 'Origem', 'Código do parceiro', 'Parceiro', 'Tipo', 'CPF/CNPJ do parceiro'];
  $lin = array_map(fn($r) => [$dth($r['criado_em']), $r['cliente_nome'], tel_br($r['cliente_tel']), $r['cliente_cpf'] ? doc_br($r['cliente_cpf']) : '', $r['cliente_email'] ?? '', $r['cliente_cep'] ?? '', $r['cliente_numero'] ?? '',
    $r['plano'] ?? '', $r['status'], $r['motivo'] ?? '', $dt($r['instalado_em']), $dt($r['fatura_em']), $r['status'] === 'Instalado' ? $din($r['comissao']) : '', ($r['origem'] ?? 'qr') === 'app' ? 'App do parceiro' : 'QR / WhatsApp',
    $r['codigo'], $r['pnome'], $r['tipo'], doc_br($r['pdoc'])], $rows);
  $nome = 'indicacoes' . ($c ? "-$c" : '') . '-' . date('Y-m-d');
} elseif ($t === 'pagamentos' && $c) {
  $fim = date('Y-m-t', strtotime($c . '-01'));
  $rows = all("SELECT g.*, p.codigo, p.tipo, p.nome, p.loja, p.doc FROM saques g JOIN parceiros p ON p.id = g.parceiro_id
               WHERE g.pago_em BETWEEN ? AND ? ORDER BY g.pago_em, g.id", [$c . '-01', $fim]);
  $cab = ['Pago em', 'Saque nº', 'Pedido em', 'Código', 'Tipo', 'Responsável', 'Empresa', 'CNPJ/CPF', 'Chave PIX', 'Clientes', 'Bônus (R$)', 'ID do PIX', 'Comprovante anexado', 'Pago por'];
  $lin = array_map(fn($r) => [$dt($r['pago_em']), (int)$r['id'], $dth($r['pedido_em']), $r['codigo'], $r['tipo'], $r['nome'], $r['loja'] ?? '', doc_br($r['doc']), $r['chave_pix'],
    (int)$r['n'], $din($r['valor']), $r['pix_id'] ?? '', $r['arquivo'] ? 'Sim' : 'Não', $r['pago_por'] ?? ''], $rows);
  $lin[] = ['TOTAL', '', '', '', '', '', '', '', '', array_sum(array_map(fn($r) => (int)$r['n'], $rows)), $din(array_sum(array_map(fn($r) => (float)$r['valor'], $rows))), '', '', ''];
  $nome = "bonus-pagos-$c";
} elseif ($t === 'instalacoes' && $c) {
  $rows = all("SELECT i.*, p.codigo, COALESCE(p.loja, p.nome) AS pnome, p.doc, g.pago_em AS sq_pago FROM indicacoes i JOIN parceiros p ON p.id = i.parceiro_id LEFT JOIN saques g ON g.id = i.saque_id
               WHERE i.status = 'Instalado' AND i.instalado_em BETWEEN ? AND ? ORDER BY p.codigo, i.instalado_em", [$c . '-01', date('Y-m-t', strtotime($c . '-01'))]);
  $cab = ['Instalada em', 'Semana (seg)', 'Código', 'Parceiro', 'CNPJ/CPF do parceiro', 'Cliente', 'CPF do cliente', 'Plano', 'Bônus (R$)', '1ª fatura paga em', 'Situação do bônus', 'Saque nº'];
  $lin = array_map(fn($r) => [$dt($r['instalado_em']), date('d/m/Y', strtotime(semana_de($r['instalado_em'])[0])), $r['codigo'], $r['pnome'], doc_br($r['doc']), $r['cliente_nome'],
    $r['cliente_cpf'] ? doc_br($r['cliente_cpf']) : '', $r['plano'] ?? '', $din($r['comissao']), $dt($r['fatura_em']),
    $r['saque_id'] ? ($r['sq_pago'] ? 'Pago' : 'Saque pedido') : ($r['fatura_em'] ? 'Liberado' : 'Aguardando 1ª fatura'), $r['saque_id'] ?? ''], $rows);
  $nome = "instalacoes-$c";
} else { http_response_code(400); exit('Planilha inválida.'); }

auditar('admin:' . $a['usuario'], 'exportou planilha', $nome, count($lin) . ' linhas');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hunter-league-' . $nome . '.csv"');
header('Cache-Control: private, no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
// evita que o Excel execute fórmulas vindas de dados digitados (=, +, -, @)
$limpo = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
fputcsv($out, $cab, ';', '"', '');
foreach ($lin as $l) fputcsv($out, array_map($limpo, $l), ';', '"', '');
fclose($out);
