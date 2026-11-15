<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Kleiner QR-Code-Erzeuger (Byte-Modus, Fehlerkorrektur M, Version 1 bis 10), ohne Bibliothek.
 * Gibt ein SVG mit viewBox und ohne feste Breite/Höhe zurück; Farben als Attribute (CSP: kein style).
 */
final class Qr
{
    /** Version => [Gesamtcodewörter, EC je Block, Blöcke Gruppe 1, Daten je Block G1, Blöcke G2, Daten je Block G2] */
    private const VER = [
        1 => [26, 10, 1, 16, 0, 0], 2 => [44, 16, 1, 28, 0, 0], 3 => [70, 26, 1, 44, 0, 0],
        4 => [100, 18, 2, 32, 0, 0], 5 => [134, 24, 2, 43, 0, 0], 6 => [172, 16, 4, 27, 0, 0],
        7 => [196, 18, 4, 31, 0, 0], 8 => [242, 22, 2, 38, 2, 39], 9 => [292, 22, 3, 36, 2, 37],
        10 => [346, 26, 4, 43, 1, 44],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var list<int> */
    private static array $exp = [];
    /** @var list<int> */
    private static array $log = [];

    /** @return list<list<bool>> Modulmatrix (true = dunkel) ohne Ruhezone */
    public static function matrix(string $data): array
    {
        $len = strlen($data);
        $version = 0;
        foreach (self::VER as $v => $t) {
            $capacityBytes = $t[2] * $t[3] + $t[4] * $t[5];
            $countBits = $v <= 9 ? 8 : 16;
            if (intdiv(4 + $countBits + 8 * $len + 7, 8) <= $capacityBytes) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('Text zu lang für den QR-Code');
        }
        [$total, $ecLen, $b1, $d1, $b2, $d2] = self::VER[$version];
        $dataCw = $b1 * $d1 + $b2 * $d2;

        // Bitstrom: Modus 0100, Länge, Daten, Abschluss, Füllbytes
        $bits = '0100' . str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $cw = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($cw) < $dataCw; $pad++) {
            $cw[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        // Blöcke und Fehlerkorrektur
        self::initGf();
        $gen = self::generator($ecLen);
        $blocks = [];
        $ecBlocks = [];
        $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $blk = array_slice($cw, $pos, $size);
                $pos += $size;
                $blocks[] = $blk;
                $ecBlocks[] = self::remainder($blk, $gen);
            }
        }
        $final = [];
        $maxData = max($d1, $d2);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $blk) {
                if (isset($blk[$i])) {
                    $final[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $blk) {
                $final[] = $blk[$i];
            }
        }
        $stream = '';
        foreach ($final as $b) {
            $stream .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        $size = 17 + 4 * $version;
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            [$m, $reserved] = self::base($version, $size);
            self::place($m, $reserved, $stream, $size, $mask);
            self::formatInfo($m, $size, $mask);
            $score = self::penalty($m, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $m;
            }
        }
        return array_map(static fn (array $row): array => array_map(static fn ($v): bool => $v === 1, $row), $best);
    }

