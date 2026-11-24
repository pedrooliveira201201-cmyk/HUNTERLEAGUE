<?php
/* O parceiro abre o comprovante do PIX que a CallNet anexou ao saque. Só o dono do saque consegue. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
$p = parceiro_logado();
if (!$p) { http_response_code(403); exit('Entre no app.'); }
$g = one('SELECT id, arquivo FROM saques WHERE id = ? AND parceiro_id = ?', [(int)($_GET['s'] ?? 0), $p['id']]);
enviar_comprovante($g['arquivo'] ?? null, 'comprovante-' . $p['codigo'] . '-saque-' . ($g['id'] ?? ''));
