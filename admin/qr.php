<?php
/* QR do parceiro em SVG, para o admin baixar e imprimir. */
declare(strict_types=1);
require __DIR__ . '/../api/_boot.php';
require __DIR__ . '/../api/_qr.php';
sessao();
if (empty($_SESSION['admin_id']) || !one('SELECT id FROM admins WHERE id = ? AND ativo = 1', [$_SESSION['admin_id']])) { http_response_code(403); exit('Entre no admin.'); }
/* ?id= QR de um parceiro · ?c= QR genérico do adesivo A3 */
$c = strtoupper((string)preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['c'] ?? '')));
if ($c !== '' && qr_generico($c)) $codigo = $c;
else {
  $p = one('SELECT codigo FROM parceiros WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
  if (!$p || !$p['codigo']) { http_response_code(404); exit; }
  $codigo = $p['codigo'];
}
header('Content-Type: image/svg+xml');
header('Content-Disposition: inline; filename="qr-' . $codigo . '.svg"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', HLQR::svg(link_parceiro($codigo)));
