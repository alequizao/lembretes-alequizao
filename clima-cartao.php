<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Mostra o cartão da previsão em imagem (o mesmo que vai nos avisos).
   Uso: clima-cartao.php[?cidade=Maceió][&exemplo=1] */
require_once __DIR__ . '/alertachuva.php';
require_once __DIR__ . '/climaimagem.php';
sessaoIniciar();
if (!usuarioAtual()) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Entre no painel para ver o cartão.'); }

$a = chuvaAnalisar((string)($_GET['cidade'] ?? '') ?: null);
if (empty($a['ok'])) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit((string)($a['erro'] ?? 'previsão indisponível')); }
/* sem chuva na previsão: mostra como ficaria, para conferir o visual */
if (!$a['vai_chover'] && !empty($_GET['exemplo'])) {
    $a = array_merge($a, ['vai_chover' => true, 'quando' => 'por volta das 15:00', 'hora' => '15:00',
        'descricao' => 'pancadas de chuva', 'pico' => 80, 'mm' => 6.2, 'codigo_chuva' => 80,
        'resumo' => 'Pancadas de chuva por volta das 15:00 — pico de 80% e 6,2 mm nas próximas ' . $a['horas'] . 'h em ' . $a['cidade']]);
}
$img = climaImagemGerar($a);
if (empty($img['ok'])) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit((string)($img['erro'] ?? 'falha ao gerar')); }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=600');
readfile($img['caminho']);
