<?php
/**
 * test-eebgw.php — Prüfstand für das EEBUSGateway ohne laufendes Symcon.
 *
 * Teil 1 (immer): EEBUS-JSON, Zertifikat/SKI, Modellbau, Zusammenführen von
 *   Schreibbefehlen, Formular, Kind-Anmeldung.
 * Teil 2 (--live <controlbox-binär> <cert> <key>): startet eebus-go controlbox,
 *   prüft die Gegenstelle mit GegenstellePruefen(), verbindet das Modul über eine
 *   echte TLS-Verbindung (wie der Client Socket: Client-Zertifikat + Pinning) und
 *   erwartet, dass die LPC-Grenze als Schreibereignis beim Kind ankommt.
 *
 * Aufruf:   php .tools/test-eebgw.php [--live controlbox cb.crt cb.key [--strict]]
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IM_CHANGESTATUS = 10505;
const IS_ACTIVE = 102;
const KL_MESSAGE = 10201;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10205;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_STRING = 3;

$GLOBALS['INST'] = [];
$GLOBALS['FEHLER'] = 0;
$GLOBALS['OK'] = 0;

function pruefe(bool $b, string $text): void
{
    if ($b) {
        $GLOBALS['OK']++;
        echo "  ok   $text\n";
    } else {
        $GLOBALS['FEHLER']++;
        echo "  FEHL $text\n";
    }
}

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['INST'][$id]); }
function IPS_GetName(int $id): string { return $GLOBALS['INST'][$id]->name ?? ('#' . $id); }
function IPS_GetInstance(int $id): array
{
    $i = $GLOBALS['INST'][$id];
    return ['ConnectionID' => $i->parentId ?? 0, 'InstanceStatus' => $i->status ?? 102, 'ModuleInfo' => ['ModuleID' => $i->modul ?? '']];
}
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config); }
function IPS_SetConfiguration(int $id, string $c): bool { $GLOBALS['INST'][$id]->config = array_merge($GLOBALS['INST'][$id]->config, json_decode($c, true)); return true; }
function IPS_SetProperty(int $id, string $n, $v): bool { $GLOBALS['INST'][$id]->config[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $i = $GLOBALS['INST'][$id]; if (method_exists($i, 'anwenden')) { $i->anwenden(); } return true; }
function IPS_CreateInstance(string $guid): int { $id = 20000 + count($GLOBALS['INST']); new FakeSocket($id); return $id; }
function IPS_ConnectInstance(int $id, int $parent): bool { $GLOBALS['INST'][$id]->parentId = $parent; return true; }
function IPS_DeleteInstance(int $id): bool { unset($GLOBALS['INST'][$id]); return true; }
function IPS_SetName(int $id, string $n): bool { $GLOBALS['INST'][$id]->name = $n; return true; }
function IPS_GetLibrary(string $guid): array { $l = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true); return ['Version' => $l['version'], 'Build' => $l['build']]; }

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $buffers = [];
    public $timers = [];
    public $vars = [];
    public $status = 0;
    public $parentId = 0;
    public $name = 'EEBUS-Gateway';
    public $modul = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    public $gesendet = [];
    public $anKinder = [];
    public $log = [];
    public $debug = [];
    public $form = [];

    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RequireParent($g) {}
    protected function RegisterMessage($id, $m) {}
    protected function UnregisterMessage($id, $m) {}
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function SendDebug($t, $m, $f) { $this->debug[] = "$t: $m"; if (getenv('EEBGW_DEBUG')) { echo "      [dbg] $m\n"; } }
    protected function LogMessage($m, $t) { $this->log[] = $m; }
    protected function MaintainVariable($ident, $name, $typ, $profil, $pos, $behalten) { $this->vars[$ident] = $this->vars[$ident] ?? null; }
    protected function GetValue($ident) { return $this->vars[$ident] ?? null; }
    protected function SetValue($ident, $v) { $this->vars[$ident] = $v; }
    protected function UpdateFormField($f, $p, $v) { $this->form[$f][$p] = $v; }
    protected function SendDataToParent($json)
    {
        $d = json_decode($json, true);
        $this->gesendet[] = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
        if (isset($GLOBALS['BRUECKE'])) { fwrite($GLOBALS['BRUECKE'], end($this->gesendet)); }
        return '';
    }
    protected function SendDataToChildren($json) { $this->anKinder[] = json_decode($json, true); }
}

/** Nachgebildeter Client Socket. */
class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false, 'Host' => '', 'Port' => 0];
    public $status = 104;
    public $modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public $name = 'Client Socket';
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function anwenden(): void { $this->status = $this->config['Open'] ? 102 : 104; }
}

