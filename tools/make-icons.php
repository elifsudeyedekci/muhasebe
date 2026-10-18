<?php
// Kullanım: php tools/make-icons.php  — PWA simgelerini GD ile üretir (public/assets/icons).
$out = __DIR__ . '/../public/assets/icons';
@mkdir($out, 0755, true);
function icon(int $size, bool $maskable): GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, true);
    // dikey gradyan: #1d4ed8 → #0ea5a4
    for ($y = 0; $y < $size; $y++) {
        $t = $y / $size;
        $c = imagecolorallocate($img, (int)(29 + (14 - 29) * $t), (int)(78 + (165 - 78) * $t), (int)(216 + (164 - 216) * $t));
        imageline($img, 0, $y, $size, $y, $c);
    }
    if (!$maskable) { // yuvarlak köşe (maskable tam kare bırakılır)
        $r = (int)($size * 0.22);
        $mask = imagecreatetruecolor($size, $size);
        $tr = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagealphablending($mask, false);
        imagefill($mask, 0, 0, $tr);
        imagesavealpha($mask, true);
        $white = imagecolorallocate($mask, 255, 255, 255);
        imagefilledrectangle($mask, $r, 0, $size - $r, $size, $white);
        imagefilledrectangle($mask, 0, $r, $size, $size - $r, $white);
        foreach ([[$r, $r], [$size - $r, $r], [$r, $size - $r], [$size - $r, $size - $r]] as [$cx, $cy]) {
            imagefilledellipse($mask, $cx, $cy, $r * 2, $r * 2, $white);
        }
        $res = imagecreatetruecolor($size, $size);
        imagealphablending($res, false);
        imagesavealpha($res, true);
        imagefill($res, 0, 0, imagecolorallocatealpha($res, 0, 0, 0, 127));
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if ((imagecolorat($mask, $x, $y) & 0xFF) > 200) {
                    imagesetpixel($res, $x, $y, imagecolorat($img, $x, $y));
                }
            }
        }
        $img = $res;
    }
    imagealphablending($img, true);
    // "F" harfi + küçük çubuk grafik
    $white = imagecolorallocate($img, 255, 255, 255);
    $u = $size / 100;
    $pad = $maskable ? 0.18 : 0.0;
    $o = (int)($size * $pad);
    $s = $size - 2 * $o;
    $x0 = $o + (int)($s * .30); $y0 = $o + (int)($s * .24); $w = (int)($s * .13);
    imagefilledrectangle($img, $x0, $y0, $x0 + $w, $o + (int)($s * .78), $white);
    imagefilledrectangle($img, $x0, $y0, $o + (int)($s * .72), $y0 + $w, $white);
    imagefilledrectangle($img, $x0, $o + (int)($s * .46), $o + (int)($s * .62), $o + (int)($s * .46) + $w, $white);
    return $img;
}
foreach ([['icon-192.png', 192, false], ['icon-512.png', 512, false], ['icon-maskable-512.png', 512, true], ['apple-touch-icon.png', 180, true]] as [$n, $sz, $m]) {
    $i = icon($sz, $m);
    imagesavealpha($i, true);
    imagepng($i, "$out/$n", 9);
    echo "$n\n";
}