    public static function svg(string $data, string $label = 'QR-Code'): string
    {
        $m = self::matrix($data);
        $n = count($m);
        $q = 4;
        $d = '';
        foreach ($m as $y => $row) {
            $x = 0;
            while ($x < $n) {
                if ($row[$x]) {
                    $start = $x;
                    while ($x < $n && $row[$x]) {
                        $x++;
                    }
                    $d .= 'M' . ($start + $q) . ' ' . ($y + $q) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $t = $n + 2 * $q;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" shape-rendering="crispEdges">'
            . '<rect width="' . $t . '" height="' . $t . '" fill="#ffffff"/><path fill="#000000" d="' . $d . '"/></svg>';
    }

    // --- Galois-Feld GF(256), Polynom 0x11D ---

    private static function initGf(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return list<int> Generatorpolynom (ohne führenden 1er-Koeffizienten) */
    private static function generator(int $degree): array
    {
        $g = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= self::mul($c, self::$exp[$i]);
            }
            $g = $next;
        }
        return array_slice($g, 1);
    }

    /** @param list<int> $data @param list<int> $gen @return list<int> */
    private static function remainder(array $data, array $gen): array
    {
        $rem = array_fill(0, count($gen), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($rem);
            $rem[] = 0;
            if ($f !== 0) {
                foreach ($gen as $i => $c) {
                    $rem[$i] ^= self::mul($c, $f);
                }
            }
        }
        return $rem;
    }

    // --- Matrix ---

    /** @return array{0:list<list<int>>,1:list<list<bool>>} */
    private static function base(int $version, int $size): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $r = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, int $v) use (&$m, &$r, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $m[$y][$x] = $v;
                $r[$y][$x] = true;
            }
        };
        // Suchmuster mit Trennstreifen
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $inside = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                    $v = 0;
                    if ($inside) {
                        $edge = $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6;
                        $core = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                        $v = ($edge || $core) ? 1 : 0;
                    }
                    $set($ox + $dx, $oy + $dy, $v);
                }
            }
        }
        // Taktmuster
        for ($i = 8; $i < $size - 8; $i++) {
            $set($i, 6, $i % 2 === 0 ? 1 : 0);
            $set(6, $i, $i % 2 === 0 ? 1 : 0);
        }
        // Ausrichtungsmuster
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $iy => $cy) {
            foreach ($pos as $ix => $cx) {
                if (($ix === 0 && $iy === 0) || ($ix === 0 && $iy === $last) || ($ix === $last && $iy === 0)) {
                    continue; // überlappt ein Suchmuster
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, (max(abs($dx), abs($dy)) !== 1) ? 1 : 0);
                    }
                }
            }
        }
        // Formatbereich reservieren
        for ($i = 0; $i < 9; $i++) {
            $set($i, 8, 0);
            $set(8, $i, 0);
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, 0);
            $set(8, $size - 1 - $i, 0);
        }
        $set(8, $size - 8, 1); // dunkles Modul
        // Versionsinformation (ab Version 7)
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
            }
            $bitsV = ($version << 12) | ($rem & 0xFFF);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($bitsV >> $i) & 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }
        return [$m, $r];
    }

    /** @param list<list<int>> $m @param list<list<bool>> $reserved */
    private static function place(array &$m, array $reserved, string $stream, int $size, int $mask): void
    {
        $i = 0;
        $n = strlen($stream);
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if ($reserved[$y][$x]) {
                        continue;
                    }
                    $bit = $i < $n ? (int) $stream[$i] : 0;
                    $i++;
                    $m[$y][$x] = $bit ^ (self::maskBit($mask, $x, $y) ? 1 : 0);
                }
            }
        }
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    /** @param list<list<int>> $m */
    private static function formatInfo(array &$m, int $size, int $mask): void
    {
        $data = (0b00 << 3) | $mask; // Fehlerkorrektur M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $b = static fn (int $i): int => ($bits >> $i) & 1;
        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = $b($i);
        }
        $m[7][8] = $b(6);
        $m[8][8] = $b(7);
        $m[8][7] = $b(8);
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = $b($i);
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $b($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = $b($i);
        }
        $m[$size - 8][8] = 1;
    }

    /** @param list<list<int>> $m */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        // Regel 1 und 3 (Zeilen und Spalten)
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $line = [];
                for ($b = 0; $b < $size; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($i = 1; $i < $size; $i++) {
                    if ($line[$i] === $line[$i - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += $run - 2;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += $run - 2;
                }
                $s = implode('', $line);
                $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
            }
        }
        // Regel 2: 2x2-Blöcke
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // Regel 4: Anteil dunkler Module
        $dark = 0;
        foreach ($m as $row) {
            $dark += array_sum($row);
        }
        $pct = $dark * 100 / ($size * $size);
        $score += (int) (abs(intdiv((int) ($pct * 1), 5) * 5 - 50) / 5) * 10;
        return $score;
    }
}
