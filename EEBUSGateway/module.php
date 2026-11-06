<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EEBGWJson.php';
require_once __DIR__ . '/../libs/EEBGWCert.php';
require_once __DIR__ . '/../libs/EEBGWSession.php';
require_once __DIR__ . '/../libs/EEBGWFormular.php';
require_once __DIR__ . '/../libs/EEBGWPairing.php';
require_once __DIR__ . '/../libs/EEBGWQr.php';
require_once __DIR__ . '/../libs/EEBGWTlsServer.php';

// ===========================================================================
// NRG-Stack EEBUSGateway
//
// Hält Zertifikat/SKI, die Kopplung mit einer Gegenstelle und die SHIP-
// Verbindung (Client-Rolle) über Symcons Client Socket. Der SPINE-Knoten wird
// aus den Anmeldungen der Kind-Instanzen gebaut; Fachlogik (z. B. § 14a)
// steckt ausschließlich in den Kindern. Schnittstelle zu den Kindern:
// .docs/kind-schnittstelle.md.
//
// Zwei Verbindungsrichtungen:
// - Gateway verbindet sich (Standard): TLS übernimmt der Client Socket, eigenes
//   Zertifikat als Client-Zertifikat, Zertifikat der Gegenstelle als einzige
//   Vertrauensbasis (Pinning). Geprüft an Symcon 9.0 gegen eebus-go controlbox.
// - Gerät verbindet sich zum Gateway (ab 0.5.0, z. B. Wolf Link): Server
//   Socket ohne SSL, TLS macht EEBGWTlsServer, Vertrauen über den SKI des
//   Client-Zertifikats; Ankündigung per DNS-SD (_ship._tcp) auf Knopfdruck.
// ===========================================================================

class EEBUSGateway extends IPSModule
{
    use EEBGWFormular;

    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SERVER_SOCKET = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    // Server Socket: Datenpaket „Erweitert (Socket)“ mit ClientIP/ClientPort/Type (0 Daten, 1 verbunden,
    // 2 getrennt), wie im NRG-Stack ModbusServer.
    private const DATA_VOM_SERVER = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
    private const DATA_ZUM_SERVER = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';
    private const RICHTUNG_CLIENT = 0;
    private const RICHTUNG_SERVER = 1;
    private const DNSSD = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const PAIRING_TAKT_MS = 30000;
    private const DATA_ZUM_IO = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const DATA_ZUM_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    private const DATA_ZUM_KIND = '{12D8B8A8-3EE7-491F-A68A-D632304165D4}';

    // Version der Kind-Schnittstelle (Anmeldung/Daten/Ereignisse), .docs/kind-schnittstelle.md.
    private const SCHNITTSTELLE = '1.2';

