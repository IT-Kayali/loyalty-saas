# Support-Tickets

Eingebauter Helpdesk zwischen Betreibern (Partnern) und Plattform-Admins. Alles läuft in der App. E-Mail ist nur eine Benachrichtigung.

## Funktionen

- **Partner** (`/support`): Ticket anlegen (Thema, Betreff, Nachricht), eigene Tickets suchen und nach Status filtern, antworten. Funktioniert auf jedem Plan und auch bei abgelaufenem Abo.
- **Admin-Inbox** (`/admin/support`, nur `platform_admin_emails`): Filter nach Thema, Status, Priorität, Eingangskanal und Zuständigkeit (Namensauswahl, auch nicht zugewiesen), Suche nach Referenz, Betreff, Absender und Zuständigem, 50 Einträge pro Seite. Die gefilterte Metadatenliste lässt sich privat als CSV, TSV oder JSON herunterladen, ohne Nachrichten oder Absender-E-Mail-Feld.
- **Status:** Offen, Wartet auf Antwort, Geschlossen. Eine Partnerantwort öffnet ein geschlossenes Ticket wieder, eine öffentliche Admin-Antwort setzt "Wartet auf Antwort".
- **Interne Notizen:** nur für Admins sichtbar. Sie erscheinen nicht in Partner-Thread, Partner-Liste, Partner-Suche, Benachrichtigungen oder Audit und ändern den Status nicht.
- **Zuweisung und Priorität:** Zuständig ist nur ein aktueller Plattform-Admin.
- **Ausblenden:** weiches Löschen mit exakter Bestätigung der Referenz. Nachrichten bleiben gespeichert, sind aber nirgends mehr erreichbar.
- **Audit:** Aktion und Referenz, nie Nachrichtentexte.
- **Öffentlicher Kontakt** (`/contact`): Formular mit Honeypot und Rate-Limit (5 pro Stunde und IP).

## Sicherheitsregeln

- Zugriff immer über das Konto des Tickets (`user_id`). Fremde Tickets liefern 404.
- Die Ziel-Referenz einer Aktion muss zur URL passen. Listen-URLs legen nur an, Ticket-URLs bearbeiten nur das eigene Ticket.
- CSRF-Token, Rate-Limit (30 Schreibaktionen pro Stunde und Konto) und optimistische Versionsprüfung (veraltete Formulare werden abgelehnt).
- Jede Aktion trägt eine Vorgangskennung. Dasselbe Formular erneut zu senden legt nichts doppelt an. Anlage, Nachricht, Status, Audit und Benachrichtigung werden in einer Transaktion geschrieben.
- Der Kontakt-Absender ist **unbestätigt**. Eine eingegebene E-Mail verbindet nie mit einem Konto, gibt keinen Zugriff auf Tickets und bekommt keine Antwort oder Thread-Inhalte per E-Mail. Admins können Kontakt-Tickets nur intern bearbeiten (Notiz, Status, Ausblenden).

## E-Mail

`support_email` in `config.php` ist das Ziel für Operator-Benachrichtigungen (leer = keine). Partner werden an die E-Mail ihres Kontos benachrichtigt. Voraussetzung ist konfiguriertes SMTP (siehe [MAIL.md](MAIL.md)). Ohne SMTP wird nichts versendet. Benachrichtigungen enthalten nur Referenz und Link, keinen Ticket- oder Notiztext. Platzhalter-Domains (`example.*`, `.test`, `.local`) werden übersprungen. Antworten per E-Mail werden nicht in Tickets übernommen (kein Inbound-Parser).

