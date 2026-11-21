<?php
declare(strict_types=1);
/**
 * Zeitzone je Unternehmen (businesses.timezone, IANA-Name, Standard UTC). Gespeichert wird weiter alles in UTC,
 * die Zeitzone wirkt nur auf Eingabe und Anzeige der Funktionen, die sie ausdruecklich nutzen (Praemien-Zeitfenster, Einloesecodes).
 */
const BIZ_TZ_CHOICES = ['UTC', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/Paris', 'Europe/Madrid', 'Europe/Rome', 'Europe/London', 'Europe/Istanbul', 'Europe/Moscow',
    'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Tokyo', 'Australia/Sydney'];

function tz_valid(string $tz): bool { return in_array($tz, BIZ_TZ_CHOICES, true); }

function biz_tz(int $businessId): string
{
    $q = db()->prepare('SELECT timezone FROM businesses WHERE id=?');
    $q->execute([$businessId]);
    $tz = (string)$q->fetchColumn();
    $q->closeCursor();
    return tz_valid($tz) ? $tz : 'UTC';
}

/** UTC-Text 'Y-m-d H:i:s' -> lokale Zeit im Format $fmt. */
function tz_local(string $utc, string $tz, string $fmt = 'Y-m-d H:i'): string
{
    if ($utc === '') return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format($fmt);
}
/** Kurzname der Zeitzone zum Zeitpunkt $utc, z. B. CET, CEST, UTC. */
function tz_abbr(string $utc, string $tz): string
{
    $d = (new DateTimeImmutable($utc !== '' ? $utc : 'now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
    $a = $d->format('T');
    return preg_match('/^[+-]/', $a) ? 'UTC' . $d->format('P') : $a;
}
/** Lokale Eingabe 'Y-m-dTH:i' (datetime-local) in Zeitzone $tz -> UTC 'Y-m-d H:i:s'; null leer, false ungueltig. */
function tz_input_to_utc(string $in, string $tz): string|null|false
{
    $in = trim($in);
    if ($in === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $in, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) return false;
    $d = new DateTimeImmutable("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:00", new DateTimeZone($tz));
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function route_business_timezone(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $tz = (string)($_POST['timezone'] ?? '');
    if (!$b || !tz_valid($tz)) { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    db()->prepare('UPDATE businesses SET timezone=? WHERE id=?')->execute([$tz, $b['id']]);
    audit_log((int)$u['id'], 'business.timezone', ['business_id' => (int)$b['id'], 'timezone' => $tz]);
    flash('Zeitzone gespeichert: ' . $tz . '. Gespeicherte Zeiten bleiben unverändert (UTC), nur Eingabe und Anzeige ändern sich.');
    redirect('/business/' . (int)$b['id']);
}

function business_timezone_form_html(array $b): string
{
    $cur = biz_tz((int)$b['id']);
    $opts = '';
    foreach (BIZ_TZ_CHOICES as $z) $opts .= '<option value="' . h($z) . '"' . ($z === $cur ? ' selected' : '') . '>' . h($z) . '</option>';
    return '<h2>Zeitzone</h2><p class="muted">Gilt für Zeitfenster von Prämien und die Zeiten bei Einlösecodes. Intern wird weiter UTC gespeichert. Andere Bereiche (z. B. Gutscheine, Protokolle) zeigen weiter UTC.</p>
      <form method="post" action="/business/timezone" class="form inline-form">' . csrf_field() . '<input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
        <label>Zeitzone <select name="timezone">' . $opts . '</select></label><button class="btn ghost">Speichern</button></form>';
}
