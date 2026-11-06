<?php

declare(strict_types=1);

require_once __DIR__ . '/EEBGWCert.php';

// ===========================================================================
// TLS-1.2-Server (RFC 5246) für die SHIP-Server-Rolle.
//
// Warum selbst: Manche EEBUS-Geräte (z. B. Wolf Link) bauen die Verbindung
// nur selbst auf. SHIP verlangt dann vom Server ein Client-Zertifikat (SHIP 9,
// ship-go: RequireAnyClientCert), dessen SKI über das Vertrauen entscheidet.
// Symcons Server Socket kann TLS, reicht das Zertifikat des Clients aber nicht
// an das Modul weiter (live geprüft 06.11.2026). Deshalb nimmt der Server
// Socket hier nur rohe Bytes an, TLS macht dieses Modul selbst – damit hängt
// die Pflicht-Chiffre auch nicht an Symcons TLS-Bibliothek (Symcon 9.1: rustls
// ohne CBC).
//
// Umfang bewusst klein: ECDHE-ECDSA auf secp256r1 mit
// TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA256 (SHIP-Pflicht, 0xC023) oder
// TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256 (0xC02B), Extended Master Secret
// (RFC 7627), sichere Neuverhandlung nur als leere Bestätigung (RFC 5746,
// neu verhandelt wird nie), keine Sitzungswiederaufnahme (SHIP 9.6).
// Transportunabhängig und ohne Ressourcen: feed() bekommt die Bytes des
// Clients, liefert die zu sendenden; der Zustand lässt sich serialisieren.
// Vorbild für Aufbau und Namen: RoboHubTls (TLS-Client im RoboHub).
// ===========================================================================

final class EEBGWTlsServer
{
    // Kennung → [Art, Schlüssellänge, MAC-Länge, feste IV-Länge]
    private const SUITEN = [
        0xC023 => ['cbc', 16, 32, 0],
        0xC02B => ['gcm', 16, 0, 4],
    ];
    private const VERSION = "\x03\x03";
    private const T_CCS = 20;
    private const T_ALARM = 21;
    private const T_HANDSCHLAG = 22;
    private const T_DATEN = 23;
    // DER-Vorspann eines P-256-SubjectPublicKeyInfo vor dem unkomprimierten Punkt.
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var string eigenes Zertifikat und Schlüssel (PEM), vom Aufrufer gesetzt */
    public $zertifikat = '';
    public $schluessel = '';

    /** @var string Zustand: hello, client, ccs, finished, daten, zu, fehler */
    public $phase = 'hello';
    public $fehler = '';
    /** @var string Zertifikat des Clients (PEM) und sein SKI, sobald der Handschlag steht */
    public $clientZertifikat = '';
    public $clientSki = '';
    public $suite = 0;

    private $roh = '';
    private $hsOffen = '';
    private $verlauf = '';
    private $clientZufall = '';
    private $serverZufall = '';
    private $ems = false;
    private $ephemer = '';          // eigener ECDHE-Schlüssel (PEM)
    private $clientPub = '';        // öffentlicher Schlüssel des Client-Zertifikats (PEM)
    private $hashBisKeyExchange = '';
    private $master = '';
    private $leseSchluessel = null;    // [Schlüssel, MAC-Schlüssel, feste IV]
    private $schreibSchluessel = null;
    private $leseNr = 0;
    private $schreibNr = 0;

    public function verbunden(): bool
    {
        return $this->phase === 'daten';
    }

