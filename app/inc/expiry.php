<?php
declare(strict_types=1);
/**
 * Punkteverfall bei Inaktivitaet. Ein Programm kann einstellen: Punkte verfallen, wenn die Karte
 * N Tage keine Buchung hatte (Gutschrift, Einloesung, Korrektur). Lifetime-Punkte und Stufen bleiben.
 * Verfall wird nur durch tools/expire-points.php oder den Button im Programm ausgefuehrt, nie durch Seitenaufrufe.
 */
const POINTS_EXPIRE_MAX_DAYS = 3650;

/** Zeitpunkt der letzten Buchung (ohne Verfall) als SQLite-UTC-Text. */
function card_last_activity(int $cardId): string
{
    $q = db()->prepare("SELECT COALESCE((SELECT MAX(created_at) FROM transactions WHERE card_id=c.id AND kind<>'points_expire'), c.created_at) FROM cards c WHERE c.id=?");
    $q->execute([$cardId]);
    return (string)$q->fetchColumn();
}

/** Zeitpunkt, ab dem die Punkte der Karte verfallen, oder null (aus, keine Punkte). */
function card_points_expiry(int $cardId): ?string
{
    $q = db()->prepare('SELECT c.points, p.points_expire_days FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.id=?');
    $q->execute([$cardId]);
    $r = $q->fetch();
    if (!$r || (int)$r['points_expire_days'] < 1 || (int)$r['points'] < 1) return null;
    return gmdate('Y-m-d H:i:s', strtotime(card_last_activity($cardId) . ' UTC') + (int)$r['points_expire_days'] * 86400);
}

/** Wallet-Hinweis, nur wenn Verfall aktiv ist. */
function points_expiry_notice_html(int $cardId): string
{
    $at = card_points_expiry($cardId);
    if (!$at) return '';
    $days = (int)ceil((strtotime($at . ' UTC') - time()) / 86400);
    $date = date('d.m.Y', strtotime($at . ' UTC'));
    return '<p class="muted points-expiry">' . h($days <= 0 ? 'Deine Punkte verfallen mit der nächsten Prüfung. Eine Buchung hält sie am Leben.' : 'Punkte verfallen am ' . $date . ', wenn bis dahin keine Buchung stattfindet.') . '</p>';
}

/** Verfall fuer ein Programm. Liefert [Karten, Punkte]. $now (Unix) nur fuer Tests. */
function expire_program_points(int $programId, ?int $now = null): array
{
    $db = db();
    $p = $db->prepare("SELECT points_expire_days FROM programs WHERE id=? AND type='points'");
    $p->execute([$programId]);
    $days = (int)$p->fetchColumn();
    if ($days < 1) return [0, 0];
    $cut = gmdate('Y-m-d H:i:s', ($now ?? time()) - $days * 86400);
    $ids = $db->prepare("SELECT c.id FROM cards c WHERE c.program_id=? AND c.points>0
        AND COALESCE((SELECT MAX(created_at) FROM transactions t WHERE t.card_id=c.id AND t.kind<>'points_expire'), c.created_at) < ?");
    $ids->execute([$programId, $cut]);
    $cards = 0; $sum = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $c = $db->prepare('SELECT points FROM cards WHERE id=?');
            $c->execute([$cid]);
            $pts = (int)$c->fetchColumn();
            $act = card_last_activity((int)$cid);
            if ($pts > 0 && $act < $cut) {
                $db->prepare('UPDATE cards SET points=0 WHERE id=?')->execute([$cid]);
                txn((int)$cid, 'points_expire', -$pts, 'Verfall nach ' . $days . ' Tagen ohne Buchung', ['kind' => 'owner']);
                $cards++; $sum += $pts;
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable) {}
            throw $e;
        }
    }
    return [$cards, $sum];
}

function expire_all_points(?int $now = null): array
{
    $cards = 0; $sum = 0;
    foreach (db()->query("SELECT id FROM programs WHERE type='points' AND points_expire_days>0")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        [$c, $s] = expire_program_points((int)$pid, $now);
        $cards += $c; $sum += $s;
    }
    return [$cards, $sum];
}

function route_program_expiry(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points') { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    $back = '/program/' . (int)$p['id'];
    if (($_POST['action'] ?? '') === 'run') {
        [$c, $s] = expire_program_points((int)$p['id']);
        audit_log((int)$u['id'], 'points.expire.run', ['program_id' => (int)$p['id'], 'cards' => $c, 'points' => $s]);
        flash($c . ' Karten geprüft und verfallen, ' . $s . ' Punkte entfernt.');
        redirect($back);
    }
    $days = (int)($_POST['days'] ?? 0);
    if ($days < 0 || $days > POINTS_EXPIRE_MAX_DAYS) { flash('Tage zwischen 0 (aus) und ' . POINTS_EXPIRE_MAX_DAYS . '.', 'err'); redirect($back); }
    db()->prepare('UPDATE programs SET points_expire_days=? WHERE id=?')->execute([$days, $p['id']]);
    audit_log((int)$u['id'], 'points.expire.set', ['program_id' => (int)$p['id'], 'days' => $days]);
    flash($days > 0 ? 'Punkteverfall nach ' . $days . ' Tagen ohne Buchung aktiv.' : 'Punkteverfall aus.');
    redirect($back);
}

function points_expiry_form_html(array $p): string
{
    $d = (int)($p['points_expire_days'] ?? 0);
    return '<h2>Punkteverfall</h2><p class="muted">Punkte verfallen, wenn eine Karte so viele Tage keine Buchung hatte. Lifetime-Punkte und Stufen bleiben. Der Verfall läuft per Cron (tools/expire-points.php) oder mit dem Button.</p>
      <form method="post" action="/program/expiry" class="form inline-form panel">' . csrf_field() . '<input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
        <label>Tage ohne Buchung (0 = aus) <input type="number" name="days" value="' . $d . '" min="0" max="' . POINTS_EXPIRE_MAX_DAYS . '"></label>
        <button class="btn" name="action" value="save">Speichern</button>'
        . ($d > 0 ? '<button class="btn ghost" name="action" value="run" onclick="return confirm(\'Abgelaufene Punkte jetzt entfernen?\')">Jetzt prüfen und verfallen lassen</button>' : '') . '
      </form>';
}
