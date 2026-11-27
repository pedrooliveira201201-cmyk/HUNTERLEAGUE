/* Botão discreto "Instalar app no celular" (qualquer elemento com data-instalar).
   Android/Chrome: abre a janela de instalação do próprio sistema.
   iPhone/iPad: não existe essa janela, então mostra os 2 toques no Safari.
   Some quando o app já está instalado (aberto pela tela de início).
   Telas desenhadas depois (Central) chamam window.HLinstalar() de novo. */
(() => {
 const aberto = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
 const ua = navigator.userAgent;
 const ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
 const toque = matchMedia('(pointer: coarse)').matches;
 let pedido = null, instalado = false;
 const pode = () => !aberto && !instalado && (pedido || ios || toque);
 const mostra = () => document.querySelectorAll('[data-instalar]').forEach(b => { b.style.display = pode() ? 'inline-flex' : 'none'; });
 if ('serviceWorker' in navigator) { try { navigator.serviceWorker.register('/sw.js', { scope:'/' }).catch(() => {}); } catch (e) {} }
 addEventListener('beforeinstallprompt', e => { e.preventDefault(); pedido = e; mostra(); });
 addEventListener('appinstalled', () => { pedido = null; instalado = true; mostra(); });

 function dica() {
  const safari = ios && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua), ipad = ios && !/iPhone|iPod/.test(ua);
  const passos = ios
   ? (safari
     ? ['Toque no botão <b>Compartilhar</b> <span class="ic">⬆</span> na barra do Safari.', 'Role e toque em <b>Adicionar à Tela de Início</b>.', 'Toque em <b>Adicionar</b>. O ícone aparece junto dos seus apps.']
     : ['Toque no botão <b>Compartilhar</b> <span class="ic">⬆</span> do navegador.', 'Toque em <b>Adicionar à Tela de Início</b>.', 'Se não aparecer, abra este endereço no <b>Safari</b> e faça por lá.'])
   : ['Abra o menu <b>⋮</b> do navegador (canto de cima).', 'Toque em <b>Instalar app</b> ou <b>Adicionar à tela inicial</b>.', 'Confirme. O ícone aparece junto dos seus apps.'];
  const w = document.createElement('div');
  w.setAttribute('role', 'dialog'); w.setAttribute('aria-label', 'Instalar o app');
  w.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.55);font:600 15px/1.45 Montserrat,system-ui,sans-serif';
  w.innerHTML = `<div style="box-sizing:border-box;width:min(440px,100%);padding:22px 20px calc(20px + env(safe-area-inset-bottom));background:#0B1013;color:#EEF2F4;border-top:3px solid #19F7FF;border-radius:18px 18px 0 0;box-shadow:0 -12px 40px rgba(0,0,0,.6);text-align:left">
   <b style="display:block;font:900 24px/1 'Big Shoulders Display',Impact,sans-serif;text-transform:uppercase">Instalar no ${ios ? (ipad ? 'iPad' : 'iPhone') : 'celular'}</b>
   <p style="margin:6px 0 12px;color:#8A959C;font-size:13.5px;letter-spacing:0;text-transform:none">Fica com ícone na tela de início e abre direto, sem digitar o endereço.</p>
   <ol style="margin:0;padding:0;list-style:none;display:grid;gap:10px">${passos.map((t, i) => `<li style="display:grid;grid-template-columns:28px 1fr;gap:10px;align-items:start"><span style="display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#19F7FF;color:#050607;font-weight:900">${i + 1}</span><span>${t}</span></li>`).join('')}</ol>
   <button type="button" style="all:unset;box-sizing:border-box;display:block;width:100%;margin-top:18px;padding:13px;text-align:center;background:#15222A;color:#EEF2F4;font:800 15px Montserrat,system-ui,sans-serif;cursor:pointer;border-radius:10px">Entendi</button></div>`;
  w.querySelectorAll('.ic').forEach(x => { x.style.cssText = 'display:inline-grid;place-items:center;width:22px;height:22px;border:1.5px solid #19F7FF;border-radius:5px;color:#19F7FF;font-size:13px;vertical-align:-5px'; });
  w.addEventListener('click', e => { if (e.target === w || e.target.closest('button')) w.remove(); });
  document.body.appendChild(w); w.querySelector('button').focus();
 }

 async function clique(e) {
  e.preventDefault();
  if (pedido) { const p = pedido; pedido = null; p.prompt(); try { const r = await p.userChoice; if (r.outcome === 'accepted') instalado = true; } catch (x) {} mostra(); return; }
  dica();
 }
 window.HLinstalar = () => { document.querySelectorAll('[data-instalar]').forEach(b => { if (!b.dataset.ok) { b.dataset.ok = '1'; b.addEventListener('click', clique); } }); mostra(); };
 window.HLinstalar();
})();
