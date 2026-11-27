<?php
/* GoTo (WhatsApp da CallNet) → Hunter League.
   - Conexão: a equipe cola o ID e o segredo do cliente OAuth do GoTo na Central (Mais → Integrações) e clica em Conectar;
     o GoTo volta em /api/goto-retorno.php, que cria o canal de aviso (webhook) e a assinatura das mensagens.
   - Cada mensagem do WhatsApp chega em /api/goto-webhook.php e fica guardada (conversa na ficha do CRM).
   - Mensagem com "Código do parceiro: HL-…" (a que o cliente manda ao ler o QR) vira indicação daquele parceiro.
   - Cartões de contato (vCard) mandados do WhatsApp do próprio parceiro viram a lista de contatos dele.
   Adaptado do painel do contratenio (26/11/2026). */
declare(strict_types=1);

function goto_url(string $f): string { return rtrim((string)conf('site_url', 'https://www.hunterleague.com.br'), '/') . '/api/' . $f; }
function goto_cfg(string $k): string { return (string)(one('SELECT valor FROM config WHERE chave = ?', [$k])['valor'] ?? ''); }
function goto_set(string $k, string $v): void {
  if (one('SELECT chave FROM config WHERE chave = ?', [$k])) q('UPDATE config SET valor = ? WHERE chave = ?', [$v, $k]);
  else q('INSERT INTO config (chave, valor) VALUES (?, ?)', [$k, $v]);
}
function goto_migrar(): void {
  static $ok = false; if ($ok) return; $ok = true;
  if (conf('goto_v') === '1') return;
  $sq = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $id = $sq ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $eng = $sq ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  db()->exec("CREATE TABLE IF NOT EXISTS goto_msgs (id $id, msg_id VARCHAR(120) NULL, tel VARCHAR(11) NOT NULL, dir VARCHAR(3) NOT NULL, perfil VARCHAR(80) NULL, texto TEXT NULL, em DATETIME NOT NULL)$eng");
  try { db()->exec('CREATE INDEX idx_gm_tel ON goto_msgs (tel, em)'); } catch (Throwable $e) {}
  try { db()->exec('CREATE UNIQUE INDEX idx_gm_msg ON goto_msgs (msg_id)'); } catch (Throwable $e) {}
  q("INSERT INTO config (chave, valor) VALUES ('goto_v', '1')");
}
function goto_http(string $metodo, string $url, array $hdr, ?string $corpo = null): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $hdr]);
  if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
  $r = (string)curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$st, json_decode($r, true) ?: [], mb_substr($r, 0, 300)];
}
function goto_tel(string $v): string {
  $d = preg_replace('/\D/', '', $v);
  if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
  if (strlen($d) === 10 && in_array($d[2], ['6', '7', '8', '9'], true)) $d = substr($d, 0, 2) . '9' . substr($d, 2); /* celular sem o 9 */
  return $d;
}
function goto_toque($t): string { if (is_string($t)) return $t; if (is_array($t)) foreach (['value', 'phoneNumber', 'number', 'address', 'id'] as $k) if (!empty($t[$k]) && is_string($t[$k])) return $t[$k]; return ''; }
function goto_nome(?string $s): ?string {
  $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
  if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 60 || preg_match('/[\d@\/]/', $s)) return null;
  return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
}

/* página que o GoTo abre depois da autorização */
function goto_pagina(bool $ok, string $msg): void {
  http_response_code($ok ? 200 : 400); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>GoTo · Hunter League</title>'
    . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#050607;color:#EEF2F4;font:16px/1.5 system-ui,sans-serif">'
    . '<div style="max-width:440px;padding:30px;border:2px solid ' . ($ok ? '#19F7FF' : '#FF5C7A') . ';background:#0B1013;text-align:center">'
    . '<div style="font-size:44px">' . ($ok ? '✅' : '⚠️') . '</div><h1 style="font-size:20px;margin:8px 0">' . ($ok ? 'WhatsApp do GoTo conectado' : 'Não deu para conectar') . '</h1>'
    . '<p style="color:#A9B4C6">' . h($msg) . '</p><a href="/admin/#mais" style="display:inline-block;margin-top:12px;padding:12px 18px;background:#19F7FF;color:#050607;text-decoration:none;font-weight:800">Voltar para a Central</a></div>';
}

