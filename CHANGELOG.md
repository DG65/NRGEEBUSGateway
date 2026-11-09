# Changelog

## 0.7.1 (Build 17, 09.11.2026)

- Behoben: Ausgehende SPINE-Nachrichten haben ihre Felder jetzt in der Reihenfolge der SPINE-Spezifikation
  (z. B. `timePeriod` vor `value` in `loadControlLimitData`, `number` vor `scale`). Slummis Vaillant (Forum 144877,
  Dump 5) beantwortete eine aktive Leistungsgrenze mit `value` vor `timePeriod` gar nicht, eine aufgehobene
  (ohne `timePeriod`) dagegen sofort.
- `nodeManagementDestinationListData` wird angeboten und beantwortet (wie evcc/spine-go; die Vaillant liest es dort).
- Debug „SHIP/SPINE“ zeigt WebSocket-Ping und -Pong in beide Richtungen, für die weiter offenen Abbrüche um xx:50.

## 0.7.0 (Build 16, 09.11.2026)

- Kind-Schnittstelle 1.3: Ein Kind kann mit `extraEntities` weitere eigene Entitäten anmelden (z. B. GridGuard).
  Sie stehen direkt hinter seiner Haupt-Entität und bekommen eigenen DeviceDiagnosis Server und Client.
  Schreiben und Lesen gehen automatisch vom passenden Client-Feature aus (`clientVon`), das Heartbeat-Abo
  bevorzugt den Client einer GridGuard-Entität. Anlass: Slummis Vaillant (Forum 144877) beendet die Verbindung
  jede Minute genau bei ihrem eigenen Heartbeat und setzt Leistungsgrenzen nicht um. evcc meldet LPC von einer
  eigenen GridGuard-Entität an, dort läuft die Verbindung stabil.
- Behoben: Nach einem Schließen durch die Gegenseite steht nicht mehr zusätzlich „im Schritt closed abgebrochen:
  client socket no longer active“ im Meldungsprotokoll.

## 0.6.3 (Build 15, 09.11.2026)

- Behoben: WebSocket-Ping alle 50 s (SHIP 4.2, wie ship-go `pingPeriod`). Slummis Vaillant (Forum 144877,
  Dump 09.11.) beendete jede Verbindung knapp 60 s nach dem Aufbau mit 1002 „Frame Error!“.
- Automatisch ergänzte DeviceDiagnosis-Server-Features bieten auch `deviceDiagnosisStateData` an
  (`operatingState` `normalOperation`, wie evcc); die Vaillant fragt das nach dem Aufbau ab.
- Debug-Kanal „SPINE-Inhalt“: vollständige SPINE-Nachrichten in beide Richtungen (bis 8000 Zeichen), die
  Übersicht „SHIP/SPINE“ bleibt kurz.

## 0.6.2 (Build 14, 09.11.2026)

