# Eigenes Wallet-Passwort ändern

In der Wallet führt "Passwort ändern" zu `/member/password`. Das gilt nur für das aktuell angemeldete Mitgliederkonto. Es gibt kein Feld zur Auswahl eines anderen Mitglieds. Betreiber- und Personalzugänge werden nicht geändert.

## Ablauf

1. Bisheriges Passwort eingeben.
2. Neues Passwort und Wiederholung eingeben. Mindestens 12, maximal 72 Byte. Umlaute und andere Mehrbyte-Zeichen zählen mehrfach. Die Obergrenze verhindert stilles Abschneiden beim verwendeten bcrypt-Hash. HTML kann nur die Zeichenanzahl prüfen, die Byteprüfung bleibt auf dem Server.
3. Bestätigen. Alle Wallet-Sitzungen, auch die aktuelle, werden abgemeldet. Danach mit dem neuen Passwort anmelden.

Die Wallet bündelt wie bisher Mitgliedszeilen über Unternehmen hinweg anhand derselben E-Mail-Adresse (Groß-/Kleinschreibung wird ignoriert). Die Änderung vereinheitlicht das Passwort dieser Zeilen. Das ist dieselbe Kontogrenze wie Login und Passwortreset, kein neues Identitätsmodell. Name, E-Mail-Adresse, Kartenstände und Betreiberpasswort bleiben unverändert.

## Schutz

- Anmeldung, CSRF und bisheriges Passwort erforderlich. Nach zehn POST-Versuchen je Wallet-Konto in 15 Minuten wird die Änderung vorübergehend begrenzt, auch bei Eingabefehlern. Das Limit gilt nicht nur je Browser.
- Hashes und aktuelle Sitzungs-Version werden innerhalb einer `BEGIN IMMEDIATE`-Transaktion erneut geprüft. Alle betroffenen Zeilen erhalten denselben neuen Hash und das bisherige Versionsmaximum plus 1. Damit können alte Sitzungen und alte alternative Passwörter derselben Wallet nicht weiterverwendet werden.
- Noch offene Passwortreset-Links für dieses Mitgliederkonto werden entwertet. Betreiber-Resetlinks bleiben unberührt.
- Kontosicherheit ist keine bezahlte Betreiberfunktion: die Änderung funktioniert auch bei abgelaufenem Betreiber-Abo. Nur diese Passwort-/Sitzungsfelder werden dabei geändert.
- Antwort nicht cachen, Eingaben nicht wieder ausgeben oder protokollieren. Kein SMTP und keine Mail nötig.

## Grenzen

Kein allgemeiner Profileditor, E-Mail-Wechsel, OTP oder MFA. Kein Ändern des Betreiberpassworts in dieser Ansicht, dafür bleibt der vorhandene Passwortreset. Keine Benachrichtigungsmail und kein neues Mitglieder-Sicherheitsprotokoll. Wer kein bisheriges Passwort kennt, nutzt den vorhandenen Reset, der SMTP benötigt.

Der Sitzungswiderruf wird bei der nächsten Serveranfrage geprüft. Schon ausgelieferte Seiten und ausdrücklich gespeicherte Offline-Karten auf anderen Geräten können nicht aus der Ferne gelöscht werden. Letztere bleiben bis zu ihrem Ablauf (höchstens 24 Stunden) sichtbar, ohne Offlinebuchungen. Öffentliche Kartenlinks werden durch die Passwortänderung nicht gedreht.

## Tests

`python3 tests/run-member-password.py` startet eine Wegwerf-Installation. Prüft Shared-Wallet-Zeilen mit unterschiedlichen Hashes/Versionen, falsches/kurzes/zu langes/Mehrbyte-Passwort, Wiederholung, CSRF, Auth, fremde Zielparameter ohne Wirkung, Reset-Entwertung, alle eigenen Sitzungen ungültig, andere Mitglieder unverändert, neues Login, Rate-Limit und inaktives Betreiber-Abo. Chromium Desktop/Mobil mit Pixelprüfung. Keine realen Konten oder echten Geräte.
