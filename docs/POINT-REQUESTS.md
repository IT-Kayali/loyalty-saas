# Mitglieder-Punkteanfragen

Referenz: https://rewardloyalty.co/docs/5.x/loyalty-cards/member-point-requests. Ein Mitglied erzeugt einen Anfrage-Link. Ein anderes Mitglied desselben Punkteprogramms sendet darüber Punkte von seinem Guthaben.

## Ablauf

1. Betreiber: im Punkteprogramm den Bereich „Punkteanfragen zwischen Mitgliedern“ aktivieren. **Standard ist aus**, weil Übertragungen missbraucht werden können (Weitergabe von Guthaben).
2. Mitglied A: Wallet, „Punkte anfordern“, Karte wählen, Link erstellen und weitergeben. Höchstens 5 Links je Konto (`TRANSFER_MAX_LINKS`), Links haben keinen Ablauf, Löschen gibt Platz frei.
3. Mitglied B öffnet den Link, meldet sich im Wallet an, gibt die Anzahl an (1 bis Guthaben, höchstens 10000) und bestätigt, dass die Punkte abgezogen werden.
4. Guthaben von A steigt, das von B fällt um denselben Betrag. Beide sehen eine Buchung im Kartenverlauf, A bekommt eine Wallet-Nachricht.

## Regeln

- Nur innerhalb desselben Programms, keine Umrechnung zwischen Geschäften. B braucht eine Karte im Programm, A ebenfalls.
- Nicht an sich selbst. Eigener Link zeigt kein Formular.
- **Lifetime-Punkte ändern sich nicht**, also keine Stufen durch Übertragung. Das ist unsere Entscheidung, die Referenz sagt dazu nichts.
- Jede Übertragung ist atomar und je Formular genau einmal (`point_transfers.op` ist eindeutig). Doppelklick oder Wiederholung überträgt nicht doppelt.
- Nicht stornierbar (kein Undo im Kartenverlauf). Korrektur nur durch den Betreiber per Gutschrift und Abzug.
- Zählt als Aktivität für den Punkteverfall beider Karten.
- Wird das Programm deaktiviert oder der Opt-in ausgeschaltet, liefern alle Links 404.

## Grenzen

- Wer den Link kennt und angemeldet ist, kann senden. Kein Freigabeschritt für den Empfänger, Es gelten Tageslimits je Programm (siehe unten). Keine Limits pro Absender-Empfänger-Paar, keine Missbrauchserkennung über Konten hinweg.

## Tageslimits

Im Programm (Bereich Übertragungen) stellt der Owner drei Werte ein, jeweils in einem gleitenden 24-Stunden-Fenster je Karte: Punkte senden (Standard 500), Punkte empfangen (Standard 1000) und Anzahl Übertragungen (Standard 5). 0 heißt unbegrenzt, erlaubt sind 0 bis 1000000 (Anzahl 0 bis 1000). Die Prüfung läuft in der Transaktion von `transfer_points()` (`app/inc/transfers.php`), Fehlermeldungen sind deutsch. Das Sendeformular zeigt den Rest und begrenzt das Betragsfeld. Der Owner sieht außerdem die letzten 20 Übertragungen.
- Der Link-Aufruf ohne Anmeldung leitet zum Login, danach muss der Link erneut geöffnet werden.
- Keine Anfrage ohne gewählte Karte („für alle Karten“) wie in der Referenz.
- Keine Live-Aktualisierung der Wallet.
- Nicht in API/MCP.

## Test

`python tests/run-transfers.py`: 45 Prüfungen (Tageslimits, Opt-in, Rechte, Linklimit, Beträge, Bestätigung, Replay, Lifetime, Verlauf, Nachricht, Undo gesperrt, Löschen, 404) und Browser Desktop und Mobil mit Screenshots.
