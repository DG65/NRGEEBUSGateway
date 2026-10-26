<?php

declare(strict_types=1);

require_once __DIR__ . '/EEBGWJson.php';

// ===========================================================================
// Eine SHIP-Verbindung in der Client-Rolle: WebSocket, SHIP-Handshake und ein
// generischer SPINE-Knoten.
//
// Transportunabhängig: feed() bekommt die Rohbytes aus der TLS-Verbindung und
// liefert die zu sendenden Bytes zurück. Kein Closure-Zustand, damit das Modul
// die Sitzung zwischen zwei ReceiveData()-Aufrufen serialisiert ablegen kann.
//
// Fachlogik steckt nicht hier: Welche Entitäten, Anwendungsfälle und Features
// der Knoten anbietet, melden die Kind-Instanzen an (buildModel()); ihre
// Feature-Daten liegen in $data. Schreibbefehle der Gegenstelle werden
// zusammengeführt und als Ereignis an das Modul gegeben.
//
// Referenz für Abläufe und Nachrichtenformate: enbility ship-go/spine-go/
// eebus-go (MIT), gegen deren controlbox im Prototyp geprüft (27.10.2026).
// ===========================================================================

final class EEBGWSession
{
    public const SPEC_VERSION = '1.3.0';
    public const HEARTBEAT_INTERVALL_S = 10;
    public const HEARTBEAT_TIMEOUT = 'PT30S';
    // LPC/LPP: fehlt der Heartbeat der Gegenstelle länger als 120 s, gilt die Ausfallgrenze.
    public const HEARTBEAT_VERLOREN_S = 120;

    // Listen-Funktionen, deren Einträge bei "partial" je Kennung zusammengeführt werden.
    private const LISTEN_SCHLUESSEL = [
        'loadControlLimitListData' => ['loadControlLimitData', 'limitId'],
        'loadControlLimitDescriptionListData' => ['loadControlLimitDescriptionData', 'limitId'],
        'deviceConfigurationKeyValueListData' => ['deviceConfigurationKeyValueData', 'keyId'],
        'deviceConfigurationKeyValueDescriptionListData' => ['deviceConfigurationKeyValueDescriptionData', 'keyId'],
        'electricalConnectionCharacteristicListData' => ['electricalConnectionCharacteristicData', 'characteristicId'],
    ];

    // --- Konfiguration (vom Modul gesetzt) ---
    public $host = '';
    public $port = 0;
    public $localShipId = '';
    public $localDevice = '';
    public $deviceType = 'EnergyManagementSystem';
    /** @var array Ergebnis von buildModel() */
    public $model = ['entities' => []];
    /** @var array "e/f" => [Funktion => Daten als Array] */
    public $data = [];

    // --- Zustand ---
    public $phase = 'idle';      // idle, http, cmi, hello, prot, pin, access, data, closed, error
    public $fehler = '';
    public $buf = '';
    public $wsKey = '';
    public $msgCounter = 0;
    public $helloOk = false;
    public $vertrauenAusstehend = false;
    public $remoteShipId = '';
    public $remoteDevice = '';
    public $remoteEntities = [];
    public $remoteUseCases = [];
    public $subscriptions = [];  // je ['client' => Adresse, 'server' => [e, f]]
    public $bindings = [];
    public $hbCounter = 0;
    public $lastOwnHb = 0;
    public $lastRemoteHb = 0;
    public $remoteHbTimeout = 0;
    public $hbAbonniert = false;
    public $hbVerlorenGemeldet = false;
    public $verbundenSeit = 0;
    public $letzteDaten = 0;

    private $events = [];
    private $log = [];

    // ------------------------------------------------------------------
    // Modell aus den Anmeldungen der Kinder
    // ------------------------------------------------------------------

    /**
     * $anmeldungen: Kind-Instanz-ID => ['type' => Entitätstyp, 'useCases' => [...], 'features' => [...]]
     * Jedes Kind bekommt eine eigene Entität (1, 2, ...), in fester Reihenfolge nach Instanz-ID.
     * Zusätzlich bekommt jede Entität DeviceDiagnosis als Server (eigener Heartbeat) und Client
     * (Heartbeat der Gegenstelle lesen), sofern das Kind sie nicht selbst anmeldet.
     */
    public static function buildModel(array $anmeldungen): array
    {
        ksort($anmeldungen);
        $entities = [];
        $nr = 1;
        foreach ($anmeldungen as $owner => $a) {
            $features = [];
            $i = 1;
            $hat = [];
            foreach ((array)($a['features'] ?? []) as $f) {
                $typ = (string)($f['type'] ?? '');
                $rolle = (string)($f['role'] ?? 'server');
                if ($typ === '') {
                    continue;
                }
                $features[] = ['index' => $i++, 'type' => $typ, 'role' => $rolle, 'functions' => (array)($f['functions'] ?? [])];
                $hat[$typ . '/' . $rolle] = true;
            }
            if (!isset($hat['DeviceDiagnosis/server'])) {
                $features[] = ['index' => $i++, 'type' => 'DeviceDiagnosis', 'role' => 'server', 'functions' => ['deviceDiagnosisHeartbeatData' => ['read']]];
            }
            if (!isset($hat['DeviceDiagnosis/client'])) {
                $features[] = ['index' => $i++, 'type' => 'DeviceDiagnosis', 'role' => 'client', 'functions' => []];
            }
            $entities[] = [
                'address' => $nr++,
                'owner' => (int)$owner,
                'type' => (string)($a['type'] ?? 'CEM'),
                'useCases' => array_values((array)($a['useCases'] ?? [])),
                'features' => $features,
            ];
        }
        return ['entities' => $entities];
    }