    /**
     * Bytes vom Client verarbeiten. Rückgabe ['out' => an den Client, 'daten' => entschlüsselte
     * Anwendungsdaten]. Bei Fehlern steht die Phase auf 'fehler' und out enthält den Alarm.
     */
    public function feed(string $bytes): array
    {
        $this->roh .= $bytes;
        $out = '';
        $daten = '';
        while (in_array($this->phase, ['hello', 'client', 'ccs', 'finished', 'daten'], true) && strlen($this->roh) >= 5) {
            $typ = ord($this->roh[0]);
            $len = unpack('n', substr($this->roh, 3, 2))[1];
            if ($len > 18432) {
                return ['out' => $out . $this->alarm(22, 'record too long'), 'daten' => $daten];
            }
            if (strlen($this->roh) < 5 + $len) {
                break;
            }
            $satz = substr($this->roh, 5, $len);
            $this->roh = (string)substr($this->roh, 5 + $len);
            try {
                if ($this->leseSchluessel !== null) {
                    $satz = $this->entschluesseln($typ, $satz);
                }
                if ($typ === self::T_HANDSCHLAG) {
                    $out .= $this->handschlagDaten($satz);
                } elseif ($typ === self::T_CCS) {
                    if ($this->phase !== 'ccs' || $satz !== "\x01") {
                        throw new RuntimeException('unexpected ChangeCipherSpec');
                    }
                    $this->leseSchluessel = $this->schluesselFuer('client');
                    $this->leseNr = 0;
                    $this->phase = 'finished';
                } elseif ($typ === self::T_ALARM) {
                    if (strlen($satz) >= 2 && ord($satz[1]) === 0) {
                        $this->phase = 'zu';
                        $this->fehler = 'close_notify';
                    } else {
                        $this->phase = 'fehler';
                        $this->fehler = 'alert from client: ' . bin2hex($satz);
                    }
                } elseif ($typ === self::T_DATEN && $this->phase === 'daten') {
                    $daten .= $satz;
                } else {
                    throw new RuntimeException('unexpected record type ' . $typ . ' in phase ' . $this->phase);
                }
            } catch (Throwable $e) {
                return ['out' => $out . $this->alarm($e instanceof EEBGWTlsEntschluesseln ? 20 : 40, $e->getMessage()), 'daten' => $daten];
            }
        }
        return ['out' => $out, 'daten' => $daten];
    }

    /** Anwendungsdaten verschlüsseln (in Sätzen zu höchstens 16 KiB). */
    public function senden(string $daten): string
    {
        if ($this->phase !== 'daten') {
            return '';
        }
        $out = '';
        foreach (str_split($daten, 16384) as $teil) {
            $out .= $this->satz(self::T_DATEN, $teil);
        }
        return $out;
    }

    /** close_notify an den Client. */
    public function schliessen(): string
    {
        if ($this->phase !== 'daten') {
            return '';
        }
        $this->phase = 'zu';
        return $this->satz(self::T_ALARM, "\x01\x00");
    }

    // ------------------------------------------------------------------
    // Handschlag
    // ------------------------------------------------------------------

    private function handschlagDaten(string $satz): string
    {
        $this->hsOffen .= $satz;
        $out = '';
        while (strlen($this->hsOffen) >= 4) {
            $len = unpack('N', "\x00" . substr($this->hsOffen, 1, 3))[1];
            if (strlen($this->hsOffen) < 4 + $len) {
                break;
            }
            $nachricht = substr($this->hsOffen, 0, 4 + $len);
            $this->hsOffen = (string)substr($this->hsOffen, 4 + $len);
            $out .= $this->nachricht(ord($nachricht[0]), substr($nachricht, 4), $nachricht);
        }
        return $out;
    }

