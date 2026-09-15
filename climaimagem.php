<?php
/* Cartão de previsão do tempo em imagem (GD) — mesmo visual da tela #/clima.
   Uso: climaImagemGerar($analise) → ['arquivo'=>'clima-....png', 'caminho'=>..., 'url'=>...]  */
require_once __DIR__ . '/midias.php';

define('CLIMA_IMG_DIR', MIDIA_DIR . '/clima');
define('CLIMA_IMG_URL', 'https://alequizao.com/lembretes/uploads/clima/');

/* ---------- ajudantes de desenho ---------- */
function ciCor($im, string $hex, float $alfa = 0): int {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return imagecolorallocatealpha($im, $r, $g, $b, (int)round($alfa * 127));
}
function ciDegrade($im, int $x, int $y, int $l, int $a, string $de, string $para): void {
    [$r1, $g1, $b1] = sscanf($de, '#%02x%02x%02x');
    [$r2, $g2, $b2] = sscanf($para, '#%02x%02x%02x');
    for ($i = 0; $i < $a; $i++) {
        $t = $i / max(1, $a - 1);
        $c = imagecolorallocate($im, (int)($r1 + ($r2 - $r1) * $t), (int)($g1 + ($g2 - $g1) * $t), (int)($b1 + ($b2 - $b1) * $t));
        imagefilledrectangle($im, $x, $y + $i, $x + $l, $y + $i, $c);
    }
}
/* Retângulo arredondado translúcido: mistura o branco com o fundo pixel a pixel,
   porque elipses com alfa se sobrepõem nos cantos e deixam manchas escuras. */
function ciCaixa($im, int $x, int $y, int $l, int $a, int $raio, float $forca = .16, string $hex = '#FFFFFF'): void {
    [$cr, $cg, $cb] = sscanf($hex, '#%02x%02x%02x');
    $x2 = $x + $l; $y2 = $y + $a;
    for ($py = $y; $py <= $y2; $py++) {
        for ($px = $x; $px <= $x2; $px++) {
            /* canto arredondado */
            $dx = $px < $x + $raio ? $x + $raio - $px : ($px > $x2 - $raio ? $px - ($x2 - $raio) : 0);
            $dy = $py < $y + $raio ? $y + $raio - $py : ($py > $y2 - $raio ? $py - ($y2 - $raio) : 0);
            if ($dx && $dy && ($dx * $dx + $dy * $dy) > $raio * $raio) continue;
            $c = imagecolorat($im, $px, $py);
            $r = (int)(((($c >> 16) & 255) * (1 - $forca)) + $cr * $forca);
            $g = (int)(((($c >> 8) & 255) * (1 - $forca)) + $cg * $forca);
            $b = (int)((($c & 255) * (1 - $forca)) + $cb * $forca);
            imagesetpixel($im, $px, $py, imagecolorallocate($im, $r, $g, $b));
        }
    }
}
function ciFonte(bool $negrito = false): string {
    return __DIR__ . ($negrito ? '/fonte-bold.ttf' : '/fonte.ttf');
}
function ciTexto($im, int $tam, int $x, int $y, int $cor, string $txt, bool $negrito = false, string $ancora = 'esq'): array {
    $cx = imagettfbbox($tam, 0, ciFonte($negrito), $txt);
    $larg = $cx[2] - $cx[0];
    if ($ancora === 'centro') $x -= (int)($larg / 2);
    if ($ancora === 'dir') $x -= $larg;
    imagettftext($im, $tam, 0, $x, $y, $cor, ciFonte($negrito), $txt);
    return ['largura' => $larg, 'x' => $x];
}
function ciLargura(int $tam, string $txt, bool $negrito = false): int {
    $c = imagettfbbox($tam, 0, ciFonte($negrito), $txt);
    return $c[2] - $c[0];
}
/* quebra o texto em linhas que cabem em $max px */
function ciQuebrar(int $tam, string $txt, int $max, bool $negrito = false): array {
    $linhas = []; $atual = '';
    foreach (explode(' ', $txt) as $p) {
        $teste = $atual === '' ? $p : $atual . ' ' . $p;
        if (ciLargura($tam, $teste, $negrito) > $max && $atual !== '') { $linhas[] = $atual; $atual = $p; }
        else $atual = $teste;
    }
    if ($atual !== '') $linhas[] = $atual;
    return $linhas;
}
/* pílula translúcida com texto */
function ciPilula($im, int $x, int $y, string $txt, int $tam = 22): int {
    $pad = 20; $l = ciLargura($tam, $txt) + $pad * 2; $a = 48;
    ciCaixa($im, $x, $y, $l, $a, 24, .18);
    ciTexto($im, $tam, $x + $pad, $y + 33, ciCor($im, '#FFFFFF'), $txt);
    return $l;
}