/** Platzhalter-Kind (so meldet sich später z. B. SteuerboxHub an). */
class FakeKind
{
    public $InstanceID;
    public $parentId;
    public $name = 'SteuerboxHub (Test)';
    public $modul = '{00000000-0000-0000-0000-00000000C11D}';
    public function __construct(int $id, int $parent) { $this->InstanceID = $id; $this->parentId = $parent; $GLOBALS['INST'][$id] = $this; }
}

require dirname(__DIR__) . '/EEBUSGateway/module.php';

function lpcAnmeldung(): array
{
    $ucs = [];
    foreach (['limitationOfPowerConsumption', 'limitationOfPowerProduction'] as $n) {
        $ucs[] = ['actor' => 'ControllableSystem', 'name' => $n, 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1, 2, 3, 4]];
    }
    return [
        'type' => 'CEM',
        'useCases' => $ucs,
        'features' => [
            ['type' => 'LoadControl', 'role' => 'server', 'functions' => ['loadControlLimitDescriptionListData' => ['read'], 'loadControlLimitListData' => ['read', 'write']]],
            ['type' => 'DeviceConfiguration', 'role' => 'server', 'functions' => ['deviceConfigurationKeyValueDescriptionListData' => ['read'], 'deviceConfigurationKeyValueListData' => ['read', 'write']]],
            ['type' => 'ElectricalConnection', 'role' => 'server', 'functions' => ['electricalConnectionCharacteristicListData' => ['read']]],
        ],
    ];
}

function lpcDaten(): array
{
    $ld = function (int $id, string $dir) {
        return ['limitId' => $id, 'limitType' => 'signDependentAbsValueLimit', 'limitCategory' => 'obligation', 'limitDirection' => $dir, 'measurementId' => 50, 'unit' => 'W', 'scopeType' => 'activePowerLimit'];
    };
    return [
        ['LoadControl', 'loadControlLimitDescriptionListData', ['loadControlLimitDescriptionData' => [$ld(0, 'consume'), $ld(1, 'produce')]]],
        ['LoadControl', 'loadControlLimitListData', ['loadControlLimitData' => [
            ['limitId' => 0, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => 4200, 'scale' => 0]],
            ['limitId' => 1, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => -10000, 'scale' => 0]]]]],
        ['DeviceConfiguration', 'deviceConfigurationKeyValueDescriptionListData', ['deviceConfigurationKeyValueDescriptionData' => [
            ['keyId' => 0, 'keyName' => 'failsafeConsumptionActivePowerLimit', 'valueType' => 'scaledNumber', 'unit' => 'W'],
            ['keyId' => 1, 'keyName' => 'failsafeDurationMinimum', 'valueType' => 'duration'],
            ['keyId' => 2, 'keyName' => 'failsafeProductionActivePowerLimit', 'valueType' => 'scaledNumber', 'unit' => 'W']]]],
        ['DeviceConfiguration', 'deviceConfigurationKeyValueListData', ['deviceConfigurationKeyValueData' => [
            ['keyId' => 0, 'value' => ['scaledNumber' => ['number' => 4200, 'scale' => 0]], 'isValueChangeable' => true],
            ['keyId' => 1, 'value' => ['duration' => 'PT2H'], 'isValueChangeable' => true],
            ['keyId' => 2, 'value' => ['scaledNumber' => ['number' => 4200, 'scale' => 0]], 'isValueChangeable' => true]]]],
        ['ElectricalConnection', 'electricalConnectionCharacteristicListData', ['electricalConnectionCharacteristicData' => [
            ['electricalConnectionId' => 0, 'parameterId' => 0, 'characteristicId' => 0, 'characteristicContext' => 'entity', 'characteristicType' => 'contractualConsumptionNominalMax', 'value' => ['number' => 32000, 'scale' => 0], 'unit' => 'W']]]],
    ];
}

