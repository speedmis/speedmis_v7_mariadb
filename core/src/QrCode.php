<?php
namespace App;

/**
 * Minimal QR Code generator (Model 2, ECC-M, byte mode).
 * No external dependencies — pure PHP + GD for PNG output.
 */
class QrCode
{
    /** Generate QR code as base64 data URI */
    public static function toDataUri(string $text, int $size = 200): string
    {
        $modules = self::encode($text);
        $png = self::toPng($modules, $size);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** Generate QR PNG binary */
    public static function toPng(array $modules, int $size = 200): string
    {
        $n = count($modules);
        $quiet = 4;
        $total = $n + $quiet * 2;
        $scale = max(1, intdiv($size, $total));
        $imgSize = $total * $scale;

        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $imgSize - 1, $imgSize - 1, $white);

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($modules[$y][$x]) {
                    $px = ($x + $quiet) * $scale;
                    $py = ($y + $quiet) * $scale;
                    imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    // ────────────────────────────────────────────────────────
    // QR Code encoding (Version 1-10, ECC M, Byte mode)
    // ────────────────────────────────────────────────────────

    private const EC_M = [ // (totalCodewords, ecPerBlock, numBlocks)
        1 => [26, 10, 1], 2 => [44, 16, 1], 3 => [70, 26, 1], 4 => [100, 18, 2],
        5 => [134, 24, 2], 6 => [172, 16, 4], 7 => [196, 18, 4], 8 => [242, 22, 4],
        9 => [292, 22, 5], 10 => [346, 26, 5],
    ];

    private const ALIGN_POS = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private const FORMAT_BITS = [
        0 => 0x5412, 1 => 0x5125, 2 => 0x5E7C, 3 => 0x5B4B,
        4 => 0x45F9, 5 => 0x40CE, 6 => 0x4F97, 7 => 0x4AA0,
    ];

    public static function encode(string $text): array
    {
        $dataBytes = array_values(unpack('C*', $text));
        $dataLen = count($dataBytes);

        // pick smallest version that fits
        $version = 0;
        foreach (self::EC_M as $v => [$total, $ecPer, $nBlocks]) {
            $dataCap = $total - $ecPer * $nBlocks;
            // byte mode: 4 bit mode + char count bits + data
            $charCountBits = $v <= 9 ? 8 : 16;
            $availBits = $dataCap * 8;
            $neededBits = 4 + $charCountBits + $dataLen * 8;
            if ($neededBits <= $availBits) { $version = $v; break; }
        }
        if (!$version) throw new \RuntimeException("Data too long for QR (max version 10)");

        [$totalCW, $ecPerBlock, $numBlocks] = self::EC_M[$version];
        $dataCW = $totalCW - $ecPerBlock * $numBlocks;
        $charCountBits = $version <= 9 ? 8 : 16;

        // build bit stream
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin($dataLen), $charCountBits, '0', STR_PAD_LEFT);
        foreach ($dataBytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        // terminator
        $bits .= str_repeat('0', min(4, $dataCW * 8 - strlen($bits)));
        // byte-align
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        // pad codewords
        $pad = [0xEC, 0x11]; $pi = 0;
        while (strlen($bits) < $dataCW * 8) {
            $bits .= str_pad(decbin($pad[$pi % 2]), 8, '0', STR_PAD_LEFT);
            $pi++;
        }

        $codewords = [];
        for ($i = 0; $i < $dataCW; $i++) {
            $codewords[] = bindec(substr($bits, $i * 8, 8));
        }

        // split into blocks and generate EC
        $blockSize = intdiv($dataCW, $numBlocks);
        $extra = $dataCW % $numBlocks;
        $dataBlocks = []; $ecBlocks = [];
        $offset = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $sz = $blockSize + ($b >= $numBlocks - $extra ? 1 : 0);
            $block = array_slice($codewords, $offset, $sz);
            $dataBlocks[] = $block;
            $ecBlocks[] = self::rsEncode($block, $ecPerBlock);
            $offset += $sz;
        }

        // interleave
        $result = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++)
            foreach ($dataBlocks as $bl)
                if ($i < count($bl)) $result[] = $bl[$i];
        for ($i = 0; $i < $ecPerBlock; $i++)
            foreach ($ecBlocks as $bl)
                $result[] = $bl[$i];

        // place in matrix
        $n = 17 + $version * 4;
        $grid = array_fill(0, $n, array_fill(0, $n, -1)); // -1 = unset
        $locked = array_fill(0, $n, array_fill(0, $n, false));

        // finder patterns
        self::placeFinderPattern($grid, $locked, 0, 0, $n);
        self::placeFinderPattern($grid, $locked, $n - 7, 0, $n);
        self::placeFinderPattern($grid, $locked, 0, $n - 7, $n);

        // timing patterns
        for ($i = 8; $i < $n - 8; $i++) {
            $v = ($i % 2 === 0) ? 1 : 0;
            if ($grid[6][$i] === -1) { $grid[6][$i] = $v; $locked[6][$i] = true; }
            if ($grid[$i][6] === -1) { $grid[$i][6] = $v; $locked[$i][6] = true; }
        }

        // alignment patterns
        if (isset(self::ALIGN_POS[$version])) {
            $positions = self::ALIGN_POS[$version];
            foreach ($positions as $ay) {
                foreach ($positions as $ax) {
                    if ($locked[$ay][$ax]) continue;
                    self::placeAlignPattern($grid, $locked, $ay, $ax);
                }
            }
        }

        // dark module + reserved areas
        $grid[$n - 8][8] = 1; $locked[$n - 8][8] = true;

        // reserve format info areas
        for ($i = 0; $i < 8; $i++) {
            if (!$locked[8][$i]) { $grid[8][$i] = 0; $locked[8][$i] = true; }
            if (!$locked[$i][8]) { $grid[$i][8] = 0; $locked[$i][8] = true; }
            if (!$locked[8][$n - 1 - $i]) { $grid[8][$n - 1 - $i] = 0; $locked[8][$n - 1 - $i] = true; }
            if (!$locked[$n - 1 - $i][8]) { $grid[$n - 1 - $i][8] = 0; $locked[$n - 1 - $i][8] = true; }
        }
        if (!$locked[8][8]) { $grid[8][8] = 0; $locked[8][8] = true; }

        // place data bits
        $bitStream = '';
        foreach ($result as $cw) $bitStream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        // remainder bits for version
        $remBits = [1=>0,2=>7,3=>7,4=>7,5=>7,6=>7,7=>0,8=>0,9=>0,10=>0];
        $bitStream .= str_repeat('0', $remBits[$version] ?? 0);

        $bitIdx = 0;
        $upward = true;
        for ($col = $n - 1; $col >= 1; $col -= 2) {
            if ($col === 6) $col = 5; // skip timing column
            $rows = $upward ? range($n - 1, 0, -1) : range(0, $n - 1);
            foreach ($rows as $row) {
                for ($dc = 0; $dc <= 1; $dc++) {
                    $c = $col - $dc;
                    if ($c < 0 || $c >= $n) continue;
                    if ($locked[$row][$c]) continue;
                    $grid[$row][$c] = ($bitIdx < strlen($bitStream)) ? (int)$bitStream[$bitIdx++] : 0;
                }
            }
            $upward = !$upward;
        }

        // apply mask (try all 8, pick lowest penalty)
        $bestMask = 0; $bestPenalty = PHP_INT_MAX; $bestGrid = $grid;
        for ($mask = 0; $mask < 8; $mask++) {
            $masked = self::applyMask($grid, $locked, $mask, $n);
            self::placeFormatInfo($masked, $mask, $n);
            $penalty = self::calcPenalty($masked, $n);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestGrid = $masked;
            }
        }

