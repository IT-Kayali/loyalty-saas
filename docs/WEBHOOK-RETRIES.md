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

## Grenzen

- Kein Parallelbetrieb mehrerer Worker abgesichert (keine Sperre): zwei gleichzeitige Cron-Läufe können dieselbe Zustellung doppelt senden.
- Versand läuft synchron im Request (Timeout 5 s je Ziel), keine echte Queue.
- Kein Alarm per Mail bei `dead`, keine automatische Deaktivierung dauerhaft toter Ziele.
- Alte Zustellungen vor dieser Version haben keinen Termin und werden nur manuell wiederholt.
- Tests nutzen die Testnaht `webhook_transport` in `config.php` statt echtem Netzwerk. Echte Zielserver wurden nicht angesprochen.

## Tests

`python tests/run-webhooks.py`: Backoff, Header, dead, Owner- und Admin-Wiederholung, Mandantentrennung, CSRF, Cron-CLI, Browser Desktop und Mobil.
