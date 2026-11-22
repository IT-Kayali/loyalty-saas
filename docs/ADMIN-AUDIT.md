# Vorhandenes Aktivitätsprotokoll lesen und exportieren

Das Admin-Dashboard verlinkt „Filtern und ältere Einträge lesen“ unter dem bisherigen 100-Zeilen-Auszug. `/admin/audit` ist eine getrennte, private/no-store GET-Seite nur für aktuelle Plattform-Admins. Der neue Download unter `/admin/audit/export` nutzt dieselbe Session und aktuelle Admin-Allowlist. Kein Betreiber-, Staff-, Mitglieder- oder öffentlicher Zugriff, kein Ersatz durch API-Bearer oder neue Managerrechte. Keine Anbindung an externe Dienste.

## Filter und Reihenfolge

- Von/Bis als optionale UTC-Kalendertage, jeweils inklusive. Ungültige Eingaben oder umgekehrter Zeitraum werden mit 422 abgelehnt.
- Aktion exakt, maximal 120 Zeichen (Buchstaben, Zahlen, Punkt, Unterstrich, Doppelpunkt, Bindestrich). Kein Präfix-/Volltextfilter. Bestehende ungewöhnliche Aktionsnamen bleiben ungefiltert lesbar und exportierbar, sind aber nicht alle über dieses Filterformat wählbar.
- „Konto enthält“ sucht im heutigen E-Mail-Feld des verknüpften Kontos, maximal 254 Zeichen. Prozent-, Unterstrich- und Ausrufezeichen werden wörtlich gesucht, nicht als SQL-Platzhalter. SQLite-LIKE behandelt ASCII-Groß-/Kleinschreibung gleich; keine Zusage vollständiger Unicode-Suchnormalisierung.
- Die bisherige Normalisierung äußerer Leerzeichen bleibt erhalten. Nicht-textuelle Werte, ungültiges UTF-8 und ASCII-Steuerzeichen sind keine gültigen Filter.
- Alle Filter werden gemeinsam angewendet. „Filtern“ beginnt wieder auf der neuesten passenden Seite. Zurücksetzen entfernt alle Filter.

Jeweils höchstens 100 Zeilen, absteigend nach gespeicherter ID, nicht nach Ereigniszeit. „Ältere 100“ behält die Filter bei und liest nur IDs unter der letzten gezeigten ID. Neu eingefügte Zeilen verschieben ältere Seiten deshalb nicht. Das ist keine eingefrorene Datenbanksicht: Löschungen, direkte Eingriffe und Änderungen an Konto-E-Mails können spätere Treffer verändern. Browser-Zurück oder Filter erneut anwenden führt zu neueren Seiten. Kein Gesamtzähler und keine Seitennummern.

Liste und Download teilen sich Filterprüfung, SQL-Bedingungen, Reihenfolge und Metadatenprojektion. Die bisherigen Zeitbedingungen vergleichen gespeicherte Zeichenketten mit UTC-Tagesgrenzen. Normal erzeugte Einträge haben das SQLite-UTC-Format `YYYY-MM-DD HH:MM:SS`. Manuell beschädigte oder nichtkanonische Altwerte werden dadurch nicht validiert oder repariert; sie können bei diesen Vergleichen unerwartet in einen Zeitraum fallen. Ansicht und CSV behalten dieselbe Auswahl und gespeicherte Zeitwerte. Keine neue Zeitzonenumrechnung oder behauptete historische Zeitkorrektur.

## Vollständiger gefilterter CSV-Download

Der Exportbereich steht über der Liste, mit sichtbarem Hinweis auf private Konto-E-Mails. Die zusätzliche Hilfe „Inhalt und Grenzen“ ist zunächst geschlossen und lässt sich öffnen. Bei 1280, 390 und 320 Pixel Breite geprüft; auf kleinen Bildschirmen bleiben die bestehenden beschrifteten Zeilenkarten erhalten.

`GET /admin/audit/export` exportiert **alle Treffer der aktuellen Filter**, nicht nur die sichtbare 100er-Seite. Auch der Link auf einer älteren Seite entfernt `before` und behält Von/Bis/Aktion/Konto. Ein direkt mitgeschickter Cursor wird mit 422 abgelehnt, ebenso unbekannte Downloadparameter oder ein anderes Format. Ein optionales `lang=de` oder `lang=en` verändert weder Felder noch Aktionscodes. Die bisherige HTML-Liste ignoriert weiterhin unbekannte Zusatzparameter; deren Werte gelangen nicht in den Downloadlink.

Feste Spalten in dieser Reihenfolge:

| Spalte | Inhalt |
|---|---|
| `audit_id` | Gespeicherte Protokoll-ID |
| `created_at_utc` | Gespeicherter Zeittext, nicht repariert oder umgerechnet |
| `account_email` | Heutige E-Mail des verknüpften Kontos; leer, wenn das Konto fehlt |
| `action` | Gespeicherter Aktionscode, nicht übersetzt |

Das ist derselbe kleine Metadatenumfang wie in der Liste. Keine historischen Identitäts-Snapshots und kein erfundener „System“-Akteur bei einem fehlenden Konto. Namen, Passwörter, interne Benutzerfelder und insbesondere `audit_logs.details` werden nicht ausgewählt. Auch große oder ungültig kodierte private Detail-Payloads sind damit kein Teil dieser Ausgabe.

### Grenzen und Dateiformat

