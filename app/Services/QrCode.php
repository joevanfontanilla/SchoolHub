<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * A small QR code encoder written for this project (docs/29): no library, per docs/00. Byte mode, error
 * correction level M, versions 1 to 10 (up to 213 bytes), output as an SVG. It follows ISO/IEC 18004: the
 * data is split into blocks with Reed-Solomon error correction, interleaved, placed in the matrix around
 * the finder, alignment, and timing patterns, masked with the best of the eight masks, and given format
 * and version information. The tests decode its output with an independent decoder.
 */
final class QrCode
{
    /** Per version, level M: [error-correction codewords per block, [[blocks, data codewords per block], ...]]. */
    private const BLOCKS = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Alignment pattern centres per version. */
    private const ALIGNMENT = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** The format bits for error correction level M. */
    private const LEVEL_M = 0;

    /**
     * The QR code as a square grid of modules (true = dark), without the quiet zone.
     *
     * @return array<int,array<int,bool>>
     * @throws InvalidArgumentException when the text does not fit in version 10
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::chooseVersion(count($bytes));
        $codewords = self::interleave(self::dataCodewords($bytes, $version), $version);

        $size = 17 + 4 * $version;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $function = array_fill(0, $size, array_fill(0, $size, false));
        self::drawFunctionPatterns($modules, $function, $version, $size);
        self::drawCodewords($modules, $function, $codewords, $size);

        // Try each mask and keep the one with the lowest penalty.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            self::applyMask($candidate, $function, $mask, $size);
            self::drawFormatBits($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** The QR code as an SVG with a quiet zone, scalable to any size. */
    public static function svg(string $text, int $quietZone = 4, string $label = 'QR code'): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $total = $size + 2 * $quietZone;

        $path = '';
        foreach ($matrix as $y => $row) {
            // One rectangle per run of dark modules keeps the file small.
            $x = 0;
            while ($x < $size) {
                if (!$row[$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $size && $row[$x]) {
                    $x++;
                }
                $path .= 'M' . ($start + $quietZone) . ' ' . ($y + $quietZone) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    // -- Encoding -----------------------------------------------------------------------------

    private static function chooseVersion(int $length): int
    {
        foreach (self::BLOCKS as $version => [, $groups]) {
            $capacityBits = 8 * array_sum(array_map(static fn (array $g): int => $g[0] * $g[1], $groups));
            $countBits = $version <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * $length <= $capacityBits) {
                return $version;
            }
        }

        throw new InvalidArgumentException('That text is too long for the QR code generator.');
    }

    /**
     * @param array<int,int> $bytes
     * @return array<int,int> the data codewords, padded to the version's capacity
     */
    private static function dataCodewords(array $bytes, int $version): array
    {
        $capacity = array_sum(array_map(static fn (array $g): int => $g[0] * $g[1], self::BLOCKS[$version][1]));
        $bits = [];
        $append = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);                                  // byte mode
        $append(count($bytes), $version <= 9 ? 8 : 16);      // character count
        foreach ($bytes as $byte) {
            $append($byte, 8);
        }
        $append(0, min(4, $capacity * 8 - count($bits)));    // terminator
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = bindec(implode('', $chunk));
        }
        for ($pad = 0xEC; count($codewords) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    /**
     * Split into blocks, add Reed-Solomon error correction to each, and interleave.
     *
     * @param array<int,int> $data
     * @return array<int,int>
     */
    private static function interleave(array $data, int $version): array
    {
        [$eccLength, $groups] = self::BLOCKS[$version];
        $divisor = self::reedSolomonDivisor($eccLength);

        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $length]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $length);
                $offset += $length;
                $blocks[] = ['data' => $chunk, 'ecc' => self::reedSolomonRemainder($chunk, $divisor)];
            }
        }

        $result = [];
        $longest = max(array_map(static fn (array $b): int => count($b['data']), $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block['data'])) {
                    $result[] = $block['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block['ecc'][$i];
            }
        }

        return $result;
    }

    /** @return array<int,int> the generator polynomial of the given degree, highest term (always 1) left out */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param array<int,int> $data
     * @param array<int,int> $divisor
     * @return array<int,int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /** Multiplication in GF(256) with the QR polynomial x^8 + x^4 + x^3 + x^2 + 1. */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // -- The matrix ---------------------------------------------------------------------------

    /**
     * @param array<int,array<int,bool>> $modules
     * @param array<int,array<int,bool>> $function
     */
    private static function drawFunctionPatterns(array &$modules, array &$function, int $version, int $size): void
    {
        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$function, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $modules[$y][$x] = $dark;
                $function[$y][$x] = true;
            }
        };

        for ($i = 0; $i < $size; $i++) {                      // timing patterns
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {   // finder patterns and their separators
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $distance = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $distance !== 2 && $distance !== 4);
                }
            }
        }

        $centres = self::ALIGNMENT[$version];
        $last = count($centres) - 1;
        foreach ($centres as $i => $cy) {                     // alignment patterns, except over a finder
            foreach ($centres as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        self::drawFormatBits($modules, 0, $size, $function);  // reserve the format areas; the real bits come with the mask

        if ($version >= 7) {                                  // version information
            $remainder = $version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $dark);
                $set($b, $a, $dark);
            }
        }
    }

    /**
     * Draw the 15 format bits (error correction level M and the mask) in both places, plus the dark module.
     *
     * @param array<int,array<int,bool>>       $modules
     * @param array<int,array<int,bool>>|null  $function when given, the modules are also marked as function modules
     */
    private static function drawFormatBits(array &$modules, int $mask, int $size, ?array &$function = null): void
    {
        $data = (self::LEVEL_M << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$function): void {
            $modules[$y][$x] = $dark;
            if ($function !== null) {
                $function[$y][$x] = true;
            }
        };
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);                             // the always-dark module
    }

    /**
     * @param array<int,array<int,bool>> $modules
     * @param array<int,array<int,bool>> $function
     * @param array<int,int>             $codewords
     */
    private static function drawCodewords(array &$modules, array $function, array $codewords, int $size): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vertical : $vertical;
                    if (!$function[$y][$x]) {
                        // Past the last codeword, the leftover modules (remainder bits) are light.
                        $modules[$y][$x] = $i < $total && ((($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1);
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * @param array<int,array<int,bool>> $modules
     * @param array<int,array<int,bool>> $function
     */
    private static function applyMask(array &$modules, array $function, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if (!$function[$y][$x] && $invert) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    /** The standard's four penalty rules; the mask with the lowest total is used. */
    private static function penalty(array $modules, int $size): int
    {
        $score = 0;

        // Rules 1 and 3, along rows then columns.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $line = [];
                for ($b = 0; $b < $size; $b++) {
                    $line[] = $pass === 0 ? $modules[$a][$b] : $modules[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    if ($line[$b] === $line[$b - 1]) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                    }
                }
                $text = implode('', array_map(static fn (bool $m): string => $m ? '1' : '0', $line));
                $score += 40 * (substr_count($text, '10111010000') + substr_count($text, '00001011101'));
            }
        }

        // Rule 2: 2 x 2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $modules[$y][$x];
                if ($c === $modules[$y][$x + 1] && $c === $modules[$y + 1][$x] && $c === $modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 4: how far the dark share is from half.
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $score += 10 * max(0, intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1);

        return $score;
    }
}
