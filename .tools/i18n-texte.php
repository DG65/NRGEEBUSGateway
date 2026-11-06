<?php
/**
 * i18n-texte.php — sammelt alle übersetzbaren englischen Quelltexte aus dem Code.
 *
 * Erfasst: Argumente von t() und fest(), Werte von 'caption' => / 'confirm' =>, erste Argumente von
 * EEBGWFehler(...) und fehlerZustand(...), die Zeilen von $EEBGW_NEWS_VERSIONS.
 * Nur Literale (dynamische Teile laufen über %s). Wird vom Prüfstand eingebunden;
 * direkt aufgerufen listet es fehlende und überzählige locale.json-Einträge.
 */

declare(strict_types=1);

function eebgwQuelltexte(string $wurzel): array
{
    $dateien = array_merge([$wurzel . '/EEBUSGateway/module.php'], glob($wurzel . '/libs/*.php') ?: []);
    $texte = [];
    foreach ($dateien as $datei) {
        $tok = array_values(array_filter(token_get_all((string)file_get_contents($datei)), function ($t) {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $inNews = false;
        foreach ($tok as $i => $t) {
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$EEBGW_NEWS_VERSIONS') {
                $inNews = true;
            }
            if ($inNews && $t === ';') {
                $inNews = false;
            }
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $wert = eval('return ' . $t[1] . ';');
            $vor = $tok[$i - 1] ?? null;
            $vor2 = $tok[$i - 2] ?? null;
            $nach = $tok[$i + 1] ?? null;
            $nimm = false;
            if ($inNews && $nach !== '=>' && strpos($wert, '•') === 0) {
                $nimm = true;
            }
            if ($vor === '(' && is_array($vor2) && in_array($vor2[1], ['t', 'fest', 'EEBGWFehler', 'fehlerZustand'], true)) {
                $nimm = true;
            }
            if (is_array($vor) && $vor[0] === T_DOUBLE_ARROW && is_array($vor2) && in_array($vor2[1], ["'caption'", "'confirm'"], true) && $nach !== '.') {
                $nimm = true;
            }
            if ($nimm && preg_match('/\p{L}/u', $wert)) {
                $texte[$wert] = basename($datei) . ':' . $t[2];
            }
        }
    }
    return $texte;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    $wurzel = dirname(__DIR__);
    $texte = eebgwQuelltexte($wurzel);
    $locale = json_decode((string)file_get_contents($wurzel . '/EEBUSGateway/locale.json'), true)['translations']['de'] ?? [];
    $fehlt = array_diff_key($texte, $locale);
    $uebrig = array_diff_key($locale, $texte);
    foreach ($fehlt as $text => $ort) {
        echo "FEHLT   $ort  " . json_encode($text, JSON_UNESCAPED_UNICODE) . "\n";
    }
    foreach ($uebrig as $text => $_) {
        echo "ÜBRIG   " . json_encode($text, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo count($texte) . ' Quelltexte, ' . count($fehlt) . ' fehlen, ' . count($uebrig) . " überzählig\n";
    exit($fehlt ? 1 : 0);
}
