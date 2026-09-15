<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require_once __DIR__ . '/agenda.php';
require_once __DIR__ . '/midias.php';
header('Content-Type: application/json; charset=utf-8');
sessaoIniciar();
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$corpo = json_decode(file_get_contents('php://input'), true) ?: [];
$e = function (string $k, $p = '') use ($corpo) { return $corpo[$k] ?? ($_POST[$k] ?? ($_GET[$k] ?? $p)); };

/* Monta o WHERE do historico de envios a partir dos filtros da tela. */
function enviosFiltro(callable $e): array {
    $ondes = []; $par = [];
    $canal = (string)$e('canal', '');
    if (in_array($canal, ['whatsapp', 'direct', 'email'], true)) { $ondes[] = 'e.canal = ?'; $par[] = $canal; }
    $status = (string)$e('status', '');
    if (in_array($status, ['enviado', 'erro'], true)) { $ondes[] = 'e.status = ?'; $par[] = $status; }
    $dias = (int)$e('dias', 0);
    if ($dias > 0) { $ondes[] = 'e.criado_em >= NOW() - INTERVAL ? DAY'; $par[] = $dias; }
    $busca = trim((string)$e('busca', ''));
    if ($busca !== '') {
        $ondes[] = '(l.titulo LIKE ? OR e.origem LIKE ? OR c.nome LIKE ? OR e.destino LIKE ? OR e.mensagem LIKE ?)';
        array_push($par, "%$busca%", "%$busca%", "%$busca%", "%$busca%", "%$busca%");
    }
    $tipo = (string)$e('tipo', '');
    if ($tipo === 'reais')  $ondes[] = 'e.teste = 0';
    if ($tipo === 'testes') $ondes[] = 'e.teste = 1';
    return [$ondes ? 'WHERE ' . implode(' AND ', $ondes) : '', $par];
}

