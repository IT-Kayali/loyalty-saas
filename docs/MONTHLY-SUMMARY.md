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

Programmnamen und Zuordnungen sind heutiger Datenstand. Vergangene Monate werden aus den noch vorhandenen Daten neu berechnet, nicht archiviert. Spätere Stornos, Löschungen und Korrekturen können Zahlen rückwirkend ändern. Keine historischen Salden, Geld-/Währungs-/Umsatzsummen, Wirtschaftlichkeitsanalyse oder Buchhaltung. Privater CSV-Download des gewählten Monats ist verfügbar, siehe unten. Kein PDF-Export, E-Mail-Bericht, automatischer Versand oder monatlicher Cronjob. Die ursprünglichen Analytics-Allzeit-/30-Tage-Zahlen wurden nicht umdefiniert. Neue Texte bislang Deutsch.

Die Ansicht ist `private, no-store` und ohne Referrer. Keine Mitgliedsnamen, Kontaktangaben, Codes oder Journalnotizen im Bericht. Desktop zeigt die Programmtabelle, Mobil dieselben Daten in beschrifteten Zeilen ohne ausgeblendete Spalten.

## Tests

`python3 tests/run-monthly-summary.py`: 28 HTTP-/Domänenprüfungen und 12 Chromium-Prüfungen. Schaltjahr und exakte Monatsgrenzen, Storno/Reversal, alle vier Einlösungsfamilien, leere Monate, eindeutige Mitglieder über Programme, Mandanten-/Login-/Plantrennung, ungültige Monate, laufender Monat, Standardmonat, unveränderte Salden/Journal/Mail/Audit und Programmobergrenze. Browser prüft Monatswechsel und lange Programmnamen auf Desktop/Mobil. Screenshots unter `/tmp/loyalty-monthly-summary/` visuell geprüft. Nur lokale Wegwerfdaten, keine externen Konten verändert. Kein Lasttest großer Journale.

Etappenregression: alle 39 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Analytics, Browsergesamtprüfung, Buchungs- und Gutscheinpfaden. WordPress-Echtumgebung nicht erneut geprüft.


## Privates Monats-CSV

Die Schaltfläche **Monat als CSV herunterladen** übernimmt den gültigen ausgewählten Monat in `/business/ID/monthly-summary.csv?month=YYYY-MM`. Nur GET, nur der Eigentümer, auch ohne aktiven Schreibplan. Keine Mitgliedsliste, Namen, E-Mails, Kundencodes, Einzelbuchungen oder Journalnotizen im Export. Es werden Unternehmens-Programmnamen und aggregierte Zahlen ausgegeben.

Das CSV berechnet denselben UTC-Monat und dieselben Kennzahlen über `monthly_summary_data()` in einem neuen Lesesnapshot. Es ist **kein eingefrorener Download der vorherigen HTML-Antwort**. Datenänderungen zwischen Seitenaufruf und Download können die Zahlen ändern. `Stand_UTC` ist die Erzeugungszeit dieses CSV, kein Zeitpunkt einer unveränderlichen Archivkopie.

Format: UTF-8 ohne BOM, Komma als Trennzeichen, Standard-Anführungszeichen. Beim Tabellenimport diese Kodierung/Trennung wählen. Fester Dateiname `monthly-summary-ID-YYYY-MM.csv`, nur validierte ID/Monat. Spalten:

`Zeilentyp`, `Monat_UTC`, `Von_UTC`, `Bis_exklusiv_UTC`, `Stand_UTC`, `Laufender_Monat`, `Programm_ID`, `Programmname`, `Programmtyp`, `Neue_Mitgliedseintraege`, `Aktive_Mitglieder`, `Journal_Aktionen`, `Einloesungen`, `Hinweis`.

Eine `gesamt`-Zeile enthält die Gesamtzahlen. Je noch vorhandenem Programm folgt eine `programm`-Zeile, auch bei null Aktivität. `Neue_Mitgliedseintraege` bleibt auf Programmebene leer, weil diese Kennzahl dort nicht definiert ist. Programmtypen sind interne Typnamen. Aktive Mitglieder je Programm dürfen nicht addiert werden. Laufender Monat ist mit `ja` markiert, abgeschlossener mit `nein`. Zeitgrenzen/Erzeugungsstand werden auf jeder Zeile mitgeführt.

Keine Geld-/Währungswerte, versteckten Rohdaten oder weiteren Summen. Formeln beginnende Programmnamen (auch nach Leerraum) erhalten ein führendes Apostroph; CSV-Anführungszeichen erhalten Kommas, Anführungszeichen und Zeilenumbrüche innerhalb einer Zelle. Dies ist ein Schutz für den ersten Tabellenimport, keine Garantie für nachträgliches Kopieren oder Umformatieren in beliebiger Software.

Der CSV-Pfad ist `private, no-store` und ohne Referrer. Fehlender/ungültiger Monat, ungültiger UTF-8-Programmname oder mehr als 200 Programme ergeben einen vollständigen Abbruch vor den CSV-Downloadheadern, keinen Teilbericht. Ein Download ist kein Versand, keine Mail, kein Audit-Schreibereignis und keine Änderung der Buchungsdaten.

`python3 tests/run-monthly-csv.py`: 33 HTTP-/Domänen- und 12 Chromiumprüfungen. Exakte UTC-Schaltmonatsgrenzen/Kennzahlen, Mehrfachprogramme derselben Person, leere und laufende Monate, Privat-/Rollen-/Plan-/Methodengrenzen, sichere Header/Dateinamen, fehlende/ungültige Monate, Formelschutz und CSV-Quoting, 200er-Grenze ohne Teilanhang, unveränderte Daten. Browser lädt echte Dateien herunter und prüft Inhalt/Dateinamen, Monatswechsel und Desktop/Mobil. Screenshots unter `/tmp/loyalty-monthly-csv/` visuell geprüft, kein externer Spreadsheet-Client-Test. Die oben genannte ursprüngliche Etappenregression beschreibt den früheren Stand ohne CSV.

CSV-Etappenregression: alle 47 weiteren Nicht-WordPress-`run-*.py`-Suiten grün. PHP-Lint und `git diff --check` sauber. WordPress-Echtumgebung und externe Tabellenprogramme nicht erneut geprüft.
