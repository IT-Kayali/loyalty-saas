<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 2: QR-Scan an der Theke + Undo von Transaktionen             */
/* Doku: "Scan a customer's QR code" + "Undoing and reversing"         */
/* ------------------------------------------------------------------ */

/** Kunden-QR scannen (Kamera + jsQR) oder Code manuell eingeben. */
function route_staff_scan(): never
{
    $s = require_staff();
    page('QR scannen', '
      <p><a href="/staff" class="muted">← Theke</a></p>
      <h1>Kunden-QR scannen</h1>
      <div class="scan-wrap">
        <video id="cam" playsinline muted></video>
        <canvas id="scan-canvas" hidden></canvas>
        <p id="scan-status" class="muted">Kamera wird gestartet...</p>
      </div>
      <details class="panel" style="margin-top:1rem">
        <summary>Kein QR? Code manuell eingeben</summary>
        <form method="get" action="/staff/find" class="form inline-form">
          <label>Kunden-Code <input name="code" required placeholder="z.B. B074818F74" style="text-transform:uppercase"></label>
          <button class="btn">Kunde öffnen</button>
        </form>
      </details>
      <script src="/assets/jsQR.js"></script>
      <script>
      (function(){
        var video = document.getElementById("cam");
        var canvas = document.getElementById("scan-canvas");
        var status = document.getElementById("scan-status");
        var ctx = canvas.getContext("2d", {willReadFrequently: true});
        var done = false;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          status.textContent = "Keine Kamera verfügbar - bitte Code manuell eingeben.";
          return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } }).then(function(stream){
          video.srcObject = stream;
          video.play();
          status.textContent = "QR-Code vor die Kamera halten...";
          requestAnimationFrame(tick);
        }).catch(function(){
          status.textContent = "Kamera-Zugriff verweigert - bitte Code manuell eingeben.";
        });
        function tick(){
          if (done) return;
          if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(img.data, img.width, img.height);
            if (code && code.data) {
              var match = code.data.match(/([A-F0-9]{10})/i);
              if (match) {
                done = true;
                status.textContent = "Gefunden! Öffne Kunde...";
                if (video.srcObject) video.srcObject.getTracks().forEach(function(t){ t.stop(); });
                window.location.href = "/staff/find?code=" + match[1].toUpperCase();
                return;
              }
            }
          }
          requestAnimationFrame(tick);
        }
      })();
      </script>', null, $s['brand_color']);
    exit;
}

/** Direkt-Redirect per Kunden-Code (Scan-Ergebnis oder manuelle Eingabe). */
function route_staff_find(): never
{
    $s = require_staff();
    $code = strtoupper(trim($_GET['code'] ?? ''));
    $stmt = db()->prepare('SELECT id FROM members WHERE business_id = ? AND code = ?');
    $stmt->execute([$s['business_id'], $code]);
    $m = $stmt->fetch();
    if (!$m) {
        flash('Kein Kunde mit Code ' . $code . ' gefunden.', 'err');
        redirect('/staff');
    }
    redirect('/staff/member/' . (int)$m['id']);
}

/* ------------------------------------------------------------------ */
/* Undo / Reverse                                                      */
/* ------------------------------------------------------------------ */

/**
 * Storniert eine Transaktion und kehrt ihre Wirkung um.
 * Gibt true zurück, wenn storniert wurde.
 */
function undo_transaction(array $card, int $txnId): bool
{
    $db = db();
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT * FROM transactions WHERE id = ? AND card_id = ? AND reversed_at IS NULL');
    $stmt->execute([$txnId, $card['id']]);
    $t = $stmt->fetch();
    if (!$t) {
        $db->rollBack();
        return false;
    }

    $cid = (int)$card['id'];
    $req = (int)$card['stamps_required'];
    switch ($t['kind']) {
        case 'stamp_add':
            /* Letzten Stempel zurücknehmen; ggf. eine dadurch entstandene Belohnung mit auflösen */
            if ((int)$card['stamps'] > 0) {
                $db->prepare('UPDATE cards SET stamps = stamps - 1 WHERE id = ?')->execute([$cid]);
            } elseif ((int)$card['pending_rewards'] > 0) {
                $db->prepare('UPDATE cards SET pending_rewards = pending_rewards - 1, stamps = ? WHERE id = ?')
                    ->execute([$req - 1, $cid]);
                $db->prepare("UPDATE transactions SET reversed_at = datetime('now')
                    WHERE card_id = ? AND kind = 'reward_earned' AND reversed_at IS NULL
                    AND id = (SELECT MAX(id) FROM transactions WHERE card_id = ? AND kind = 'reward_earned' AND reversed_at IS NULL)")
                    ->execute([$cid, $cid]);
            }
            break;
        case 'points_add':
            $db->prepare('UPDATE cards SET points = MAX(0, points - ?) WHERE id = ?')->execute([(int)$t['amount'], $cid]);
            break;
        case 'points_redeem':
            $db->prepare('UPDATE cards SET points = points + ? WHERE id = ?')->execute([(int)$t['amount'], $cid]);
            break;
        case 'reward_redeem':
            $db->prepare('UPDATE cards SET pending_rewards = pending_rewards + 1 WHERE id = ?')->execute([$cid]);
            break;
        case 'voucher_use':
            $db->prepare('UPDATE cards SET uses_left = uses_left + 1 WHERE id = ?')->execute([$cid]);
            break;
        case 'visit':
            $db->prepare('UPDATE cards SET visits_left = visits_left + 1 WHERE id = ?')->execute([$cid]);
            break;
        default:
            $db->rollBack();
            return false; /* reward_earned und reversal selbst sind nicht direkt stornierbar */
    }

    $db->prepare("UPDATE transactions SET reversed_at = datetime('now') WHERE id = ?")->execute([$txnId]);
    txn($cid, 'reversal', (int)$t['amount'], 'Storniert: ' . $t['note']);
    $db->commit();
    return true;
}

function route_card_undo(int $cardId): never
{
    $u = require_login();
    $card = own_card((int)$u['id'], $cardId);
    if (!$card) {
        not_found();
    }
    if (undo_transaction($card, (int)($_POST['txn_id'] ?? 0))) {
        flash('Aktion storniert.');
    } else {
        flash('Storno nicht möglich.', 'err');
    }
    redirect('/member/' . (int)$card['member_id']);
}

function route_staff_card_undo(int $cardId): never
{
    $s = require_staff();
    $stmt = db()->prepare('SELECT c.*, p.type AS program_type, p.stamps_required, p.name AS program_name
        FROM cards c JOIN programs p ON p.id = c.program_id
        WHERE c.id = ? AND p.business_id = ?');
    $stmt->execute([$cardId, $s['business_id']]);
    $card = $stmt->fetch();
    if (!$card) {
        not_found();
    }
    if (undo_transaction($card, (int)($_POST['txn_id'] ?? 0))) {
        flash('Aktion storniert.');
    } else {
        flash('Storno nicht möglich.', 'err');
    }
    redirect('/staff/member/' . (int)$card['member_id']);
}