    /** Feature-Index einer Entität für Typ/Rolle, null wenn nicht vorhanden. */
    public function featureIndex(int $entity, string $typ, string $rolle = 'server'): ?int
    {
        foreach ($this->model['entities'] as $e) {
            if ($e['address'] !== $entity) {
                continue;
            }
            foreach ($e['features'] as $f) {
                if ($f['type'] === $typ && $f['role'] === $rolle) {
                    return $f['index'];
                }
            }
        }
        return null;
    }

    public function entityOf(int $owner): ?int
    {
        foreach ($this->model['entities'] as $e) {
            if ($e['owner'] === $owner) {
                return $e['address'];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Ereignisse/Protokoll für das Modul
    // ------------------------------------------------------------------

    public function drainEvents(): array
    {
        $e = $this->events;
        $this->events = [];
        return $e;
    }

    public function drainLog(): array
    {
        $l = $this->log;
        $this->log = [];
        return $l;
    }

    private function l(string $s): void
    {
        $this->log[] = $s;
    }

    private function ereignis(array $e): void
    {
        $this->events[] = $e;
    }

    public function istVerbunden(): bool
    {
        return $this->phase === 'data';
    }

    // ------------------------------------------------------------------
    // WebSocket
    // ------------------------------------------------------------------

    /** Neue Verbindung: Zustand zurücksetzen und HTTP-Upgrade liefern (muss binnen 10 s raus). */
    public function start(): string
    {
        $war = $this->phase === 'data';
        foreach (['fehler' => '', 'buf' => '', 'msgCounter' => 0, 'helloOk' => false, 'vertrauenAusstehend' => false,
            'remoteShipId' => '', 'remoteDevice' => '', 'remoteEntities' => [], 'remoteUseCases' => [], 'subscriptions' => [],
            'bindings' => [], 'lastOwnHb' => 0, 'lastRemoteHb' => 0, 'remoteHbTimeout' => 0, 'hbAbonniert' => false,
            'hbVerlorenGemeldet' => false, 'verbundenSeit' => 0, 'letzteDaten' => 0] as $k => $v) {
            $this->$k = $v;
        }
        if ($war) {
            $this->ereignis(['event' => 'connection', 'state' => 'disconnected']);
        }
        $this->phase = 'http';
        $this->wsKey = base64_encode(random_bytes(16));
        $this->l('-> HTTP-Upgrade /ship/ (Unterprotokoll ship)');
        return 'GET /ship/ HTTP/1.1' . "\r\n"
            . 'Host: ' . $this->host . ':' . $this->port . "\r\n"
            . 'Upgrade: websocket' . "\r\n"
            . 'Connection: Upgrade' . "\r\n"
            . 'Sec-WebSocket-Key: ' . $this->wsKey . "\r\n"
            . 'Sec-WebSocket-Version: 13' . "\r\n"
            . 'Sec-WebSocket-Protocol: ship' . "\r\n\r\n";
    }

    /** Verbindung ist weg (Socket geschlossen). */
    public function getrennt(string $grund): void
    {
        if ($this->phase === 'data') {
            $this->ereignis(['event' => 'connection', 'state' => 'disconnected']);
        }
        if ($this->phase !== 'idle') {
            $this->l('Verbindung getrennt: ' . $grund);
        }
        $this->phase = 'idle';
        $this->buf = '';
    }

    private function frame(string $payload, int $opcode = 0x2): string
    {
        $len = strlen($payload);
        $h = chr(0x80 | $opcode);
        if ($len < 126) {
            $h .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $h .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $h .= chr(0x80 | 127) . pack('J', $len);
        }
        // Client-Rahmen müssen maskiert sein (RFC 6455 5.3).
        $mask = random_bytes(4);
        $maskiert = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
        return $h . $mask . substr($maskiert, 0, $len);
    }

    public function feed(string $bytes, int $jetzt): string
    {
        $this->buf .= $bytes;
        $out = '';
        if ($this->phase === 'http') {
            $p = strpos($this->buf, "\r\n\r\n");
            if ($p === false) {
                if (strlen($this->buf) > 16384) {
                    $this->fehlerZustand('Ungültige HTTP-Antwort der Gegenstelle.');
                }
                return '';
            }
            $kopf = substr($this->buf, 0, $p);
            $this->buf = substr($this->buf, $p + 4);
            $erwartet = base64_encode(sha1($this->wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (!preg_match('#^HTTP/1\.1 101#', $kopf)) {
                $this->fehlerZustand('WebSocket-Upgrade abgelehnt: ' . strtok($kopf, "\r\n"));
                return '';
            }
            if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $kopf, $m) || $m[1] !== $erwartet) {
                $this->fehlerZustand('WebSocket-Upgrade: falscher Sec-WebSocket-Accept.');
                return '';
            }
            if (!preg_match('/^Sec-WebSocket-Protocol:\s*ship\s*$/mi', $kopf)) {
                $this->fehlerZustand('Gegenstelle bestätigt das Unterprotokoll "ship" nicht.');
                return '';
            }
            $this->l('<- 101 Switching Protocols, Unterprotokoll ship');
            $this->phase = 'cmi';
            $this->l('-> SHIP init');
            $out .= $this->frame("\x00\x00");
        }
        while (strlen($this->buf) >= 2) {
            $b1 = ord($this->buf[1]);
            $len = $b1 & 0x7f;
            $off = 2;
            if ($len === 126) {
                if (strlen($this->buf) < 4) {
                    break;
                }
                $len = unpack('n', substr($this->buf, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if (strlen($this->buf) < 10) {
                    break;
                }
                $len = unpack('J', substr($this->buf, 2, 8))[1];
                $off = 10;
            }
            $maskiert = ($b1 & 0x80) !== 0;
            if ($maskiert) {
                $off += 4;
            }
            if (strlen($this->buf) < $off + $len) {
                break;
            }
            $opcode = ord($this->buf[0]) & 0x0f;
            $payload = substr($this->buf, $off, $len);
            if ($maskiert) {
                $mask = substr($this->buf, $off - 4, 4);
                $payload = $payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
            }
            $this->buf = substr($this->buf, $off + $len);
            $this->letzteDaten = $jetzt;
            if ($opcode === 0x9) {
                $out .= $this->frame($payload, 0xA);
                continue;
            }
            if ($opcode === 0x8) {
                $this->l('<- WebSocket-Schließen ' . bin2hex(substr($payload, 0, 2)) . ' ' . substr($payload, 2));
                $this->getrennt('Gegenstelle hat die WebSocket-Verbindung geschlossen');
                $this->phase = 'closed';
                continue;
            }
            if ($opcode === 0x2 || $opcode === 0x1) {
                $out .= $this->onShip($payload, $jetzt);
            }
        }
        return $out;
    }

    private function fehlerZustand(string $text): void
    {
        $this->l('FEHLER ' . $text);
        $this->fehler = $text;
        if ($this->phase === 'data') {
            $this->ereignis(['event' => 'connection', 'state' => 'disconnected']);
        }
        $this->phase = 'error';
    }

    // ------------------------------------------------------------------
    // SHIP
    // ------------------------------------------------------------------

    private function shipCtl(array $msg): string
    {
        $json = EEBGWJson::render($msg);
        $this->l('-> SHIP ' . $json);
        return $this->frame("\x01" . $json);
    }

    private function onShip(string $p, int $jetzt): string
    {
        if ($p === '') {
            return '';
        }
        $typ = ord($p[0]);
        $body = substr($p, 1);
        if ($typ === 0) {
            if ($this->phase !== 'cmi') {
                return '';
            }
            $this->l('<- SHIP init');
            $this->phase = 'hello';
            return $this->shipCtl(['connectionHello' => ['phase' => 'ready', 'waiting' => 60000]]);
        }
        if ($typ === 2) {
            return $this->phase === 'data' ? $this->onSpine($body, $jetzt) : '';
        }
        if ($typ === 3) {
            $this->l('<- SHIP Ende ' . $body);
            return '';
        }
        $this->l('<- SHIP ' . $body);
        $msg = EEBGWJson::parse(trim($body, "\x00"));
        if (!($msg instanceof stdClass)) {
            return '';
        }
        $out = '';
        if (isset($msg->connectionHello)) {
            $h = $msg->connectionHello;
            $ph = (string)($h->phase ?? '');
            if ($ph === 'ready' && !$this->helloOk) {
                $this->helloOk = true;
                $this->vertrauenAusstehend = false;
                $this->phase = 'prot';
                $out .= $this->shipCtl(['messageProtocolHandshake' => ['handshakeType' => 'announceMax',
                    'version' => ['major' => 1, 'minor' => 0], 'formats' => ['format' => ['JSON-UTF8']]]]);
            } elseif ($ph === 'pending') {
                if (!empty($h->prolongationRequest)) {
                    $out .= $this->shipCtl(['connectionHello' => ['phase' => 'ready', 'waiting' => 60000]]);
                } elseif (!$this->vertrauenAusstehend) {
                    $this->vertrauenAusstehend = true;
                    $this->l('   Gegenstelle wartet auf Freigabe dieses Gateways (SKI dort bestätigen)');
                    $this->ereignis(['event' => 'trust', 'state' => 'pending']);
                }
            } elseif ($ph === 'aborted') {
                $this->fehlerZustand('Gegenstelle hat den Verbindungsaufbau abgebrochen (Gateway dort nicht freigegeben?).');
            }
        } elseif (isset($msg->messageProtocolHandshake)) {
            $mph = $msg->messageProtocolHandshake;
            if (($mph->handshakeType ?? '') === 'select') {
                $fmt = $mph->formats->format ?? [];
                if ((int)($mph->version->major ?? 0) !== 1 || !in_array('JSON-UTF8', (array)$fmt, true)) {
                    $out .= $this->shipCtl(['messageProtocolHandshakeError' => ['error' => 2]]);
                    $this->fehlerZustand('Gegenstelle wählt ein nicht unterstütztes SHIP-Protokoll.');
                    return $out;
                }
                $out .= $this->shipCtl(['messageProtocolHandshake' => ['handshakeType' => 'select',
                    'version' => ['major' => 1, 'minor' => 0], 'formats' => ['format' => ['JSON-UTF8']]]]);
                $this->phase = 'pin';
                $out .= $this->shipCtl(['connectionPinState' => ['pinState' => 'none']]);
            }
        } elseif (isset($msg->connectionPinState)) {
            $ps = (string)($msg->connectionPinState->pinState ?? '');
            if ($ps !== 'none' && $ps !== 'pinOk') {
                $this->fehlerZustand('Gegenstelle verlangt eine PIN (' . $ps . '); das unterstützt das Gateway noch nicht.');
                return $out;
            }
            $this->phase = 'access';
            $out .= $this->shipCtl(['accessMethodsRequest' => new stdClass()]);
        } elseif (isset($msg->accessMethodsRequest)) {
            $out .= $this->shipCtl(['accessMethods' => ['id' => $this->localShipId]]);
        } elseif (isset($msg->accessMethods)) {
            if ($this->phase !== 'access') {
                return $out;
            }
            $this->remoteShipId = (string)($msg->accessMethods->id ?? '');
            $this->phase = 'data';
            $this->verbundenSeit = $jetzt;
            $this->l('   SHIP-Verbindung steht, Gegenstelle ' . $this->remoteShipId);
            $this->ereignis(['event' => 'connection', 'state' => 'connected', 'shipId' => $this->remoteShipId]);
            $out .= $this->spineSend(['addressSource' => $this->addr([0], 0), 'addressDestination' => ['entity' => [0], 'feature' => 0],
                'cmdClassifier' => 'read'], ['nodeManagementDetailedDiscoveryData' => new stdClass()]);
        } elseif (isset($msg->connectionClose)) {
            $cc = $msg->connectionClose;
            if (($cc->phase ?? '') === 'announce') {
                $out .= $this->shipCtl(['connectionClose' => ['phase' => 'confirm']]);
            }
            $this->getrennt('Gegenstelle beendet die Verbindung (' . ($cc->reason ?? 'ohne Grund') . ')');
            $this->phase = 'closed';
        } elseif (isset($msg->messageProtocolHandshakeError)) {
            $this->fehlerZustand('SHIP-Protokollfehler der Gegenstelle: ' . json_encode($msg->messageProtocolHandshakeError));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // SPINE
    // ------------------------------------------------------------------

    private function addr(array $entity, int $feature): array
    {
        return ['device' => $this->localDevice, 'entity' => $entity, 'feature' => $feature];
    }

    private function spineSend(array $header, array $cmd): string
    {
        $header['msgCounter'] = ++$this->msgCounter;
        $h = ['specificationVersion' => self::SPEC_VERSION];
        foreach (['addressSource', 'addressDestination', 'msgCounter', 'msgCounterReference', 'cmdClassifier', 'ackRequest'] as $k) {
            if (array_key_exists($k, $header)) {
                $h[$k] = $header[$k];
            }
        }
        if (isset($h['addressDestination']) && !isset($h['addressDestination']['device']) && $this->remoteDevice !== '') {
            $h['addressDestination'] = ['device' => $this->remoteDevice] + $h['addressDestination'];
        }
        $nachricht = ['data' => ['header' => ['protocolId' => 'ee1.0'],
            'payload' => ['datagram' => ['header' => $h, 'payload' => ['cmd' => [$cmd]]]]]];
        $name = isset($cmd['function']) ? $cmd['function'] : (string)array_key_first($cmd);
        $this->l(sprintf('-> SPINE #%d %s %s', $this->msgCounter, $h['cmdClassifier'], $name));
        return $this->frame("\x02" . EEBGWJson::render($nachricht));
    }

    private function onSpine(string $body, int $jetzt): string
    {
        $d = EEBGWJson::parse(trim($body, "\x00"));
        $dg = $d->data->payload->datagram ?? null;
        if (!($dg instanceof stdClass) || !isset($dg->header)) {
            $this->l('<- SPINE unlesbar');
            return '';
        }
        $h = $dg->header;
        $cmd = $dg->payload->cmd[0] ?? new stdClass();
        if (!($cmd instanceof stdClass)) {
            $cmd = new stdClass();
        }
        $fn = isset($cmd->function) ? (string)$cmd->function : (string)$this->ersterNutzschluessel($cmd);
        $klasse = (string)($h->cmdClassifier ?? '');
        $this->l(sprintf('<- SPINE #%s %s %s', $h->msgCounter ?? '?', $klasse, $fn));
        $src = $h->addressSource ?? new stdClass();
        $dst = $h->addressDestination ?? new stdClass();
        if ($this->remoteDevice === '' && isset($src->device)) {
            $this->remoteDevice = (string)$src->device;
        }
        $dstE = (array)($dst->entity ?? [0]);
        $dstF = (int)($dst->feature ?? 0);
        $antwort = function (array $c, string $cls) use ($h, $src, $dstE, $dstF): string {
            return $this->spineSend([
                'addressSource' => $this->addr($dstE, $dstF),
                'addressDestination' => ['device' => $src->device ?? $this->remoteDevice, 'entity' => $src->entity ?? [0], 'feature' => $src->feature ?? 0],
                'msgCounterReference' => $h->msgCounter ?? 0, 'cmdClassifier' => $cls], $c);
        };
        $ergebnis = function (int $nr, string $text = '') use ($antwort): string {
            $r = ['errorNumber' => $nr];
            if ($text !== '') {
                $r['description'] = $text;
            }
            return $antwort(['resultData' => $r], 'result');
        };
        $ack = !empty($h->ackRequest);

        switch ($klasse) {
            case 'read':
                $daten = $this->leseDaten($dstE, $dstF, $fn, $jetzt);
                if ($daten === null) {
                    $this->l('   Lesefunktion nicht unterstützt: ' . $fn . ' an ' . json_encode($dstE) . '/' . $dstF);
                    return $ergebnis(6, 'function not supported');
                }
                return $antwort([$fn => $daten], 'reply');

            case 'call':
                if ($fn === 'nodeManagementSubscriptionRequestCall') {
                    $r = $cmd->$fn->subscriptionRequest ?? null;
                    if ($r !== null) {
                        $this->abo($this->subscriptions, $r);
                        $this->l('   Abo auf ' . ($r->serverFeatureType ?? '?'));
                    }
                } elseif ($fn === 'nodeManagementSubscriptionDeleteCall') {
                    $this->aboLoeschen($this->subscriptions, $cmd->$fn->subscriptionDelete ?? null);
                } elseif ($fn === 'nodeManagementBindingRequestCall') {
                    $r = $cmd->$fn->bindingRequest ?? null;
                    if ($r !== null) {
                        $this->abo($this->bindings, $r);
                        $this->l('   Bindung auf ' . ($r->serverFeatureType ?? '?'));
                    }
                } elseif ($fn === 'nodeManagementBindingDeleteCall') {
                    $this->aboLoeschen($this->bindings, $cmd->$fn->bindingDelete ?? null);
                } else {
                    return $ack ? $ergebnis(6, 'call not supported') : '';
                }
                return $ack ? $ergebnis(0) : '';

            case 'write':
                [$nr, $text, $folge] = $this->schreiben($dstE, $dstF, $fn, $cmd, $src);
                return ($ack || $nr !== 0 ? $ergebnis($nr, $text) : '') . $folge;

            case 'reply':
            case 'notify':
                return $this->antwortVerarbeiten($fn, $cmd, $src, $jetzt);

            case 'result':
                $r = $cmd->resultData ?? null;
                if ($r !== null && (int)($r->errorNumber ?? 0) !== 0) {
                    $this->l('   Gegenstelle meldet Fehler ' . json_encode($r));
                }
                return '';
        }
        return '';
    }

    private function ersterNutzschluessel(stdClass $cmd): ?string
    {
        foreach ((array)$cmd as $k => $_) {
            if ($k !== 'filter' && $k !== 'manufacturerSpecificExtension' && $k !== 'lastUpdateAt') {
                return (string)$k;
            }
        }
        return null;
    }

    private function abo(array &$liste, stdClass $r): void
    {
        $eintrag = [
            'client' => ['device' => (string)($r->clientAddress->device ?? $this->remoteDevice), 'entity' => (array)($r->clientAddress->entity ?? []), 'feature' => (int)($r->clientAddress->feature ?? 0)],
            'server' => [(array)($r->serverAddress->entity ?? []), (int)($r->serverAddress->feature ?? 0)],
            'type' => (string)($r->serverFeatureType ?? ''),
        ];
        foreach ($liste as $vorhanden) {
            if ($vorhanden['client'] == $eintrag['client'] && $vorhanden['server'] == $eintrag['server']) {
                return;
            }
        }
        $liste[] = $eintrag;
    }

    private function aboLoeschen(array &$liste, $r): void
    {
        if (!($r instanceof stdClass)) {
            return;
        }
        $client = [(array)($r->clientAddress->entity ?? []), (int)($r->clientAddress->feature ?? 0)];
        $server = [(array)($r->serverAddress->entity ?? []), (int)($r->serverAddress->feature ?? 0)];
        $liste = array_values(array_filter($liste, function ($e) use ($client, $server) {
            return !([$e['client']['entity'], $e['client']['feature']] == $client && $e['server'] == $server);
        }));
    }

    private function entity(int $adresse): ?array
    {
        foreach ($this->model['entities'] as $e) {
            if ($e['address'] === $adresse) {
                return $e;
            }
        }
        return null;
    }

    private function feature(array $entity, int $index): ?array
    {
        foreach ($entity['features'] as $f) {
            if ($f['index'] === $index) {
                return $f;
            }
        }
        return null;
    }

    /** Antwortdaten für eine Lese-Anfrage, null = nicht unterstützt. */
    private function leseDaten(array $e, int $f, string $fn, int $jetzt)
    {
        if ($e === [0]) {
            if ($f === 0) {
                switch ($fn) {
                    case 'nodeManagementDetailedDiscoveryData':
                        return $this->discovery();
                    case 'nodeManagementUseCaseData':
                        return $this->useCaseData();
                    case 'nodeManagementSubscriptionData':
                        return ['subscriptionEntry' => array_map(function ($s, $i) {
                            return ['subscriptionId' => $i, 'clientAddress' => $s['client'],
                                'serverAddress' => ['device' => $this->localDevice, 'entity' => $s['server'][0], 'feature' => $s['server'][1]]];
                        }, $this->subscriptions, array_keys($this->subscriptions))];
                    case 'nodeManagementBindingData':
                        return ['bindingEntry' => array_map(function ($s, $i) {
                            return ['bindingId' => $i, 'clientAddress' => $s['client'],
                                'serverAddress' => ['device' => $this->localDevice, 'entity' => $s['server'][0], 'feature' => $s['server'][1]]];
                        }, $this->bindings, array_keys($this->bindings))];
                }
                return null;
            }
            if ($f === 1 && $fn === 'deviceClassificationManufacturerData') {
                return ['deviceName' => 'EEBUSGateway', 'deviceCode' => 'NRG-Stack', 'brandName' => 'NRG-Stack', 'vendorName' => 'DG65'];
            }
            return null;
        }
        $ent = count($e) === 1 ? $this->entity((int)$e[0]) : null;
        $feat = $ent === null ? null : $this->feature($ent, $f);
        if ($feat === null || !in_array('read', (array)($feat['functions'][$fn] ?? []), true)) {
            return null;
        }
        if ($feat['type'] === 'DeviceDiagnosis' && $fn === 'deviceDiagnosisHeartbeatData') {
            return $this->heartbeatDaten($jetzt, false);
        }
        $daten = $this->data[$ent['address'] . '/' . $f][$fn] ?? null;
        // Noch keine Daten vom Kind: leere Liste statt Fehler (wie eebus-go bei leerem Feature).
        return $daten === null ? new stdClass() : $daten;
    }

    private function heartbeatDaten(int $jetzt, bool $weiterzaehlen): array
    {
        if ($weiterzaehlen) {
            $this->hbCounter++;
        }
        return ['timestamp' => gmdate('Y-m-d\TH:i:s\Z', $jetzt), 'heartbeatCounter' => $this->hbCounter, 'heartbeatTimeout' => self::HEARTBEAT_TIMEOUT];
    }

    private function discovery(): array
    {
        $fset = function (string $f, array $ops): array {
            $o = new stdClass();
            foreach ($ops as $op) {
                $o->$op = new stdClass();
            }
            return ['function' => $f, 'possibleOperations' => $o];
        };
        $feat = function (array $e, int $f, string $typ, string $rolle, array $fns): array {
            $d = ['featureAddress' => ['entity' => $e, 'feature' => $f], 'featureType' => $typ, 'role' => $rolle];
            if ($fns !== []) {
                $d['supportedFunction'] = $fns;
            }
            return ['description' => $d];
        };
        $entities = [['description' => ['entityAddress' => ['entity' => [0]], 'entityType' => 'DeviceInformation']]];
        $features = [
            $feat([0], 0, 'NodeManagement', 'special', [
                $fset('nodeManagementDetailedDiscoveryData', ['read']), $fset('nodeManagementUseCaseData', ['read']),
                $fset('nodeManagementSubscriptionData', ['read']), $fset('nodeManagementSubscriptionRequestCall', []),
                $fset('nodeManagementSubscriptionDeleteCall', []), $fset('nodeManagementBindingData', ['read']),
                $fset('nodeManagementBindingRequestCall', []), $fset('nodeManagementBindingDeleteCall', [])]),
            $feat([0], 1, 'DeviceClassification', 'server', [$fset('deviceClassificationManufacturerData', ['read'])]),
        ];
        foreach ($this->model['entities'] as $e) {
            $entities[] = ['description' => ['entityAddress' => ['entity' => [$e['address']]], 'entityType' => $e['type']]];
            foreach ($e['features'] as $f) {
                $fns = [];
                foreach ($f['functions'] as $name => $ops) {
                    $fns[] = $fset((string)$name, (array)$ops);
                }
                $features[] = $feat([$e['address']], $f['index'], $f['type'], $f['role'], $fns);
            }
        }
        return [
            'specificationVersionList' => ['specificationVersion' => [self::SPEC_VERSION]],
            'deviceInformation' => ['description' => ['deviceAddress' => ['device' => $this->localDevice],
                'deviceType' => $this->deviceType, 'networkFeatureSet' => 'smart']],
            'entityInformation' => $entities,
            'featureInformation' => $features,
        ];
    }

    private function useCaseData(): array
    {
        $info = [];
        foreach ($this->model['entities'] as $e) {
            $jeAkteur = [];
            foreach ($e['useCases'] as $uc) {
                $jeAkteur[(string)($uc['actor'] ?? '')][] = [
                    'useCaseName' => (string)($uc['name'] ?? ''),
                    'useCaseVersion' => (string)($uc['version'] ?? '1.0.0'),
                    'useCaseAvailable' => (bool)($uc['available'] ?? true),
                    'scenarioSupport' => array_map('intval', (array)($uc['scenarios'] ?? [])),
                    'useCaseDocumentSubRevision' => (string)($uc['subRevision'] ?? 'release'),
                ];
            }
            foreach ($jeAkteur as $akteur => $liste) {
                $info[] = ['address' => ['device' => $this->localDevice, 'entity' => [$e['address']]], 'actor' => $akteur, 'useCaseSupport' => $liste];
            }
        }
        return ['useCaseInformation' => $info];
    }

    /** Rückgabe [errorNumber, Text, Folgenachrichten]. */
    private function schreiben(array $e, int $f, string $fn, stdClass $cmd, stdClass $src): array
    {
        $ent = count($e) === 1 ? $this->entity((int)$e[0]) : null;
        $feat = $ent === null ? null : $this->feature($ent, $f);
        if ($feat === null || !in_array('write', (array)($feat['functions'][$fn] ?? []), true)) {
            $this->l('   Schreiben nicht erlaubt: ' . $fn . ' an ' . json_encode($e) . '/' . $f);
            return [7, 'write not allowed', ''];
        }
        // SPINE: Schreiben nur von gebundenen Clients. Nicht hart verweigern (manche Geräte binden
        // nicht), aber vermerken – das Modul zeigt es im Protokoll.
        $gebunden = false;
        foreach ($this->bindings as $b) {
            if ($b['server'] == [$e, $f] && $b['client']['entity'] == (array)($src->entity ?? []) && $b['client']['feature'] === (int)($src->feature ?? 0)) {
                $gebunden = true;
            }
        }
        if (!$gebunden) {
            $this->l('   Hinweis: Schreiben ohne vorherige Bindung');
        }
        $neu = json_decode((string)json_encode($cmd->$fn ?? new stdClass()), true) ?: [];
        $teilweise = false;
        foreach ((array)($cmd->filter ?? []) as $filter) {
            if (isset($filter->cmdControl->partial)) {
                $teilweise = true;
            }
            if (isset($filter->cmdControl->delete)) {
                return [6, 'delete not supported', ''];
            }
        }
        $schluessel = $ent['address'] . '/' . $f;
        $alt = $this->data[$schluessel][$fn] ?? [];
        [$gesamt, $geaendert] = self::zusammenfuehren($fn, is_array($alt) ? $alt : [], $neu, $teilweise);
        $this->data[$schluessel][$fn] = $gesamt;
        if ($geaendert === []) {
            $this->l('   Schreibbefehl ohne Änderung gegenüber dem gemeldeten Stand');
            return [0, '', $this->notify($ent['address'], $f, $fn)];
        }
        $this->ereignis(['event' => 'write', 'owner' => $ent['owner'], 'entity' => $ent['address'], 'feature' => $feat['type'],
            'function' => $fn, 'changes' => $geaendert, 'data' => $gesamt, 'bound' => $gebunden]);
        return [0, '', $this->notify($ent['address'], $f, $fn)];
    }

    /**
     * Schreibdaten in den bisherigen Stand einarbeiten.
     * Rückgabe [Gesamtstand, geänderte Einträge]. Bei Listen-Funktionen sind die geänderten
     * Einträge die gegenüber dem bisherigen Stand wirklich veränderten (Befund Prototyp:
     * controlbox schickt immer die ganze Liste, teils mit veraltetem Stand der anderen Grenze).
     */
    public static function zusammenfuehren(string $fn, array $alt, array $neu, bool $teilweise): array
    {
        $def = self::LISTEN_SCHLUESSEL[$fn] ?? null;
        if ($def === null) {
            return [$neu, $neu == $alt ? [] : [$neu]];
        }
        [$liste, $id] = $def;
        $altListe = [];
        foreach ((array)($alt[$liste] ?? []) as $eintrag) {
            if (isset($eintrag[$id])) {
                $altListe[(string)$eintrag[$id]] = $eintrag;
            }
        }
        $ergebnis = $teilweise ? $altListe : [];
        $geaendert = [];
        foreach ((array)($neu[$liste] ?? []) as $eintrag) {
            if (!isset($eintrag[$id])) {
                continue;
            }
            $k = (string)$eintrag[$id];
            $vorher = $altListe[$k] ?? [];
            // Felder, die der Schreibbefehl weglässt, bleiben (partial) – timePeriod fehlt bei
            // aufgehobener Befristung ganz und muss dann auch entfallen.
            $zusammen = $teilweise ? array_merge($vorher, $eintrag) : $eintrag;
            if ($teilweise && isset($vorher['timePeriod']) && !isset($eintrag['timePeriod'])) {
                unset($zusammen['timePeriod']);
            }
            $ergebnis[$k] = $zusammen;
            if ($zusammen != $vorher) {
                $geaendert[] = $zusammen;
            }
        }
        return [[$liste => array_values($ergebnis)], $geaendert];
    }

    /** notify an alle Abonnenten eines Features. */
    public function notify(int $entity, int $feature, string $fn): string
    {
        if ($this->phase !== 'data') {
            return '';
        }
        $out = '';
        foreach ($this->subscriptions as $s) {
            if ($s['server'] != [[$entity], $feature]) {
                continue;
            }
            $daten = $fn === 'deviceDiagnosisHeartbeatData' ? $this->heartbeatDaten(time(), false) : ($this->data[$entity . '/' . $feature][$fn] ?? new stdClass());
            $out .= $this->spineSend(['addressSource' => $this->addr([$entity], $feature),
                'addressDestination' => $s['client'], 'cmdClassifier' => 'notify'], [$fn => $daten]);
        }
        return $out;
    }

    /** Kind hat neue Feature-Daten gemeldet: übernehmen und Abonnenten benachrichtigen. */
    public function datenSetzen(int $entity, int $feature, string $fn, array $daten): string
    {
        $alt = $this->data[$entity . '/' . $feature][$fn] ?? null;
        $this->data[$entity . '/' . $feature][$fn] = $daten;
        return $alt == $daten ? '' : $this->notify($entity, $feature, $fn);
    }

    private function antwortVerarbeiten(string $fn, stdClass $cmd, stdClass $src, int $jetzt): string
    {
        $daten = $cmd->$fn ?? null;
        if ($fn === 'nodeManagementDetailedDiscoveryData' && $daten instanceof stdClass) {
            $this->remoteEntities = [];
            foreach ((array)($daten->entityInformation ?? []) as $e) {
                $adr = (array)($e->description->entityAddress->entity ?? []);
                $this->remoteEntities[json_encode($adr)] = ['entity' => $adr, 'type' => (string)($e->description->entityType ?? ''), 'features' => []];
            }
            foreach ((array)($daten->featureInformation ?? []) as $f) {
                $fd = $f->description ?? null;
                if (!($fd instanceof stdClass)) {
                    continue;
                }
                $k = json_encode((array)($fd->featureAddress->entity ?? []));
                $fns = [];
                foreach ((array)($fd->supportedFunction ?? []) as $sf) {
                    $fns[(string)($sf->function ?? '')] = array_keys((array)($sf->possibleOperations ?? []));
                }
                $this->remoteEntities[$k]['features'][] = ['feature' => (int)($fd->featureAddress->feature ?? 0),
                    'type' => (string)($fd->featureType ?? ''), 'role' => (string)($fd->role ?? ''), 'functions' => $fns];
            }
            $this->remoteEntities = array_values($this->remoteEntities);
            foreach ($this->remoteEntities as $e) {
                $this->l('   Gegenstelle Entität ' . json_encode($e['entity']) . ' ' . $e['type']);
            }
            $out = $this->spineSend(['addressSource' => $this->addr([0], 0), 'addressDestination' => ['entity' => [0], 'feature' => 0],
                'cmdClassifier' => 'read'], ['nodeManagementUseCaseData' => new stdClass()]);
            return $out . $this->heartbeatAbonnieren();
        }
        if ($fn === 'nodeManagementUseCaseData' && $daten instanceof stdClass) {
            $this->remoteUseCases = [];
            foreach ((array)($daten->useCaseInformation ?? []) as $ui) {
                foreach ((array)($ui->useCaseSupport ?? []) as $us) {
                    $this->remoteUseCases[] = ['entity' => (array)($ui->address->entity ?? []), 'actor' => (string)($ui->actor ?? ''),
                        'name' => (string)($us->useCaseName ?? ''), 'scenarios' => array_map('intval', (array)($us->scenarioSupport ?? [])),
                        'available' => (bool)($us->useCaseAvailable ?? true)];
                }
            }
            $this->ereignis(['event' => 'remote', 'useCases' => $this->remoteUseCases, 'entities' => array_map(function ($e) {
                return ['entity' => $e['entity'], 'type' => $e['type']];
            }, $this->remoteEntities)]);
            return '';
        }
        if ($fn === 'deviceDiagnosisHeartbeatData' && $daten instanceof stdClass) {
            $this->lastRemoteHb = $jetzt;
            $this->remoteHbTimeout = self::dauerSekunden((string)($daten->heartbeatTimeout ?? ''));
            if ($this->hbVerlorenGemeldet) {
                $this->hbVerlorenGemeldet = false;
                $this->l('   Heartbeat der Gegenstelle wieder da');
                $this->ereignis(['event' => 'heartbeat', 'state' => 'ok']);
            }
            return '';
        }
        return '';
    }

    private function heartbeatAbonnieren(): string
    {
        // Eigener DeviceDiagnosis-Client: erste Entität, die einen hat.
        $client = null;
        foreach ($this->model['entities'] as $e) {
            $i = $this->featureIndex($e['address'], 'DeviceDiagnosis', 'client');
            if ($i !== null) {
                $client = [$e['address'], $i];
                break;
            }
        }
        if ($client === null) {
            return '';
        }
        $out = '';
        foreach ($this->remoteEntities as $e) {
            foreach ($e['features'] as $f) {
                if ($f['type'] !== 'DeviceDiagnosis' || $f['role'] !== 'server' || !isset($f['functions']['deviceDiagnosisHeartbeatData'])) {
                    continue;
                }
                $server = ['device' => $this->remoteDevice, 'entity' => $e['entity'], 'feature' => $f['feature']];
                $out .= $this->spineSend(['addressSource' => $this->addr([0], 0), 'addressDestination' => ['entity' => [0], 'feature' => 0],
                    'cmdClassifier' => 'call', 'ackRequest' => true], ['nodeManagementSubscriptionRequestCall' => ['subscriptionRequest' => [
                    'clientAddress' => $this->addr([$client[0]], $client[1]), 'serverAddress' => $server, 'serverFeatureType' => 'DeviceDiagnosis']]]);
                $out .= $this->spineSend(['addressSource' => $this->addr([$client[0]], $client[1]), 'addressDestination' => $server,
                    'cmdClassifier' => 'read'], ['deviceDiagnosisHeartbeatData' => new stdClass()]);
                $this->hbAbonniert = true;
                return $out;
            }
        }
        return $out;
    }

    /** ISO-8601-Dauer (PT1H2M3S) in Sekunden, 0 bei Fehler. */
    public static function dauerSekunden(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $iso, $m)) {
            return 0;
        }
        return (int)((int)($m[1] ?? 0) * 86400 + (int)($m[2] ?? 0) * 3600 + (int)($m[3] ?? 0) * 60 + (float)($m[4] ?? 0));
    }

    /** Zeittakt: eigener Heartbeat und Überwachung des Heartbeats der Gegenstelle. */
    public function tick(int $jetzt): string
    {
        if ($this->phase !== 'data') {
            return '';
        }
        $out = '';
        if ($jetzt - $this->lastOwnHb >= self::HEARTBEAT_INTERVALL_S) {
            $this->lastOwnHb = $jetzt;
            $this->hbCounter++;
            foreach ($this->model['entities'] as $e) {
                $i = $this->featureIndex($e['address'], 'DeviceDiagnosis', 'server');
                if ($i !== null) {
                    $out .= $this->notify($e['address'], $i, 'deviceDiagnosisHeartbeatData');
                }
            }
        }
        if ($this->hbAbonniert && !$this->hbVerlorenGemeldet) {
            $seit = $this->lastRemoteHb > 0 ? $this->lastRemoteHb : $this->verbundenSeit;
            $grenze = max(self::HEARTBEAT_VERLOREN_S, $this->remoteHbTimeout);
            if ($jetzt - $seit > $grenze) {
                $this->hbVerlorenGemeldet = true;
                $this->l('   Heartbeat der Gegenstelle seit ' . ($jetzt - $seit) . ' s ausgeblieben');
                $this->ereignis(['event' => 'heartbeat', 'state' => 'lost', 'since' => $seit]);
            }
        }
        return $out;
    }
}
