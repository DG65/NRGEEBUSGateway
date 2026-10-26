<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EEBGWJson.php';
require_once __DIR__ . '/../libs/EEBGWCert.php';
require_once __DIR__ . '/../libs/EEBGWSession.php';
require_once __DIR__ . '/../libs/EEBGWFormular.php';

// ===========================================================================
// NRG-Stack EEBUSGateway
//
// Hält Zertifikat/SKI, die Kopplung mit einer Gegenstelle und die SHIP-
// Verbindung (Client-Rolle) über Symcons Client Socket. Der SPINE-Knoten wird
// aus den Anmeldungen der Kind-Instanzen gebaut; Fachlogik (z. B. § 14a)
// steckt ausschließlich in den Kindern. Schnittstelle zu den Kindern:
// .docs/kind-schnittstelle.md.
//
// TLS übernimmt der Client Socket: eigenes Zertifikat als Client-Zertifikat,
// Zertifikat der Gegenstelle als einzige Vertrauensbasis (Pinning). Geprüft an
// Symcon 9.0 gegen eebus-go controlbox mit Pflicht-Chiffre (27.10.2026).
// ===========================================================================

class EEBUSGateway extends IPSModule
{
    use EEBGWFormular;

    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DATA_ZUM_IO = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const DATA_ZUM_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    private const DATA_ZUM_KIND = '{12D8B8A8-3EE7-491F-A68A-D632304165D4}';

    // Version der Kind-Schnittstelle (Anmeldung/Daten/Ereignisse), .docs/kind-schnittstelle.md.
    private const SCHNITTSTELLE = '1.0';

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
        $this->RegisterAttributeInteger('Eltern', 0);
        $this->registerFormAttributes();

        $this->RegisterTimer('Takt', 0, 'EEBGW_Takt($_IPS[\'TARGET\']);');
        $this->RequireParent(self::CLIENT_SOCKET);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->MaintainVariable('Verbunden', 'Verbunden', VARIABLETYPE_BOOLEAN, '', 1, true);
        $this->MaintainVariable('Zustand', 'Zustand', VARIABLETYPE_STRING, '', 2, true);