## Tests

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/support-images python tests/run-support.py` führt 23 Domänenprüfungen, 113 HTTP-Prüfungen (Zugriff, Mandantentrennung, CSRF, Routenbindung, interne Notizen, Filter, Planablauf, Kontakt, Rate-Limit, Ausblenden) und 58 Chromium-Prüfungen mit Screenshots (Desktop und Mobil) aus. Benachrichtigungen werden nur in die Outbox geschrieben. Es läuft kein Mail-Worker und keine SMTP-Verbindung. Chromium über `NODE_PATH` mit Playwright.

## Grenzen (ehrlich)

Keine vollständige Referenzparität. Grenzen nicht mit bestätigten Referenzlücken verwechseln:
- Kein öffentlicher Kontaktbesucher-Thread und kein E-Mail-Eingangsparser, beides auch so in der [Referenz](https://rewardloyalty.co/docs/5.x/administration/support-tickets) beschrieben.
- Anders als die Referenz keine E-Mail-Antwort an Kontaktformular-Absender. Bewusste lokale Sicherheitsgrenze wegen unbestätigter Adressen. Lokale Kontaktanfragen bleiben immer ohne Kontobindung; die Referenz beschreibt eine Kontozuordnung für bereits angemeldete Mitglieder, nicht anhand einer frei eingegebenen E-Mail-Adresse.
- Keine Anhänge oder Zuweisung an Betreiber-Teammitglieder. Aus der geprüften Support-Detailseite folgt dafür keine eigene Referenzanforderung. Plattform-Zugriff ist hier auf die Admin-Allowlist begrenzt, kein zusätzliches globales Managerrollenmodell.
- Kein Snapshot von Zeitzone, Sprache oder App-Version am Ticket; keine Suche nach übersetzten Status-/Prioritäts-/Kanallabels. Die Referenz beschreibt beides.
- Keine Support-API. Nur Session-Formulare.
- Keine Echtzeit-Aktualisierung. Veraltete Formulare werden abgelehnt und müssen neu geladen werden.
- Keine echte SMTP-Zustellung getestet. Nicht produktiv abgenommen.

## Kanal und Zuständigkeit in der Inbox

Plattform-Admins können alle Eingänge, angemeldete Betreiber oder das unbestätigte Kontaktformular wählen. Die Tabellenzeile zeigt den Kanal ausdrücklich. Ein Kontaktformular wird dadurch weder authentifiziert noch mit einem Konto verbunden; die bisherigen Antwortsperren bleiben bestehen.

Zuständigkeit wird per Name/E-Mail aktueller Plattform-Admins gewählt, mit „Alle“ und „Nicht zugewiesen“. Bestehende Links mit numerischer `assignee`-ID funktionieren weiter. Eine alte oder unbekannte positive ID bleibt als solche sichtbar, damit der Filter nicht still auf „Alle“ zurückfällt. Das ist keine neue Zuweisung und keine Aufwertung eines früheren Kontos zum Admin.

Kanal, Status, Priorität, Zuständigkeit und Suchtext bleiben beim Link „Nächste 50“ erhalten. Ein unbekannter Kanal liefert HTTP 422. Auf Partnerseiten bewirken Admin-Filter keine Erweiterung des Zugriffs. Filter schreiben keine Nachrichten, Status, Outbox oder Auditdaten. Suchbegriffe und Filter stehen in der Browseradresse. Kein gespeicherter Filter, automatisches Aktualisieren oder Gesamtzähler. Die Listen zeigen auf Mobilgerätegröße alle Felder untereinander statt seitlichem Tabellen-Scrollen.

Zusatztests: 12 HTTP-Prüfungen für Kanaltrennung, kombinierte Filter, Partnerisolation, sichere Fehler, ausgeblendete Tickets, Lesewirkung, historische Zuständigkeit und 52 passende Datensätze auf zwei Seiten ohne Überschneidung. 10 zusätzliche Browserprüfungen für beide Kanalwechsel, Namensauswahl, vollständige Tabellen und Desktop-/Mobilansicht.

## Eigenen Thread herunterladen

Im eigenen Betreiber-Ticket lädt „Sichtbaren Thread als JSON laden“ eine private Datei `support-REFERENZ.json`. JSON ist UTF-8, mit Formatkennung `loyalty-support-thread`, Version 1, UTC-Exportzeit, Ticketreferenz, Betreff, Thema, Status und Anlage-/Änderungszeit. Die Nachrichten enthalten die bereits in der Partneransicht sichtbaren Autorennamen, UTC-Zeiten und vollständigen Texte in Reihenfolge. Es ist keine HTML-Datei und kein wieder importierbares Backup.

Interne Notizen und ihre Autorendaten, Zuständigkeit, Priorität, Absender-E-Mail, interne IDs, Vorgangskennungen und Benachrichtigungen gehören nicht zum Export. Die Ausgabe verwendet eine feste Feldliste, nicht den vollständigen Ticketdatensatz. Nur der angemeldete Eigentümer kann sein aktives Partner-Ticket exportieren. Fremde und ausgeblendete Tickets sowie Kontaktformular-Anfragen liefern 404. Kein Admin-Threadexport und keine Ausweitung von Kontaktberechtigungen. Wie Support selbst bleibt der Download ohne aktiven Bezahlplan verfügbar.

Nachrichten und Ticket werden in einer kurzen Lesetransaktion gelesen. Der Download verändert keine Daten, Mail-Outbox oder Audit. Private/no-store, kein Versand per E-Mail. Maximal 1000 sichtbare Nachrichten und 2 MiB fertig kodiertes JSON, sonst HTTP 422 ohne Teildatei. Interne Nachrichten zählen nicht zur 1000er-Grenze. Fehlerhafte gespeicherte UTF-8-Daten können den Export blockieren statt still ersetzt zu werden. Kein unbegrenzter Streamingexport.

Die Datei enthält private Supportinhalte, lokal sicher aufbewahren und nicht unbedacht weitergeben. Sie ist ein Datenstand beim Download, keine vollständige Änderungshistorie, Rechts-/Datenschutzzusage oder unveränderbares Archiv. Autorennamen sind die beim Download aktuellen Kontonamen, keine historischen Identitätssnapshots. Kein Thread-Sammelexport, keine Anhänge, kein CSV/PDF für einzelne Threads, keine neue API. Der getrennte Admin-Listenexport unten enthält keine Nachrichten. Die bestehenden Threadanzeigen und Ausblendungsregeln bleiben unverändert.

Zusatztests für den Download: 14 HTTP-Fälle für Schema/Feldliste, Volltextreihenfolge, Ausschluss interner Daten, exakten Eigentümer, Kontakt/ausgeblendet, no-store, Lesewirkung, Aboablauf und harte Nachrichten-/UTF-8-Bytegrenzen. Vier Browserprüfungen laden die tatsächliche Datei auf Desktop und Mobil herunter und prüfen den Inhalt ohne interne Notiz. Exportlink und Thread auf beiden Größen visuell geprüft.

## Themenfilter und sichtbare Themen

Das bei der Anlage gewählte Thema erscheint übersetzt im Threadkopf und in jeder Listenzeile. Betreiber und Plattform-Admins filtern ihre jeweilige Liste nach Abrechnung, Technik, Konto, Feedback oder Sonstiges. „Alle Themen“ entfernt nur den Themenfilter. Thema schränkt die vorhandenen Status-/Such-/Adminfilter zusätzlich ein; es ersetzt sie nicht. „Nächste 50“ behält das Thema und alle anderen Filter bei. Unbekannte Themen liefern 422 statt still alle Tickets zu zeigen.

Die Auswahl ist eine reine Leseaktion, kein Umkategorisieren. Kein Themeneditor, keine automatische Klassifizierung, keine Themenstatistik. Betreiber sehen weiterhin nur ihre eigenen aktiven Partner-Tickets; ein Themenfilter verknüpft keine Kontaktadresse mit einem Konto. Interne Notizen bleiben unsichtbar. Thema und Suche stehen in der Browseradresse. Keine neuen Benachrichtigungen oder Audit-/Statusänderungen.

Zusätzlich 12 HTTP-Prüfungen für sichtbare Bezeichnungen, beide Rollen, Filterkombinationen, fremde/ausgeblendete Tickets, Kontakttrennung, sichere Fehler, vollständige filtertreue Paginierung und reine Lesewirkung. Weitere 12 Browserprüfungen für Themenwechsel, Admin-/Betreiberliste, kombinierte Kanalauswahl und Desktop-/Mobilansichten.

## Eigenes Ticket schließen und wieder öffnen

Im eigenen aktiven Partner-Thread steht unter der Antwort ein getrennter Statusschalter. „Eigenes Ticket schließen“ setzt auf Geschlossen; dort bietet „Eigenes Ticket wieder öffnen“ Offen an. Auch bei ausgelaufenem Plan verfügbar. Eine Nachricht ist dafür nicht nötig. Bereits vorhandene Nachrichten und internen Notizen bleiben unverändert und behalten ihre Sichtbarkeit. Der eigene sichtbare JSON-Export bleibt auch geschlossen verfügbar.

Der Schalter ändert nur Status, Änderungszeit und Version dieses Tickets. Keine Antwort, Mail oder Benachrichtigung, kein Löschen. Priorität und Zuständigkeit bleiben auch bei manipulierten Zusatzfeldern unverändert. „Wartet auf Antwort“ ist nicht als Partnerziel erlaubt. Die bisherige Regel bleibt: Eine spätere Partnerantwort öffnet ein geschlossenes Ticket ebenfalls wieder; Admins können weiter ihren eigenen Bearbeitungsstand setzen. Geschlossen sperrt Antworten nicht dauerhaft und ist keine Zusage, dass das Anliegen gelöst ist.

Eigentümer/Partnerkanal, CSRF, URL-Zielbindung, Rate-Limit, Versionsprüfung und Vorgangskennung gelten auch hier. Ein altes Formular überschreibt keine neuere Antwort oder Statusänderung. Dasselbe Formular erneut senden führt denselben Vorgang nicht doppelt aus. Status, Referenz-Audit `support.partner_state` und Vorgangsbeleg werden in einer Transaktion gespeichert; ein Fehler rollt alles zurück. Keine neue vollständige Statushistorie im Thread oder Export. Kontaktanfragen bleiben unbestätigt und haben keinen Schalter. Ausgeblendete Tickets können hier nicht wieder geöffnet werden. Admins nutzen weiter ihre vorhandene Statusverwaltung.

Zusätzlich 22 HTTP-Prüfungen für Schließen/Öffnen, Planablauf, private Exporte, unveränderte Nachrichten/Mails/Adminfelder, exakte Wiederholung, alte Formulare, CSRF, fremde/ausgeblendete/Kontakt-Tickets, Routenbindung, erlaubte Zustände, Audit und Rollback. Zehn zusätzliche Browserprüfungen für Desktop/Mobil und echten Zwei-Tab-Konflikt. Geschlossenansichten visuell geprüft. Kein echter E-Mail-/Provideraufruf.

## Gefilterte Admin-Liste herunterladen

Auf `/admin/support` stehen unter der Suche drei Links: "Liste als CSV", "Liste als TSV" und "Liste als JSON". Sie übernehmen Suchtext, Thema, Status, Priorität, Kanal und Zuständigkeit. Gemeint sind **alle passenden, nicht ausgeblendeten Tickets, auch geschlossene**, nicht nur die angezeigten 50 oder die restlichen Treffer ab einem Seiten-Cursor. Auch auf Seite zwei beginnt der Export beim ersten passenden Ticket. Suche/Filter anwenden, dann herunterladen. Änderungen anderer Personen zwischen Listenaufruf und Download können den Datenstand verändern.

Technische Route: `GET /admin/support/export`, Standard `format=csv`, alternativ `tsv` oder `json`. Nur ein aktuell zugelassener Plattform-Admin, unabhängig vom Bezahlplan eines Betreibers. Betreiber, Mitglieder und Personal bekommen keinen neuen Exportzugriff. Kein Versand, keine Schreibaktion, keine zusätzliche API-Berechtigung. Andere Methoden liefern 405; Cursor und unbekannte Exportparameter 422 statt still eine andere Datenmenge zu liefern. Eigener Partner-Threadexport bleibt getrennt und unverändert.

Feste Spalten in allen Formaten, auf Listenmetadaten begrenzt:

| Feld | Inhalt |
| --- | --- |
| `reference` | Sichtbare Ticketreferenz |
| `subject` | Betreff, kein Nachrichtentext |
| `topic` | Gespeicherter Themencode, z. B. `billing` |
| `status` | Gespeicherter Statuscode, z. B. `open` |
| `priority` | Gespeicherter Prioritätscode |
| `updated_at_utc` | Änderungszeit wie in der Liste, UTC |
| `channel` | Gespeicherter Kanalcode, `partner` oder `contact` |
| `requester_name` | Gespeicherte Absenderangabe, bei Kontakt nicht verifiziert |
| `assigned_name` | Beim Download aktueller Kontoname des Zuständigen, sonst JSON `null` bzw. leere CSV-/TSV-Zelle |

Keine Absender-E-Mail-Spalte, internen IDs, Vorgangskennungen, Nachrichten, internen Notizen oder ausgeblendeten Tickets. Namen und Betreff können selbst private Angaben enthalten, auch eine dort eingegebene E-Mail-Adresse. Die Datei deshalb privat aufbewahren. Zuständigkeitsnamen sind keine historischen Identitätssnapshots. Eine eingegebene Kontaktadresse wird weder bestätigt noch mit einem Konto verbunden.

JSON ist UTF-8 und enthält `format=loyalty-support-inbox`, `version=1`, `exported_at_utc`, `row_count`, `row_limit=5000`, `byte_limit=4194304`, `truncated=false` sowie die `tickets`-Liste. CSV/TSV haben dieselbe feste Kopfzeile, Komma bzw. Tab als Trenner und CRLF als Datensatztrenner. Jeder Zellwert wird in doppelte Anführungszeichen eingeschlossen; enthaltene Anführungszeichen werden verdoppelt. Riskante Textanfänge, auch nach Leer-/Steuerzeichen und in voller Zeichenbreite, bekommen ein führendes Apostroph. Das verändert betroffene Werte. Für unveränderte Werte JSON verwenden. Tabellenprogramme verhalten sich unterschiedlich; besonders erneutes Speichern/Öffnen kann Schutzpräfixe entfernen. Keine universelle Formel-Sicherheitsgarantie oder echte Excel-/LibreOffice-Abnahme. [OWASP-Hinweise](https://community.owasp.org/attacks/CSV_Injection).

Ein einziges SELECT liefert Ticketmetadaten und aktuelle Namen konsistent für diesen Download. Keine Nachrichtentabelle wird gelesen. Maximal **5000 Treffer und 4 MiB fertig kodierte Ausgabe je Format**. Die Grenzen sind eigene lokale Schutzgrenzen, keine aus der Referenz abgeleiteten Supportwerte. Überschreitung oder fehlerhaftes gespeichertes UTF-8 ergibt HTTP 422 ohne Attachment oder Teildatei. Nichts wird still ersetzt oder abgeschnitten. Leere Treffer liefern Kopfzeile bzw. leere JSON-Liste. `private, no-store`, `nosniff`, fester datierter Dateiname. Kein Backup, Importformat, unveränderbares Archiv oder vollständige Änderungshistorie.

Liste und Export nutzen dieselbe Filtervalidierung und WHERE-Erzeugung. Gültige bisherige Filterlinks einschließlich historischer numerischer Zuständigkeit bleiben gültig. Neu werden ungültige Status-/Prioritätswerte, Arrays, ungültiges UTF-8, Steuer-/Formatzeichen, Suchtexte über 100 Zeichen und ungültige/zu große Zahlen abgelehnt, statt Filter still zu ignorieren oder Suchtext zu kürzen. Auf Partnerlisten werden Adminfilter weiterhin ohne Zugriffserweiterung ignoriert. Suche bleibt eine Literalsuche mit maskiertem `%`, `_` und `!`; keine übersetzte Labelsuche.

Tests: `PHP_BIN=/pfad/php NODE_PATH=/pfad/node_modules python3 tests/run-support-list.py`. 73 Domänen-, 87 HTTP- und 45 Chromium-Prüfungen. Einschließlich unabhängiger Filtererwartungen, Liste/Export/Paginierung über 52 Treffer, aller sechs Filter, Rollen und Widerruf, Hidden-/Notizschutz, Feld-Whitelist, tatsächlicher Downloads in drei Formaten auf 1280/390/320 px, genau 5000/5001 Zeilen, exakt 4 MiB/+1 Byte, mehrbyte-bedingter Überschreitung, ungültigem UTF-8, Formelpräfixen/Quotes/Trennern/Zeilenumbrüchen und unveränderten Tabellen/Audit/Outboxes. Bestehende Support-Suite zusätzlich unverändert weiter ausführen. Kein SMTP-/Worker-/Provideraufruf.

## Quellenabgleich vom 22. November 2026

Die [Support-Referenz vom 5. Oktober 2026](https://rewardloyalty.co/docs/5.x/administration/support-tickets) nennt ausdrücklich den **Listenexport**, nicht einen einzelnen Admin-Threadexport. Die [Exportdokumentation vom 26. Oktober 2026](https://rewardloyalty.co/docs/5.x/administration/data-export) beschreibt Filterübernahme, CSV/TSV/JSON und Rohcodes. Konkrete Supportspalten oder Supportmengenlimits sind dort nicht vorgegeben; die dortigen 50.000 Zeilen mit Trunkierungsmarkierung gelten ausdrücklich für Ledger. Hier stattdessen ein harter eigener Abbruch ohne Teildatei. Dieser Ausbau ist keine vollständige Support- oder Exportparität.
