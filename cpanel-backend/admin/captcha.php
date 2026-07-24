<?php
/**
 * Renders the login verification question (stored in the session by index.php)
 * as a small distorted PNG so bots can't read it from the HTML.
 * If GD is unavailable the login page falls back to a plain-text question,
 * so this file simply 404s in that case.
 */
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();

if (!function_exists('imagecreatetruecolor')) { http_response_code(404); exit; }
$q = $_SESSION['captcha_q'] ?? '';
if ($q === '') { http_response_code(404); exit; }

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$w = 150; $h = 46;
$img = imagecreatetruecolor($w, $h);
$bg    = imagecolorallocate($img, 12, 13, 22);
$fg    = imagecolorallocate($img, 126, 200, 227);
$noise = imagecolorallocate($img, 55, 62, 88);
imagefilledrectangle($img, 0, 0, $w, $h, $bg);

// scatter noise lines + dots
for ($i = 0; $i < 6; $i++) {
    imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $noise);
}
for ($i = 0; $i < 60; $i++) {
    imagesetpixel($img, random_int(0, $w), random_int(0, $h), $noise);
}

// draw each character with a little vertical jitter (built-in font, no TTF needed)
$x = 12;
$len = strlen($q);
for ($i = 0; $i < $len; $i++) {
    $y = random_int(10, 20);
    imagestring($img, 5, $x, $y, $q[$i], $fg);
    $x += 10;
}

imagepng($img);
imagedestroy($img);
