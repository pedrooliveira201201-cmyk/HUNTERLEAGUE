<?php
// Recebe a prova do processo seletivo (processo-seletivo.html) e guarda no servidor.
declare(strict_types=1);
require __DIR__ . '/recrutamento-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function reply(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

$dir = callnet_data_dir();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) reply($dir ? 200 : 500, ['ok' => (bool)$dir, 'armazenamento' => callnet_store_kind()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok' => false, 'erro' => 'metodo']);
if (!$dir) reply(500, ['ok' => false, 'erro' => 'armazenamento']);

$raw = file_get_contents('php://input', false, null, 0, 250000);
if (!is_string($raw) || strlen($raw) >= 250000) reply(413, ['ok' => false, 'erro' => 'tamanho']);
$in = json_decode($raw, true);
if (!is_array($in) || !is_array($in['respostas'] ?? null)) reply(400, ['ok' => false, 'erro' => 'formato']);

// guarda só textos e listas de textos, com limite de tamanho
$respostas = [];
foreach ($in['respostas'] as $k => $v) {
    if (!is_string($k) || !preg_match('/^[A-Za-z0-9_]{1,40}$/', $k)) continue;
    if (is_array($v)) $v = implode(', ', array_map(fn($x) => mb_substr((string)$x, 0, 300), array_slice(array_filter($v, 'is_scalar'), 0, 20)));
    elseif (is_scalar($v)) $v = (string)$v;
    else continue;
    $respostas[$k] = mb_substr(trim($v), 0, 20000);
}
$rotulos = [];
foreach ((array)($in['rotulos'] ?? []) as $k => $l) if (isset($respostas[$k]) && is_string($l)) $rotulos[$k] = mb_substr($l, 0, 400);

if (mb_strlen($respostas['nome'] ?? '') < 2 || strlen(preg_replace('/\D/', '', $respostas['whatsapp'] ?? '')) < 10) reply(422, ['ok' => false, 'erro' => 'campos']);
// generoso de propósito: candidatos podem fazer a prova juntos, na mesma rede
if (!callnet_within_limit($dir, 'envio', 40, 3600)) reply(429, ['ok' => false, 'erro' => 'limite']);

$id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$registro = [
    'id' => $id,
    'recebido_em' => date('d/m/Y H:i'),
    'vaga' => 'Consultor de vendas',
    'respostas' => $respostas,
    'rotulos' => $rotulos,
];
$ok = file_put_contents("$dir/provas/$id.json", json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
if (!$ok) reply(500, ['ok' => false, 'erro' => 'gravacao']);
reply(200, ['ok' => true, 'id' => $id]);
