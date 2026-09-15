<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* API de previsão do tempo (Open-Meteo, sem chave de acesso).
   Uso: /lembretes/clima.php?cidade=Maceió[&dias=3]  →  JSON
   Também é usada internamente pelas variáveis {clima}, {temperatura}… dos lembretes. */
require_once __DIR__ . '/lib.php';

const CLIMA_CACHE_MIN = 30;

$CLIMA_CODIGOS = [
    0 => ['céu limpo', '☀️'], 1 => ['quase limpo', '🌤️'], 2 => ['parcialmente nublado', '⛅'], 3 => ['nublado', '☁️'],
    45 => ['neblina', '🌫️'], 48 => ['neblina com geada', '🌫️'],
    51 => ['garoa fraca', '🌦️'], 53 => ['garoa', '🌦️'], 55 => ['garoa forte', '🌧️'],
    56 => ['garoa congelante', '🌧️'], 57 => ['garoa congelante forte', '🌧️'],
    61 => ['chuva fraca', '🌦️'], 63 => ['chuva', '🌧️'], 65 => ['chuva forte', '🌧️'],
    66 => ['chuva congelante', '🌧️'], 67 => ['chuva congelante forte', '🌧️'],
    71 => ['neve fraca', '🌨️'], 73 => ['neve', '🌨️'], 75 => ['neve forte', '❄️'], 77 => ['grãos de neve', '❄️'],
    80 => ['pancadas de chuva', '🌦️'], 81 => ['pancadas de chuva', '🌧️'], 82 => ['pancadas fortes de chuva', '⛈️'],
    85 => ['pancadas de neve', '🌨️'], 86 => ['pancadas fortes de neve', '❄️'],
    95 => ['tempestade', '⛈️'], 96 => ['tempestade com granizo', '⛈️'], 99 => ['tempestade forte com granizo', '⛈️'],
];

function climaHttp(string $url, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
                            CURLOPT_USERAGENT => 'LembretesAlequizao/1.0']);
    $resp = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$resp, true);
    return ['codigo' => $cod, 'dados' => is_array($d) ? $d : []];
}

/* Descobre latitude/longitude de uma cidade (com cache permanente). */
function climaCidade(string $cidade): ?array {
    $cidade = trim($cidade) ?: 'Maceió';
    $chave = 'geo_' . md5(mb_strtolower($cidade));
    $cache = json_decode((string)cfg($chave), true);
    if (is_array($cache) && !empty($cache['lat'])) return $cache;
    $r = climaHttp('https://geocoding-api.open-meteo.com/v1/search?count=1&language=pt&format=json&name=' . urlencode($cidade));
    $p = $r['dados']['results'][0] ?? null;
    if (!$p) return null;
    $achado = ['nome' => $p['name'], 'estado' => $p['admin1'] ?? '', 'pais' => $p['country'] ?? '',
               'lat' => $p['latitude'], 'lon' => $p['longitude'], 'fuso' => $p['timezone'] ?? APP_TZ];
    cfgSet($chave, json_encode($achado, JSON_UNESCAPED_UNICODE));
    return $achado;
}

