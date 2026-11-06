<?php

declare(strict_types=1);

// ===========================================================================
// SHIP Pairing Service, Rolle „Listener“ (devA).
//
// Das Gateway erzeugt ein Geheimnis und gibt SKI, SHIP-ID, Fingerabdruck des
// eigenen Zertifikats und das Geheimnis heraus (Text bzw. QR-Code für den
// Messstellenbetreiber). Die Gegenstelle (devZ, z. B. eine Steuerbox) kündigt
// sich danach per mDNS unter _shippairing._tcp an und weist per HMAC nach, dass
// sie das Geheimnis kennt. Ist der Nachweis gültig, vertraut das Gateway ihr und
// verbindet sich zu ihrer _ship._tcp-Ankündigung mit derselben SHIP-ID.
//
// Vorbild: enbility ship-go pairing/hmac.go und pairing/listener.go (MIT),
// Prüfwerte aus Anhang A der Spezifikation (ship-go api/shippairing_txt_test.go).
// ===========================================================================

final class EEBGWPairing
{
    // Reihenfolge und Schlüssel der TXT-Einträge (SHIP Pairing Service, TXT-Format 1).
    public const FELDER = ['txtvers', 'parType', 'forId', 'forPar', 'trustId', 'trustPar', 'trustCurve', 'type', 'trustNonce', 'alg', 'digest'];
    // Ringpuffer gegen Wiederholungen: mindestens 10 Einträge (Spezifikation Abschnitt 11).
    public const VERLAUF = 20;

    /** Neues Geheimnis: 16 Byte, als Hex in Großbuchstaben. */
    public static function neuesGeheimnis(): string
    {
        return strtoupper(bin2hex(random_bytes(16)));
    }

    /** SHA-256-Fingerabdruck eines PEM-Zertifikats (DER), 64 Hex-Zeichen groß – wie ship-go. */
    public static function fingerabdruck(string $pem): string
    {
        $der = base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        return $der === false ? '' : strtoupper(hash('sha256', $der));
    }

    /** Kopplungsdaten als Text, wie EEBUS-Geräte sie im QR-Code tragen. */
    public static function qrText(string $ski, string $shipId, string $fingerabdruck, string $geheimnis): string
    {
        return 'SHIP;SKI:' . strtolower($ski) . ';ID:' . $shipId . ';FPH256:' . strtoupper($fingerabdruck)
            . ';SPSEC:' . strtoupper($geheimnis) . ';BRAND:NRG-Stack;MODEL:EEBUSGateway;ENDSHIP;';
    }

    /** TXT-Einträge (Schlüssel beliebig geschrieben, ggf. "k=v"-Liste) auf die Feldnamen abbilden. */
    public static function txtNormalisieren(array $roh): array
    {
        $klein = [];
        foreach ($roh as $k => $v) {
            if (is_int($k) && is_string($v) && strpos($v, '=') !== false) {
                [$k, $v] = explode('=', $v, 2);
            }
            $klein[strtolower(trim((string)$k))] = trim((string)$v);
        }
        $txt = [];
        foreach (self::FELDER as $f) {
            $txt[$f] = $klein[strtolower($f)] ?? '';
        }
        return $txt;
    }

    /** HMAC-SHA256 nach Spezifikation: Schlüssel = Geheimnis ‖ Nonce (binär), Nachricht = feste Feldfolge. */
    public static function digest(string $geheimnisHex, array $txt): string
    {
        $schluessel = (string)hex2bin($geheimnisHex) . (string)hex2bin($txt['trustNonce']);
        $nachricht = '';
        foreach (array_slice(self::FELDER, 0, 10) as $f) {
            $nachricht .= $f . '=' . $txt[$f] . ';';
        }
        return strtoupper(hash_hmac('sha256', $nachricht, $schluessel));
    }

    /**
     * Ankündigung prüfen. Rückgabe ['ok' => bool, 'grund' => englischer Quelltext (locale.json)].
     * $gesehen: bereits angenommene Digests (Wiederholungsschutz).
     */
    public static function pruefen(array $txt, string $geheimnisHex, string $eigeneShipId, string $eigenerFingerabdruck, array $gesehen): array
    {
        $fehlt = [];
        foreach (self::FELDER as $f) {
            if ($txt[$f] === '') {
                $fehlt[] = $f;
            }
        }
        if ($fehlt !== []) {
            return ['ok' => false, 'grund' => 'Pairing announcement incomplete.'];
        }
        if ($txt['txtvers'] !== '1' || $txt['parType'] !== 'fpSha256' || $txt['type'] !== 'addCu') {
            return ['ok' => false, 'grund' => 'Pairing announcement has an unknown format.'];
        }
        if ($txt['forId'] !== $eigeneShipId) {
            return ['ok' => false, 'grund' => 'Pairing announcement is meant for another device.'];
        }
        if (strcasecmp($txt['forPar'], $eigenerFingerabdruck) !== 0) {
            return ['ok' => false, 'grund' => 'Pairing announcement names a different certificate of this gateway.'];
        }
        if ($txt['trustCurve'] !== 'secp256r1' || $txt['alg'] !== 'hmacSha256') {
            return ['ok' => false, 'grund' => 'Pairing announcement uses an unsupported curve or algorithm.'];
        }
        if (!ctype_xdigit($txt['trustNonce']) || strlen($txt['trustNonce']) % 2 !== 0 || !ctype_xdigit($txt['digest'])
            || !ctype_xdigit($txt['trustPar']) || strlen($txt['trustPar']) !== 64) {
            return ['ok' => false, 'grund' => 'Pairing announcement contains invalid values.'];
        }
        if (!hash_equals(self::digest($geheimnisHex, $txt), strtoupper($txt['digest']))) {
            return ['ok' => false, 'grund' => 'Pairing proof is wrong (secret does not match).'];
        }
        if (in_array(strtoupper($txt['digest']), $gesehen, true)) {
            return ['ok' => false, 'grund' => 'Pairing announcement was already used (replay).'];
        }
        return ['ok' => true, 'grund' => ''];
    }
}
