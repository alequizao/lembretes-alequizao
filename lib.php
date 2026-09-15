<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set(APP_TZ);
/* Warnings nunca podem vazar no meio do JSON da API. */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

function db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $db;
}

function dbIg(): ?PDO {
    static $db = false;
    if ($db === false) {
        try {
            $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . IG_DB_NAME . ';charset=utf8mb4', IG_DB_USER, IG_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (Throwable $e) { $db = null; }
    }
    return $db;
}

/* ---------- configurações (chave/valor) ---------- */
function cfg(string $chave, $padrao = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT chave, valor FROM config') as $r) $cache[$r['chave']] = $r['valor'];
    }
    return array_key_exists($chave, $cache) ? $cache[$chave] : $padrao;
}
function cfgSet(string $chave, $valor): void {
    $st = db()->prepare('INSERT INTO config (chave, valor) VALUES (:c, :v) ON DUPLICATE KEY UPDATE valor = :v2');
    $st->execute([':c' => $chave, ':v' => (string)$valor, ':v2' => (string)$valor]);
}

/* ---------- sessão ---------- */
function sessaoIniciar(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('lembretessess');
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}
function usuarioAtual(): ?array {
    sessaoIniciar();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, usuario, nome, admin FROM usuarios WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}
function exigirLogin(): array {
    $u = usuarioAtual();
    if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'erro' => 'Sessão expirada. Entre novamente.']); exit; }
    return $u;
}

/* Só o dono do painel vê a caixa de mensagens. */
const DONO_DO_PAINEL = 'alequizao';
function ehDono(?array $u = null): bool {
    $u = $u ?: usuarioAtual();
    return $u && strtolower((string)$u['usuario']) === DONO_DO_PAINEL;
}
function exigirDono(): array {
    $u = exigirLogin();
    if (!ehDono($u)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Esta área é só do administrador.']);
        exit;
    }
    return $u;
}

/* ---------- utilidades ---------- */
function so_digitos(string $s): string { return preg_replace('/\D+/', '', $s); }

/* Normaliza telefone brasileiro para o formato do WhatsApp (55 + DDD + número). */
function telefone_wa(string $tel): string {
    $d = so_digitos($tel);
    if ($d === '') return '';
    if (strlen($d) <= 11 && substr($d, 0, 2) !== '55') $d = '55' . $d;
    return $d;
}
function agora(): string { return date('Y-m-d H:i:s'); }
function jsonSaida(array $dados): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function logar(string $tipo, string $texto): void {
    try {
        $st = db()->prepare('INSERT INTO log (tipo, texto) VALUES (?, ?)');
        $st->execute([$tipo, mb_substr($texto, 0, 1000)]);
    } catch (Throwable $e) {}
}

/* Variáveis que podem ser usadas no título e na mensagem do lembrete. */
function variaveisDisponiveis(): array {
    return [
        'Pessoa' => [
            '{nome}' => 'Nome do contato',
            '{primeiro_nome}' => 'Só o primeiro nome',
            '{whatsapp}' => 'WhatsApp do contato',
            '{email}' => 'E-mail do contato',
            '{instagram}' => '@ do Instagram',
            '{observacao}' => 'Observação do cadastro',
        ],
        'Data e hora' => [
            '{data}' => 'Data do envio (14/09/2026)',
            '{data_extenso}' => 'Data por extenso (14 de setembro de 2026)',
            '{dia_semana}' => 'Dia da semana (segunda-feira)',
            '{dia}' => 'Dia (14)',
            '{mes}' => 'Mês (09)',
            '{mes_nome}' => 'Nome do mês (setembro)',
            '{ano}' => 'Ano (2026)',
            '{hora}' => 'Hora do envio (09:30)',
            '{agora}' => 'Hora em que a mensagem saiu',
            '{saudacao}' => 'Bom dia / Boa tarde / Boa noite',
        ],
        'Tempo (previsão)' => [
            '{clima}' => 'Tempo agora (ex.: ☁️ nublado, 26°C)',
            '{temperatura}' => 'Temperatura agora',
            '{temp_max}' => 'Máxima do dia',
            '{temp_min}' => 'Mínima do dia',
            '{chance_chuva}' => 'Chance de chuva hoje',
            '{clima_amanha}' => 'Previsão de amanhã',
            '{nascer_do_sol}' => 'Nascer do sol',
            '{por_do_sol}' => 'Pôr do sol',
            '{cidade}' => 'Cidade da previsão',
            '{sensacao}' => 'Sensação térmica agora',
            '{umidade}' => 'Umidade do ar agora',
            '{vento}' => 'Velocidade do vento agora',
            '{chuva_mm}' => 'Chuva prevista hoje (mm)',
            '{clima_proximas_horas}' => 'Chance de chuva nas próximas 6 horas',
        ],
        'Lembrete' => [
            '{titulo}' => 'Título do lembrete',
            '{repeticao}' => 'Repetição (Todo mês, Toda semana…)',
            '{proximo}' => 'Data do próximo envio',
            '{canal}' => 'Canal usado (WhatsApp, Direct, E-mail)',
            '{assinatura}' => 'Assinatura definida em Conexões',
            '{link}' => 'Link do painel de lembretes',
        ],
    ];
}

