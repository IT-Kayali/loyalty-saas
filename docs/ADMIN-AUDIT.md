# Vorhandenes Aktivitätsprotokoll lesen

Das Admin-Dashboard verlinkt „Filtern und ältere Einträge lesen“ unter dem bisherigen 100-Zeilen-Auszug. `/admin/audit` ist eine getrennte, private/no-store GET-Seite nur für aktuelle Plattform-Admins. Kein Betreiber-, Staff-, Mitglieder- oder öffentlicher Zugriff. Keine Anbindung an externe Dienste.

## Filter und Reihenfolge

- Von/Bis als optionale UTC-Kalendertage, jeweils inklusive. Ungültige Daten oder umgekehrter Zeitraum werden mit 422 abgelehnt.
- Aktion exakt, maximal 120 Zeichen (Buchstaben, Zahlen, Punkt, Unterstrich, Doppelpunkt, Bindestrich). Kein Präfix-/Volltextfilter. Bestehende ungewöhnliche Aktionsnamen bleiben in der ungefilterten Liste lesbar, sind aber nicht alle über dieses Filterformat wählbar.
- „Konto enthält“ sucht im heutigen E-Mail-Feld des verknüpften Kontos, maximal 254 Zeichen. Prozent-/Unterstrichzeichen werden wörtlich gesucht, nicht als SQL-Platzhalter. SQLite-LIKE behandelt ASCII-Groß-/Kleinschreibung gleich; keine Zusage vollständiger Unicode-Suchnormalisierung.
- Alle Filter werden gemeinsam angewendet. „Filtern“ beginnt wieder auf der neuesten passenden Seite. Zurücksetzen entfernt alle Filter.

Jeweils höchstens 100 Zeilen, absteigend nach gespeicherter ID, nicht nach Ereigniszeit. „Ältere 100“ behält die Filter bei und liest nur IDs unter der letzten gezeigten ID. Neu eingefügte Zeilen verschieben ältere Seiten deshalb nicht. Das ist keine eingefrorene Datenbanksicht: Löschungen, direkte Eingriffe und Änderungen an Konto-E-Mails können spätere Treffer verändern. Browser-Zurück oder Filter erneut anwenden führt zu neueren Seiten. Kein Gesamtzähler und keine Seitennummern.

## Sichtbarkeit und Grenzen

Die Seite zeigt nur ID, gespeicherten UTC-Zeitpunkt, heutige Konto-E-Mail und Aktionsnamen. Bei fehlendem Konto steht „Konto nicht mehr vorhanden“. Das ist kein historischer Identitätsnachweis. Gespeicherte JSON-Details werden weder abgefragt noch angezeigt. Keine Suche in Details, kein Download/CSV und keine Tickettexte, Empfängerlisten oder geheimen Inhalte neu offengelegt. Die Seite ist eine zusätzliche Ansicht bestehender Daten, keine neue Protokollierung.

Lesen verändert keine Protokolle, Einstellungen oder Outbox und sendet nichts. POST wird auch mit gültigem CSRF-Token mit 405 abgelehnt. Admin-Lesen bleibt unabhängig vom Betreiber-Bezahlplan. Die Plattform-Adminliste bleibt die bestehende Konfiguration, keine neuen Rollen oder delegierten Leserechte.

Es besteht weiterhin keine lückenlose Security-/Änderungshistorie für alle Module. Keine Manipulationssicherheit, Aufbewahrungsplanung, Fernarchivierung, Anomalieerkennung, historische Identitäts-Snapshots oder Produktions-/Lastabnahme. Freie Kontosuche steht in der Browseradresse. Konto-E-Mails sind private Daten, die Ansicht ist kein Freigabelink.

## Tests

`python3 tests/run-admin-audit.py`: 39 HTTP-/Datenbankprüfungen für Rollen, genaue Filter, UTC-Tagesgrenzen, 205 Treffer über drei Seiten, neue Inserts zwischen Seiten, wörtliche Sonderzeichen, sichere Fehler, fehlende Konten, no-store, ausgeblendete Details, Nebenwirkungsfreiheit, Methodensperre und Planunabhängigkeit. 20 Chromium-Prüfungen für Desktop/Mobil, Filter/Folgeseiten, lange Aktionsnamen, sichtbare vollständige Tabellen ohne seitliches Scrollen und leere Ergebnisse. Screenshots unter `/tmp/loyalty-admin-audit/` visuell geprüft. Nur Wegwerfdaten, keine externen Provideraufrufe.
