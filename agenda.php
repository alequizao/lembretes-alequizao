<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Regras de agendamento e disparo dos lembretes. */
require_once __DIR__ . '/midias.php';

/* Próxima ocorrência depois de $base, respeitando a repetição. Devolve null quando acaba. */
function proximaOcorrencia(array $l, ?string $base = null): ?string {
    $t = strtotime($base ?: $l['proxima_em'] ?: $l['quando']);
    switch ($l['repeticao']) {
        case 'horaria':   $t = strtotime('+1 hour', $t); break;
        case 'diaria':    $t = strtotime('+1 day', $t); break;
        case 'semanal':   $t = strtotime('+7 days', $t); break;
        case 'quinzenal': $t = strtotime('+14 days', $t); break;
        case 'mensal':    $t = strtotime('+1 month', $t); break;
        case 'anual':     $t = strtotime('+1 year', $t); break;
        case 'dias':      $d = max(1, (int)$l['intervalo_dias']); $t = strtotime("+$d days", $t); break;
        default:          return null;
    }
    if (!empty($l['repetir_ate']) && $t > strtotime($l['repetir_ate'] . ' 23:59:59')) return null;
    return date('Y-m-d H:i:s', $t);
}

/* Se o horário já passou (servidor parado, lembrete antigo), avança até o futuro. */
function alinharFuturo(array $l, string $proxima): ?string {
    $limite = 0;
    while ($proxima !== null && strtotime($proxima) <= time() && $limite++ < 2000) {
        $proxima = proximaOcorrencia($l, $proxima);
    }
    return $proxima;
}

function contatosDoLembrete(int $id): array {
    $st = db()->prepare('SELECT c.* FROM contatos c JOIN lembrete_contatos lc ON lc.contato_id = c.id WHERE lc.lembrete_id = ? AND c.ativo = 1');
    $st->execute([$id]);
    return $st->fetchAll();
}

/* Envia e, se falhar, tenta de novo (até $max tentativas) antes de desistir. */
function enviarComTentativas(string $canal, array $contato, string $titulo, string $texto, array $ctx = [], array $anexos = [], int $max = 3): array {
    $r = ['ok' => false, 'detalhe' => 'não enviado'];
    for ($n = 1; $n <= max(1, $max); $n++) {
        $r = enviarPorCanal($canal, $contato, $titulo, $texto, $ctx, $anexos);
        $r['tentativas'] = $n;
        if (!empty($r['ok'])) return $r;
        /* destino ausente ou inválido não melhora tentando de novo */
        if (stripos((string)($r['detalhe'] ?? ''), 'sem ') === 0) return $r;
        if ($n < $max) sleep(2);
    }
    return $r;
}

/* Dispara um lembrete para todos os contatos e canais. Devolve o resumo. */
/* Texto amigável para a antecedência (minutos -> "2 horas", "1 dia"). */
function antecedenciaTexto(int $min): string {
    if ($min <= 0) return '';
    if ($min < 60) return $min . ($min == 1 ? ' minuto' : ' minutos');
    if ($min < 1440) { $h = round($min / 60); return $h . ($h == 1 ? ' hora' : ' horas'); }
    $d = round($min / 1440); return $d . ($d == 1 ? ' dia' : ' dias');
}

