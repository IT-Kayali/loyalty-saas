# Programm-Ausbau: Designer, Gutscheine und Prepaid

## Designer pro Programm

Unter `/program/ID/design` gibt es jetzt ein eigenes Design. Ohne eigenes Design erbt ein Programm das Unternehmensdesign. Die Webkarte nutzt das jeweilige Programmdesign, einschließlich Farben, Hintergrundfoto mit Farbüberlagerung (0–100 %), Logo-/Stempel-Bild, Texten und einer kleinen kuratierten Emoji-Auswahl. Das Speichern ändert keine gesammelten Punkte/Stempel.

Die Kunden-Wallet bleibt ein Sammelpass pro Mitglied/Unternehmen. Dessen Farben/Logo/Stempelgrafik kommen vom ersten aktiven Programm, bevorzugt Stempel. Es ist **noch nicht** je Programm ein separat installierbarer nativer Wallet-Pass. Hintergrundfotos/Emoji werden derzeit nur in der Webkarte und Vorschau gezeigt, Wallets verwenden weiterhin Farben, Logo und Raster-Stempelbild. QR-Studio bleibt beim Unternehmensdesign. Designänderungen stoßen noch keinen eigenen Wallet-Push an.

Uploads haben dieselbe strikte Prüfung und PNG-Neukodierung wie die Unternehmensbilder. PNG/JPEG/WebP bis 2 MB, keine SVGs oder GIFs. Alte, ersetzte Bilddateien werden nicht automatisch bereinigt, damit bestehende Pass-URLs nicht brechen; Speicherbereinigung ist ein Betriebspunkt.

## Code-Gutscheine

Zusätzlich zu den bisherigen einfachen Gutscheinkarten:

- Vier Rabattarten: Prozent, Festbetrag, Gratisprodukt, Bonuspunkte.
- Prozentberechnung mit Integer-Cents und optionalem Maximalrabatt.
- Mindestkaufbetrag, UTC-Gültigkeitsdaten, maximales Kampagnenkontingent, Einlösungen pro Mitglied und Mindest-Lifetime-Punkte.
- Batch-Erzeugung bis 500 zufällige Codes pro Vorgang, CSV-Export bis 10.000 Codes.
- Regeln werden beim Erzeugen im Code gespeichert. Spätere Regeländerungen gelten für neue Codes, nicht still für bereits verteilte.
- Owner-/Staff-Kassenformular mit Prüfseite und ausdrücklicher Bestätigung. Endgültige Berechtigung/Restnutzung wird innerhalb der DB-Transaktion erneut geprüft.
- Einlösungen sind durch Vorgangskennung wiederholbar, ohne doppelt zu buchen.
- Gratisprodukt bestätigt die Ausgabe eines benannten Artikels, Bonuspunkte brauchen eine bereits bestehende aktive Punktekarte.
- Keine Zahlung oder automatische POS-Rabattierung. Externen Kaufbetrag und Gutscheinwährung an der Kasse prüfen.

Ergänzt: persönliche Gutscheine aus Lifecycle-Automationen (LIFECYCLE-VOUCHERS.md), QR in der Wallet mit Team-Scanner (VOUCHER-QR.md), Batchstorno und eine Code-Gutschein-Auswertung je Programm (VOUCHER-REPORT.md). Noch offen: öffentliche Claimseite, vollständiger gezielter Versand, Rücknahme einer Einlösung, verschachtelte Eligibility und vollständige Unternehmens-/Conversionberichte. Bestehende einfache Gutscheinkarten verwenden weiterhin ihre eigenen Regeln, sie sind nicht nachträglich an die Code-Regeln gebunden.

## Prepaid-Besuchspässe

Zusätzlich zur bisherigen einfachen Mehrfachkarte gibt es getrennte **verkaufte Pässe**:

- Produktart „Besuchspaket“ oder „Unbegrenzt mit Laufzeit“.
- Besuchsanzahl, Gültigkeit ab Verkauf, Standardpreis und Währung konfigurierbar.
- Verkauf an ein bekanntes Mitglied, tatsächlicher externer Zahlbetrag, eindeutige Belegreferenz und Prüfschritt vor dem endgültigen Dokumentieren.
- Mehrere Verkäufe desselben Produkts an dasselbe Mitglied ergeben getrennte Pässe.
- Jeder Pass speichert seine zum Verkauf geltenden Bedingungen. Produktänderungen wirken nur auf zukünftige Verkäufe. Ändert sich das Produkt zwischen Prüfschritt und Bestätigung, wird neu geprüft statt still andere Bedingungen zu verkaufen.
- Besuche verbrauchen eine definierte Menge, unbegrenzte Pässe protokollieren einzelne Besuche bis zum Ablauf.
- Versionsprüfung schützt vor einem zwischenzeitlich geänderten Stand. Wiederholte Vorgänge mit derselben Kennung buchen nicht doppelt.
- Owner kann Besuche stornieren; Staff nur eigene. Ein Besuch kann einmal storniert werden.
- Append-only-Ledger für Verkauf/Besuch/Storno und CSV-Export. Falsche Buchungen werden durch Gegenbuchung berichtigt, nicht gelöscht.
- Kundenkarte zeigt gekaufte Pässe und Verlauf. Kein externer Zahlungsverkehr in der App.