try {
switch ($acao) {

case 'ping': jsonSaida(['ok' => true, 'versao' => APP_VERSAO, 'hora' => date('d/m/Y H:i:s')]);

case 'login': {
    $u = trim((string)$e('usuario'));
    $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? OR email = ?');
    $st->execute([$u, $u]);
    $user = $st->fetch();
    if (!$user || !password_verify((string)$e('senha'), $user['senha'])) jsonSaida(['ok' => false, 'erro' => 'Usuário ou senha inválidos']);
    $_SESSION['uid'] = (int)$user['id'];
    jsonSaida(['ok' => true, 'usuario' => ['nome' => $user['nome'], 'usuario' => $user['usuario'], 'admin' => (int)$user['admin']]]);
}
case 'sessao': {
    $u = usuarioAtual();
    jsonSaida(['ok' => (bool)$u, 'usuario' => $u]);
}
case 'sair': { session_destroy(); jsonSaida(['ok' => true]); }

/* ---------- painel ---------- */
case 'painel': {
    exigirLogin();
    $db = db();
    $n = fn(string $q) => (int)$db->query($q)->fetchColumn();
    $prox = $db->query("SELECT l.id, l.titulo, l.proxima_em, l.canais, l.repeticao,
        (SELECT GROUP_CONCAT(c.nome SEPARATOR ', ') FROM lembrete_contatos lc JOIN contatos c ON c.id = lc.contato_id WHERE lc.lembrete_id = l.id) AS contatos
        FROM lembretes l WHERE l.status = 'ativo' AND l.proxima_em IS NOT NULL ORDER BY l.proxima_em LIMIT 8")->fetchAll();
    $ult = $db->query("SELECT e.*, COALESCE(l.titulo, e.origem) AS titulo, c.nome AS contato FROM envios e LEFT JOIN lembretes l ON l.id = e.lembrete_id
        LEFT JOIN contatos c ON c.id = e.contato_id ORDER BY e.id DESC LIMIT 8")->fetchAll();
    jsonSaida(['ok' => true, 'cartoes' => [
        'ativos'    => $n("SELECT COUNT(*) FROM lembretes WHERE status = 'ativo'"),
        'hoje'      => $n("SELECT COUNT(*) FROM lembretes WHERE status = 'ativo' AND DATE(proxima_em) = CURDATE()"),
        'contatos'  => $n('SELECT COUNT(*) FROM contatos WHERE ativo = 1'),
        'enviados7' => $n("SELECT COUNT(*) FROM envios WHERE status = 'enviado' AND criado_em >= NOW() - INTERVAL 7 DAY"),
        'erros7'    => $n("SELECT COUNT(*) FROM envios WHERE status = 'erro' AND criado_em >= NOW() - INTERVAL 7 DAY"),
    ], 'proximos' => $prox, 'ultimos' => $ult, 'whatsapp' => whatsappEstado()]);
}

/* ---------- contatos ---------- */
case 'contatos': {
    exigirLogin();
    $busca = trim((string)$e('busca'));
    if ($busca !== '') {
        $st = db()->prepare('SELECT * FROM contatos WHERE nome LIKE :b1 OR whatsapp LIKE :b2 OR email LIKE :b3 OR ig_usuario LIKE :b4 ORDER BY nome');
        $st->execute([':b1' => "%$busca%", ':b2' => "%$busca%", ':b3' => "%$busca%", ':b4' => "%$busca%"]);
        jsonSaida(['ok' => true, 'itens' => $st->fetchAll()]);
    }
    jsonSaida(['ok' => true, 'itens' => db()->query('SELECT * FROM contatos ORDER BY ativo DESC, nome')->fetchAll()]);
}
case 'contato_salvar': {
    exigirLogin();
    $id = (int)$e('id');
    /* se veio só o @, tenta achar a conversa correspondente */
    if (!trim((string)$e('ig_remetente_id')) && trim((string)$e('ig_usuario'))) {
        $achado = igResolverUsuario((string)$e('ig_usuario'));
        if ($achado) {
            $_POST['ig_remetente_id'] = $corpo['ig_remetente_id'] = $achado['remetente_id'];
            $_POST['ig_cliente_id'] = $corpo['ig_cliente_id'] = $achado['cliente_id'];   // envia pela conta dona da conversa
        }
    }
    $dados = [
        ':nome' => trim((string)$e('nome')), ':whatsapp' => trim((string)$e('whatsapp')),
        ':email' => trim((string)$e('email')), ':ig_conversa_id' => ($e('ig_conversa_id') ?: null),
        ':ig_usuario' => trim((string)$e('ig_usuario')), ':ig_remetente_id' => trim((string)$e('ig_remetente_id')),
        ':ig_cliente_id' => ($e('ig_cliente_id') ?: null), ':observacao' => trim((string)$e('observacao')),
        ':cidade' => trim((string)$e('cidade')),
        ':alerta_chuva' => (int)!!$e('alerta_chuva', 1),
        ':ativo' => (int)!!$e('ativo', 1),
    ];
    if ($dados[':nome'] === '') jsonSaida(['ok' => false, 'erro' => 'Informe o nome do contato']);
    if ($id) {
        $dados[':id'] = $id;
        db()->prepare('UPDATE contatos SET nome=:nome, whatsapp=:whatsapp, email=:email, ig_conversa_id=:ig_conversa_id,
            ig_usuario=:ig_usuario, ig_remetente_id=:ig_remetente_id, ig_cliente_id=:ig_cliente_id, observacao=:observacao, cidade=:cidade, alerta_chuva=:alerta_chuva, ativo=:ativo WHERE id=:id')->execute($dados);
    } else {
        db()->prepare('INSERT INTO contatos (nome, whatsapp, email, ig_conversa_id, ig_usuario, ig_remetente_id, ig_cliente_id, observacao, cidade, alerta_chuva, ativo)
            VALUES (:nome,:whatsapp,:email,:ig_conversa_id,:ig_usuario,:ig_remetente_id,:ig_cliente_id,:observacao,:cidade,:alerta_chuva,:ativo)')->execute($dados);
        $id = (int)db()->lastInsertId();
    }
    jsonSaida(['ok' => true, 'id' => $id]);
}
case 'contato_excluir': {
    exigirLogin();
    db()->prepare('DELETE FROM contatos WHERE id = ?')->execute([(int)$e('id')]);
    jsonSaida(['ok' => true]);
}

/* ---------- lembretes ---------- */
case 'variaveis': {
    exigirLogin();
    $contato = db()->query('SELECT * FROM contatos WHERE ativo = 1 ORDER BY id LIMIT 1')->fetch() ?: ['nome' => 'Maria Silva', 'whatsapp' => '82988717072', 'email' => 'maria@email.com'];
    $exemplo = ['titulo' => 'Consulta com a nutricionista', 'proxima_em' => date('Y-m-d H:i:s', strtotime('+2 days 09:30')), 'repeticao' => 'mensal', 'intervalo_dias' => 0];
    $grupos = [];
    foreach (variaveisDisponiveis() as $grupo => $itens) {
        foreach ($itens as $chave => $desc) {
            $grupos[$grupo][] = ['chave' => $chave, 'descricao' => $desc,
                                 'exemplo' => aplicarVariaveis($chave, $exemplo, $contato, 'whatsapp', date('Y-m-d H:i:s', strtotime('+32 days 09:30')))];
        }
    }
    jsonSaida(['ok' => true, 'grupos' => $grupos]);
}
case 'previa': {
    exigirLogin();
    $contatoId = (int)$e('contato_id');
    $contato = $contatoId ? (db()->query('SELECT * FROM contatos WHERE id = ' . $contatoId)->fetch() ?: []) : [];
    if (!$contato) $contato = db()->query('SELECT * FROM contatos WHERE ativo = 1 ORDER BY id LIMIT 1')->fetch() ?: ['nome' => 'Maria Silva'];
    $l = ['titulo' => (string)$e('titulo'), 'mensagem' => (string)$e('mensagem'),
          'proxima_em' => ($e('quando') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)$e('quando')))) : agora()),
          'repeticao' => (string)$e('repeticao', 'nenhuma'), 'intervalo_dias' => (int)$e('intervalo_dias', 0)];
    $canal = (string)$e('canal', 'whatsapp');
    jsonSaida(['ok' => true, 'contato' => $contato['nome'] ?? '',
               'titulo' => aplicarVariaveis($l['titulo'], $l, $contato, $canal),
               'mensagem' => aplicarVariaveis($l['mensagem'], $l, $contato, $canal)]);
}
case 'midia_upload': {
    exigirLogin();
    $r = midiaGuardar($_FILES['arquivo'] ?? []);
    if (!$r['ok']) jsonSaida(['ok' => false, 'erro' => $r['erro']]);
    $id = (int)$e('lembrete_id') ?: null;
    $st = db()->prepare('INSERT INTO lembrete_midias (lembrete_id, arquivo, nome, mime, tipo, tamanho) VALUES (?,?,?,?,?,?)');
    $st->execute([$id, $r['arquivo'], $r['nome'], $r['mime'], $r['tipo'], $r['tamanho']]);
    jsonSaida(['ok' => true, 'midia' => [
        'id' => (int)db()->lastInsertId(), 'nome' => $r['nome'], 'tipo' => $r['tipo'], 'mime' => $r['mime'],
        'tamanho' => $r['tamanho'], 'tamanho_txt' => tamanhoLegivel($r['tamanho']), 'url' => midiaUrlBase() . $r['arquivo'],
    ]]);
}
case 'midia_excluir': {
    exigirLogin();
    midiaExcluir((int)$e('id'));
    jsonSaida(['ok' => true]);
}
case 'midias': {
    exigirLogin();
    $itens = midiasDoLembrete((int)$e('lembrete_id'));
    foreach ($itens as &$m) $m['tamanho_txt'] = tamanhoLegivel((int)$m['tamanho']);
    jsonSaida(['ok' => true, 'itens' => $itens]);
}
case 'lembretes': {
    exigirLogin();
    $filtro = (string)$e('status', '');
    $sql = "SELECT l.*, (SELECT GROUP_CONCAT(c.nome SEPARATOR ', ') FROM lembrete_contatos lc JOIN contatos c ON c.id = lc.contato_id WHERE lc.lembrete_id = l.id) AS contatos,
            (SELECT GROUP_CONCAT(lc.contato_id) FROM lembrete_contatos lc WHERE lc.lembrete_id = l.id) AS contato_ids,
            (SELECT COUNT(*) FROM lembrete_midias lm WHERE lm.lembrete_id = l.id) AS qtd_midias FROM lembretes l";
    if (in_array($filtro, ['ativo', 'pausado', 'concluido'], true)) $sql .= " WHERE l.status = " . db()->quote($filtro);
    $sql .= " ORDER BY (l.status = 'ativo') DESC, l.proxima_em IS NULL, l.proxima_em LIMIT 500";
    jsonSaida(['ok' => true, 'itens' => db()->query($sql)->fetchAll()]);
}
case 'lembrete_salvar': {
    exigirLogin();
    $id = (int)$e('id');
    $quando = trim((string)$e('quando'));
    $quando = $quando ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $quando))) : '';
    $canais = $e('canais', []);
    if (is_string($canais)) $canais = array_filter(explode(',', $canais));
    $canais = array_values(array_intersect(['whatsapp', 'direct', 'email'], (array)$canais));
    $contatos = array_map('intval', (array)$e('contatos', []));
    $titulo = trim((string)$e('titulo'));
    if ($titulo === '') jsonSaida(['ok' => false, 'erro' => 'Informe o título']);
    if (!$quando) jsonSaida(['ok' => false, 'erro' => 'Informe a data e a hora']);
    if (!$canais) jsonSaida(['ok' => false, 'erro' => 'Escolha pelo menos um canal']);
    if (!$contatos) jsonSaida(['ok' => false, 'erro' => 'Escolha pelo menos um contato']);

    $d = [
        ':titulo' => $titulo, ':mensagem' => (string)$e('mensagem'), ':quando' => $quando,
        ':repeticao' => (string)$e('repeticao', 'nenhuma'), ':intervalo_dias' => (int)$e('intervalo_dias', 0),
        ':repetir_ate' => ($e('repetir_ate') ?: null), ':canais' => implode(',', $canais),
        ':whatsapp_id' => ((int)$e('whatsapp_id') ?: null),
        ':antecedencia' => max(0, (int)$e('antecedencia', 0)),
        ':status' => (string)$e('status', 'ativo'),
    ];
    /* condição de clima ("só envie se…") */
    require_once __DIR__ . '/clima.php';
    $condTipo = (string)$e('condicao_tipo', 'nenhuma');
    if (!array_key_exists($condTipo, climaCondicoes())) $condTipo = 'nenhuma';
    $d[':condicao_tipo'] = $condTipo;
    $d[':condicao_valor'] = round((float)$e('condicao_valor', 0), 1);
    $d[':condicao_horas'] = max(1, min(48, (int)$e('condicao_horas', 6) ?: 6));
    $d[':condicao_cidade'] = trim((string)$e('condicao_cidade')) ?: null;
    $d[':condicao_antirrepete'] = (int)$e('condicao_antirrepete', 1) ? 1 : 0;
    /* com condição o lembrete é uma verificação repetida; "uma vez só" o mataria no 1º "não" */
    if ($condTipo !== 'nenhuma' && $d[':repeticao'] === 'nenhuma') $d[':repeticao'] = 'diaria';
    $db = db();
    if ($id) {
        $d[':id'] = $id;
        $antigo = $db->query('SELECT proxima_em, quando FROM lembretes WHERE id = ' . $id)->fetch();
        $d[':proxima_em'] = ($antigo && $antigo['quando'] === $quando) ? $antigo['proxima_em'] : $quando;
        $db->prepare('UPDATE lembretes SET titulo=:titulo, mensagem=:mensagem, quando=:quando, proxima_em=:proxima_em, repeticao=:repeticao,
            intervalo_dias=:intervalo_dias, repetir_ate=:repetir_ate, canais=:canais, status=:status, whatsapp_id=:whatsapp_id,
            antecedencia=:antecedencia, condicao_tipo=:condicao_tipo, condicao_valor=:condicao_valor, condicao_horas=:condicao_horas,
            condicao_cidade=:condicao_cidade, condicao_antirrepete=:condicao_antirrepete WHERE id=:id')->execute($d);
    } else {
        $d[':proxima_em'] = $quando;
        $u = usuarioAtual();
        $d[':criado_por'] = $u['id'];
        $db->prepare('INSERT INTO lembretes (titulo, mensagem, quando, proxima_em, repeticao, intervalo_dias, repetir_ate, canais, status, whatsapp_id, antecedencia,
            condicao_tipo, condicao_valor, condicao_horas, condicao_cidade, condicao_antirrepete, criado_por)
            VALUES (:titulo,:mensagem,:quando,:proxima_em,:repeticao,:intervalo_dias,:repetir_ate,:canais,:status,:whatsapp_id,:antecedencia,
            :condicao_tipo,:condicao_valor,:condicao_horas,:condicao_cidade,:condicao_antirrepete,:criado_por)')->execute($d);
        $id = (int)$db->lastInsertId();
    }
    /* anexos enviados antes de o lembrete existir */
    $midias = array_filter(array_map('intval', (array)$e('midias', [])));
    if ($midias) {
        $lista = implode(',', $midias);
        $db->exec("UPDATE lembrete_midias SET lembrete_id = $id WHERE id IN ($lista)");
    }
    $db->prepare('DELETE FROM lembrete_contatos WHERE lembrete_id = ?')->execute([$id]);
    $ins = $db->prepare('INSERT IGNORE INTO lembrete_contatos (lembrete_id, contato_id) VALUES (?,?)');
    foreach ($contatos as $c) $ins->execute([$id, $c]);
    jsonSaida(['ok' => true, 'id' => $id]);
}
case 'clima_condicoes': {
    exigirLogin();
    require_once __DIR__ . '/clima.php';
    jsonSaida(['ok' => true, 'itens' => climaCondicoes()]);
}
case 'clima_testar': {
    exigirLogin();
    require_once __DIR__ . '/clima.php';
    $r = climaAvaliar((string)$e('condicao_tipo', 'nenhuma'), (float)$e('condicao_valor', 0),
                      (int)$e('condicao_horas', 6), (string)$e('condicao_cidade'));
    jsonSaida(['ok' => true, 'atende' => !empty($r['atende']), 'resumo' => (string)$r['resumo'], 'consultou' => !empty($r['ok'])]);
}
case 'lembrete_status': {
    exigirLogin();
    $id = (int)$e('id'); $novo = (string)$e('status');
    if (!in_array($novo, ['ativo', 'pausado', 'concluido'], true)) jsonSaida(['ok' => false, 'erro' => 'Status inválido']);
    $l = db()->query('SELECT * FROM lembretes WHERE id = ' . $id)->fetch();
    if (!$l) jsonSaida(['ok' => false, 'erro' => 'Lembrete não encontrado']);
    $prox = $l['proxima_em'];
    if ($novo === 'ativo' && (!$prox || strtotime($prox) <= time())) {
        $prox = $l['repeticao'] === 'nenhuma' ? $l['quando'] : (alinharFuturo($l, $l['proxima_em'] ?: $l['quando']) ?: $l['quando']);
    }
    db()->prepare('UPDATE lembretes SET status = ?, proxima_em = ? WHERE id = ?')->execute([$novo, $prox, $id]);
    jsonSaida(['ok' => true]);
}
case 'lembrete_excluir': {
    exigirLogin();
    db()->prepare('DELETE FROM lembretes WHERE id = ?')->execute([(int)$e('id')]);
    jsonSaida(['ok' => true]);
}
case 'lembrete_testar': {
    exigirLogin();
    $l = db()->query('SELECT * FROM lembretes WHERE id = ' . (int)$e('id'))->fetch();
    if (!$l) jsonSaida(['ok' => false, 'erro' => 'Lembrete não encontrado']);
    jsonSaida(['ok' => true, 'resultado' => dispararLembrete($l, true)]);
}

