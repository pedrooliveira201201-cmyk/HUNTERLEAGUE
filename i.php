<?php
/* QR do parceiro: hunterleague.com.br/i/CODIGO
   Registra a leitura e leva o cliente direto ao WhatsApp da CallNet com o código do parceiro. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';

/* Abre o WhatsApp instalado direto na conversa, sem a página intermediária do api.whatsapp.com
   (que no Android manda para o download quando o celular só tem o WhatsApp Business).
   Android: intent://send (o sistema escolhe WhatsApp ou Business), com o wa.me de reserva.
   iPhone: whatsapp://send e, se nada abrir, o wa.me. Computador: wa.me. */
function wa_js(string $wa, string $msg): string {
  $q = 'phone=' . rawurlencode($wa) . '&text=' . rawurlencode($msg);
  $web = 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg);
  return '<script>window.HLWA=' . json_encode([
    'android' => 'intent://send/?' . $q . '#Intent;scheme=whatsapp;S.browser_fallback_url=' . rawurlencode($web) . ';end',
    'ios' => 'whatsapp://send?' . $q, 'web' => $web], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';'
   . 'window.abrirWA=function(){var u=navigator.userAgent||"",W=window.HLWA;'
   . 'if(/Android/i.test(u)){location.href=W.android;return}'
   . 'if(/iPhone|iPad|iPod/i.test(u)){var t=Date.now();location.href=W.ios;setTimeout(function(){if(!document.hidden&&Date.now()-t<2500)location.href=W.web},1600);return}'
   . 'location.href=W.web};'
   . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-wa]");if(a){e.preventDefault();window.abrirWA()}});</script>';
}

$codigo = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['c'] ?? '')));
$p = parceiro_por_codigo($codigo);
$wa = so_digitos(conf('whatsapp', '553420281071'));
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$generico = qr_generico($codigo);

/* QR genérico ainda livre: a peça foi impressa, mas nenhum parceiro ativou o código.
   Quem ler fala com a CallNet (a mensagem já leva o código). Se quem leu é um parceiro aprovado que ainda
   não ativou o QR (no iPhone, a câmera abre esta página), aparece o botão para ativar na conta dele. */
if (!$p && $generico) {
  $msg = str_replace('{codigo}', $codigo, conf('mensagem_wa', 'Olá! Quero saber da internet Nio Fibra. Código do parceiro: {codigo}'));
  $link = 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg);
  $eu = parceiro_sessao();
  $podeAtivar = $eu && $eu['status'] === 'ativo' && !$eu['codigo'];
  ?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Internet fibra Nio · CallNet</title>
  <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F0F5EB;color:#14412A;font:17px/1.45 system-ui,sans-serif;text-align:center}main{padding:24px;max-width:360px}.wa,.at{display:block;width:100%;margin-top:18px;padding:16px;border:0;border-radius:14px;background:#32E000;color:#14412A;font:800 17px system-ui,sans-serif;text-decoration:none;cursor:pointer}.at{background:#14412A;color:#fff}.dono{margin-top:34px;padding-top:18px;border-top:1px solid #C9D8BF;font-size:15px}.dono a{color:#14412A;font-weight:800}.cod{display:inline-block;margin-top:6px;padding:4px 10px;border-radius:8px;background:#fff;font-weight:800;letter-spacing:.04em}#m{min-height:1.4em;margin-top:12px;font-weight:700}</style>
  <main>
  <?php if ($podeAtivar): ?>
   <h1 style="font-size:24px">Ativar este QR na sua conta?</h1><p>Olá, <?= h(primeiro_nome($eu['nome'])) ?>! Este é o QR da sua peça:<br><span class="cod"><?= h($codigo) ?></span></p>
   <button class="at" id="at" type="button">Ativar este QR na minha conta</button><p id="m" role="status"></p>
   <script>document.getElementById('at').onclick=function(){var b=this,m=document.getElementById('m');b.disabled=true;b.textContent='Ativando…';
    fetch('/api/qr-ativar.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({codigo:<?= json_encode($codigo) ?>})}).then(function(r){return r.json()}).then(function(j){
     if(j.ok){m.textContent=j.msg;setTimeout(function(){location.href='/app'},1200);return}b.disabled=false;b.textContent='Ativar este QR na minha conta';m.textContent=j.erro||'Não deu para ativar.'}).catch(function(){b.disabled=false;b.textContent='Ativar este QR na minha conta';m.textContent='Sem conexão. Tente de novo.'})}</script>
  <?php else: ?>
   <h1 style="font-size:24px">Internet fibra Nio</h1><p>Fale com a CallNet no WhatsApp e veja a oferta para o seu endereço.</p><a class="wa" data-wa href="<?= h($link) ?>">Falar com a CallNet</a><?= wa_js($wa, $msg) ?>
   <p class="dono">Quer ser parceiro? <a href="/cadastro">Crie sua conta</a>.</p>
  <?php endif; ?>
  </main></html><?php
  exit;
}

