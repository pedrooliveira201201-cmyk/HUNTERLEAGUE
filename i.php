<?php
/* QR do parceiro: hunterleague.com.br/i/CODIGO
   Registra a leitura e leva o cliente direto ao WhatsApp da CallNet com o código do parceiro. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';

$codigo = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['c'] ?? '')));
$p = $codigo !== '' ? one("SELECT id, codigo, status FROM parceiros WHERE codigo = ?", [$codigo]) : null;
$wa = so_digitos(conf('whatsapp', '553420281071'));
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (!$p || $p['status'] !== 'ativo') {
  $link = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Olá! Quero saber da internet Nio Fibra.');
  http_response_code(404);
  ?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Internet fibra · CallNet</title>
  <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F0F5EB;color:#14412A;font:17px/1.45 system-ui,sans-serif;text-align:center}main{padding:24px;max-width:360px}a{display:block;margin-top:18px;padding:16px;border-radius:14px;background:#32E000;color:#14412A;font-weight:800;text-decoration:none}</style>
  <main><h1 style="font-size:24px">Este código não está ativo</h1><p>Mas você ainda pode falar com a CallNet pelo WhatsApp.</p><a href="<?= h($link) ?>">Falar com a CallNet</a></main></html><?php
  exit;
}

$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
$bot = (bool)preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp/i', $ua);
if (!$bot) q('INSERT INTO leituras (parceiro_id, em, ip_hash, agente) VALUES (?,?,?,?)', [$p['id'], agora(), ip_hash(), $ua]);

$msg = str_replace('{codigo}', $p['codigo'], conf('mensagem_wa', 'Olá! Quero saber da internet Nio Fibra. Código do parceiro: {codigo}'));
$link = 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg);
$pixel = preg_replace('/\D/', '', (string)conf('pixel_meta', ''));

if ($pixel === '') { header('Location: ' . $link, true, 302); exit; }
/* Página do pixel: registra o evento e abre o WhatsApp. Alguns navegadores (Safari do iPhone, navegador
   interno do Instagram e do Facebook) bloqueiam a abertura automática, então o botão fica sempre visível. */
?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="3;url=<?= h($link) ?>"><title>Abrindo o WhatsApp…</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#32E000;color:#14412A;font:17px/1.45 system-ui,sans-serif;text-align:center}main{padding:24px;max-width:360px}h1{font-size:22px;margin:0 0 6px}a{display:block;margin-top:20px;padding:18px;border-radius:16px;background:#14412A;color:#fff;font-weight:800;font-size:19px;text-decoration:none}small{display:block;margin-top:14px;opacity:.8}</style>
<main><h1>Abrindo o WhatsApp da CallNet…</h1><p>Se não abrir sozinho, toque no botão.</p><a id="go" href="<?= h($link) ?>">Abrir o WhatsApp</a><small>Internet fibra Nio · parceiro credenciado</small></main>
<script>
(function(){
  var link = <?= json_encode($link) ?>, foi = false;
  function abrir(){ if (foi) return; foi = true; location.href = link; }
  try {
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init','<?= $pixel ?>'); fbq('track','PageView'); fbq('track','Contact',{content_name:'QR parceiro'});
  } catch (e) {}
  setTimeout(abrir, 600);
})();
</script></html>