/* Previsão completa: agora + próximos dias. */
function climaPrevisao(string $cidade = '', int $dias = 3): array {
    global $CLIMA_CODIGOS;
    $cidade = trim($cidade) ?: (string)cfg('clima_cidade', 'Maceió');
    $dias = max(1, min(7, $dias));
    $chave = 'clima2_' . md5(mb_strtolower($cidade) . '|' . $dias);
    $cache = json_decode((string)cfg($chave), true);
    if (is_array($cache) && !empty($cache['em']) && strtotime($cache['em']) > time() - CLIMA_CACHE_MIN * 60) {
        $cache['cache'] = true;
        return $cache;
    }
    $lugar = climaCidade($cidade);
    if (!$lugar) return ['ok' => false, 'erro' => 'Cidade não encontrada: ' . $cidade];
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => $lugar['lat'], 'longitude' => $lugar['lon'],
        'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,precipitation',
        'hourly' => 'weather_code,temperature_2m,precipitation_probability,precipitation',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,sunrise,sunset',
        'timezone' => $lugar['fuso'] ?: APP_TZ, 'forecast_days' => $dias,
    ]);
    $r = climaHttp($url);
    if ($r['codigo'] !== 200 || empty($r['dados']['current'])) return ['ok' => false, 'erro' => 'Serviço de previsão indisponível agora.'];
    $c = $r['dados']['current'];
    $d = $r['dados']['daily'];
    $desc = fn($cod) => $CLIMA_CODIGOS[(int)$cod] ?? ['tempo indefinido', '🌡️'];
    [$txtAgora, $icAgora] = $desc($c['weather_code'] ?? 0);
    $lista = [];
    $nomes = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    foreach ($d['time'] as $i => $data) {
        [$txt, $ic] = $desc($d['weather_code'][$i] ?? 0);
        $lista[] = [
            'data' => $data, 'data_br' => date('d/m/Y', strtotime($data)),
            'dia_semana' => $nomes[(int)date('w', strtotime($data))],
            'codigo' => (int)($d['weather_code'][$i] ?? 0), 'descricao' => $txt, 'icone' => $ic,
            'max' => round($d['temperature_2m_max'][$i]), 'min' => round($d['temperature_2m_min'][$i]),
            'chance_chuva' => (int)($d['precipitation_probability_max'][$i] ?? 0),
            'chuva_mm' => round((float)($d['precipitation_sum'][$i] ?? 0), 1),
            'nascer_do_sol' => date('H:i', strtotime($d['sunrise'][$i])),
            'por_do_sol' => date('H:i', strtotime($d['sunset'][$i])),
        ];
    }
    /* próximas horas (a partir da hora cheia atual) — base das condições "vai chover" */
    $horas = [];
    $h = $r['dados']['hourly'] ?? [];
    $corte = strtotime(date('Y-m-d H:00:00'));
    foreach (($h['time'] ?? []) as $i => $t) {
        $ts = strtotime($t);
        if ($ts < $corte) continue;
        [$txtH, $icH] = $desc($h['weather_code'][$i] ?? 0);
        $horas[] = [
            'hora' => date('H:i', $ts), 'data' => date('Y-m-d', $ts), 'em' => $t,
            'codigo' => (int)($h['weather_code'][$i] ?? 0), 'descricao' => $txtH, 'icone' => $icH,
            'temperatura' => round((float)($h['temperature_2m'][$i] ?? 0)),
            'chance_chuva' => (int)($h['precipitation_probability'][$i] ?? 0),
            'chuva_mm' => round((float)($h['precipitation'][$i] ?? 0), 1),
        ];
        if (count($horas) >= 48) break;
    }

    $saida = [
        'ok' => true,
        'cidade' => $lugar['nome'] . ($lugar['estado'] ? ' - ' . $lugar['estado'] : ''),
        'agora' => [
            'temperatura' => round($c['temperature_2m']), 'sensacao' => round($c['apparent_temperature'] ?? $c['temperature_2m']),
            'umidade' => (int)($c['relative_humidity_2m'] ?? 0), 'vento' => round($c['wind_speed_10m'] ?? 0),
            'chuva_mm' => round((float)($c['precipitation'] ?? 0), 1),
            'codigo' => (int)($c['weather_code'] ?? 0), 'descricao' => $txtAgora, 'icone' => $icAgora,
        ],
        'dias' => $lista,
        'horas' => $horas,
        'fonte' => 'Open-Meteo', 'em' => agora(),
    ];
    cfgSet($chave, json_encode($saida, JSON_UNESCAPED_UNICODE));
    return $saida;
}

