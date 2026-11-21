# Einlösecodes für Prämien

Mitglieder erzeugen in der Wallet einen persönlichen, zeitlich begrenzten Code (mit QR) für eine Prämie. An der Kasse prüft und löst das Personal ihn ein. Erst dann werden die Punkte abgebucht.

## Einschalten
Programmseite, Abschnitt "Einlösecodes": Gültigkeit in Minuten (0 = aus, höchstens 1440). Die Änderung steht im Audit-Log (`claims.set`).

## Ablauf
1. Mitglied: Wallet, Button "Code für <Prämie>" (nur für Prämien, die das Guthaben deckt). Es gibt Code `XXXX-XXXX` (8 Zeichen ohne 0/O/1/I/L) und QR.
2. Höchstens 3 gültige Codes je Karte, 1 gültiger Code je Prämie. Mitglied kann zurückziehen.
3. Kasse: `/business/N/claim-counter` (Owner) bzw. `/staff/business/N/claim-counter` (Personal mit Recht "redeem"). Code eintippen oder QR mit der Handykamera öffnen (`/claim/CODE`, leitet Owner/Personal zur Kasse, alle anderen sehen eine neutrale Seite).
4. Vorschau zeigt Mitglied, Prämie, Kosten, Status. "Einlösen" bucht in einer Transaktion ab (`points_redeem` mit Code und Einlösendem), setzt den Code auf verbraucht, prüft Erfolge, sendet Webhook `card.redeem` und aktualisiert den Wallet-Pass.

## Zeitfenster je Prämie
Optional je Prämie (Programmseite, Abschnitt "Zeitfenster für Prämien"): Start und Ende in UTC (`rewards.valid_from`, `valid_until`, leer = unbegrenzt, Audit-Eintrag `reward.window`).
- Codes lassen sich nur im Fenster erzeugen. Vor dem Start zeigt die Wallet "ab ...", nach dem Ende verschwindet die Prämie dort.
- Ein Code läuft spätestens zum Fensterende ab (sonst nach der eingestellten Minutenzahl).
- Eingelöst wird nur im Fenster, auch bei Codes, die vorher erzeugt wurden: Kasse blockiert, nichts wird abgebucht, die Kasse zeigt einen Hinweis.
- Das Fenster gilt auch für direktes Einlösen (Kartenaktion von Owner und Team) und die Merchant-API (`redeem_reward`), nicht nur für Codes. Stempel-Belohnungen, Gutscheine und Pässe haben kein Fenster.
- Eingabe und Anzeige sind UTC (es gibt noch keine Zeitzone je Unternehmen).

## Sicherheit
- Einlösen nur einmal (BEGIN IMMEDIATE, Status nur von active zu used). Vier parallele Einlösungen getestet: genau eine gewinnt.
- CSRF-Schutz, Mandantentrennung (Code eines anderen Unternehmens ist nicht ladbar), fremde Mitglieder können nicht erzeugen oder zurückziehen.

## Grenzen
- Keine Punktereservierung: Punkte werden erst beim Einlösen abgezogen. Fällt das Guthaben dazwischen, schlägt das Einlösen fehl.
- Der Kassen-Scanner im Personalbereich liest die Codes nicht automatisch. Es zählt die Handykamera oder die Eingabe.
- Abgelaufene Codes bleiben mit Status active in der Tabelle und werden nur als abgelaufen angezeigt.
- Keine Plan-Guard-Trigger für die neue Tabelle `reward_claims`.
- Nicht mit echten Geräten oder QR-Scannern getestet, nur mit Browser-Tests (Desktop und Mobil).

- Kein Serientermin (z. B. jeden Freitag) und keine Zeitzone je Unternehmen, nur ein einzelnes Fenster je Prämie.

Tests: `python tests/run-claims.py` (56 Prüfungen, davon 22 im Browser).
