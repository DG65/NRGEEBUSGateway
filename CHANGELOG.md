# Changelog

## 0.1.0 (Build 1, 27.10.2026)

- Erste Fassung: SHIP-Client über Symcons Client Socket (Client-Zertifikat, Pinning), WebSocket,
  SHIP-Handshake, generischer SPINE-Knoten aus den Anmeldungen der Kind-Instanzen.
- Zertifikat/SKI automatisch, Gegenstelle prüfen (Trust on first use oder Abgleich mit
  eingetragenem SKI).
- Heartbeat senden und überwachen (Ausfall nach 120 s an die Kinder), Wiederverbindung.
- Kind-Schnittstelle 1.0 (`.docs/kind-schnittstelle.md`).
- Geprüft gegen eebus-go controlbox, auch mit TLS 1.2 und ausschließlich
  `TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA256`.