- Höchstens 5000 Datenzeilen und 4 MiB **fertig kodiertes CSV**, einschließlich Kopfzeile, Anführungszeichen und Zeilenenden. Die exakte Grenze ist erlaubt.
- Höchstens 64 KiB je gespeicherter Textzelle. Größere Zellen werden bereits in der SQL-Projektion abgefangen, nicht erst nach dem Laden in PHP. Diese Zellprüfung und gültiges UTF-8 gelten auch für die getrennte HTML-Liste.
- Bei Überschreitung oder ungültigem Metadaten-UTF-8: 422 ohne Anhang und ohne Teildatei. Engere Filter können bei zu vielen oder insgesamt zu großen Treffern helfen. Beschädigte einzelne Werte verlangen eine getrennte Datenprüfung, dieser Leseweg ändert sie nicht.
- Leeres Ergebnis: echte CSV nur mit Kopfzeile, nicht ein Fehler oder ein erfundener Datensatz.
- UTF-8, Komma, CRLF-Zeilenenden. Alle Zellen stehen in doppelten Anführungszeichen; enthaltene Anführungszeichen werden verdoppelt. Kommas und eingebettete Zeilenumbrüche bleiben in einer Zelle.
- Riskante Formel-/Steuerzeichenanfänge, auch nach bestimmten Leer-/Formatzeichen und mit Vollbreitzeichen, erhalten einen schützenden Apostroph. Dieser ist Teil der exportierten Zeichenfolge. Normale Aktionscodes bleiben unverändert. **Keine universelle Tabellenprogramm-Garantie**, insbesondere nicht nach Speichern und erneutem Öffnen in Excel. Keine echte Excel-/LibreOffice-Abnahme und kein verlustfreies Rohdatenarchiv für beliebige historische Texte.
- Der vollständige Inhalt wird vor den Anhang-Headern erstellt. Erfolgreiche Antwort: `text/csv; charset=utf-8`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff` und dateibasierter Name `admin-audit-YYYY-MM-DD.csv` mit UTC-Downloadtag, ohne Kontosuche im Dateinamen.

Die Datei enthält private Konto-E-Mails. Geschützt aufbewahren und nur im berechtigten Rahmen teilen. Kontosuche steht auch in der Browseradresse; die Ansicht ist kein Freigabelink. Liste und späterer Export sind getrennte Abrufe, keine gemeinsam eingefrorene Momentaufnahme.

## Nebenwirkungen und verbleibende Grenzen

Lesen und Herunterladen verändern keine Protokolle, Einstellungen, Geschäfts-/Mitgliedsdaten oder Versandqueues und senden nichts. Der CSV-Abruf erzeugt selbst keinen zusätzlichen Audit-Eintrag. POST wird auch mit gültigem CSRF-Token mit 405 abgelehnt; die zentrale CSRF-Sperre bleibt bestehen. Andere Methoden sind ebenfalls gesperrt. Aktueller Entzug der Admin-Allowlist greift auch bei bereits angemeldeter Session. Admin-Lesen bleibt unabhängig vom Betreiber-Bezahlplan.

Es besteht weiterhin keine lückenlose Security-/Änderungshistorie für alle Module. Keine neuen Partnerprotokolle, Kategorien, Betroffenen-/Akteursfilter, Detailansichten mit Vorher/Nachher, Vollübersetzung, Manipulationssicherheit, Aufbewahrungsplanung, Löschung, Fernarchivierung, Anomalieerkennung oder Produktions-/Lastabnahme. Der CSV-Export ist ein begrenzter weiterer Schritt, keine volle Referenzparität.

## Tests

- Bisherige Suite unverändert: `python3 tests/run-admin-audit.py`, 39 HTTP-/Datenbank- und 20 Chromium-Prüfungen für Liste, Rollen, Filter, Cursor und mobile Tabellen.
- Neue Suite: `python3 tests/run-admin-audit-export.py`, 99 Domänen-, 86 HTTP-/Integrations- und 51 Chromium-Prüfungen. Enthält filtergleiche 205 Treffer über die 100er-Seite hinaus, ältere Seiten ohne Exportcursor, UTC-Tagesgrenzen und bewusst unveränderte Altzeit-Vergleiche, literale Kontosuche, fehlende Konten, Rollen/Allowlist-Entzug/Planunabhängigkeit, keine privaten JSON-Details, sichere Fehler, Methoden-/CSRF-Sperre und unveränderte Anwendungsdaten.
- Exakte 5000/5001-Zeilen-, 4-MiB/Plus-ein-Byte- und 64-KiB-Grenzen, ungültiges UTF-8, CSV-Formelanfänge/Quotes/Mehrzeiler, echte Browserdownloads und leere Dateien geprüft. Nur erwartete Login-Protokolle und Rate-Limit-Daten bei neuen Browseranmeldungen kommen hinzu; frühere Protokolle bleiben unverändert.
- Bilder unter `/tmp/loyalty-admin-audit-export/`: Desktop, mobile Exportsteuerung mit geschlossener/offener Hilfe, beschriftete Zeilen und Fehlerseite visuell geprüft. Nur Wegwerfdaten, keine echten Kontakte oder externen Provideraufrufe.

## Referenzen

- [Reward Loyalty: Viewing activity logs](https://rewardloyalty.co/docs/5.x/activity-logs/viewing-logs), am 22.11.2026 gelesen. Bestätigt Exportsteuerung und stabile CSV-Codes; das lokale Vier-Felder-Schema und die Grenzen sind eigene, dokumentierte Entscheidungen. Die Referenz hat deutlich mehr Protokollfunktionen und kontobezogene Zeitzonen.
- [OWASP: CSV Injection](https://community.owasp.org/attacks/CSV_Injection), am 22.11.2026 gelesen. Grundlage für defensives Quoting/Präfixe und die ausdrückliche Grenze jeder Tabellenprogramm-Zusage.