/* Variáveis de clima usadas nas mensagens dos lembretes. */
function climaVariaveis(string $cidade = ''): array {
    $p = climaPrevisao($cidade, 2);
    if (empty($p['ok'])) return ['{clima}' => '', '{temperatura}' => '', '{temp_max}' => '', '{temp_min}' => '',
                                 '{chance_chuva}' => '', '{clima_amanha}' => '', '{cidade}' => '', '{nascer_do_sol}' => '', '{por_do_sol}' => ''];
    $hoje = $p['dias'][0] ?? [];
    $amanha = $p['dias'][1] ?? $hoje;
    return [
        '{clima}' => $p['agora']['icone'] . ' ' . $p['agora']['descricao'] . ', ' . $p['agora']['temperatura'] . '°C',
        '{temperatura}' => $p['agora']['temperatura'] . '°C',
        '{temp_max}' => ($hoje['max'] ?? '') . '°C',
        '{temp_min}' => ($hoje['min'] ?? '') . '°C',
        '{chance_chuva}' => ($hoje['chance_chuva'] ?? 0) . '%',
        '{clima_amanha}' => ($amanha['icone'] ?? '') . ' ' . ($amanha['descricao'] ?? '') . ', ' . ($amanha['min'] ?? '') . '°C a ' . ($amanha['max'] ?? '') . '°C',
        '{cidade}' => $p['cidade'],
        '{nascer_do_sol}' => $hoje['nascer_do_sol'] ?? '',
        '{por_do_sol}' => $hoje['por_do_sol'] ?? '',
        '{sensacao}' => $p['agora']['sensacao'] . '°C',
        '{umidade}' => $p['agora']['umidade'] . '%',
        '{vento}' => $p['agora']['vento'] . ' km/h',
        '{chuva_mm}' => ($hoje['chuva_mm'] ?? 0) . ' mm',
        '{clima_proximas_horas}' => climaTextoHoras($p['horas'] ?? [], 6),
    ];
}

/* Resumo curtinho das próximas horas: "14h 80% · 15h 90% · 16h 60%". */
function climaTextoHoras(array $horas, int $qtd = 6): string {
    $p = [];
    foreach (array_slice($horas, 0, max(1, $qtd)) as $h) $p[] = $h['icone'] . ' ' . $h['hora'] . ' ' . $h['chance_chuva'] . '%';
    return implode(' · ', $p);
}

/* ---------- condições (gatilhos "só envie se…") ---------- */

/* Catálogo das condições, usado pela API e pela tela. */
function climaCondicoes(): array {
    return [
        'nenhuma'      => ['rotulo' => 'Sempre enviar (sem condição)',        'unidade' => '',      'padrao' => 0,  'horas' => false],
        'chuva_chance' => ['rotulo' => 'Chance de chuva hoje for pelo menos', 'unidade' => '%',     'padrao' => 60, 'horas' => false],
        'chuva_horas'  => ['rotulo' => 'For chover nas próximas horas',       'unidade' => '%',     'padrao' => 60, 'horas' => true],
        'chuva_mm'     => ['rotulo' => 'Volume de chuva hoje for pelo menos', 'unidade' => 'mm',    'padrao' => 5,  'horas' => false],
        'tempestade'   => ['rotulo' => 'Houver tempestade prevista',          'unidade' => '',      'padrao' => 0,  'horas' => true],
        'temp_acima'   => ['rotulo' => 'Temperatura agora estiver acima de',  'unidade' => '°C',    'padrao' => 33, 'horas' => false],
        'temp_abaixo'  => ['rotulo' => 'Temperatura agora estiver abaixo de', 'unidade' => '°C',    'padrao' => 18, 'horas' => false],
        'vento_acima'  => ['rotulo' => 'Vento agora estiver acima de',        'unidade' => 'km/h',  'padrao' => 40, 'horas' => false],
        'umidade_abaixo' => ['rotulo' => 'Umidade agora estiver abaixo de',   'unidade' => '%',     'padrao' => 30, 'horas' => false],
    ];
}

const CLIMA_CODIGOS_TEMPESTADE = [95, 96, 99];

