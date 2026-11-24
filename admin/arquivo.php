<?php
/* O admin (master) abre um comprovante anexado a um saque. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$a = admin_logado();
if (!$a || $a['papel'] !== 'master') { http_response_code(403); exit('Só o master abre comprovantes.'); }
$g = one('SELECT g.id, g.arquivo, p.codigo FROM saques g JOIN parceiros p ON p.id = g.parceiro_id WHERE g.id = ?', [(int)($_GET['id'] ?? 0)]);
enviar_comprovante($g['arquivo'] ?? null, 'comprovante-' . ($g['codigo'] ?? '') . '-saque-' . ($g['id'] ?? ''));
