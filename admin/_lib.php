<?php
/* Funções comuns do admin (login, CSRF, formatos, regras). */
declare(strict_types=1);
require_once __DIR__ . '/../api/_boot.php';
require_once __DIR__ . '/../api/_dados.php';

function admin_logado(): ?array {
  sessao();
  $id = $_SESSION['admin_id'] ?? null;
  if (!$id) return null;
  if (time() - ($_SESSION['admin_visto'] ?? 0) > 12 * 3600) { unset($_SESSION['admin_id']); return null; }
  $_SESSION['admin_visto'] = time();
  return one('SELECT * FROM admins WHERE id = ? AND ativo = 1', [$id]);
}
function token_admin(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function data_br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function dh_br(?string $d): string { return $d ? date('d/m H:i', strtotime($d)) : ''; }
function tel_br(?string $t): string { $t = (string)$t; return strlen($t) === 11 ? sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 5), substr($t, 7)) : (strlen($t) === 10 ? sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 4), substr($t, 6)) : $t); }
function doc_br(?string $d): string { $d = (string)$d; return strlen($d) === 11 ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d) : preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d); }
function novo_codigo(string $cidade): string {
  $cidade = nome_cidade($cidade);
  $mapa = ['Araguari' => 'ARG', 'Uberlândia' => 'UDI', 'Uberaba' => 'UBA'];
  $pre = $mapa[$cidade] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $cidade) ?: 'HLG'), 0, 3));
  if (strlen($pre) < 3) $pre = str_pad($pre, 3, 'X');
  $n = (int)(one("SELECT COUNT(*) AS n FROM parceiros WHERE codigo LIKE ?", ["HL-{$pre}-%"])['n'] ?? 0);
  do { $n++; $c = sprintf('HL-%s-%04d', $pre, $n); } while (one('SELECT id FROM parceiros WHERE codigo = ?', [$c]));
  return $c;
}
function duplicado(string $cpf, string $tel, int $exceto = 0): ?array { return indicacao_valida($cpf, $tel, $exceto); }