case 'lembrete_duplicar': {
    exigirLogin();
    $id = (int)$e('id');
    $db = db();
    $l = $db->query('SELECT * FROM lembretes WHERE id = ' . $id)->fetch();
    if (!$l) jsonSaida(['ok' => false, 'erro' => 'Lembrete nao encontrado']);
    $u = usuarioAtual();
    $st = $db->prepare('INSERT INTO lembretes (titulo, mensagem, quando, proxima_em, repeticao, intervalo_dias, repetir_ate, canais, status, whatsapp_id, antecedencia,
        condicao_tipo, condicao_valor, condicao_horas, condicao_cidade, condicao_antirrepete, criado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $quando = $l['proxima_em'] ?: $l['quando'];
    if (strtotime($quando) <= time()) $quando = date('Y-m-d H:i:s', strtotime('+1 day', strtotime($quando)));
    $st->execute([mb_substr($l['titulo'] . ' (copia)', 0, 160), $l['mensagem'], $quando, $quando, $l['repeticao'], $l['intervalo_dias'],
                  $l['repetir_ate'], $l['canais'], 'pausado', $l['whatsapp_id'], (int)($l['antecedencia'] ?? 0),
                  (string)($l['condicao_tipo'] ?? 'nenhuma'), (float)($l['condicao_valor'] ?? 0), (int)($l['condicao_horas'] ?? 6),
                  $l['condicao_cidade'] ?? null, (int)($l['condicao_antirrepete'] ?? 1), $u['id']]);
    $novo = (int)$db->lastInsertId();
    $db->exec("INSERT IGNORE INTO lembrete_contatos (lembrete_id, contato_id) SELECT $novo, contato_id FROM lembrete_contatos WHERE lembrete_id = $id");
    foreach ($db->query("SELECT * FROM lembrete_midias WHERE lembrete_id = $id") as $m) {
        $db->prepare('INSERT INTO lembrete_midias (lembrete_id, arquivo, nome, mime, tipo, tamanho) VALUES (?,?,?,?,?,?)')
           ->execute([$novo, $m['arquivo'], $m['nome'], $m['mime'], $m['tipo'], $m['tamanho']]);
    }
    jsonSaida(['ok' => true, 'id' => $novo]);
}
case 'lembrete_adiar': {
    exigirLogin();
    $id = (int)$e('id');
    $min = (int)$e('minutos', 0);
    $para = trim((string)$e('para', ''));
    $l = db()->query('SELECT * FROM lembretes WHERE id = ' . $id)->fetch();
    if (!$l) jsonSaida(['ok' => false, 'erro' => 'Lembrete nao encontrado']);
    $base = strtotime($l['proxima_em'] ?: $l['quando']);
    if ($base < time()) $base = time();
    if ($para !== '') $novo = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $para)));
    elseif ($min > 0) $novo = date('Y-m-d H:i:s', $base + $min * 60);
    else jsonSaida(['ok' => false, 'erro' => 'Informe quanto adiar']);
    db()->prepare("UPDATE lembretes SET proxima_em = ?, aviso_em = NULL, status = 'ativo' WHERE id = ?")->execute([$novo, $id]);
    jsonSaida(['ok' => true, 'proxima_em' => $novo]);
}
case 'envio_reenviar': {
    exigirLogin();
    $env = db()->query('SELECT * FROM envios WHERE id = ' . (int)$e('id'))->fetch();
    if (!$env) jsonSaida(['ok' => false, 'erro' => 'Envio nao encontrado']);
    $contato = $env['contato_id'] ? (db()->query('SELECT * FROM contatos WHERE id = ' . (int)$env['contato_id'])->fetch() ?: []) : [];
    if (!$contato) {
        $contato = ['nome' => 'Destino', 'whatsapp' => '', 'email' => '', 'ig_remetente_id' => '', 'ig_usuario' => ''];
        $campo = ['whatsapp' => 'whatsapp', 'email' => 'email', 'direct' => 'ig_remetente_id'][$env['canal']];
        $contato[$campo] = (string)$env['destino'];
    }
    $lem = $env['lembrete_id'] ? db()->query('SELECT * FROM lembretes WHERE id = ' . (int)$env['lembrete_id'])->fetch() : null;
    $titulo = $lem ? aplicarVariaveis($lem['titulo'], $lem, $contato, $env['canal']) : 'Lembrete';
    $ctx = $lem ? ['quando' => $lem['proxima_em'] ?: $lem['quando'], 'repeticao' => $lem['repeticao'],
                   'instancia' => whatsappInstanciaDe(isset($lem['whatsapp_id']) ? (int)$lem['whatsapp_id'] : null)] : [];
    $r = enviarComTentativas((string)$env['canal'], $contato, $titulo, (string)$env['mensagem'], $ctx, [], 3);
    registrarEnvio($env['lembrete_id'] ? (int)$env['lembrete_id'] : null, $env['contato_id'] ? (int)$env['contato_id'] : null,
                   (string)$env['canal'], $r, (string)$env['mensagem'], (bool)$env['teste'], false, (int)($r['tentativas'] ?? 1));
    jsonSaida(['ok' => (bool)$r['ok'], 'detalhe' => (string)($r['detalhe'] ?? '')]);
}
case 'envios_csv': {
    exigirLogin();
    [$sql, $par] = enviosFiltro($e);
    $st = db()->prepare("SELECT e.criado_em, COALESCE(l.titulo, e.origem) AS titulo, c.nome AS contato, e.destino, e.canal, e.status, e.tentativas, e.previo, e.teste, e.detalhe, e.mensagem
        FROM envios e LEFT JOIN lembretes l ON l.id = e.lembrete_id LEFT JOIN contatos c ON c.id = e.contato_id
        $sql ORDER BY e.id DESC LIMIT 5000");
    $st->execute($par);
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="envios-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Quando', 'Lembrete', 'Contato', 'Destino', 'Canal', 'Situacao', 'Tentativas', 'Aviso previo', 'Teste', 'Detalhe', 'Mensagem'], ';');
    foreach ($st->fetchAll() as $r) {
        fputcsv($out, [date('d/m/Y H:i:s', strtotime($r['criado_em'])), $r['titulo'], $r['contato'], $r['destino'],
            ['whatsapp' => 'WhatsApp', 'direct' => 'Direct', 'email' => 'E-mail'][$r['canal']] ?? $r['canal'],
            $r['status'], $r['tentativas'], $r['previo'] ? 'sim' : 'nao', $r['teste'] ? 'sim' : 'nao',
            $r['detalhe'], preg_replace('/\s+/u', ' ', (string)$r['mensagem'])], ';');
    }
    fclose($out);
    exit;
}
case 'agenda_mes': {
    exigirLogin();
    $mes = (string)$e('mes', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $ini = $mes . '-01 00:00:00';
    $fim = date('Y-m-t 23:59:59', strtotime($ini));
    $itens = [];
    $st = db()->query("SELECT l.*, (SELECT GROUP_CONCAT(c.nome SEPARATOR ', ') FROM lembrete_contatos lc JOIN contatos c ON c.id = lc.contato_id WHERE lc.lembrete_id = l.id) AS contatos
        FROM lembretes l WHERE l.status <> 'concluido' ORDER BY l.proxima_em");
    foreach ($st->fetchAll() as $l) {
        $q = $l['proxima_em'] ?: $l['quando'];
        $n = 0;
        while ($q !== null && strtotime($q) <= strtotime($fim) && $n++ < 400) {
            if (strtotime($q) >= strtotime($ini)) {
                $itens[] = ['id' => (int)$l['id'], 'titulo' => $l['titulo'], 'quando' => $q, 'canais' => $l['canais'],
                            'status' => $l['status'], 'contatos' => $l['contatos'], 'repeticao' => $l['repeticao']];
            }
            if ($l['repeticao'] === 'nenhuma') break;
            $q = proximaOcorrencia($l, $q);
        }
    }
    usort($itens, fn($a, $b) => strcmp($a['quando'], $b['quando']));
    jsonSaida(['ok' => true, 'mes' => $mes, 'itens' => $itens]);
}
case 'contatos_importar': {
    exigirLogin();
    $texto = (string)$e('csv');
    if (trim($texto) === '') jsonSaida(['ok' => false, 'erro' => 'Cole o conteudo do arquivo CSV']);
    $linhas = preg_split('/\r\n|\n|\r/', trim($texto));
    $sep = (substr_count($linhas[0], ';') >= substr_count($linhas[0], ',')) ? ';' : ',';
    $cab = array_map(fn($x) => strtolower(trim($x, " \t\"'")), str_getcsv(array_shift($linhas), $sep));
    $mapa = ['nome' => 'nome', 'contato' => 'nome', 'whatsapp' => 'whatsapp', 'telefone' => 'whatsapp', 'celular' => 'whatsapp',
             'email' => 'email', 'e-mail' => 'email', 'instagram' => 'ig_usuario', 'ig' => 'ig_usuario', '@' => 'ig_usuario',
             'cidade' => 'cidade', 'observacao' => 'observacao', 'observação' => 'observacao', 'obs' => 'observacao'];
    $colunas = array_map(fn($c) => $mapa[$c] ?? null, $cab);
    if (!in_array('nome', $colunas, true)) jsonSaida(['ok' => false, 'erro' => 'O CSV precisa de uma coluna "nome". Colunas aceitas: nome, whatsapp, email, instagram, cidade, observacao.']);
    $novos = 0; $atualizados = 0; $ignorados = 0;
    $db = db();
    foreach ($linhas as $linha) {
        if (trim($linha) === '') continue;
        $campos = str_getcsv($linha, $sep);
        $d = ['nome' => '', 'whatsapp' => '', 'email' => '', 'ig_usuario' => '', 'cidade' => '', 'observacao' => ''];
        foreach ($colunas as $i => $chave) { if ($chave !== null && isset($campos[$i])) $d[$chave] = trim((string)$campos[$i]); }
        $d['ig_usuario'] = ltrim($d['ig_usuario'], '@');
        if ($d['nome'] === '') { $ignorados++; continue; }
        $achado = null;
        if ($d['whatsapp'] !== '' || $d['email'] !== '') {
            $q = $db->prepare('SELECT id FROM contatos WHERE (whatsapp <> "" AND whatsapp = ?) OR (email <> "" AND email = ?) LIMIT 1');
            $q->execute([$d['whatsapp'], $d['email']]);
            $achado = $q->fetchColumn();
        }
        if ($achado) {
            $db->prepare('UPDATE contatos SET nome=?, whatsapp=?, email=?, ig_usuario=?, cidade=?, observacao=? WHERE id=?')
               ->execute([$d['nome'], $d['whatsapp'], $d['email'], $d['ig_usuario'], $d['cidade'], $d['observacao'], $achado]);
            $atualizados++;
        } else {
            $db->prepare('INSERT INTO contatos (nome, whatsapp, email, ig_usuario, cidade, observacao, ativo) VALUES (?,?,?,?,?,?,1)')
               ->execute([$d['nome'], $d['whatsapp'], $d['email'], $d['ig_usuario'], $d['cidade'], $d['observacao']]);
            $novos++;
        }
    }
    logar('importacao', "contatos: $novos novos, $atualizados atualizados, $ignorados ignorados");
    jsonSaida(['ok' => true, 'novos' => $novos, 'atualizados' => $atualizados, 'ignorados' => $ignorados]);
}

/* ---------- envios ---------- */
case 'envios': {
    exigirLogin();
    $lim = min(1000, max(10, (int)$e('limite', 200)));
    [$sql, $par] = enviosFiltro($e);
    $st = db()->prepare("SELECT e.*, COALESCE(l.titulo, e.origem) AS titulo, c.nome AS contato FROM envios e LEFT JOIN lembretes l ON l.id = e.lembrete_id
        LEFT JOIN contatos c ON c.id = e.contato_id $sql ORDER BY e.id DESC LIMIT $lim");
    $st->execute($par);
    jsonSaida(['ok' => true, 'itens' => $st->fetchAll()]);
}

/* ---------- caixa de mensagens (só o dono) ---------- */
case 'msg_caixas': {
    exigirDono();
    require_once __DIR__ . '/mensagens.php';
    jsonSaida(['ok' => true, 'itens' => msgCaixas()]);
}
case 'msg_conversas': {
    exigirDono();
    require_once __DIR__ . '/mensagens.php';
    jsonSaida(['ok' => true, 'itens' => msgConversas((string)$e('tipo'), (int)$e('id'), (string)$e('busca'))]);
}
case 'msg_mensagens': {
    exigirDono();
    require_once __DIR__ . '/mensagens.php';
    $tipo = (string)$e('tipo'); $id = (int)$e('id'); $conversa = (string)$e('conversa');
    if ($conversa === '') jsonSaida(['ok' => false, 'erro' => 'Escolha uma conversa']);
    if ((int)$e('marcar_lida')) msgMarcarLida($tipo, $id, $conversa);
    jsonSaida(['ok' => true, 'itens' => msgMensagens($tipo, $id, $conversa, (int)$e('limite', 60))]);
}
case 'msg_enviar': {
    exigirDono();
    require_once __DIR__ . '/mensagens.php';
    jsonSaida(msgEnviar((string)$e('tipo'), (int)$e('id'), (string)$e('conversa'), (string)$e('texto')));
}

case 'msg_enviar_midia': {
    exigirDono();
    require_once __DIR__ . '/mensagens.php';
    jsonSaida(msgEnviarMidia((string)$e('tipo'), (int)$e('id'), (string)$e('conversa'),
                             $_FILES['arquivo'] ?? [], (string)$e('legenda')));
}

/* ---------- configurações e canais ---------- */
case 'config': {
    exigirLogin();
    $c = [];
    foreach (db()->query('SELECT chave, valor FROM config') as $r) $c[$r['chave']] = $r['valor'];
    $c['smtp_senha'] = $c['smtp_senha'] ? '********' : '';
    jsonSaida(['ok' => true, 'config' => $c, 'contas_ig' => igContas(), 'whatsapp' => whatsappEstado()]);
}
case 'config_salvar': {
    exigirLogin();
    $permitidas = ['clima_cidade', 'evo_instancia', 'smtp_host', 'smtp_porta', 'smtp_seguranca', 'smtp_usuario', 'smtp_senha', 'smtp_de', 'smtp_nome', 'ig_cliente_id', 'assinatura',
                   'email_dev', 'avisar_dev', 'avisar_dev_minutos'];
    foreach ($permitidas as $k) {
        $v = $corpo[$k] ?? null;
        if ($v === null) continue;
        if ($k === 'smtp_senha' && $v === '********') continue;   // não apaga a senha guardada
        cfgSet($k, (string)$v);
    }
    jsonSaida(['ok' => true]);
}
case 'envios_limpar': {
    exigirLogin();
    $tipo = (string)$e('tipo', 'tudo');
    $sql = ['tudo' => 'DELETE FROM envios',
            'testes' => "DELETE FROM envios WHERE teste = 1",
            'erros' => "DELETE FROM envios WHERE status = 'erro'",
            '30dias' => 'DELETE FROM envios WHERE criado_em < NOW() - INTERVAL 30 DAY',
            '7dias' => 'DELETE FROM envios WHERE criado_em < NOW() - INTERVAL 7 DAY'][$tipo] ?? null;
    if (!$sql) jsonSaida(['ok' => false, 'erro' => 'Opção inválida']);
    $n = db()->exec($sql);
    logar('limpeza', "$tipo: $n registro(s)");
    jsonSaida(['ok' => true, 'removidos' => (int)$n]);
}
case 'cron_estado': {
    exigirLogin();
    jsonSaida(['ok' => true] + cronEstado());
}
case 'cron_salvar': {
    exigirLogin();
    $min = (int)$e('intervalo', 1);
    if (!in_array($min, [1, 2, 5, 10, 15, 30, 60], true)) jsonSaida(['ok' => false, 'erro' => 'Intervalo inválido']);
    cfgSet('cron_intervalo', $min);
    cfgSet('cron_pedido_em', agora());
    jsonSaida(['ok' => true] + cronEstado());
}
case 'cron_rodar': {
    exigirLogin();
    $feitos = processarFila();
    jsonSaida(['ok' => true, 'disparados' => count($feitos), 'itens' => $feitos]);
}
/* ---------- alerta automático de chuva ---------- */
case 'chuva_estado': {
    exigirLogin();
    require_once __DIR__ . '/alertachuva.php';
    jsonSaida(['ok' => true] + chuvaEstado());
}
case 'chuva_salvar': {
    exigirLogin();
    require_once __DIR__ . '/alertachuva.php';
    $permitidas = ['chuva_ativo', 'chuva_cidade', 'chuva_minimo', 'chuva_horas', 'chuva_intervalo',
                   'chuva_inicio', 'chuva_fim', 'chuva_tempestade', 'chuva_passou', 'chuva_imagem',
                   'chuva_titulo', 'chuva_mensagem', 'chuva_titulo_fim', 'chuva_msg_fim'];
    foreach ($permitidas as $k) {
        if (!array_key_exists($k, $corpo)) continue;
        cfgSet($k, (string)$corpo[$k]);
    }
    if (array_key_exists('chuva_canais', $corpo)) {
        $c = is_array($corpo['chuva_canais']) ? $corpo['chuva_canais'] : explode(',', (string)$corpo['chuva_canais']);
        $c = array_values(array_intersect(array_map('trim', $c), ['whatsapp', 'direct', 'email']));
        cfgSet('chuva_canais', implode(',', $c));
    }
    jsonSaida(['ok' => true] + chuvaEstado());
}
case 'chuva_previa': {
    exigirLogin();
    require_once __DIR__ . '/alertachuva.php';
    $a = chuvaAnalisar((string)$e('cidade', '') ?: null,
                       $e('minimo', '') !== '' ? (int)$e('minimo') : null,
                       $e('horas', '') !== '' ? (int)$e('horas') : null);
    $c = db()->query('SELECT * FROM contatos WHERE ativo = 1 AND alerta_chuva = 1 ORDER BY nome LIMIT 1')->fetch()
         ?: ['nome' => 'Maria Silva', 'whatsapp' => '82988717072', 'email' => 'maria@email.com'];
    if (empty($a['ok'])) jsonSaida(['ok' => false, 'erro' => (string)($a['erro'] ?? 'previsão indisponível')]);
    $demo = $a['vai_chover'] ? $a : array_merge($a, ['quando' => 'por volta das 15:00', 'hora' => '15:00',
                                   'descricao' => 'pancadas de chuva', 'icone' => '🌧️', 'pico' => 80, 'mm' => 6.2]);
    jsonSaida(['ok' => true, 'analise' => $a, 'contato' => $c['nome'],
               'titulo' => chuvaTexto((string)chuvaCfg('chuva_titulo'), $demo, $c, 'whatsapp'),
               'mensagem' => chuvaTexto((string)chuvaCfg('chuva_mensagem'), $demo, $c, 'whatsapp'),
               'exemplo' => !$a['vai_chover']]);
}
case 'chuva_teste': {
    exigirLogin();
    require_once __DIR__ . '/alertachuva.php';
    $cid = (int)$e('contato_id');
    if (!$cid) jsonSaida(['ok' => false, 'erro' => 'Escolha para quem enviar o teste']);
    $st = db()->prepare('SELECT * FROM contatos WHERE id = ?');
    $st->execute([$cid]);
    $c = $st->fetch();
    if (!$c) jsonSaida(['ok' => false, 'erro' => 'Contato não encontrado']);
    $a = chuvaAnalisar(trim((string)($c['cidade'] ?? '')) ?: null);
    if (empty($a['ok'])) jsonSaida(['ok' => false, 'erro' => (string)($a['erro'] ?? 'previsão indisponível')]);
    /* sem chuva de verdade, manda um exemplo para dar para conferir o visual */
    if (!$a['vai_chover']) $a = array_merge($a, ['vai_chover' => true, 'quando' => 'por volta das 15:00',
        'hora' => '15:00', 'janela' => 'das 15h até 17h', 'descricao' => 'pancadas de chuva', 'codigo_chuva' => 80,
        'intensidade' => 'chuva moderada', 'pico' => 80, 'mm' => 6.2,
        'resumo' => 'Pancadas de chuva das 15h até 17h — pico de 80% e 6,2 mm nas próximas ' . $a['horas'] . 'h em ' . $a['cidade']]);
    $r = chuvaEnviar($a, false, true, '[TESTE] ', $cid);
    jsonSaida(['ok' => true, 'contato' => $c['nome'], 'cidade' => $a['cidade']] + $r);
}
case 'chuva_rodar': {
    exigirLogin();
    require_once __DIR__ . '/alertachuva.php';
    jsonSaida(chuvaRodar((string)$e('forcar', '') === '1') + ['ok' => true]);
}

case 'conexoes': {
    exigirLogin();
    jsonSaida(['ok' => true] + conexoesEstado(((string)$e('rapido', '')) !== '1'));
}
case 'wa_estado':      { exigirLogin(); jsonSaida(['ok' => true, 'whatsapp' => whatsappEstado(whatsappInstanciaDe((int)$e('id')))]); }
case 'wa_qr':          { exigirLogin(); jsonSaida(whatsappQr(whatsappInstanciaDe((int)$e('id')))); }
case 'wa_desconectar': { exigirLogin(); jsonSaida(whatsappDesconectar(whatsappInstanciaDe((int)$e('id')))); }
case 'wa_lista':       { exigirLogin(); jsonSaida(['ok' => true, 'itens' => whatsappLista()]); }
case 'wa_salvar': {
    exigirLogin();
    $id = (int)$e('id');
    $nome = trim((string)$e('nome')) ?: 'Número';
    $inst = trim((string)$e('instancia'));
    if ($inst === '') $inst = 'lembretes-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $nome) ?: bin2hex(random_bytes(3)));
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $inst)) jsonSaida(['ok' => false, 'erro' => 'Nome interno inválido (use letras, números, ponto, hífen).']);
    try {
        if ($id) db()->prepare('UPDATE whatsapps SET nome = ?, instancia = ?, ativo = ? WHERE id = ?')
                     ->execute([$nome, $inst, (int)!!$e('ativo', 1), $id]);
        else {
            db()->prepare('INSERT INTO whatsapps (nome, instancia) VALUES (?,?)')->execute([$nome, $inst]);
            $id = (int)db()->lastInsertId();
        }
    } catch (PDOException $ex) {
        jsonSaida(['ok' => false, 'erro' => 'Já existe um número com esse nome interno.']);
    }
    jsonSaida(['ok' => true, 'id' => $id]);
}
case 'wa_padrao': {
    exigirLogin();
    $id = (int)$e('id');
    db()->exec('UPDATE whatsapps SET padrao = 0');
    db()->prepare('UPDATE whatsapps SET padrao = 1 WHERE id = ?')->execute([$id]);
    jsonSaida(['ok' => true]);
}
case 'wa_excluir': {
    exigirLogin();
    $id = (int)$e('id');
    $w = db()->query('SELECT * FROM whatsapps WHERE id = ' . $id)->fetch();
    if (!$w) jsonSaida(['ok' => false, 'erro' => 'Número não encontrado']);
    if ((int)db()->query('SELECT COUNT(*) FROM whatsapps')->fetchColumn() <= 1) jsonSaida(['ok' => false, 'erro' => 'Deixe pelo menos um número cadastrado.']);
    if (((string)$e('apagar_instancia')) === '1') evo('DELETE', '/instance/delete/' . rawurlencode($w['instancia']), null, 20);
    db()->prepare('UPDATE lembretes SET whatsapp_id = NULL WHERE whatsapp_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM whatsapps WHERE id = ?')->execute([$id]);
    jsonSaida(['ok' => true]);
}
case 'ig_conversas':   { exigirLogin(); jsonSaida(['ok' => true, 'itens' => igConversas((int)($e('cliente_id') ?: cfg('ig_cliente_id')))]); }
case 'ig_procurar': {
    exigirLogin();
    $achado = igResolverUsuario((string)$e('usuario'));
    if ($achado) {
        $conta = igCliente((int)$achado['cliente_id']);
        $achado['conta'] = $conta['ig_username'] ?? '';
    }
    jsonSaida(['ok' => (bool)$achado, 'conversa' => $achado ?: null]);
}
case 'teste_aviso_dev': {
    exigirLogin();
    cfgSet('avisodev_' . substr(md5('teste-dev'), 0, 16), '');
    $ok = avisarDesenvolvedor('Teste de aviso ao desenvolvedor', 'Disparado manualmente pelo painel por ' . (usuarioAtual()['nome'] ?? '?') . '.', 'teste-dev');
    jsonSaida(['ok' => $ok, 'erro' => $ok ? '' : 'Não consegui enviar — confira o SMTP e o e-mail do desenvolvedor.']);
}
case 'teste_canal': {
    exigirLogin();
    $canal = (string)$e('canal');
    $contato = ['nome' => 'Teste', 'whatsapp' => (string)$e('whatsapp'), 'email' => (string)$e('email'),
                'ig_remetente_id' => (string)$e('ig_remetente_id'), 'ig_usuario' => (string)$e('ig_usuario'), 'ig_cliente_id' => $e('ig_cliente_id')];
    if ($canal === 'direct' && $contato['ig_remetente_id'] === '' && $contato['ig_usuario'] !== '') {
        $achado = igResolverUsuario($contato['ig_usuario']);
        if ($achado) { $contato['ig_remetente_id'] = $achado['remetente_id']; $contato['ig_cliente_id'] = $achado['cliente_id']; }
    }
    $ctxTeste = ['instancia' => whatsappInstanciaDe((int)$e('whatsapp_id'))];
    $r = enviarPorCanal($canal, $contato, 'Teste de lembrete', 'Se você recebeu esta mensagem, o canal ' . $canal . ' está funcionando. 🎉', $ctxTeste);
    registrarEnvio(null, null, $canal, $r, 'teste de canal', true, false, 1, 'Teste de canal');
    jsonSaida(['ok' => (bool)$r['ok'], 'detalhe' => (string)($r['detalhe'] ?? ''), 'destino' => (string)($r['destino'] ?? '')]);
}
case 'perfil': {
    $u = exigirLogin();
    $st = db()->prepare('SELECT usuario, nome, email FROM usuarios WHERE id = ?');
    $st->execute([$u['id']]);
    jsonSaida(['ok' => true, 'perfil' => $st->fetch()]);
}
case 'perfil_salvar': {
    $u = exigirLogin();
    $email = trim((string)$e('email'));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonSaida(['ok' => false, 'erro' => 'E-mail inválido']);
    $nome = trim((string)$e('nome')) ?: $u['nome'];
    db()->prepare('UPDATE usuarios SET email = ?, nome = ? WHERE id = ?')->execute([$email ?: null, $nome, $u['id']]);
    jsonSaida(['ok' => true]);
}
case 'senha_recuperar': {
    $quem = trim((string)$e('usuario'));
    /* resposta sempre igual: não revela se o usuário existe */
    $resposta = ['ok' => true, 'msg' => 'Se esse usuário ou e-mail estiver cadastrado, o link de recuperação chega em instantes.'];
    if ($quem === '') jsonSaida($resposta);
    $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
    $st->execute([$quem, $quem]);
    $u = $st->fetch();
    if (!$u || trim((string)$u['email']) === '') { logar('senha-recuperar', "sem e-mail para: $quem"); jsonSaida($resposta); }
    /* no máximo 3 pedidos por hora por usuário */
    $q = db()->prepare('SELECT COUNT(*) FROM senha_tokens WHERE usuario_id = ? AND criado_em >= NOW() - INTERVAL 1 HOUR');
    $q->execute([$u['id']]);
    if ((int)$q->fetchColumn() >= 3) { logar('senha-recuperar', "limite por hora: {$u['usuario']}"); jsonSaida($resposta); }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO senha_tokens (usuario_id, token, expira_em, ip) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR),?)')
        ->execute([$u['id'], $token, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    $link = 'https://alequizao.com/lembretes/?recuperar=' . $token;
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px">'
          . '<h2 style="color:#2563EB;margin:0 0 8px">Recuperação de senha</h2>'
          . '<p style="color:#374151">Olá, ' . htmlspecialchars($u['nome']) . '! Alguém pediu para redefinir a senha do painel de Lembretes.</p>'
          . '<p style="margin:22px 0"><a href="' . $link . '" style="background:#2563EB;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;display:inline-block">Criar uma nova senha</a></p>'
          . '<p style="color:#6b7280;font-size:13px">O link vale por <b>1 hora</b> e só pode ser usado uma vez.<br>'
          . 'Se não foi você, ignore este e-mail — a senha atual continua valendo.<br><br>'
          . 'Link: <span style="word-break:break-all">' . $link . '</span></p></div>';
    $det = '';
    $enviado = smtpEnviar((string)$u['email'], 'Recuperação de senha — Lembretes', $html, $det);
    logar('senha-recuperar', ($enviado ? 'enviado para ' : 'FALHOU para ') . $u['email'] . ' ' . $det);
    if (!$enviado) avisarDesenvolvedor('Falha ao enviar recuperação de senha', $u['email'] . ': ' . $det, 'senha-recuperar');
    jsonSaida($resposta);
}
case 'senha_redefinir': {
    $token = preg_replace('/[^a-f0-9]/', '', (string)$e('token'));
    $nova = (string)$e('nova');
    if (strlen($nova) < 4) jsonSaida(['ok' => false, 'erro' => 'A senha precisa ter ao menos 4 caracteres']);
    $st = db()->prepare('SELECT * FROM senha_tokens WHERE token = ? AND usado = 0 AND expira_em > NOW() LIMIT 1');
    $st->execute([$token]);
    $t = $st->fetch();
    if (!$t) jsonSaida(['ok' => false, 'erro' => 'Este link expirou ou já foi usado. Peça um novo.']);
    db()->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $t['usuario_id']]);
    db()->prepare('UPDATE senha_tokens SET usado = 1 WHERE id = ?')->execute([$t['id']]);
    db()->prepare('UPDATE senha_tokens SET usado = 1 WHERE usuario_id = ? AND usado = 0')->execute([$t['usuario_id']]);
    $u = db()->query('SELECT usuario FROM usuarios WHERE id = ' . (int)$t['usuario_id'])->fetchColumn();
    logar('senha-redefinida', 'usuario: ' . $u);
    jsonSaida(['ok' => true, 'usuario' => $u]);
}
case 'senha_trocar': {
    $u = exigirLogin();
    $nova = (string)$e('nova');
    if (strlen($nova) < 4) jsonSaida(['ok' => false, 'erro' => 'A senha precisa ter ao menos 4 caracteres']);
    $st = db()->prepare('SELECT senha FROM usuarios WHERE id = ?'); $st->execute([$u['id']]);
    if (!password_verify((string)$e('atual'), (string)$st->fetchColumn())) jsonSaida(['ok' => false, 'erro' => 'Senha atual incorreta']);
    db()->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
    jsonSaida(['ok' => true]);
}

default: jsonSaida(['ok' => false, 'erro' => 'Ação desconhecida: ' . $acao]);
}
} catch (Throwable $ex) {
    logar('erro-api', $acao . ': ' . $ex->getMessage());
    try { avisarDesenvolvedor('Erro no servidor (API)', $acao . ': ' . $ex->getMessage()
        . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine(), 'erro-api'); } catch (Throwable $e2) {}
    http_response_code(500);
    jsonSaida(['ok' => false, 'erro' => 'Erro no servidor: ' . $ex->getMessage()]);
}
