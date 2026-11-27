<?php
/* Link de contatos do parceiro: hunterleague.com.br/contatos/ID-CHAVE
   1) o parceiro escolhe os contatos (Android: agenda do celular; iPhone: arquivo de contatos; ou colar a lista)
   2) envia: cada contato vira indicação dele (origem "lista"), sem teto, com a mesma conferência de duplicados
   3) avisa um por um: cada toque abre o WhatsApp DELE na conversa do contato, com a mensagem do roteiro pronta. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/admin/_lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');

$pid = (int)($_GET['p'] ?? 0); $k = (string)($_GET['k'] ?? '');
$p = $pid ? one('SELECT * FROM parceiros WHERE id = ?', [$pid]) : null;
$ok = $p && in_array($p['status'], ['ativo', 'pendente'], true) && hash_equals(contatos_chave($pid), $k);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$ok) json_out(['erro' => 'Link inválido. Peça um novo para a CallNet.'], 403);
  $e = entrada();
  if (empty($e['autorizou'])) json_out(['erro' => 'Confirme que essas pessoas sabem que a CallNet vai entrar em contato.'], 422);
  $itens = is_array($e['contatos'] ?? null) ? array_slice($e['contatos'], 0, 500) : [];
  $linhas = [];
  foreach ($itens as $c) {
    $nome = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', strip_tags((string)($c['nome'] ?? '')))), 0, 120);
    $tel = so_digitos((string)($c['tel'] ?? ''));
    if ($tel !== '') $linhas[] = ($nome !== '' ? $nome : 'Contato') . ' – ' . $tel;
  }
  if (!$linhas) json_out(['erro' => 'Escolha pelo menos um contato com telefone.'], 422);
  if (travado('cont:' . $pid, 12, 3600) > 0) json_out(['erro' => 'Muitos envios seguidos. Tente de novo daqui a pouco.'], 429);
  errou('cont:' . $pid);
  $r = lancar_lista($p, implode("\n", $linhas), true, 'parceiro:' . $pid);
  json_out(['ok' => true, 'entraram' => $r['ok'], 'total' => $r['total'],
    'itens' => array_map(fn($i) => ['nome' => $i['nome'], 'tel' => $i['tel'], 'st' => $i['st'], 'obs' => $i['obs']], $r['itens'])]);
}

$nome = $ok ? primeiro_nome($p['nome']) : '';
$loja = $ok ? ($p['loja'] ? strip_tags($p['loja']) : '') : '';
$boot = $ok ? ['p' => $pid, 'k' => $k, 'nome' => $nome, 'loja' => $loja, 'link' => $p['codigo'] ? link_parceiro((string)$p['codigo']) : '',
  'preco' => time() >= strtotime('2026-12-07 00:00:00') ? 70 : 55] : null;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#050505">
<meta name="robots" content="noindex">
<title>Enviar contatos · Hunter League</title>
<link rel="icon" type="image/png" href="/icons/favicon-64.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@800;900&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{color-scheme:dark;--bg:#050505;--cyan:#19F7FF;--cyan2:#AEFCFF;--silver:#BEC5CC;--ink:#EEF1F3;--mute:#8A939A;--edge:#2A3036;--field:#0C0E10;--ok:#32E000;--zap:#25D366;--red:#FF5C7A;--amb:#FFB547}
*{box-sizing:border-box;min-width:0}
body{margin:0;min-height:100vh;background:radial-gradient(90% 50% at 50% 0,#0C1518 0,#050505 70%) fixed,#050505;color:var(--ink);font:500 16px/1.5 Montserrat,system-ui,sans-serif;-webkit-font-smoothing:antialiased}
main{max-width:460px;margin:0 auto;padding:calc(18px + env(safe-area-inset-top)) 18px calc(30px + env(safe-area-inset-bottom))}
.top{display:flex;align-items:center;gap:10px}.top img{width:44px}.top small{color:var(--mute);font-weight:700;font-size:12.5px;letter-spacing:.1em;text-transform:uppercase}
h1{margin:18px 0 6px;font:900 38px/.95 "Big Shoulders Display",Impact,sans-serif;text-transform:uppercase}h1 span{color:var(--cyan)}
.lead{margin:0;color:var(--silver)}
.tela{display:none}.tela.on{display:block}
.bt{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:56px;margin-top:14px;padding:12px 16px;border:0;border-radius:14px;background:var(--cyan);color:#050505;font:900 21px "Big Shoulders Display",Impact,sans-serif;text-transform:uppercase;letter-spacing:.02em;cursor:pointer;text-decoration:none}
.bt.g{background:#131B1F;color:var(--ink);font:800 15px Montserrat,sans-serif;text-transform:none;letter-spacing:0;border:1px solid var(--edge)}
.bt.zap{background:var(--zap);color:#04210F}
.bt:disabled{opacity:.45}
.bt svg{width:24px;height:24px;flex:none}
.box{margin-top:16px;padding:14px;border:1px solid var(--edge);border-radius:14px;background:#0A0F11}
.box b{display:block}.box p{margin:6px 0 0;color:var(--silver);font-size:14.5px}
ol.passos{margin:8px 0 0;padding-left:20px;color:var(--silver);font-size:14.5px}ol.passos li{margin:4px 0}
textarea{width:100%;min-height:130px;margin-top:10px;padding:12px;border:1px solid var(--edge);border-radius:12px;background:var(--field);color:var(--ink);font:500 15px Montserrat,sans-serif}
.lista{list-style:none;margin:12px 0 0;padding:0;max-height:52vh;overflow:auto;border-top:1px solid var(--edge)}
.lista li{display:grid;grid-template-columns:26px 1fr auto;gap:10px;align-items:center;padding:10px 2px;border-bottom:1px solid #161D21}
.lista input{width:20px;height:20px;accent-color:var(--cyan)}
.lista b{display:block;font-size:15px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lista small{color:var(--mute);font-size:13px}
.lista em{font-style:normal;font-size:12px;font-weight:800;color:var(--amb)}
.aut{display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:start;margin-top:16px;font-size:14.5px;color:var(--silver);cursor:pointer}
.aut input{width:22px;height:22px;margin:1px 0 0;accent-color:var(--cyan)}
.cont{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px;color:var(--mute);font-weight:700;font-size:14px}.cont b{color:var(--ink);font-size:16px}
.err{margin-top:12px;padding:10px 12px;border-left:3px solid var(--red);background:rgba(255,92,122,.1);color:#FFC2CD;font-size:14px}
.versoes{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.versoes button{padding:7px 12px;border:1px solid var(--edge);border-radius:20px;background:#0E1417;color:var(--silver);font:700 13px Montserrat,sans-serif;cursor:pointer}
.versoes button[aria-pressed=true]{background:var(--cyan);color:#050505;border-color:var(--cyan)}
.previa{margin-top:10px;padding:12px 14px;border-radius:12px 12px 12px 2px;background:#0E2A1C;color:#DFF7E7;font-size:14.5px;white-space:pre-wrap}
.av li{grid-template-columns:1fr auto}
.av li.feito b{color:var(--mute)}
.av .mini{padding:8px 12px;border:0;border-radius:10px;background:var(--zap);color:#04210F;font:800 13px Montserrat,sans-serif;cursor:pointer;white-space:nowrap}
.av li.feito .mini{background:#16241B;color:var(--ok)}
.prog{height:8px;margin-top:12px;border-radius:4px;background:#16212A;overflow:hidden}.prog i{display:block;height:100%;width:0;background:var(--zap);transition:width .3s}
.fim{text-align:center;padding:16px 0 4px}.fim b{display:block;font:900 30px "Big Shoulders Display",Impact,sans-serif;text-transform:uppercase;color:var(--ok)}
.sub{color:var(--mute);font-size:13.5px}
</style>
</head>
<body>
<main>
 <div class="top"><img src="/img/crest-160.webp" alt="Hunter League"><small>Hunter League · CallNet</small></div>
<?php if (!$ok): ?>
 <h1>Link <span>inválido</span></h1>
 <p class="lead">Este link de contatos não está ativo. Peça um novo para a CallNet no WhatsApp <b>(34) 2028-1071</b>.</p>
<?php else: ?>
 <!-- 1) escolher -->
 <section class="tela on" id="t1">
  <h1>Envie seus <span>contatos</span></h1>
  <p class="lead">Oi, <?= h($nome) ?>! Escolha as pessoas que podem querer uma internet melhor: família, amigos, clientes. Pode mandar quantos quiser.</p>
  <button class="bt" type="button" id="b-agenda" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.5 3.3-5.5 6.5-5.5s5.9 2 6.5 5.5M17 8h5M19.5 5.5v5"/></svg>Escolher na agenda</button>
  <div class="box" id="ios" hidden><b>No iPhone</b>
   <ol class="passos"><li>Abra o app <b>Contatos</b> e selecione as pessoas (arraste dois dedos sobre a lista para marcar várias).</li><li>Toque e segure num dos marcados e escolha <b>Compartilhar</b> → <b>Salvar em Arquivos</b>.</li><li>Volte aqui e toque em <b>Escolher arquivo de contatos</b>.</li></ol></div>
  <label class="bt g" id="b-arq" style="cursor:pointer"><input type="file" id="arq" accept=".vcf,text/vcard,text/x-vcard,text/directory" multiple hidden>Escolher arquivo de contatos (.vcf)</label>
  <button class="bt g" type="button" id="b-colar">Colar uma lista</button>
  <div id="colar" hidden><textarea id="txt" placeholder="Maria Souza 34 99999-9999&#10;João Lima (34) 98888-7777"></textarea><button class="bt g" type="button" id="b-ler">Conferir a lista</button></div>
  <div id="e1"></div>
 </section>
 <!-- 2) conferir e enviar -->
 <section class="tela" id="t2">
  <h1>Confira e <span>envie</span></h1>
  <p class="lead">Desmarque quem não quiser indicar.</p>
  <div class="cont"><span>Marcados</span><b id="n-sel">0</b></div>
  <ul class="lista" id="sel"></ul>
  <label class="aut"><input type="checkbox" id="autorizo"><span>Confirmo que essas pessoas sabem que vão receber contato da CallNet sobre internet.</span></label>
  <button class="bt" type="button" id="b-enviar" disabled>Enviar contatos</button>
  <button class="bt g" type="button" id="b-mais">Escolher mais contatos</button>
  <div id="e2"></div>
 </section>
 <!-- 3) avisar um por um pelo WhatsApp dele -->
 <section class="tela" id="t3">
  <h1>Agora <span>avise</span> cada um</h1>
  <p class="lead" id="t3-lead"></p>
  <div class="versoes" id="versoes"></div>
  <div class="previa" id="previa"></div>
  <button class="bt zap" type="button" id="b-prox"></button>
  <div class="prog"><i id="prog"></i></div>
  <ul class="lista av" id="av"></ul>
  <div class="fim" id="fim" hidden><b>Todos avisados!</b><p class="sub">A CallNet vai ligar para cada um. Quando alguém instalar, a casa acende no seu app.</p></div>
  <a class="bt g" href="/app">Voltar para o app</a>
 </section>
<?php endif; ?>
</main>
<?php if ($ok): ?>
<script>
(() => {
 const B = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
 const $ = s => document.querySelector(s), h = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]);
 const tela = n => { document.querySelectorAll('.tela').forEach(t => t.classList.toggle('on', t.id === 't' + n)); scrollTo(0, 0); };
 const erro = (id, t) => { $(id).innerHTML = t ? `<div class="err">${h(t)}</div>` : ''; };
 const store = { get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} } };
 const CHAVE = 'hl_cont_' + B.p;

 /* telefone: só números, sem 55 e sem 0 da operadora; sem DDD → 34 (Araguari) */
 const fone = t => { let d = String(t || '').replace(/\D/g, ''); if (d.length >= 12 && d.startsWith('55')) d = d.slice(2); if (d.length >= 11 && d[0] === '0') d = d.replace(/^0+(\d{2})?/, ''); if (d.length === 8 || d.length === 9) d = '34' + d; if (d.length === 10 && /^[6-9]/.test(d[2])) d = d.slice(0, 2) + '9' + d.slice(2); return d.length === 10 || d.length === 11 ? d : ''; };
 const melhor = lista => { const t = lista.map(fone).filter(Boolean); return t.find(x => x.length === 11) || t[0] || ''; };
 const nomeBom = n => String(n || '').replace(/\s+/g, ' ').trim().slice(0, 120);
 let escolhidos = [];   /* [{nome, tel, on}] */
 const junta = novos => { const ja = new Set(escolhidos.map(x => x.tel)); for (const c of novos) if (c.tel && !ja.has(c.tel)) { ja.add(c.tel); escolhidos.push({ nome:c.nome, tel:c.tel, on:true }); } };
 const telBr = t => t.length === 11 ? `(${t.slice(0, 2)}) ${t.slice(2, 7)}-${t.slice(7)}` : `(${t.slice(0, 2)}) ${t.slice(2, 6)}-${t.slice(6)}`;

 /* 1) Android: agenda do celular (Chrome). iPhone e computador: arquivo de contatos ou colar */
 const temAgenda = 'contacts' in navigator && 'ContactsManager' in window;
 const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
 if (temAgenda) $('#b-agenda').hidden = false;
 if (ios) $('#ios').hidden = false;
 $('#b-agenda').addEventListener('click', async () => {
  erro('#e1');
  try { const r = await navigator.contacts.select(['name', 'tel'], { multiple:true });
   junta(r.map(c => ({ nome:nomeBom((c.name || [])[0]), tel:melhor(c.tel || []) })).filter(c => c.tel));
   if (!escolhidos.length) return erro('#e1', 'Nenhum dos contatos escolhidos tem celular. Tente de novo.');
   confere(); } catch (e) { erro('#e1', 'Não deu para abrir a agenda. Use o arquivo de contatos ou cole a lista.'); }
 });
 const vcards = txt => (txt.match(/BEGIN:VCARD[\s\S]*?END:VCARD/gi) || []).map(c => {
  const un = c.replace(/\r?\n[ \t]/g, '');
  const fn = (un.match(/^FN[^:]*:(.*)$/mi) || [])[1] || ((un.match(/^N[^:]*:(.*)$/mi) || [])[1] || '').split(';').filter(Boolean).reverse().join(' ');
  const tels = [...un.matchAll(/^(?:item\d+\.)?TEL[^:]*:(.*)$/gmi)].map(m => m[1]);
  return { nome:nomeBom(fn), tel:melhor(tels) }; }).filter(c => c.tel);
 $('#arq').addEventListener('change', async ev => {
  erro('#e1'); let n = 0;
  for (const f of ev.target.files) { const t = await f.text(); const l = vcards(t); n += l.length; junta(l); }
  ev.target.value = '';
  if (!n) return erro('#e1', 'Não achei contatos com telefone nesse arquivo.');
  confere();
 });
 $('#b-colar').addEventListener('click', () => { $('#colar').hidden = !$('#colar').hidden; if (!$('#colar').hidden) $('#txt').focus(); });
 $('#b-ler').addEventListener('click', () => {
  const l = []; let pend = '';
  for (const linha of $('#txt').value.split(/\n/)) { const s = linha.trim(); if (!s) continue;
   const m = s.match(/(\+?55[\s.-]*)?\(?\d{2}\)?[\s.-]*9?[\s.-]*\d{4}[\s.-]*\d{4}/) || s.match(/\b9?\d{4}[\s.-]?\d{4}\b/);   /* sem DDD: entra com 34 */
   if (m) { let n = nomeBom(s.replace(m[0], ' ').replace(/[–—:|,;-]+/g, ' ')); if (!n && pend) n = pend; pend = ''; const t = fone(m[0]); if (t) l.push({ nome:n, tel:t }); } else pend = nomeBom(s); }
  if (!l.length) return erro('#e1', 'Não achei telefones. Escreva um contato por linha: nome e telefone com DDD.');
  junta(l); $('#txt').value = ''; confere();
 });

 /* 2) conferir e enviar */
 const conta = () => { const n = escolhidos.filter(x => x.on).length; $('#n-sel').textContent = n; $('#b-enviar').disabled = !n || !$('#autorizo').checked; $('#b-enviar').textContent = n ? `Enviar ${n} ${n === 1 ? 'contato' : 'contatos'}` : 'Enviar contatos'; };
 function confere() {
  $('#sel').innerHTML = escolhidos.map((c, i) => `<li><input type="checkbox" data-i="${i}"${c.on ? ' checked' : ''} aria-label="Indicar ${h(c.nome || telBr(c.tel))}"><span><b>${h(c.nome || 'Sem nome')}</b><small>${telBr(c.tel)}</small></span><span></span></li>`).join('');
  conta(); tela(2);
 }
 $('#sel').addEventListener('change', ev => { const x = ev.target.closest('[data-i]'); if (x) { escolhidos[+x.dataset.i].on = x.checked; conta(); } });
 $('#autorizo').addEventListener('change', conta);
 $('#b-mais').addEventListener('click', () => tela(1));
 $('#b-enviar').addEventListener('click', async () => {
  const b = $('#b-enviar'); b.disabled = true; b.textContent = 'Enviando…'; erro('#e2');
  let r; try { r = await (await fetch(location.pathname, { method:'POST', headers:{ 'Content-Type':'application/json' }, body:JSON.stringify({ autorizou:true, contatos:escolhidos.filter(x => x.on).map(x => ({ nome:x.nome, tel:x.tel })) }) })).json(); }
  catch (e) { r = { erro:'Sem conexão. Confira a internet e tente de novo.' }; }
  if (r.erro) { conta(); return erro('#e2', r.erro); }
  const entra = r.itens.filter(i => i.st === 'ok').map(i => ({ nome:i.nome === 'Contato' ? '' : i.nome, tel:i.tel }));
  const fora = r.itens.filter(i => i.st !== 'ok');
  store.set(CHAVE, { lista:entra, feitos:[], fora:fora.length, v:'oficial' });
  avisar();
 });

 /* 3) avisar um por um: abre o WhatsApp do parceiro na conversa do contato, com a mensagem do roteiro */
 const V = {
  oficial:['Oficial', n => `Oi${n ? ', ' + n : ''}! Passei seu contato pro pessoal da CALLNET, parceira da Nio Fibra. Eles vão te ligar pra mostrar a internet. Atende, que é coisa boa!`],
  curta:['Curta', n => `Fala${n ? ', ' + n : ''}! Fechei uma parceria nova aqui no meu negócio e passei seu contato pra eles te apresentarem um serviço. Escuta eles, é coisa boa`],
  oferta:['Com a oferta', n => `Oi${n ? ', ' + n : ''}! Tô indicando uma internet de fibra aqui em Araguari, a partir de R$ ${B.preco} e com preço garantido em contrato até 2030. Passei seu número pro pessoal te chamar.` + (B.link ? `\n\nSe quiser já falar com eles: ${B.link}` : '')],
  atender:['Pedindo para atender', n => `${n ? n + ', p' : 'P'}assei seu contato pro pessoal da CALLNET, são meus parceiros. Eles vão te chamar no WhatsApp, atende que é coisa boa. Qualquer coisa me fala.`] };
 const primeiro = n => { const p = String(n || '').trim().split(/\s+/)[0] || ''; return /^[\p{L}'-]{2,}$/u.test(p) ? p[0].toUpperCase() + p.slice(1).toLowerCase() : ''; };
 const abreZap = (tel, txt) => {
  const d = '55' + tel, q = 'phone=' + d + '&text=' + encodeURIComponent(txt), web = 'https://wa.me/' + d + '?text=' + encodeURIComponent(txt), ua = navigator.userAgent;
  if (/Android/i.test(ua)) location.href = 'intent://send/?' + q + '#Intent;scheme=whatsapp;S.browser_fallback_url=' + encodeURIComponent(web) + ';end';
  else if (ios) { const t = Date.now(); location.href = 'whatsapp://send?' + q; setTimeout(() => { if (!document.hidden && Date.now() - t < 2500) location.href = web; }, 1600); }
  else window.open(web, '_blank', 'noopener');
 };
 function avisar() {
  const S = store.get(CHAVE); if (!S || !S.lista) return tela(1);
  tela(3);
  const L = S.lista, feitos = new Set(S.feitos || []), prox = L.findIndex((c, i) => !feitos.has(i));
  $('#t3-lead').textContent = L.length
   ? `${L.length} ${L.length === 1 ? 'contato entrou' : 'contatos entraram'} como suas indicações.${S.fora ? ` ${S.fora} já tinham sido indicados ou estavam incompletos.` : ''} Toque para mandar a mensagem pelo seu WhatsApp.`
   : 'Esses contatos já tinham sido indicados antes. Escolha outros para indicar.';
  $('#versoes').innerHTML = Object.entries(V).map(([k, [t]]) => `<button type="button" data-v="${k}" aria-pressed="${S.v === k}">${t}</button>`).join('');
  const msg = c => V[S.v || 'oficial'][1](primeiro(c.nome));
  $('#previa').textContent = msg(L[prox >= 0 ? prox : 0] || { nome:'Maria' });
  $('#av').innerHTML = L.map((c, i) => `<li class="${feitos.has(i) ? 'feito' : ''}"><span><b>${h(c.nome || 'Sem nome')}</b><small>${telBr(c.tel)}</small></span><button class="mini" type="button" data-a="${i}">${feitos.has(i) ? '✓ Avisado' : 'Avisar'}</button></li>`).join('');
  $('#prog').style.width = (L.length ? feitos.size / L.length * 100 : 0) + '%';
  const b = $('#b-prox');
  if (prox >= 0) { b.hidden = false; b.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z"/></svg>Avisar ${h(primeiro(L[prox].nome) || telBr(L[prox].tel))} (${feitos.size + 1} de ${L.length})`; b.dataset.a = prox; }
  else b.hidden = true;
  $('#fim').hidden = !(L.length && prox < 0);
 }
 const marca = i => { const S = store.get(CHAVE); if (!S) return; const f = new Set(S.feitos || []); f.add(i); S.feitos = [...f]; store.set(CHAVE, S); };
 document.addEventListener('click', ev => {
  const a = ev.target.closest('[data-a]'); if (a) { const S = store.get(CHAVE), i = +a.dataset.a, c = S.lista[i]; marca(i); abreZap(c.tel, V[S.v || 'oficial'][1](primeiro(c.nome))); setTimeout(avisar, 400); return; }
  const v = ev.target.closest('[data-v]'); if (v) { const S = store.get(CHAVE); S.v = v.dataset.v; store.set(CHAVE, S); avisar(); }
 });
 document.addEventListener('visibilitychange', () => { if (!document.hidden && $('#t3').classList.contains('on')) avisar(); });
 /* voltou ao link depois: continua de onde parou */
 const S0 = store.get(CHAVE); if (S0 && S0.lista && S0.lista.length && (S0.feitos || []).length < S0.lista.length) avisar();
})();
</script>
<?php endif; ?>
</body>
</html>
