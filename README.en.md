# NRG-Stack EEBUSGateway

[![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Module version](https://img.shields.io/badge/Module%20version-0.4.0-blue.svg)](library.json)
[![Symcon version](https://img.shields.io/badge/Symcon%20version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue.svg)](https://paypal.me/DietmarGureth)

[Deutsch](README.md) · **English**

Connects Symcon via **EEBUS** (SHIP/SPINE) to a device in the home network, e.g. a grid
operator's control box under § 14a EnWG (the German law on controllable consumer devices). The
gateway holds the certificate and the pairing and establishes the encrypted connection. What
happens with the values is decided by application modules attached as child instances:
**NRG-Stack SteuerboxHub** for a § 14a control box (the gateway is the controlled device there) and
**NRG-Stack WPEEBUSHub** for heat pumps (the gateway acts as energy management there). One gateway
per device.

> Version 0.4.0: early version, tested against the simulators from
> [eebus-go](https://github.com/enbility/eebus-go) (control box `controlbox`, consumer `evse` with
> measurements). Not yet tested with real devices.

## Setup

1. Create an instance "NRG-Stack EEBUS-Gateway" (the client socket is created along with it).
2. Enter address and port of the remote device, click "Apply".
3. **Check remote device**: the gateway fetches the remote certificate and shows its SKI. If it
   matches the SKI the device itself shows, click "Trust this SKI". Alternatively enter the SKI
   beforehand; then only this exact device is accepted.
4. Trust the **SKI of this gateway** (shown in the form) in the remote device.
5. Create the application module (e.g. SteuerboxHub) as a child instance.

### Alternative: pairing via the metering point operator (SHIP Pairing Service)

Some metering point operators pair control box and energy management via the SHIP Pairing Service:

1. At the bottom of the form under "This gateway" → "Pairing via the metering point operator", click
   "Prepare pairing and wait for the remote device".
2. Give the pairing data shown (QR code or text) to the metering point operator or installer. They
   contain a secret - only pass them there.
3. As soon as the control box announces itself in the home network, the gateway checks its proof and
   trusts it by itself, including its address. A DNS-SD instance in Symcon is required (default).

## What the gateway does

- Own ECDSA P-256 certificate, SKI according to SHIP; the private key is stored in an attribute.
- TLS via Symcon's client socket: own certificate as client certificate, the remote certificate
  as the only trust anchor (pinning).
- WebSocket (`/ship/`, subprotocol `ship`), SHIP handshake, SPINE node with the entities of the
  child instances, subscriptions, bindings, heartbeat (own one and monitoring of the remote one).
- Reconnects when the remote device stays silent or the setup hangs.
- Variables "Connected" and "State"; `EEBGW_GetState()` returns the state as JSON.
- Bilingual (German/English), follows the language of the Symcon console.

## Limits of this version

- Client role only: the gateway connects to the remote device. Devices that can only open the
  connection themselves are not supported yet (Symcon's server socket cannot require a client
  certificate).
- Without the SHIP Pairing Service, address and port are entered manually (no general device discovery).
- No PIN pairing.

## For developers of application modules

Interface between gateway and child: [.docs/kind-schnittstelle.md](.docs/kind-schnittstelle.md)
(German). Test bench: `php .tools/test-eebgw.php` (offline) or with
`--live <controlbox> <cert> <key>` against eebus-go controlbox.

## Functions

| Function | Returns |
|---|---|
| `EEBGW_GegenstellePruefen(int $id)` | Text: fetch the remote certificate, check the SKI |
| `EEBGW_GegenstelleUebernehmen(int $id)` | Text: trust the SKI checked last |
| `EEBGW_NeuVerbinden(int $id)` | Text: re-establish the connection |
| `EEBGW_ZertifikatErneuern(int $id)` | Text: new own certificate (new SKI!) |
| `EEBGW_PairingStarten(int $id)` | Text: create pairing data, wait for the remote device |
| `EEBGW_PairingBeenden(int $id)` | Text: stop waiting |
| `EEBGW_ResetFormLayout(int $id)` | Text: reset "Form: layout" to default in the open form |
| `EEBGW_GetState(int $id)` | JSON: connection state (`contractVersion` 1.0) |

The function names are German identifiers and stay unchanged.

## Acknowledgements

Procedures and message formats follow the MIT-licensed reference implementations
[ship-go](https://github.com/enbility/ship-go), [spine-go](https://github.com/enbility/spine-go)
and [eebus-go](https://github.com/enbility/eebus-go) by enbility.

---

Part of the **NRG-Stack** — which module versions belong together is listed in the
[compatibility manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
