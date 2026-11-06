# Changelog

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