    private function nachricht(int $typ, string $inhalt, string $ganz): string
    {
        switch ($typ) {
            case 1:
                if ($this->phase !== 'hello') {
                    // Neuverhandlung wird nie gemacht (RFC 5746, wie RoboHubTls).
                    throw new RuntimeException('renegotiation not supported');
                }
                $this->verlauf .= $ganz;
                return $this->clientHello($inhalt);
            case 11:
                $this->erwarte('client');
                $this->verlauf .= $ganz;
                $this->clientCertificate($inhalt);
                return '';
            case 16:
                $this->erwarte('client');
                if ($this->clientZertifikat === '') {
                    throw new RuntimeException('client certificate missing (SHIP requires client authentication)');
                }
                $this->verlauf .= $ganz;
                $this->hashBisKeyExchange = hash('sha256', $this->verlauf, true);
                $this->clientKeyExchange($inhalt);
                return '';
            case 15:
                $this->erwarte('client');
                $this->certificateVerify($inhalt);
                $this->verlauf .= $ganz;
                $this->phase = 'ccs';
                return '';
            case 20:
                $this->erwarte('finished');
                $soll = self::prf($this->master, 'client finished', hash('sha256', $this->verlauf, true), 12);
                if (!hash_equals($soll, $inhalt)) {
                    throw new RuntimeException('client Finished does not match');
                }
                $this->verlauf .= $ganz;
                $out = $this->rohSatz(self::T_CCS, "\x01");
                $this->schreibSchluessel = $this->schluesselFuer('server');
                $this->schreibNr = 0;
                $verify = self::prf($this->master, 'server finished', hash('sha256', $this->verlauf, true), 12);
                $out .= $this->satz(self::T_HANDSCHLAG, "\x14" . substr(pack('N', 12), 1) . $verify);
                $this->phase = 'daten';
                // Nicht mehr gebraucht; nicht im serialisierten Zustand mitschleppen.
                $this->verlauf = '';
                $this->ephemer = '';
                return $out;
        }
        throw new RuntimeException('unexpected handshake message ' . $typ);
    }

    private function erwarte(string $phase): void
    {
        if ($this->phase !== $phase) {
            throw new RuntimeException('handshake message out of order (phase ' . $this->phase . ')');
        }
    }