        $this->zertifikatSicherstellen();
        $this->elternBeobachten();
        $this->elternKonfigurieren();
        $this->SetTimerInterval('Takt', $this->ReadPropertyBoolean('Aktiv') ? self::TAKT_MS : 0);
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
                    $s->getrennt('Client Socket nicht mehr aktiv');
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
            $this->LogMessage('Zertifikat konnte nicht erzeugt werden: ' . $e->getMessage(), KL_ERROR);
            return;
        }
        $this->WriteAttributeString('Zertifikat', $neu['cert']);
        $this->WriteAttributeString('Schluessel', $neu['key']);
        $this->WriteAttributeString('EigenerSki', $neu['ski']);
        $this->LogMessage('Neues EEBUS-Zertifikat erzeugt, SKI ' . EEBGWCert::lesbar($neu['ski']), KL_MESSAGE);
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
            return '⛔ Bitte zuerst Adresse und Port der Gegenstelle eintragen und „Übernehmen“ klicken.';
        }
        $this->zertifikatSicherstellen();
        try {
            $g = EEBGWCert::gegenstelleAbholen($host, $port, $this->ReadAttributeString('Zertifikat'), $this->ReadAttributeString('Schluessel'));
        } catch (Throwable $e) {
            $this->LogMessage('Prüfung der Gegenstelle fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            return '⛔ ' . $e->getMessage();
        }
        $erwartet = EEBGWCert::normalisieren((string)$this->ReadPropertyString('ErwarteterSki'));
        $tls = $g['protokoll'] . ', ' . $g['chiffre'];
        if ($erwartet !== '') {
            if ($erwartet !== $g['ski']) {
                $this->LogMessage('SKI der Gegenstelle stimmt nicht: erwartet ' . $erwartet . ', gefunden ' . $g['ski'], KL_WARNING);
                return '⛔ Der SKI der Gegenstelle stimmt NICHT mit dem eingetragenen überein.' . "\n"
                    . 'Eingetragen: ' . EEBGWCert::lesbar($erwartet) . "\n" . 'Gefunden: ' . EEBGWCert::lesbar($g['ski']) . "\n"
                    . 'Bitte Adresse und SKI prüfen. Es wurde nichts übernommen.';
            }
            $this->gegenstelleFestlegen($g);
            return '✅ Gegenstelle bestätigt und übernommen (SKI ' . EEBGWCert::lesbar($g['ski']) . ', ' . $tls . ').' . "\n"
                . 'Jetzt den SKI dieses Gateways in der Gegenstelle freigeben: ' . EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki'));
        }
        $this->WriteAttributeString('Kandidat', (string)json_encode($g));
        $this->UpdateFormField('KandidatPanel', 'visible', true);
        $this->UpdateFormField('KandidatZeile', 'caption', 'Gefundener SKI: ' . EEBGWCert::lesbar($g['ski']));
        return 'ℹ️ Gegenstelle erreicht (' . $tls . ').' . "\n" . 'Ihr SKI lautet: ' . EEBGWCert::lesbar($g['ski']) . "\n"
            . 'Vergleiche ihn mit dem SKI, den das Gerät selbst anzeigt (App, Weboberfläche, Typenschild). Stimmt er, im Formular „Diesen SKI übernehmen“ klicken.';
    }

    /** EEBGW_GegenstelleUebernehmen($id): string — den zuletzt geprüften SKI festlegen. */
    public function GegenstelleUebernehmen(): string
    {
        $g = json_decode($this->ReadAttributeString('Kandidat'), true);
        if (!is_array($g) || empty($g['cert'])) {
            return '⛔ Es liegt kein geprüftes Zertifikat vor. Bitte zuerst „Gegenstelle prüfen“.';
        }
        $this->gegenstelleFestlegen($g);
        $this->UpdateFormField('KandidatPanel', 'visible', false);
        return '✅ Gegenstelle übernommen (SKI ' . EEBGWCert::lesbar($g['ski']) . '). Das Gateway verbindet sich jetzt.' . "\n"
            . 'Gib in der Gegenstelle den SKI dieses Gateways frei: ' . EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki'));
    }

    private function gegenstelleFestlegen(array $g): void
    {
        $this->WriteAttributeString('GegenstelleZertifikat', (string)$g['cert']);
        $this->WriteAttributeString('GegenstelleSki', (string)$g['ski']);
        $this->WriteAttributeString('GegenstelleInfo', (string)json_encode(['geprueft' => time(), 'protokoll' => $g['protokoll'] ?? '', 'chiffre' => $g['chiffre'] ?? '']));
        $this->WriteAttributeString('Kandidat', '{}');
        $this->LogMessage('Gegenstelle festgelegt, SKI ' . EEBGWCert::lesbar((string)$g['ski']), KL_MESSAGE);
        $this->elternKonfigurieren();
        $this->statusAktualisieren();
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
        return '✅ Neues Zertifikat erzeugt. Neuer SKI: ' . $ski . "\n" . 'Wichtig: Diesen SKI in der Gegenstelle neu freigeben, der alte gilt nicht mehr.';
    }

    // ------------------------------------------------------------------
    // Elterninstanz (Client Socket)
    // ------------------------------------------------------------------

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
        if ($eltern === 0 || IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] !== self::CLIENT_SOCKET) {
            return;
        }
        $bereit = $this->ReadPropertyBoolean('Aktiv') && trim((string)$this->ReadPropertyString('Host')) !== ''
            && $this->ReadPropertyInteger('Port') > 0 && $this->ReadAttributeString('GegenstelleZertifikat') !== ''
            && $this->ReadAttributeString('Zertifikat') !== '';
        $soll = ['Open' => $bereit];
        if ($bereit) {
            $soll += [
                'Host' => trim((string)$this->ReadPropertyString('Host')),
                'Port' => (int)$this->ReadPropertyInteger('Port'),
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
            return '⛔ Kein Client Socket verbunden.';
        }
        if ($this->ReadAttributeString('GegenstelleZertifikat') === '') {
            return '⛔ Die Gegenstelle ist noch nicht geprüft. Bitte zuerst „Gegenstelle prüfen“.';
        }
        IPS_SetProperty($eltern, 'Open', false);
        IPS_ApplyChanges($eltern);
        $this->elternKonfigurieren();
        return '✅ Verbindung wird neu aufgebaut. Der Zustand erscheint in wenigen Sekunden unter „Zustand“.';
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
        $s->host = trim((string)$this->ReadPropertyString('Host'));
        $s->port = (int)$this->ReadPropertyInteger('Port');
        $s->localShipId = 'NRG-Stack-EEBUSGateway-' . substr($ski, 0, 12);
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
            $this->SendDebug('Sitzung', 'Semaphore belegt, Aufruf verworfen', 0);
            return null;
        }
        $events = [];
        $rueckgabe = null;
        try {
            $s = $this->sitzungLaden();
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
            $this->standSpeichern($s);
            $rueckgabe = is_array($ergebnis) ? ($ergebnis['rueckgabe'] ?? null) : null;
        } catch (Throwable $e) {
            $this->LogMessage('Fehler in der EEBUS-Sitzung: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', KL_ERROR);
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
        if ($this->ReadAttributeString('GegenstelleZertifikat') === '') {
            return;
        }
        $this->mitSitzung(function (EEBGWSession $s) {
            return $s->start();
        });
    }

    private function senden(string $bytes): void
    {
        try {
            // Symcons Datenfluss trägt Bytes als UTF-8-kodiertes Latin-1 (Verbund-Muster RoboHub/Fernwirk101).
            $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_IO, 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
        } catch (Throwable $e) {
            $this->LogMessage('Senden an den Client Socket fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
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
            if ($elternAktiv && in_array($s->phase, ['idle', 'closed', 'error'], true)) {
                // Socket offen, aber keine (oder eine beendete) Sitzung: neu aufbauen.
                $neuVerbinden = true;
            } elseif ($elternAktiv && !$s->istVerbunden() && !$s->vertrauenAusstehend
                && $jetzt - (int)($stand['phaseSeit'] ?? $jetzt) > self::AUFBAU_FRIST_S) {
                $neuVerbinden = true;
            } elseif ($s->istVerbunden() && $s->letzteDaten > 0 && $jetzt - $s->letzteDaten > self::STILLE_FRIST_S) {
                $this->LogMessage('Gegenstelle seit ' . ($jetzt - $s->letzteDaten) . ' s stumm — Verbindung wird neu aufgebaut.', KL_WARNING);
                $neuVerbinden = true;
            }
            return $out;
        });
        if ($neuVerbinden && $this->ReadPropertyBoolean('Aktiv')) {
            $this->NeuVerbinden();
        }
        $this->statusAktualisieren();
    }

    private function standSpeichern(EEBGWSession $s): void
    {
        $alt = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        $neu = [
            'phase' => $s->phase,
            'phaseSeit' => ($alt['phase'] ?? '') === $s->phase ? (int)($alt['phaseSeit'] ?? time()) : time(),
            'fehler' => $s->fehler,
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
                return 'Verbunden mit ' . ($st['remoteShipId'] ?: 'Gegenstelle') . ($st['heartbeatVerloren'] ? ' (Heartbeat ausgeblieben)' : '');
            case 'hello':
                return !empty($st['vertrauenAusstehend']) ? 'Wartet auf Freigabe in der Gegenstelle' : 'Verbindungsaufbau (SHIP)';
            case 'http':
            case 'cmi':
            case 'prot':
            case 'pin':
            case 'access':
                return 'Verbindungsaufbau (SHIP)';
            case 'error':
                return 'Fehler: ' . ($st['fehler'] ?? '');
            case 'closed':
                return 'Von der Gegenstelle getrennt';
        }
        return $this->ReadAttributeString('GegenstelleZertifikat') === '' ? 'Gegenstelle noch nicht geprüft' : 'Nicht verbunden';
    }

    private function statusAktualisieren(): void
    {
        $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->SetStatus(104);
            return;
        }
        if (trim((string)$this->ReadPropertyString('Host')) === '' || $this->ReadPropertyInteger('Port') <= 0
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
        }
        return (string)json_encode(['ok' => false, 'error' => 'unknown command']);
    }

    private function kindAnmelden(int $kind, array $entity): array
    {
        if ($kind <= 0 || !IPS_InstanceExists($kind)) {
            return ['ok' => false, 'error' => 'Sender fehlt oder unbekannt'];
        }
        $anm = json_decode($this->ReadAttributeString('Anmeldungen'), true) ?: [];
        $alt = $anm[(string)$kind] ?? null;
        $anm[(string)$kind] = $entity;
        $anm = $this->anmeldungenBereinigen($anm);
        if ($alt != $entity) {
            $this->WriteAttributeString('Anmeldungen', (string)json_encode($anm));
            $this->LogMessage('Kind-Instanz #' . $kind . ' (' . IPS_GetName($kind) . ') angemeldet: ' . ($entity['type'] ?? '?'), KL_MESSAGE);
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
        return ['ok' => false, 'error' => 'Anmeldung nicht übernommen'];
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
            return ['ok' => false, 'error' => 'Feature und Function sind Pflicht'];
        }
        $r = $this->mitSitzung(function (EEBGWSession $s) use ($kind, $featureTyp, $funktion, $daten) {
            $entity = $s->entityOf($kind);
            $index = $entity === null ? null : $s->featureIndex($entity, $featureTyp, 'server');
            if ($index === null) {
                return ['out' => '', 'rueckgabe' => ['ok' => false, 'error' => 'Feature ' . $featureTyp . ' ist für diese Instanz nicht angemeldet']];
            }
            return ['out' => $s->datenSetzen($entity, $index, $funktion, $daten), 'rueckgabe' => ['ok' => true]];
        });
        return is_array($r) ? $r : ['ok' => false, 'error' => 'Gateway beschäftigt'];
    }

    private function ereignisVerteilen(array $e): void
    {
        $art = (string)($e['event'] ?? '');
        if ($art === 'connection') {
            $this->LogMessage($e['state'] === 'connected'
                ? 'EEBUS-Verbindung steht (Gegenstelle ' . ($e['shipId'] ?? '?') . ').'
                : 'EEBUS-Verbindung getrennt.', $e['state'] === 'connected' ? KL_MESSAGE : KL_WARNING);
        } elseif ($art === 'write') {
            $this->LogMessage('Schreibbefehl der Gegenstelle für ' . $e['feature'] . '/' . $e['function'] . ' an Instanz #' . $e['owner'] . ': ' . json_encode($e['changes']), KL_MESSAGE);
        } elseif ($art === 'heartbeat' && $e['state'] === 'lost') {
            $this->LogMessage('Heartbeat der Gegenstelle ausgeblieben.', KL_WARNING);
        } elseif ($art === 'trust') {
            $this->LogMessage('Gegenstelle wartet auf Freigabe dieses Gateways (SKI ' . EEBGWCert::lesbar($this->ReadAttributeString('EigenerSki')) . ').', KL_NOTIFY);
        }
        $paket = ['DataID' => self::DATA_ZUM_KIND, 'Target' => (int)($e['owner'] ?? 0), 'Event' => $art] + $e;
        unset($paket['event']);
        try {
            $this->SendDataToChildren((string)json_encode($paket));
        } catch (Throwable $ex) {
            $this->LogMessage('Weitergabe an Kind-Instanzen fehlgeschlagen: ' . $ex->getMessage(), KL_WARNING);
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
        $eigenerSki = $this->ReadAttributeString('EigenerSki');
        $gegenSki = $this->ReadAttributeString('GegenstelleSki');
        $info = json_decode($this->ReadAttributeString('GegenstelleInfo'), true) ?: [];
        $kandidat = json_decode($this->ReadAttributeString('Kandidat'), true) ?: [];
        $st = json_decode($this->ReadAttributeString('Stand'), true) ?: [];

        if ($gegenSki !== '') {
            $gegenZeile = '✅ Gegenstelle festgelegt: SKI ' . EEBGWCert::lesbar($gegenSki)
                . (isset($info['geprueft']) ? ', geprüft am ' . date('d.m.Y H:i', (int)$info['geprueft']) : '')
                . (!empty($info['chiffre']) ? ' (' . $info['protokoll'] . ', ' . $info['chiffre'] . ')' : '');
        } else {
            $gegenZeile = 'ℹ️ Noch keine Gegenstelle festgelegt. Adresse und Port eintragen, „Übernehmen“, dann „Gegenstelle prüfen“.';
        }

        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => $gegenSki === '', 'caption' => '🔌  Gegenstelle',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Adresse der Gegenstelle (IP oder Hostname)', 'width' => '420px'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port (z. B. 4712, steht in der Anleitung des Geräts)', 'minimum' => 0, 'maximum' => 65535, 'width' => '420px'],
                ['type' => 'ValidationTextBox', 'name' => 'ErwarteterSki', 'caption' => 'SKI der Gegenstelle (optional, aus App oder Typenschild)', 'width' => '420px'],
                ['type' => 'Label', 'caption' => 'Ist der SKI eingetragen, akzeptiert das Gateway nur genau dieses Gerät. Leer lassen, um den SKI nach der Prüfung selbst zu vergleichen.'],
                ['type' => 'Button', 'caption' => 'Gegenstelle prüfen', 'onClick' => 'echo EEBGW_GegenstellePruefen($id);'],
                ['type' => 'Label', 'name' => 'GegenstelleZeile', 'caption' => $gegenZeile, 'color' => $gegenSki !== '' ? 0x2E8B3D : -1],
                [
                    'type' => 'ExpansionPanel', 'name' => 'KandidatPanel', 'expanded' => true, 'visible' => !empty($kandidat['ski']),
                    'caption' => 'Gefundenes Gerät bestätigen',
                    'items' => [
                        ['type' => 'Label', 'name' => 'KandidatZeile', 'caption' => 'Gefundener SKI: ' . (!empty($kandidat['ski']) ? EEBGWCert::lesbar((string)$kandidat['ski']) : '')],
                        ['type' => 'Label', 'caption' => 'Nur übernehmen, wenn dieser SKI mit dem übereinstimmt, den das Gerät selbst anzeigt.'],
                        ['type' => 'Button', 'caption' => 'Diesen SKI übernehmen', 'onClick' => 'echo EEBGW_GegenstelleUebernehmen($id);'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Verbindung aktiv'],
            ],
        ];

        $gateway = [
            'type' => 'ExpansionPanel', 'expanded' => $gegenSki !== '' && ($st['phase'] ?? '') !== 'data', 'caption' => '🪪  Dieses Gateway',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'EigenerSkiFeld', 'caption' => 'SKI dieses Gateways (in der Gegenstelle freigeben)', 'value' => EEBGWCert::lesbar($eigenerSki), 'enabled' => false, 'width' => '520px'],
                ['type' => 'Label', 'caption' => 'Diesen SKI trägst du in der Gegenstelle ein bzw. bestätigst ihn dort (App, Weboberfläche oder beim Netzbetreiber, je nach Gerät).'],
                [
                    'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => 'Neues Zertifikat erzeugen',
                    'items' => [
                        ['type' => 'Label', 'caption' => '⚠️ Danach hat das Gateway einen neuen SKI. Die Gegenstelle muss ihn neu freigeben, bis dahin besteht keine Verbindung.'],
                        ['type' => 'Button', 'caption' => 'Neues Zertifikat erzeugen', 'onClick' => 'echo EEBGW_ZertifikatErneuern($id);', 'confirm' => 'Wirklich ein neues Zertifikat erzeugen? Die Gegenstelle muss den neuen SKI erneut freigeben.'],
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
            $kinder[] = ['type' => 'Label', 'caption' => '• #' . $e['owner'] . ' ' . IPS_GetName($e['owner']) . ' – Entität ' . $e['address'] . ' ' . $e['type'] . ($ucs ? ': ' . implode(', ', $ucs) : '')];
        }
        if ($kinder === []) {
            $kinder[] = ['type' => 'Label', 'caption' => 'ℹ️ Noch kein Fachmodul angemeldet. Ohne Fachmodul baut das Gateway die Verbindung auf, bietet aber keine Anwendungsfälle an.'];
        }
        $remote = array_map(function ($u) {
            return $u['name'] . ' (' . $u['actor'] . ')';
        }, (array)($st['remoteUseCases'] ?? []));

        $zustand = [
            'type' => 'ExpansionPanel', 'expanded' => $gegenSki !== '', 'caption' => '📡  Zustand',
            'items' => array_merge([
                ['type' => 'Label', 'name' => 'ZustandZeile', 'caption' => (($st['phase'] ?? '') === 'data' ? '✅ ' : 'ℹ️ ') . $this->zustandText($st)],
                ['type' => 'Label', 'caption' => 'Anwendungsfälle der Gegenstelle: ' . ($remote ? implode(', ', array_unique($remote)) : '–')],
                ['type' => 'Label', 'caption' => 'Letzter Heartbeat der Gegenstelle: ' . (!empty($st['remoteHeartbeat']) ? date('d.m.Y H:i:s', (int)$st['remoteHeartbeat']) : '–')],
                ['type' => 'Label', 'caption' => 'Angemeldete Fachmodule:'],
            ], $kinder, [
                ['type' => 'Button', 'caption' => 'Neu verbinden', 'onClick' => 'echo EEBGW_NeuVerbinden($id);'],
            ]),
        ];

        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Gegenstelle eintragen und prüfen'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'SKI der Gegenstelle stimmt nicht mit dem eingetragenen überein'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'Gegenstelle seit über 5 Minuten nicht verbunden'],
            ['code' => 203, 'icon' => 'inactive', 'caption' => 'Wartet auf Freigabe dieses Gateways in der Gegenstelle'],
        ];
        return $this->eebgwBuildForm([$verbindung, $gateway, $zustand], [], $status);
    }
}
