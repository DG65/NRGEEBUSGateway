# EEBUSGateway – Kind-Schnittstelle 1.3

Wie sich ein Fachmodul (z. B. SteuerboxHub, später eine WP-Anbindung) als Kind-Instanz an das
EEBUSGateway hängt. Das Gateway kennt keine Fachlogik: Es baut aus den Anmeldungen der Kinder den
SPINE-Knoten, beantwortet Lese-Anfragen der Gegenstelle aus den Daten der Kinder, führt
Schreibbefehle zusammen und meldet sie weiter.

## module.json des Kinds

```json
"parentRequirements": ["{26D4DA14-6C0D-4686-AE00-D23234BAD90D}"],
"implemented": ["{12D8B8A8-3EE7-491F-A68A-D632304165D4}"]
```

Eigenständigkeit: Ein Kind darf das Gateway nicht voraussetzen (SteuerboxHub hat z. B. auch den
Kontakte-Weg). Ohne Elterninstanz einfach keine EEBUS-Funktion anbieten.

## Kind → Gateway (`SendDataToParent`)

Jede Anfrage ist ein JSON-Objekt mit `DataID` = `{26D4DA14-6C0D-4686-AE00-D23234BAD90D}`,
`Sender` = eigene Instanz-ID (Symcon liefert den Absender nicht mit) und `Cmd`. Die Antwort ist ein
JSON-String.

### `Register` – Entität anmelden (in `ApplyChanges()` des Kinds)

```json
{"DataID": "…", "Sender": 12345, "Cmd": "Register", "Entity": {
  "type": "CEM",
  "useCases": [
    {"actor": "ControllableSystem", "name": "limitationOfPowerConsumption", "version": "1.0.0", "subRevision": "release", "scenarios": [1,2,3,4]},
    {"actor": "ControllableSystem", "name": "limitationOfPowerProduction",  "version": "1.0.0", "subRevision": "release", "scenarios": [1,2,3,4]}
  ],
  "features": [
    {"type": "LoadControl", "role": "server", "functions": {"loadControlLimitDescriptionListData": ["read"], "loadControlLimitListData": ["read","write"]}},
    {"type": "DeviceConfiguration", "role": "server", "functions": {"deviceConfigurationKeyValueDescriptionListData": ["read"], "deviceConfigurationKeyValueListData": ["read","write"]}},
    {"type": "ElectricalConnection", "role": "server", "functions": {"electricalConnectionCharacteristicListData": ["read"]}}
  ]
}}
```

Antwort: `{"ok": true, "interface": "1.0", "entity": 1, "features": [{"index": 1, "type": "LoadControl", "role": "server"}, …]}`.

- Jedes Kind bekommt eine eigene Entität (1, 2, … nach Instanz-ID).
- `DeviceDiagnosis` (Server für den eigenen Heartbeat, Client für den Heartbeat der Gegenstelle)
  ergänzt das Gateway selbst. Den Heartbeat sendet und überwacht das Gateway.
- Ändert sich die Anmeldung, baut das Gateway die Verbindung neu auf (die Gegenstelle muss das
  Gerätemodell neu erfragen). Deshalb nur bei echten Änderungen neu anmelden – das Gateway
  vergleicht selbst und tut bei gleicher Anmeldung nichts.

### `SetData` – Feature-Daten melden

```json
{"DataID": "…", "Sender": 12345, "Cmd": "SetData", "Feature": "LoadControl",
 "Function": "loadControlLimitListData", "Data": {"loadControlLimitData": [ … ]}}
```

`Data` ist der SPINE-Inhalt der Funktion in normalem JSON (das Gateway wandelt ins EEBUS-Format).
Geänderte Daten meldet das Gateway sofort an alle Abonnenten der Gegenstelle (`notify`).
Antwort `{"ok": true}` oder `{"ok": false, "error": "…"}`.

### `GetState`, `Unregister`

`GetState` liefert dasselbe wie `EEBGW_GetState()`: `connected`, `phase`, `trustPending`,
`ownSki`, `remoteSki`, `remoteShipId`, `remoteUseCases`, `remoteHeartbeat`, `heartbeatLost`,
`connectedSince`, `contractVersion`.

## Gateway → Kind (`ReceiveData` des Kinds)

