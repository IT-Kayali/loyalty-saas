<?php
declare(strict_types=1);

/** Decode and re-encode untrusted uploads. Only new PNG bytes reach storage. */
function store_design_image(array $file, int $businessId, string $kind): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Upload fehlgeschlagen. Bitte eine Bilddatei bis 2 MB wählen.');
    }
    $path = $file['tmp_name'];
    if (filesize($path) > 2 * 1024 * 1024 || filesize($path) < 16) {
        throw new InvalidArgumentException('Bild abgelehnt: maximal 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $info = @getimagesize($path);
    if (!$info || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
        || ($info['mime'] ?? '') !== $mime || $info[0] < 16 || $info[1] < 16
        || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 8000000) {
        throw new InvalidArgumentException('Bild abgelehnt: PNG, JPEG oder WebP, 16 bis 4096 Pixel, maximal 8 Megapixel.');
    }
    $bytes = file_get_contents($path);
    if (stripos($bytes, '<?') !== false || stripos($bytes, '<script') !== false) {
        throw new InvalidArgumentException('Bild abgelehnt: eingebetteter Code.');
    }
    $image = @imagecreatefromstring($bytes);
    if (!$image) {
        throw new InvalidArgumentException('Bild konnte nicht gelesen werden.');
    }
    $dir = (string)config('upload_dir', ROOT_DIR . '/data/uploads');
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Upload-Verzeichnis nicht verfügbar.');
    }
    $name = $kind . '_b' . $businessId . '_' . bin2hex(random_bytes(16)) . '.png';
    imagesavealpha($image, true);
    $ok = imagepng($image, $dir . '/' . $name);
    imagedestroy($image);
    if (!$ok) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }
    chmod($dir . '/' . $name, 0640);
    return $name;
}

function stamp_html(array $design, bool $filled): string
{
    $class = 'dot' . ($filled ? ' filled' : '');
    if (($design['stamp_image'] ?? '') !== '') {
        return '<span class="' . $class . ' custom-stamp"><img alt="' . ($filled ? 'Stempel gesammelt' : 'Stempel offen') . '" src="' . h(upload_url($design['stamp_image'])) . '"></span>';
    }
    if(!empty($design['stamp_icon']))return '<span class="'.$class.' emoji-stamp">'.h($design['stamp_icon']).'</span>';
    return '<span class="' . $class . '" aria-label="' . ($filled ? 'Stempel gesammelt' : 'Stempel offen') . '"></span>';
}

/** Raster strip for Apple storeCard and Google Generic heroImage. */
function stamp_strip_png(array $m, int $scale = 1): string
{
    $w = 750 * $scale; $h = 246 * $scale;
    $im = imagecreatetruecolor($w, $h);
    $d = $m['design']; $rgb = sscanf($d['bg_color'], '#%02x%02x%02x');
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    $card = null;
    foreach ($m['cards'] as $c) { if ($c['type'] === 'stamps') { $card = $c; break; } }
    $src = false;
    $f = (string)config('upload_dir', ROOT_DIR . '/data/uploads') . '/' . basename($d['stamp_image'] ?? '');
    if (is_file($f)) { $src = @imagecreatefromstring((string)file_get_contents($f)); }
    if ($card) {
        $n = min(50, max(2, (int)$card['stamps_required']));
        $cols = min(10, $n); $rows = (int)ceil($n / $cols);
        $size = (int)min(56 * $scale, ($h - 24 * $scale) / $rows);
        $gap = 12 * $scale; $startX = ($w - $cols * ($size + $gap) + $gap) / 2;
        $fg = imagecolorallocate($im, ...sscanf($d['accent_color'], '#%02x%02x%02x'));
        for ($i = 0; $i < $n; $i++) {
            $x = (int)($startX + ($i % $cols) * ($size + $gap));
            $startY = ($h - $rows * ($size + 4 * $scale) + 4 * $scale) / 2;
            $y = (int)($startY + intdiv($i, $cols) * ($size + 4 * $scale));
            if ($src) {
                $ratio = min($size / imagesx($src), $size / imagesy($src));
                $dw = (int)(imagesx($src) * $ratio); $dh = (int)(imagesy($src) * $ratio);
                $draw = $src;
                if ($i >= (int)$card['stamps']) {
                    $draw = imagecreatetruecolor(imagesx($src), imagesy($src));
                    imagealphablending($draw, false); imagesavealpha($draw, true);
                    imagecopy($draw, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                    imagefilter($draw, IMG_FILTER_GRAYSCALE);
                    imagefilter($draw, IMG_FILTER_COLORIZE, 0, 0, 0, 105);
                }
                imagecopyresampled($im, $draw, $x + (int)(($size-$dw)/2), $y + (int)(($size-$dh)/2), 0, 0, $dw, $dh, imagesx($src), imagesy($src));
                if ($draw !== $src) imagedestroy($draw);
            } elseif ($i < (int)$card['stamps']) {
                imagefilledellipse($im, $x + (int)($size/2), $y + (int)($size/2), $size-4, $size-4, $fg);
            } else {
                imageellipse($im, $x + (int)($size/2), $y + (int)($size/2), $size-4, $size-4, $fg);
            }
        }
    }
    if ($src) imagedestroy($src);
    ob_start(); imagepng($im); imagedestroy($im); return (string)ob_get_clean();
}

function route_stamp_strip(string $code, string $token): never
{
    $m = pass_member($code);
    if (!$m || !hash_equals(wallet_image_token($m), $token)) not_found();
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-cache');
    header('Referrer-Policy: no-referrer');
    echo stamp_strip_png($m); exit;
}
