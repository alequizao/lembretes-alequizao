<?php
/* Alerta automático de chuva.
   Vigia a previsão hora a hora da cidade configurada (padrão: Maceió) e, quando a chuva
   estiver chegando, avisa sozinho todos os contatos ativos — sem precisar criar lembrete.
   Uso pelo cron:  php alertachuva.php            (respeita as configurações)
                   php alertachuva.php --forcar   (ignora horário e antirrepetição)
                   php alertachuva.php --teste    (não envia nada, só mostra o diagnóstico) */
require_once __DIR__ . '/agenda.php';
require_once __DIR__ . '/clima.php';

function chuvaNum($v): string { return str_replace('.', ',', (string)round((float)$v, 1)); }

const CHUVA_PADROES = [
    'chuva_ativo'      => '1',
    'chuva_cidade'     => 'Maceió',
    'chuva_minimo'     => '60',    /* % de chance para considerar que vai chover */
    'chuva_horas'      => '4',     /* olhar as próximas N horas */
    'chuva_intervalo'  => '6',     /* horas de silêncio entre um aviso e o próximo */
    'chuva_inicio'     => '06:00', /* não incomodar antes disso */
    'chuva_fim'        => '22:00', /* nem depois disso */
    'chuva_tempestade' => '1',     /* tempestade avisa mesmo fora do horário */
    'chuva_passou'     => '1',     /* avisar também quando a chuva passar */
    'chuva_imagem'     => '1',     /* mandar junto o cartão da previsão */
    'chuva_titulo'     => '🌧️ Vai chover em {cidade}',
    'chuva_mensagem'   => "{saudacao}, {primeiro_nome}!\n\nA previsão indica *chuva em {cidade}* {quando_chuva}.\n\n• Condição: {condicao_chuva}\n• Chance: {chance_chuva_alerta}\n• Volume previsto: {chuva_mm_alerta}\n• Próximas horas: {clima_proximas_horas}\n\nSe for sair, leve guarda-chuva. ☂️",
    'chuva_titulo_fim' => '🌤️ A chuva passou em {cidade}',
    'chuva_msg_fim'    => "{primeiro_nome}, a previsão de chuva para as próximas horas em {cidade} passou.\n\nAgora: {clima}",
];

function chuvaCfg(string $chave) {
    $v = cfg($chave, null);
    return ($v === null || $v === '') ? (CHUVA_PADROES[$chave] ?? '') : $v;
}
function chuvaConfig(): array {
    $c = [];
    foreach (CHUVA_PADROES as $k => $_) $c[$k] = chuvaCfg($k);
    return $c;
}