/* ---------- ícones do tempo (desenhados, sem emoji) ---------- */
function ciSol($im, int $cx, int $cy, int $r, ?int $cor = null): void {
    $c = $cor ?? ciCor($im, '#FDE047');
    for ($i = 0; $i < 8; $i++) {
        $ang = $i * M_PI / 4;
        imagesetthickness($im, max(2, (int)($r / 7)));
        imageline($im, (int)($cx + cos($ang) * $r * 1.35), (int)($cy + sin($ang) * $r * 1.35),
                       (int)($cx + cos($ang) * $r * 1.85), (int)($cy + sin($ang) * $r * 1.85), $c);
    }
    imagesetthickness($im, 1);
    imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $c);
}
function ciNuvem($im, int $cx, int $cy, int $r, string $hex = '#FFFFFF', float $alfa = 0): void {
    $c = ciCor($im, $hex, $alfa);
    imagefilledellipse($im, (int)($cx - $r * .75), $cy, (int)($r * 1.25), (int)($r * 1.25), $c);
    imagefilledellipse($im, (int)($cx + $r * .7), (int)($cy + $r * .1), (int)($r * 1.1), (int)($r * 1.1), $c);
    imagefilledellipse($im, $cx, (int)($cy - $r * .45), (int)($r * 1.6), (int)($r * 1.6), $c);
    imagefilledrectangle($im, (int)($cx - $r * 1.3), $cy, (int)($cx + $r * 1.25), (int)($cy + $r * .6), $c);
}
function ciGotas($im, int $cx, int $cy, int $r, int $qtd = 3): void {
    $c = ciCor($im, '#93C5FD');
    for ($i = 0; $i < $qtd; $i++) {
        $x = (int)($cx - $r * .8 + $i * $r * .8);
        imagesetthickness($im, max(2, (int)($r / 6)));
        imageline($im, $x, (int)($cy + $r * .2), (int)($x - $r * .18), (int)($cy + $r * .95), $c);
    }
    imagesetthickness($im, 1);
}
function ciRaio($im, int $cx, int $cy, int $r): void {
    $c = ciCor($im, '#FBBF24');
    $p = [$cx + $r * .15, $cy + $r * .1, $cx - $r * .35, $cy + $r * .95, $cx - $r * .02, $cy + $r * .95,
          $cx - $r * .25, $cy + $r * 1.7, $cx + $r * .5, $cy + $r * .75, $cx + $r * .1, $cy + $r * .75];
    imagefilledpolygon($im, array_map('intval', $p), $c);
}
/* escolhe o desenho pelo código do Open-Meteo */
function ciIcone($im, int $codigo, int $cx, int $cy, int $r): void {
    if (in_array($codigo, [0, 1], true)) { ciSol($im, $cx, $cy, (int)($r * .55)); return; }
    if ($codigo === 2) { ciSol($im, (int)($cx - $r * .45), (int)($cy - $r * .35), (int)($r * .42)); ciNuvem($im, (int)($cx + $r * .15), (int)($cy + $r * .2), (int)($r * .6)); return; }
    if ($codigo === 3 || $codigo === 45 || $codigo === 48) { ciNuvem($im, $cx, $cy, (int)($r * .7), '#E5E7EB'); return; }
    if (in_array($codigo, [95, 96, 99], true)) { ciNuvem($im, $cx, (int)($cy - $r * .25), (int)($r * .62), '#CBD5E1'); ciRaio($im, $cx, (int)($cy + $r * .1), (int)($r * .5)); return; }
    /* garoa / chuva / pancadas */
    ciNuvem($im, $cx, (int)($cy - $r * .25), (int)($r * .62), '#E5E7EB');
    ciGotas($im, $cx, (int)($cy + $r * .2), (int)($r * .6), $codigo >= 80 ? 3 : 2);
}

