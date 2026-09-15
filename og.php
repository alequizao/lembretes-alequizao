<?php
/* Imagem 1200x630 da prévia de link (WhatsApp, Instagram, Telegram, Twitter). */
require_once __DIR__ . '/lib.php';
$cache = __DIR__ . '/og-cache.png';
if (is_file($cache) && filemtime($cache) > @filemtime(__FILE__) && filemtime($cache) > time() - 86400 * 7) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache);
    exit;
}
$L = 1200; $A = 630;
$im = imagecreatetruecolor($L, $A);
imageantialias($im, true);
$rgb = fn($h) => [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
[$r1, $g1, $b1] = $rgb('1E3A8A'); [$r2, $g2, $b2] = $rgb('3B82F6');
for ($y = 0; $y < $A; $y++) {           // fundo em degradê
    $t = $y / $A;
    $c = imagecolorallocate($im, (int)($r1 + ($r2 - $r1) * $t), (int)($g1 + ($g2 - $g1) * $t), (int)($b1 + ($b2 - $b1) * $t));
    imageline($im, 0, $y, $L, $y, $c);
}
/* círculos decorativos */
$brilho = imagecolorallocatealpha($im, 255, 255, 255, 112);
imagefilledellipse($im, 1040, 120, 380, 380, $brilho);
imagefilledellipse($im, 150, 590, 300, 300, $brilho);

$branco = imagecolorallocate($im, 255, 255, 255);
$claro  = imagecolorallocate($im, 219, 234, 254);
$fonte  = __DIR__ . '/fonte.ttf';
$fonteB = __DIR__ . '/fonte-bold.ttf';
if (!is_file($fonteB)) $fonteB = $fonte;

/* sino desenhado (sem depender de emoji na fonte) */
$cx = 150; $cy = 160;
$azul = imagecolorallocate($im, 37, 99, 235);
imagefilledellipse($im, $cx, $cy, 160, 160, $branco);
/* corpo do sino: trapézio com topo arredondado */
imagefilledpolygon($im, [$cx - 44, $cy + 26, $cx - 34, $cy - 8, $cx + 34, $cy - 8, $cx + 44, $cy + 26], $azul);
imagefilledellipse($im, $cx, $cy - 8, 68, 56, $azul);
imagefilledrectangle($im, $cx - 50, $cy + 26, $cx + 50, $cy + 36, $azul);
imagefilledellipse($im, $cx, $cy - 38, 16, 16, $azul);   // alça
imagefilledellipse($im, $cx, $cy + 46, 22, 18, $azul);   // badalo

$txt = function ($texto, $x, $y, $tam, $cor, $negrito = true) use ($im, $fonte, $fonteB) {
    imagettftext($im, $tam, 0, $x, $y, $cor, $negrito ? $fonteB : $fonte, $texto);
};
$txt('Lembretes', 270, 175, 58, $branco);
$txt('alequizao.com/lembretes', 272, 222, 24, $claro, false);
$txt('Avise quem importa, na hora certa.', 90, 352, 38, $branco);
$txt('Agende uma vez — o sistema entrega sozinho por:', 90, 412, 25, $claro, false);

/* pílulas dos canais */
$x = 92;
foreach ([['WhatsApp', '25D366'], ['Direct do Instagram', 'E1306C'], ['E-mail', 'F59E0B']] as [$nome, $hex]) {
    $largura = (int)(imagettfbbox(24, 0, $fonteB, $nome)[2] - imagettfbbox(24, 0, $fonteB, $nome)[0]) + 64;
    [$pr, $pg, $pb] = $rgb($hex);
    $cor = imagecolorallocate($im, $pr, $pg, $pb);
    imagefilledrectangle($im, $x, 460, $x + $largura, 524, $cor);
    imagefilledellipse($im, $x, 492, 64, 64, $cor);
    imagefilledellipse($im, $x + $largura, 492, 64, 64, $cor);
    $txt($nome, $x + 22, 502, 24, $branco);
    $x += $largura + 76;
}
imagepng($im, $cache, 6);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($im, null, 6);
imagedestroy($im);