/* Olha a previsão hora a hora e diz se (e quando) vem chuva. */
function chuvaAnalisar(?string $cidade = null, ?int $minimo = null, ?int $horas = null): array {
    $cidade = trim((string)($cidade ?? chuvaCfg('chuva_cidade'))) ?: 'Maceió';
    $minimo = (int)($minimo ?? chuvaCfg('chuva_minimo'));
    $horas  = max(1, min(24, (int)($horas ?? chuvaCfg('chuva_horas'))));

    $p = climaPrevisao($cidade, 2);
    if (empty($p['ok'])) return ['ok' => false, 'erro' => (string)($p['erro'] ?? 'previsão indisponível')];

    $janelaHoras = array_slice($p['horas'] ?? [], 0, $horas);
    $pico = 0; $mm = 0.0; $primeira = null; $tempestade = false; $descricao = ''; $icone = '🌧️'; $codigoChuva = 0;
    foreach ($janelaHoras as $h) {
        $mm += (float)$h['chuva_mm'];
        if ($h['chance_chuva'] > $pico) $pico = (int)$h['chance_chuva'];
        $chove = $h['chance_chuva'] >= $minimo || $h['chuva_mm'] >= 0.5;
        if ($chove && $primeira === null) { $primeira = $h; $descricao = $h['descricao']; $icone = $h['icone']; $codigoChuva = (int)$h['codigo']; }
        if (in_array((int)$h['codigo'], CLIMA_CODIGOS_TEMPESTADE, true)) $tempestade = true;
    }
    $vai = $primeira !== null;
    $quando = ''; $janela = ''; $ultimaChuva = null;
    if ($vai) {
        $hIni = strtotime($primeira['em']);
        $faltam = max(0, (int)round(($hIni - time()) / 60));
        $quando = $faltam <= 60 ? 'na próxima hora (por volta das ' . $primeira['hora'] . ')'
                : ('por volta das ' . $primeira['hora'] . ($primeira['data'] !== date('Y-m-d') ? ' de amanhã' : ''));
        /* até que horas a chuva deve durar (horas seguidas ainda molhadas) */
        $vendo = false;
        foreach ($janelaHoras as $h) {
            $molhada = $h['chance_chuva'] >= $minimo || $h['chuva_mm'] >= 0.5;
            if ($h['em'] === $primeira['em']) $vendo = true;
            if (!$vendo) continue;
            if ($molhada) $ultimaChuva = $h; elseif ($ultimaChuva) break;
        }
        $fimTxt = $ultimaChuva && $ultimaChuva['em'] !== $primeira['em']
                ? date('H\\h', strtotime($ultimaChuva['em']) + 3600) : '';
        $janela = $fimTxt ? ('das ' . substr($primeira['hora'], 0, 2) . 'h até ' . $fimTxt)
                          : ('por volta das ' . $primeira['hora']);
    }
    /* intensidade pelo volume previsto na janela */
    $intensidade = $mm >= 20 ? 'chuva forte' : ($mm >= 5 ? 'chuva moderada' : ($mm > 0 ? 'chuva fraca' : 'chuva passageira'));
    if ($tempestade) $intensidade = 'tempestade';
    return [
        'ok' => true, 'vai_chover' => $vai, 'cidade' => $p['cidade'], 'cidade_busca' => $cidade,
        'minimo' => $minimo, 'horas' => $horas,
        'quando' => $quando, 'hora' => $primeira['hora'] ?? '', 'em' => $primeira['em'] ?? '',
        'janela' => $janela, 'fim' => $ultimaChuva['hora'] ?? '', 'intensidade' => $intensidade,
        'pico' => $pico, 'mm' => round($mm, 1), 'descricao' => $descricao, 'icone' => $icone, 'codigo_chuva' => $codigoChuva,
        'tempestade' => $tempestade,
        'evento' => $vai ? date('Y-m-d H', strtotime($primeira['em'])) : '',
        'resumo' => $vai
            ? ($icone . ' ' . ucfirst($descricao ?: 'chuva') . ' ' . $quando . ' — pico de ' . $pico . '% e ' . chuvaNum($mm) . ' mm nas próximas ' . $horas . 'h em ' . $p['cidade'])
            : ('sem chuva prevista para as próximas ' . $horas . 'h em ' . $p['cidade'] . ' (maior chance: ' . $pico . '%, mínimo ' . $minimo . '%)'),
        'agora' => $p['agora'],
        'proximas' => climaTextoHoras($p['horas'] ?? [], min(6, $horas)),
        /* para o gráfico da tela: próximas 12 horas cruas */
        'lista_horas' => array_slice($p['horas'] ?? [], 0, 12),
        'hoje' => $p['dias'][0] ?? [],
        'amanha' => $p['dias'][1] ?? [],
    ];
}

/* Estamos dentro do horário em que é permitido avisar? */
function chuvaNoHorario(): bool {
    $ini = (string)chuvaCfg('chuva_inicio') ?: '06:00';
    $fim = (string)chuvaCfg('chuva_fim') ?: '22:00';
    $agora = date('H:i');
    return $ini <= $fim ? ($agora >= $ini && $agora <= $fim) : ($agora >= $ini || $agora <= $fim);
}

/* Quem recebe o alerta: contatos ativos que não pediram para sair.
   Com $cidade, devolve só quem é daquela cidade (a do cadastro ou, se vazia, a cidade padrão). */
function chuvaDestinatarios(?string $cidade = null): array {
    $todos = db()->query('SELECT * FROM contatos WHERE ativo = 1 AND alerta_chuva = 1 ORDER BY nome')->fetchAll();
    if ($cidade === null) return $todos;
    $padrao = chuvaNormalizar((string)chuvaCfg('chuva_cidade'));
    $alvo = chuvaNormalizar($cidade);
    return array_values(array_filter($todos, function ($c) use ($alvo, $padrao) {
        $dele = chuvaNormalizar((string)($c['cidade'] ?? ''));
        return ($dele === '' ? $padrao : $dele) === $alvo;
    }));
}

/* Nome de cidade comparável (sem acento, minúsculo). */
function chuvaNormalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
                      'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
}

