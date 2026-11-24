<?php
/* Gerador de QR Code (modo byte, correção de erro nível M, versões 1 a 10).
   Sem biblioteca externa: segue a norma ISO/IEC 18004. Devolve a matriz ou um SVG. */
declare(strict_types=1);

final class HLQR {
  private const ECC_POR_BLOCO = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];  // nível M
  private const BLOCOS        = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
  private int $v; private int $n; private array $m = []; private array $f = [];

  public static function matriz(string $texto): array {
    $q = new self();
    return $q->gerar($texto);
  }

  public static function svg(string $texto, string $cor = '#14412A', int $borda = 4): string {
    $m = self::matriz($texto); $n = count($m); $t = $n + 2 * $borda; $r = '';
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) if ($m[$y][$x]) $r .= '<rect x="' . ($x + $borda) . '" y="' . ($y + $borda) . '" width="1" height="1"/>';
    return '<svg class="qrsvg" viewBox="0 0 ' . $t . ' ' . $t . '" shape-rendering="crispEdges" role="img" aria-label="QR Code do parceiro"><rect width="100%" height="100%" fill="#fff"/><g fill="' . $cor . '">' . $r . '</g></svg>';
  }

  private static function bruto(int $v): int {
    $r = (16 * $v + 128) * $v + 64;
    if ($v >= 2) { $na = intdiv($v, 7) + 2; $r -= (25 * $na - 10) * $na - 55; if ($v >= 7) $r -= 36; }
    return $r;
  }
  private static function dados(int $v): int { return intdiv(self::bruto($v), 8) - self::ECC_POR_BLOCO[$v] * self::BLOCOS[$v]; }

  private function gerar(string $texto): array {
    $bytes = array_values(unpack('C*', $texto) ?: []);
    $len = count($bytes);
    for ($v = 1; $v <= 10; $v++) {
      $cc = $v <= 9 ? 8 : 16;
      if (4 + $cc + 8 * $len <= self::dados($v) * 8) break;
    }
    if ($v > 10) throw new RuntimeException('Texto grande demais para o QR.');
    $this->v = $v; $this->n = 4 * $v + 17;
    // bits: modo byte, tamanho, dados, terminador, enchimento
    $bits = [];
    $put = function (int $val, int $qt) use (&$bits) { for ($i = $qt - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
    $put(4, 4); $put($len, $v <= 9 ? 8 : 16); foreach ($bytes as $b) $put($b, 8);
    $cap = self::dados($v) * 8;
    $put(0, min(4, $cap - count($bits)));
    $put(0, (8 - count($bits) % 8) % 8);
    for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $put($pad, 8);
    $cw = [];
    for ($i = 0; $i < count($bits); $i += 8) { $b = 0; for ($j = 0; $j < 8; $j++) $b = ($b << 1) | $bits[$i + $j]; $cw[] = $b; }
    $final = $this->eccIntercalar($cw);

    $n = $this->n;
    $this->m = array_fill(0, $n, array_fill(0, $n, false));
    $this->f = array_fill(0, $n, array_fill(0, $n, false));
    $this->padroes();
    $this->desenharDados($final);
    // escolhe a máscara de menor penalidade
    $melhor = 0; $pen = PHP_INT_MAX; $base = $this->m;
    for ($k = 0; $k < 8; $k++) {
      $this->m = $base; $this->mascara($k); $this->formato($k);
      $p = $this->penalidade();
      if ($p < $pen) { $pen = $p; $melhor = $k; }
    }
    $this->m = $base; $this->mascara($melhor); $this->formato($melhor);
    return $this->m;
  }

  private static function mul(int $x, int $y): int {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF; $z ^= (($y >> $i) & 1) * $x; }
    return $z;
  }
  private static function divisor(int $g): array {
    $r = array_fill(0, $g, 0); $r[$g - 1] = 1; $root = 1;
    for ($i = 0; $i < $g; $i++) {
      for ($j = 0; $j < $g; $j++) { $r[$j] = self::mul($r[$j], $root); if ($j + 1 < $g) $r[$j] ^= $r[$j + 1]; }
      $root = self::mul($root, 0x02);
    }
    return $r;
  }
  private static function resto(array $dados, array $div): array {
    $r = array_fill(0, count($div), 0);
    foreach ($dados as $b) {
      $fator = $b ^ array_shift($r); $r[] = 0;
      foreach ($div as $i => $c) $r[$i] ^= self::mul($c, $fator);
    }
    return $r;
  }
  private function eccIntercalar(array $dados): array {
    $v = $this->v; $nb = self::BLOCOS[$v]; $ecl = self::ECC_POR_BLOCO[$v];
    $raw = intdiv(self::bruto($v), 8); $curtos = $nb - $raw % $nb; $tamCurto = intdiv($raw, $nb);
    $div = self::divisor($ecl); $blocos = []; $k = 0;
    for ($i = 0; $i < $nb; $i++) {
      $t = $tamCurto - $ecl + ($i < $curtos ? 0 : 1);
      $d = array_slice($dados, $k, $t); $k += $t;
      $e = self::resto($d, $div);
      if ($i < $curtos) $d[] = 0;
      $blocos[] = array_merge($d, $e);
    }
    $out = [];
    for ($i = 0; $i < count($blocos[0]); $i++)
      foreach ($blocos as $j => $b)
        if ($i !== $tamCurto - $ecl || $j >= $curtos) $out[] = $b[$i];
    return $out;
  }

  private function por(int $x, int $y, bool $escuro): void { $this->m[$y][$x] = $escuro; $this->f[$y][$x] = true; }
  private function alinhamentos(): array {
    $v = $this->v; if ($v === 1) return [];
    $na = intdiv($v, 7) + 2; $passo = (int)(ceil(($v * 4 + 4) / ($na * 2 - 2)) * 2);
    $r = [6];
    for ($p = $this->n - 7; count($r) < $na; $p -= $passo) array_splice($r, 1, 0, [$p]);
    return $r;
  }
  private function padroes(): void {
    $n = $this->n;
    for ($i = 0; $i < $n; $i++) { $this->por(6, $i, $i % 2 === 0); $this->por($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy])
      for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
        $d = max(abs($dx), abs($dy)); $x = $cx + $dx; $y = $cy + $dy;
        if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) $this->por($x, $y, $d !== 2 && $d !== 4);
      }
    $al = $this->alinhamentos(); $na = count($al);
    for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
      if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
      for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->por($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    $this->formato(0);
    if ($this->v >= 7) {
      $rem = $this->v; for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
      $bits = ($this->v << 12) | $rem;
      for ($i = 0; $i < 18; $i++) { $b = (($bits >> $i) & 1) === 1; $a = $n - 11 + $i % 3; $c = intdiv($i, 3); $this->por($a, $c, $b); $this->por($c, $a, $b); }
    }
  }
  private function formato(int $mask): void {
    $dado = (0 << 3) | $mask;  // nível M = 00
    $rem = $dado; for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    $bits = (($dado << 10) | $rem) ^ 0x5412; $n = $this->n;
    $b = fn($i) => (($bits >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) $this->por(8, $i, $b($i));
    $this->por(8, 7, $b(6)); $this->por(8, 8, $b(7)); $this->por(7, 8, $b(8));
    for ($i = 9; $i < 15; $i++) $this->por(14 - $i, 8, $b($i));
    for ($i = 0; $i < 8; $i++) $this->por($n - 1 - $i, 8, $b($i));
    for ($i = 8; $i < 15; $i++) $this->por(8, $n - 15 + $i, $b($i));
    $this->por(8, $n - 8, true);
  }
  private function desenharDados(array $d): void {
    $n = $this->n; $i = 0; $tot = count($d) * 8;
    for ($dir = $n - 1; $dir >= 1; $dir -= 2) {
      if ($dir === 6) $dir = 5;
      for ($vert = 0; $vert < $n; $vert++) for ($j = 0; $j < 2; $j++) {
        $x = $dir - $j; $sobe = (($dir + 1) & 2) === 0; $y = $sobe ? $n - 1 - $vert : $vert;
        if (!$this->f[$y][$x] && $i < $tot) { $this->m[$y][$x] = (($d[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
      }
    }
  }
  private function mascara(int $k): void {
    $n = $this->n;
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
      if ($this->f[$y][$x]) continue;
      switch ($k) {
        case 0: $inv = ($x + $y) % 2 === 0; break;
        case 1: $inv = $y % 2 === 0; break;
        case 2: $inv = $x % 3 === 0; break;
        case 3: $inv = ($x + $y) % 3 === 0; break;
        case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
        case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
        case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
        default: $inv = (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
      }
      if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
    }
  }
  private function penalidade(): int {
    $n = $this->n; $m = $this->m; $p = 0; $escuros = 0;
    $linhas = [];
    for ($y = 0; $y < $n; $y++) { $l = ''; $c = ''; for ($x = 0; $x < $n; $x++) { $l .= $m[$y][$x] ? '1' : '0'; $c .= $m[$x][$y] ? '1' : '0'; } $linhas[] = $l; $linhas[] = $c; $escuros += substr_count($l, '1'); }
    foreach ($linhas as $l) {
      if (preg_match_all('/0{5,}|1{5,}/', $l, $mm)) foreach ($mm[0] as $s) $p += 3 + strlen($s) - 5;
      $p += 40 * (preg_match_all('/(?=00001011101)|(?=10111010000)/', $l));
    }
    for ($y = 0; $y < $n - 1; $y++) for ($x = 0; $x < $n - 1; $x++) {
      $c = $m[$y][$x]; if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $p += 3;
    }
    $tot = $n * $n; $k = (int)ceil(abs($escuros * 20 - $tot * 10) / $tot) - 1;
    return $p + max(0, $k) * 10;
  }
}
