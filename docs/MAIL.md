# E-Mail, Automationen und Passwortwiederherstellung

## Funktionsstand

- TLS-SMTP über cURL: Port 587 mit STARTTLS oder 465 mit SMTPS, Zertifikats-/Hostprüfung aktiv, keine unverschlüsselte Alternative. SMTP-Ziele müssen auf öffentliche IPs zeigen; Verbindung wird auf eine geprüfte IP gepinnt. Keine internen SMTP-Hosts in dieser Version.
- Outbox mit getrennten Zuständen `queued`, `sending`, `sent`, `skipped`, `unknown`. `sent` bedeutet Annahme durch den SMTP-Server, kein Nachweis für Posteingangszustellung oder Lesen.
- Kampagnen bleiben in der Wallet-Inbox sichtbar und legen bei vorhandener SMTP-Konfiguration zusätzlich E-Mails für eingewilligte Mitglieder in die Outbox.
- Drei einfache Regeln pro Unternehmen: Willkommen (einmal, Beitritt in den letzten 7 Tagen), Geburtstag (einmal pro UTC-Kalenderjahr), Winback (einmal je letzter bekannter Aktivität nach X Tagen). Platzhalter `{name}`.
- Mitglied kann Marketing separat je Unternehmen aktivieren/abschalten und freiwillig einen Geburtstag speichern. Bestehende Mitglieder sind standardmäßig **nicht** eingewilligt. Einwilligungszeit und Formularversion/Quelle werden gespeichert. Keine nachträgliche Annahme aus einer E-Mail-Adresse.
- Abmeldelink je Mitglied mit HMAC-Token. GET zeigt Bestätigung, erst POST meldet ab. Noch wartende Marketingnachrichten werden verworfen. Kontosicherheitsnachrichten sind getrennt.
- Betreiber-/Mitglieder-Passwortwiederherstellung: generische Antwort ohne Kontobestätigung, zufälliger Einmaltoken, gespeichert nur als SHA-256-Hash, 30 Minuten Gültigkeit, neues Passwort 12–200 Zeichen, ungültige/benutzte Links werden abgelehnt. Bestehende Sessions werden durch `session_version` widerrufen. Staff-PINs bleiben Betreiberaufgabe.

## Einrichtung

1. Eigener SMTP-Provider mit verifizierter Absenderdomain, SPF/DKIM/DMARC und einem freigegebenen Testempfänger. Zugangsdaten serverseitig als Umgebungsvariablen:

   ```text
   SMTP_HOST=smtp.dein-provider.example
   SMTP_USER=...
   SMTP_PASSWORD=...
   SMTP_FROM=loyalty@deine-domain.example
   ```

2. In `config.php` den aktuellen `smtp`-Block übernehmen, `enabled=true`, Port 587 oder 465, eigenen `from_name` setzen. Fehlende Konfiguration erzeugt keine E-Mails und markiert nichts als zugestellt. Keine Secrets in Git, Formulare oder Screenshots übernehmen.
3. Versand zuerst in einer getrennten Testinstallation mit Testempfängern prüfen. Die lokalen Tests haben **keine** echte SMTP-Verbindung oder Nachricht an einen externen Empfänger ausgelöst.
4. Worker manuell ausführen:

   ```bash
   php tools/mail-worker.php 25
   ```

   Er legt fällige Automationen in die Queue und verarbeitet höchstens 25 Nachrichten. Wiederkehrender Cron z.B. jede Minute kann vom Betreiber eingerichtet werden. Hier wurde kein produktiver Cron und keine automatische Kommunikation aktiviert.
5. `/business/ID/mail` zeigt Regeln und Versandstatus. Eine ausgefüllte Konfiguration ist keine bestätigte Providerverbindung. Versandfälle und Providerlogs separat prüfen.

## Zustellung und Wiederholungen

SMTP besitzt keine allgemeine Exactly-once-Garantie. Ein Verbindungsabbruch nach Annahme kann bedeuten, dass eine Nachricht trotz fehlender Bestätigung versandt wurde. Deshalb wird ein Fehler oder abgebrochener Worker als `unknown` behandelt, nicht automatisch erneut versendet. Im Providerlog prüfen, bevor ein Betreiber einen neuen Versand anstößt. Ein Wiederholungs-UI ist noch nicht enthalten.

Nachrichten haben stabile Message-IDs und lokale Deduplizierung. Regeln/Campaigns erzeugen beim nächsten Worker-Lauf nicht blind dieselbe Nachricht erneut. Marketing-Einwilligung und Planberechtigung werden beim Queueing **und** unmittelbar vor der Übermittlung nochmals geprüft. Transaktionale Resetnachrichten hängen nicht am bezahlten Abo.

Die Nachrichtentexte werden nach `sent`/`skipped` entfernt. Alte abgeschlossene Marketingprotokolle werden nach 90 Tagen beim Worker-Lauf entfernt. Transaktionale Outboxeinträge werden nach einem Tag bereinigt; abgelaufene Passwort-Resetdatensätze ebenfalls. Queueing und Protokolle enthalten personenbezogene Daten und benötigen private DB-/Backupzugriffe. Der Worker ist für diese Bereinigung notwendig.

`data/mail-signing.key` ist ein privater, automatisch erzeugter lokaler Signaturschlüssel. Er wird vom Backupwerkzeug gesichert. Verliert oder ersetzt man ihn, werden alte Abmeldelinks ungültig. Backups enthalten diesen Schlüssel und gegebenenfalls SMTP-Zugangsdaten.

## Grenzen

- Kein produktiv verifizierter SMTP-Provider, Bounce-/Complaint-Webhooks, Zustellnachweis oder unabhängiger E-Mail-/Datenschutz-Audit.
- Noch kein Double-Opt-In, keine bearbeitbaren HTML-Templates, keine Provider-spezifischen API-Adapter oder Resend-/Retry-Oberfläche.
- Regeln senden Textnachrichten, erzeugen noch keine automatischen Geburtstags-/Winback-Gutscheine.
- Keine vollständige Original-Parität bei Lifecycle-/Punkterezepten, Aktivierungsnudges, Tierwechseln, Pass-Receipts oder Reminder-/Expiry-Jobs.
- Passwortreset wird in die Queue gelegt. Er braucht einen zeitnah laufenden Worker; läuft er erst nach 30 Minuten, ist der Link abgelaufen und ein neuer muss angefordert werden. Kein Reset wird ohne konfigurierte SMTP-Zustellung als gesendet behauptet.
- Die Auskunft-/Lösch- und Einwilligungsregeln müssen vor Produktivstart rechtlich geprüft werden. Einwilligungsformular und Abmeldelink allein sind keine DSGVO-Konformitätszusage.

## Tests

```bash
python3 tests/run-mail.py
```

17 isolierte Outbox-/Einwilligungs-/Automationstests, 4 Browserprüfungen und HTTP-Prüfungen für Einmaltoken, neues Passwort, Sessionwiderruf und bestätigte Abmeldung. Der Transportadapter fängt alle Nachrichten im Testprozess ab; echte TLS-SMTP-Zustellung bleibt als externer Abnahmeschritt offen.

Quellen für den Referenzumfang:
- https://rewardloyalty.co/docs/5.x/automated-messages
- https://rewardloyalty.co/docs/5.x/email-campaigns