function dispararLembrete(array $l, bool $teste = false, bool $previo = false): array {
    $canais = array_values(array_filter(explode(',', $l['canais'])));
    $contatos = contatosDoLembrete((int)$l['id']);
    $res = ['enviados' => 0, 'erros' => 0, 'itens' => []];
    if (!$contatos) {
        $res['itens'][] = ['canal' => '-', 'contato' => '-', 'ok' => false, 'detalhe' => 'Nenhum contato ativo vinculado'];
        $res['erros']++;
        return $res;
    }
    $proximo = proximaOcorrencia($l);
    $instanciaWa = whatsappInstanciaDe(isset($l['whatsapp_id']) ? (int)$l['whatsapp_id'] : null);
    $midias = $previo ? [] : midiasDoLembrete((int)$l['id']);   /* o aviso antecipado vai só em texto */
    $anexosEmail = $midias ? anexosParaEmail($midias) : [];
    $linksEmail = [];
    foreach ($midias as $m) {
        $caminho = MIDIA_DIR . '/' . $m['arquivo'];
        if (is_file($caminho) && filesize($caminho) > EMAIL_ANEXO_MAX) {
            $linksEmail[] = ['nome' => $m['nome'], 'url' => $m['url'], 'tamanho' => tamanhoLegivel((int)$m['tamanho'])];
        }
    }
    foreach ($contatos as $c) {
        foreach ($canais as $canal) {
            $texto  = aplicarVariaveis($l['mensagem'], $l, $c, $canal, $proximo);
            $titulo = aplicarVariaveis($l['titulo'], $l, $c, $canal, $proximo);
            if ($previo) {
                $falta  = antecedenciaTexto((int)($l['antecedencia'] ?? 0));
                $qPrevio = strtotime($l['proxima_em'] ?: $l['quando']);
                $titulo = 'Em breve: ' . $titulo;
                $texto  = '⏰ Faltam ' . $falta . ' para: ' . trim(aplicarVariaveis($l['titulo'], $l, $c, $canal, $proximo))
                        . ' (' . date('d/m/Y', $qPrevio) . ' às ' . date('H:i', $qPrevio) . ")\n\n" . $texto;
            }
                    $ctx = ['quando' => $l['proxima_em'] ?: $l['quando'], 'repeticao' => $l['repeticao'], 'links' => $linksEmail,
                    'instancia' => $instanciaWa];
            $r = enviarComTentativas($canal, $c, $titulo, $texto, $ctx, $canal === 'email' ? $anexosEmail : [], $teste ? 1 : 3);
            registrarEnvio((int)$l['id'], (int)$c['id'], $canal, $r, $texto, $teste, $previo, (int)($r['tentativas'] ?? 1));
            $r['ok'] ? $res['enviados']++ : $res['erros']++;
            $res['itens'][] = ['canal' => $canal, 'contato' => $c['nome'], 'ok' => (bool)$r['ok'], 'detalhe' => (string)($r['detalhe'] ?? '')];

            /* anexos: o e-mail já foi junto; WhatsApp e Direct vão em mensagens separadas */
            if ($r['ok'] && $midias && $canal !== 'email') {
                foreach ($midias as $m) {
                    $rm = $canal === 'whatsapp' ? enviarWhatsappMidia((string)($c['whatsapp'] ?? ''), $m, '', $instanciaWa) : enviarDirectMidia($c, $m);
                    registrarEnvio((int)$l['id'], (int)$c['id'], $canal, $rm, '[anexo] ' . $m['nome'], $teste);
                    $rm['ok'] ? $res['enviados']++ : $res['erros']++;
                    $res['itens'][] = ['canal' => $canal, 'contato' => $c['nome'] . ' · ' . $m['nome'],
                                       'ok' => (bool)$rm['ok'], 'detalhe' => (string)($rm['detalhe'] ?? '')];
                }
            }
        }
    }
    return $res;
}

/* Avalia a condição de clima do lembrete. Sem condição, sempre libera o envio. */
function condicaoDoLembrete(array $l): array {
    $tipo = (string)($l['condicao_tipo'] ?? 'nenhuma');
    if ($tipo === '' || $tipo === 'nenhuma') return ['atende' => true, 'resumo' => '', 'ok' => true];
    require_once __DIR__ . '/clima.php';

    /* antirrepetição: no máximo um aviso por dia para o mesmo lembrete */
    if (!empty($l['condicao_antirrepete']) && !empty($l['condicao_ultimo_ok'])
        && $l['condicao_ultimo_ok'] === date('Y-m-d')) {
        return ['atende' => false, 'ok' => true, 'resumo' => 'já avisei hoje (antirrepetição ligada)'];
    }
    $r = climaAvaliar($tipo, (float)($l['condicao_valor'] ?? 0), (int)($l['condicao_horas'] ?? 6),
                      (string)($l['condicao_cidade'] ?? ''));
    return ['atende' => !empty($r['atende']), 'ok' => !empty($r['ok']), 'resumo' => (string)$r['resumo']];
}

