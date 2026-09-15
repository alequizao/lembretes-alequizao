<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Roda a cada minuto pelo cron: dispara os lembretes vencidos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente CLI'); }
require_once __DIR__ . '/agenda.php';
$trava = fopen('/tmp/lembretes-cron.lock', 'c');
if (!flock($trava, LOCK_EX | LOCK_NB)) exit(0);
try {
    cfgSet('cron_ultima', agora());
    $feitos = processarFila();
    foreach ($feitos as $f) echo date('[H:i:s] ') . "#{$f['id']} {$f['titulo']} — {$f['enviados']} enviados / {$f['erros']} erros\n";
} catch (Throwable $e) {
    logar('erro-cron', $e->getMessage());
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
}
