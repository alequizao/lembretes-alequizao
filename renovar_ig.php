<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Renova os tokens de longa duração do Instagram antes que expirem.
   Rodar pelo cron todo dia: /www/server/php/83/bin/php renovar_ig.php [--forcar] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente CLI'); }
require_once __DIR__ . '/canais.php';

$forcar = in_array('--forcar', $argv, true);
$db = dbIg();
if (!$db) { fwrite(STDERR, "sem acesso ao banco do Instagram\n"); exit(1); }

/* A Meta exige token com mais de 24h de vida; renovar cedo é permitido e reinicia os 60 dias. */
$LIMITE_DIAS = 40;   // renova quando faltarem 40 dias ou menos (a Meta exige token com +24h de vida)
$contas = $db->query('SELECT id, nome, ig_username, access_token, token_expira_em FROM clientes WHERE access_token IS NOT NULL AND access_token <> ""')->fetchAll();

foreach ($contas as $c) {
    $rotulo = '@' . ($c['ig_username'] ?: $c['nome']);
    $dias = $c['token_expira_em'] ? (int)floor((strtotime($c['token_expira_em']) - time()) / 86400) : 0;
    if (!$forcar && $c['token_expira_em'] && $dias > $LIMITE_DIAS) {
        echo date('[Y-m-d H:i] ') . "$rotulo: faltam $dias dias, nada a fazer\n";
        continue;
    }
    $r = http_json('GET', 'https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' . urlencode($c['access_token']), null, [], 25);
    $novo = $r['dados']['access_token'] ?? '';
    if ($novo === '') {
        $erro = $r['dados']['error']['message'] ?? ($r['erro'] ?: ('HTTP ' . $r['codigo']));
        echo date('[Y-m-d H:i] ') . "$rotulo: FALHOU — $erro\n";
        logar('ig-token', "$rotulo falhou ao renovar: $erro");
        cfgSet('ig_renov_erro_' . $c['id'], agora() . ' | ' . $erro);
        continue;
    }
    $segundos = (int)($r['dados']['expires_in'] ?? 60 * 86400);
    $expira = date('Y-m-d H:i:s', time() + $segundos);
    $db->prepare('UPDATE clientes SET access_token = ?, token_expira_em = ? WHERE id = ?')->execute([$novo, $expira, $c['id']]);
    cfgSet('ig_check_' . $c['id'], '');                       // invalida o cache do diagnóstico
    cfgSet('ig_renov_ok_' . $c['id'], agora());
    cfgSet('ig_renov_erro_' . $c['id'], '');
    echo date('[Y-m-d H:i] ') . "$rotulo: RENOVADO até $expira (" . round($segundos / 86400) . " dias)\n";
    logar('ig-token', "$rotulo renovado até $expira");
}
