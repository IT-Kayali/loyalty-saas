# Shopify-Integration

Shopify schickt jede bezahlte Bestellung per Webhook an die Loyalty-SaaS-App, die dafür Treuepunkte gutschreibt. Es ist keine App-Entwicklung nötig — nur ein Webhook im Shopify-Admin.

## Einrichtung

1. In der Loyalty-SaaS-App: Business-Seite → **Integrationen** → **Shopify Webhook-Secret** eintragen (ein langes, zufälliges Passwort deiner Wahl) und **Punkte je Cent Umsatz** setzen. Speichern.
2. Die auf derselben Seite angezeigte Webhook-URL kopieren. Sie hat die Form:
   `https://loyalty.deine-domain.de/integrations/shopify/webhook/1`
3. In Shopify: **Einstellungen → Benachrichtigungen → Webhooks → Webhook erstellen**:
   - Ereignis: **Bestellung bezahlt** (`orders/paid`) — alternativ `orders/create`
   - Format: JSON
   - URL: die kopierte Webhook-URL
4. Dasselbe Secret muss Shopify mitgeteilt werden. Bei den klassischen Admin-Webhooks (Schritt 3) signiert Shopify mit dem **API-Key der Benachrichtigungen**, der dir nach dem Speichern angezeigt wird — trage genau diesen Wert in der Loyalty-SaaS-App als Webhook-Secret ein. Bei einer eigenen Custom App ist es das **API-Secret** der App.

## Sicherheit

- Jede Anfrage wird per HMAC-SHA256 (Header `X-Shopify-Hmac-Sha256`) gegen das Secret geprüft. Ungültige Signaturen werden mit 401 abgelehnt.
- Ohne gesetztes Secret antwortet der Endpunkt mit 404 — die Integration ist dann komplett abgeschaltet.
- Nur `orders/paid` und `orders/create` werden verarbeitet, alle anderen Topics werden ignoriert.

## Was passiert pro Bestellung

1. Mitglied wird per E-Mail gefunden oder angelegt (mit Code + QR, direkt wallet-fähig).
2. Punkte = Bestellsumme in Cent × **Punkte je Cent** werden auf das erste aktive Punkte-Programm des Business gutgeschrieben. Bonus-Zeitfenster (Happy Hour etc.) gelten auch hier.
3. Referenz `shopify-{Bestell-ID}` verhindert Doppelgutschriften bei erneuter Zustellung des Webhooks.

## Test

Shopify-Admin → der angelegte Webhook → **Testbenachrichtigung senden**. In der Loyalty-SaaS-App unter Integrationen → Webhooks siehst du die Zustellung, und das Testmitglied erscheint in der Mitgliederliste.
