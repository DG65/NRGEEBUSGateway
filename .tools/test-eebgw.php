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
$GLOBALS['LANG'] = 'de';
$GLOBALS['TRANSLATE_AUFRUFE'] = 0;
$GLOBALS['LOCALE'] = json_decode((string)file_get_contents(dirname(__DIR__) . '/EEBUSGateway/locale.json'), true)['translations']['de'] ?? [];

/** Formular wie in der Konsole: Symcon übersetzt feste caption/confirm-Texte selbst über locale.json. */
/** Jeder Symcon-Aufruf startet mit leerem Übersetzungsspeicher (Modulobjekt je Anfrage neu). */
function neueAnfrage($gw): void
{
    $r = new ReflectionProperty(get_class($gw), 'eebgwUebersetzt');
    $r->setValue($gw, []);
}

function formular($gw): array
{
    neueAnfrage($gw);
    $f = json_decode($gw->GetConfigurationForm(), true);
    $walk = function (&$x) use (&$walk) {
        if (!is_array($x)) {
            return;
        }
        foreach ($x as $k => &$v) {
            if (in_array($k, ['caption', 'confirm'], true) && is_string($v) && $GLOBALS['LANG'] === 'de') {
                $v = $GLOBALS['LOCALE'][$v] ?? $v;
            } else {
                $walk($v);
            }
        }
    };
    $walk($f);
    return $f;
}
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
// Nachgebildete DNS-SD-Instanz: $GLOBALS['MDNS'][Typ] = Liste von ZC_QueryService-Ergebnissen.
$GLOBALS['MDNS'] = [];
function IPS_GetInstanceListByModuleID(string $g): array { return $g === '{780B2D48-916C-4D59-AD35-5A429B2355A5}' ? [99001] : []; }
function ZC_QueryServiceType(int $id, string $typ, string $dom): array
{
    if (isset($GLOBALS['MDNS_LIVE'])) { return mdnsLiveTypen($typ); }
    return array_map(function ($e) use ($typ) { return ['Name' => $e['Name'], 'Type' => $typ, 'Domain' => 'local']; }, $GLOBALS['MDNS'][$typ] ?? []);
}
function ZC_QueryService(int $id, string $name, string $typ, string $dom): array
{
    if (isset($GLOBALS['MDNS_LIVE'])) { return mdnsLiveDienst($name, $typ); }
    return array_values(array_filter($GLOBALS['MDNS'][$typ] ?? [], function ($e) use ($name) { return $e['Name'] === $name; }));
}
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
    public function Translate($text) { $GLOBALS['TRANSLATE_AUFRUFE']++; return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['LOCALE'][$text] ?? $text) : $text; }
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