    private const TAKT_MS = 5000;
    // Kein SHIP-Abschluss binnen dieser Zeit (und keine ausstehende Freigabe): neu verbinden.
    private const AUFBAU_FRIST_S = 30;
    // In der Datenphase ohne jedes Lebenszeichen der Gegenstelle: neu verbinden.
    private const STILLE_FRIST_S = 180;
    // Erst nach dieser Zeit ohne Verbindung meldet die Instanz einen Fehlerstatus.
    private const STOERUNG_NACH_S = 300;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 0);
        // Optional: SKI der Gegenstelle laut Typenschild/App; dann wird ohne Rückfrage nur genau dieser akzeptiert.
        $this->RegisterPropertyString('ErwarteterSki', '');
        $this->RegisterPropertyBoolean('Aktiv', true);
        // Wer baut die Verbindung auf: 0 = das Gateway (Standard), 1 = das Gerät (Server-Rolle).
        $this->RegisterPropertyInteger('Richtung', self::RICHTUNG_CLIENT);
        // Server-Rolle: Port, auf dem das Gateway Verbindungen annimmt (4712 wie evcc).
        $this->RegisterPropertyInteger('ServerPort', 4712);
        // Formular: Aufbau (SUITE.md 3a): je Fachpanel Aufklapp-Zustand und Position.
        $this->RegisterPropertyString('FormLayout', '[]');

        $this->RegisterAttributeString('Zertifikat', '');
        // Privater Schlüssel im Attribut, nicht in einer Property (NRG-Stack-Konvention Zugangsdaten).
        $this->RegisterAttributeString('Schluessel', '');
        $this->RegisterAttributeString('EigenerSki', '');
        $this->RegisterAttributeString('GegenstelleZertifikat', '');
        $this->RegisterAttributeString('GegenstelleSki', '');
        $this->RegisterAttributeString('GegenstelleInfo', '{}');
        $this->RegisterAttributeString('Kandidat', '{}');
        $this->RegisterAttributeString('Anmeldungen', '{}');
        $this->RegisterAttributeString('Daten', '{}');
        $this->RegisterAttributeString('Stand', '{}');
        // SHIP Pairing Service: Geheimnis, Verlauf angenommener Nachweise, Suchstand, gefundene Adresse.
        $this->RegisterAttributeString('PairingGeheimnis', '');
        $this->RegisterAttributeString('PairingVerlauf', '[]');
        $this->RegisterAttributeString('PairingStand', '{}');
        $this->RegisterAttributeString('PairingZiel', '{}');
        $this->RegisterAttributeInteger('Eltern', 0);
        $this->registerFormAttributes();

        $this->RegisterTimer('Takt', 0, 'EEBGW_Takt($_IPS[\'TARGET\']);');
        // RequireParent legt den Client Socket nicht zuverlässig an (live 27.10.2026, wie RoboHub 0.4.2):
        // fehlt er nach 15 s noch, legt das Gateway ihn selbst an (VerbindungPruefen).
        $this->RegisterTimer('Verbindung', 0, 'EEBGW_VerbindungPruefen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Pairing', 0, 'EEBGW_PairingSuchen($_IPS[\'TARGET\']);');
        $this->RequireParent(self::CLIENT_SOCKET);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->MaintainVariable('Verbunden', $this->t('Connected'), VARIABLETYPE_BOOLEAN, '', 1, true);
        $this->MaintainVariable('Zustand', $this->t('State'), VARIABLETYPE_STRING, '', 2, true);

        $this->zertifikatSicherstellen();
        if ($this->elternID() === 0) {
            $this->SetTimerInterval('Verbindung', 15000);
        }
        $this->elternBeobachten();
        $this->elternKonfigurieren();
        $this->SetTimerInterval('Takt', $this->ReadPropertyBoolean('Aktiv') ? self::TAKT_MS : 0);
        $pairing = json_decode($this->ReadAttributeString('PairingStand'), true) ?: [];
        $this->SetTimerInterval('Pairing', !empty($pairing['aktiv']) ? self::PAIRING_TAKT_MS : 0);
        $this->SetBuffer('AktivSeit', (string)time());
        $this->statusAktualisieren();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('Eltern')) {
            if ((int)($Data[0] ?? 0) === IS_ACTIVE) {
                // Die Gegenstelle schließt die Verbindung, wenn das Upgrade nicht binnen 10 s kommt.
                $this->sitzungStarten();
            } else {
                $this->mitSitzung(function (EEBGWSession $s) {
                    $s->getrennt('client socket no longer active');
                    return '';
                });
            }
        }
    }

    // ------------------------------------------------------------------
    // Zertifikat und Gegenstelle
    // ------------------------------------------------------------------

    private function zertifikatSicherstellen(): void
    {
        if ($this->ReadAttributeString('Zertifikat') !== '' && $this->ReadAttributeString('Schluessel') !== '') {
            return;
        }
        try {
            $neu = EEBGWCert::erzeugen('NRG-Stack-EEBUSGateway-' . $this->InstanceID);
        } catch (Throwable $e) {
            $this->LogMessage($this->t('The certificate could not be created: %s', $this->fehlerText($e)), KL_ERROR);
            return;
        }
        $this->WriteAttributeString('Zertifikat', $neu['cert']);
        $this->WriteAttributeString('Schluessel', $neu['key']);
        $this->WriteAttributeString('EigenerSki', $neu['ski']);
        $this->LogMessage($this->t('New EEBUS certificate created, SKI %s', EEBGWCert::lesbar($neu['ski'])), KL_MESSAGE);
    }

    /**
     * EEBGW_GegenstellePruefen($id): string — Zertifikat der Gegenstelle abholen.
     * Ist ein erwarteter SKI eingetragen und stimmt er, wird sofort übernommen;
     * sonst wird der gefundene SKI zur Bestätigung angezeigt.
     */
    public function GegenstellePruefen(): string
    {
        $host = trim((string)$this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($host === '' || $port <= 0) {
            return '⛔ ' . $this->t('Please enter address and port of the remote device first and click “Apply”.');
        }
        $this->zertifikatSicherstellen();
        try {
            $g = EEBGWCert::gegenstelleAbholen($host, $port, $this->ReadAttributeString('Zertifikat'), $this->ReadAttributeString('Schluessel'));
        } catch (Throwable $e) {
            $this->LogMessage($this->t('Checking the remote device failed: %s', $this->fehlerText($e)), KL_WARNING);
            return '⛔ ' . $this->fehlerText($e);
        }
        $erwartet = EEBGWCert::normalisieren((string)$this->ReadPropertyString('ErwarteterSki'));
        $tls = $g['protokoll'] . ', ' . $g['chiffre'];
        if ($erwartet !== '') {
            if ($erwartet !== $g['ski']) {
                $this->LogMessage($this->t('SKI of the remote device does not match: expected %s, found %s', $erwartet, (string)$g['ski']), KL_WARNING);
                return '⛔ ' . $this->t('The SKI of the remote device does NOT match the one entered.') . "\n"
                    . $this->t('Entered: %s', EEBGWCert::lesbar($erwartet)) . "\n" . $this->t('Found: %s', EEBGWCert::lesbar($g['ski'])) . "\n"
                    . $this->t('Please check address and SKI. Nothing was applied.');
            }
            $this->gegenstelleFestlegen($g);
            return '✅ ' . $this->t('Remote device confirmed and applied (SKI %s, %s).', EEBGWCert::lesbar($g['ski']), $tls) . "\n"
                . $this->t('Now trust the SKI of this gateway in the remote device: %s', EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki')));
        }
        $this->WriteAttributeString('Kandidat', (string)json_encode($g));
        $this->UpdateFormField('KandidatPanel', 'visible', true);
        $this->UpdateFormField('KandidatZeile', 'caption', $this->t('SKI found: %s', EEBGWCert::lesbar($g['ski'])));
        return 'ℹ️ ' . $this->t('Remote device reached (%s).', $tls) . "\n" . $this->t('Its SKI is: %s', EEBGWCert::lesbar($g['ski'])) . "\n"
            . $this->t('Compare it with the SKI the device itself shows (app, web interface, type plate). If it matches, click “Trust this SKI” in the form.');
    }

    /** EEBGW_GegenstelleUebernehmen($id): string — den zuletzt geprüften SKI festlegen. */
    public function GegenstelleUebernehmen(): string
    {
        $g = json_decode($this->ReadAttributeString('Kandidat'), true);
        if (!is_array($g) || empty($g['cert'])) {
            return '⛔ ' . $this->t('There is no checked certificate yet. Please click “Check remote device” first.');
        }
        $this->gegenstelleFestlegen($g);
        $this->UpdateFormField('KandidatPanel', 'visible', false);
        if ($this->serverRolle()) {
            // Server-Rolle: Das Gerät hat sich schon gemeldet und vertraut dem Gateway; es verbindet sich selbst wieder.
            return '✅ ' . $this->t('Remote device applied (SKI %s). It connects again by itself with its next attempt (usually within a few minutes).', EEBGWCert::lesbar($g['ski']));
        }
        return '✅ ' . $this->t('Remote device applied (SKI %s). The gateway is connecting now.', EEBGWCert::lesbar($g['ski'])) . "\n"
            . $this->t('Trust the SKI of this gateway in the remote device: %s', EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki')));
    }

    private function gegenstelleFestlegen(array $g): void
    {
        $this->WriteAttributeString('GegenstelleZertifikat', (string)$g['cert']);
        $this->WriteAttributeString('GegenstelleSki', (string)$g['ski']);
        $this->WriteAttributeString('GegenstelleInfo', (string)json_encode(['geprueft' => time(), 'protokoll' => $g['protokoll'] ?? '', 'chiffre' => $g['chiffre'] ?? '']));
        $this->WriteAttributeString('Kandidat', '{}');
        $this->LogMessage($this->t('Remote device set, SKI %s', EEBGWCert::lesbar((string)$g['ski'])), KL_MESSAGE);
        $this->elternKonfigurieren();
        $this->statusAktualisieren();
    }

    // ------------------------------------------------------------------
    // SHIP Pairing Service (Kopplung über den Messstellenbetreiber)
    // ------------------------------------------------------------------

    /** Kopplungsdaten dieses Gateways: SKI, SHIP-ID, Fingerabdruck, Geheimnis, Text für den QR-Code. */
    private function pairingDaten(): array
    {
        $geheimnis = $this->ReadAttributeString('PairingGeheimnis');
        $fp = EEBGWPairing::fingerabdruck($this->ReadAttributeString('Zertifikat'));
        $ski = $this->ReadAttributeString('EigenerSki');
        return ['ski' => $ski, 'shipId' => $this->eigeneShipId(), 'fingerabdruck' => $fp, 'geheimnis' => $geheimnis,
            'text' => $geheimnis === '' ? '' : EEBGWPairing::qrText($ski, $this->eigeneShipId(), $fp, $geheimnis)];
    }

    /** QR-Code der Kopplungsdaten als PNG (data:-URI), leer ohne Geheimnis oder ohne GD. */
    private function pairingQr(string $text): string
    {
        if ($text === '' || !function_exists('imagepng')) {
            return '';
        }
        try {
            return EEBGWQr::pngDataUri($text, 4);
        } catch (Throwable $e) {
            $this->SendDebug('Pairing', 'QR: ' . $e->getMessage(), 0);
            return '';
        }
    }

    /**
     * EEBGW_PairingStarten($id): string — Geheimnis erzeugen (falls noch keins) und auf die
     * Ankündigung der Gegenstelle warten. Die Daten gibt der Nutzer an den Messstellenbetreiber.
     */
    public function PairingStarten(): string
    {
        $this->zertifikatSicherstellen();
        if ($this->ReadAttributeString('PairingGeheimnis') === '') {
            $this->WriteAttributeString('PairingGeheimnis', EEBGWPairing::neuesGeheimnis());
        }
        $this->pairingStandSetzen(['aktiv' => true, 'seit' => time(), 'trustId' => '', 'trustPar' => '',
            'vorlage' => 'Waiting for the remote device to announce itself (SHIP Pairing Service).', 'werte' => []]);
        $this->SetTimerInterval('Pairing', self::PAIRING_TAKT_MS);
        $d = $this->pairingDaten();
        $this->UpdateFormField('PairingDaten', 'value', $d['text']);
        $this->UpdateFormField('PairingQr', 'image', $this->pairingQr($d['text']));
        $this->UpdateFormField('PairingQr', 'visible', true);
        $this->UpdateFormField('PairingGeheimnisFeld', 'value', $d['geheimnis']);
        $this->UpdateFormField('PairingZeile', 'caption', $this->pairingZeile());
        return '✅ ' . $this->t('The gateway is now waiting for the remote device.') . "\n"
            . $this->t('Give these pairing data to your metering point operator (or the installer of the device):') . "\n" . $d['text'];
    }

    /** EEBGW_PairingBeenden($id): string — nicht mehr auf eine Ankündigung warten. */
    public function PairingBeenden(): string
    {
        $this->pairingStandSetzen(['aktiv' => false, 'vorlage' => 'Waiting stopped.', 'werte' => []]);
        $this->SetTimerInterval('Pairing', 0);
        $this->UpdateFormField('PairingZeile', 'caption', $this->pairingZeile());
        return 'ℹ️ ' . $this->t('The gateway no longer waits for a pairing announcement.');
    }

    private function pairingStandSetzen(array $werte): void
    {
        $stand = json_decode($this->ReadAttributeString('PairingStand'), true) ?: [];
        $this->WriteAttributeString('PairingStand', (string)json_encode(array_merge($stand, $werte)));
    }

    private function pairingZeile(): string
    {
        $stand = json_decode($this->ReadAttributeString('PairingStand'), true) ?: [];
        if (empty($stand['vorlage'])) {
            return 'ℹ️ ' . $this->t('Pairing via the SHIP Pairing Service has not been prepared yet.');
        }
        $zeichen = !empty($stand['gekoppelt']) ? '✅ ' : (!empty($stand['aktiv']) ? '⏳ ' : 'ℹ️ ');
        $text = $this->t((string)$stand['vorlage'], ...array_map('strval', (array)($stand['werte'] ?? [])));
        return $zeichen . $text . (!empty($stand['zuletzt']) ? ' ' . $this->t('(last search %s)', date($this->t('H:i:s'), (int)$stand['zuletzt'])) : '');
    }

    private function dnssdInstanz(): int
    {
        $liste = function_exists('IPS_GetInstanceListByModuleID') ? IPS_GetInstanceListByModuleID(self::DNSSD) : [];
        return (int)($liste[0] ?? 0);
    }

    /** DNS-SD-Abfrage: alle Ankündigungen eines Diensttyps mit Host, Port, IPv4 und TXT. */
    private function dnssdSuchen(int $dnssd, string $typ): array
    {
        $out = [];
        try {
            foreach ((array)ZC_QueryServiceType($dnssd, $typ, '') as $e) {
                foreach ((array)ZC_QueryService($dnssd, (string)$e['Name'], (string)$e['Type'], (string)$e['Domain']) as $d) {
                    $out[] = $d;
                }
            }
        } catch (Throwable $ex) {
            $this->SendDebug('Pairing', 'DNS-SD: ' . $ex->getMessage(), 0);
        }
        return $out;
    }

    /** Timer: Ankündigungen der Gegenstelle suchen, Nachweis prüfen, danach koppeln. */
    public function PairingSuchen(): void
    {
        $stand = json_decode($this->ReadAttributeString('PairingStand'), true) ?: [];
        if (empty($stand['aktiv'])) {
            $this->SetTimerInterval('Pairing', 0);
            return;
        }
        $dnssd = $this->dnssdInstanz();
        if ($dnssd === 0 || !function_exists('ZC_QueryServiceType')) {
            $this->pairingStandSetzen(['vorlage' => 'No DNS-SD instance found in Symcon; the gateway cannot search for the remote device.', 'werte' => [], 'zuletzt' => time()]);
            return;
        }
        $d = $this->pairingDaten();
        $trustId = (string)($stand['trustId'] ?? '');
        $trustPar = (string)($stand['trustPar'] ?? '');
        if ($trustId === '') {
            $verlauf = json_decode($this->ReadAttributeString('PairingVerlauf'), true) ?: [];
            $grund = '';
            foreach ($this->dnssdSuchen($dnssd, '_shippairing._tcp') as $eintrag) {
                $txt = EEBGWPairing::txtNormalisieren((array)($eintrag['TXTRecords'] ?? []));
                if ($txt['forId'] !== $d['shipId']) {
                    continue;   // gilt einem anderen Gerät
                }
                $p = EEBGWPairing::pruefen($txt, $d['geheimnis'], $d['shipId'], $d['fingerabdruck'], $verlauf);
                if (!$p['ok']) {
                    // Nur bei neuem Grund protokollieren, nicht alle 30 s dasselbe.
                    if (($stand['vorlage'] ?? '') !== $p['grund']) {
                        $this->LogMessage($this->t('Pairing announcement rejected: %s', $this->t($p['grund'])), KL_WARNING);
                    }
                    $grund = $p['grund'];
                    continue;
                }
                $verlauf[] = strtoupper($txt['digest']);
                $this->WriteAttributeString('PairingVerlauf', (string)json_encode(array_slice($verlauf, -EEBGWPairing::VERLAUF)));
                $trustId = $txt['trustId'];
                $trustPar = strtoupper($txt['trustPar']);
                $this->pairingStandSetzen(['trustId' => $trustId, 'trustPar' => $trustPar]);
                $this->LogMessage($this->t('Pairing proof of %s is valid.', $trustId), KL_MESSAGE);
                break;
            }
            if ($trustId === '') {
                $this->pairingStandSetzen(['vorlage' => $grund !== '' ? $grund : 'Waiting for the remote device to announce itself (SHIP Pairing Service).', 'werte' => [], 'zuletzt' => time()]);
                return;
            }
        }
        // Gültig: die SHIP-Ankündigung derselben Gegenstelle suchen und dorthin verbinden.
        $host = '';
        $port = 0;
        foreach ($this->dnssdSuchen($dnssd, '_ship._tcp') as $eintrag) {
            $txt = [];
            foreach ((array)($eintrag['TXTRecords'] ?? []) as $zeile) {
                if (strpos((string)$zeile, '=') !== false) {
                    [$k, $v] = explode('=', (string)$zeile, 2);
                    $txt[strtolower($k)] = $v;
                }
            }
            if (($txt['id'] ?? '') === $trustId) {
                $host = (string)(((array)($eintrag['IPv4'] ?? []))[0] ?? ($eintrag['Host'] ?? ''));
                $port = (int)($eintrag['Port'] ?? 0);
                break;
            }
        }
        if ($host === '' || $port <= 0) {
            $this->pairingStandSetzen(['vorlage' => 'Pairing proof valid; waiting for the SHIP service of %s.', 'werte' => [$trustId], 'zuletzt' => time()]);
            return;
        }
        try {
            $g = EEBGWCert::gegenstelleAbholen($host, $port, $this->ReadAttributeString('Zertifikat'), $this->ReadAttributeString('Schluessel'));
        } catch (Throwable $e) {
            $this->pairingStandSetzen(['vorlage' => 'Remote device found at %s, but not reachable: %s', 'werte' => [$host . ':' . $port, $this->fehlerText($e)], 'zuletzt' => time()]);
            return;
        }
        if (EEBGWPairing::fingerabdruck($g['cert']) !== $trustPar) {
            $this->pairingStandSetzen(['vorlage' => 'The certificate at %s does not match the pairing announcement; not trusted.', 'werte' => [$host . ':' . $port], 'zuletzt' => time()]);
            $this->LogMessage($this->t('The certificate at %s does not match the pairing announcement; not trusted.', $host . ':' . $port), KL_WARNING);
            return;
        }
        $erwartet = EEBGWCert::normalisieren((string)$this->ReadPropertyString('ErwarteterSki'));
        if ($erwartet !== '' && $erwartet !== $g['ski']) {
            $this->pairingStandSetzen(['vorlage' => 'The paired device has a different SKI than the one entered (%s).', 'werte' => [EEBGWCert::lesbar($g['ski'])], 'zuletzt' => time()]);
            return;
        }
        $this->WriteAttributeString('PairingZiel', (string)json_encode(['host' => $host, 'port' => $port, 'shipId' => $trustId]));
        $this->pairingStandSetzen(['aktiv' => false, 'gekoppelt' => time(), 'vorlage' => 'Paired with %s at %s.', 'werte' => [$trustId, $host . ':' . $port], 'zuletzt' => time()]);
        $this->SetTimerInterval('Pairing', 0);
        $this->LogMessage($this->t('Paired with %s at %s.', $trustId, $host . ':' . $port), KL_MESSAGE);
        $this->gegenstelleFestlegen($g);
    }

    /**
     * EEBGW_ZertifikatErneuern($id): string — neues eigenes Zertifikat. Danach muss der neue
     * SKI in der Gegenstelle erneut freigegeben werden.
     */
    public function ZertifikatErneuern(): string
    {
        $this->WriteAttributeString('Zertifikat', '');
        $this->WriteAttributeString('Schluessel', '');
        $this->zertifikatSicherstellen();
        $this->elternKonfigurieren();
        $ski = EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki'));
        $this->UpdateFormField('EigenerSkiFeld', 'value', $ski);
        return '✅ ' . $this->t('New certificate created. New SKI: %s', $ski) . "\n" . $this->t('Important: trust this SKI in the remote device again, the old one is no longer valid.');
    }

    // ------------------------------------------------------------------
    // Elterninstanz (Client Socket)
    // ------------------------------------------------------------------

    /** Timer: fehlt der Client Socket noch, selbst anlegen und verbinden. */
    public function VerbindungPruefen(): void
    {
        $this->SetTimerInterval('Verbindung', 0);
        if ($this->elternID() !== 0) {
            return;
        }
        $server = $this->serverRolle();
        $id = IPS_CreateInstance($server ? self::SERVER_SOCKET : self::CLIENT_SOCKET);
        // Inzwischen doch verbunden (z. B. über die Konsole)? Dann den eigenen wieder entfernen.
        if ($this->elternID() !== 0 || !IPS_ConnectInstance($this->InstanceID, $id)) {
            IPS_DeleteInstance($id);
            return;
        }
        if ($server) {
            IPS_SetName($id, $this->t('EEBUS gateway server socket (%s)', IPS_GetName($this->InstanceID)));
            $this->LogMessage($this->t('Server socket #%s created and connected.', (string)$id), KL_MESSAGE);
        } else {
            IPS_SetName($id, $this->t('EEBUS gateway client socket (%s)', IPS_GetName($this->InstanceID)));
            $this->LogMessage($this->t('Client socket #%s created and connected.', (string)$id), KL_MESSAGE);
        }
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Fehlertext in der Sprache der Konsole (EEBGWFehler trägt eine übersetzbare Vorlage). */
    private function fehlerText(Throwable $e): string
    {
        return $e instanceof EEBGWFehler ? $this->t($e->vorlage, ...$e->werte) : $e->getMessage();
    }

    /** Adresse der Gegenstelle: Eingabe im Formular, sonst die per Pairing gefundene. */
    private function zielHost(): string
    {
        $host = trim((string)$this->ReadPropertyString('Host'));
        if ($host !== '') {
            return $host;
        }
        $ziel = json_decode($this->ReadAttributeString('PairingZiel'), true) ?: [];
        return (string)($ziel['host'] ?? '');
    }

    private function zielPort(): int
    {
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($port > 0 && trim((string)$this->ReadPropertyString('Host')) !== '') {
            return $port;
        }
        $ziel = json_decode($this->ReadAttributeString('PairingZiel'), true) ?: [];
        return (int)($ziel['port'] ?? ($port > 0 ? $port : 0));
    }

    private function serverRolle(): bool
    {
        return (int)$this->ReadPropertyInteger('Richtung') === self::RICHTUNG_SERVER;
    }

    /** Passt die Elterninstanz zur gewählten Richtung? (0 = keine Elterninstanz) */
    private function elternPasst(): bool
    {
        $eltern = $this->elternID();
        if ($eltern === 0) {
            return true;
        }
        $modul = (string)(IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] ?? '');
        return $modul === ($this->serverRolle() ? self::SERVER_SOCKET : self::CLIENT_SOCKET);
    }

    /** SHIP-ID dieses Gateways (aus dem SKI, damit stabil). */
    private function eigeneShipId(): string
    {
        return 'NRG-Stack-EEBUSGateway-' . substr($this->ReadAttributeString('EigenerSki'), 0, 12);
    }

    private function elternID(): int
    {
        return (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function elternBeobachten(): void
    {
        $eltern = $this->elternID();
        $vorher = $this->ReadAttributeInteger('Eltern');
        if ($vorher !== $eltern && $vorher > 0) {
            $this->UnregisterMessage($vorher, IM_CHANGESTATUS);
        }
        $this->WriteAttributeInteger('Eltern', $eltern);
        if ($eltern > 0) {
            $this->RegisterMessage($eltern, IM_CHANGESTATUS);
        }
    }

    /** Client Socket nach dem eigenen Stand einstellen (nur bei Abweichung, ohne Inhalte zu protokollieren). */
    private function elternKonfigurieren(): void
    {
        $eltern = $this->elternID();
        if ($eltern === 0 || !$this->elternPasst()) {
            return;
        }
        if ($this->serverRolle()) {
            // TLS macht das Gateway selbst (EEBGWTlsServer), der Server Socket reicht nur Bytes durch.
            $soll = ['Open' => $this->ReadPropertyBoolean('Aktiv'), 'Port' => (int)$this->ReadPropertyInteger('ServerPort'), 'UseSSL' => false];
            $ist = json_decode((string)IPS_GetConfiguration($eltern), true) ?: [];
            foreach ($soll as $k => $v) {
                if (($ist[$k] ?? null) !== $v) {
                    IPS_SetConfiguration($eltern, (string)json_encode($soll));
                    IPS_ApplyChanges($eltern);
                    return;
                }
            }
            return;
        }
        $bereit = $this->ReadPropertyBoolean('Aktiv') && $this->zielHost() !== ''
            && $this->zielPort() > 0 && $this->ReadAttributeString('GegenstelleZertifikat') !== ''
            && $this->ReadAttributeString('Zertifikat') !== '';
        $soll = ['Open' => $bereit];
        if ($bereit) {
            $soll += [
                'Host' => $this->zielHost(),
                'Port' => $this->zielPort(),
                'UseSSL' => true,
                'UseCertificate' => true,
                // Symcon erwartet die PEM-Dateien Base64-kodiert (im Prototyp live geprüft).
                'Certificate' => base64_encode($this->ReadAttributeString('Zertifikat')),
                'PrivateKey' => base64_encode($this->ReadAttributeString('Schluessel')),
                'CertificateAuthority' => base64_encode($this->ReadAttributeString('GegenstelleZertifikat')),
                'VerifyPeer' => true,
                // SHIP-Zertifikate tragen keinen Hostnamen; Vertrauen über das gepinnte Zertifikat.
                'VerifyHost' => false,
            ];
        }
        $ist = json_decode((string)IPS_GetConfiguration($eltern), true) ?: [];
        $abweichung = false;
        foreach ($soll as $k => $v) {
            if (!array_key_exists($k, $ist) || $ist[$k] !== $v) {
                $abweichung = true;
                break;
            }
        }
        if (!$abweichung) {
            return;
        }
        IPS_SetConfiguration($eltern, (string)json_encode($soll));
        IPS_ApplyChanges($eltern);
    }

    /** EEBGW_NeuVerbinden($id): string — Verbindung schließen und neu aufbauen. */
    public function NeuVerbinden(): string
    {
        $eltern = $this->elternID();
        if ($eltern === 0) {
            return '⛔ ' . $this->t('No client socket connected.');
        }
        if ($this->serverRolle()) {
            // Das Gerät verbindet sich selbst wieder; nur die bestehende Verbindung trennen.
            $this->mitSitzung(function (EEBGWSession $s) {
                $s->getrennt('reconnect requested');
                $this->serverTrennen();
                return '';
            });
            return '✅ ' . $this->t('The connection was closed. The device connects again by itself.');
        }
        if ($this->ReadAttributeString('GegenstelleZertifikat') === '') {
            return '⛔ ' . $this->t('The remote device has not been checked yet. Please click “Check remote device” first.');
        }
        IPS_SetProperty($eltern, 'Open', false);
        IPS_ApplyChanges($eltern);
        $this->elternKonfigurieren();
        return '✅ ' . $this->t('The connection is being re-established. The state appears under “State” in a few seconds.');
    }

    // ------------------------------------------------------------------
    // Sitzung
    // ------------------------------------------------------------------

    private function sitzungLaden(): EEBGWSession
    {
        $roh = $this->GetBuffer('Sitzung');
        $s = $roh !== '' ? @unserialize($roh, ['allowed_classes' => [EEBGWSession::class, stdClass::class]]) : null;
        if (!($s instanceof EEBGWSession)) {
            $s = new EEBGWSession();
        }
        $ski = $this->ReadAttributeString('EigenerSki');
        $s->host = $this->zielHost();
        $s->port = $this->zielPort();
        $s->localShipId = $this->eigeneShipId();
        $s->localDevice = 'd:_n:NRG-Stack_EEBUSGateway-' . substr($ski, 0, 12);
        $s->model = EEBGWSession::buildModel(json_decode($this->ReadAttributeString('Anmeldungen'), true) ?: []);
        $s->data = json_decode($this->ReadAttributeString('Daten'), true) ?: [];
        return $s;
    }

    /**
     * Sitzung laden, $aktion ausführen (liefert zu sendende Bytes), senden, Ereignisse
     * verteilen, speichern. Semaphore, weil ReceiveData/Timer/Kinder gleichzeitig kommen können.
     */
    private function mitSitzung(callable $aktion)
    {
        $sem = 'EEBGW_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 5000)) {
            $this->SendDebug('Session', 'semaphore busy, call dropped', 0);
            return null;
        }
        $events = [];
        $rueckgabe = null;
        try {
            $s = $this->sitzungLaden();
            $this->tls = $this->serverRolle() ? $this->tlsLaden() : null;
            $datenVorher = $s->data;
            $ergebnis = $aktion($s);
            $out = is_string($ergebnis) ? $ergebnis : (string)($ergebnis['out'] ?? '');
            if ($out !== '') {
                $this->senden($out);
            }
            foreach ($s->drainLog() as $zeile) {
                $this->SendDebug('SHIP/SPINE', $zeile, 0);
            }
            $events = $s->drainEvents();
            if ($s->data != $datenVorher) {
                $this->WriteAttributeString('Daten', (string)json_encode($s->data));
            }
            $this->SetBuffer('Sitzung', serialize($s));
            if ($this->serverRolle()) {
                $this->SetBuffer('Tls', $this->tls === null ? '' : serialize($this->tls));
            }
            $this->standSpeichern($s);
            $rueckgabe = is_array($ergebnis) ? ($ergebnis['rueckgabe'] ?? null) : null;
        } catch (Throwable $e) {
            $this->LogMessage($this->t('Error in the EEBUS session: %s', $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'), KL_ERROR);
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        // Erst nach Freigabe verteilen: Kinder antworten evtl. sofort mit SetData (ForwardData → mitSitzung).
        foreach ($events as $e) {
            $this->ereignisVerteilen($e);
        }
        return $rueckgabe;
    }

    private function sitzungStarten(): void
    {
        // Server-Rolle: Die Sitzung beginnt erst, wenn sich das Gerät verbindet (serverEmpfangen).
        if ($this->serverRolle() || $this->ReadAttributeString('GegenstelleZertifikat') === '') {
            return;
        }
        $this->mitSitzung(function (EEBGWSession $s) {
            return $s->start();
        });
    }

    private function senden(string $bytes): void
    {
        if ($this->serverRolle()) {
            if ($this->tls !== null) {
                $this->serverSendenRoh($this->tls->senden($bytes));
            }
            return;
        }
        try {
            // Symcons Datenfluss trägt Bytes als UTF-8-kodiertes Latin-1 (Verbund-Muster RoboHub/Fernwirk101).
            $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_IO, 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
        } catch (Throwable $e) {
            $this->LogMessage($this->t('Sending to the client socket failed: %s', $e->getMessage()), KL_WARNING);
        }
    }

    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
        if (is_array($paket) && ($paket['DataID'] ?? '') === self::DATA_VOM_SERVER) {
            $this->serverEmpfangen($paket);
            return '';
        }
        if (!is_array($paket) || !isset($paket['Buffer'])) {
            return '';
        }
        $bytes = mb_convert_encoding((string)$paket['Buffer'], 'ISO-8859-1', 'UTF-8');
        $this->mitSitzung(function (EEBGWSession $s) use ($bytes) {
            if ($s->phase === 'idle') {
                return '';
            }
            return $s->feed($bytes, time());
        });
        return '';
    }

    /** Timer: eigener Heartbeat, Überwachung, Wiederverbindung. */
    public function Takt(): void
    {
        $neuVerbinden = false;
        $this->mitSitzung(function (EEBGWSession $s) use (&$neuVerbinden) {
            $jetzt = time();
            $out = $s->tick($jetzt);
            $stand = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
            $eltern = $this->elternID();
            $elternAktiv = $eltern > 0 && IPS_GetInstance($eltern)['InstanceStatus'] === IS_ACTIVE;
            if ($this->serverRolle()) {
                // Server-Rolle: Das Gerät verbindet sich selbst; hängende oder stille Verbindungen trennen.
                $haengt = !$s->istVerbunden() && $s->phase !== 'idle' && !$s->vertrauenAusstehend
                    && $jetzt - (int)($stand['phaseSeit'] ?? $jetzt) > self::AUFBAU_FRIST_S;
                $still = $s->istVerbunden() && $s->letzteDaten > 0 && $jetzt - $s->letzteDaten > self::STILLE_FRIST_S;
                if ($haengt || $still) {
                    $s->getrennt($still ? 'remote device silent' : 'connection setup stuck');
                    $this->serverTrennen();
                }
                return $out;
            }
            if ($elternAktiv && in_array($s->phase, ['idle', 'closed', 'error'], true)) {
                // Socket offen, aber keine (oder eine beendete) Sitzung: neu aufbauen.
                $neuVerbinden = true;
            } elseif ($elternAktiv && !$s->istVerbunden() && !$s->vertrauenAusstehend
                && $jetzt - (int)($stand['phaseSeit'] ?? $jetzt) > self::AUFBAU_FRIST_S) {
                $neuVerbinden = true;
            } elseif ($s->istVerbunden() && $s->letzteDaten > 0 && $jetzt - $s->letzteDaten > self::STILLE_FRIST_S) {
                $this->LogMessage($this->t('Remote device silent for %s s — re-establishing the connection.', (string)($jetzt - $s->letzteDaten)), KL_WARNING);
                $neuVerbinden = true;
            }
            if ($s->istVerbunden()) {
                $this->SetBuffer('Versuche', '0');
                $this->SetBuffer('NaechsterVersuch', '0');
            }
            return $out;
        });
        // Wachsende Wartezeit zwischen Versuchen: Live gesehen (27.10.2026), dass eine Gegenstelle eine
        // abgerissene Verbindung noch für aktiv hält und neue Versuche ablehnt — Anklopfen alle 5 s hilft
        // dann nicht, sondern hält sie nur beschäftigt.
        if ($neuVerbinden && $this->ReadPropertyBoolean('Aktiv') && time() >= (int)$this->GetBuffer('NaechsterVersuch')) {
            $versuche = (int)$this->GetBuffer('Versuche') + 1;
            $this->SetBuffer('Versuche', (string)$versuche);
            $this->SetBuffer('NaechsterVersuch', (string)(time() + self::wartezeit($versuche)));
            $this->NeuVerbinden();
        }
        $this->statusAktualisieren();
    }

    // ------------------------------------------------------------------
    // Server-Rolle: das Gerät verbindet sich zum Gateway
    // ------------------------------------------------------------------

    /** @var EEBGWTlsServer|null TLS der aktuellen Verbindung (nur innerhalb von mitSitzung geladen) */
    private $tls = null;

    private function tlsLaden(): ?EEBGWTlsServer
    {
        $roh = $this->GetBuffer('Tls');
        $t = $roh !== '' ? @unserialize($roh, ['allowed_classes' => [EEBGWTlsServer::class]]) : null;
        return $t instanceof EEBGWTlsServer ? $t : null;
    }

    private function serverClient(): array
    {
        return json_decode($this->GetBuffer('ServerClient'), true) ?: [];
    }

    private function serverSendenRoh(string $bytes, int $typ = 0): void
    {
        $c = $this->serverClient();
        if (($bytes === '' && $typ === 0) || empty($c['ip'])) {
            return;
        }
        try {
            $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_SERVER, 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1'),
                'ClientIP' => $c['ip'], 'ClientPort' => (int)$c['port'], 'Type' => $typ]));
        } catch (Throwable $e) {
            $this->LogMessage($this->t('Sending to the server socket failed: %s', $e->getMessage()), KL_WARNING);
        }
    }

    /** Verbindung zum Gerät beenden (close_notify, dann Server Socket trennen lassen). */
    private function serverTrennen(): void
    {
        if ($this->tls !== null) {
            $this->serverSendenRoh($this->tls->schliessen());
        }
        $this->serverSendenRoh('', 2);
        $this->tls = null;
        $this->SetBuffer('ServerClient', '');
    }

    /** Paket vom Server Socket: neue Verbindung, Daten oder Trennung. */
    private function serverEmpfangen(array $p): void
    {
        $ip = (string)($p['ClientIP'] ?? '');
        $port = (int)($p['ClientPort'] ?? 0);
        $typ = (int)($p['Type'] ?? 0);
        $bytes = mb_convert_encoding((string)($p['Buffer'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $this->mitSitzung(function (EEBGWSession $s) use ($ip, $port, $typ, $bytes) {
            $aktuell = $this->serverClient();
            $istAktuell = ($aktuell['ip'] ?? '') === $ip && (int)($aktuell['port'] ?? 0) === $port;
            if ($typ === 2) {
                if ($istAktuell) {
                    $s->getrennt('device closed the connection');
                    $this->tls = null;
                    $this->SetBuffer('ServerClient', '');
                }
                return '';
            }
            if ($typ === 1 || !$istAktuell) {
                // Neue Verbindung ersetzt eine bestehende (ein Gateway hält genau ein Gerät).
                if (!empty($aktuell['ip']) && !$istAktuell) {
                    $s->getrennt('replaced by a new connection from ' . $ip);
                    $this->serverTrennen();
                }
                $this->SetBuffer('ServerClient', (string)json_encode(['ip' => $ip, 'port' => $port, 'seit' => time()]));
                $t = new EEBGWTlsServer();
                $t->zertifikat = $this->ReadAttributeString('Zertifikat');
                $t->schluessel = $this->ReadAttributeString('Schluessel');
                $this->tls = $t;
                $this->SendDebug('Server', 'connection from ' . $ip . ':' . $port, 0);
                if ($typ === 1) {
                    return '';
                }
            }
            if ($this->tls === null || $bytes === '') {
                return '';
            }
            $warVerbunden = $this->tls->verbunden();
            $r = $this->tls->feed($bytes);
            $this->serverSendenRoh($r['out']);
            if (in_array($this->tls->phase, ['fehler', 'zu'], true)) {
                $this->SendDebug('TLS', 'end: ' . $this->tls->fehler, 0);
                if ($this->tls->phase === 'fehler') {
                    $this->LogMessage($this->t('TLS connection from %s failed: %s', $ip, $this->tls->fehler), KL_WARNING);
                }
                $s->getrennt('TLS ' . $this->tls->phase);
                $this->serverTrennen();
                return '';
            }
            if (!$warVerbunden && $this->tls->verbunden()) {
                if (!$this->serverVertrauen($this->tls)) {
                    $this->serverTrennen();
                    return '';
                }
                $s->startServer();
            }
            return $r['daten'] === '' ? '' : $s->feed($r['daten'], time());
        });
    }

    /**
     * Darf sich dieses Gerät verbinden? Vertraut wird dem festgelegten Gerät (SKI und Zertifikat) oder dem
     * eingetragenen erwarteten SKI. Ein unbekanntes Gerät wird zur Bestätigung angezeigt, wie beim Prüfen.
     */
    private function serverVertrauen(EEBGWTlsServer $t): bool
    {
        $ski = $t->clientSki;
        $gesetzt = $this->ReadAttributeString('GegenstelleSki');
        $erwartet = EEBGWCert::normalisieren((string)$this->ReadPropertyString('ErwarteterSki'));
        $info = ['cert' => $t->clientZertifikat, 'ski' => $ski, 'protokoll' => 'TLSv1.2',
            'chiffre' => $t->suite === 0xC023 ? 'ECDHE-ECDSA-AES128-SHA256' : 'ECDHE-ECDSA-AES128-GCM-SHA256'];
        if ($gesetzt !== '' && $ski === $gesetzt) {
            if ($erwartet !== '' && $erwartet !== $ski) {
                return false;
            }
            // Zertifikat gewechselt, SKI gleich: SKI ist die Vertrauensbasis (SHIP 12.2), Zertifikat nachführen.
            if ($t->clientZertifikat !== $this->ReadAttributeString('GegenstelleZertifikat')) {
                $this->WriteAttributeString('GegenstelleZertifikat', $t->clientZertifikat);
            }
            return true;
        }
        if ($gesetzt === '' && $erwartet !== '' && $ski === $erwartet) {
            $this->gegenstelleFestlegen($info);
            return true;
        }
        $kandidat = json_decode($this->ReadAttributeString('Kandidat'), true) ?: [];
        if (($kandidat['ski'] ?? '') !== $ski) {
            $this->WriteAttributeString('Kandidat', (string)json_encode($info));
            $this->LogMessage($this->t('Device with SKI %s wants to connect. If this is your device, trust it in the form of the gateway.', EEBGWCert::lesbar($ski)), KL_NOTIFY);
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Ankündigung per DNS-SD (Server-Rolle)
    // ------------------------------------------------------------------

    private function ankuendigungName(): string
    {
        return $this->eigeneShipId();
    }

    private function ankuendigungEintrag(): array
    {
        $txt = ['txtvers=1', 'id=' . $this->eigeneShipId(), 'path=/ship/', 'ski=' . $this->ReadAttributeString('EigenerSki'),
            'register=' . ($this->ReadAttributeString('GegenstelleSki') === '' ? 'true' : 'false'),
            'brand=NRG-Stack', 'model=EEBUSGateway', 'type=EnergyManagementSystem'];
        return ['Name' => $this->ankuendigungName(), 'RegType' => '_ship._tcp', 'Domain' => '', 'Host' => '',
            'Port' => (int)$this->ReadPropertyInteger('ServerPort'),
            'TXTRecords' => array_map(function ($v) {
                return ['Value' => $v];
            }, $txt)];
    }

    private function ankuendigungZeile(): string
    {
        $dnssd = $this->dnssdInstanz();
        if ($dnssd === 0) {
            return '⛔ ' . $this->t('No DNS-SD instance found in Symcon.');
        }
        [, $eigen] = $this->ankuendigungStand($dnssd);
        if ($eigen === null) {
            return 'ℹ️ ' . $this->t('Not announced yet.');
        }
        return $eigen == $this->ankuendigungEintrag()
            ? '✅ ' . $this->t('Announced as %s on port %s.', (string)$eigen['Name'], (string)$eigen['Port'])
            : '⚠️ ' . $this->t('Announced, but with outdated data - click “Announce in the network” again.');
    }

    /** Liste der DNS-SD-Instanz ohne den eigenen Eintrag, und ob er da war (und wie). */
    private function ankuendigungStand(int $dnssd): array
    {
        $liste = json_decode((string)@IPS_GetProperty($dnssd, 'Services'), true) ?: [];
        $eigen = null;
        $rest = [];
        foreach ($liste as $e) {
            if (($e['Name'] ?? '') === $this->ankuendigungName() && ($e['RegType'] ?? '') === '_ship._tcp') {
                $eigen = $e;
            } else {
                $rest[] = $e;
            }
        }
        return [$rest, $eigen];
    }

    /**
     * EEBGW_AnkuendigungEintragen($id): string — trägt das Gateway in die DNS-SD-Instanz von Symcon ein
     * (_ship._tcp), damit das Gerät es in seiner App findet. Ändert eine fremde Instanz, deshalb nur auf
     * Knopfdruck mit Rückfrage.
     */
    public function AnkuendigungEintragen(): string
    {
        $dnssd = $this->dnssdInstanz();
        if ($dnssd === 0) {
            return '⛔ ' . $this->t('No DNS-SD instance found in Symcon.');
        }
        [$rest, $eigen] = $this->ankuendigungStand($dnssd);
        $neu = $this->ankuendigungEintrag();
        if ($eigen == $neu) {
            return 'ℹ️ ' . $this->t('The gateway is already announced in the network.');
        }
        $rest[] = $neu;
        IPS_SetProperty($dnssd, 'Services', (string)json_encode($rest));
        IPS_ApplyChanges($dnssd);
        $this->LogMessage($this->t('Announced in the network as %s on port %s (DNS-SD instance #%s).', $neu['Name'], (string)$neu['Port'], (string)$dnssd), KL_MESSAGE);
        return '✅ ' . $this->t('The gateway is now announced in the network. Search for EEBUS devices in the app of your device and trust “%s”.', $neu['Name']);
    }

    /** EEBGW_AnkuendigungEntfernen($id): string — eigenen Eintrag aus der DNS-SD-Instanz nehmen. */
    public function AnkuendigungEntfernen(): string
    {
        $dnssd = $this->dnssdInstanz();
        if ($dnssd === 0) {
            return '⛔ ' . $this->t('No DNS-SD instance found in Symcon.');
        }
        [$rest, $eigen] = $this->ankuendigungStand($dnssd);
        if ($eigen === null) {
            return 'ℹ️ ' . $this->t('The gateway is not announced.');
        }
        IPS_SetProperty($dnssd, 'Services', (string)json_encode($rest));
        IPS_ApplyChanges($dnssd);
        return '✅ ' . $this->t('Announcement removed.');
    }

    /** Wartezeit vor dem nächsten Verbindungsversuch: 5, 10, 20, … s, höchstens 5 Minuten. */
    private static function wartezeit(int $versuche): int
    {
        return (int)min(300, 5 * (2 ** min(max($versuche - 1, 0), 6)));
    }

    private function standSpeichern(EEBGWSession $s): void
    {
        $alt = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        $neu = [
            'phase' => $s->phase,
            'phaseSeit' => ($alt['phase'] ?? '') === $s->phase ? (int)($alt['phaseSeit'] ?? time()) : time(),
            'fehler' => $s->fehler,
            'fehlerVorlage' => $s->fehlerVorlage,
            'fehlerWerte' => $s->fehlerWerte,
            'vertrauenAusstehend' => $s->vertrauenAusstehend,
            'remoteShipId' => $s->remoteShipId,
            'remoteDevice' => $s->remoteDevice,
            'remoteUseCases' => $s->remoteUseCases,
            'verbundenSeit' => $s->verbundenSeit,
            'letzteVerbindung' => $s->istVerbunden() ? time() : (int)($alt['letzteVerbindung'] ?? 0),
            'remoteHeartbeat' => $s->lastRemoteHb,
            'heartbeatVerloren' => $s->hbVerlorenGemeldet,
        ];
        if ($neu != $alt) {
            $this->WriteAttributeString('Stand', (string)json_encode($neu));
        }
        $verbunden = $s->istVerbunden();
        if (@$this->GetValue('Verbunden') !== $verbunden) {
            $this->SetValue('Verbunden', $verbunden);
        }
        $text = $this->zustandText($neu);
        if (@$this->GetValue('Zustand') !== $text) {
            $this->SetValue('Zustand', $text);
        }
    }

    private function zustandText(array $st): string
    {
        switch ($st['phase'] ?? 'idle') {
            case 'data':
                return $this->t('Connected to %s', (string)($st['remoteShipId'] ?: $this->t('remote device'))) . ($st['heartbeatVerloren'] ? ' ' . $this->t('(heartbeat missing)') : '');
            case 'hello':
                return !empty($st['vertrauenAusstehend']) ? $this->t('Waiting to be trusted by the remote device') : $this->t('Connecting (SHIP)');
            case 'http':
            case 'httpserver':
            case 'cmi':
            case 'prot':
            case 'pin':
            case 'access':
                return $this->t('Connecting (SHIP)');
            case 'error':
                return $this->t('Error: %s', !empty($st['fehlerVorlage']) ? $this->t((string)$st['fehlerVorlage'], ...array_map('strval', (array)($st['fehlerWerte'] ?? []))) : (string)($st['fehler'] ?? ''));
            case 'closed':
                return $this->t('Disconnected by the remote device');
        }
        if ($this->serverRolle()) {
            return $this->ReadAttributeString('GegenstelleSki') === '' ? $this->t('Waiting for a device to connect') : $this->t('Waiting for the device to connect');
        }
        return $this->ReadAttributeString('GegenstelleZertifikat') === '' ? $this->t('Remote device not checked yet') : $this->t('Not connected');
    }

    private function statusAktualisieren(): void
    {
        $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->SetStatus(104);
            return;
        }
        if (!$this->elternPasst()) {
            $this->SetStatus(205);
            return;
        }
        if ($this->serverRolle() && $this->ReadAttributeString('GegenstelleSki') === '') {
            // Warten, dass sich ein Gerät meldet und ihm vertraut wird.
            $this->SetStatus(104);
            return;
        }
        if (!$this->serverRolle() && ($this->zielHost() === '' || $this->zielPort() <= 0)
            || $this->ReadAttributeString('GegenstelleZertifikat') === '') {
            $this->SetStatus(104);
            return;
        }
        $erwartet = EEBGWCert::normalisieren((string)$this->ReadPropertyString('ErwarteterSki'));
        if ($erwartet !== '' && $erwartet !== $this->ReadAttributeString('GegenstelleSki')) {
            $this->SetStatus(201);
            return;
        }
        if (!empty($st['vertrauenAusstehend']) && ($st['phase'] ?? '') === 'hello') {
            $this->SetStatus(203);
            return;
        }
        // Fehlerstatus erst nach längerer Störung, damit kurze Wiederverbindungen keine
        // Überwachungsskripte auslösen (SUITE.md Store-Checkliste 9d).
        $bezug = max((int)($st['letzteVerbindung'] ?? 0), (int)$this->GetBuffer('AktivSeit'));
        if (($st['phase'] ?? '') !== 'data' && time() - $bezug > self::STOERUNG_NACH_S) {
            $this->SetStatus(202);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
    }

    // ------------------------------------------------------------------
    // Kinder
    // ------------------------------------------------------------------

    /** Anfragen der Kind-Instanzen (Kind-Schnittstelle 1.0). */
    public function ForwardData($JSONString)
    {
        $d = json_decode((string)$JSONString, true);
        if (!is_array($d) || ($d['DataID'] ?? '') !== self::DATA_ZUM_GATEWAY) {
            return '';
        }
        $kind = (int)($d['Sender'] ?? 0);
        switch ((string)($d['Cmd'] ?? '')) {
            case 'Register':
                return (string)json_encode($this->kindAnmelden($kind, (array)($d['Entity'] ?? [])));
            case 'Unregister':
                $this->kindAbmelden($kind);
                return (string)json_encode(['ok' => true]);
            case 'SetData':
                return (string)json_encode($this->kindDaten($kind, (string)($d['Feature'] ?? ''), (string)($d['Function'] ?? ''), (array)($d['Data'] ?? [])));
            case 'GetState':
                return $this->GetState();
            case 'Read':
            case 'Write':
                return (string)json_encode($this->kindAnfrage($kind, $d));
            case 'GetRemoteData':
                $r = $this->mitSitzung(function (EEBGWSession $s) use ($d) {
                    return ['out' => '', 'rueckgabe' => ['ok' => true, 'data' => $s->remoteDaten((array)($d['Entity'] ?? []), (string)($d['Feature'] ?? ''), (string)($d['Function'] ?? ''))]];
                });
                return (string)json_encode(is_array($r) ? $r : ['ok' => false, 'error' => 'gateway busy']);
        }
        return (string)json_encode(['ok' => false, 'error' => 'unknown command']);
    }

    private function kindAnmelden(int $kind, array $entity): array
    {
        if ($kind <= 0 || !IPS_InstanceExists($kind)) {
            return ['ok' => false, 'error' => 'sender missing or unknown'];
        }
        $anm = json_decode($this->ReadAttributeString('Anmeldungen'), true) ?: [];
        $alt = $anm[(string)$kind] ?? null;
        $anm[(string)$kind] = $entity;
        $anm = $this->anmeldungenBereinigen($anm);
        if ($alt != $entity) {
            $this->WriteAttributeString('Anmeldungen', (string)json_encode($anm));
            $this->LogMessage($this->t('Child instance #%s (%s) registered: %s', (string)$kind, IPS_GetName($kind), (string)($entity['type'] ?? '?')), KL_MESSAGE);
            // Neues Gerätemodell: die Gegenstelle muss es neu erfragen, also Verbindung neu aufbauen.
            $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
            if (($st['phase'] ?? '') === 'data') {
                $this->NeuVerbinden();
            }
        }
        $modell = EEBGWSession::buildModel($anm);
        foreach ($modell['entities'] as $e) {
            if ($e['owner'] === $kind) {
                return ['ok' => true, 'interface' => self::SCHNITTSTELLE, 'entity' => $e['address'],
                    'features' => array_map(function ($f) {
                        return ['index' => $f['index'], 'type' => $f['type'], 'role' => $f['role']];
                    }, $e['features'])];
            }
        }
        return ['ok' => false, 'error' => 'registration not applied'];
    }

    private function kindAbmelden(int $kind): void
    {
        $anm = json_decode($this->ReadAttributeString('Anmeldungen'), true) ?: [];
        if (isset($anm[(string)$kind])) {
            unset($anm[(string)$kind]);
            $this->WriteAttributeString('Anmeldungen', (string)json_encode($anm));
        }
    }

    /** Anmeldungen gelöschter oder umgehängter Kinder entfernen. */
    private function anmeldungenBereinigen(array $anm): array
    {
        foreach (array_keys($anm) as $kind) {
            $id = (int)$kind;
            if (!IPS_InstanceExists($id) || (int)(IPS_GetInstance($id)['ConnectionID'] ?? 0) !== $this->InstanceID) {
                unset($anm[$kind]);
            }
        }
        return $anm;
    }

    private function kindDaten(int $kind, string $featureTyp, string $funktion, array $daten): array
    {
        if ($funktion === '' || $featureTyp === '') {
            return ['ok' => false, 'error' => 'Feature and Function are required'];
        }
        $r = $this->mitSitzung(function (EEBGWSession $s) use ($kind, $featureTyp, $funktion, $daten) {
            $entity = $s->entityOf($kind);
            $index = $entity === null ? null : $s->featureIndex($entity, $featureTyp, 'server');
            if ($index === null) {
                return ['out' => '', 'rueckgabe' => ['ok' => false, 'error' => 'feature ' . $featureTyp . ' is not registered for this instance']];
            }
            return ['out' => $s->datenSetzen($entity, $index, $funktion, $daten), 'rueckgabe' => ['ok' => true]];
        });
        return is_array($r) ? $r : ['ok' => false, 'error' => 'gateway busy'];
    }

    /** Rolle Energiemanagement: Kind liest oder schreibt eine Funktion der Gegenstelle (Kind-Schnittstelle 1.1). */
    private function kindAnfrage(int $kind, array $d): array
    {
        $art = $d['Cmd'] === 'Write' ? 'write' : 'read';
        $r = $this->mitSitzung(function (EEBGWSession $s) use ($kind, $d, $art) {
            $a = $s->remoteAnfrage($kind, $art, (array)($d['Entity'] ?? []), (string)($d['Feature'] ?? ''), (string)($d['Function'] ?? ''),
                isset($d['Data']) ? (array)$d['Data'] : null, (bool)($d['Partial'] ?? true));
            return ['out' => $a['out'], 'rueckgabe' => $a['error'] === '' ? ['ok' => true, 'msgCounter' => $a['msgCounter']] : ['ok' => false, 'error' => $a['error']]];
        });
        return is_array($r) ? $r : ['ok' => false, 'error' => 'gateway busy'];
    }

    private function ereignisVerteilen(array $e): void
    {
        $art = (string)($e['event'] ?? '');
        if ($art === 'connection') {
            $this->LogMessage($e['state'] === 'connected'
                ? $this->t('EEBUS connection established (remote device %s).', (string)($e['shipId'] ?? '?'))
                : $this->t('EEBUS connection closed.'), $e['state'] === 'connected' ? KL_MESSAGE : KL_WARNING);
        } elseif ($art === 'write') {
            $this->LogMessage($this->t('Write command from the remote device for %s, instance #%s: %s', $e['feature'] . '/' . $e['function'], (string)$e['owner'], (string)json_encode($e['changes'])), KL_MESSAGE);
        } elseif ($art === 'heartbeat' && $e['state'] === 'lost') {
            $this->LogMessage($this->t('Heartbeat of the remote device is missing.'), KL_WARNING);
        } elseif ($art === 'remoteEntity') {
            $this->LogMessage($e['state'] === 'added'
                ? $this->t('Remote device offers %s (entity %s) for instance #%s.', (string)($e['type'] ?? '?'), (string)json_encode($e['entity']), (string)$e['owner'])
                : $this->t('Remote device no longer offers entity %s.', (string)json_encode($e['entity'])), KL_MESSAGE);
        } elseif ($art === 'result' && (int)$e['errorNumber'] !== 0) {
            $this->LogMessage($this->t('The remote device rejected %s: error %s %s', (string)$e['function'], (string)$e['errorNumber'], (string)$e['description']), KL_WARNING);
        } elseif ($art === 'trust') {
            $this->LogMessage($this->t('The remote device is waiting for this gateway to be trusted (SKI %s).', EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki'))), KL_NOTIFY);
        }
        $paket = ['DataID' => self::DATA_ZUM_KIND, 'Target' => (int)($e['owner'] ?? 0), 'Event' => $art] + $e;
        unset($paket['event']);
        try {
            $this->SendDataToChildren((string)json_encode($paket));
        } catch (Throwable $ex) {
            $this->LogMessage($this->t('Forwarding to child instances failed: %s', $ex->getMessage()), KL_WARNING);
        }
    }

    /** EEBGW_GetState($id): string — Zustand als JSON (Diagnose und Kinder). */
    public function GetState(): string
    {
        $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        return (string)json_encode([
            'contractVersion' => '1.0',
            'connected' => ($st['phase'] ?? '') === 'data',
            'phase' => (string)($st['phase'] ?? 'idle'),
            'trustPending' => (bool)($st['vertrauenAusstehend'] ?? false),
            'ownSki' => $this->ReadAttributeString('EigenerSki'),
            'remoteSki' => $this->ReadAttributeString('GegenstelleSki'),
            'remoteShipId' => (string)($st['remoteShipId'] ?? ''),
            'remoteUseCases' => (array)($st['remoteUseCases'] ?? []),
            'remoteHeartbeat' => (int)($st['remoteHeartbeat'] ?? 0),
            'heartbeatLost' => (bool)($st['heartbeatVerloren'] ?? false),
            'connectedSince' => (int)($st['verbundenSeit'] ?? 0),
        ]);
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Enter and check the remote device'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'SKI of the remote device does not match the one entered'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'Remote device not connected for more than 5 minutes'],
            ['code' => 203, 'icon' => 'inactive', 'caption' => 'Waiting for this gateway to be trusted by the remote device'],
            ['code' => 205, 'icon' => 'error', 'caption' => 'The parent instance does not match the connection direction (client socket or server socket)'],
        ];
        return $this->eebgwBuildForm($this->eebgwFachpanels(), [], $status);
    }

    /** Fachpanels in Standardreihenfolge, Schlüssel stabil (Formular: Aufbau, SUITE.md 3a). */
    protected function eebgwFachpanels(): array
    {
        $eigenerSki = $this->ReadAttributeString('EigenerSki');
        $gegenSki = $this->ReadAttributeString('GegenstelleSki');
        $info = json_decode($this->ReadAttributeString('GegenstelleInfo'), true) ?: [];
        $kandidat = json_decode($this->ReadAttributeString('Kandidat'), true) ?: [];
        $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        $server = $this->serverRolle();

        if ($gegenSki !== '') {
            $gegenZeile = '✅ ' . $this->t('Remote device set: SKI %s', EEBGWCert::lesbar($gegenSki))
                . (isset($info['geprueft']) ? ', ' . $this->t('checked on %s', date($this->t('Y-m-d H:i'), (int)$info['geprueft'])) : '')
                . (!empty($info['chiffre']) ? ' (' . $info['protokoll'] . ', ' . $info['chiffre'] . ')' : '');
        } else {
            $gegenZeile = self::fest('ℹ️ No remote device set yet. Enter address and port, click “Apply”, then “Check remote device”.');
        }

        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => $gegenSki === '', 'caption' => '🔌  Remote device',
            'items' => [
                ['type' => 'Select', 'name' => 'Richtung', 'caption' => 'Who establishes the connection?', 'width' => '520px', 'options' => [
                    ['caption' => 'The gateway connects to the device (control box, most devices)', 'value' => self::RICHTUNG_CLIENT],
                    ['caption' => 'The device connects to the gateway (e.g. Wolf Link)', 'value' => self::RICHTUNG_SERVER],
                ]],
                ['type' => 'Label', 'caption' => 'After changing the direction click “Apply”; the gateway then creates the matching socket (client or server socket) if none is attached.'],
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Address of the remote device (IP or host name)', 'width' => '420px', 'visible' => !$server],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port (e.g. 4712, see the device manual)', 'minimum' => 0, 'maximum' => 65535, 'width' => '420px', 'visible' => !$server],
                ['type' => 'NumberSpinner', 'name' => 'ServerPort', 'caption' => 'Port on which the gateway accepts the connection', 'minimum' => 1, 'maximum' => 65535, 'width' => '420px', 'visible' => $server],
                ['type' => 'Label', 'caption' => 'The device finds the gateway via its announcement in the network (see “This gateway” below) and connects by itself. When it reports for the first time, its SKI appears here for confirmation.', 'visible' => $server],
                ['type' => 'ValidationTextBox', 'name' => 'ErwarteterSki', 'caption' => 'SKI of the remote device (optional, from app or type plate)', 'width' => '420px'],
                ['type' => 'Label', 'caption' => 'If an SKI is entered, the gateway accepts only this exact device. Leave it empty to compare the SKI yourself after the check.'],
                ['type' => 'Button', 'caption' => 'Check remote device', 'onClick' => 'echo EEBGW_GegenstellePruefen($id);', 'visible' => !$server],
                ['type' => 'Label', 'name' => 'GegenstelleZeile', 'caption' => $gegenZeile, 'color' => $gegenSki !== '' ? 0x2E8B3D : -1],
                [
                    'type' => 'ExpansionPanel', 'name' => 'KandidatPanel', 'expanded' => true, 'visible' => !empty($kandidat['ski']),
                    'caption' => $server ? self::fest('A device wants to connect') : self::fest('Confirm the device found'),
                    'items' => [
                        ['type' => 'Label', 'name' => 'KandidatZeile', 'caption' => $this->t('SKI found: %s', !empty($kandidat['ski']) ? EEBGWCert::lesbar((string)$kandidat['ski']) : '')],
                        ['type' => 'Label', 'caption' => 'Only trust it if this SKI matches the one the device itself shows.'],
                        ['type' => 'Button', 'caption' => 'Trust this SKI', 'onClick' => 'echo EEBGW_GegenstelleUebernehmen($id);'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Connection enabled'],
            ],
        ];

        $pd = $this->pairingDaten();
        $pairingStand = json_decode($this->ReadAttributeString('PairingStand'), true) ?: [];
        // Felder ohne Eigenschaft gehören in den Aktionsbereich (IRHub-Befund); „Formular: Aufbau“ steuert ihn mit.
        $gateway = [
            'type' => 'ExpansionPanel', 'bereich' => 'actions',
            'expanded' => ($gegenSki !== '' && ($st['phase'] ?? '') !== 'data') || !empty($pairingStand['aktiv']), 'caption' => '🪪  This gateway',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'EigenerSkiFeld', 'caption' => 'SKI of this gateway (trust it in the remote device)', 'value' => EEBGWCert::lesbar($eigenerSki), 'width' => '520px'],
                ['type' => 'Label', 'caption' => 'Enter or confirm this SKI in the remote device (app, web interface or with the grid operator, depending on the device).'],
                [
                    'type' => 'ExpansionPanel', 'expanded' => !empty($pairingStand['aktiv']), 'visible' => !$server, 'caption' => '🔗  Pairing via the metering point operator (SHIP Pairing Service)',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Some metering point operators pair the control box with the energy management via the SHIP Pairing Service: you give them the pairing data of this gateway, they enter it into the control box. The control box then announces itself in the home network, and the gateway finds and trusts it by itself - address, port and SKI no longer need to be entered.'],
                        ['type' => 'Button', 'caption' => 'Prepare pairing and wait for the remote device', 'onClick' => 'echo EEBGW_PairingStarten($id);'],
                        ['type' => 'Image', 'name' => 'PairingQr', 'image' => $this->pairingQr($pd['text']), 'visible' => $pd['text'] !== ''],
                        ['type' => 'ValidationTextBox', 'name' => 'PairingDaten', 'caption' => 'Pairing data (text for the QR code)', 'value' => $pd['text'], 'width' => '900px'],
                        ['type' => 'ValidationTextBox', 'name' => 'PairingShipId', 'caption' => 'SHIP ID of this gateway', 'value' => $pd['shipId'], 'width' => '520px'],
                        ['type' => 'ValidationTextBox', 'name' => 'PairingFingerabdruck', 'caption' => 'Certificate fingerprint (SHA-256)', 'value' => $pd['fingerabdruck'], 'width' => '900px'],
                        ['type' => 'ValidationTextBox', 'name' => 'PairingGeheimnisFeld', 'caption' => 'Pairing secret', 'value' => $pd['geheimnis'], 'width' => '520px'],
                        ['type' => 'Label', 'caption' => 'The pairing data contain a secret. Only pass them to your metering point operator or the installer.'],
                        ['type' => 'Label', 'name' => 'PairingZeile', 'caption' => $this->pairingZeile(), 'color' => !empty($pairingStand['gekoppelt']) ? 0x2E8B3D : -1],
                        ['type' => 'Button', 'caption' => 'Stop waiting', 'onClick' => 'echo EEBGW_PairingBeenden($id);'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'expanded' => $server && $gegenSki === '', 'visible' => $server, 'caption' => '📣  Announce in the network (DNS-SD)',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'So that the device finds the gateway in its app, the gateway must be announced in the network as an EEBUS service (_ship._tcp). This is done by an entry in the DNS-SD instance of Symcon.'],
                        ['type' => 'Label', 'caption' => $server ? $this->ankuendigungZeile() : ''],
                        ['type' => 'Button', 'caption' => 'Announce in the network', 'onClick' => 'echo EEBGW_AnkuendigungEintragen($id);',
                            'confirm' => 'This adds an entry to the DNS-SD instance of Symcon (outside this module). Continue?'],
                        ['type' => 'Button', 'caption' => 'Remove announcement', 'onClick' => 'echo EEBGW_AnkuendigungEntfernen($id);'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => 'Create a new certificate',
                    'items' => [
                        ['type' => 'Label', 'caption' => '⚠️ Afterwards the gateway has a new SKI. The remote device has to trust it again; until then there is no connection.'],
                        ['type' => 'Button', 'caption' => 'Create new certificate', 'onClick' => 'echo EEBGW_ZertifikatErneuern($id);', 'confirm' => 'Really create a new certificate? The remote device has to trust the new SKI again.'],
                    ],
                ],
            ],
        ];

        $kinder = [];
        $anm = $this->anmeldungenBereinigen(json_decode($this->ReadAttributeString('Anmeldungen'), true) ?: []);
        foreach (EEBGWSession::buildModel($anm)['entities'] as $e) {
            $ucs = array_map(function ($u) {
                return ($u['name'] ?? '?') . ' (' . ($u['actor'] ?? '?') . ')';
            }, $e['useCases']);
            $kinder[] = ['type' => 'Label', 'caption' => '• #' . $e['owner'] . ' ' . IPS_GetName($e['owner']) . ' – ' . $this->t('entity %s', $e['address'] . ' ' . $e['type']) . ($ucs ? ': ' . implode(', ', $ucs) : '')];
        }
        if ($kinder === []) {
            $kinder[] = ['type' => 'Label', 'caption' => 'ℹ️ No application module registered yet. Without one the gateway establishes the connection but offers no use cases.'];
        }
        $remote = array_map(function ($u) {
            return $u['name'] . ' (' . $u['actor'] . ')';
        }, (array)($st['remoteUseCases'] ?? []));

        $zustand = [
            'type' => 'ExpansionPanel', 'expanded' => $gegenSki !== '', 'caption' => '📡  State',
            'items' => array_merge([
                ['type' => 'Label', 'name' => 'ZustandZeile', 'caption' => (($st['phase'] ?? '') === 'data' ? '✅ ' : 'ℹ️ ') . $this->zustandText($st)],
                ['type' => 'Label', 'caption' => $this->t('Use cases of the remote device: %s', $remote ? implode(', ', array_unique($remote)) : '–')],
                ['type' => 'Label', 'caption' => $this->t('Last heartbeat of the remote device: %s', !empty($st['remoteHeartbeat']) ? date($this->t('Y-m-d H:i:s'), (int)$st['remoteHeartbeat']) : '–')],
                ['type' => 'Label', 'caption' => 'Registered application modules:'],
            ], $kinder, [
                ['type' => 'Button', 'caption' => 'Reconnect', 'onClick' => 'echo EEBGW_NeuVerbinden($id);'],
            ]),
        ];

        return ['gegenstelle' => $verbindung, 'gateway' => $gateway, 'zustand' => $zustand];
    }
}