/* o QR só leva ao parceiro quando a conta está ativa */
$liberado = $p && $p['status'] === 'ativo';
if (!$liberado) {
  $link = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Olá! Quero saber da internet Nio Fibra.');
  http_response_code(404);
  ?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Internet fibra · CallNet</title>
  <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F0F5EB;color:#14412A;font:17px/1.45 system-ui,sans-serif;text-align:center}main{padding:24px;max-width:360px}a{display:block;margin-top:18px;padding:16px;border-radius:14px;background:#32E000;color:#14412A;font-weight:800;text-decoration:none}</style>
  <main><h1 style="font-size:24px">Este código não está ativo</h1><p>Mas você ainda pode falar com a CallNet pelo WhatsApp.</p><a data-wa href="<?= h($link) ?>">Falar com a CallNet</a></main><?= wa_js($wa, 'Olá! Quero saber da internet Nio Fibra.') ?></html><?php
  exit;
}

$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
$bot = (bool)preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp/i', $ua);
if (!$bot) q('INSERT INTO leituras (parceiro_id, em, ip_hash, agente) VALUES (?,?,?,?)', [$p['id'], agora(), ip_hash(), $ua]);

$msg = str_replace('{codigo}', $p['codigo'], conf('mensagem_wa', 'Olá! Quero saber da internet Nio Fibra. Código do parceiro: {codigo}'));
$link = 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg);
$pixel = preg_replace('/\D/', '', (string)conf('pixel_meta', ''));

/* Página curta que abre o WhatsApp instalado direto na conversa (com o pixel da Meta, se configurado).
   O botão fica sempre visível: alguns navegadores internos bloqueiam a abertura automática. */
?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Abrindo o WhatsApp…</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#32E000;color:#14412A;font:17px/1.45 system-ui,sans-serif;text-align:center}main{padding:24px;max-width:360px}h1{font-size:22px;margin:0 0 6px}a{display:block;margin-top:20px;padding:18px;border-radius:16px;background:#14412A;color:#fff;font-weight:800;font-size:19px;text-decoration:none}small{display:block;margin-top:14px;opacity:.8}</style>
<main><h1>Abrindo o WhatsApp da CallNet…</h1><p>Se não abrir sozinho, toque no botão.</p><a id="go" data-wa href="<?= h($link) ?>">Abrir o WhatsApp</a><small>Internet fibra Nio · parceiro credenciado</small></main>
<?= wa_js($wa, $msg) ?>
<script>
(function(){
  <?php if ($pixel !== ''): ?>
  try {
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init','<?= $pixel ?>'); fbq('track','PageView'); fbq('track','Contact',{content_name:'QR parceiro'});
  } catch (e) {}
  <?php endif; ?>
  setTimeout(window.abrirWA, <?= $pixel !== '' ? 600 : 150 ?>);
})();
</script></html>
