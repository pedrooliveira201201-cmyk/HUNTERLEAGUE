<?php
// Funções compartilhadas pelo recebimento das provas e pelo painel do recrutamento.
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
date_default_timezone_set('America/Sao_Paulo');

/** Pasta dos dados: fora da pasta pública quando possível, para não ser acessível nem apagada a cada publicação. */
function callnet_data_dir(): ?string {
    static $dir = false;
    if ($dir !== false) return $dir;
    $root = (string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    $candidates = [dirname($root) . '/callnet-recrutamento-dados', __DIR__ . '/_dados'];
    foreach ($candidates as $i => $d) {
        if (!is_dir("$d/provas") && !@mkdir("$d/provas", 0700, true)) continue;
        if (!is_writable("$d/provas")) continue;
        if ($i === 1) { // dentro da pasta pública: bloqueia acesso pela web
            @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
            @file_put_contents("$d/index.html", '');
        }
        $GLOBALS['CALLNET_STORE'] = $i === 0 ? 'privado' : 'interno';
        return $dir = $d;
    }
    return $dir = null;
}

function callnet_store_kind(): string { return (string)($GLOBALS['CALLNET_STORE'] ?? 'nenhum'); }

/** Chave anônima do visitante, só para limitar abusos (o IP em si não é guardado). */
function callnet_visitor_key(): string {
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $ip = $fwd !== '' ? trim(explode(',', $fwd)[0]) : (string)($_SERVER['REMOTE_ADDR'] ?? '0');
    return substr(hash('sha256', 'callnet|' . $ip), 0, 24);
}

/** Registra um evento e diz se o visitante ainda está dentro do limite da janela. */
function callnet_within_limit(string $dir, string $name, int $max, int $window, bool $record = true): bool {
    $file = "$dir/limite-$name.json";
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($data)) $data = [];
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn($t) => $t > $now - $window));
        if (!$data[$k]) unset($data[$k]);
    }
    $key = callnet_visitor_key();
    $ok = count($data[$key] ?? []) < $max;
    if ($ok && $record) $data[$key][] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN); fclose($fh);
    return $ok;
}

function callnet_password_record(): array {
    $dir = callnet_data_dir();
    if ($dir && is_file("$dir/senha.json")) {
        $j = json_decode((string)file_get_contents("$dir/senha.json"), true);
        if (isset($j['salt'], $j['hash'])) return $j;
    }
    return require __DIR__ . '/recrutamento-config.php';
}

function callnet_check_password(string $password): bool {
    $c = callnet_password_record();
    return hash_equals((string)$c['hash'], hash('sha256', $c['salt'] . $password));
}

function callnet_set_password(string $password): bool {
    $dir = callnet_data_dir();
    if (!$dir) return false;
    $salt = bin2hex(random_bytes(16));
    return (bool)file_put_contents("$dir/senha.json", json_encode(['salt' => $salt, 'hash' => hash('sha256', $salt . $password)]), LOCK_EX);
}

/** Provas guardadas, mais recentes primeiro. */
function callnet_list_provas(string $dir): array {
    $out = [];
    foreach (glob("$dir/provas/*.json") ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && isset($j['id'])) $out[] = $j;
    }
    usort($out, fn($a, $b) => strcmp((string)$b['id'], (string)$a['id']));
    return $out;
}
