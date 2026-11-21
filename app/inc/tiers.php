<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 3: Membership Tiers + Bonus Hours                            */
/* Doku: "Membership tiers" + "Bonus hours"                            */
/* ------------------------------------------------------------------ */

/** Aktuelle Stufe fuer ein Punkte-Programm anhand der Lifetime-Punkte. */
function current_tier(int $programId, int $lifetimePoints): ?array
{
    $stmt = db()->prepare('SELECT * FROM tiers WHERE program_id = ? AND min_points <= ? ORDER BY min_points DESC LIMIT 1');
    $stmt->execute([$programId, $lifetimePoints]);
    return $stmt->fetch() ?: null;
}

/** Naechste Stufe (fuer Fortschrittsanzeige). */
function next_tier(int $programId, int $lifetimePoints): ?array
{
    $stmt = db()->prepare('SELECT * FROM tiers WHERE program_id = ? AND min_points > ? ORDER BY min_points LIMIT 1');
    $stmt->execute([$programId, $lifetimePoints]);
    return $stmt->fetch() ?: null;
}

/** Aktiver Punkte-Multiplikator (Bonus Hours) fuer jetzt. */
function points_multiplier(int $programId): int
{
    $wd = (int)date('w');
    $h = (int)date('G');
    $stmt = db()->prepare('SELECT multiplier FROM bonus_hours
        WHERE program_id = ? AND weekday = ? AND start_hour <= ? AND end_hour > ?
        ORDER BY multiplier DESC LIMIT 1');
    $stmt->execute([$programId, $wd, $h, $h]);
    $row = $stmt->fetch();
    return $row ? max(1, (int)$row['multiplier']) : 1;
}

/** Tier-Anzeige (Badge + Fortschritt) fuer Karten-Panels und Wallet. */
function tier_html(int $programId, int $lifetimePoints): string
{
    $tier = current_tier($programId, $lifetimePoints);
    $next = next_tier($programId, $lifetimePoints);
    if (!$tier && !$next) {
        return '';
    }
    $out = '<p class="tier-line">';
    if ($tier) {
        $out .= '<span class="badge tier-badge">★ ' . h($tier['name']) . '</span> ';
        if ($tier['benefit']) {
            $out .= '<span class="muted">' . h($tier['benefit']) . '</span> ';
        }
    }
    if ($next) {
        $out .= '<span class="muted">' . t('Noch {n} Punkte bis {tier}', ['n' => (int)$next['min_points'] - $lifetimePoints, 'tier' => $next['name']]) . '</span>';
    }
    return $out . '</p>';
}

/* ------------------------------------------------------------------ */
/* Owner: Tiers und Bonus Hours verwalten (auf der Programmseite)      */
/* ------------------------------------------------------------------ */

function route_tier_create(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    $name = trim($_POST['name'] ?? '');
    if (!$p || $p['type'] !== 'points' || $name === '') {
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO tiers (program_id, name, min_points, benefit) VALUES (?,?,?,?)')
        ->execute([$p['id'], $name, max(0, (int)$_POST['min_points']), trim($_POST['benefit'] ?? '')]);
    flash(t('Stufe angelegt.'));
    redirect('/program/' . (int)$p['id']);
}

function route_tier_delete(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if ($p) {
        db()->prepare('DELETE FROM tiers WHERE id = ? AND program_id = ?')
            ->execute([(int)$_POST['tier_id'], $p['id']]);
        flash(t('Stufe gelöscht.'));
        redirect('/program/' . (int)$p['id']);
    }
    redirect('/dashboard');
}

function route_bonus_create(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points') {
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    $wd = max(0, min(6, (int)$_POST['weekday']));
    $sh = max(0, min(23, (int)$_POST['start_hour']));
    $eh = max(1, min(24, (int)$_POST['end_hour']));
    $mult = max(2, min(10, (int)$_POST['multiplier']));
    if ($eh <= $sh) {
        flash(t('Ende muss nach Start liegen.'), 'err');
        redirect('/program/' . (int)$p['id']);
    }
    db()->prepare('INSERT INTO bonus_hours (program_id, weekday, start_hour, end_hour, multiplier) VALUES (?,?,?,?,?)')
        ->execute([$p['id'], $wd, $sh, $eh, $mult]);
    flash(t('Bonus-Zeit angelegt.'));
    redirect('/program/' . (int)$p['id']);
}

function route_bonus_delete(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if ($p) {
        db()->prepare('DELETE FROM bonus_hours WHERE id = ? AND program_id = ?')
            ->execute([(int)$_POST['bonus_id'], $p['id']]);
        flash(t('Bonus-Zeit gelöscht.'));
        redirect('/program/' . (int)$p['id']);
    }
    redirect('/dashboard');
}