/* troca o código pelo token, cria o canal de aviso e assina as mensagens do WhatsApp */
function goto_retorno(): void {
  $falha = function (string $m) { goto_set('goto_erro', $m); goto_pagina(false, $m); };
  $st = explode('|', goto_cfg('goto_state')); $rec = (string)($_GET['state'] ?? '');
  if (count($st) !== 2 || !$rec || !hash_equals($st[0], $rec) || (int)$st[1] < time()) { goto_pagina(false, 'Link vencido ou inválido. Volte à Central e clique em Conectar de novo.'); return; }
  goto_set('goto_state', '');
  if (!empty($_GET['error'])) { $falha('O GoTo não autorizou: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200)); return; }
  $code = (string)($_GET['code'] ?? ''); $cid = goto_cfg('goto_cid'); $sec = goto_cfg('goto_sec');
  if (!$code || !$cid || !$sec) { $falha('Faltou o código do GoTo ou o ID/segredo do cliente.'); return; }
  [$s, $t] = goto_http('POST', 'https://authentication.logmeininc.com/oauth/token', ['Authorization: Basic ' . base64_encode("$cid:$sec"), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => goto_url('goto-retorno.php')]));
  $at = (string)($t['access_token'] ?? '');
  if (!$at) { $falha('O GoTo recusou o ID/segredo (' . ($t['error_description'] ?? $t['error'] ?? "HTTP $s") . '). Confira se copiou certo e se o endereço de retorno está cadastrado no cliente do GoTo.'); return; }
  $bear = ['Authorization: Bearer ' . $at, 'Accept: application/json', 'Content-Type: application/json'];
  $conta = preg_replace('/\D/', '', goto_cfg('goto_conta')) ?: preg_replace('/\D/', '', (string)($t['account_key'] ?? ''));
  if (!$conta) { [, $me] = goto_http('GET', 'https://api.getgo.com/admin/rest/v1/me', $bear); $conta = preg_replace('/\D/', '', (string)($me['accounts'][0]['key'] ?? $me['accountKey'] ?? '')); }
  if (!$conta) { $falha('Não achei a chave da conta GoTo (accountKey). Cole no campo "Conta GoTo" e conecte de novo.'); return; }
  $hook = goto_cfg('goto_hook'); if (!$hook) { $hook = bin2hex(random_bytes(20)); goto_set('goto_hook', $hook); }
  [$s, $c, $raw] = goto_http('POST', 'https://api.goto.com/notification-channel/v1/channels/hunter-league', $bear,
    json_encode(['channelType' => 'Webhook', 'webhookChannelData' => ['webhook' => ['url' => goto_url('goto-webhook.php') . '?k=' . $hook]]]));
  $canal = (string)($c['channelId'] ?? '');
  if (!$canal) { $falha("O GoTo não criou o canal de aviso (HTTP $s): $raw"); return; }
  $assinar = fn() => goto_http('POST', 'https://api.goto.com/messaging/v2/subscriptions', $bear,
    json_encode(['accountKey' => $conta, 'ownerEntity' => $conta, 'ownerEntityType' => 'ORGANIZATION', 'channelId' => $canal, 'eventTypes' => ['INBOUND_MESSAGE', 'OUTBOUND_MESSAGE']]));
  [$s, $r, $raw] = $assinar();
  goto_set('goto_canal', $canal);
  if (!empty($t['refresh_token'])) goto_set('goto_rt', (string)$t['refresh_token']);
  if ($s === 409) {
    /* já existe outra integração (o painel do contratenio) recebendo as mensagens desta conta */
    $sid = (string)($r['metadata']['conflictingSubscriptionId'] ?? ''); $outro = explode(':', (string)base64_decode($sid, true))[0];
    if ($outro !== $canal && goto_cfg('goto_assumir') !== '1') {
      goto_set('goto_conflito', $sid);
      $falha('Outra integração já recebe as mensagens desta conta GoTo (provavelmente o painel do contratenio). Na Central, marque "Trazer o WhatsApp para o Hunter League" e conecte de novo. O contratenio deixa de receber as mensagens.');
      return;
    }
    [$sd] = goto_http('DELETE', 'https://api.goto.com/messaging/v2/subscriptions/' . rawurlencode($sid), $bear);
    [$s2, , $raw2] = $sd >= 200 && $sd < 300 ? $assinar() : [0, [], ''];
    if ($s2 < 200 || $s2 >= 300) { $falha('Não deu para trocar a assinatura antiga do GoTo (HTTP ' . ($sd >= 200 && $sd < 300 ? $s2 : $sd) . "). $raw2"); return; }
    goto_set('goto_assumir', ''); goto_set('goto_conflito', '');
  } elseif ($s < 200 || $s >= 300) { $falha("O GoTo não aceitou a assinatura de mensagens (HTTP $s): $raw. O usuário precisa ser Super Admin."); return; }
  goto_set('goto_conta', $conta); goto_set('goto_erro', '');
  goto_set('goto_ok', json_encode(['canal' => $canal, 'quem' => (string)($t['principal'] ?? ''), 'em' => agora()]));
  auditar('sistema', 'conectou o GoTo', $conta);
  goto_pagina(true, 'Pronto. As mensagens do WhatsApp da CallNet chegam no Hunter League: quem ler o QR e mandar o código vira indicação do parceiro, e a conversa aparece na ficha do CRM.');
}

/* recebe as mensagens */
function goto_webhook(): void {
  $k = (string)($_GET['k'] ?? ''); $hook = goto_cfg('goto_hook');
  if (!$hook || !hash_equals($hook, $k)) json_out(['erro' => 'Acesso negado'], 403);
  $raw = (string)file_get_contents('php://input', false, null, 0, 200001);
  if (trim($raw) === '') json_out(['ok' => true]);
  $j = json_decode($raw, true); if (!is_array($j)) json_out(['ok' => true]);
  goto_set('goto_ultimo', agora());
  $am = json_decode(goto_cfg('goto_amostra'), true) ?: []; array_unshift($am, mb_substr($raw, 0, 1200)); goto_set('goto_amostra', json_encode(array_slice($am, 0, 3), JSON_UNESCAPED_UNICODE));
  foreach (array_is_list($j) ? $j : [$j] as $ev) { try { goto_evento(is_array($ev) ? $ev : []); } catch (Throwable $x) { error_log('goto ' . $x->getMessage()); } }
  json_out(['ok' => true]);
}
function goto_evento(array $e): void {
  $p = $e['content']['payload'] ?? $e['payload'] ?? []; if (!is_array($p)) return;
  $tipo = (string)($e['type'] ?? ''); $dir = strtoupper((string)($p['direction'] ?? ($tipo === 'OUTBOUND_MESSAGE' ? 'OUTBOUND' : 'INBOUND')));
  if (!in_array($dir, ['INBOUND', 'OUTBOUND'], true)) return;
  if (strtoupper((string)($p['channel'] ?? 'WHATSAPP')) !== 'WHATSAPP') return;
  $conta = preg_replace('/\D/', '', goto_cfg('goto_conta')); $ak = preg_replace('/\D/', '', (string)($e['content']['accountKey'] ?? ''));
  if ($conta && $ak && $ak !== $conta) return;
  $in = $dir === 'INBOUND';
  $tel = goto_tel($in ? (goto_toque($p['authorTouchpoint'] ?? '') ?: goto_toque(($p['contactTouchpoints'] ?? [])[0] ?? '')) : goto_toque(($p['contactTouchpoints'] ?? [])[0] ?? ''));
  if (strlen($tel) < 10 || strlen($tel) > 11) return;
  $corpo = mb_substr((string)($p['body'] ?? ''), 0, 4000);
  $nAnexo = (int)($p['attachments']['count'] ?? (is_array($p['attachments'] ?? null) && array_is_list($p['attachments']) ? count($p['attachments']) : 0));
  $texto = $corpo !== '' ? $corpo : ($nAnexo ? "[$nAnexo anexo(s)]" : '[mensagem sem texto]');
  $perfil = null; if ($in) foreach ([$p['authorName'] ?? null, $p['contactName'] ?? null, $p['profileName'] ?? null, $p['displayName'] ?? null, $p['author']['name'] ?? null, $p['contact']['name'] ?? null] as $v) if (is_string($v) && ($perfil = goto_nome($v))) break;
  $em = agora(); if (!empty($p['timestamp']) && ($ts = strtotime((string)$p['timestamp']))) $em = date('Y-m-d H:i:s', min($ts, time()));
  $mid = mb_substr((string)($e['id'] ?? ($p['messageId'] ?? '')), 0, 120) ?: null;
  if ($mid && one('SELECT id FROM goto_msgs WHERE msg_id = ?', [$mid])) return; /* repetida */
  q('INSERT INTO goto_msgs (msg_id, tel, dir, perfil, texto, em) VALUES (?,?,?,?,?,?)', [$mid, $tel, $in ? 'in' : 'out', $perfil, mb_substr($texto, 0, 2000), $em]);
  if (!$in) return;
  goto_set('goto_n', (string)((int)goto_cfg('goto_n') + 1));

  /* 0) empresa do CRM que estava "Aguardando WhatsApp" respondeu: marca e volta para o topo da fila */
  try {
    foreach (all("SELECT id FROM empresas WHERE etapa = 'mapeado' AND motivo = 'Aguardando WhatsApp' AND (tel1 = ? OR tel2 = ?)", [$tel, $tel]) as $emp) {
      q("UPDATE empresas SET motivo = 'Respondeu no WhatsApp', proximo_em = ?, atualizado_em = ? WHERE id = ?", [agora(), agora(), $emp['id']]);
      q('INSERT INTO empresa_hist (empresa_id, em, quem, tipo, texto) VALUES (?,?,?,?,?)', [$emp['id'], agora(), 'WhatsApp', 'whatsapp', mb_substr('Respondeu no WhatsApp: ' . $texto, 0, 400)]);
    }
  } catch (Throwable $z) { /* CRM ainda não instalado */ }

  /* 1) quem leu o QR: "Código do parceiro: HL-ARG-9016" → indicação do parceiro */
  if (preg_match('/\bHL-(?:UBR-)?(?:[A-Z]{3}-\d{4}|TESTE-\d{2})\b/i', $corpo, $m)) {
    $par = parceiro_por_codigo(strtoupper($m[0]));
    if ($par && $par['status'] === 'ativo' && $par['whatsapp'] !== $tel) {
      if (!indicacao_valida('', $tel)) {
        q('INSERT INTO indicacoes (parceiro_id, cliente_nome, cliente_tel, status, origem, criado_em, criado_por, atualizado_em) VALUES (?,?,?,?,?,?,?,?)',
          [$par['id'], $perfil ?: 'Contato WhatsApp ' . $tel, $tel, 'Nova', 'qr', agora(), 'goto', agora()]);
        auditar('goto', 'indicação pelo QR', $par['codigo'], $perfil ?: $tel);
      }
      return;
    }
  }
  /* 2) cartões de contato mandados do WhatsApp do próprio parceiro → a lista dos 20 dele */
  if (stripos($corpo, 'BEGIN:VCARD') !== false) {
    $par = one("SELECT * FROM parceiros WHERE whatsapp = ? AND status IN ('ativo', 'pendente') ORDER BY id DESC LIMIT 1", [$tel]);
    if ($par) lancar_lista($par, $corpo, true, 'goto');
  }
}
