<?php
/* Casa dos Dados (API Pro). O token fica no servidor: em config.php ('casadosdados_key') ou salvo pela Central
   (Mais → Integrações). Nunca volta para o navegador; a Central mostra só os 4 últimos caracteres. */
declare(strict_types=1);

const CDD_HOST = 'https://api.casadosdados.com.br';

function cdd_chave(): string {
  $c = (string)(cfg()['casadosdados_key'] ?? '');
  if ($c !== '') return $c;
  $r = one("SELECT valor FROM config WHERE chave = 'casadosdados_key'");
  return (string)($r['valor'] ?? '');
}

/* chamada à API; devolve [código HTTP, corpo decodificado ou texto] */
function cdd_req(string $metodo, string $rota, ?array $corpo = null): array {
  $k = cdd_chave();
  if ($k === '') return [0, 'Token da Casa dos Dados não configurado.'];
  if (!function_exists('curl_init')) return [0, 'O servidor não tem cURL.'];
  $ch = curl_init(CDD_HOST . $rota);
  $h = ['api-key: ' . $k, 'Accept: application/json'];
  if ($corpo !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE)); }
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8]);
  $txt = curl_exec($ch); $cod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
  if ($txt === false) return [0, 'Sem resposta da Casa dos Dados: ' . $err];
  $j = json_decode((string)$txt, true);
  return [$cod, is_array($j) ? $j : mb_substr((string)$txt, 0, 300)];
}

/* teste de conexão sem gastar saldo: tenta as rotas de saldo (v5, depois v4) */
function cdd_testar(): array {
  if (cdd_chave() === '') return ['ok' => false, 'msg' => 'Cole o token e salve antes de testar.'];
  $ultimo = null;
  foreach (['/v5/saldo', '/v4/saldo'] as $r) {
    [$cod, $res] = cdd_req('GET', $r);
    if ($cod === 401) return ['ok' => false, 'msg' => 'Token recusado (401): confira se colou o token inteiro.'];
    if ($cod === 403) return ['ok' => false, 'msg' => 'Token aceito, mas sem acesso a este recurso (403). Confira o plano.'];
    if ($cod >= 200 && $cod < 300) {
      $saldo = is_array($res) ? ($res['saldo'] ?? $res['data']['saldo'] ?? $res['creditos'] ?? null) : null;
      return ['ok' => true, 'msg' => 'Conectado à Casa dos Dados.' . ($saldo !== null ? ' Saldo: ' . (is_scalar($saldo) ? $saldo : json_encode($saldo)) . '.' : ''), 'resposta' => $res];
    }
    $ultimo = [$cod, $res];
  }
  return ['ok' => false, 'msg' => 'A Casa dos Dados respondeu ' . ($ultimo[0] ?: 'sem conexão') . '. ' . (is_string($ultimo[1]) ? $ultimo[1] : ''), 'resposta' => $ultimo[1]];
}
