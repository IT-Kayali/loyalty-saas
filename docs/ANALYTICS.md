# Analytics: Einlösungszahlen und mobile Programmübersicht

`/business/ID/analytics` ist eine reine Leseansicht für den Eigentümer des Unternehmens. Auch ohne aktiven Plan bleiben gespeicherte Daten lesbar. Die Antwort trägt `Cache-Control: private, no-store`.

## Korrektur der Einlösungszahlen

Die allgemeine Kennzahl "Einlösungen" und die Einlösungs-Spalte der Programm-Performance zählen jetzt auch Transaktionen vom Typ `voucher_code_use`. Zuvor fehlten diese Code-Gutscheine dort, obwohl sie im Transaktionsjournal vorhanden waren.

Gezählt werden Ereignisse der Typen `reward_redeem`, `points_redeem`, `voucher_use` und `voucher_code_use`, deren `reversed_at` leer ist. Ein Ereignis zählt als eine Einlösung, unabhängig von Punktezahl, Rabatt oder externem Kaufbetrag. Prämiencodes schreiben bereits `points_redeem` und werden deshalb nicht noch einmal addiert. Eine idempotente Wiederholung erzeugt keine neue Transaktion und zählt nicht doppelt.

Eine Batchsperre entfernt vergangene Einlösungen nicht. Ein `reversal`-Ereignis ist selbst keine Einlösung. Bestehende Kartenbuchungen werden nicht geändert, neu angelegt oder zurückgenommen. Der Bericht liest die vorhandenen Ereignisse neu, deshalb gelten die korrigierten Zahlen auch für historische Code-Einlösungen.

## Darstellung und Grenzen

Die Programm-Performance zeigt auf kleinen Bildschirmen beschriftete Zeilenkarten. Programmname, Typ, Karten, Aktionen und Einlösungen bleiben sichtbar, ohne Spalten auszublenden oder seitlich zu scrollen. Die Beschriftungen verwenden die bestehenden Übersetzungen.

Einlösungszahl und Programm-Performance betrachten alle noch gespeicherten Daten. Das vorhandene Aktivitätsdiagramm betrachtet nur die letzten 30 UTC-Tage. Die Ansicht nennt den Unterschied ausdrücklich. Die Aktiv-Mitglieder-Kennzahl hat weiterhin ihr eigenes 30-Tage-Fenster.

Keine Geldsumme, Währungsumrechnung, Umsatz-, Gewinn- oder Steuerrechnung. Prepaid-Verkäufe und -Besuche sind keine der oben genannten Einlösungen. Bestehende Karten-/Aktions-/Punktekennzahlen wurden nicht neu definiert. Das ist kein vollständiger Analysebaukasten, kein zeitlich filterbares Gesamtdashboard und kein Lasttest. Datenlöschungen können historische Zahlen ändern. Der allgemeine Bericht liest das Transaktionsjournal; die eigene Code-Gutschein-Auswertung liest `voucher_redemptions`, siehe [VOUCHER-REPORT.md](VOUCHER-REPORT.md). Manuelle Datenbankänderungen können beide Quellen auseinanderlaufen lassen.

## Tests

`python3 tests/run-analytics.py`: lokale Wegwerf-Installation, echte Code-Einlösung und wiederholte Vorgangskennung, Gesamt-/Programmzahl, Batchsperre, Stornoflag, bisherige Einlösungsarten, kein Reversal-Doppelzählen, Unternehmens- und Login-Grenzen, historische Daten und lesbare Plan-Sperre. Chromium prüft Zahlen, Cache-Header, lange Programmnamen und Desktop-/Mobil-Layout. Pixelprüfung der Screenshots unter `/tmp/loyalty-analytics/`. Keine echten Kunden-/Kassenkonten verwendet.
