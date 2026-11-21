# Segmente und Kampagnen sicher löschen

Die vorhandenen Löschbuttons führen jetzt zuerst auf eine getrennte Prüfseite mit Name und Unternehmen. „Abbrechen, nichts löschen“ kehrt zurück. Erst „Endgültig löschen“ entfernt den zulässigen Datensatz. CSRF und Eigentümer-/Planprüfung gelten für beide Schritte. Die Prüfseite ist privat und nicht cachebar.

## Was gelöscht werden darf

- Segment nur ohne verknüpfte Kampagne, unabhängig von deren Status. Das verhindert die bisherige indirekte Datenbank-Kaskade, die beim Segmentlöschen auch Kampagnen und Versandbelege entfernen konnte.
- Kampagne nur im Status `draft`, ohne Versandbelege und ohne zugehörige Outbox-Einträge. Vorbereitete und historisch teilweise vorbereitete Kampagnen bleiben erhalten.
- Unvorbereitete Entwürfe können im Editor einem anderen Segment zugeordnet oder einzeln nach Bestätigung gelöscht werden. Ein Segment einer bereits vorbereiteten Kampagne bleibt damit absichtlich gebunden. Kein Archiv-/Ausblendungsworkflow ergänzt.

Die endgültige Löschung prüft Eigentümer, Plan, Status, Verknüpfung und Formularkonflikt unter `BEGIN IMMEDIATE` erneut. Änderungen seit der Prüfseite blockieren die Löschung. Bei gleichzeitigem Versand und Löschen eines Entwurfs kann nur eine Aktion gewinnen: entweder Entwurf gelöscht, kein Versand; oder Versand vorbereitet, keine Löschung. Eine neu verknüpfte Kampagne blockiert ebenfalls die Segmentlöschung.

Audit `segment.delete` oder `campaign.delete` enthält nur Objekt- und Unternehmens-ID, keine Namen oder Nachrichtentexte. Der reine Prüfschritt schreibt keinen Lösch-Audit.

## Grenzen

Dies schützt die beiden vorhandenen Eigentümer-Löschaktionen, nicht jede Kaskade der Datenbank. Unternehmens-/Kontolöschung, Datenschutzlöschung einzelner Mitglieder und direkte administrative Datenbankeingriffe sind nicht neu entworfen. Die Fremdschlüsselstruktur bleibt unverändert. Es gibt keinen Versandrückruf, keine Wiederherstellung, kein Löschen historischer Inbox-Nachrichten oder bereits zugestellter E-Mails. Historische unzuordenbare Inbox-Altlasten werden nicht repariert. Kein neuer API-/MCP-Löschpfad. Ein gleichzeitig verschwindendes Segment kann bei der bisherigen Kampagnenanlage einen Fehler statt Anlage verursachen; kein automatischer Wiederholungsworkflow.

## Tests

`python3 tests/run-marketing-delete.py`: 23 Domänen-/HTTP-Prüfungen für Vorschau ohne Schreibwirkung, CSRF, Revision, getrennte bestätigte Löschung, Audit, Mandant/Plan, historische Teilversände/Outbox, nachträgliche Verknüpfung, Versand seit Prüfung und echte parallele Lösch-/Versandprozesse. Dazu 11 Chromium-Prüfungen für Desktop/Mobil, konkrete Namen, Abbrechen, blockierte Verknüpfung und bestätigtes Entfernen. Screenshots unter `/tmp/loyalty-marketing-delete/` visuell geprüft. Lokale Wegwerfdaten, kein echter Versand.
