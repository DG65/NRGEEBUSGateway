# Changelog

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
