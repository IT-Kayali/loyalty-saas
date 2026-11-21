# Lifecycle-Gutscheine und Zustellkanäle (Geburtstag, Winback, Willkommen)

Die E-Mail-Automationen (Seite „E-Mail & Automationen“ eines Unternehmens) können einen persönlichen Gutschein beilegen.

## Einrichten

1. Ein Gutschein-Programm anlegen und seine Regeln einstellen (Typ, Wert, Mindestumsatz, Limits, Ende).
2. Unter „E-Mail & Automationen“ eine Automation anlegen, Ereignis wählen (Geburtstag, Inaktiv seit X Tagen, Willkommen), bei „Persönlichen Gutschein beilegen“ das Programm wählen und die Gültigkeit in Tagen angeben (1 bis 365).
3. Zustellung wählen: nur E-Mail, nur Wallet-Inbox oder beides. Die Wallet-Inbox braucht weder SMTP noch Worker.
4. Im Text {name}, {code} und {valid_until} verwenden. Fehlt {code}, hängt das System den Code an.

## Verhalten

- Pro Mail entsteht ein eigener Einmal-Code, nur für dieses Mitglied einlösbar. Die Einlösung läuft wie bei jedem Gutschein an der Kasse (Regeln und Limits des Programms gelten, `per_member` begrenzt die Einlösungen je Mitglied).
- Gültig bis: heute plus Gültigkeitstage, höchstens bis zum Ende des Gutschein-Programms. Ist das Programmende vorbei oder das Programm inaktiv, wird nichts ausgegeben.
- Zustellung je Regel, Mitglied und Zyklus nur einmal (Tabelle automation_runs, eindeutiger Schlüssel). Das gilt für alle Kanäle und gilt auch, wenn alte Mails aus dem Versandprotokoll gelöscht wurden.
- Wallet-Inbox: die Nachricht steht im Feed der Wallet. Zusätzlich zeigt die Wallet den Bereich „Deine Gutscheine“ mit allen persönlichen, noch nutzbaren Codes (Wert, gültig bis). Eingelöste, abgelaufene oder deaktivierte verschwinden.
- Kanal „beide“ ohne SMTP liefert nur in die Inbox. Kanal „E-Mail“ ohne SMTP liefert nichts, wie bisher.
- Code, Inbox-Nachricht und Mail entstehen in einer Transaktion und je Zyklus nur einmal (Geburtstag jährlich, Winback je letzter Aktivität, Willkommen einmal). Ein zweiter Lauf erzeugt weder Mail noch Code.
- Nur mit Marketing-Einwilligung des Mitglieds und aktivem Plan, wie bei jeder Automation. Das Gutschein-Programm muss zum selben Unternehmen gehören.

## Grenzen

- Ausgelöst wird nur durch einen Lauf von `tools/mail-worker.php` (Cron, zum Beispiel stündlich oder täglich). Ohne SMTP führt der Worker nur die Automationen aus und sendet keine Mails. Ohne Cron passiert nichts.
- Inbox-Nachrichten sind reiner Text, ohne Push und ohne Lesebestätigung. Die Wallet zeigt die letzten fünf Nachrichten.
- Auch Wallet-Nachrichten werden nur an Mitglieder mit Marketing-Einwilligung geschickt.
- Der Gutschein steht nicht im Mailtext nach dem Versand (Mailkörper werden nach dem Versand geleert). Der Code bleibt im Gutschein-Programm unter den Codes sichtbar.
- Keine echte Providerzustellung geprüft (siehe FEATURE-AUDIT, Kampagnen).
- Kein Widerruf bereits ausgegebener Codes pro Automation, nur über Batch-/Codeverwaltung des Programms.
- Keine eigene Auswertung (Einlösequote) für Automationen.

## Test

`python tests/run-lifecycle.py`: 17 Domänenprüfungen (Geburtstag, Zuordnung, Platzhalter, Gültigkeit, Idempotenz, Einlösung, fremdes Mitglied, Einwilligung, fremdes und inaktives Programm, abgelaufenes Ende, Winback) und Browser-Läufe Desktop/Mobil mit Screenshots.
