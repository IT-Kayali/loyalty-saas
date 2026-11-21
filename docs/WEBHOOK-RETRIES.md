# Webhook-Retries und Wiederholung

Ausgehende Webhooks (siehe [API.md](API.md)) werden bei Fehlern automatisch wiederholt und lassen sich manuell neu senden.

## Ablauf

1. Beim Ereignis wird die Zustellung gespeichert und sofort einmal gesendet (Versuch 1).
2. Antwort außerhalb von 2xx, Verbindungsfehler oder SSRF-Ablehnung: Status `failed`, `next_attempt_at` wird gesetzt.
3. Wartezeit nach Versuch 1 bis 5: 1 Minute, 5 Minuten, 30 Minuten, 2 Stunden, 12 Stunden.
4. Nach 6 Versuchen: Status `dead`. Kein automatischer Versuch mehr, nur noch manuell.
5. Erfolg (2xx): `delivered`, kein weiterer Termin.

Der gespeicherte Body (inkl. Zeitstempel des Ereignisses) wird unverändert wiederholt, die HMAC-Signatur wird neu berechnet. Zusätzliche Header: `X-Webhook-Delivery` (ID der Zustellung, gleich bei allen Versuchen) und `X-Webhook-Attempt` (Versuchsnummer). Empfänger sollten damit deduplizieren, es gilt at-least-once.

## Cron (nötig für automatische Wiederholung)

```
* * * * * php /pfad/zur/app/tools/webhook-retry.php
```

Ohne Cron passiert nichts automatisch. Der Admin kann dann im Admin-Dashboard "Fällige Wiederholungen jetzt ausführen" klicken. Die Demo hat vermutlich keinen Cron.

## Bedienung

- Owner: Business-Seite, Bereich Webhooks. Das Protokoll zeigt Versuche und nächsten Termin, bei nicht zugestellten Einträgen steht "Erneut senden" bereit. Nur eigene Webhooks, CSRF-geschützt.
- Platform-Admin: Admin-Dashboard, "Webhook-Zustellungen zur Prüfung" (20 neueste nicht zugestellte) mit "Wiederholen". Aktionen landen im Aktivitätsprotokoll (`webhook.retry`, `webhook.run_due`).
- Manuelle Versuche zählen mit. Schlägt der Versuch fehl, bleibt `dead` bzw. `failed` mit neuem Termin.
- Deaktivierte Webhooks: Wiederholung endet als `dead` mit Hinweis.

## Sperre gegen parallele Läufe

- Jede Zustellung wird vor dem Versuch atomar in der DB reserviert (`lock_until`, Lease 2 Minuten). Cron-Lauf, Admin-Button und manueller Klick können dieselbe Zustellung nicht gleichzeitig senden. Wer verliert, überspringt (Worker) oder bekommt den Hinweis "wird gerade gesendet".
- Stürzt ein Lauf mitten im Versand ab, läuft die Sperre nach 2 Minuten ab.
- `tools/webhook-retry.php` hat zusätzlich eine Dateisperre (`data/webhook-retry.lock`), ein zweiter Worker bricht mit Exit 1 ab.

## Hinweis bei dead

- Im Admin-Dashboard erscheint oben ein roter Hinweis mit der Anzahl endgültig fehlgeschlagener Zustellungen, "Als gesehen markieren" blendet ihn aus (Aktivitätsprotokoll `webhook.ack`). Eine neue dead-Zustellung zeigt ihn wieder.
- Ist SMTP aktiv und `support_email` gesetzt, geht außerdem eine Mail an diese Adresse (eine je Zustellung, ohne URL, Payload und Secret, nur Nummer, Event, Unternehmen und Link zum Admin). Sie läuft über die normale Mail-Queue, also braucht es den Mail-Worker.

## Hinweis an den Owner

- Business-Seite, Bereich Webhooks: roter Hinweis mit der Anzahl endgültig fehlgeschlagener Zustellungen, "Als gesehen markieren" (nur dieses Unternehmen, CSRF). Eine neue dead-Zustellung zeigt ihn wieder. Unabhängig vom Admin-Hinweis.
- Mit SMTP zusätzlich eine Mail an die Konto-Adresse des Owners (transaktional, höchstens eine je Webhook und Tag) mit Event, eigener Ziel-URL, Versuchszahl und Link zur Business-Seite. Kein Secret, keine Payload. Läuft über die Mail-Queue, braucht den Mail-Worker. Adressen auf example.*, *.test und *.local werden nicht angeschrieben.

## Grenzen

- Die Sperre ist ein Lease in SQLite, keine Garantie über mehrere Datenbanken oder Hosts.
- Versand läuft synchron im Request (Timeout 5 s je Ziel), keine echte Queue.
- Keine automatische Deaktivierung dauerhaft toter Ziele. Kein Opt-out für die Owner-Mail, keine andere Empfängeradresse als die Konto-Adresse.
- Alte Zustellungen vor dieser Version haben keinen Termin und werden nur manuell wiederholt.
- Tests nutzen die Testnaht `webhook_transport` in `config.php` statt echtem Netzwerk. Echte Zielserver wurden nicht angesprochen.

## Tests

`python tests/run-webhooks.py`: Backoff, Header, dead, Sperre (parallele Prozesse), Banner und Hinweismails für Betreiber und Owner, Owner- und Admin-Wiederholung, Mandantentrennung, CSRF, Cron-CLI, Browser Desktop und Mobil.
