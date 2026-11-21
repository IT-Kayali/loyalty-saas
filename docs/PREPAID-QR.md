# QR für bereits gekaufte Prepaid-Pässe

Die angemeldete Wallet zeigt bei bereits verkauften Besuchspaketen und unbegrenzten Zeitpässen den bestehenden individuellen Passcode als QR. Das erzeugt keinen Verkauf und keine Zahlung. Es ist **kein Kauf-/Zahlungs-QR**. Einfache frühere Mehrfachkarten sind davon getrennt.

## Wallet und Team-Scanner

- Eigene aktive Pässe mit Restbesuchen oder gültiger unbegrenzter Laufzeit zeigen `P-` plus den vorhandenen 20-stelligen Hex-Code. Abgelaufene und aufgebrauchte Pässe bleiben mit Status sichtbar, aber ohne QR.
- Nur die angemeldete Wallet bekommt diesen vollständigen Code/QR. Andere Mitglieder und die öffentliche Kundenkarte erhalten ihn nicht. Die öffentliche Karte behält ihre bisherigen Passinformationen und die gekürzte Kennung.
- Die bisherige Grenze von 100 verkauften Pässen pro Mitgliedsansicht bleibt bestehen, sortiert nach Ablauf und ID. Kein neuer Offline-Snapshot für verkaufte Pässe.
- Kamera und manuelle Eingabe im Team-Scanner erkennen das genaue Präfixformat. Die Passprüfung verlangt eine aktive Staff-Sitzung des zugehörigen Unternehmens und `redeem`. Fremde Codes liefern 404, fehlende Rechte 403.
- Der Scan öffnet `/staff/prepaid/scan/CODE`: Unternehmen, Mitglied, Passkennung, Restbesuche und Ablauf. Er bucht nichts. Vorhandene Besuchsbestätigung verwendet CSRF, frische serverseitige Rechte-/Plan-/Ablauf-/Restmengenprüfung, Versionskonfliktschutz und Vorgangskennung. Kein neuer Buchungspfad, keine Änderung der Passkonditionen.
- Auf der Prüfseite muss das Team den richtigen Kunden und Pass prüfen und den Besuch ausdrücklich bestätigen. Unbegrenzte Pässe dokumentieren jeweils einen Besuch. Bereits geladene Seiten bleiben Momentaufnahmen; ein späterer Verbrauch oder Ablauf kann die Bestätigung blockieren.
- Passverlauf und Storno bleiben über die Kundenkarte erreichbar. Kein automatisches Achievement, Wallet-Update oder Webhook durch diesen neuen Scan.

## Grenzen und Datenschutz

Der QR ist eine andere Darstellung desselben Codes, kein Besitz- oder Identitätsnachweis. Screenshot und Code nicht weitergeben. Die Kundenprüfung bleibt Aufgabe des Teams. Der Code steht in der internen Vorschauadresse und kann damit in Browserhistorie und Serverlogs erscheinen. Kein externer QR-Dienst, kein öffentlicher Claimlink, kein Druck-/Batch-Export, keine native Apple-/Google-Wallet-Erweiterung, keine echte Mobilkamera-/Scannerabnahme. Keine Geldguthabenpässe, Zahlung oder Refund-Funktion ergänzt. Die Regeln verkaufter Pässe ändern sich nicht, wenn später das Programm angepasst wird.

## Tests

`python3 tests/run-prepaid-qr.py`: 26 Domänen-/HTTP-Prüfungen für Wallet-Privatsphäre, öffentliche Karte ohne Vollcode, exaktes Format, Mandant/Rechte/Sitzung, Vorschau ohne Schreibwirkung, CSRF, Plan, Mengen, Ablauf, aufgebrauchte und unbegrenzte Pässe, nachträglichen Rechteentzug sowie veraltete Scan-Version. Dazu 11 Chromium-Prüfungen auf Desktop und Mobilgröße: tatsächlich gezeichnete QR-Pixel mit jsQR dekodiert, Kamera-Steuerpfad mit simuliertem Frame, konkrete Passvorschau, keine Überläufe und genau eine ausdrückliche Bestätigung. Lokale Wegwerfdaten, kein echter Verkauf oder Zahlung. Screenshots unter `/tmp/loyalty-prepaid-qr/` visuell geprüft.
