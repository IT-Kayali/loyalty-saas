# Support-Tickets

Eingebauter Helpdesk zwischen Betreibern (Partnern) und Plattform-Admins. Alles läuft in der App. E-Mail ist nur eine Benachrichtigung.

## Funktionen

- **Partner** (`/support`): Ticket anlegen (Thema, Betreff, Nachricht), eigene Tickets suchen und nach Status filtern, antworten. Funktioniert auf jedem Plan und auch bei abgelaufenem Abo.
- **Admin-Inbox** (`/admin/support`, nur `platform_admin_emails`): Filter nach Status, Priorität und Zuständigkeit (Admin-ID, 0 = nicht zugewiesen), Suche nach Referenz, Betreff, Absender und Zuständigem, 50 Einträge pro Seite.
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

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/support-images python tests/run-support.py` führt 23 Domänenprüfungen, 53 HTTP-Prüfungen (Zugriff, Mandantentrennung, CSRF, Routenbindung, interne Notizen, Filter, Planablauf, Kontakt, Rate-Limit, Ausblenden) und 22 Chromium-Prüfungen mit Screenshots (Desktop und Mobil) aus. Benachrichtigungen werden nur in die Outbox geschrieben. Es läuft kein Mail-Worker und keine SMTP-Verbindung. Chromium über `NODE_PATH` mit Playwright.

## Grenzen (ehrlich)

Keine vollständige Referenzparität:
- Kein öffentlicher Kontakt-Thread und keine Antwort an Kontaktadressen (bewusst, da unbestätigt).
- Kein E-Mail-Eingang, keine Anhänge, kein Export, kein Kanal-Filter, keine Zuweisung an Teammitglieder oder delegierte Manager.
- Kein Snapshot von Zeitzone, Sprache oder App-Version am Ticket.
- Keine Support-API. Nur Session-Formulare.
- Zuständigkeitsfilter über Admin-ID statt Namensauswahl.
- Keine Echtzeit-Aktualisierung. Veraltete Formulare werden abgelehnt und müssen neu geladen werden.
- Keine echte SMTP-Zustellung getestet. Nicht produktiv abgenommen.
