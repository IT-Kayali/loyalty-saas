# Meilensteine vor der ersten Freischaltung bearbeiten

Unternehmen öffnen, beim Meilenstein „Prüfen / Bearbeiten“ wählen. Name (1 bis 80 Zeichen), eine der vorhandenen vier Messgrößen, Schwelle 1 bis 100000 und Bonus 0 bis 100000 können geändert werden, solange kein gespeicherter Vergabebeleg existiert. Ungültige Zeichen und Werte werden abgewiesen. Nur der Eigentümer, mit CSRF und Planberechtigung. Die Seite ist privat/no-store.

Speichern vergibt keine Punkte, Freischaltungen oder Nachrichten und prüft nicht nachträglich alle Mitglieder. Die nächste bereits unterstützte Kartenaktion prüft die dann gespeicherte Regel. Ein alter Tab kann neuere Eingaben nicht überschreiben. Prüfseite zeigt aktuelle Regel und Zahl gespeicherter Freischaltungen.

## Erreichte Regeln und sichere Vergabe

Sobald ein `member_achievements`-Beleg existiert, bleiben Regel und Bonus über diese Verwaltung unveränderbar und unlöschbar. Der vorhandene Löschpfad kann damit erreichte Meilensteine nicht mehr samt Vergabebelegen entfernen. Für eine neue Regel einen neuen Meilenstein anlegen, dabei die möglicherweise zusätzliche Prämie bedenken. Pause/Fortsetzen siehe unten; kein Archiv ergänzt.

Vergabeprüfung und Editor/Löschen verwenden denselben `BEGIN IMMEDIATE`-Schreiblock und lesen den Stand darin erneut. Bei gleichzeitigem Speichern und Freischalten wird entweder die neue Regel zuerst gespeichert oder eine unter der alten Regel erfolgte Vergabe sperrt die Änderung. Zwei parallele Prüfungen vergeben denselben Meilenstein nicht doppelt. Vergabebeleg, Bonus, Punktebuchung und Wallet-Inbox werden gemeinsam gespeichert oder bei einem Datenbankfehler vollständig zurückgenommen. Die auslösende Kartenbuchung ist weiterhin eine getrennte Operation und wird durch einen Fehler der anschließenden Meilensteinprüfung nicht zurückgenommen.

Webhooks laufen erst nach dem Meilenstein-Commit. Sie können fehlschlagen; das nimmt bereits vergebene Punkte nicht zurück. Zwischen Commit und Webhook besteht eine Unterbrechungslücke, kein neuer dauerhafter Ereignis-Outboxpfad. Kein echter Provider-/Netzwerktest. Audit `achievement.update`/`achievement.delete` enthält nur Meilenstein-/Unternehmens-ID, keine Regeltexte.

## Bestehende Regeln und Grenzen

Vier vorhandene Messgrößen: Stempel gesamt, Lifetime-Punkte, Besuche gesamt, Einlösungen gesamt. Keine neuen Metriken, Wochenzeiträume, Wiederholungen, individuellen Limits oder Gutscheinprämien. Die vorhandene Metrikberechnung und unterstützten Auslöser bleiben bestehen; separat verkaufte Prepaid-Besuche und Code-Gutscheine erweitern diese Messgrößen nicht automatisch.

Bonuspunkte gehen wie bisher auf die erste gefundene Punktekarte des Mitglieds im Unternehmen, ohne Wahl des Programms und ohne neu eingeführte Aktivstatusprüfung. Ohne Punktekarte kann ein Meilenstein erreicht werden, ohne Bonusbuchung oder späteren Nachtrag. Die Prüfseite sagt nicht zu, dass ein Bonus jedem Mitglied gutgeschrieben werden kann. Vergabeboni beeinflussen Lifetime-Punkte; weitere Bedingungen werden innerhalb einer Prüfung nicht rekursiv neu berechnet.

Kein dauerhaftes historisches Regel-Snapshot: Löschen eines Mitglieds kann Vergabebelege entfernen und damit später die Bearbeitung wieder ermöglichen. Unternehmens-/Kontolöschung und direkte Datenbankeingriffe sind nicht neu geschützt. Vorhandene Anlagevalidierung und sofortiges Löschen noch nie erreichter Regeln wurden nicht neu entworfen. Kein neuer API-/MCP-Editor, keine echte Wallet-Geräte-/Lastabnahme.

## Tests

`python3 tests/run-achievement-edit.py`: 50 Domänen-/HTTP-/Parallelprüfungen, darunter Feldgrenzen, Mandant/CSRF/Plan, Nebenwirkungsfreiheit, alte Tabs, drei echte Edit-/Vergabe-Prozesspaare, konkurrierende genau-einmal-Vergabe, historischer Löschschutz und erzwungener Fehler mit vollständigem lokalem Rollback. Dazu 18 Chromium-Prüfungen für Desktop/Mobil, Speichern, Konflikt, Nur-Lesen und Überlauf. Screenshots unter `/tmp/loyalty-achievement-edit/` visuell geprüft. Nur Wegwerfdaten, keine echten Bonuspunkte oder Webhooks an externe Systeme.

## Pausieren und fortsetzen

Auf der Prüfseite gibt es einen getrennten Schalter. Auch bereits erreichte Regeln dürfen pausiert oder fortgesetzt werden, ohne Name/Messgröße/Schwelle/Bonus zu ändern. Pause stoppt zukünftige Freischaltungen. Bestehende Vergabebelege, Bonuspunkte, Buchungen und Nachrichten bleiben erhalten. Fortsetzen löst keine nachträgliche Prüfung aus; erst eine nächste unterstützte Kartenaktion prüft die aktive Regel.

Die Betreiberliste zeigt aktive und pausierte Regeln. Die angemeldete Wallet zeigt weiterhin bereits erreichte pausierte Meilensteine; noch nicht erreichte pausierte Regeln werden nicht als offenes Ziel angezeigt. Kein neuer Wallet-Push oder Versand. Die Seite bleibt ein geladener Stand.

Der Schalter verlangt Eigentümer, CSRF, Plan, exakten Status 0/1 und passende Revision. Status und Vergabe werden unter demselben Schreiblock geprüft. Wenn eine Vergabe vor der Pause gewinnt, bleibt sie bestehen; nach der Pause erfolgen keine neuen Vergaben dieser Regel. Ein alter Editor oder Schalter kann den neuen Status nicht überschreiben. Audit `achievement.state` mit IDs, keine Nachrichtentexte. Kein automatisches Wiederaufnehmen, kein Zeitplan. Pausieren macht eine erreichte Regel weder editierbar noch löschbar.

Zusätzlich getestet: 16 Domänen-/HTTP-/Parallelfälle für Pause/Fortsetzen, Rechte/Plan/CSRF, Wallet-Ziel-/Erfolgsanzeige, keine Vergabe beim Schalten, spätere Prüfung, Statuskonflikt, Audit und Rennen gegen Vergabe. Sechs Browserprüfungen für Desktop/Mobil zu Fortsetzen, Pause und weiterhin gesperrter Regel. Screenshots des pausierten Zustands visuell geprüft.
