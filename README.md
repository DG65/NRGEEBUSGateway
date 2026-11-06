# NRG-Stack EEBUSGateway

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.5.1-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue.svg)](https://paypal.me/DietmarGureth)

**Deutsch** · [English](README.en.md)

Verbindet Symcon über **EEBUS** (SHIP/SPINE) mit einem Gerät im Heimnetz, z. B. einer
Steuerbox des Netzbetreibers nach § 14a EnWG. Das Gateway hält Zertifikat und Kopplung und baut die
verschlüsselte Verbindung auf. Was mit den Werten geschieht, entscheiden Fachmodule, die als
Kind-Instanzen am Gateway hängen: **NRG-Stack SteuerboxHub** (Steuerbox nach § 14a, das Gateway ist
dort das gesteuerte Gerät) und **NRG-Stack WPEEBUSHub** (Wärmepumpe, das Gateway ist dort das
Energiemanagement). Je Gerät ein eigenes Gateway.

> Stand 0.5.1: frühe Fassung, geprüft gegen die Simulatoren aus
> [eebus-go](https://github.com/enbility/eebus-go) (Steuerbox `controlbox`, Verbraucher `evse` mit
> Messwerten). Mit echten Geräten noch nicht getestet.

## Einrichtung

1. Instanz „NRG-Stack EEBUS-Gateway“ anlegen (der Client Socket wird mit angelegt).
2. Adresse und Port der Gegenstelle eintragen, „Übernehmen“.
3. **Gegenstelle prüfen**: Das Gateway holt das Zertifikat der Gegenstelle und zeigt ihren SKI.
   Stimmt er mit dem SKI überein, den das Gerät selbst anzeigt, „Diesen SKI übernehmen“.
   Alternativ den SKI vorher eintragen, dann wird nur genau dieses Gerät akzeptiert.
4. Den **SKI dieses Gateways** (steht im Formular) in der Gegenstelle freigeben.
5. Fachmodul (z. B. SteuerboxHub) als Kind-Instanz anlegen.

### Gerät verbindet sich selbst (z. B. Wolf Link)

Manche Geräte bauen die Verbindung nur selbst auf und nehmen keine an. Dann:

1. Unter „Gegenstelle“ **„Das Gerät verbindet sich zum Gateway“** wählen, Port (Standard 4712)
   prüfen, „Übernehmen“. Das Gateway legt einen Server Socket an.
2. Unten unter „Dieses Gateway“ **„Im Netzwerk ankündigen“** (trägt `_ship._tcp` in die DNS-SD-Instanz
   von Symcon ein).
3. In der App des Geräts nach EEBUS-Geräten suchen und dem Gateway vertrauen.
4. Meldet sich das Gerät, erscheint im Formular „Ein Gerät möchte sich verbinden“ mit seinem SKI -
   vergleichen und „Diesen SKI übernehmen“. Beim nächsten Versuch des Geräts steht die Verbindung.

### Alternativ: Kopplung über den Messstellenbetreiber (SHIP Pairing Service)

Manche Messstellenbetreiber koppeln Steuerbox und Energiemanagement über den SHIP Pairing Service:

1. Unten im Formular unter „Dieses Gateway“ → „Kopplung über den Messstellenbetreiber“ auf
   „Kopplung vorbereiten und auf die Gegenstelle warten“ klicken.
2. Die angezeigten Kopplungsdaten (QR-Code oder Text) an den Messstellenbetreiber bzw. Installateur geben.
   Sie enthalten ein Geheimnis - nur dorthin weitergeben.
3. Sobald die Steuerbox sich im Heimnetz meldet, prüft das Gateway ihren Nachweis und übernimmt sie
   selbst, samt Adresse. Voraussetzung ist eine DNS-SD-Instanz in Symcon (Standard).

## Was das Gateway tut

- Eigenes ECDSA-P-256-Zertifikat, SKI nach SHIP; der private Schlüssel liegt in einem Attribut.
- TLS über Symcons Client Socket: eigenes Zertifikat als Client-Zertifikat, das Zertifikat der
  Gegenstelle als einzige Vertrauensbasis (Pinning).
- WebSocket (`/ship/`, Unterprotokoll `ship`), SHIP-Handshake, SPINE-Knoten mit den Entitäten der
  Kind-Instanzen, Abos, Bindungen, Heartbeat (eigener und Überwachung der Gegenstelle).
- Wiederverbindung bei Stille oder hängendem Aufbau.
- Variablen „Verbunden“ und „Zustand“; `EEBGW_GetState()` liefert den Zustand als JSON.
- Zweisprachig (Deutsch/Englisch), folgt der Sprache der Symcon-Konsole.

## Grenzen dieser Fassung

- Server-Rolle (Gerät verbindet sich, z. B. Wolf Link) nur gegen den Simulator geprüft; TLS macht das
  Gateway dafür selbst, weil Symcons Server Socket das Client-Zertifikat nicht an das Modul weitergibt.
- Ohne SHIP Pairing Service werden Adresse und Port von Hand eingetragen (keine allgemeine Gerätesuche).
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
| `EEBGW_PairingStarten(int $id)` | Text: Kopplungsdaten erzeugen, auf die Gegenstelle warten |
| `EEBGW_PairingBeenden(int $id)` | Text: nicht mehr warten |
| `EEBGW_ResetFormLayout(int $id)` | Text: „Formular: Aufbau“ im offenen Formular auf Standard |
| `EEBGW_AnkuendigungEintragen(int $id)` | Text: Gateway als `_ship._tcp` in die DNS-SD-Instanz eintragen (Server-Rolle) |
| `EEBGW_AnkuendigungEntfernen(int $id)` | Text: Eintrag wieder entfernen |
| `EEBGW_GetState(int $id)` | JSON: Verbindungszustand (`contractVersion` 1.0) |

## Danksagung

Abläufe und Nachrichtenformate folgen den MIT-lizenzierten Referenzimplementierungen
[ship-go](https://github.com/enbility/ship-go), [spine-go](https://github.com/enbility/spine-go)
und [eebus-go](https://github.com/enbility/eebus-go) von enbility.

---

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
[Kompatibilitäts-Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
