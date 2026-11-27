<?php
/* Central da CallNet (admin da Hunter League): entrada e a tela do painel. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');

$a = admin_logado();
$erro = '';
if (!$a && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = strtolower(mb_substr(trim((string)($_POST['usuario'] ?? '')), 0, 40)); $chave = 'adm:' . $u; $chaveIp = 'admip:' . ip_hash();
  if (max(travado($chave, 5, 900), travado($chaveIp, 15, 900)) > 0) $erro = 'Muitas tentativas. Aguarde 15 minutos.';
  else {
    $r = one('SELECT * FROM admins WHERE usuario = ? AND ativo = 1', [$u]);
    $ok = password_verify((string)($_POST['senha'] ?? ''), $r['senha_hash'] ?? password_hash('x', PASSWORD_DEFAULT));
    if ($r && $ok) {
      session_regenerate_id(true);
      $_SESSION['admin_id'] = (int)$r['id']; $_SESSION['admin_visto'] = time(); unset($_SESSION['csrf']);
      limpa_tentativas($chave); auditar('admin:' . $r['usuario'], 'login');
      header('Location: ./', true, 303); exit;
    }
    errou($chave); errou($chaveIp); $erro = 'Usuário ou senha incorretos.';
  }
}

if ($a) {
  $boot = 'window.HL_ADMIN = ' . json_encode(['modo' => 'real', 'token' => token_admin(), 'nome' => primeiro_nome($a['nome']), 'papel' => $a['papel'], 'foto' => foto_admin_url((int)$a['id'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';';
  echo str_replace(['/*__ADMIN_BOOT__*/', '__BASE__'], [$boot, '../'], file_get_contents(__DIR__ . '/app.tpl.html'));
  exit;
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="theme-color" content="#050607">
<title>Entrar | Central Hunter League</title><link rel="icon" href="../icons/favicon-64.png"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="../icons/apple-touch-icon.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Central HL"><meta name="apple-mobile-web-app-status-bar-style" content="black">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@900&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(60% 50% at 50% 0,rgba(25,247,255,.1),transparent 60%),#050607;color:#EEF2F4;font:600 15px Montserrat,system-ui,sans-serif}
form{width:min(380px,100%);text-align:center}
img{width:120px;height:auto;filter:drop-shadow(0 12px 30px rgba(0,0,0,.8))}
h1{margin:10px 0 2px;font:900 44px/.9 "Big Shoulders Display",Impact,sans-serif;text-transform:uppercase}
p{margin:0 0 22px;color:#7E8A92;letter-spacing:.1em;text-transform:uppercase;font-size:12.5px;font-weight:800}
label{display:block;text-align:left;margin-top:12px;color:#BEC5CC;font-size:13.5px}
input{display:block;width:100%;height:52px;margin-top:6px;padding:0 14px;border:1px solid #26333A;background:#0B1013;color:#fff;font:600 16px Montserrat,sans-serif;outline:none;clip-path:polygon(10px 0,100% 0,100% calc(100% - 10px),calc(100% - 10px) 100%,0 100%,0 10px)}
input:focus{border-color:#19F7FF}
button{width:100%;height:56px;margin-top:20px;border:0;background:#19F7FF;color:#050607;font:900 21px "Big Shoulders Display",Impact,sans-serif;text-transform:uppercase;cursor:pointer;clip-path:polygon(12px 0,100% 0,100% calc(100% - 12px),calc(100% - 12px) 100%,0 100%,0 12px);box-shadow:0 0 24px rgba(25,247,255,.3)}
.e{margin-top:14px;padding:12px;background:rgba(255,107,129,.12);border-left:3px solid #FF6B81;color:#FFC2CD;text-align:left}
</style></head><body>
<form method="post" autocomplete="on">
 <img src="../img/crest-160.webp" alt="Hunter League">
 <h1>Central</h1><p>CallNet · Hunter League</p>
 <?php if ($erro): ?><div class="e"><?= h($erro) ?></div><?php endif; ?>
 <label>Usuário<input name="usuario" autocomplete="username" required autofocus></label>
 <label>Senha<input name="senha" type="password" autocomplete="current-password" required></label>
 <button>Entrar</button>
 <div style="margin-top:14px"><button type="button" data-instalar style="all:unset;box-sizing:border-box;display:none;align-items:center;gap:6px;margin-top:0;padding:6px 10px;color:#8A959C;font:700 13px Montserrat,system-ui,sans-serif;cursor:pointer;border-bottom:1px dashed #33434A"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M12 7v7m-3-3 3 3 3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Instalar app no celular</button></div>
</form>
<script src="../assets/instalar.js?v=2" defer></script>
</body></html>
