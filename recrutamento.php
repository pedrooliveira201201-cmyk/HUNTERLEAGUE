<?php
// Painel do recrutamento: lista as provas recebidas. Acesso protegido por senha.
declare(strict_types=1);
require __DIR__ . '/recrutamento-lib.php';

session_name('callnet_rh');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

const ORDEM = ['nome', 'whatsapp', 'idade', 'bairro', 'escolaridade', 'estuda', 'curso', 'porqueNaoEstuda', 'situacao', 'experiencia', 'sobreVoce',
    'comoSoube', 'momento', 'chamou', 'externo', 'meta', 'objetivo', 'objetivoDetalhe', 'nivel', 'comoAjuda', 'vaiPorQue', 'redacao', 'objecao'];

$dir = callnet_data_dir();
$msg = '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrfOk = hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
$acao = (string)($_POST['acao'] ?? '');

if ($acao === 'entrar' && $dir) {
    if (!callnet_within_limit($dir, 'login', 8, 900, false)) {
        $msg = 'Muitas tentativas erradas. Aguarde 15 minutos e tente de novo.';
    } elseif (callnet_check_password((string)($_POST['senha'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        header('Location: recrutamento.php');
        exit;
    } else {
        callnet_within_limit($dir, 'login', 8, 900, true);
        usleep(800000);
        $msg = 'Senha incorreta.';
    }
}
$logado = !empty($_SESSION['ok']) && $dir;

if ($logado && $csrfOk) {
    if ($acao === 'sair') { session_destroy(); header('Location: recrutamento.php'); exit; }
    if ($acao === 'excluir') {
        $id = preg_replace('/[^0-9A-Za-z-]/', '', (string)($_POST['id'] ?? ''));
        if ($id !== '' && is_file("$dir/provas/$id.json")) unlink("$dir/provas/$id.json");
        header('Location: recrutamento.php?excluida=1');
        exit;
    }
    if ($acao === 'senha') {
        $nova = (string)($_POST['nova'] ?? '');
        if (mb_strlen($nova) < 10) $msg = 'A nova senha precisa ter pelo menos 10 caracteres.';
        elseif ($nova !== (string)($_POST['nova2'] ?? '')) $msg = 'As duas senhas digitadas não são iguais.';
        elseif (!callnet_check_password((string)($_POST['atual'] ?? ''))) $msg = 'A senha atual está incorreta.';
        else $msg = callnet_set_password($nova) ? 'Senha trocada com sucesso.' : 'Não foi possível trocar a senha.';
    }
}
if (isset($_GET['excluida'])) $msg = 'Prova excluída.';

$provas = $logado ? callnet_list_provas($dir) : [];
$rotulos = [];
foreach ($provas as $p) foreach ((array)($p['rotulos'] ?? []) as $k => $l) $rotulos[$k] = $rotulos[$k] ?? $l;
$campos = array_values(array_unique(array_merge(ORDEM, ...array_map(fn($p) => array_keys((array)$p['respostas']), $provas ?: [['respostas' => []]]))));

// planilha (CSV com ; para abrir direto no Excel em português)
if ($logado && isset($_GET['planilha'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="provas-callnet-' . date('Y-m-d') . '.csv"');
    $cell = function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'" . $v; // evita fórmulas no Excel
        return '"' . str_replace('"', '""', $v) . '"';
    };
    echo "\xEF\xBB\xBF";
    echo implode(';', array_map($cell, array_merge(['Recebido em'], array_map(fn($k) => $rotulos[$k] ?? $k, $campos)))) . "\r\n";
    foreach ($provas as $p) {
        $linha = [$p['recebido_em'] ?? ''];
        foreach ($campos as $k) $linha[] = $p['respostas'][$k] ?? '';
        echo implode(';', array_map($cell, $linha)) . "\r\n";
    }
    exit;
}

$semana = count(array_filter($provas, fn($p) => strcmp((string)$p['id'], date('Ymd', strtotime('-7 days'))) >= 0));
function zap(string $n): string { $d = preg_replace('/\D/', '', $n); if (strlen($d) <= 11) $d = '55' . $d; return 'https://wa.me/' . $d; }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Recrutamento · Callnet</title>
<link rel="icon" href="assets/logo-callnet-mark.png">
<style>
:root{--ink:#04070F;--panel:#0A1428;--panel-2:#0E1B35;--line:#1C2D52;--fg:#EEF4FF;--muted:#8EA3C6;--cyan:#00D6F5;--blue:#0084FF;--ok:#2BD48A;--bad:#FF6B7A;color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:var(--ink);color:var(--fg);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:0 max(16px,env(safe-area-inset-left,0px))}
.wrap{max-width:1100px;margin:0 auto;padding:24px 0 60px;display:flex;flex-direction:column;gap:20px}
header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
header img{height:34px}
h1{font-size:22px;margin:0}
.muted{color:var(--muted)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px}
.msg{padding:12px 14px;border-radius:10px;background:rgba(0,132,255,.12);border:1px solid var(--line)}
input[type=password],input[type=search]{width:100%;font:inherit;color:var(--fg);background:var(--panel-2);border:1px solid #2E4A80;border-radius:10px;padding:11px 12px}
input:focus{outline:2px solid var(--blue);outline-offset:1px}
button,.btn{font:600 14px/1 system-ui,sans-serif;cursor:pointer;border-radius:10px;padding:11px 16px;border:1px solid #2E4A80;background:transparent;color:var(--fg);text-decoration:none;display:inline-flex;align-items:center;gap:6px;min-height:40px}
.primary{border:0;background:linear-gradient(100deg,var(--cyan),var(--blue));color:#03101F}
.danger{border-color:rgba(255,107,122,.5);color:var(--bad)}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.toolbar input{max-width:320px}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:12px 16px;min-width:150px}
.stat b{display:block;font-size:26px;font-variant-numeric:tabular-nums}
.stat span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
details.prova{background:var(--panel);border:1px solid var(--line);border-radius:12px}
details.prova+details.prova{margin-top:10px}
details.prova summary{list-style:none;cursor:pointer;padding:14px 16px;display:grid;grid-template-columns:110px minmax(140px,1.4fr) 150px 60px minmax(120px,1fr) 120px;gap:12px;align-items:center}
details.prova summary::-webkit-details-marker{display:none}
details.prova[open] summary{border-bottom:1px solid var(--line)}
.nome{font-weight:600}
.tag{font-size:12px;color:var(--cyan)}
.corpo{padding:16px}
dl{display:grid;grid-template-columns:minmax(160px,300px) 1fr;gap:10px 18px;margin:0}
dt{color:var(--muted);font-size:13px}
dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
.acoes{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.head{display:grid;grid-template-columns:110px minmax(140px,1.4fr) 150px 60px minmax(120px,1fr) 120px;gap:12px;padding:0 16px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
form.inline{display:inline}
.login{max-width:380px;margin:12vh auto 0;display:flex;flex-direction:column;gap:14px}
@media (max-width:760px){details.prova summary,.head{grid-template-columns:1fr 1fr}.head{display:none}dl{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$dir): ?>
  <div class="card">O armazenamento das provas não está disponível no servidor. Fale com o responsável pelo site.</div>
<?php elseif (!$logado): ?>
  <form class="card login" method="post" autocomplete="off">
    <img src="assets/logo-callnet.png" alt="Callnet" style="width:220px">
    <h1>Painel do recrutamento</h1>
    <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
    <label for="senha" class="muted">Senha</label>
    <input id="senha" name="senha" type="password" required autofocus>
    <input type="hidden" name="acao" value="entrar">
    <button class="primary" type="submit">Entrar</button>
  </form>
<?php else: ?>
  <header>
    <img src="assets/logo-callnet.png" alt="Callnet">
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="sair"><button type="submit">Sair</button></form>
  </header>
  <h1>Provas do processo seletivo · Consultor de vendas</h1>
  <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
  <div class="stats">
    <div class="stat"><b><?= count($provas) ?></b><span>Provas recebidas</span></div>
    <div class="stat"><b><?= $semana ?></b><span>Últimos 7 dias</span></div>
  </div>
  <div class="toolbar">
    <input type="search" id="busca" placeholder="Buscar por nome, bairro, WhatsApp…" aria-label="Buscar">
    <a class="btn" href="recrutamento.php?planilha=1">Baixar planilha</a>
    <a class="btn" href="processo-seletivo.html" target="_blank" rel="noopener">Abrir a prova</a>
  </div>
  <?php if (!$provas): ?>
    <div class="card muted">Nenhuma prova recebida ainda.</div>
  <?php else: ?>
    <div class="head"><span>Recebida</span><span>Nome</span><span>WhatsApp</span><span>Idade</span><span>Experiência</span><span>Redação</span></div>
    <div id="lista">
    <?php foreach ($provas as $p): $r = (array)$p['respostas']; ?>
      <details class="prova">
        <summary>
          <span class="muted"><?= h($p['recebido_em'] ?? '') ?></span>
          <span><span class="nome"><?= h($r['nome'] ?? '') ?></span><br><span class="tag"><?= h($r['bairro'] ?? '') ?></span></span>
          <span><?= h($r['whatsapp'] ?? '') ?></span>
          <span><?= h($r['idade'] ?? '') ?></span>
          <span><?= h($r['experiencia'] ?? '') ?></span>
          <span class="muted"><?= mb_strlen($r['redacao'] ?? '') ?> caracteres</span>
        </summary>
        <div class="corpo">
          <dl>
          <?php foreach ($campos as $k): if (!isset($r[$k]) || $r[$k] === '') continue; ?>
            <dt><?= h($rotulos[$k] ?? $k) ?></dt><dd><?= h($r[$k]) ?></dd>
          <?php endforeach; ?>
          </dl>
          <div class="acoes">
            <?php if (!empty($r['whatsapp'])): ?><a class="btn primary" href="<?= h(zap((string)$r['whatsapp'])) ?>" target="_blank" rel="noopener">Chamar no WhatsApp</a><?php endif; ?>
            <form method="post" class="inline" onsubmit="return confirm('Excluir esta prova? Não dá para desfazer.')">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= h($p['id']) ?>">
              <button class="danger" type="submit">Excluir prova</button>
            </form>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <details class="card">
    <summary style="cursor:pointer">Trocar a senha do painel</summary>
    <form method="post" style="display:grid;gap:10px;max-width:380px;margin-top:14px" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="senha">
      <input type="password" name="atual" placeholder="Senha atual" required>
      <input type="password" name="nova" placeholder="Nova senha (mínimo 10 caracteres)" required minlength="10">
      <input type="password" name="nova2" placeholder="Repita a nova senha" required minlength="10">
      <button class="primary" type="submit">Trocar senha</button>
    </form>
  </details>
  <script>
  document.getElementById('busca')?.addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('#lista details').forEach(d => { d.hidden = q && !d.textContent.toLowerCase().includes(q); });
  });
  </script>
<?php endif; ?>
</div>
</body>
</html>