        return $bestGrid;
    }

    private static function placeFinderPattern(array &$grid, array &$locked, int $row, int $col, int $n): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r; $cc = $col + $c;
                if ($rr < 0 || $rr >= $n || $cc < 0 || $cc >= $n) continue;
                $inOuter = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $inInner = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $isSep = ($r === -1 || $r === 7 || $c === -1 || $c === 7);
                $grid[$rr][$cc] = ($isSep) ? 0 : (($inOuter || $inInner) ? 1 : 0);
                $locked[$rr][$cc] = true;
            }
        }
    }

    private static function placeAlignPattern(array &$grid, array &$locked, int $cy, int $cx): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $val = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)) ? 1 : 0;
                $grid[$cy + $r][$cx + $c] = $val;
                $locked[$cy + $r][$cx + $c] = true;
            }
        }
    }

    private static function applyMask(array $grid, array $locked, int $mask, int $n): array
    {
        $out = $grid;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($locked[$r][$c]) continue;
                $flip = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                    6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
                };
                if ($flip) $out[$r][$c] ^= 1;
            }
        }
        return $out;
    }

    private static function placeFormatInfo(array &$grid, int $mask, int $n): void
    {
        // ECC level M = 00, mask pattern 3 bits
        $formatBits = self::FORMAT_BITS[$mask];
        $bits = str_pad(decbin($formatBits), 15, '0', STR_PAD_LEFT);

        // horizontal: row 8
        $hPos = [0,1,2,3,4,5,7,8, $n-8,$n-7,$n-6,$n-5,$n-4,$n-3,$n-2,$n-1];
        // vertical: col 8
        $vPos = [$n-1,$n-2,$n-3,$n-4,$n-5,$n-6,$n-7, 8, 7,5,4,3,2,1,0];

        for ($i = 0; $i < 15; $i++) {
            $bit = (int)$bits[$i];
            if (isset($hPos[$i])) $grid[8][$hPos[$i]] = $bit;
            if (isset($vPos[$i])) $grid[$vPos[$i]][8] = $bit;
        }
    }

    private static function calcPenalty(array $grid, int $n): int
    {
        $penalty = 0;
        // Rule 1: consecutive same-color modules in row/col
        for ($r = 0; $r < $n; $r++) {
            $cnt = 1;
            for ($c = 1; $c < $n; $c++) {
                if ($grid[$r][$c] === $grid[$r][$c - 1]) { $cnt++; }
                else { if ($cnt >= 5) $penalty += $cnt - 2; $cnt = 1; }
            }
            if ($cnt >= 5) $penalty += $cnt - 2;
        }
        for ($c = 0; $c < $n; $c++) {
            $cnt = 1;
            for ($r = 1; $r < $n; $r++) {
                if ($grid[$r][$c] === $grid[$r - 1][$c]) { $cnt++; }
                else { if ($cnt >= 5) $penalty += $cnt - 2; $cnt = 1; }
            }
            if ($cnt >= 5) $penalty += $cnt - 2;
        }
        // Rule 2: 2x2 blocks
        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                $v = $grid[$r][$c];
                if ($v === $grid[$r][$c+1] && $v === $grid[$r+1][$c] && $v === $grid[$r+1][$c+1])
                    $penalty += 3;
            }
        }
        // Rule 4: proportion
        $dark = 0;
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) if ($grid[$r][$c]) $dark++;
        $pct = $dark * 100 / ($n * $n);
        $prev5 = intdiv((int)$pct, 5) * 5;
        $next5 = $prev5 + 5;
        $penalty += min(abs($prev5 - 50), abs($next5 - 50)) / 5 * 10;
        return (int)$penalty;
    }

    // ── Reed-Solomon (GF(2^8), poly 0x11D) ──

    private static array $gfExp = [];
    private static array $gfLog = [];
    private static bool $gfInit = false;

    private static function gfInit(): void
    {
        if (self::$gfInit) return;
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $v = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $v;
            self::$gfLog[$v] = $i;
            $v <<= 1;
            if ($v >= 256) $v ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) self::$gfExp[$i] = self::$gfExp[$i - 255];
        self::$gfInit = true;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    private static function rsEncode(array $data, int $ecLen): array
    {
        self::gfInit();
        // build generator polynomial
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $newGen = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                $newGen[$j] ^= $gen[$j];
                $newGen[$j + 1] ^= self::gfMul($gen[$j], self::$gfExp[$i]);
            }
            $gen = $newGen;
        }

        $msg = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $msg[$i];
            if ($coef === 0) continue;
            for ($j = 1; $j < count($gen); $j++) {
                $msg[$i + $j] ^= self::gfMul($gen[$j], $coef);
            }
        }
        return array_slice($msg, count($data));
    }
}
