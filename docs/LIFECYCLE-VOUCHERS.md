# Lifecycle-Gutscheine (Geburtstag, Winback, Willkommen)

Die E-Mail-Automationen (Seite „E-Mail & Automationen“ eines Unternehmens) können einen persönlichen Gutschein beilegen.

## Einrichten

1. Ein Gutschein-Programm anlegen und seine Regeln einstellen (Typ, Wert, Mindestumsatz, Limits, Ende).
2. Unter „E-Mail & Automationen“ eine Automation anlegen, Ereignis wählen (Geburtstag, Inaktiv seit X Tagen, Willkommen), bei „Persönlichen Gutschein beilegen“ das Programm wählen und die Gültigkeit in Tagen angeben (1 bis 365).
3. Im Text {name}, {code} und {valid_until} verwenden. Fehlt {code}, hängt das System den Code an.

## Verhalten

- Pro Mail entsteht ein eigener Einmal-Code, nur für dieses Mitglied einlösbar. Die Einlösung läuft wie bei jedem Gutschein an der Kasse (Regeln und Limits des Programms gelten, `per_member` begrenzt die Einlösungen je Mitglied).
- Gültig bis: heute plus Gültigkeitstage, höchstens bis zum Ende des Gutschein-Programms. Ist das Programmende vorbei oder das Programm inaktiv, wird nichts ausgegeben.
- Code und Mail entstehen in einer Transaktion und je Zyklus nur einmal (Geburtstag jährlich, Winback je letzter Aktivität, Willkommen einmal). Ein zweiter Lauf erzeugt weder Mail noch Code.
- Nur mit Marketing-Einwilligung des Mitglieds und aktivem Plan, wie bei jeder Automation. Das Gutschein-Programm muss zum selben Unternehmen gehören.

## Grenzen

- Der Code geht nur per E-Mail raus. Ohne eingerichteten SMTP-Versand und laufenden Worker (`tools/mail-worker.php`) gibt es keine Mails und keine Codes. Keine Anzeige in der Wallet-Inbox.
- Der Gutschein steht nicht im Mailtext nach dem Versand (Mailkörper werden nach dem Versand geleert). Der Code bleibt im Gutschein-Programm unter den Codes sichtbar.
- Keine echte Providerzustellung geprüft (siehe FEATURE-AUDIT, Kampagnen).
- Kein Widerruf bereits ausgegebener Codes pro Automation, nur über Batch-/Codeverwaltung des Programms.
- Keine eigene Auswertung (Einlösequote) für Automationen.

## Test

`python tests/run-lifecycle.py`: 17 Domänenprüfungen (Geburtstag, Zuordnung, Platzhalter, Gültigkeit, Idempotenz, Einlösung, fremdes Mitglied, Einwilligung, fremdes und inaktives Programm, abgelaufenes Ende, Winback) und Browser-Läufe Desktop/Mobil mit Screenshots.
