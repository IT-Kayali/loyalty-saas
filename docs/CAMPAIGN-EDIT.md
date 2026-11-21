# Kampagnenentwurf bearbeiten und prüfen

Unternehmen öffnen, in der Kampagnenliste **Prüfen / Bearbeiten** wählen. Auf der eigenen Seite stehen Name, Betreff, Segment und vollständiger gespeicherter Text. Bei einem unversendeten Entwurf können diese Felder geändert werden. Speichern erzeugt keine Inbox-Nachrichten, Versandbelege oder Outbox-Einträge. Der Senden-Button bleibt getrennt auf der Unternehmensseite.

## Schutzregeln

- Nur der Eigentümer des Unternehmens kann lesen oder ändern. POST verlangt CSRF und die Kampagnen-Planberechtigung.
- Name 1 bis 80, Betreff 1 bis 120, Text 1 bis 2000 Zeichen. Rand-Leerzeichen werden entfernt. NUL und ungültiges UTF-8 werden abgewiesen. Das Zielsegment muss zum selben Unternehmen gehören.
- Ein Formular trägt eine Revision des gespeicherten Inhalts und Status. Ein veralteter Tab kann neueren Text nicht überschreiben. Nach dem Konflikt erscheint der aktuelle Stand, die ungespeicherte Eingabe wird nicht aufbewahrt.
- Änderungen prüfen Eigentümer, Plan, Status, Revision und Segment erneut unter demselben SQLite-Schreiblock wie die lokale Versandvorbereitung. Gewinnt Speichern den Lock zuerst, liest die nachfolgende Vorbereitung den neuen Text. Gewinnt Versand zuerst, bleibt der versendete Text unverändert und Speichern wird abgewiesen.
- Nur Status `draft` ohne Versandbelege und ohne kampagnenbezogene Outbox-Einträge ist editierbar. Abgeschlossene Kampagnen und historische Teilversände sind nur lesbar. Eine Outbox allein genügt zum Sperren. Keine automatische Reparatur alter Daten.
- Audit `campaign.update` enthält Kampagnen-/Unternehmens-ID, keine Nachrichtentexte.

## Grenzen

Die Zielgruppe bleibt dynamisch. Die Segmentzahlen sind aktuelle Leseergebnisse und kein fester Empfängerbestand. Die Ansicht zeigt den gespeicherten Text escaped, keine HTML-Mailvorschau oder Zustellbestätigung. Die Wallet-Inbox enthält weiterhin nur den bestehenden gekürzten Ausschnitt, optionale E-Mail den vollständigen Text. Keine Zeitplanung, Testmail, automatische Speicherung oder Versionenliste. Kein neuer API-/MCP-Pfad. Vorhandenes Löschen und Kampagnenanlage wurden in dieser Etappe nicht neu entworfen. Ältere überlange Entwürfe bleiben lesbar, müssen zum Bearbeiten auf die angegebenen Längen gekürzt werden. Kein echter Provider-, Geräte- oder Lasttest.

## Tests

`python3 tests/run-campaign-edit.py`: 35 Prüfungen für Felder, Mandant, CSRF, Plan, Revision, escaping, fehlende Nebenwirkungen, abgeschlossene/teilweise vorbereitete Kampagnen und vier echte konkurrierende Edit-/Versand-Prozesspaare mit Inhaltsabgleich. Dazu 12 Chromium-Prüfungen auf Desktop und Mobilgerät-Größe für Link, Speichern, Volltext, zwei Tabs, Nur-Lesen und Überlauf. Nur lokale Wegwerfdaten, kein SMTP-Versand. Screenshots in `/tmp/loyalty-campaign-edit/` visuell geprüft.
