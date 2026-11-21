# Einfache Segmente bearbeiten

Auf der Unternehmensseite hat jedes gespeicherte Segment einen Link "Bearbeiten". Der Editor unter `/segment/ID/edit` gehört ausschließlich dem Eigentümer des Unternehmens. GET liest nur; POST mit CSRF prüft die Vorschau oder speichert ausdrücklich Name, Regel und Wert. Business-/Segment-IDs aus Formularfeldern können das Ziel nicht ändern.

## Umfang

Die sechs vorhandenen Einzelregeln bleiben dieselben: alle Mitglieder, neue Mitglieder, aktiv/inaktiv in X Tagen, mindestens X Lifetime-Punkte und mindestens X Stempel. Name 1 bis 80 Zeichen, Wert eine ganze Zahl zwischen 0 und 100000. Bei "Alle Mitglieder" wird der Wert nicht zur Auswahl verwendet. Tagesregeln verwenden UTC. Es gibt keine neue Regelsemantik, kein AND/OR und keine Empfängerliste.

Der Editor nennt die Anzahl der Mitglieder nach der aktuell gespeicherten Regel. „Vorschau prüfen“ berechnet die Anzahl für die ungespeicherten Formulareingaben separat. Segment-/Kampagnendaten, Versand, Outbox und Audit bleiben dabei unverändert. Eingaben und Revision bleiben im Formular erhalten; erst „Segment speichern“ übernimmt die Regel. Nach Speichern wird die gespeicherte Anzahl neu berechnet. Eine Zielgruppe ist dynamisch: zwischen Bearbeiten und einem späteren Versand kann sie sich durch neue Mitglieder oder Buchungen ändern. Die Zahl ist kein festgeschriebener Versandbestand.

## Kampagnen und Konflikte

Verknüpfte Kampagnen behalten dieselbe Segment-ID. Noch nicht gesendete Kampagnen verwenden bei ihrem späteren Versand den dann gelesenen Regelsatz. Der Editor nennt deren Anzahl und warnt vor dieser Wirkung. Bereits gesendete Kampagnen, Versandprotokolle, Inbox und Mail-Outbox werden vom Bearbeiten nicht geändert. Speichern sendet keine Nachrichten und erzeugt keine Versandaufträge.

Ein SHA-256-Vergleich des gespeicherten Namens, der Regel und des Werts schützt vor dem Überschreiben einer zwischenzeitlichen Bearbeitung. Innerhalb einer `BEGIN IMMEDIATE`-Transaktion wird der Stand neu gelesen und verglichen. Bei Konflikt wird nicht gespeichert; der aktuelle Stand wird mit Hinweis neu geladen. Es gibt keine Historie oder Zusammenführung. Wenn zwischenzeitlich exakt wieder derselbe Zustand hergestellt wurde, ist das kein erkennbarer Konflikt. Der Vergleich reserviert kein Segment für einen parallel gestarteten Kampagnenversand. Bereits laufenden Versand nicht durch Editieren als gestoppt oder umgelenkt betrachten.

Die bestehende Planprüfung für Kampagnen blockiert Vorschau-POSTs und Änderungen bei fehlender Berechtigung. Lesen bleibt möglich. Audit `segment.update` enthält Segment-/Business-ID, keine Namen, Regeln oder Empfänger. Bestehendes Anlegen/Löschen bleibt unverändert. Die spätere Kampagnen-Vorbereitung ist jetzt atomar und liest die dann gültige Regel innerhalb ihrer Transaktion, siehe CAMPAIGN-DISPATCH.md. Die Vorschau zeigt nur eine dynamische Anzahl, keine Personen oder festgeschriebenen Empfänger. Ein veralteter Vorschau-Aufruf wird wie eine veraltete Speicherung abgewiesen. Vorschau nach neuer Aktivität kann andere Zahlen zeigen. Kein vollständiger Segment-Builder, keine Versionierung der Empfänger oder neue API-/MCP-Funktion.

## Tests

`python3 tests/run-segments.py`: Wegwerf-Datenbank, alle sechs Regeln, Feldvalidierung, CSRF, Eigentümer-/Mitglieder-/Gastgrenzen, fixiertes Ziel, Konflikt, dynamische Anzahl, kein Versand, unveränderte gesendete Kampagne/Protokoll, Audit, Planrechte. Chromium Desktop/Mobil mit echten Zwei-Tab-Konflikten und visueller Prüfung. Screenshots unter `/tmp/loyalty-segments/`. Kein Produktions-Last- oder paralleler Versandtest.

Vorschau-Tests ergänzen alle sechs Regeln, getrennte Anzahlen, unveränderte Segment-/Kampagnen-/Versand-/Auditdaten, erhaltene Formularwerte/Revision und Konfliktschutz nach Vorschau.

Die bestehenden Löschaktionen sind jetzt separat gehärtet: Bestätigung, Konfliktschutz, keine verknüpften Segmente oder vorbereiteten Kampagnen löschen. [MARKETING-DELETE.md](MARKETING-DELETE.md). Unternehmens-/Mitgliederlöschung bleibt davon getrennt.
