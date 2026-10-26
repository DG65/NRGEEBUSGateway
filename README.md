# NRG-Stack EEBUSGateway

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.1.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue.svg)](https://paypal.me/DietmarGureth)

Verbindet IP-Symcon über **EEBUS** (SHIP/SPINE) mit einem Gerät im Heimnetz, z. B. einer
Steuerbox des Netzbetreibers nach § 14a EnWG. Das Gateway hält Zertifikat und Kopplung und baut die
verschlüsselte Verbindung auf. Was mit den Werten geschieht, entscheiden Fachmodule, die als
Kind-Instanzen am Gateway hängen (zuerst **NRG-Stack SteuerboxHub**).

> Stand 0.1.0: erste Fassung, geprüft gegen den Steuerbox-Simulator `controlbox` aus
> [eebus-go](https://github.com/enbility/eebus-go). Mit echter Steuerbox noch nicht getestet.

## Einrichtung

1. Instanz „NRG-Stack EEBUS-Gateway“ anlegen (der Client Socket wird mit angelegt).
2. Adresse und Port der Gegenstelle eintragen, „Übernehmen“.
3. **Gegenstelle prüfen**: Das Gateway holt das Zertifikat der Gegenstelle und zeigt ihren SKI.
   Stimmt er mit dem SKI überein, den das Gerät selbst anzeigt, „Diesen SKI übernehmen“.
   Alternativ den SKI vorher eintragen, dann wird nur genau dieses Gerät akzeptiert.
4. Den **SKI dieses Gateways** (steht im Formular) in der Gegenstelle freigeben.
5. Fachmodul (z. B. SteuerboxHub) als Kind-Instanz anlegen.

## Was das Gateway tut

- Eigenes ECDSA-P-256-Zertifikat, SKI nach SHIP; der private Schlüssel liegt in einem Attribut.
- TLS über Symcons Client Socket: eigenes Zertifikat als Client-Zertifikat, das Zertifikat der
  Gegenstelle als einzige Vertrauensbasis (Pinning).
- WebSocket (`/ship/`, Unterprotokoll `ship`), SHIP-Handshake, SPINE-Knoten mit den Entitäten der
  Kind-Instanzen, Abos, Bindungen, Heartbeat (eigener und Überwachung der Gegenstelle).
- Wiederverbindung bei Stille oder hängendem Aufbau.
- Variablen „Verbunden“ und „Zustand“; `EEBGW_GetState()` liefert den Zustand als JSON.

## Grenzen dieser Fassung

- Nur Client-Rolle: Das Gateway verbindet sich zur Gegenstelle. Geräte, die ausschließlich selbst
  verbinden wollen, gehen noch nicht (Symcons Server Socket kann kein Client-Zertifikat verlangen).
- Keine Gerätesuche per mDNS (`_ship._tcp`); Adresse und Port von Hand.
- Keine PIN-Kopplung.

## Für Entwickler von Fachmodulen

Schnittstelle zwischen Gateway und Kind: [.docs/kind-schnittstelle.md](.docs/kind-schnittstelle.md).
Prüfstand: `php .tools/test-eebgw.php` (ohne Netz) bzw. mit `--live <controlbox> <cert> <key>`
gegen eebus-go controlbox.

## Funktionen

| Funktion | Rückgabe |
|---|---|
| `EEBGW_GegenstellePruefen(int $id)` | Text: Zertifikat der Gegenstelle abholen, SKI prüfen |
| `EEBGW_GegenstelleUebernehmen(int $id)` | Text: zuletzt geprüften SKI festlegen |
| `EEBGW_NeuVerbinden(int $id)` | Text: Verbindung neu aufbauen |
| `EEBGW_ZertifikatErneuern(int $id)` | Text: neues eigenes Zertifikat (neuer SKI!) |
| `EEBGW_GetState(int $id)` | JSON: Verbindungszustand (`contractVersion` 1.0) |

## Danksagung

Abläufe und Nachrichtenformate folgen den MIT-lizenzierten Referenzimplementierungen
[ship-go](https://github.com/enbility/ship-go), [spine-go](https://github.com/enbility/spine-go)
und [eebus-go](https://github.com/enbility/eebus-go) von enbility.

---

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
[Kompatibilitäts-Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