**Keine monetären Guthabenpässe in dieser Etappe.** Dafür braucht es getrennte Salden-, Refund-, Reconciliation-, Integritäts- und Berechtigungspfade sowie geklärte Geschäftsbedingungen. Ein einfaches Feld „Guthaben“ wäre dafür kein sicherer Ersatz. Ebenfalls offen: Verkauf per kurzlebigem Kauf-QR ohne Mitgliedersuche, Reminder/Expiry-Job, private Notizen/Fotos, alle API-/MCP-Passoperationen und ausführliche Umsatz-/Breakage-Analytics.

Bestehende einfache Mehrfachkarten bleiben separat bestehen und werden nicht still als bezahlte Verkäufe interpretiert. Keine Demo-Bestandskarte wird rückwirkend als Kauf gebucht.

## Datenschutz und Buchhaltungsgrenzen

Bei der Löschung eines Mitglieds werden die Identitätsverknüpfungen verkaufter Pässe auf NULL gesetzt. Das finanzielle Ledger bleibt erhalten. Externe Belegreferenzen können selbst personenbezogen sein: keine Namen/E-Mails als Belegreferenz verwenden. Aufbewahrung, Auskunft, Löschung gesetzlich nicht mehr benötigter Daten und externe Buchhaltung müssen vor Produktivstart geregelt werden. Es werden keine Steuerbelege erstellt und keine Rechtskonformität zugesagt.

## Tests

`python3 tests/run-programs.py` erzeugt eine Wegwerf-Installation und prüft 23 isolierte Regeln/Transaktionen sowie 12 echte Browserfälle: Geldparser, Prozentcap, Kontingente, wiederholte Einlösung, Ablauf, mehrfache Verkäufe, unveränderliche Verkaufsbedingungen, doppelte Besuchsbuchung, Konfliktversionen, Storno, Staff-Grenzen, Ledger-Schutz, Datenschutzverknüpfung und mobile Oberfläche. Bestehende Browser-, Policy-, Stripe- und Backup-Tests bleiben zusätzlich erforderlich.

## Quellen

- https://rewardloyalty.co/docs/5.x/stamp-cards/designing-cards
- https://rewardloyalty.co/docs/5.x/vouchers/partner-discount-types
- https://rewardloyalty.co/docs/5.x/prepaid-passes/overview
- https://rewardloyalty.co/docs/5.x/prepaid-passes/staff-selling-and-scanning

## Gutschein-Batches stornieren und wiederherstellen

Auf der Programmseite zeigt die Batch-Tabelle Codes, bisherige Einlösungen, offene und stornierte Codes. Auf kleinen Bildschirmen erscheinen die Batches als beschriftete Zeilenkarten, ohne seitliches Scrollen.

- Nur der Eigentümer kann einen Batch stornieren oder wiederherstellen. Beide Aktionen brauchen CSRF und eine Browser-Bestätigung.
- Storno sperrt alle noch offenen Codes dieses Batchnamens im gewählten Programm. Vollständig verbrauchte Codes bleiben unverändert. Bei teilweise genutzten Mehrfachcodes werden weitere Einlösungen gesperrt; bisherige Nutzungen bleiben erhalten.
- Wiederherstellen setzt nur stornierte Codes wieder auf aktiv. Nutzungszähler, Ablaufdatum, persönliche Zuordnung und gespeicherte Rabattregeln bleiben erhalten. Abgelaufene Codes werden dadurch nicht wieder gültig.
- An der Kasse werden stornierte Codes bei Vorschau und Einlösen abgelehnt. Persönliche stornierte Codes verschwinden aus der Wallet. CSV enthält den Status `cancelled`.
- Audit-Ereignisse `voucher.batch_cancel` und `voucher.batch_restore` enthalten Programm, Batchname und Anzahl geänderter Codes. Eine leere oder wiederholte Aktion erzeugt keinen neuen Audit-Eintrag.
- Neue Batchnamen müssen innerhalb des Programms exakt eindeutig sein. Historisch mehrfach verwendete Namen werden gemeinsam behandelt. Keine nachträgliche Umbenennung.

Grenzen: Die Übersicht zeigt die letzten 20 Batchnamen, ohne Suche/Seitenwechsel. Offene Codes können abgelaufen sein; die Anzeige ist kein Gültigkeitsversprechen. Es gibt keine Einzelcode-Stornierung, Benachrichtigung betroffener Mitglieder, Rückerstattung oder Rücknahme vergangener Einlösungen und keinen eigenen Storno-Webhook. Batchaktionen und Einlösen verwenden `BEGIN IMMEDIATE`; ein eigener Paralleltest für Storno gegen Einlösen fehlt. Kein Test mit echten Geräten/POS-Systemen.

Tests: `python3 tests/run-voucher-batches.py`, Wegwerf-Datenbank, HTTP/Rechte/Audit/CSV, teilweise verwendete Codes, historische Namensduplikate und Ablauf nach Wiederherstellung sowie Chromium Desktop/Mobil einschließlich Bestätigung abbrechen.
