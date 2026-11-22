# Punkteprogramm nach Kalenderzeitraum auswerten

`GET /program/{id}/analytics` ist eine private, rein lesende Auswertung für den tatsächlichen Eigentümer eines **Punkteprogramms**. Verlinkt im Programm und über dessen Namen in der Unternehmens-Analytics-Tabelle. Andere Programmtypen, fremde oder fehlende Programme liefern 404. Plattform-Adminstatus ersetzt keine Eigentümerschaft. Mitglieder-/Staff-Sessions sind kein Betreiberzugang. Lesen bleibt auch bei inaktivem Programm oder fehlendem Bezahlplan möglich.

Die bisherige Unternehmens-Analytics und die Monatsübersicht werden nicht neu berechnet oder umdefiniert. Die neue Seite ist eine zusätzliche Programmansicht.

## Kalender und Vergleich

Der Parameter `range` hat genau acht Werte, Standard `this_month`:

| Wert | Gewählter Zeitraum | Vorperiode |
|---|---|---|
| `today` | Heutiger UTC-Tag | Gestern |
| `yesterday` | Gestern | Vorgestern |
| `this_week` | Aktuelle Woche, Montag bis Sonntag | Vorige Woche |
| `last_week` | Vorige Woche | Woche davor |
| `this_month` | Aktueller Kalendermonat | Voriger Monat |
| `last_month` | Voriger Monat | Monat davor |
| `this_year` | Aktuelles Kalenderjahr | Voriges Jahr |
| `last_year` | Voriges Jahr | Jahr davor |

Alle Grenzen sind ausdrücklich **UTC**, unabhängig von PHP-Server-, Browser- oder Unternehmenszeitzone. Das unterscheidet sich von kontozeitzonenbezogenen Referenzansichten. Beginn inklusive, folgende Kalendergrenze exklusiv. Exakte Zeitgrenzen beider Perioden sind über „Genaue UTC-Grenzen“ aufklappbar; die gewählten Kalendertage stehen direkt über den Kennzahlen.

Der Abruf hält einen UTC-Stand sekundengenau fest. Buchungen genau in dieser Sekunde zählen mit, spätere Zeitstempel nicht. Laufende Zeiträume sind als laufend gekennzeichnet. Der Vergleich nutzt die **ganze Vorperiode**, nicht dieselbe Zahl bereits verstrichener Stunden/Tage. Das steht sichtbar neben dem Stand. Keine Prozent- oder Trendbehauptung aus ungleichen Laufzeiten.

Unbekannte Parameter, unbekannte/mehrwertige/leere Zeitraumauswahl und ungültige optionale Sprache liefern 422 ohne Teilkennzahlen. Ein unterstütztes `lang=de` oder `lang=en` steuert nur den bestehenden Sprachrahmen; neue Texte sind zunächst deutsch. Keine frei wählbaren Start-/Enddaten oder Exportparameter.

## Drei Kennzahlen aus dem bestehenden Journal

Nur nicht stornierte `transactions` mit `reversed_at IS NULL`, zum gewählten eigenen Punkteprogramm und mit passender Mitglieds-Unternehmenszuordnung:

| Kennzahl | Definition |
|---|---|
| Punkte gutgeschrieben | Summe von `amount` bei `points_add` |
| Punkte eingelöst | Summe der **positiv gespeicherten Kosten** bei `points_redeem`, kein Negieren oder Absolutwert |
| Prämieneinlösungen | Anzahl dieser `points_redeem`-Ereignisse |

Das sind keine Änderungen am Saldo. Auch Bonus-/Empfehlungs-/Gutschein-Gutschriften zählen, soweit sie als `points_add` im Journal stehen. Prämiencodes schreiben schon `points_redeem` und zählen nicht zusätzlich. Heutige Prämienkosten werden nicht rückwirkend anstelle des damaligen Journals eingesetzt. Eine historische Null-Kosten-Einlösung zählt als eine Einlösung mit null eingelösten Punkten.

Stornierte Zeilen, `reversal`, Punktetransfers, Verfall, Stempel-, Gutschein- und Prepaid-Ereignisse zählen nicht zu diesen drei Kennzahlen. Eine idempotente Wiederholung zählt nur dann erneut, wenn tatsächlich ein weiterer Journaleintrag vorliegt. Keine neue Deduplizierung oder Garantie für ältere nicht-idempotente Buchungspfade.

## Lesetransaktion, Datenqualität und Grenzen

Programmdaten, Prüfung und beide Perioden werden in einer gemeinsamen Datenbank-Lesetransaktion gelesen. Der Datenhelfer prüft die Eigentümerschaft nochmals selbst. Weder private Mitgliedsnamen/-E-Mails noch Notizen, Empfängerlisten oder andere Programminhalte werden für die Ausgabe ausgewählt. Die Antwort trägt `Cache-Control: private, no-store` und `Referrer-Policy: no-referrer`.