/* Todas as cidades que precisam ser vigiadas: a padrão + as dos contatos. */
function chuvaCidades(): array {
    $cidades = [chuvaCfg('chuva_cidade')];
    foreach (chuvaDestinatarios() as $c) {
        $dele = trim((string)($c['cidade'] ?? ''));
        if ($dele !== '') $cidades[] = $dele;
    }
    $vistas = []; $saida = [];
    foreach ($cidades as $cid) {
        $k = chuvaNormalizar((string)$cid);
        if ($k === '' || isset($vistas[$k])) continue;
        $vistas[$k] = true; $saida[] = (string)$cid;
    }
    return $saida;
}

/* Canais que cada contato consegue receber. */
function chuvaCanaisDo(array $c): array {
    $canais = [];
    if (trim((string)($c['whatsapp'] ?? '')) !== '') $canais[] = 'whatsapp';
    if (trim((string)($c['email'] ?? '')) !== '')    $canais[] = 'email';
    if (trim((string)($c['ig_remetente_id'] ?? '')) !== '' || trim((string)($c['ig_usuario'] ?? '')) !== '') $canais[] = 'direct';
    $permitidos = array_values(array_filter(explode(',', (string)cfg('chuva_canais', 'whatsapp,email,direct'))));
    return $permitidos ? array_values(array_intersect($canais, $permitidos)) : $canais;
}

/* Troca as variáveis do alerta (as específicas da chuva primeiro, depois as do sistema). */
function chuvaTexto(string $modelo, array $a, array $contato, string $canal): string {
    /* As específicas vêm ANTES porque {cidade} tem de ser a cidade da análise —
       se deixar para aplicarVariaveis, ela usa a cidade do cadastro/padrão. */
    $t = strtr($modelo, [
        '{quando_chuva}' => $a['quando'] ?: 'nas próximas horas',
        '{janela_chuva}' => $a['janela'] ?? '',
        '{intensidade_chuva}' => $a['intensidade'] ?? '',
        '{hora_chuva}' => $a['hora'] ?? '',
        '{condicao_chuva}' => trim(($a['icone'] ?? '') . ' ' . ($a['descricao'] ?: 'chuva')),
        '{chance_chuva_alerta}' => ($a['pico'] ?? 0) . '%',
        '{chuva_mm_alerta}' => chuvaNum($a['mm'] ?? 0) . ' mm',
        '{clima_proximas_horas}' => $a['proximas'] ?? '',
        '{cidade}' => $a['cidade'],
    ]);
    /* o resto ({nome}, {saudacao}, {clima}…) na cidade que foi consultada */
    $falso = ['titulo' => 'Alerta de chuva', 'mensagem' => '', 'quando' => agora(), 'proxima_em' => agora(),
              'repeticao' => 'nenhuma', 'intervalo_dias' => 0];
    return aplicarVariaveis($t, $falso, array_merge($contato, ['cidade' => (string)($a['cidade_busca'] ?? '')]), $canal);
}

