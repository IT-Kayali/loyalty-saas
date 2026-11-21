# Gutschein-Batch drucken und QR exportieren

Im Gutscheinprogramm führt **Druck / QR** neben einem Batch zur privaten Druckansicht. Nur der Programmeigentümer erhält Zugriff, keine Team-/Mitglieder-/öffentliche Ansicht.

## Auswahl und Ablauf

- Exakter Batchname, höchstens 500 vorhandene Codes. Bei mehr Codes wird der gesamte Druck abgelehnt, kein stiller Teilausschnitt.
- Nur aktive, ungebundene, unbenutzte Codes mit 24-stelligem Hexformat, lesbarem Regelsnapshot und aktuellem UTC-Datumsfenster; das Programm muss aktiv sein. Persönliche, schon genutzte, stornierte, zukünftige, abgelaufene und fehlerhafte Datensätze bleiben ausgeschlossen.
- Die Seite zeigt die ausgewählte und gesamte Anzahl. Kein Erzeugen, Buchen, Reservieren, Versenden oder Ändern. Andere Kampagnen-/Mitglieder-/Punktelimits prüft erst die Kasse.
- QR wird lokal erzeugt, ohne externen QR-Dienst. Inhalt ist `V-` plus Code, passend zum bestehenden Team-Scanner. Dieser übernimmt nur den Code, Einlösung braucht weiterhin Mitglied, Kaufbetrag und ausdrückliche Bestätigung.
- **Drucken / als PDF speichern** verwendet den Browserdruck: A4, Hochformat, 100 %, Browser-Kopf-/Fußzeilen ausschalten. Vier Karten pro Seitengruppe, mit Wert, Klartextcode und Kurzbedingungen. Programmname auf höchstens 80 Zeichen gekürzt. Lange Inhalte können browserabhängig mehr Platz brauchen, Vorschau vor Verteilung prüfen.
- **QR als PNG laden** lädt eine einzelne 320×320-Grafik mit weißem Rand. Sie enthält nur den QR, keine Bedingungen und keinen Namen. Kein ZIP und kein serverseitiger PDF-Renderer.

## Schutz und Grenzen

Antworten sind `private, no-store`, ohne Referrer und mit lokaler Content-Security-Policy. Der Batchname steht dennoch in der Browseradresse. Codes/Dateien privat aufbewahren: Sie sind weitergebbar, kein Identitäts- oder Guthabennachweis. Keine Fremdschriften oder Analyse-/QR-Aufrufe in der Druckseite. Bei fehlender QR-Bibliothek bleiben Druckknopf und PNGs gesperrt. Manuell ausgelöster Browserdruck kann dadurch nicht technisch verhindert werden.

Die Auswahl ist eine Momentaufnahme, keine Einlösbarkeitszusage. Spätere Sperren gelten weiter. Die Kurzbedingungen ersetzen keine vollständigen Kampagnenbedingungen. Keine echte Drucker-, Kamera-, iOS-/Android- oder Scanner-Abnahme; geprüft sind Chromium, PDF-Rasterbilder und maschinelles QR-Dekodieren.

## Tests

`python3 tests/run-voucher-print.py`: 27 HTTP-/Domänenprüfungen und 15 Chromium-Prüfungen. Eigentümer/Mandant, Ausschlüsse, kaputte Snapshots, Lesezugriff ohne Schreibplan, unveränderte Datenbank, GET-only, private Header, 500/501-Grenze, Desktop/Mobil, lokale QR-Dekodierung, echte PNG-Dateien samt Dekodierung und Fehlermodus. Das Skript erzeugt ein A4-PDF und Screenshots unter `/tmp/loyalty-voucher-print/`. PDF-Seiten werden zusätzlich visuell geprüft. Ohne native Drucker-/Gerätetests.

Etappenregression: 38 weitere `run-*.py`-Suiten grün, einschließlich bestehendem Gutschein-Batch-Storno, Wallet-/Scanner-QR und Auswertung. WordPress-Echtumgebung nicht erneut geprüft. Batch-Aktionen umbrechen jetzt in einem eigenen Container, damit Druck/QR keine Aktionsspalte verdrängt.
