# Punkteverfall

Optional je Punkteprogramm (Seite des Programms, Bereich „Punkteverfall“). Einstellung: Tage ohne Buchung, 0 heißt aus (Standard), höchstens 3650.

## Regel

- Eine Karte verliert **alle** aktuellen Punkte, wenn ihre letzte Buchung länger als N Tage zurückliegt. Als Buchung zählt jede Transaktion außer dem Verfall selbst (Gutschrift, Einlösung, Storno). Ohne Buchung zählt das Ausstellungsdatum der Karte.
- Lifetime-Punkte und damit Stufen und Segmente bleiben unverändert.
- Der Verfall steht im Kartenverlauf als Buchung „Verfall nach N Tagen ohne Buchung“ mit negativem Betrag und ist nicht stornierbar. Eine Korrektur geht über eine normale Gutschrift.
- Mitglieder sehen auf der Karte, bis wann ihre Punkte gelten, wenn bis dahin nichts gebucht wird.

## Ausführung

Seitenaufrufe verändern nie Salden. Der Verfall läuft nur
- per Cron, zum Beispiel täglich: `php tools/expire-points.php`, oder
- manuell mit dem Button „Jetzt prüfen und verfallen lassen“ im Programm (mit Rückfrage, im Audit-Log).

Jede Karte wird in einer eigenen Transaktion neu geprüft. Ein zweiter Lauf ändert nichts.

## Grenzen

- Kein Verfall einzelner Punktepakete (FIFO) und keine feste Frist ab Gutschrift. Nur Inaktivität.
- Keine Erinnerungs-Mail vor dem Verfall, nur der Hinweis auf der Karte.
- Ohne eingerichteten Cron passiert nichts automatisch.
- Zeiten sind UTC der Datenbank, Anzeige nach Serverzeit.
- Nicht im MCP/API sichtbar.

## Test

`python tests/run-expiry.py` (Einstellung, Validierung, Login, Button, CLI, Lifetime, Idempotenz, Aktivität hält Punkte am Leben, Verlauf, Hinweis auf der Karte, Browser Desktop und Mobil).