/* Envia o alerta (ou o aviso de que passou) para todos. */
function chuvaEnviar(array $a, bool $fim = false, bool $teste = false, string $prefixo = '',
                     ?int $soContato = null, ?array $lista = null): array {
    if ($soContato) {
        $st = db()->prepare('SELECT * FROM contatos WHERE id = ?');
        $st->execute([$soContato]);
        $contatos = array_values(array_filter([$st->fetch()]));
    } else {
        $contatos = $lista !== null ? $lista : chuvaDestinatarios();
    }
    $res = ['enviados' => 0, 'erros' => 0, 'itens' => []];
    $titulo  = (string)chuvaCfg($fim ? 'chuva_titulo_fim' : 'chuva_titulo');
    $modelo  = (string)chuvaCfg($fim ? 'chuva_msg_fim' : 'chuva_mensagem');
    $instancia = whatsappInstanciaDe(null);

    /* cartão da previsão (a mesma cara do painel) */
    $midia = null;
    if ((string)chuvaCfg('chuva_imagem') === '1') {
        require_once __DIR__ . '/climaimagem.php';
        $img = climaImagemGerar($a);
        if (!empty($img['ok'])) { $midia = climaImagemMidia($img); climaImagemLimpar(); }
        else logar('chuva', 'não consegui gerar o cartão: ' . (string)($img['erro'] ?? '?'));
    }
    $rotulo = $fim ? 'Alerta de chuva (passou)' : 'Alerta de chuva';
    $anexoEmail = $midia ? [['nome' => $midia['nome'], 'mime' => $midia['mime'],
                             'conteudo' => (string)@file_get_contents(MIDIA_DIR . '/' . $midia['arquivo'])]] : [];

    foreach ($contatos as $c) {
        foreach (chuvaCanaisDo($c) as $canal) {
            $tit = $prefixo . chuvaTexto($titulo, $a, $c, $canal);
            $txt = chuvaTexto($modelo, $a, $c, $canal);
            $anota = function (array $r, string $sufixo = '') use (&$res, $c, $canal) {
                $r['ok'] ? $res['enviados']++ : $res['erros']++;
                $res['itens'][] = ['canal' => $canal, 'contato' => $c['nome'] . $sufixo, 'ok' => (bool)$r['ok'],
                                   'detalhe' => (string)($r['detalhe'] ?? '')];
            };
            if ($midia && $canal === 'whatsapp') {
                /* no WhatsApp a imagem vai com o texto como legenda: uma mensagem só */
                $r = enviarWhatsappMidia((string)($c['whatsapp'] ?? ''), $midia, '*' . $tit . "*\n\n" . $txt, $instancia);
                registrarEnvio(null, (int)$c['id'], $canal, $r, $txt, $teste, false, 1, $rotulo);
                $anota($r);
                continue;
            }
            $r = enviarComTentativas($canal, $c, $tit, $txt,
                    ['quando' => agora(), 'repeticao' => 'nenhuma', 'instancia' => $instancia],
                    $canal === 'email' ? $anexoEmail : [], $teste ? 1 : 2);
            registrarEnvio(null, (int)$c['id'], $canal, $r, $txt, $teste, false, (int)($r['tentativas'] ?? 1), $rotulo);
            $anota($r);
            /* Direct não aceita legenda: manda a imagem logo depois do texto */
            if ($midia && $canal === 'direct' && !empty($r['ok'])) {
                $rm = enviarDirectMidia($c, $midia);
                registrarEnvio(null, (int)$c['id'], $canal, $rm, '[cartão da previsão]', $teste, false, 1, $rotulo . ' · cartão');
                $anota($rm, ' · cartão');
            }
        }
    }
    return $res;
}

/* Rotina do cron: analisa cada cidade vigiada, decide e avisa. */
function chuvaRodar(bool $forcar = false, bool $teste = false): array {
    if ((string)chuvaCfg('chuva_ativo') !== '1' && !$forcar)
        return ['ok' => true, 'acao' => 'desligado', 'resumo' => 'Alerta de chuva está desligado.'];

    $porCidade = [];
    foreach (chuvaCidades() as $cidade) $porCidade[] = chuvaRodarCidade($cidade, $forcar, $teste);

    /* resumo geral: o que aconteceu de mais importante */
    $ordem = ['avisado' => 5, 'piorou' => 4, 'fim' => 3, 'erro' => 2, 'silencio' => 1, 'ja-avisado' => 1, 'fora-do-horario' => 1, 'nada' => 0];
    usort($porCidade, fn($x, $y) => ($ordem[$y['acao']] ?? 0) <=> ($ordem[$x['acao']] ?? 0));
    $p = $porCidade[0] ?? ['ok' => true, 'acao' => 'nada', 'resumo' => 'nenhuma cidade para vigiar'];
    $envio = ['enviados' => 0, 'erros' => 0];
    foreach ($porCidade as $c) {
        $envio['enviados'] += (int)($c['envio']['enviados'] ?? 0);
        $envio['erros'] += (int)($c['envio']['erros'] ?? 0);
    }
    return $p + ['cidades' => $porCidade, 'envio' => $envio['enviados'] || $envio['erros'] ? $envio : ($p['envio'] ?? null)];
}

