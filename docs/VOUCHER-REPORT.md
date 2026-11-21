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

Nur Code-Gutscheine dieses Programms, nicht die früheren einfachen Gutscheinkarten. Kein vollständiges Unternehmens-/Kampagnen-Dashboard, keine Produktbewertung, kein Conversion-/Versand-/ROI-Tracking und keine Währungsumrechnung. Keine neue API-/MCP-Funktion. Tageslisten zeigen nur Tage mit Einlösungen.

Es werden keine Mitgliedsnamen, E-Mails oder Gutscheincodes im Bericht ausgegeben. Das macht die Ansicht nicht zu einem revisionssicheren Buchhaltungsarchiv: Mitglieder-/Programm-/Datenlöschungen können Einlösungsdatensätze entfernen und historische Zahlen verändern. Ein Bericht ist ein geladener Datenstand, keine automatische Live-Aktualisierung.

## Tests

`python3 tests/run-voucher-report.py` erzeugt eine Wegwerf-Installation: Summen, Währungs-/Typtrennung, Punkte/Produkte, ungültiger Snapshot, UTC-Grenzen, Stornoausschluss, eindeutige Mitglieder, Login/Mandant, sichere Datumsfehler, leere Zeiträume, 366-Tage-Grenze und harter 10.000-Zeilen-Abbruch. Chromium prüft Link, Datumsauswahl, Werte, Desktop/Mobil und Tabellen ohne seitliches Scrollen. Die Screenshots werden visuell geprüft. Kein Produktions-Lasttest und keine externe Kassen-/Buchhaltungsabnahme.

## CSV-Export

„Auswertung als CSV“ lädt denselben aktuell ausgewählten UTC-Zeitraum herunter. Der Export berechnet den Datenstand beim Download neu. Zwischen Seitenaufruf und Download können neue Einlösungen oder Stornos die Zahlen ändern. Er verwendet dieselbe Funktion, Datumsprüfung und harte Datensatzgrenze wie die Ansicht, keine zweite Summenlogik. Fehler liefern HTTP 422 als HTML statt eines unvollständigen Downloads. Eigentümerbindung und privater No-Store-Cache gelten auch für CSV; kein aktives Abo nötig.

Datei: `voucher-report-VON-BIS.csv`, UTF-8, Komma als Trennzeichen, Anführungszeichen nach CSV-Konvention. In Excel/LibreOffice gegebenenfalls über Datenimport mit UTF-8 und Komma öffnen. Keine Prüfung mit installierten Office-Programmen, nur standardkonformes Parsen und echte Browserdownloads.

Zeilenarten in einer Tabelle mit festem Spaltensatz:

- `gesamt`: Einlösungen, unterschiedliche Mitgliedsdatensätze, ausgeschlossene Stornos, unbekannte Snapshots. Keine währungsübergreifende Geldsumme.
- `wertgruppe`: Rabattart (`fixed`, `percent`, `product`, `points`), Währung, Anzahl, erfasster Kaufbetrag in Cent, Geldrabatt in Cent nur bei Festbetrag/Prozent, Bonuspunkte nur bei Punktegutscheinen. Nicht zutreffende Werte bleiben leer, nicht null als scheinbare Bewertung.
- `tag`: UTC-Tag und Anzahl nicht rückgängig markierter Einlösungen. Nur Tage mit Einlösungen.

Alle Zeilen tragen Von/Bis; Hinweise auf fehlenden Zahlungs-/Umsatznachweis und unbewertete Produkte bleiben in der Datei. Keine Namen, E-Mails, Codes, Batch-/Programmnamen, Belegnummern oder freie Kundentexte. Es werden keine frei eingegebenen Textfelder als Tabellenformeln exportiert. Export schreibt weder Audit noch Einlösungen oder Codes. Der bestehende getrennte Code-CSV-Export ist unverändert und enthält weiterhin Codes.

Die erweiterte Suite umfasst 40 Domänen-/HTTP-Prüfungen und 16 Browserprüfungen, darunter CSV-Schema, Summengleichheit, Nullbericht, Centwerte, Rechte/Plan, Grenzen und Desktop-/Mobildownload mit übernommenen Filtern. Das sind 18 neue Prüfungen plus 4 neue Browserprüfungen gegenüber der ursprünglichen Auswertung.