    private function clientHello(string $h): string
    {
        $p = 0;
        if (substr($h, 0, 2) < self::VERSION) {
            throw new RuntimeException('client does not offer TLS 1.2');
        }
        $p += 2;
        $this->clientZufall = substr($h, $p, 32);
        $p += 32;
        $p += 1 + ord($h[$p]);                       // Sitzungs-ID (keine Wiederaufnahme)
        $nSuiten = unpack('n', substr($h, $p, 2))[1];
        $suiten = [];
        foreach (str_split(substr($h, $p + 2, $nSuiten), 2) as $s) {
            $suiten[] = unpack('n', $s)[1];
        }
        $p += 2 + $nSuiten;
        $p += 1 + ord($h[$p]);                       // Kompression
        $ext = [];
        if ($p + 2 <= strlen($h)) {
            $ende = $p + 2 + unpack('n', substr($h, $p, 2))[1];
            $p += 2;
            while ($p + 4 <= $ende) {
                $t = unpack('n', substr($h, $p, 2))[1];
                $l = unpack('n', substr($h, $p + 2, 2))[1];
                $ext[$t] = substr($h, $p + 4, $l);
                $p += 4 + $l;
            }
        }
        // SHIP-Pflichtchiffre zuerst (SHIP 9.1), sonst GCM.
        foreach ([0xC023, 0xC02B] as $s) {
            if (in_array($s, $suiten, true)) {
                $this->suite = $s;
                break;
            }
        }
        if ($this->suite === 0) {
            throw new RuntimeException('client offers no supported cipher suite (needs ECDHE-ECDSA-AES128-SHA256)');
        }
        if (isset($ext[10]) && strpos(substr($ext[10], 2), "\x00\x17") === false) {
            throw new RuntimeException('client does not support secp256r1');
        }
        $this->ems = isset($ext[0x0017]);
        $this->serverZufall = random_bytes(32);

        $extAus = '';
        if (isset($ext[0xFF01]) || in_array(0x00FF, $suiten, true)) {
            $extAus .= "\xff\x01\x00\x01\x00";
        }
        if (isset($ext[11])) {
            $extAus .= "\x00\x0b\x00\x02\x01\x00";
        }
        if ($this->ems) {
            $extAus .= "\x00\x17\x00\x00";
        }
        $out = $this->handschlagSenden(2, self::VERSION . $this->serverZufall . "\x00" . pack('n', $this->suite) . "\x00"
            . ($extAus === '' ? '' : pack('n', strlen($extAus)) . $extAus));

        $der = self::der($this->zertifikat);
        $out .= $this->handschlagSenden(11, self::len3(self::len3($der) . $der) . self::len3($der) . $der);

        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($k === false || !openssl_pkey_export($k, $pem)) {
            throw new RuntimeException('ECDHE key could not be created');
        }
        $this->ephemer = $pem;
        $ec = openssl_pkey_get_details($k)['ec'];
        $punkt = "\x04" . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
        $params = "\x03\x00\x17" . chr(strlen($punkt)) . $punkt;
        if (!openssl_sign($this->clientZufall . $this->serverZufall . $params, $sig, $this->schluessel, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('ServerKeyExchange could not be signed');
        }
        $out .= $this->handschlagSenden(12, $params . "\x04\x03" . pack('n', strlen($sig)) . $sig);
        // CertificateRequest: ecdsa_sign, ecdsa_secp256r1_sha256, keine CA-Liste (selbst signiert, SHIP 12.1).
        $out .= $this->handschlagSenden(13, "\x01\x40" . "\x00\x02\x04\x03" . "\x00\x00");
        $out .= $this->handschlagSenden(14, '');
        $this->phase = 'client';
        return $out;
    }

    private function clientCertificate(string $h): void
    {
        $gesamt = unpack('N', "\x00" . substr($h, 0, 3))[1];
        if ($gesamt === 0) {
            return;
        }
        $len = unpack('N', "\x00" . substr($h, 3, 3))[1];
        $der = substr($h, 6, $len);
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            throw new RuntimeException('client certificate unreadable');
        }
        $details = openssl_pkey_get_details($pub);
        if (($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
            throw new RuntimeException('client certificate has no EC key');
        }
        $this->clientZertifikat = $pem;
        $this->clientPub = (string)$details['key'];
        $this->clientSki = (string)EEBGWCert::skiAusZertifikat($pem);
    }

    private function clientKeyExchange(string $h): void
    {
        $punkt = substr($h, 1, ord($h[0]));
        if (strlen($punkt) !== 65 || $punkt[0] !== "\x04") {
            throw new RuntimeException('invalid ECDHE public key from client');
        }
        $spki = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI) . $punkt), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $peer = openssl_pkey_get_public($spki);
        $eigen = openssl_pkey_get_private($this->ephemer);
        $pms = ($peer === false || $eigen === false) ? false : openssl_pkey_derive($peer, $eigen);
        if ($pms === false || $pms === '') {
            throw new RuntimeException('ECDHE key agreement failed');
        }
        $this->master = $this->ems
            ? self::prf($pms, 'extended master secret', $this->hashBisKeyExchange, 48)
            : self::prf($pms, 'master secret', $this->clientZufall . $this->serverZufall, 48);
    }

    private function certificateVerify(string $h): void
    {
        if (substr($h, 0, 2) !== "\x04\x03") {
            throw new RuntimeException('CertificateVerify uses an unsupported signature algorithm ' . bin2hex(substr($h, 0, 2)));
        }
        $sig = substr($h, 4, unpack('n', substr($h, 2, 2))[1]);
        if (openssl_verify($this->verlauf, $sig, $this->clientPub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('client certificate signature invalid');
        }
    }

    /** Schlüsselblock: client MAC, server MAC, client Schlüssel, server Schlüssel, client IV, server IV. */
    private function schluesselFuer(string $seite): array
    {
        [, $kl, $ml, $il] = self::SUITEN[$this->suite];
        $block = self::prf($this->master, 'key expansion', $this->serverZufall . $this->clientZufall, 2 * ($ml + $kl + $il));
        $c = $seite === 'client' ? 0 : 1;
        return [
            substr($block, 2 * $ml + $c * $kl, $kl),
            substr($block, $c * $ml, $ml),
            substr($block, 2 * $ml + 2 * $kl + $c * $il, $il),
        ];
    }

    // ------------------------------------------------------------------
    // Datensätze
    // ------------------------------------------------------------------

    private function handschlagSenden(int $typ, string $inhalt): string
    {
        $n = chr($typ) . self::len3($inhalt) . $inhalt;
        $this->verlauf .= $n;
        return $this->satz(self::T_HANDSCHLAG, $n);
    }

    private function alarm(int $beschreibung, string $grund): string
    {
        $this->phase = 'fehler';
        $this->fehler = $grund;
        return $this->satz(self::T_ALARM, "\x02" . chr($beschreibung));
    }

    private function rohSatz(int $typ, string $inhalt): string
    {
        return chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt;
    }

    private function satz(int $typ, string $inhalt): string
    {
        if ($this->schreibSchluessel === null) {
            return $this->rohSatz($typ, $inhalt);
        }
        [$key, $mac, $iv] = $this->schreibSchluessel;
        $nr = pack('J', $this->schreibNr++);
        if (self::SUITEN[$this->suite][0] === 'gcm') {
            $explizit = $nr;
            $c = openssl_encrypt($inhalt, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv . $explizit, $tag, $nr . chr($typ) . self::VERSION . pack('n', strlen($inhalt)), 16);
            return $this->rohSatz($typ, $explizit . $c . $tag);
        }
        $m = hash_hmac('sha256', $nr . chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt, $mac, true);
        $klar = $inhalt . $m;
        $pad = 15 - (strlen($klar) % 16);
        $klar .= str_repeat(chr($pad), $pad + 1);
        $ivZufall = random_bytes(16);
        return $this->rohSatz($typ, $ivZufall . openssl_encrypt($klar, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $ivZufall));
    }

    private function entschluesseln(int $typ, string $satz): string
    {
        [$key, $mac, $iv] = $this->leseSchluessel;
        $nr = pack('J', $this->leseNr++);
        if (self::SUITEN[$this->suite][0] === 'gcm') {
            if (strlen($satz) < 24) {
                throw new EEBGWTlsEntschluesseln('record too short');
            }
            $klar = openssl_decrypt(substr($satz, 8, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv . substr($satz, 0, 8), substr($satz, -16),
                $nr . chr($typ) . self::VERSION . pack('n', strlen($satz) - 24));
            if ($klar === false) {
                throw new EEBGWTlsEntschluesseln('bad record (GCM)');
            }
            return $klar;
        }
        if (strlen($satz) < 64 || strlen($satz) % 16 !== 0) {
            throw new EEBGWTlsEntschluesseln('bad record length');
        }
        $klar = openssl_decrypt(substr($satz, 16), 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($satz, 0, 16));
        $pad = $klar === false ? 256 : ord($klar[strlen($klar) - 1]);
        $ok = $klar !== false && $pad + 33 <= strlen($klar) && substr($klar, -($pad + 1)) === str_repeat(chr($pad), $pad + 1);
        $inhalt = $ok ? substr($klar, 0, -($pad + 33)) : '';
        $m = $ok ? substr($klar, -($pad + 33), 32) : str_repeat("\x00", 32);
        $soll = hash_hmac('sha256', $nr . chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt, $mac, true);
        if (!$ok || !hash_equals($soll, $m)) {
            throw new EEBGWTlsEntschluesseln('bad record MAC');
        }
        return $inhalt;
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    private static function len3(string $s): string
    {
        return substr(pack('N', strlen($s)), 1);
    }

    private static function der(string $pem): string
    {
        return (string)base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
    }

    /** TLS-1.2-PRF mit SHA-256 (RFC 5246 Abschnitt 5). */
    public static function prf(string $geheim, string $etikett, string $saat, int $laenge): string
    {
        $saat = $etikett . $saat;
        $a = $saat;
        $out = '';
        while (strlen($out) < $laenge) {
            $a = hash_hmac('sha256', $a, $geheim, true);
            $out .= hash_hmac('sha256', $a . $saat, $geheim, true);
        }
        return substr($out, 0, $laenge);
    }
}

/** Entschlüsselung/MAC fehlgeschlagen (Alarm bad_record_mac). */
final class EEBGWTlsEntschluesseln extends RuntimeException
{
}