$form = formular($gw);
$flach = json_encode($form, JSON_UNESCAPED_UNICODE);
pruefe(strpos($flach, 'Wozu dieses Modul') !== false && strpos($flach, 'Über dieses Modul') !== false, 'Formular: Rahmen nach Konvention');
pruefe(strpos($flach, EEBGWCert::lesbar($gw->attrs['EigenerSki'])) !== false, 'Formular: eigener SKI sichtbar');
pruefe(strpos($flach, 'SteuerboxHub (Test)') !== false, 'Formular: angemeldetes Fachmodul sichtbar');
// Formular: Aufbau (SUITE.md 3a)
$captions = function (EEBUSGateway $gw) {
    $f = formular($gw);
    $out = [];
    foreach (array_merge($f['elements'], $f['actions']) as $e) {
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
pruefe($byName['📡  Zustand'][0] < $byName['🔌  Gegenstelle'][0], 'Aufbau: Reihenfolge nach Position (innerhalb der Elemente)');
$fRoh = formular($gw);
pruefe(in_array('🪪  Dieses Gateway', array_column($fRoh['actions'], 'caption'), true) && !in_array('🪪  Dieses Gateway', array_column($fRoh['elements'], 'caption'), true)
    && strpos(json_encode($fRoh, JSON_UNESCAPED_UNICODE), '"bereich"') === false, 'Aufbau: „Dieses Gateway“ im Aktionsbereich, Bereichsmerker nicht im Formular');
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
// SHIP Pairing Service
require_once dirname(__DIR__) . '/libs/EEBGWPairing.php';
$annex = EEBGWPairing::txtNormalisieren(['txtvers' => '1', 'parType' => 'fpSha256', 'forId' => 'i:983327_u:C8277H008F-3',
    'forPar' => 'C74B7855D3479415F62CC01E5F6D9A93EBC676057D85417ADA16FD1384338943', 'trustId' => 'i:46925_u:43652bk-2-gt1',
    'trustPar' => '2CC72E781F7A7D2A08D50196C50FEDF0F7BA583F43F76C8C0DDEC9EEF0D005B4', 'trustCurve' => 'secp256r1', 'type' => 'addCu',
    'trustNonce' => 'BDCEE427FA7208DF3C1F2A749BA6F4D4', 'alg' => 'hmacSha256', 'digest' => 'BCBB62B2176DA2CEE545784CEB1F2A55E049451B12A549C98E8CA213F001DA25']);
$annexGeheimnis = '7A37DCF81BDB50F8E92CFA4160CCB3DE';
pruefe(EEBGWPairing::digest($annexGeheimnis, $annex) === $annex['digest'], 'Pairing: HMAC = Testvektor Anhang A.3 der Spezifikation');
$ok = EEBGWPairing::pruefen($annex, $annexGeheimnis, $annex['forId'], $annex['forPar'], []);
pruefe($ok['ok'] === true, 'Pairing: gültige Ankündigung angenommen');
pruefe(EEBGWPairing::pruefen($annex, 'FFFFDCF81BDB50F8E92CFA4160CCB3DE', $annex['forId'], $annex['forPar'], [])['ok'] === false, 'Pairing: falsches Geheimnis abgelehnt');
pruefe(EEBGWPairing::pruefen($annex, $annexGeheimnis, 'andere-id', $annex['forPar'], [])['grund'] === 'Pairing announcement is meant for another device.', 'Pairing: fremde SHIP-ID abgelehnt');
pruefe(EEBGWPairing::pruefen($annex, $annexGeheimnis, $annex['forId'], str_repeat('A', 64), [])['ok'] === false, 'Pairing: fremder Fingerabdruck abgelehnt');
pruefe(EEBGWPairing::pruefen($annex, $annexGeheimnis, $annex['forId'], $annex['forPar'], [$annex['digest']])['grund'] === 'Pairing announcement was already used (replay).', 'Pairing: Wiederholung abgelehnt');
pruefe(EEBGWPairing::txtNormalisieren(['TXTVERS=1', 'trustnonce=AB'])['trustNonce'] === 'AB', 'Pairing: TXT als k=v-Liste, Schlüssel ohne Rücksicht auf Groß-/Kleinschreibung');

// Ablauf im Modul mit nachgebildeter DNS-SD-Instanz
$pg = new EEBUSGateway(10200);
$pg->parentId = 10001;
$pg->Create();
$pg->ApplyChanges();
neueAnfrage($pg);
$antwort = $pg->PairingStarten();
$daten = json_decode((string)json_encode((new ReflectionMethod($pg, 'pairingDaten'))->invoke($pg)), true);
pruefe(strpos($antwort, 'SHIP;SKI:' . $pg->attrs['EigenerSki']) !== false && strlen($daten['geheimnis']) === 32 && $pg->timers['Pairing'] === 30000,
    'Pairing starten: Geheimnis, Kopplungsdaten, Suche alle 30 s');
$qr = $pg->form['PairingQr']['image'] ?? '';
$qrDatei = sys_get_temp_dir() . '/eebgw-qr.png';
file_put_contents($qrDatei, (string)base64_decode(substr($qr, 22)));
$gelesen = trim((string)shell_exec('zbarimg -q --raw -Sbinary ' . escapeshellarg($qrDatei) . ' 2>/dev/null'));
if (trim((string)shell_exec('command -v zbarimg')) !== '') {
    pruefe($gelesen === $daten['text'], 'Pairing: QR-Code im Formular liest zbarimg als Kopplungstext zurück');
} else {
    echo "  --   QR-Gegenprüfung übersprungen (zbarimg fehlt)\n";
}
pruefe(strpos($daten['text'], ';FPH256:' . EEBGWPairing::fingerabdruck($pg->attrs['Zertifikat']) . ';') !== false && strpos($daten['text'], ';ID:' . $daten['shipId'] . ';') !== false,
    'Pairing: Kopplungstext trägt SHIP-ID und SHA-256-Fingerabdruck');
$fremd = EEBGWCert::erzeugen('Steuerbox');
$ank = ['txtvers' => '1', 'parType' => 'fpSha256', 'forId' => $daten['shipId'], 'forPar' => $daten['fingerabdruck'], 'trustId' => 'Test-Steuerbox-1',
    'trustPar' => EEBGWPairing::fingerabdruck($fremd['cert']), 'trustCurve' => 'secp256r1', 'type' => 'addCu', 'trustNonce' => strtoupper(bin2hex(random_bytes(16))), 'alg' => 'hmacSha256'];
$ank['digest'] = EEBGWPairing::digest('00' . substr($daten['geheimnis'], 2), $ank);
$txtListe = function (array $a) { $o = []; foreach ($a as $k => $v) { $o[] = $k . '=' . $v; } return $o; };
$GLOBALS['MDNS']['_shippairing._tcp'] = [['Name' => 'Steuerbox', 'Host' => 'box.local', 'Port' => 4712, 'TXTRecords' => $txtListe($ank), 'IPv4' => ['192.0.2.10'], 'IPv6' => []]];
$pg->PairingSuchen();
$stand = json_decode($pg->attrs['PairingStand'], true);
pruefe($stand['vorlage'] === 'Pairing proof is wrong (secret does not match).' && $stand['trustId'] === '', 'Pairing-Suche: falscher Nachweis abgelehnt, nichts übernommen');
$ank['digest'] = EEBGWPairing::digest($daten['geheimnis'], $ank);
$GLOBALS['MDNS']['_shippairing._tcp'][0]['TXTRecords'] = $txtListe($ank);
$pg->PairingSuchen();
$stand = json_decode($pg->attrs['PairingStand'], true);
pruefe($stand['trustId'] === 'Test-Steuerbox-1' && $stand['vorlage'] === 'Pairing proof valid; waiting for the SHIP service of %s.' && count(json_decode($pg->attrs['PairingVerlauf'], true)) === 1,
    'Pairing-Suche: gültiger Nachweis gemerkt, wartet auf den SHIP-Dienst');
neueAnfrage($pg);
$GLOBALS['LANG'] = 'de';
$fp = formular($pg);
pruefe(strpos(json_encode($fp, JSON_UNESCAPED_UNICODE), 'Kopplungsnachweis gültig; warte auf den SHIP-Dienst von Test-Steuerbox-1') !== false, 'Formular: Pairing-Zustand sichtbar');
neueAnfrage($pg);
pruefe(strpos($pg->PairingBeenden(), 'wartet nicht mehr') !== false && $pg->timers['Pairing'] === 0, 'Pairing beenden: Suche aus');
unset($GLOBALS['INST'][10200]);
$GLOBALS['MDNS'] = [];

// Zweisprachigkeit (SUITE.md Konventionen 9)
require_once __DIR__ . '/i18n-texte.php';
$quellen = eebgwQuelltexte(dirname(__DIR__));
$fehlend = array_diff_key($quellen, $GLOBALS['LOCALE']);
pruefe($fehlend === [], 'Zweisprachig: jeder Quelltext steht in locale.json' . ($fehlend ? ' – fehlt: ' . implode(' | ', array_keys($fehlend)) : ''));
pruefe(array_diff_key($GLOBALS['LOCALE'], $quellen) === [], 'Zweisprachig: keine verwaisten locale.json-Einträge');
$platz = [];
foreach ($GLOBALS['LOCALE'] as $en => $de) {
    if (substr_count($en, '%s') !== substr_count($de, '%s') || $de === '') {
        $platz[] = $en;
    }
}
pruefe($platz === [], 'Zweisprachig: Platzhalter %s in beiden Sprachen gleich' . ($platz ? ' – ' . implode(' | ', $platz) : ''));
$deutschImCode = [];
foreach (array_merge([dirname(__DIR__) . '/EEBUSGateway/module.php'], glob(dirname(__DIR__) . '/libs/*.php')) as $datei) {
    foreach (token_get_all((string)file_get_contents($datei)) as $tk) {
        if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/[äöüÄÖÜß]/u', $tk[1])) {
            $deutschImCode[] = basename($datei) . ':' . $tk[2];
        }
    }
}
pruefe($deutschImCode === [], 'Zweisprachig: kein deutscher Text mehr im Code' . ($deutschImCode ? ' – ' . implode(', ', $deutschImCode) : ''));
$gesammelt = function (array $f): array {
    $out = [];
    array_walk_recursive($f, function ($v, $k) use (&$out) {
        if (in_array($k, ['caption', 'confirm', 'Panel'], true) && is_string($v)) {
            $out[] = $v;
        }
    });
    return $out;
};
$GLOBALS['LANG'] = 'de';
$texteDe = $gesammelt(formular($gw));
$englischGeblieben = array_values(array_filter($texteDe, function ($t) {
    return isset($GLOBALS['LOCALE'][$t]) && $GLOBALS['LOCALE'][$t] !== $t;
}));
pruefe($englischGeblieben === [], 'Formular deutsch: kein englischer Quelltext übrig' . ($englischGeblieben ? ' – ' . implode(' | ', $englischGeblieben) : ''));
$GLOBALS['LANG'] = 'en';
$texteEn = $gesammelt(formular($gw));
$deutschGeblieben = array_values(array_filter($texteEn, function ($t) {
    return preg_match('/[äöüÄÖÜß]|\b(der|die|das|und|nicht|Gegenstelle|Verbindung|Fehler|Zustand)\b/u', str_replace(['§ 14a EnWG', 'SteuerboxHub (Test)'], '', $t)) === 1;
}));
pruefe($deutschGeblieben === [], 'Formular englisch: kein deutscher Text' . ($deutschGeblieben ? ' – ' . implode(' | ', $deutschGeblieben) : ''));
if (getenv('EEBGW_FORM_EN')) {
    echo implode("\n", array_map(function ($t) { return '      | ' . $t; }, $texteEn)), "\n";
}
neueAnfrage($gw);
pruefe(strpos($gw->GegenstellePruefen(), 'remote device') !== false, 'Meldung englisch, wenn die Konsole englisch ist');
$GLOBALS['LANG'] = 'de';
$GLOBALS['TRANSLATE_AUFRUFE'] = 0;
formular($gw);
pruefe($GLOBALS['TRANSLATE_AUFRUFE'] < 80, 'Stolperstein 24: ' . $GLOBALS['TRANSLATE_AUFRUFE'] . ' Translate()-Aufrufe je Formular (< 80)');
$GLOBALS['LANG'] = 'de';

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

// ---------------------------------------------------------------------------
// Teil 3 (--live-pairing <controlbox> <cert> <key>): controlbox als Ankündiger (SHIP Pairing
// Service) mit den Kopplungsdaten des Gateways. Das Gateway sucht per mDNS (macOS dns-sd),
// prüft den HMAC der echten Go-Implementierung, übernimmt die Gegenstelle und verbindet sich –
// ohne dass controlbox den SKI des Gateways kennt.
$lp = array_search('--live-pairing', $argv, true);
if ($lp !== false) {
    [$bin, $cbCert, $cbKey] = array_slice($argv, $lp + 1, 3);
    echo "\nTeil 3: SHIP Pairing Service live gegen controlbox\n";
    $port = 4713;
    $pgw = new EEBUSGateway(10300);
    $psock = new FakeSocket(10301);
    $pgw->parentId = 10301;
    $pgw->Create();
    $pgw->ApplyChanges();
    neueAnfrage($pgw);
    $pgw->PairingStarten();
    $d = (new ReflectionMethod($pgw, 'pairingDaten'))->invoke($pgw);
    $log = sys_get_temp_dir() . '/eebgw-controlbox-pairing.log';
    $proc = proc_open([$bin, '-port', (string)$port, '-certpath', $cbCert, '-keypath', $cbKey, '-target', $d['text']], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    $GLOBALS['MDNS_LIVE'] = true;
    try {
        $ende = time() + 60;
        do {
            sleep(3);
            neueAnfrage($pgw);
            $pgw->PairingSuchen();
            $stand = json_decode($pgw->attrs['PairingStand'], true);
            echo '      … ' . vsprintf($stand['vorlage'], $stand['werte'] ?? []) . "\n";
        } while (empty($stand['gekoppelt']) && time() < $ende);
        pruefe(!empty($stand['gekoppelt']), 'Pairing: HMAC der Go-Implementierung geprüft, Gegenstelle übernommen');
        pruefe($pgw->attrs['GegenstelleSki'] === (string)EEBGWCert::skiAusZertifikat((string)file_get_contents($cbCert)), 'Pairing: richtiger SKI gepinnt (ohne Eingabe)');
        pruefe($psock->config['Open'] === true && $psock->config['Port'] === $port, 'Pairing: Client Socket auf gefundene Adresse eingestellt (' . ($psock->config['Host'] ?? '') . ':' . ($psock->config['Port'] ?? '') . ')');
        // Verbinden wie der Client Socket
        $dir = sys_get_temp_dir();
        file_put_contents("$dir/eebgw-pc.pem", base64_decode($psock->config['Certificate']));
        file_put_contents("$dir/eebgw-pk.pem", base64_decode($psock->config['PrivateKey']));
        file_put_contents("$dir/eebgw-pca.pem", base64_decode($psock->config['CertificateAuthority']));
        $s = stream_socket_client('tls://' . $psock->config['Host'] . ':' . $port, $en, $es, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'local_cert' => "$dir/eebgw-pc.pem", 'local_pk' => "$dir/eebgw-pk.pem", 'cafile' => "$dir/eebgw-pca.pem",
            'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true]]));
        $GLOBALS['BRUECKE'] = $s;
        stream_set_blocking($s, false);
        $pgw->MessageSink(time(), 10301, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
        $bis = time() + 15;
        while (time() < $bis && ($pgw->vars['Verbunden'] ?? false) !== true) {
            $r = [$s]; $w = $x = null;
            if (stream_select($r, $w, $x, 1) > 0) {
                $daten = fread($s, 65536);
                if ($daten === '' && feof($s)) { break; }
                $pgw->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($daten, 'UTF-8', 'ISO-8859-1')]));
            }
        }
        pruefe(($pgw->vars['Verbunden'] ?? false) === true, 'Pairing: SHIP-Verbindung steht, controlbox vertraut dem Gateway über den Fingerabdruck');
        fclose($s);
        unset($GLOBALS['BRUECKE']);
    } finally {
        unset($GLOBALS['MDNS_LIVE']);
        proc_terminate($proc);
        proc_close($proc);
    }
}

/** dns-sd auf macOS kurz laufen lassen und die Ausgabe einsammeln. */
function dnssdLauf(array $args, int $sekunden = 3): string
{
    $p = proc_open(array_merge(['dns-sd'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $ende = microtime(true) + $sekunden;
    while (microtime(true) < $ende) {
        $out .= (string)stream_get_contents($pipes[1]);
        usleep(100000);
    }
    proc_terminate($p);
    proc_close($p);
    return $out;
}

function mdnsLiveTypen(string $typ): array
{
    $namen = [];
    foreach (explode("\n", dnssdLauf(['-B', $typ, 'local.'])) as $z) {
        if (preg_match('/\sAdd\s+\d+\s+\d+\s+local\.\s+' . preg_quote($typ, '/') . '\.\s+(.+)$/', $z, $m)) {
            $namen[trim($m[1])] = ['Name' => trim($m[1]), 'Type' => $typ, 'Domain' => 'local'];
        }
    }
    return array_values($namen);
}

function mdnsLiveDienst(string $name, string $typ): array
{
    $o = dnssdLauf(['-L', $name, $typ, 'local.']);
    if (!preg_match('/can be reached at (\S+?)\.?:(\d+)/', $o, $m)) {
        return [];
    }
    $host = $m[1];
    $rest = substr($o, strpos($o, $m[0]) + strlen($m[0]));
    $rest = substr($rest, strpos($rest, "\n") + 1);
    $txt = [];
    foreach (preg_split('/(?<!\\\\) /', trim(strtok($rest, "\n"))) as $teil) {
        if (strpos($teil, '=') !== false) {
            $txt[] = str_replace('\\ ', ' ', $teil);
        }
    }
    $ip = gethostbyname($host);
    return [['Name' => $name, 'Host' => $host, 'Port' => (int)$m[2], 'TXTRecords' => $txt, 'IPv4' => $ip !== $host ? [$ip] : [], 'IPv6' => []]];
}

echo "\n" . $GLOBALS['OK'] . ' bestanden, ' . $GLOBALS['FEHLER'] . " fehlgeschlagen\n";
exit($GLOBALS['FEHLER'] > 0 ? 1 : 0);
