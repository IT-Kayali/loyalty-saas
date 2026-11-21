# Support-Tickets

Eingebauter Helpdesk zwischen Betreibern (Partnern) und Plattform-Admins. Alles läuft in der App. E-Mail ist nur eine Benachrichtigung.

## Funktionen

- **Partner** (`/support`): Ticket anlegen (Thema, Betreff, Nachricht), eigene Tickets suchen und nach Status filtern, antworten. Funktioniert auf jedem Plan und auch bei abgelaufenem Abo.
- **Admin-Inbox** (`/admin/support`, nur `platform_admin_emails`): Filter nach Status, Priorität, Eingangskanal und Zuständigkeit (Namensauswahl, auch nicht zugewiesen), Suche nach Referenz, Betreff, Absender und Zuständigem, 50 Einträge pro Seite.
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

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/support-images python tests/run-support.py` führt 23 Domänenprüfungen, 91 HTTP-Prüfungen (Zugriff, Mandantentrennung, CSRF, Routenbindung, interne Notizen, Filter, Planablauf, Kontakt, Rate-Limit, Ausblenden) und 48 Chromium-Prüfungen mit Screenshots (Desktop und Mobil) aus. Benachrichtigungen werden nur in die Outbox geschrieben. Es läuft kein Mail-Worker und keine SMTP-Verbindung. Chromium über `NODE_PATH` mit Playwright.

## Grenzen (ehrlich)

Keine vollständige Referenzparität:
- Kein öffentlicher Kontakt-Thread und keine Antwort an Kontaktadressen (bewusst, da unbestätigt).
- Kein E-Mail-Eingang, keine Anhänge, keine Zuweisung an Teammitglieder oder delegierte Manager.
- Kein Snapshot von Zeitzone, Sprache oder App-Version am Ticket.
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

Interne Notizen und ihre Autorendaten, Zuständigkeit, Priorität, Absender-E-Mail, interne IDs, Vorgangskennungen und Benachrichtigungen gehören nicht zum Export. Die Ausgabe verwendet eine feste Feldliste, nicht den vollständigen Ticketdatensatz. Nur der angemeldete Eigentümer kann sein aktives Partner-Ticket exportieren. Fremde und ausgeblendete Tickets sowie Kontaktformular-Anfragen liefern 404. Kein Adminexport und keine Ausweitung von Kontaktberechtigungen. Wie Support selbst bleibt der Download ohne aktiven Bezahlplan verfügbar.

Nachrichten und Ticket werden in einer kurzen Lesetransaktion gelesen. Der Download verändert keine Daten, Mail-Outbox oder Audit. Private/no-store, kein Versand per E-Mail. Maximal 1000 sichtbare Nachrichten und 2 MiB fertig kodiertes JSON, sonst HTTP 422 ohne Teildatei. Interne Nachrichten zählen nicht zur 1000er-Grenze. Fehlerhafte gespeicherte UTF-8-Daten können den Export blockieren statt still ersetzt zu werden. Kein unbegrenzter Streamingexport.

Die Datei enthält private Supportinhalte, lokal sicher aufbewahren und nicht unbedacht weitergeben. Sie ist ein Datenstand beim Download, keine vollständige Änderungshistorie, Rechts-/Datenschutzzusage oder unveränderbares Archiv. Autorennamen sind die beim Download aktuellen Kontonamen, keine historischen Identitätssnapshots. Kein Sammel-/Adminexport, keine Anhänge, kein CSV/PDF, keine neue API. Die bestehenden Threadanzeigen und Ausblendungsregeln bleiben unverändert.

Zusatztests für den Download: 14 HTTP-Fälle für Schema/Feldliste, Volltextreihenfolge, Ausschluss interner Daten, exakten Eigentümer, Kontakt/ausgeblendet, no-store, Lesewirkung, Aboablauf und harte Nachrichten-/UTF-8-Bytegrenzen. Vier Browserprüfungen laden die tatsächliche Datei auf Desktop und Mobil herunter und prüfen den Inhalt ohne interne Notiz. Exportlink und Thread auf beiden Größen visuell geprüft.

## Themenfilter und sichtbare Themen

Das bei der Anlage gewählte Thema erscheint übersetzt im Threadkopf und in jeder Listenzeile. Betreiber und Plattform-Admins filtern ihre jeweilige Liste nach Abrechnung, Technik, Konto, Feedback oder Sonstiges. „Alle Themen“ entfernt nur den Themenfilter. Thema schränkt die vorhandenen Status-/Such-/Adminfilter zusätzlich ein; es ersetzt sie nicht. „Nächste 50“ behält das Thema und alle anderen Filter bei. Unbekannte Themen liefern 422 statt still alle Tickets zu zeigen.

Die Auswahl ist eine reine Leseaktion, kein Umkategorisieren. Kein Themeneditor, keine automatische Klassifizierung, keine Themenstatistik. Betreiber sehen weiterhin nur ihre eigenen aktiven Partner-Tickets; ein Themenfilter verknüpft keine Kontaktadresse mit einem Konto. Interne Notizen bleiben unsichtbar. Thema und Suche stehen in der Browseradresse. Keine neuen Benachrichtigungen oder Audit-/Statusänderungen.

Zusätzlich 12 HTTP-Prüfungen für sichtbare Bezeichnungen, beide Rollen, Filterkombinationen, fremde/ausgeblendete Tickets, Kontakttrennung, sichere Fehler, vollständige filtertreue Paginierung und reine Lesewirkung. Weitere 12 Browserprüfungen für Themenwechsel, Admin-/Betreiberliste, kombinierte Kanalauswahl und Desktop-/Mobilansichten.
