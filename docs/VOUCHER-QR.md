# Persönliche Gutschein-QRs

Die angemeldete Wallet zeigt die vorhandenen persönlichen Code-Gutscheine zusätzlich als QR. Es werden keine neuen Gutscheine erzeugt. Aktivstatus, Restnutzungen, aktives Programm und Datum werden beim Laden der Wallet berücksichtigt. Stornierte, abgelaufene, zukünftige und verbrauchte Codes werden nicht angezeigt. Die bestehende Grenze von 20 zuletzt erzeugten persönlichen Codes bleibt bestehen.

## An der Kasse

Der QR enthält `V-` und den vorhandenen 24-stelligen Hex-Code. Er ist kein öffentlicher Link. Nur der angemeldete Team-Scanner oder seine manuelle Eingabe erkennt dieses Format und öffnet die Gutschein-Kasse des eigenen Unternehmens. Ein fremder Unternehmenscode liefert dort 404; Staff braucht das Recht `redeem`.

Der Scan füllt ausschließlich den Code vor. Es gibt keine GET-Buchung, keine automatische Mitgliedsauswahl, keinen Kaufbetrag aus dem QR und keine automatische Einlösung. Das Team muss Mitglied (aus der bisherigen Liste mit bis zu 500 Mitgliedern) und externen Kaufbetrag wählen, die Vorschau prüfen und danach ausdrücklich bestätigen. Vorhandene serverseitige Regeln, persönliche Mitgliedsbindung, Limits, Status, Datum, CSRF und Transaktion gelten weiter. Auch ein zwischen Scan und Bestätigung storniertes Exemplar lässt sich nicht einlösen. Der Rabatt wird dokumentiert, keine externe Zahlung ausgelöst.

## Grenzen und Datenschutz

- Der QR ist eine zweite Darstellung desselben Codes, keine zusätzliche Absicherung. Wer einen Screenshot erhält, kennt den Code. Deshalb nicht weitergeben. Die persönliche Mitgliedsbindung wird weiterhin serverseitig geprüft.
- Der Code erscheint beim Scan im Queryparameter der internen Kassenadresse, damit in Browserhistorie und gegebenenfalls Serverzugriffslogs. Für QR-Daten wird kein externer Dienst genutzt.
- Keine neue öffentliche Gutschein-/Claimseite, keine QR-Links in Automationsmails und kein QR-/Druckexport für Batches. Bestehende Batch-Codes bleiben per CSV und manueller Kasse nutzbar.
- Die Wallet-Ansicht ist ein geladener Stand. Ein nachträglich gesperrter Code kann dort bis zur Aktualisierung sichtbar bleiben, aber nicht mehr eingelöst werden. Keine Offline-Einlösung.
- Nur persönliche Wallet-Gutscheine werden angezeigt, keine fremden Mitglieder oder unzugeordneten Batch-Codes. Die öffentliche Kundenkarte enthält keinen persönlichen Gutschein-QR.
- Kamera auf realen Mobilgeräten, Ausdrucke und externe Scanner wurden nicht abgenommen.

## Lokale Tests

`python3 tests/run-voucher-qr.py`: Wegwerfkonten, eigene/fremde Wallet, öffentliche Karte ohne Code, exaktes Präfixformat, externe/ungültige Eingaben, Tenant-/Staff-Rechte, kein GET-Schreibvorgang, explizite Mitgliedsauswahl, Vorschau/Bestätigung, persönliche Bindung, CSRF und Storno nach Scan.

Chromium prüft Desktop und Mobil, dekodiert die tatsächlich gezeichneten QR-Pixel mit jsQR und testet den Kamera-Steuerpfad mit simulierten Frames. Das ersetzt keinen realen Kameratest. Screenshots werden unter `/tmp/loyalty-voucher-qr/` erzeugt.
