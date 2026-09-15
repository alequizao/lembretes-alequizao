<?php
/* Entrega o arquivo de uma mensagem (foto, vídeo, áudio, documento) para a caixa de mensagens.
   Uso: midia.php?tipo=whatsapp&id=1&m=<id da mensagem>[&baixar=1] */
require_once __DIR__ . '/mensagens.php';

exigirDono();   /* mesma trava da caixa de mensagens */

$tipo = (string)($_GET['tipo'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$m    = (string)($_GET['m'] ?? '');

if (!in_array($tipo, ['whatsapp', 'direct'], true) || $m === '') {
    http_response_code(400);
    exit('pedido inválido');
}
$arq = msgBaixarMidia($tipo, $id, $m);
if (!$arq) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não consegui baixar este arquivo. Mídias antigas do WhatsApp podem não estar mais no servidor.');
}
$mime = preg_replace('/[^\w\/\.\+\-]/', '', explode(';', $arq['mime'])[0]) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($arq['bytes']));
header('Cache-Control: private, max-age=86400');
if (!empty($_GET['baixar'])) {
    $nome = preg_replace('/[^\w\.\- ]/u', '', $arq['nome']) ?: 'arquivo';
    header('Content-Disposition: attachment; filename="' . $nome . '"');
}
echo $arq['bytes'];
