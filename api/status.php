<?php
/* Situação da conta para a tela de espera do app: em análise → aprovado (falta ativar o QR) → ativo.
   A tela consulta de tempos em tempos e muda sozinha quando a CallNet aprova. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
header('Cache-Control: no-store');
$p = parceiro_sessao();
if (!$p) json_out(['erro' => 'Entre de novo.'], 401);
json_out(['ok' => true, 'estado' => $p['status'] === 'pendente' ? 'analise' : (!$p['codigo'] ? 'ativar' : 'ativo'), 'codigo' => $p['codigo']]);
