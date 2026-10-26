# EEBUSGateway – Kind-Schnittstelle 1.0

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