/* Troca as variáveis {} pelos dados reais do lembrete, do contato e do momento. */
function aplicarVariaveis(string $texto, array $lembrete, array $contato, string $canal = '', ?string $proximo = null): string {
    if (strpos($texto, '{') === false) return $texto;
    $q = strtotime($lembrete['proxima_em'] ?? ($lembrete['quando'] ?? 'now')) ?: time();
    $dias  = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $mesNome = $meses[(int)date('n', $q)];
    $h = (int)date('H');
    $rotulosRep = ['nenhuma' => 'Uma vez só', 'diaria' => 'Todo dia', 'semanal' => 'Toda semana', 'quinzenal' => 'A cada 15 dias',
                   'mensal' => 'Todo mês', 'anual' => 'Todo ano', 'dias' => 'A cada ' . (int)($lembrete['intervalo_dias'] ?? 0) . ' dias'];
    $nomeCanal = ['whatsapp' => 'WhatsApp', 'direct' => 'Direct do Instagram', 'email' => 'e-mail'][$canal] ?? '';
    $nome = trim((string)($contato['nome'] ?? ''));
    $troca = [
        '{nome}' => $nome,
        '{primeiro_nome}' => trim(explode(' ', $nome)[0] ?? ''),
        '{whatsapp}' => (string)($contato['whatsapp'] ?? ''),
        '{email}' => (string)($contato['email'] ?? ''),
        '{instagram}' => !empty($contato['ig_usuario']) ? '@' . ltrim((string)$contato['ig_usuario'], '@') : '',
        '{observacao}' => (string)($contato['observacao'] ?? ''),
        '{data}' => date('d/m/Y', $q),
        '{data_extenso}' => date('j', $q) . ' de ' . $mesNome . ' de ' . date('Y', $q),
        '{dia_semana}' => $dias[(int)date('w', $q)],
        '{dia}' => date('d', $q),
        '{mes}' => date('m', $q),
        '{mes_nome}' => $mesNome,
        '{ano}' => date('Y', $q),
        '{hora}' => date('H:i', $q),
        '{agora}' => date('H:i'),
        '{saudacao}' => $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite'),
        '{titulo}' => (string)($lembrete['titulo'] ?? ''),
        '{repeticao}' => $rotulosRep[$lembrete['repeticao'] ?? 'nenhuma'] ?? '',
        '{proximo}' => $proximo ? date('d/m/Y H:i', strtotime($proximo)) : '',
        '{canal}' => $nomeCanal,
        '{assinatura}' => (string)cfg('assinatura', ''),
        '{link}' => 'https://alequizao.com/lembretes/',
    ];
    /* variáveis de clima só chamam a internet se realmente forem usadas */
    if (preg_match('/\{(clima|temperatura|temp_max|temp_min|chance_chuva|clima_amanha|cidade|nascer_do_sol|por_do_sol|sensacao|umidade|vento|chuva_mm|clima_proximas_horas)\}/', $texto)) {
        require_once __DIR__ . '/clima.php';
        $troca += climaVariaveis((string)($contato['cidade'] ?? ''));
    }
    return strtr($texto, $troca);
}