- Behoben: Die SHIP-Datennachricht trägt die SPINE-Nutzlast jetzt als Objekt (`{"payload":{"datagram":[…]}}`)
  statt in EEBUS-Listenform (`{"payload":[{"datagram":[…]}]}`), genau wie ship-go und die Geräte selbst. eebus-go
  liest beides, eine echte Vaillant (Thread 144877 #6/#8) beendet bei der Listenform die Verbindung mit
  1002 „Frame Error!“ oder 4500 „User close“ direkt nach der ersten Antwort des Gateways. Gefunden durch
  Vergleich mit einem öffentlichen evcc-Mitschnitt gegen eine Vaillant (evcc-Issue 33733).
- Richtigstellung zu 0.6.1: Die Geräteübersicht ist nur rund 3 KB groß, die Zerteilung in 4-KB-Rahmen greift
  bei ihr gar nicht und war nicht die Ursache. Sie bleibt trotzdem, weil sie dem Verhalten von eebus-go entspricht.
- Behoben: `parentRequirements` nennt jetzt auch den Server Socket. Bisher zeigte Symcon in der Server-Rolle die
  Warnung „übergeordnete Instanz inkompatibel“, obwohl die Verbindung funktionierte.

## 0.6.1 (Build 13, 09.11.2026)

- Behoben: Ausgehende WebSocket-Nachrichten über 4082 Byte werden in Folgerahmen zerteilt, wie gorilla/websocket
  in eebus-go (4096-Byte-Schreibpuffer). Erste echte Vaillant (Thread 144877 #6) schloss die Verbindung mit
  1002 „Frame Error!“ direkt nach der eigenen Antwort auf nodeManagementDetailedDiscoveryData.
- Neu: „Socket passend zur Richtung tauschen“ (mit Rückfrage), wenn die Elterninstanz nicht zur gewählten
  Richtung passt. Der bisherige Socket wird getrennt und nur gelöscht, wenn ihn das Gateway selbst angelegt
  hat und nichts anderes daran hängt.

## 0.6.0 (Build 12, 09.11.2026)

- Neu: „Netzwerk durchsuchen“ listet die per DNS-SD (`_ship._tcp`) angekündigten EEBUS-Geräte mit Marke,
  Modell, Typ, SKI, Adresse und Port. Ein Klick trägt Adresse, Port und SKI ins Formular ein (bestätigt mit
  „Übernehmen“). Anlass: erster Bericht mit echter Wärmepumpe (Vaillant), Port musste geraten werden.
- Meldungsprotokoll: Abbruch des Verbindungsaufbaus mit Schritt und Grund (nur bei neuem Grund), Trennung
  einer bestehenden Verbindung mit Grund.

## 0.5.3 (Build 11, 06.11.2026)

- Rolle Energiemanagement: Das Gateway abonniert jetzt die Geräteübersicht der Gegenstelle. Entitäten, die
  später hinzukommen oder wegfallen (Fahrzeug an der Wallbox an- oder abgesteckt), meldet es den Kindern
  sofort (`remoteEntity`). Kind-Schnittstelle 1.2 (rückwärtskompatibel); nötig für NRG-Stack
  ChargerEEBUSHub.

## 0.5.2 (Build 10, 06.11.2026)

- Server-Rolle: „Diesen SKI übernehmen“ meldet jetzt richtig, dass sich das Gerät beim nächsten Versuch
  selbst verbindet (vorher „Das Gateway verbindet sich jetzt“, live in Symcon gesehen).

## 0.5.1 (Build 9, 06.11.2026)

- Behoben (live in Symcon 9.0 gefunden): In der Server-Rolle scheiterte jeder TLS-Handschlag mit „ECDHE key
  could not be created“, weil `openssl_pkey_new` in Symcons PHP ohne OpenSSL-Konfiguration nicht geht. Der
  flüchtige Schlüssel entsteht jetzt ohne Konfiguration und ohne Temp-Datei (Zufallszahl als SEC1-Schlüssel,
  den Punkt berechnet OpenSSL). Prüfstand Teil 5 läuft jetzt auch mit `OPENSSL_CONF=/nonexistent`.

## 0.5.0 (Build 8, 06.11.2026)

- 🔄 Neu: **Server-Rolle** für Geräte, die die Verbindung nur selbst aufbauen (z. B. Wolf Link).
  Property `Richtung` (0 = Gateway verbindet sich, Standard; 1 = Gerät verbindet sich) und
  `ServerPort` (Standard 4712). Das Gateway legt dann einen Server Socket ohne SSL an und macht TLS 1.2
  selbst (`libs/EEBGWTlsServer.php`: ECDHE-ECDSA P-256, `TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA256` als
  SHIP-Pflicht, zusätzlich AES-128-GCM, Extended Master Secret, Client-Zertifikat verlangt, keine
  Wiederaufnahme, keine Neuverhandlung). Vertrauen über den SKI des Client-Zertifikats: festgelegtes
  Gerät, erwarteter SKI oder Bestätigung „Ein Gerät möchte sich verbinden“ im Formular.
- SHIP-Sitzung als Server: HTTP-Upgrade beantworten, unmaskierte Rahmen, init/hello/Protokoll-Auswahl
  in Server-Reihenfolge.
- Ankündigung im Netzwerk (`_ship._tcp` mit txtvers, id, path, ski, register, brand, model, type) als
  Eintrag in der DNS-SD-Instanz von Symcon: `EEBGW_AnkuendigungEintragen` / `EEBGW_AnkuendigungEntfernen`,
  nur auf Knopfdruck mit Rückfrage (ändert eine Instanz außerhalb des Moduls).
- Behoben: In mehrere WebSocket-Rahmen geteilte Nachrichten (gorilla/websocket teilt große Nachrichten,
  gesehen bei der Discovery einer simulierten Wärmepumpe) wurden als unlesbar verworfen.
- Neuer Status 205: Elterninstanz passt nicht zur Richtung.
- Rolle Energiemanagement: Einzelobjekte (z. B. `smartEnergyManagementPsData` für OHPCF) werden wie
  von eebus-go immer mit `partial` geschrieben; lehnt die Gegenstelle das ab (spine-go als Server kann es
  für diesen Typ nicht), schickt das Gateway einmal das vollständige, zusammengeführte Objekt.
  Teil-Meldungen von Einzelobjekten werden eingearbeitet statt ersetzt.
- Geprüft: TLS-Server gegen `openssl s_client` (CBC, GCM, ohne Client-Zertifikat, falsche Chiffre) und
  live gegen eebus-go als Wärmepumpe, die das Gateway per mDNS findet und sich selbst verbindet
  (Prüfstand Teil 5 `--live-server`): Bestätigung des SKI, zweite Verbindung, SHIP-Sitzung, LPC-Grenze.

## 0.4.0 (Build 7, 06.11.2026)

- 🔁 Neu: Rolle **Energiemanagement** (Kind-Schnittstelle 1.1, rückwärtskompatibel). Ein Kind meldet
  Client-Features und im Feld `remote` die gewünschten Entitäten der Gegenstelle an; das Gateway
  abonniert, bindet und liest sie nach jeder Discovery selbst (auch wenn Entitäten später kommen oder
  gehen) und meldet `remoteEntity`, `data` (zusammengeführter Gesamtstand) und `result`. Neue Befehle
  `Read`, `Write` (mit `partial`, sonst ganze Liste wie eebus-go) und `GetRemoteData`.
- Discovery-Änderungen der Gegenstelle (`notify`) lösen eine neue Abfrage aus; `reply`/`notify` mit
  `ackRequest` werden bestätigt.
- Geprüft gegen einen simulierten Verbraucher (eebus-go `evse` mit MPC, als Wallbox und als
  Wärmepumpe): Anbinden, Lesen, Grenze schreiben, Bestätigung, gemessene Leistung sinkt.
  Erstes Fachmodul: NRG-Stack WPEEBUSHub (Wärmepumpen).

## 0.3.0 (Build 6, 06.11.2026)

- 🔗 Neu: Kopplung über den **SHIP Pairing Service**. Das Gateway erzeugt ein Kopplungsgeheimnis und zeigt
  seine Kopplungsdaten (SKI, SHIP-ID, SHA-256-Fingerabdruck, Geheimnis) als Text und QR-Code für den
  Messstellenbetreiber. Meldet sich die Steuerbox danach per mDNS (`_shippairing._tcp`), prüft das Gateway
  den HMAC-Nachweis, sucht ihren SHIP-Dienst, vergleicht den Zertifikats-Fingerabdruck und übernimmt sie
  samt Adresse - ohne Eingabe von Adresse, Port oder SKI. Wiederholungsschutz über die zuletzt
  angenommenen Nachweise. Geprüft mit den Testwerten aus Anhang A der Spezifikation und live gegen
  eebus-go controlbox im Ankündiger-Modus.
- Suche über Symcons DNS-SD-Instanz (alle 30 s, solange auf die Gegenstelle gewartet wird).
- Eigener QR-Code-Erzeuger (Byte-Modus, Stufe M, Versionen 1-20), gegengeprüft mit zbar.
- Der Bereich „Dieses Gateway“ steht jetzt im Aktionsbereich (Felder ohne Eigenschaft), „Formular: Aufbau“
  steuert ihn mit.

## 0.2.0 (Build 5, 05.11.2026)

- 🌍 Neu: zweisprachig Deutsch/Englisch (Formular, Meldungen, Variablennamen, Einträge im Meldungsprotokoll); folgt der Sprache
  der Symcon-Konsole. Englische Anleitung `README.en.md`. Kennungen (Idents, Funktions-, Property- und
  Attributnamen, Kind-Schnittstelle) unverändert.
- Feste Formulartexte übersetzt die Konsole selbst (Store-Checkliste 25); das Modul übersetzt nur
  Texte mit eingesetzten Werten, Rückmeldungen, Variablennamen und Meldungen.

## 0.1.3 (Build 4, 29.10.2026)

- 🪟 Neu: „Formular: Aufbau“ (NRG-Stack-Konvention): je Bereich aufgeklappt, zugeklappt oder
  ausgeblendet und Position wählbar; „Auf Standard zurücksetzen“ (`EEBGW_ResetFormLayout`).

## 0.1.2 (Build 3, 27.10.2026)

- Wiederverbindung mit wachsender Wartezeit (5 s, 10 s, 20 s … höchstens 5 Minuten) statt alle 5 s.
  Live gesehen: Nach einem Verbindungsabriss hielt die Gegenstelle die alte Verbindung noch für
  aktiv und lehnte neue Versuche ab.

## 0.1.1 (Build 2, 27.10.2026)

- Fix (live gefunden): Symcons `RequireParent` legte den Client Socket beim Anlegen der Instanz nicht
  an. Fehlt er nach 15 s noch, legt das Gateway ihn jetzt selbst an und verbindet ihn.
- Erster Live-Test in Symcon 9.0 gegen eebus-go controlbox (TLS 1.2, nur CBC-SHA256): Prüfung der
  Gegenstelle, Pinning und SHIP-Verbindung laufen.

## 0.1.0 (Build 1, 27.10.2026)

- Erste Fassung: SHIP-Client über Symcons Client Socket (Client-Zertifikat, Pinning), WebSocket,
  SHIP-Handshake, generischer SPINE-Knoten aus den Anmeldungen der Kind-Instanzen.
- Zertifikat/SKI automatisch, Gegenstelle prüfen (Trust on first use oder Abgleich mit
  eingetragenem SKI).
- Heartbeat senden und überwachen (Ausfall nach 120 s an die Kinder), Wiederverbindung.
- Kind-Schnittstelle 1.0 (`.docs/kind-schnittstelle.md`).
- Geprüft gegen eebus-go controlbox, auch mit TLS 1.2 und ausschließlich
  `TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA256`.