/* Processa tudo que está vencido. Chamado pelo cron a cada minuto. */
function processarFila(): array {
    $db = db();
    /* 1) avisos antecipados — o lembrete ainda não venceu, mas já entrou na janela de antecedência */
    $previos = $db->query("SELECT * FROM lembretes WHERE status = 'ativo' AND antecedencia > 0 AND condicao_tipo = 'nenhuma' AND proxima_em IS NOT NULL
        AND proxima_em > NOW() AND DATE_SUB(proxima_em, INTERVAL antecedencia MINUTE) <= NOW()
        AND (aviso_em IS NULL OR aviso_em <> proxima_em) ORDER BY proxima_em LIMIT 50")->fetchAll();
    foreach ($previos as $l) {
        $r = dispararLembrete($l, false, true);
        $db->prepare('UPDATE lembretes SET aviso_em = ? WHERE id = ?')->execute([$l['proxima_em'], $l['id']]);
        logar('aviso-previo', "#{$l['id']} {$l['titulo']}: {$r['enviados']} enviados, {$r['erros']} erros");
    }
    /* 2) fila normal */
    $st = $db->query("SELECT * FROM lembretes WHERE status = 'ativo' AND proxima_em IS NOT NULL AND proxima_em <= NOW() ORDER BY proxima_em LIMIT 50");
    $feitos = [];
    foreach ($st->fetchAll() as $l) {
        $cond = condicaoDoLembrete($l);
        if (!$cond['atende']) {
            /* condição não bateu: não envia, só reagenda a próxima verificação */
            $proxima = proximaOcorrencia($l);
            if ($proxima !== null) $proxima = alinharFuturo($l, $proxima);
            $db->prepare('UPDATE lembretes SET proxima_em = ?, status = ? WHERE id = ?')
               ->execute([$proxima, $proxima === null ? 'concluido' : 'ativo', $l['id']]);
            logar('condicao', "#{$l['id']} {$l['titulo']}: não enviei — {$cond['resumo']}");
            continue;
        }
        $r = dispararLembrete($l);
        if ($r['enviados'] > 0 && !empty($l['condicao_tipo']) && $l['condicao_tipo'] !== 'nenhuma') {
            $db->prepare('UPDATE lembretes SET condicao_ultimo_ok = CURDATE() WHERE id = ?')->execute([$l['id']]);
            logar('condicao', "#{$l['id']} {$l['titulo']}: enviei — {$cond['resumo']}");
        }
        $proxima = proximaOcorrencia($l);
        if ($proxima !== null) $proxima = alinharFuturo($l, $proxima);
        $up = $db->prepare('UPDATE lembretes SET ultima_em = NOW(), total_envios = total_envios + ?, proxima_em = ?, status = ? WHERE id = ?');
        $up->execute([$r['enviados'], $proxima, $proxima === null ? 'concluido' : 'ativo', $l['id']]);
        logar('disparo', "#{$l['id']} {$l['titulo']}: {$r['enviados']} enviados, {$r['erros']} erros");
        $feitos[] = ['id' => (int)$l['id'], 'titulo' => $l['titulo']] + $r;
    }
    return $feitos;
}

/* Situação do cron: intervalo pedido, intervalo aplicado no sistema e última execução. */
function cronEstado(): array {
    $pedido = (int)(cfg('cron_intervalo', 1) ?: 1);
    $aplicado = null;
    $estado = @json_decode((string)@file_get_contents(__DIR__ . '/cron-estado.json'), true);
    if (is_array($estado) && !empty($estado['aplicado'])) $aplicado = (int)$estado['aplicado'];
    $ult = db()->query("SELECT criado_em FROM log WHERE tipo IN ('disparo','batida') ORDER BY id DESC LIMIT 1")->fetchColumn();
    return [
        'intervalo' => $pedido,
        'aplicado' => $aplicado,
        'sincronizado' => $aplicado !== null && $aplicado === $pedido,
        'ultima_batida' => cfg('cron_ultima', null),
        'ultimo_disparo' => $ult ?: null,
    ];
}
