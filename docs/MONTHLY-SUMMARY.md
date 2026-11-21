# Monatsübersicht (UTC)

Analytics verlinkt eine separate private Leseansicht unter `/business/ID/monthly-summary`. Nur der Eigentümer dieses Unternehmens kann sie lesen, auch ohne bezahlten Schreibplan. Keine Team-/Mitglieder-/öffentliche Ansicht. Der Bericht löst weder Buchungen noch Nachrichten aus.

## Zeitraum und Zahlen

Voreinstellung ist der letzte abgeschlossene UTC-Kalendermonat. Der Monat ist frei wählbar von 2000-01 bis zum aktuellen UTC-Monat, der ausdrücklich als unvollständig markiert ist. Alle Vergleiche gelten ab Monatsbeginn einschließlich bis zum nächsten Monatsbeginn ausschließlich. Kein rollierendes 30-Tage-Fenster und keine Umrechnung in die Unternehmenszeitzone.

- **Neue Mitglieder:** noch gespeicherte Mitgliedseinträge dieses Unternehmens mit Anlagezeit im Monat. Keine unternehmensübergreifende Personenzahl.
- **Aktive Mitglieder:** eindeutige Mitglieds-IDs mit mindestens einer nicht zurückgenommenen Journal-Aktion im Monat. Ein Mitglied mit Aktionen in mehreren Programmen zählt insgesamt einmal.
- **Journal-Aktionen:** gespeicherte Transaktionen im Monat ohne `reversed_at` und ohne Typ `reversal`. Ein Ereignis zählt einmal, unabhängig von Betrag oder Punktzahl. Das ist eine Ereigniszahl, keine Anzahl Besuche oder Käufe.
- **Einlösungen:** Teilmenge mit `reward_redeem`, `points_redeem`, `voucher_use` oder `voucher_code_use`. Keine zusätzliche Zählung der Prämiencode-Vorschau und keine Prepaid-Verkaufszahl.

Die Programmtabelle zeigt für jedes noch vorhandene Programm dieselben Monatszahlen und die aktiven Mitglieder dieses Programms, auch Programme ohne Aktivität. Die aktiven Mitglieder der Programme dürfen wegen Überschneidungen nicht zur Gesamtzahl addiert werden. Maximal 200 Programme, darüber Abbruch des gesamten Berichts statt unvollständiger Summen. Zusammengehörige Abfragen laufen in einem SQLite-Lesesnapshot.

## Grenzen

Programmnamen und Zuordnungen sind heutiger Datenstand. Vergangene Monate werden aus den noch vorhandenen Daten neu berechnet, nicht archiviert. Spätere Stornos, Löschungen und Korrekturen können Zahlen rückwirkend ändern. Keine historischen Salden, Geld-/Währungs-/Umsatzsummen, Wirtschaftlichkeitsanalyse oder Buchhaltung. Kein CSV/PDF-Export, E-Mail-Bericht, automatischer Versand oder monatlicher Cronjob. Die ursprünglichen Analytics-Allzeit-/30-Tage-Zahlen wurden nicht umdefiniert. Neue Texte bislang Deutsch.

Die Ansicht ist `private, no-store` und ohne Referrer. Keine Mitgliedsnamen, Kontaktangaben, Codes oder Journalnotizen im Bericht. Desktop zeigt die Programmtabelle, Mobil dieselben Daten in beschrifteten Zeilen ohne ausgeblendete Spalten.

## Tests

`python3 tests/run-monthly-summary.py`: 28 HTTP-/Domänenprüfungen und 12 Chromium-Prüfungen. Schaltjahr und exakte Monatsgrenzen, Storno/Reversal, alle vier Einlösungsfamilien, leere Monate, eindeutige Mitglieder über Programme, Mandanten-/Login-/Plantrennung, ungültige Monate, laufender Monat, Standardmonat, unveränderte Salden/Journal/Mail/Audit und Programmobergrenze. Browser prüft Monatswechsel und lange Programmnamen auf Desktop/Mobil. Screenshots unter `/tmp/loyalty-monthly-summary/` visuell geprüft. Nur lokale Wegwerfdaten, keine externen Konten verändert. Kein Lasttest großer Journale.

Etappenregression: alle 39 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Analytics, Browsergesamtprüfung, Buchungs- und Gutscheinpfaden. WordPress-Echtumgebung nicht erneut geprüft.
