<?php

declare(strict_types=1);

// ===========================================================================
// Zertifikat und SKI nach SHIP.
//
// SHIP verlangt ein selbstsigniertes ECDSA-Zertifikat auf P-256. Die Identität
// eines Geräts ist der SKI: SHA-1 des unkomprimierten öffentlichen EC-Punkts,
// zugleich als subjectKeyIdentifier im Zertifikat (ship-go cert.go). OpenSSLs
// "subjectKeyIdentifier=hash" rechnet genau das; im Prototyp gegen eebus-go
// geprüft (27.10.2026).
// ===========================================================================

final class EEBGWCert
{
    /** Neues Schlüsselpaar + Zertifikat. Rückgabe ['cert' => PEM, 'key' => PEM, 'ski' => hex]. */
    public static function erzeugen(string $name): array
    {
        $cnf = self::opensslConfig();
        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
            if ($key === false) {
                throw new RuntimeException('Schlüssel konnte nicht erzeugt werden: ' . self::opensslFehler());
            }
            $csr = openssl_csr_new(['C' => 'DE', 'O' => 'NRG-Stack', 'OU' => 'EEBUSGateway', 'CN' => $name], $key, ['digest_alg' => 'sha256', 'config' => $cnf]);
            if ($csr === false) {
                throw new RuntimeException('Zertifikatsanfrage fehlgeschlagen: ' . self::opensslFehler());
            }
            // SHIP: Gültigkeit zehn Jahre wie eebus-go; Seriennummer zufällig.
            $crt = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256', 'config' => $cnf, 'x509_extensions' => 'ship'], random_int(1, PHP_INT_MAX));
            if ($crt === false) {
                throw new RuntimeException('Zertifikat konnte nicht signiert werden: ' . self::opensslFehler());
            }
            openssl_x509_export($crt, $crtPem);
            openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);
        } finally {
            @unlink($cnf);
        }
        return ['cert' => $crtPem, 'key' => $keyPem, 'ski' => (string)self::skiAusZertifikat($crtPem)];
    }

    /** SKI (40 Hex-Zeichen, klein) aus einem PEM-Zertifikat, null wenn kein P-256-Schlüssel. */
    public static function skiAusZertifikat(string $pem): ?string
    {
        $pub = @openssl_pkey_get_public($pem);
        if ($pub === false) {
            return null;
        }
        $d = openssl_pkey_get_details($pub);
        if (!isset($d['ec']['x'], $d['ec']['y']) || ($d['ec']['curve_name'] ?? '') !== 'prime256v1') {
            return null;
        }
        $punkt = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return sha1($punkt);
    }

    /** Stimmt der subjectKeyIdentifier im Zertifikat mit dem Schlüssel überein (ship-go prüft das)? */
    public static function skiStimmt(string $pem): bool
    {
        $info = @openssl_x509_parse($pem);
        $ext = strtolower(str_replace(':', '', (string)($info['extensions']['subjectKeyIdentifier'] ?? '')));
        $ski = self::skiAusZertifikat($pem);
        return $ski !== null && $ext === $ski;
    }

    /** SKI normalisieren: Leerzeichen/Doppelpunkte/Bindestriche weg, klein. */
    public static function normalisieren(string $ski): string
    {
        return strtolower((string)preg_replace('/[^0-9a-fA-F]/', '', $ski));
    }

    /** SKI lesbar in Vierergruppen, wie ihn Hersteller-Apps meist zeigen. */
    public static function lesbar(string $ski): string
    {
        return trim(chunk_split(self::normalisieren($ski), 4, ' '));
    }

    /**
     * Kurze TLS-Verbindung zur Gegenstelle, um ihr Zertifikat abzuholen
     * (Trust on first use bzw. Abgleich mit dem eingetragenen SKI).
     * Rückgabe ['cert' => PEM, 'ski' => hex, 'protokoll' => ..., 'chiffre' => ...].
     */
    public static function gegenstelleAbholen(string $host, int $port, string $eigenesCert, string $eigenerKey, int $timeout = 8): array
    {
        $dir = sys_get_temp_dir();
        $c = (string)tempnam($dir, 'eebgw');
        $k = (string)tempnam($dir, 'eebgw');
        file_put_contents($c, $eigenesCert);
        file_put_contents($k, $eigenerKey);
        try {
            $ctx = stream_context_create(['ssl' => [
                'local_cert' => $c, 'local_pk' => $k,
                // Bewusst ohne Prüfung: genau dieses Zertifikat soll erst geholt und
                // dann per SKI bestätigt werden. Danach gilt Pinning im Client Socket.
                'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                'capture_peer_cert' => true,
            ]]);
            $s = @stream_socket_client('tls://' . $host . ':' . $port, $en, $es, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if ($s === false) {
                $openssl = self::opensslFehler();
                throw new RuntimeException(trim('Keine TLS-Verbindung zu ' . $host . ':' . $port . ' (' . ($es ?: 'Fehler ' . $en) . ') ' . $openssl));
            }
            $meta = stream_get_meta_data($s)['crypto'] ?? [];
            $peer = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
            fclose($s);
        } finally {
            @unlink($c);
            @unlink($k);
        }
        if ($peer === null || !openssl_x509_export($peer, $pem)) {
            throw new RuntimeException('Die Gegenstelle hat kein Zertifikat geliefert.');
        }
        $ski = self::skiAusZertifikat($pem);
        if ($ski === null) {
            throw new RuntimeException('Das Zertifikat der Gegenstelle ist kein SHIP-Zertifikat (kein ECDSA P-256).');
        }
        return ['cert' => $pem, 'ski' => $ski, 'protokoll' => (string)($meta['protocol'] ?? ''), 'chiffre' => (string)($meta['cipher_name'] ?? '')];
    }

    private static function opensslConfig(): string
    {
        $cnf = (string)tempnam(sys_get_temp_dir(), 'eebgw');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[ship]\nsubjectKeyIdentifier=hash\nbasicConstraints=critical,CA:true\nkeyUsage=digitalSignature\n");
        return $cnf;
    }

    private static function opensslFehler(): string
    {
        $t = [];
        while (($e = openssl_error_string()) !== false) {
            $t[] = $e;
        }
        return implode(' / ', $t);
    }
}