Für die nicht stornierten Punkte-Gutschriften/-Einlösungen dieses Programms gelten kanonische UTC-Zeittexte `YYYY-MM-DD HH:MM:SS`, gültige Kalendertage ab Jahr 0001 und nichtnegative ganze Mengen. Nichtkanonische Zeittexte, negative/gebrochene/nichtnumerische Mengen oder widersprüchliche Mitgliedszuordnungen verhindern **den ganzen neuen Bericht**, auch wenn der fehlerhafte Eintrag außerhalb der gewählten Perioden liegt. Das vermeidet geschätzte oder still unvollständige Kennzahlen. Bereits stornierte und andere Ereignisarten sind von dieser Prüfung ausgeschlossen.

Summen außerhalb des PHP-/SQLite-Ganzzahlbereichs, ungültige/über 64 KiB große Programm-/Unternehmensnamen und Lesefehler liefern ebenfalls eine generische 503-Seite ohne Kennzahlen, SQL-Fehlertext oder private Datensatzwerte. Keine automatische Reparatur. Bei einem Fehler Daten und Betrieb getrennt prüfen, vorher sichern. Die neue strenge Prüfung ändert die älteren Analytics-Seiten oder gespeicherte Daten nicht.

Die Zahlen verwenden heutige Programm-/Unternehmensnamen und noch vorhandene Journaleinträge. Spätere Stornos, Löschungen und Datenkorrekturen ändern vergangene Perioden. Kein historischer Saldo, keine unveränderliche Archivkopie, keine Manipulationssicherheits- oder Lastzusage.

Nur GET: andere Methoden liefern 405, soweit nicht vorher die bestehenden Auth-/CSRF-/Plan-Schreibsperren greifen. Kein Versand, Retry, Erzeugen von Audit-Einträgen oder sonstiger Schreibpfad durch den Bericht. Normale neue Logins bleiben wie zuvor protokolliert.

## Verlauf und kleine Bildschirme

- Tagansichten: Stunden. Wochen-/Monatsansichten: Kalendertage. Jahresansichten: Kalendermonate.
- Fehlende vergangene Abschnitte sind null, zukünftige Abschnitte werden nicht erzeugt. Der letzte Abschnitt kann noch laufen.
- Orange Balken zeigen Gutschriften, blaue Balken eingelöste Punkte. Gleiche Punkteskala, keine Prämienanzahl auf dieser Skala. Nullwerte erzeugen keinen Mindestbalken. Skalenmaximum bezieht sich auf den größten Abschnitt, nicht die Periodensumme.
- Eine vollständige beschriftete Tabelle enthält zusätzlich die Prämienanzahl. Sie ist auch die genaue Alternative zum Diagramm. Mobil werden die Zeilen zu beschrifteten Karten. Caption und alle Werte bleiben ohne seitliches Scrollen lesbar.
- Programmname und Zahlen dürfen umbrechen. Keine abgeschnittenen Langnamen, versteckten Spalten oder externen Chart-Bibliotheken.

Noch keine Aufrufmessung, Einlösequote, Prozenttrends, personenbezogene Aktivitätsliste, neue CSV-/TSV-/JSON-Datei, Geld-/Umsatzberechnung oder vollständige Referenzparität.

## Prüfung

`python3 tests/run-points-analytics.py`: 231 Domänen-, 52 HTTP-/Integrations- und 45 Chromium-Prüfungen in einer Wegwerf-Installation. Unabhängiger Python-Kalendervergleich für alle acht Perioden, Schaltjahre, Jahreswechsel, UTC-Konvertierung und Montag-Wochen. Enthält positive Einlösekosten, Null-Kosten-Fall, Stornos/Transfers/Verfall, exakte Perioden-/Standgrenzen, fehlende Abschnitte, beide Überlaufpfade, ungültige Daten, Eigentümer-/Typgrenzen, Planunabhängigkeit und unveränderte Anwendungsdaten.

Chromium prüft echte Programm-/Vergleichslinks, Periodenwechsel, Tabellen-/Diagrammwerte, aufklappbare Zeitgrenzen und lesbare Captions bei 1280/390/320px. Nach der ersten Pixelprüfung wurden eine schmale Tabellen-Caption und kollidierende Diagrammbeschriftungen korrigiert und erneut geprüft. Bilder unter `/tmp/loyalty-points-analytics/`. Nur Testdaten, keine echten Kunden oder Provideraufrufe. Keine Geräte-/Produktions-/Lastabnahme.

Der anschließende frische Gesamtrücklauf besteht alle 63 Nicht-WordPress-Suiten auf 219 unveränderten Anwendungs-/Public-/Testdateien. 89 PHP-Dateien sind syntaktisch geprüft. WordPress bleibt separat und benötigt seine eigene Umgebung. Die bestehende Analytics-Suite läuft unverändert mit.

## Referenz

[Reward Loyalty: Loyalty program analytics](https://rewardloyalty.co/docs/5.x/loyalty-cards/analytics), am 22.11.2026 gelesen. Bestätigt einzelne Punkteprogramm-Dashboards mit Perioden/Vorperioden, Gutschriften, eingelösten Punkten und Prämienanzahl. Das lokale UTC-Fenster, Datenqualitätsverhalten, das kleine Dreier-Kennzahlenset und fehlende Aufruf-/Quoten-/Trendfunktionen sind ausdrücklich kein vollständiger Nachbau.