/* ---------- o cartão ---------- */
function climaImagemGerar(array $a, bool $cache = true): array {
    if (!function_exists('imagecreatetruecolor')) return ['ok' => false, 'erro' => 'GD não disponível'];
    if (!is_dir(CLIMA_IMG_DIR)) @mkdir(CLIMA_IMG_DIR, 0775, true);

    $chuva = !empty($a['vai_chover']);
    $chave = md5(json_encode([$a['cidade'] ?? '', $a['agora']['temperatura'] ?? '', $a['agora']['codigo'] ?? '',
                              $chuva, $a['quando'] ?? '', $a['pico'] ?? '', date('Y-m-d H')]));
    $arquivo = 'clima-' . substr($chave, 0, 12) . '.jpg';
    $caminho = CLIMA_IMG_DIR . '/' . $arquivo;
    if ($cache && is_file($caminho) && filemtime($caminho) > time() - 1800) {
        return ['ok' => true, 'arquivo' => $arquivo, 'caminho' => $caminho, 'url' => CLIMA_IMG_URL . $arquivo, 'cache' => true];
    }

    $L = 1080; $A = 1350; $m = 72;
    $im = imagecreatetruecolor($L, $A);
    imagealphablending($im, true); imagesavealpha($im, false);
    $chuva ? ciDegrade($im, 0, 0, $L, $A, '#0F172A', '#475569') : ciDegrade($im, 0, 0, $L, $A, '#1E40AF', '#3B82F6');
    imagefilledellipse($im, $L - 40, 120, 460, 460, ciCor($im, '#FFFFFF', .94));

    $branco = ciCor($im, '#FFFFFF');
    $claro  = ciCor($im, '#DBEAFE');
    $ag = $a['agora'] ?? [];
    $codAgora = (int)($ag['codigo'] ?? 0);
    /* na hora da chuva o ícone do topo mostra a condição que vem, não a de agora */
    $codChuva = (int)($a['codigo_chuva'] ?? ($chuva ? 80 : $codAgora));

    /* ---- cabeçalho ---- */
    ciTexto($im, 25, $m, 112, $claro, mb_strtoupper((string)($a['cidade'] ?? 'Maceió'), 'UTF-8'), true);

    /* ícone grande à esquerda, temperatura à direita (sem sobrepor) */
    ciIcone($im, $codAgora, $m + 92, 248, 96);
    $tempTxt = (string)($ag['temperatura'] ?? '--') . '°';
    ciTexto($im, 122, $m + 250, 292, $branco, $tempTxt, true);
    ciTexto($im, 31, $m + 254, 348, $claro, ucfirst((string)($ag['descricao'] ?? '')));

    /* ---- pílulas ---- */
    $x = $m; $y = 396;
    $pilulas = ['sensação ' . ($ag['sensacao'] ?? '-') . '°', 'umidade ' . ($ag['umidade'] ?? '-') . '%',
                'vento ' . ($ag['vento'] ?? '-') . ' km/h'];
    if (isset($a['hoje']['max'])) $pilulas[] = 'máx ' . $a['hoje']['max'] . '° · mín ' . $a['hoje']['min'] . '°';
    foreach ($pilulas as $txt) {
        $l = ciLargura(22, $txt) + 40;
        if ($x + $l > $L - $m) { $x = $m; $y += 62; }
        ciPilula($im, $x, $y, $txt);
        $x += $l + 14;
    }

    /* ---- veredito ---- */
    $cy = $y + 92;
    $titulo = $chuva
        ? 'Vai chover ' . (string)($a['janela'] ?? $a['quando'] ?? '')
        : 'Sem chuva à vista';
    /* destaque objetivo: intensidade, pico e volume */
    $detalhe = $chuva
        ? trim(ucfirst((string)($a['intensidade'] ?? 'chuva')) . ' · pico de ' . (int)($a['pico'] ?? 0) . '% · '
               . str_replace('.', ',', (string)round((float)($a['mm'] ?? 0), 1)) . ' mm')
        : 'Maior chance nas próximas ' . (int)($a['horas'] ?? 0) . 'h: ' . (int)($a['pico'] ?? 0) . '%';
    $tituloL = ciQuebrar(38, $titulo, $L - $m * 2 - 210, true);
    $altura = 76 + count($tituloL) * 50;
    ciCaixa($im, $m, $cy, $L - $m * 2, $altura, 28, .18);
    ciIcone($im, $chuva ? $codChuva : 0, $m + 82, $cy + (int)($altura / 2), 56);
    $ly = $cy + 66;
    foreach ($tituloL as $ln) { ciTexto($im, 38, $m + 168, $ly, $branco, $ln, true); $ly += 50; }
    ciTexto($im, 28, $m + 168, $ly + 4, $claro, $detalhe);

    /* ---- próximas horas ---- */
    $fy = $cy + $altura + 66;
    ciTexto($im, 24, $m, $fy, $claro, 'CHANCE DE CHUVA NAS PRÓXIMAS HORAS', true);
    $horas = array_slice($a['lista_horas'] ?? [], 0, 6);
    if ($horas) {
        $minimo = (int)($a['minimo'] ?? 60);
        $larg = (int)(($L - $m * 2 - 5 * 14) / 6);
        $bx = $m; $by = $fy + 30; $altB = 300; $barraMax = 62;
        foreach ($horas as $h) {
            $chance = max(0, min(100, (int)$h['chance_chuva']));
            $molhada = $chance >= $minimo || $h['chuva_mm'] >= 0.5;
            ciCaixa($im, $bx, $by, $larg, $altB, 24, $molhada ? .30 : .14);
            $meio = $bx + (int)($larg / 2);
            ciTexto($im, 24, $meio, $by + 46, $branco, substr((string)$h['hora'], 0, 2) . 'h', true, 'centro');
            ciIcone($im, (int)$h['codigo'], $meio, $by + 104, 42);
            ciTexto($im, 28, $meio, $by + 178, $branco, $h['temperatura'] . '°', true, 'centro');
            /* barra: escala fixa de 0 a 100% (não relativa) com trilho visível */
            $base = $by + 246;
            ciCaixa($im, $meio - 13, $base - $barraMax, 26, $barraMax, 12, .12);
            $alt = (int)round(($chance / 100) * $barraMax);
            if ($alt > 0) ciCaixa($im, $meio - 13, $base - $alt, 26, $alt, min(12, max(4, (int)($alt / 2))), $molhada ? .95 : .55);
            ciTexto($im, 22, $meio, $by + 284, $claro, $chance . '%', true, 'centro');
            $bx += $larg + 14;
        }
    }

    ciTexto($im, 22, $m, $A - 58, ciCor($im, '#FFFFFF', .35),
            'Lembretes Alequizão · previsão Open-Meteo · ' . date('d/m/Y H:i'));

    /* JPEG de propósito: o Direct do Instagram recusa PNG vindo por URL */
    imagejpeg($im, $caminho, 88);
    imagedestroy($im);
    @chmod($caminho, 0644);
    return ['ok' => true, 'arquivo' => $arquivo, 'caminho' => $caminho, 'url' => CLIMA_IMG_URL . $arquivo,
            'tamanho' => (int)@filesize($caminho)];
}

/* Descreve a imagem no formato que as funções de envio esperam. */
function climaImagemMidia(array $img): array {
    return ['arquivo' => 'clima/' . $img['arquivo'], 'nome' => 'previsao-do-tempo.jpg',
            'mime' => 'image/jpeg', 'tipo' => 'image', 'tamanho' => (int)($img['tamanho'] ?? 0),
            'url' => $img['url']];
}

/* Apaga cartões com mais de 2 dias. */
function climaImagemLimpar(): void {
    foreach (glob(CLIMA_IMG_DIR . '/clima-*.{jpg,png}', GLOB_BRACE) ?: [] as $f)
        if (filemtime($f) < time() - 172800) @unlink($f);
}