function kindRuft(EEBUSGateway $gw, int $kind, array $d): array
{
    $d['DataID'] = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    $d['Sender'] = $kind;
    return json_decode((string)$gw->ForwardData(json_encode($d)), true) ?: [];
}

// ---------------------------------------------------------------------------
echo "Teil 1: Bausteine\n";

$ee = '{"datagram":[{"header":[{"msgCounter":1}]},{"payload":[{"cmd":[[{"function":"x"},{"filter":[[{"cmdControl":[{"partial":[]}]}]]}]]}]}]}';
$obj = EEBGWJson::parse($ee);
pruefe(($obj->datagram->header->msgCounter ?? null) === 1, 'EEBUS-JSON lesen: Objekt aus Einzelschlüsseln');
pruefe(is_array($obj->datagram->payload->cmd) && ($obj->datagram->payload->cmd[0]->function ?? '') === 'x', 'EEBUS-JSON lesen: Liste bleibt Liste');
pruefe(EEBGWJson::render($obj) === $ee, 'EEBUS-JSON schreiben: Rundweg exakt');

$c = EEBGWCert::erzeugen('Test');
pruefe(strlen($c['ski']) === 40 && EEBGWCert::skiStimmt($c['cert']), 'Zertifikat: SKI = subjectKeyIdentifier = SHA-1(EC-Punkt)');
pruefe(EEBGWCert::lesbar('AB:CD:ef01') === 'abcd ef01', 'SKI lesbar/normalisiert');
pruefe(EEBGWSession::dauerSekunden('PT1H2M3S') === 3723 && EEBGWSession::dauerSekunden('PT30S') === 30, 'ISO-Dauer');

$m = EEBGWSession::buildModel([500 => lpcAnmeldung()]);
$typen = array_map(function ($f) { return $f['type'] . '/' . $f['role']; }, $m['entities'][0]['features']);
pruefe($m['entities'][0]['address'] === 1 && in_array('DeviceDiagnosis/server', $typen, true) && in_array('DeviceDiagnosis/client', $typen, true), 'Modell: Entität 1, Heartbeat-Features ergänzt');

