# Eigenes Betreiber-Passwort ändern

Angemeldete Betreiber finden in der Navigation "Passwort" (`/account/password`). Das Formular braucht das bisherige Passwort, ein neues Passwort und dessen Wiederholung. Das gilt ausschließlich für das angemeldete Betreiberkonto, nicht für eine aus einem Formular übergebene Benutzer-ID oder E-Mail-Adresse.

## Wirkung

- Neues Passwort: 12 bis 72 Byte, Umlaute und andere Mehrbyte-Zeichen zählen mehrfach. Keine NUL-Bytes. Die Grenze verhindert stilles Abschneiden beim verwendeten bcrypt-Hash. Das bisherige Passwort und die Sitzungs-Version werden innerhalb einer `BEGIN IMMEDIATE`-Transaktion erneut geprüft.
- Nach Erfolg erhöht sich die Sitzungs-Version. Alle Betreiber-Websitzungen, auch die aktuelle, werden abgemeldet. Der Widerruf greift beim nächsten Serveraufruf. Bereits ausgelieferte Seiten lassen sich nicht zurückrufen.
- Noch offene Resetlinks dieses Betreiberkontos werden entwertet. Resetlinks von Mitgliedern bleiben unberührt.
- Audit `account.password_change` mit Benutzerbezug, aber ohne Passwort, Hash oder Formulardaten. Fehlversuche geben keine Passwörter aus. Zehn POST-Versuche je Konto und 15 Minuten, auch bei Eingabefehlern.
- Kontosicherheit bleibt bei gesperrtem Betreiber-Abo verfügbar. Kein SMTP nötig.

## Was unverändert bleibt

API-, Shop- und Agent-Schlüssel sowie offene Agent-Freigaben werden nicht widerrufen. Wer einen Schlüsselverlust vermutet, muss Schlüssel und Freigaben separat prüfen/widerrufen. Die Passwortänderung ist kein vollständiger Konten-Lockdown. Das Formular weist darauf hin.

Mitglieder- und Personalpasswörter/PINs, fremde Betreiberkonten, Programme, Guthaben, E-Mail-Adresse und Abonnement bleiben unverändert. Kein neuer Name-/E-Mail-Editor, keine Benachrichtigungsmail, kein MFA/OTP oder neues Geräteverzeichnis. Ohne bekanntes bisheriges Passwort bleibt der vorhandene SMTP-Reset.

## Tests

`python3 tests/run-owner-password.py`: Wegwerfkonten, Login/CSRF, falsches/kurzes/langes/Mehrbyte-/NUL-Passwort, Wiederholung, identisches Passwort, eigene Sitzungen widerrufen, fremde Sitzung unverändert, fremde Zielparameter ignoriert, Mitglieder/Personal/Integrationen unverändert, nur eigene Owner-Resetlinks entwertet, Login alt/neu, Audit, inaktives Abo und Rate-Limit. Chromium Desktop/Mobil mit Pixelprüfung. Keine echten Konten oder Geräte.