`DataID` = `{12D8B8A8-3EE7-491F-A68A-D632304165D4}`. Alle Kinder bekommen alle Pakete; `Target` ist
die Instanz-ID des gemeinten Kinds oder `0` für alle.

| `Event` | Felder | Bedeutung |
|---|---|---|
| `connection` | `state` (`connected`/`disconnected`), `shipId` | SHIP-Verbindung steht bzw. ist weg |
| `trust` | `state` = `pending` | Gegenstelle hat das Gateway noch nicht freigegeben |
| `remote` | `useCases` (je `entity`, `actor`, `name`, `scenarios`, `available`), `entities` | Anwendungsfälle der Gegenstelle bekannt |
| `write` | `owner`, `entity`, `feature`, `function`, `changes`, `data`, `bound` | Schreibbefehl der Gegenstelle, schon zusammengeführt und bestätigt |
| `heartbeat` | `state` (`lost`/`ok`), `since` | Heartbeat der Gegenstelle länger als 120 s ausgeblieben bzw. wieder da |

Zu `write`:
- `data` ist der neue Gesamtstand der Funktion (bei `partial` je Kennung wie `limitId`/`keyId`
  zusammengeführt), `changes` nur die Einträge, die sich gegenüber dem zuletzt gemeldeten Stand
  wirklich geändert haben. Hintergrund: Die Steuerbox (getestet: eebus-go controlbox) schickt bei
  jeder Änderung die ganze Liste, teils mit veraltetem Stand der anderen Grenze; LPC- und
  LPP-Befehle können in falscher Reihenfolge ankommen.
- Das Gateway bestätigt den Schreibbefehl gegenüber der Gegenstelle sofort. Ein Kind, das einen
  Wert nicht umsetzen kann, meldet den tatsächlichen Stand mit `SetData` zurück.
- `bound = false`: Die Gegenstelle hat geschrieben, ohne sich vorher zu binden (SPINE verlangt
  das eigentlich). Wird zugelassen und protokolliert.

## Für SteuerboxHub (LPC/LPP)

- Grenze: `loadControlLimitListData`, `limitId` 0 = Bezug (LPC, W, positiv), 1 = Einspeisung
  (LPP, W, laut Spezifikation ≤ 0). `isLimitActive`, optional `timePeriod.endTime` als Dauer.
- Ausfallgrenze und Mindestdauer: `deviceConfigurationKeyValueListData`, `keyName`
  `failsafeConsumptionActivePowerLimit` / `failsafeProductionActivePowerLimit` / `failsafeDurationMinimum`.
- `heartbeat`/`lost` → Ausfallgrenze anwenden (LPC Szenario 3), bis wieder `ok` und ein neuer
  Grenzwert kommt.
- Umrechnung auf `SBH_GetState`: `loadPMin` in kW = Grenze/1000; die Verteilung macht das EMS.

## Rolle Energiemanagement (ab Schnittstelle 1.1)

Gegenüber einer Wallbox, Wärmepumpe oder einem anderen EEBUS-Verbraucher ist das Gateway nicht das
gesteuerte Gerät, sondern das Energiemanagement (CEM): Es liest, abonniert und schreibt Funktionen
der Gegenstelle. Das Kind meldet dazu **Client-Features** an und sagt im Feld `remote`, welche
Entitäten der Gegenstelle es ansprechen will. Den Rest (Abonnieren, Binden, erstes Lesen nach jeder
Discovery, auch wenn Entitäten später hinzukommen oder wegfallen) erledigt das Gateway selbst –
Vorbild ist eebus-go `usecases/eg/lpc` `connected()`.

```json
{"Cmd": "Register", "Entity": {
  "type": "CEM",
  "useCases": [
    {"actor": "EnergyGuard", "name": "limitationOfPowerConsumption", "version": "1.0.0", "subRevision": "release", "scenarios": [1,2,3,4]},
    {"actor": "MonitoringAppliance", "name": "monitoringOfPowerConsumption", "version": "1.0.0", "subRevision": "release", "scenarios": [1,2]}
  ],
  "features": [
    {"type": "LoadControl", "role": "client"}, {"type": "DeviceConfiguration", "role": "client"},
    {"type": "ElectricalConnection", "role": "client"}, {"type": "Measurement", "role": "client"}
  ],
  "remote": {"entityTypes": ["EVSE", "HeatPumpAppliance", "Compressor"], "features": [
    {"type": "LoadControl", "subscribe": true, "bind": true, "read": ["loadControlLimitDescriptionListData", "loadControlLimitListData"]},
    {"type": "Measurement", "subscribe": true, "read": ["measurementDescriptionListData", "measurementListData"]}
  ]}
}}
```

