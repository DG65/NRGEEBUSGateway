<?php

declare(strict_types=1);

// ===========================================================================
// Kleiner QR-Code-Erzeuger (ISO/IEC 18004) für die Kopplungsdaten des Gateways.
// Nur Byte-Modus und Fehlerkorrekturstufe M, Versionen 1–20 – genug für die
// rund 250 Zeichen des SHIP-Kopplungstexts. Ausgabe als Modul-Matrix oder PNG.
// Gegengeprüft mit zbarimg (Prüfstand .tools/test-eebgw.php).
// ===========================================================================

final class EEBGWQr
{
    // Je Version (Stufe M): [EC-Codewörter je Block, Blöcke Gruppe 1, Datenwörter G1, Blöcke G2, Datenwörter G2]
    private const BLOECKE_M = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
    ];
    private const AUSRICHTUNG = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    private static $exp = [];
    private static $log = [];

    /** Modul-Matrix (Liste von Zeilen, true = dunkel) für $text. */
    public static function matrix(string $text): array
    {
        $len = strlen($text);
        $version = 0;
        foreach (self::BLOECKE_M as $v => $b) {
            $daten = $b[1] * $b[2] + $b[3] * $b[4];
            $kopf = 4 + ($v < 10 ? 8 : 16);
            if ($kopf + 8 * $len <= 8 * $daten) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new RuntimeException('QR: text too long');
        }
        $codewoerter = self::codewoerter($text, $version);
        $n = 17 + 4 * $version;
        $best = null;
        $bestStrafe = PHP_INT_MAX;
        for ($maske = 0; $maske < 8; $maske++) {
            [$m, $funktion] = self::grundmuster($version);
            self::platzieren($m, $funktion, $codewoerter, $maske);
            self::formatinfo($m, $maske);
            $strafe = self::strafe($m);
            if ($strafe < $bestStrafe) {
                $bestStrafe = $strafe;
                $best = $m;
            }
        }
        $aus = [];
        for ($y = 0; $y < $n; $y++) {
            $zeile = [];
            for ($x = 0; $x < $n; $x++) {
                $zeile[] = $best[$y][$x] === 1;
            }
            $aus[] = $zeile;
        }
        return $aus;
    }

    /** PNG als data:-URI (für das Formular), $modul Pixel je Modul, 4 Module Ruhezone. */
    public static function pngDataUri(string $text, int $modul = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $groesse = ($n + 8) * $modul;
        $img = imagecreate($groesse, $groesse);
        $weiss = imagecolorallocate($img, 255, 255, 255);
        $schwarz = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $weiss);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    imagefilledrectangle($img, ($x + 4) * $modul, ($y + 4) * $modul, ($x + 5) * $modul - 1, ($y + 5) * $modul - 1, $schwarz);
                }
            }
        }
        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        return 'data:image/png;base64,' . base64_encode($png);
    }

    // --- Daten und Fehlerkorrektur ---

    private static function codewoerter(string $text, int $version): array
    {
        [$ec, $b1, $d1, $b2, $d2] = self::BLOECKE_M[$version];
        $kapazitaet = $b1 * $d1 + $b2 * $d2;
        $bits = '0100' . str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, 8 * $kapazitaet - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $daten = array_map('bindec', str_split($bits, 8));
        for ($i = 0; count($daten) < $kapazitaet; $i++) {
            $daten[] = $i % 2 === 0 ? 0xEC : 0x11;
        }
        $bloecke = [];
        $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$anzahl, $laenge]) {
            for ($i = 0; $i < $anzahl; $i++) {
                $bloecke[] = array_slice($daten, $pos, $laenge);
                $pos += $laenge;
            }
        }
        $ecBloecke = array_map(function ($b) use ($ec) {
            return self::reedSolomon($b, $ec);
        }, $bloecke);
        $aus = [];
        $max = max($d1, $d2);
        for ($i = 0; $i < $max; $i++) {
            foreach ($bloecke as $b) {
                if (isset($b[$i])) {
                    $aus[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($ecBloecke as $b) {
                $aus[] = $b[$i];
            }
        }
        return $aus;
    }

    private static function gf(): void
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

    private static function mal(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function reedSolomon(array $daten, int $anzahl): array
    {
        self::gf();
        $gen = [1];
        for ($i = 0; $i < $anzahl; $i++) {
            $neu = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $neu[$j] ^= $g;
                $neu[$j + 1] ^= self::mal($g, self::$exp[$i]);
            }
            $gen = $neu;
        }
        $rest = array_merge($daten, array_fill(0, $anzahl, 0));
        for ($i = 0; $i < count($daten); $i++) {
            $f = $rest[$i];
            if ($f !== 0) {
                foreach ($gen as $j => $g) {
                    $rest[$i + $j] ^= self::mal($g, $f);
                }
            }
        }
        return array_slice($rest, count($daten));
    }

    // --- Matrix ---

    private static function grundmuster(int $version): array
    {
        $n = 17 + 4 * $version;
        $m = array_fill(0, $n, array_fill(0, $n, 0));
        $f = array_fill(0, $n, array_fill(0, $n, false));
        $setze = function (int $x, int $y, int $wert) use (&$m, &$f) {
            $m[$y][$x] = $wert;
            $f[$y][$x] = true;
        };
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $ox + $dx;
                    $y = $oy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $innen = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                    $dunkel = $innen && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                    $setze($x, $y, $dunkel ? 1 : 0);
                }
            }
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $setze($i, 6, $i % 2 === 0 ? 1 : 0);
            $setze(6, $i, $i % 2 === 0 ? 1 : 0);
        }
        $pos = self::AUSRICHTUNG[$version] ?? [];
        $letzte = end($pos);
        foreach ($pos as $ay) {
            foreach ($pos as $ax) {
                // Nur die drei Ecken mit Suchmuster auslassen; auf der Taktlinie werden sie gezeichnet.
                if (($ax === 6 && $ay === 6) || ($ax === 6 && $ay === $letzte) || ($ax === $letzte && $ay === 6)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $setze($ax + $dx, $ay + $dy, (max(abs($dx), abs($dy)) !== 1) ? 1 : 0);
                    }
                }
            }
        }
        $setze(8, $n - 8, 1);   // dunkles Modul
        for ($i = 0; $i < 9; $i++) {   // Platz für Formatinformation reservieren
            if (!$f[8][$i]) { $setze($i, 8, 0); }
            if (!$f[$i][8]) { $setze(8, $i, 0); }
        }
        for ($i = 0; $i < 8; $i++) {
            $setze($n - 1 - $i, 8, 0);
            if (!$f[$n - 1 - $i][8]) { $setze(8, $n - 1 - $i, 0); }
        }
        if ($version >= 7) {
            $rest = $version << 12;
            for ($i = 17; $i >= 12; $i--) {
                if ($rest & (1 << $i)) {
                    $rest ^= 0x1F25 << ($i - 12);
                }
            }
            $info = ($version << 12) | $rest;
            for ($i = 0; $i < 18; $i++) {
                $bit = ($info >> $i) & 1;
                $a = intdiv($i, 3);
                $b = $n - 11 + $i % 3;
                $setze($a, $b, $bit);
                $setze($b, $a, $bit);
            }
        }
        return [$m, $f];
    }

    private static function platzieren(array &$m, array $f, array $codewoerter, int $maske): void
    {
        $n = count($m);
        $bits = '';
        foreach ($codewoerter as $c) {
            $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        }
        $i = 0;
        $hoch = true;
        for ($x = $n - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x--;
            }
            for ($k = 0; $k < $n; $k++) {
                $y = $hoch ? $n - 1 - $k : $k;
                for ($s = 0; $s < 2; $s++) {
                    $xx = $x - $s;
                    if ($f[$y][$xx]) {
                        continue;
                    }
                    $bit = $i < strlen($bits) ? (int)$bits[$i] : 0;
                    $i++;
                    if (self::maskiert($maske, $xx, $y)) {
                        $bit ^= 1;
                    }
                    $m[$y][$xx] = $bit;
                }
            }
            $hoch = !$hoch;
        }
    }

    private static function maskiert(int $maske, int $x, int $y): bool
    {
        switch ($maske) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0;
            case 5: return ($x * $y) % 2 + ($x * $y) % 3 === 0;
            case 6: return (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0;
            default: return (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
        }
    }

    private static function formatinfo(array &$m, int $maske): void
    {
        $n = count($m);
        $daten = (0b00 << 3) | $maske;   // Stufe M = 00
        $rest = $daten << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($rest & (1 << $i)) {
                $rest ^= 0x537 << ($i - 10);
            }
        }
        $info = (($daten << 10) | $rest) ^ 0x5412;
        for ($i = 0; $i < 15; $i++) {
            $bit = ($info >> $i) & 1;
            // um das obere linke Suchmuster
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i < 8) {
                $m[$i + 1][8] = $bit;
            } else {
                $m[8][14 - $i + ($i === 8 ? 1 : 0)] = $bit;
            }
            // zweite Kopie
            if ($i < 8) {
                $m[8][$n - 1 - $i] = $bit;
            } else {
                $m[$n - 15 + $i][8] = $bit;
            }
        }
    }

    private static function strafe(array $m): int
    {
        $n = count($m);
        $p = 0;
        // Regel 1: fünf und mehr gleiche Module in Zeile/Spalte
        for ($r = 0; $r < 2; $r++) {
            for ($a = 0; $a < $n; $a++) {
                $lauf = 1;
                for ($b = 1; $b < $n; $b++) {
                    $vorher = $r === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    $jetzt = $r === 0 ? $m[$a][$b] : $m[$b][$a];
                    if ($jetzt === $vorher) {
                        $lauf++;
                    } else {
                        if ($lauf >= 5) { $p += $lauf - 2; }
                        $lauf = 1;
                    }
                }
                if ($lauf >= 5) { $p += $lauf - 2; }
            }
        }
        // Regel 2: 2×2-Blöcke
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        // Regel 3: Suchmuster-ähnliche Folgen
        $muster = [[1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1]];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x <= $n - 11; $x++) {
                foreach ($muster as $mu) {
                    $h = true;
                    $v = true;
                    for ($k = 0; $k < 11; $k++) {
                        if ($m[$y][$x + $k] !== $mu[$k]) { $h = false; }
                        if ($m[$x + $k][$y] !== $mu[$k]) { $v = false; }
                    }
                    $p += ($h ? 40 : 0) + ($v ? 40 : 0);
                }
            }
        }
        // Regel 4: Anteil dunkler Module
        $dunkel = 0;
        foreach ($m as $z) {
            $dunkel += array_sum($z);
        }
        $p += intdiv(abs(intdiv($dunkel * 100, $n * $n) - 50), 5) * 10;
        return $p;
    }
}
