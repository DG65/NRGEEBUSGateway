<?php

declare(strict_types=1);

// ===========================================================================
// EEBUS-JSON <-> normales JSON.
//
// SHIP/SPINE schreiben jedes JSON-Objekt als Liste einzelner Schlüssel:
// {"a":1,"b":2} wird zu [{"a":1},{"b":2}]. Echte Listen bleiben Listen, ihre
// Objekt-Elemente werden dadurch zu Listen von Listen. Vorbild ist ship-go
// (JsonIntoEEBUSJson/JsonFromEEBUSJson, MIT), hier strukturell statt per
// Textersetzung. Objekte sind stdClass, Listen PHP-Arrays.
// ===========================================================================

final class EEBGWJson
{
    /** EEBUS-JSON-Text (ohne äußere Klammern, wie im SHIP-Rahmen) -> stdClass. */
    public static function parse(string $text)
    {
        $roh = json_decode('[' . $text . ']');
        return $roh === null ? null : self::decode($roh);
    }

    /** stdClass/Array -> EEBUS-JSON-Text ohne äußere Klammern (wie ship-go). */
    public static function render($wert): string
    {
        $obj = json_decode((string)json_encode($wert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $text = (string)json_encode(self::encode($obj), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return substr($text, 1, -1);
    }

    public static function decode($v)
    {
        if (is_array($v)) {
            if ($v !== [] && self::alleEinzelschluessel($v)) {
                $o = new stdClass();
                foreach ($v as $e) {
                    foreach ((array)$e as $k => $x) {
                        $o->$k = self::decode($x);
                    }
                }
                return $o;
            }
            return array_map([self::class, 'decode'], $v);
        }
        if ($v instanceof stdClass) {
            $o = new stdClass();
            foreach ((array)$v as $k => $x) {
                $o->$k = self::decode($x);
            }
            return $o;
        }
        return $v;
    }

    public static function encode($v)
    {
        if ($v instanceof stdClass) {
            $out = [];
            foreach ((array)$v as $k => $x) {
                $out[] = (object)[$k => self::encode($x)];
            }
            return $out;
        }
        if (is_array($v)) {
            return array_map([self::class, 'encode'], $v);
        }
        return $v;
    }

    private static function alleEinzelschluessel(array $v): bool
    {
        foreach ($v as $e) {
            if (!($e instanceof stdClass) || count((array)$e) !== 1) {
                return false;
            }
        }
        return true;
    }
}