$alt = ['loadControlLimitData' => [['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 100, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT1H']], ['limitId' => 1, 'isLimitActive' => false, 'value' => ['number' => -10000, 'scale' => 0]]]];
// Befund Prototyp: LPP-Schreibbefehl mit veraltetem LPC-Eintrag – darf LPC nicht als "geändert" melden, wenn er gleich ist.
[$neu, $ger] = EEBGWSession::zusammenfuehren('loadControlLimitListData', $alt, ['loadControlLimitData' => [
    ['limitId' => 0, 'isLimitActive' => true, 'value' => ['number' => 100, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT1H']],
    ['limitId' => 1, 'isLimitActive' => true, 'value' => ['number' => -100, 'scale' => 0]]]], true);
pruefe(count($ger) === 1 && $ger[0]['limitId'] === 1, 'Zusammenführen: nur wirklich geänderte Grenze gemeldet');
[$neu, $ger] = EEBGWSession::zusammenfuehren('loadControlLimitListData', $alt, ['loadControlLimitData' => [['limitId' => 0, 'isLimitActive' => false, 'value' => ['number' => 4200, 'scale' => 0]]]], true);
pruefe(!isset($neu['loadControlLimitData'][0]['timePeriod']) && count($neu['loadControlLimitData']) === 2, 'Zusammenführen: Befristung entfällt, andere Grenze bleibt');

$s = new EEBGWSession();
$s->model = $m;
$s->phase = 'data';
$s->hbAbonniert = true;
$s->verbundenSeit = 1000;
$s->tick(1000 + EEBGWSession::HEARTBEAT_VERLOREN_S - 1);
pruefe($s->drainEvents() === [], 'Heartbeat: vor Ablauf von 120 s kein Ausfall');
$s->tick(1000 + EEBGWSession::HEARTBEAT_VERLOREN_S + 1);
$ev = $s->drainEvents();
pruefe(($ev[0]['event'] ?? '') === 'heartbeat' && $ev[0]['state'] === 'lost', 'Heartbeat: Ausfall nach 120 s gemeldet (Ausfallgrenze greift im Kind)');
$s->tick(1000 + 500);
pruefe($s->drainEvents() === [], 'Heartbeat: Ausfall nur einmal gemeldet');

// Ohne Elterninstanz: Client Socket selbst anlegen (RequireParent wirkt live nicht).
$ohne = new EEBUSGateway(10100);
$ohne->Create();
$ohne->ApplyChanges();
pruefe($ohne->timers['Verbindung'] === 15000, 'Ohne Client Socket: Prüf-Timer 15 s gestartet');
$ohne->VerbindungPruefen();
$neuSock = $ohne->parentId ? $GLOBALS['INST'][$ohne->parentId] : null;
pruefe($neuSock instanceof FakeSocket && strpos($neuSock->name, 'EEBUS-Gateway Client Socket') === 0 && $ohne->timers['Verbindung'] === 0, 'Client Socket angelegt, verbunden, benannt; Timer aus');
unset($GLOBALS['INST'][10100], $GLOBALS['INST'][$ohne->parentId]);

$wz = new ReflectionMethod(EEBUSGateway::class, 'wartezeit');
pruefe([$wz->invoke(null, 1), $wz->invoke(null, 2), $wz->invoke(null, 4), $wz->invoke(null, 20)] === [5, 10, 40, 300], 'Wiederverbindung: Wartezeit 5/10/40 s, höchstens 5 min');

// Modul anlegen
$sock = new FakeSocket(10001);
$gw = new EEBUSGateway(10002);
$gw->parentId = 10001;
$gw->Create();
$gw->ApplyChanges();
pruefe(strlen($gw->attrs['EigenerSki']) === 40 && $gw->attrs['Schluessel'] !== '', 'Modul: Zertifikat beim ersten Übernehmen erzeugt');
pruefe($gw->status === 104 && $sock->config['Open'] === false, 'Modul: ohne Gegenstelle Status 104, Socket geschlossen');
pruefe(strpos($gw->GegenstellePruefen(), 'Adresse und Port') !== false, 'GegenstellePruefen ohne Adresse: klare Meldung');

$kind = new FakeKind(10003, 10002);
$r = kindRuft($gw, 10003, ['Cmd' => 'Register', 'Entity' => lpcAnmeldung()]);
pruefe(($r['ok'] ?? false) && $r['entity'] === 1 && $r['interface'] === '1.0', 'Kind-Anmeldung: Entität 1, Schnittstelle 1.0');
foreach (lpcDaten() as [$f, $fn, $d]) {
    $r = kindRuft($gw, 10003, ['Cmd' => 'SetData', 'Feature' => $f, 'Function' => $fn, 'Data' => $d]);
}
pruefe(($r['ok'] ?? false) === true, 'Kind-Daten übernommen');
$r = kindRuft($gw, 10003, ['Cmd' => 'SetData', 'Feature' => 'Measurement', 'Function' => 'x', 'Data' => []]);
pruefe(($r['ok'] ?? true) === false, 'Kind-Daten für nicht angemeldetes Feature abgelehnt');

$form = json_decode($gw->GetConfigurationForm(), true);
$flach = json_encode($form, JSON_UNESCAPED_UNICODE);
pruefe(strpos($flach, 'Wozu dieses Modul') !== false && strpos($flach, 'Über dieses Modul') !== false, 'Formular: Rahmen nach Konvention');
pruefe(strpos($flach, EEBGWCert::lesbar($gw->attrs['EigenerSki'])) !== false, 'Formular: eigener SKI sichtbar');
pruefe(strpos($flach, 'SteuerboxHub (Test)') !== false, 'Formular: angemeldetes Fachmodul sichtbar');
// Formular: Aufbau (SUITE.md 3a)
$captions = function (EEBUSGateway $gw) {
    $f = json_decode($gw->GetConfigurationForm(), true);
    $out = [];
    foreach ($f['elements'] as $e) {
        $out[] = [$e['caption'] ?? '', $e['expanded'] ?? null, $e['visible'] ?? true];
    }
    return $out;
};
$namen = array_map(function ($c) { return $c[0]; }, $captions($gw));
$iG = array_search('🔌  Gegenstelle', $namen, true); $iA = array_search('🪟  Formular: Aufbau', $namen, true); $iF = array_search('💬  Rückmeldungen', $namen, true);
pruefe($iG !== false && $iA > $iG && $iF > $iA, 'Aufbau: Panel nach den Fachpanels, vor dem Forum-Hinweis');
$gw->props['FormLayout'] = json_encode([
    ['Key' => 'zustand', 'Pos' => 1, 'Opened' => true],
    ['Key' => 'gegenstelle', 'Pos' => 5, 'Closed' => true, 'Opened' => true],
    ['Key' => 'gateway', 'Pos' => 2, 'Hidden' => true, 'Closed' => true],
    ['Key' => 'gibtsnicht', 'Pos' => 1, 'Hidden' => true],
]);
$c = $captions($gw);
$byName = []; foreach ($c as $i => $x) { $byName[$x[0]] = [$i, $x[1], $x[2]]; }
pruefe($byName['📡  Zustand'][0] < $byName['🪪  Dieses Gateway'][0] && $byName['🪪  Dieses Gateway'][0] < $byName['🔌  Gegenstelle'][0], 'Aufbau: Reihenfolge nach Position');
pruefe($byName['📡  Zustand'][1] === true && $byName['🔌  Gegenstelle'][1] === false, 'Aufbau: Aufgeklappt/Zugeklappt, Zugeklappt hat Vorrang');
pruefe($byName['🪪  Dieses Gateway'][2] === false, 'Aufbau: Ausgeblendet = visible:false, Panel bleibt im Formular');
pruefe(isset($byName['🪟  Formular: Aufbau (1 ausgeblendet)']), 'Aufbau: Caption nennt die Zahl ausgeblendeter Bereiche; unbekannter Schlüssel ignoriert');
$gw->props['FormLayout'] = '[]';
$gw->form = [];
$antwort = $gw->ResetFormLayout();
$werte = json_decode($gw->form['FormLayout']['values'] ?? '[]', true);
pruefe(count($werte) === 3 && $werte[0]['Key'] === 'gegenstelle' && $werte[2]['Pos'] === 3 && !$werte[1]['Hidden'] && strpos($antwort, 'Übernehmen') !== false, 'Aufbau: Zurücksetzen per UpdateFormField, ohne Speichern');
$gw->props['FormLayout'] = 'kaputt';
$namen2 = array_map(function ($c) { return $c[0]; }, $captions($gw));
pruefe(array_search('🔌  Gegenstelle', $namen2, true) < array_search('📡  Zustand', $namen2, true), 'Aufbau: ungültiger Inhalt fällt auf Standard zurück');
$gw->props['FormLayout'] = '[]';
pruefe(strpos(file_get_contents(dirname(__DIR__) . '/EEBUSGateway/module.php'), 'IPS_LogMessage') === false, 'Logging nur über $this->LogMessage (Store-Checkliste 15)');

// ---------------------------------------------------------------------------
$live = array_search('--live', $argv, true);
if ($live !== false) {
    [$bin, $cbCert, $cbKey] = array_slice($argv, $live + 1, 3);
    echo "\nTeil 2: live gegen controlbox\n";
    $port = 4711;
    $log = sys_get_temp_dir() . '/eebgw-controlbox.log';
    $cmd = escapeshellarg($bin) . ' -port ' . $port . ' -certpath ' . escapeshellarg($cbCert) . ' -keypath ' . escapeshellarg($cbKey)
        . ' -remoteski ' . $gw->attrs['EigenerSki'] . ' > ' . escapeshellarg($log) . ' 2>&1';
    $proc = proc_open(['/bin/sh', '-c', 'exec ' . $cmd], [], $pipes);
    sleep(2);
    try {
        $gw->props['Host'] = '127.0.0.1';
        $gw->props['Port'] = $port;
        $gw->props['ErwarteterSki'] = (string)EEBGWCert::skiAusZertifikat((string)file_get_contents($cbCert));
        $gw->ApplyChanges();
        $antwort = $gw->GegenstellePruefen();
        pruefe(strpos($antwort, '✅') === 0, 'GegenstellePruefen gegen controlbox: ' . strtok($antwort, "\n"));
        pruefe($sock->config['Open'] === true && $sock->config['VerifyPeer'] === true && $sock->config['UseCertificate'] === true, 'Client Socket eingestellt (offen, Client-Zertifikat, Pinning)');

        // Brücke: echte TLS-Verbindung mit genau den Socket-Einstellungen.
        $dir = sys_get_temp_dir();
        file_put_contents("$dir/eebgw-c.pem", base64_decode($sock->config['Certificate']));
        file_put_contents("$dir/eebgw-k.pem", base64_decode($sock->config['PrivateKey']));
        file_put_contents("$dir/eebgw-ca.pem", base64_decode($sock->config['CertificateAuthority']));
        $ssl = ['local_cert' => "$dir/eebgw-c.pem", 'local_pk' => "$dir/eebgw-k.pem", 'cafile' => "$dir/eebgw-ca.pem",
            'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true];
        if (in_array('--strict', $argv, true)) {
            $ssl['ciphers'] = 'ECDHE-ECDSA-AES128-SHA256';
            $ssl['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        $s = stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        pruefe($s !== false, 'TLS mit Pinning aufgebaut' . ($s ? ' (' . stream_get_meta_data($s)['crypto']['cipher_name'] . ')' : ": $es"));
        $GLOBALS['BRUECKE'] = $s;
        stream_set_blocking($s, false);
        $gw->MessageSink(time(), 10001, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
        $ende = time() + 20;
        $grenze = null;
        while (time() < $ende && $grenze === null) {
            $r = [$s]; $w = $x = null;
            if (stream_select($r, $w, $x, 1) > 0) {
                $d = fread($s, 65536);
                if ($d === '' && feof($s)) { break; }
                $gw->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1')]));
            }
            $gw->Takt();
            foreach ($gw->anKinder as $e) {
                if (($e['Event'] ?? '') === 'write' && ($e['function'] ?? '') === 'loadControlLimitListData') {
                    foreach ($e['changes'] as $ch) {
                        if ($ch['limitId'] === 0 && !empty($ch['isLimitActive'])) { $grenze = $ch; }
                    }
                }
            }
        }
        $events = array_column($gw->anKinder, 'Event');
        pruefe(in_array('connection', $events, true) && $gw->vars['Verbunden'] === true, 'SHIP-Verbindung steht, Variable Verbunden = true');
        pruefe(in_array('remote', $events, true), 'Anwendungsfälle der Gegenstelle an Kinder gemeldet');
        pruefe($grenze !== null && ($grenze['value']['number'] ?? 0) === 100, 'LPC-Grenze 100 W als Schreibereignis beim Kind' . ($grenze ? ' (' . json_encode($grenze) . ')' : ''));
        pruefe(($gw->anKinder[array_key_last($gw->anKinder)]['Target'] ?? -1) >= 0, 'Ereignisse tragen Target');
        $state = json_decode($gw->GetState(), true);
        pruefe($state['connected'] === true && $state['remoteShipId'] !== '', 'GetState: verbunden mit ' . $state['remoteShipId']);
        pruefe($gw->status === 102, 'Instanzstatus 102');
        $cbLog = (string)file_get_contents($log);
        pruefe(strpos($cbLog, 'Consumption limit accepted') !== false, 'controlbox: „Consumption limit accepted“');
        fclose($s);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

echo "\n" . $GLOBALS['OK'] . ' bestanden, ' . $GLOBALS['FEHLER'] . " fehlgeschlagen\n";
exit($GLOBALS['FEHLER'] > 0 ? 1 : 0);