Neue Befehle Kind → Gateway:

| `Cmd` | Felder | Antwort |
|---|---|---|
| `Write` | `Entity` (Adresse der Gegenstelle, z. B. `[1]`), `Feature`, `Function`, `Data`, optional `Partial` (Standard `true`) | `{"ok": true, "msgCounter": n}`; Bestätigung kommt als Ereignis `result` |
| `Read` | `Entity`, `Feature`, `Function` | `{"ok": true, "msgCounter": n}`; Daten kommen als Ereignis `data` |
| `GetRemoteData` | `Entity`, `Feature`, `Function` | `{"ok": true, "data": …}` – zuletzt bekannter Stand (null = unbekannt) |

Kann die Gegenstelle nicht teilweise schreiben (`write` ohne `partial` in der Discovery), ergänzt das
Gateway die Liste selbst um den bekannten Stand, wie eebus-go `WriteLimitData`.

Neue Ereignisse Gateway → Kind (jeweils mit `Target` = Kind):

| `Event` | Felder | Bedeutung |
|---|---|---|
| `remoteEntity` | `state` (`added`/`removed`), `entity`, `type`, `features` | Gewünschte Entität der Gegenstelle angebunden bzw. weggefallen (z. B. Fahrzeug abgesteckt) |
| `data` | `entity`, `feature`, `function`, `data`, `changes`, `cmd` (`reply`/`notify`) | Daten der Gegenstelle; `data` ist der zusammengeführte Gesamtstand |
| `result` | `msgCounter`, `function`, `errorNumber`, `description` | Antwort der Gegenstelle auf `Write`/`Read` (0 = angenommen) |

Ab 1.2 (Gateway 0.5.3) abonniert das Gateway die Geräteübersicht (NodeManagement) der Gegenstelle:
Entitäten, die nach dem Verbinden kommen oder gehen (Fahrzeug an der Wallbox), lösen sofort ein neues
`remoteEntity` aus. Ein Kind, das darauf angewiesen ist, prüft in der Antwort auf `Register`
`interface` ≥ 1.2.

Ab 1.3 (Gateway 0.7.0) kann ein Kind in `Register` zusätzlich `extraEntities` angeben: eine Liste weiterer
eigener Entitäten mit `type`, `useCases` und `features` (ohne `remote`). Sie stehen direkt hinter der
Haupt-Entität, bekommen ebenfalls DeviceDiagnosis Server und Client, und ein `Read`/`Write` des Kinds
läuft über das Client-Feature des passenden Typs - erst an der Haupt-Entität, sonst an der Zusatz-Entität.
Vorbild ist evcc: Die Leistungsbegrenzung (LPC als Energy Guard) sitzt dort auf einer eigenen Entität
vom Typ `GridGuard` mit LoadControl-, DeviceConfiguration- und DeviceDiagnosis-Client. Eine echte Vaillant
(VR940f, 09.11.2026) setzt Grenzen nur um und hält die Verbindung nur, wenn LPC und Heartbeat von dort
kommen; das Gateway abonniert den Heartbeat der Gegenstelle deshalb bevorzugt aus einer GridGuard-Entität.
Ältere Gateways ignorieren `extraEntities`; ein Kind prüft `interface` ≥ 1.3.

```json
"extraEntities": [{
  "type": "GridGuard",
  "useCases": [{"actor": "EnergyGuard", "name": "limitationOfPowerConsumption", "version": "1.0.0", "subRevision": "release", "scenarios": [1,2,3,4]}],
  "features": [{"type": "LoadControl", "role": "client"}, {"type": "DeviceConfiguration", "role": "client"}]
}]
```

Ein Gateway hält genau eine Gegenstelle. Für mehrere Geräte (Steuerbox, Wallbox, Wärmepumpe) je ein
Gateway anlegen.

Geprüft (06.11.2026, `.tools/test-eebgw.php --live-verbraucher`) gegen eebus-go `examples/evse`,
erweitert um MPC (Leistung/Energie), als EVSE und als HeatPumpAppliance: Anbindung, Lesen, Grenze
4000 W schreiben, Bestätigung, gemessene Leistung sinkt.
