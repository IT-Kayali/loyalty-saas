# Code-Gutschein-Auswertung

Auf der Gutschein-Programmseite führt "Code-Gutschein-Auswertung" zu `/program/ID/voucher-report`. Nur der Eigentümer des Programms kann den Bericht lesen. Es werden keine Einlösungen, Regeln oder Kundenkonten geändert. Die Ansicht ist auch ohne aktives Abo lesbar.

## Zeitraum und Zahlen

- Standard: heute und die 29 vorherigen Kalendertage. Alle Grenzen sind UTC, unabhängig von der Zeitzone des Unternehmens. Von und Bis sind inklusive; technisch wird der Folgetag exklusiv abgefragt.
- Höchstens 366 Kalendertage pro Bericht. Über 10.000 Datensätze im Zeitraum wird kein Bericht mit unvollständigen Summen gezeigt. Stattdessen erscheint HTTP 422 mit der Bitte um einen kürzeren Zeitraum.
- Anzahl nicht rückgängig markierter Code-Einlösungen, unterschiedliche Mitgliedsdatensätze und Einlösungen je UTC-Tag. Mehrere Einlösungen desselben Codes zählen einzeln. Identische Wallet-E-Mails in verschiedenen Mitgliedsdatensätzen werden nicht zusammengeführt.
- Einträge mit `reversed_at` sind separat gezählt und aus allen übrigen Zahlen ausgeschlossen. Das ist kein neuer Storno-Workflow.
- Wertgruppen getrennt nach Rabattart und Währung aus dem zum Einlösen gespeicherten Snapshot. Unterschiedliche Währungen werden nie addiert oder umgerechnet.
- Geldrabatte nur für Festbetrag/Prozent. Gratisprodukte werden gezählt, nicht bewertet. Bonuspunkte stammen aus dem damaligen Snapshot und werden nicht als Geld dargestellt.
- Erfasste Kaufbeträge stammen aus den Kasseneingaben. Sie sind kein bestätigter Umsatz, keine externen Zahlungen, keine Steuerbasis und kein Gewinn. Mehrere Einlösungen können denselben externen Kauf betreffen.
- Ungültige oder unbekannte Snapshots zählen in Anzahl/Mitgliedern/Tagesverlauf, aber nicht in Wertgruppen. Die Ansicht nennt die Anzahl dieser ausgelassenen Wert-Snapshots.

## Bewusste Grenzen

Nur Code-Gutscheine dieses Programms, nicht die früheren einfachen Gutscheinkarten. Kein vollständiges Unternehmens-/Kampagnen-Dashboard, kein CSV-Export, keine Produktbewertung, kein Conversion-/Versand-/ROI-Tracking und keine Währungsumrechnung. Keine neue API-/MCP-Funktion. Tageslisten zeigen nur Tage mit Einlösungen.

Es werden keine Mitgliedsnamen, E-Mails oder Gutscheincodes im Bericht ausgegeben. Das macht die Ansicht nicht zu einem revisionssicheren Buchhaltungsarchiv: Mitglieder-/Programm-/Datenlöschungen können Einlösungsdatensätze entfernen und historische Zahlen verändern. Ein Bericht ist ein geladener Datenstand, keine automatische Live-Aktualisierung.

## Tests

`python3 tests/run-voucher-report.py` erzeugt eine Wegwerf-Installation: Summen, Währungs-/Typtrennung, Punkte/Produkte, ungültiger Snapshot, UTC-Grenzen, Stornoausschluss, eindeutige Mitglieder, Login/Mandant, sichere Datumsfehler, leere Zeiträume, 366-Tage-Grenze und harter 10.000-Zeilen-Abbruch. Chromium prüft Link, Datumsauswahl, Werte, Desktop/Mobil und Tabellen ohne seitliches Scrollen. Die Screenshots werden visuell geprüft. Kein Produktions-Lasttest und keine externe Kassen-/Buchhaltungsabnahme.