/* Decide e avisa para UMA cidade. */
function chuvaRodarCidade(string $cidade, bool $forcar = false, bool $teste = false): array {
    $a = chuvaAnalisar($cidade);
    if (empty($a['ok'])) {
        logar('chuva', "[$cidade] previsão indisponível: " . (string)($a['erro'] ?? ''));
        return ['ok' => false, 'acao' => 'erro', 'cidade' => $cidade, 'resumo' => (string)($a['erro'] ?? 'previsão indisponível')];
    }
    $contatos = chuvaDestinatarios($cidade);
    $chave = substr(md5(chuvaNormalizar($cidade)), 0, 10);
    $kEvento = 'chuva_ev_' . $chave; $kEm = 'chuva_em_' . $chave; $kPico = 'chuva_pico_' . $chave;

    $ultimoEvento = (string)cfg($kEvento, '');
    $ultimoEm     = (string)cfg($kEm, '');
    $ultimoPico   = (int)cfg($kPico, 0);
    $intervalo    = max(1, (int)chuvaCfg('chuva_intervalo'));
    $saida = ['ok' => true, 'cidade' => $a['cidade'], 'analise' => $a, 'resumo' => $a['resumo'], 'acao' => 'nada'];

    if (!$contatos && !$forcar) return $saida + ['acao' => 'nada', 'resumo' => 'ninguém para avisar em ' . $a['cidade']];

    if (!$a['vai_chover']) {
        if ($ultimoEvento !== '' && (string)chuvaCfg('chuva_passou') === '1' && (chuvaNoHorario() || $forcar)) {
            $r = chuvaEnviar($a, true, $teste, '', null, $contatos);
            cfgSet($kEvento, ''); cfgSet($kPico, 0);
            logar('chuva', "[{$a['cidade']}] passou — {$r['enviados']} avisos de fim");
            return $saida + ['acao' => 'fim', 'envio' => $r];
        }
        cfgSet($kEvento, ''); cfgSet($kPico, 0);
        return $saida;
    }

    /* a mesma chuva piorou bastante? (subiu 25 pontos ou virou tempestade) → vale um segundo aviso */
    $piorou = $a['evento'] === $ultimoEvento && $ultimoPico > 0
              && ($a['pico'] >= $ultimoPico + 25 || ($a['tempestade'] && $ultimoPico < 100));
    $prefixo = '';

    if (!$forcar) {
        if ($a['evento'] === $ultimoEvento && !$piorou)
            return $saida + ['acao' => 'ja-avisado', 'resumo' => 'Já avisei desta chuva em ' . $a['cidade'] . '. ' . $a['resumo']];
        if (!$piorou && $ultimoEm !== '' && strtotime($ultimoEm) > time() - $intervalo * 3600)
            return $saida + ['acao' => 'silencio', 'resumo' => 'Aviso recente em ' . $a['cidade'] . ' (menos de ' . $intervalo . 'h). ' . $a['resumo']];
        if (!chuvaNoHorario() && !($a['tempestade'] && (string)chuvaCfg('chuva_tempestade') === '1'))
            return $saida + ['acao' => 'fora-do-horario', 'resumo' => 'Fora do horário de avisos. ' . $a['resumo']];
    }
    if ($piorou) $prefixo = $a['tempestade'] ? '⛈️ Atenção: ' : '🔴 Piorou: ';

    $r = chuvaEnviar($a, false, $teste, $prefixo, null, $contatos);
    if (!$teste) {
        cfgSet($kEvento, $a['evento']);
        cfgSet($kEm, agora());
        cfgSet($kPico, (int)$a['pico']);
        cfgSet('chuva_ultimo_em', agora());
        cfgSet('chuva_ultimo_resumo', $a['resumo']);
    }
    logar('chuva', "[{$a['cidade']}] " . ($piorou ? 'reaviso (piorou)' : 'alerta') . " — {$a['resumo']} — {$r['enviados']} enviados, {$r['erros']} erros");
    return $saida + ['acao' => $piorou ? 'piorou' : 'avisado', 'envio' => $r];
}

/* Estado para o painel. */
function chuvaEstado(): array {
    $a = chuvaAnalisar();
    return [
        'config' => chuvaConfig(),
        'canais' => array_values(array_filter(explode(',', (string)cfg('chuva_canais', 'whatsapp,email,direct')))),
        'analise' => $a,
        'ultimo_em' => cfg('chuva_ultimo_em', null),
        'ultimo_resumo' => cfg('chuva_ultimo_resumo', null),
        'cidades' => chuvaCidades(),
        'no_horario' => chuvaNoHorario(),
        'destinatarios' => count(chuvaDestinatarios()),
    ];
}

/* ---- execução pelo cron ---- */
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $forcar = in_array('--forcar', $argv, true);
    $teste  = in_array('--teste', $argv, true);
    $trava = fopen('/tmp/lembretes-chuva.lock', 'c');
    if (!flock($trava, LOCK_EX | LOCK_NB)) exit(0);
    try {
        $r = chuvaRodar($forcar, $teste);
        $env = $r['envio'] ?? null;
        echo date('[d/m H:i] ') . strtoupper((string)$r['acao']) . ' — ' . (string)$r['resumo']
           . ($env ? " ({$env['enviados']} enviados / {$env['erros']} erros)" : '') . "\n";
    } catch (Throwable $e) {
        logar('erro-chuva', $e->getMessage());
        fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    }
}
