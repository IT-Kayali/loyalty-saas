# Stripe-Abos für Betreiber

## Was implementiert ist

- `/billing`: Planwahl und Anzeige des serverseitigen Abo-Status.
- Stripe Checkout im Subscription-Modus mit konfigurierten wiederkehrenden Price-IDs.
- Ein Stripe-Kunde und höchstens ein offener Checkout pro Betreiberkonto. Kunden- und Checkout-Erstellung verwenden Idempotency-Keys.
- Stripe Customer Portal für Rechnungen, Zahlungsmittel, Planwechsel und Kündigung. Diese Funktionen müssen im Stripe-Portal ausdrücklich eingeschaltet werden.
- `/stripe/webhook`: Prüfung der Signatur über den unveränderten Request-Body, 5-Minuten-Zeitfenster, mehrere v1-Signaturen für Schlüsselrotation, Event-Deduplizierung und transaktionale Verarbeitung.
- Der aktuelle Subscription-Zustand wird bei Stripe abgefragt. Die Reihenfolge eintreffender Events entscheidet nicht über den aktuellen Status. Ein altes Abo kann kein neues aktives Abo überschreiben.
- `active` und `trialing` gelten als berechtigt. `past_due`, `unpaid`, `incomplete`, `paused` und `canceled` nicht. Ohne aktivierte Limits dient der Status nur der Anzeige.
- Zentrale Planprüfung für alle bestehenden betrieblichen Schreibkanäle, Mengenlimits für Unternehmen/Programme/Mitglieder/Team und getestete Feature-Schalter. Bestehende Daten bleiben bei Ablauf lesbar, Änderungen werden bei aktivierter Prüfung gesperrt. Details: [OPERATIONS.md](OPERATIONS.md).

Abrechnung erfolgt pro Betreiberkonto, nicht pro Endkunde und nicht automatisch pro Unternehmen. Beispiel-Plannamen und Mengen sind Konfigurationsvorschläge, keine freigegebenen Verkaufspreise.

## Einrichtung

1. Stabile HTTPS-Adresse und eigene produktive Konten verwenden. Keine Tunnel-URL für produktive Webhooks.
2. `config.example.php` nach `config.php` kopieren oder vorhandene Konfiguration um `stripe` ergänzen. Geheimnisse nie in `public/`, Git oder im Browser ablegen.
3. Im **Stripe-Testmodus** zwei Produkte mit wiederkehrenden Preisen anlegen, etwa Starter und Pro. Währung, monatliche/jährliche Laufzeit, Steuern und Preise legt der Betreiber selbst fest.
4. Umgebungsvariablen setzen:

   ```text
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   STRIPE_PRICE_STARTER=price_...
   STRIPE_PRICE_PRO=price_...
   ```

   Alternativ die Werte serverseitig in `config.php` eintragen. Kein Publishable Key nötig, da der Browser zum gehosteten Stripe Checkout weitergeleitet wird.
5. In Stripe einen Webhook-Endpunkt mit der vollständigen Basis-URL plus `/stripe/webhook` anlegen. Events:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
6. Das Endpoint-Signing-Secret übernehmen. Für lokale Stripe-CLI-Weiterleitung gilt deren eigenes Signing-Secret, nicht das Secret des Dashboard-Endpunkts.
7. Im Stripe Customer Portal Rechnungen, Zahlungsmittel, Kündigung und Subscription-Wechsel einschalten. Die für Wechsel erlaubten Produkte/Preise müssen auch in `stripe.plans` stehen. Unbekannte Preise führen bewusst zu einem wiederholbaren Webhook-Fehler statt zu einer geratenen Berechtigung.
8. `base_url` auf die HTTPS-Adresse setzen. App-Aufrufe pinnen die Stripe-API-Version `2024-06-20`. Für Events werden Subscription-IDs aus älteren und neueren Invoice-Payloads akzeptiert, der Abozustand wird anschließend separat gelesen.
9. Erst nach erfolgreichen Testkäufen und geklärten Verkaufskonditionen Live-Keys, Live-Prices und Live-Webhook-Secret einsetzen. Keine echten Abbuchungen waren Bestandteil der Entwicklung.
10. Optional `stripe.enforce_limits=true` setzen. Die Prüfung gilt für Betreiber, Staff, Join, API und Shopify; Login, Abrechnung und Datenschutz bleiben erreichbar. Fehlende/0-Mengenlimits bedeuten unbegrenzt. Details und Grenzen in docs/OPERATIONS.md.

Fehlen Secret Key **oder** Webhook Secret, bleibt Checkout deaktiviert, Webhooks antworten 503. Fehlende Price-IDs deaktivieren den jeweiligen Plan. Eine Success-URL oder ein Queryparameter aktiviert nie ein Abo.

## Datenmodell und Betrieb

`subscriptions` hält Customer-ID, Subscription-ID, letzten Checkout, Generation, Plan, Status, Kündigung zum Periodenende und Periodenende. `stripe_events` hält nur Event-ID und Typ, keine rohen Zahlungsdaten. Tabellen werden beim ersten Zugriff additiv angelegt; ein erneutes Seeden ist für Updates nicht nötig und würde Demo-Daten zurücksetzen.

Stripe-Webhooks müssen ohne Session-CSRF passieren; ihre eigene Authentifizierung ist die Stripe-Signatur. Alle Browser-POSTs brauchen weiterhin CSRF. Nicht erfolgreiche Verarbeitung antwortet 500, damit Stripe erneut zustellen kann. Warnungen/Fehler in Stripe Dashboard und Webhook-Zustellungen überwachen. Event-IDs nicht löschen, solange alte Events erneut zugestellt werden könnten.

Verarbeitung ist für eine kleine SQLite-Installation ausgelegt. Webhook und Checkout sind pro DB serialisiert, während Stripe abgefragt wird. Bei größerem Volumen sind eine Queue, Hintergrundabgleich und ein skalierbares DB-System erforderlich.

## Prüfung vor produktiver Freigabe

- Checkout mit Stripe-Testzahlung abschließen. Status wird erst mit Webhook `active`.
- Checkout abbrechen, wieder öffnen, doppelt klicken und Planwechsel testen.
- Eine ausstehende Zahlung, fehlgeschlagene Verlängerung, erneute Zahlung und Kündigung testen.
- Portalwechsel zwischen allen angebotenen Preisen testen. Pro-rata-Regeln im Stripe-Portal prüfen.
- Signaturfehler, Replay, wiederholte Events und vertauschte Zustellung prüfen.
- Mandant A darf niemals Abo oder Design von Mandant B verwalten.
- Test- und Live-Daten vollständig trennen.

Lokale Tests: `php tests/billing.php`, dazu `tests/run-browser.py` mit PHP, Python und Playwright. Zustandstests verwenden eine In-Memory-DB und einen Stripe-Testadapter, keine echten Stripe-Anfragen. Ein echter Stripe-End-to-End-Test bleibt ohne Keys offen.

## Quellen

- https://docs.stripe.com/billing/subscriptions/build-subscriptions
- https://docs.stripe.com/api/checkout/sessions/create
- https://docs.stripe.com/customer-management/integrate-customer-portal
- https://docs.stripe.com/webhooks/signature
- https://rewardloyalty.co/docs/5.x/plans-and-billing