/* Avalia a condição de um lembrete. Devolve se atende e uma explicação em português. */
function climaAvaliar(string $tipo, float $valor, int $horas = 6, string $cidade = ''): array {
    $tipo = $tipo ?: 'nenhuma';
    if ($tipo === 'nenhuma') return ['ok' => true, 'atende' => true, 'resumo' => 'sem condição'];
    $cat = climaCondicoes();
    if (!isset($cat[$tipo])) return ['ok' => false, 'atende' => false, 'resumo' => 'condição desconhecida: ' . $tipo];

    $p = climaPrevisao($cidade, 2);
    if (empty($p['ok'])) return ['ok' => false, 'atende' => false, 'resumo' => (string)($p['erro'] ?? 'previsão indisponível')];

    $horas = max(1, min(48, $horas));
    $janela = array_slice($p['horas'] ?? [], 0, $horas);
    $hoje = $p['dias'][0] ?? [];
    $ag = $p['agora'];
    $cidadeTxt = $p['cidade'];

    switch ($tipo) {
        case 'chuva_chance':
            $atual = (int)($hoje['chance_chuva'] ?? 0);
            return climaVeredito($atual >= $valor, "chance de chuva hoje em $cidadeTxt: {$atual}% (mínimo " . (int)$valor . '%)', $atual);

        case 'chuva_horas': {
            $pico = 0; $quando = '';
            foreach ($janela as $h) {
                if ($h['chance_chuva'] > $pico) { $pico = $h['chance_chuva']; $quando = $h['hora']; }
            }
            $txt = "maior chance de chuva nas próximas {$horas}h em $cidadeTxt: {$pico}%"
                 . ($quando ? " (às $quando)" : '') . ' (mínimo ' . (int)$valor . '%)';
            return climaVeredito($pico >= $valor, $txt, $pico);
        }

        case 'chuva_mm':
            $mm = (float)($hoje['chuva_mm'] ?? 0);
            return climaVeredito($mm >= $valor, "chuva prevista hoje em $cidadeTxt: {$mm} mm (mínimo " . $valor . ' mm)', $mm);

        case 'tempestade': {
            $achou = in_array((int)($ag['codigo'] ?? 0), CLIMA_CODIGOS_TEMPESTADE, true);
            $quando = $achou ? 'agora' : '';
            foreach ($janela as $h) {
                if (in_array($h['codigo'], CLIMA_CODIGOS_TEMPESTADE, true)) { $achou = true; $quando = $quando ?: 'às ' . $h['hora']; break; }
            }
            $txt = $achou ? "tempestade prevista em $cidadeTxt $quando" : "sem tempestade prevista em $cidadeTxt nas próximas {$horas}h";
            return climaVeredito($achou, $txt, $achou ? 1 : 0);
        }

        case 'temp_acima':
            return climaVeredito($ag['temperatura'] > $valor, "temperatura agora em $cidadeTxt: {$ag['temperatura']}°C (precisa passar de " . $valor . '°C)', $ag['temperatura']);

        case 'temp_abaixo':
            return climaVeredito($ag['temperatura'] < $valor, "temperatura agora em $cidadeTxt: {$ag['temperatura']}°C (precisa ficar abaixo de " . $valor . '°C)', $ag['temperatura']);

        case 'vento_acima':
            return climaVeredito($ag['vento'] > $valor, "vento agora em $cidadeTxt: {$ag['vento']} km/h (precisa passar de " . $valor . ' km/h)', $ag['vento']);

        case 'umidade_abaixo':
            return climaVeredito($ag['umidade'] < $valor, "umidade agora em $cidadeTxt: {$ag['umidade']}% (precisa ficar abaixo de " . $valor . '%)', $ag['umidade']);
    }
    return ['ok' => false, 'atende' => false, 'resumo' => 'condição não tratada'];
}

function climaVeredito(bool $atende, string $resumo, $valor = null): array {
    return ['ok' => true, 'atende' => $atende, 'valor' => $valor,
            'resumo' => ($atende ? 'ATENDE' : 'não atende') . ' — ' . $resumo];
}

/* ---- chamada direta pela web: devolve o JSON da previsão ---- */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=900');
    $p = climaPrevisao((string)($_GET['cidade'] ?? ''), (int)($_GET['dias'] ?? 3));
    if (empty($p['ok'])) http_response_code(404);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
