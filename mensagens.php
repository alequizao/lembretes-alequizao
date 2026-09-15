<?php
/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Caixa de entrada unificada (WhatsApp + Direct do Instagram).
   Só o dono do painel enxerga isto — veja exigirDono() em lib.php. */
require_once __DIR__ . '/midias.php';

define('MIDIA_CACHE_DIR', __DIR__ . '/uploads/.cache-midia');

/* ---------- caixas (uma por número de WhatsApp e uma por @ do Instagram) ---------- */
function msgCaixas(): array {
    $caixas = [];
    foreach (whatsappLista() as $w) {
        $est = whatsappEstado($w['instancia']);
        $caixas[] = [
            'chave' => 'whatsapp:' . $w['id'],
            'tipo' => 'whatsapp',
            'id' => (int)$w['id'],
            'nome' => $w['nome'],
            'apelido' => $w['numero'] ? telefoneBonito((string)$w['numero']) : $w['instancia'],
            'conectado' => !empty($est['ok']),
            'situacao' => (string)($est['estado'] ?? ''),
        ];
    }
    foreach (igContas() as $c) {
        $caixas[] = [
            'chave' => 'direct:' . $c['id'],
            'tipo' => 'direct',
            'id' => (int)$c['id'],
            'nome' => $c['nome'],
            'apelido' => '@' . $c['ig_username'],
            'conectado' => !empty($c['tem_token']),
            'situacao' => empty($c['tem_token']) ? 'sem token' : 'conectado',
        ];
    }
    return $caixas;
}

/* 5582991xxxxx -> (82) 99100-0132 */
function telefoneBonito(string $tel): string {
    $d = so_digitos($tel);
    if (strlen($d) >= 12 && substr($d, 0, 2) === '55') $d = substr($d, 2);
    if (strlen($d) === 11) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    if (strlen($d) === 10) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    return $tel;
}

function msgJidBonito(string $jid): string {
    $n = explode('@', $jid)[0];
    if (strpos($jid, '@g.us') !== false) return 'Grupo';
    return telefoneBonito($n);
}

/* Texto legível de qualquer tipo de mensagem do WhatsApp. */
function msgTextoWa(array $m): array {
    $msg = $m['message'] ?? [];
    $t = $m['messageType'] ?? '';
    $pega = function ($caminhos) use ($msg) {
        foreach ($caminhos as $c) {
            $v = $msg;
            foreach (explode('.', $c) as $p) { if (!is_array($v) || !isset($v[$p])) { $v = null; break; } $v = $v[$p]; }
            if (is_string($v) && $v !== '') return $v;
        }
        return '';
    };
    $texto = $pega(['conversation', 'extendedTextMessage.text', 'imageMessage.caption', 'videoMessage.caption',
                    'documentMessage.caption', 'buttonsResponseMessage.selectedDisplayText',
                    'listResponseMessage.title', 'templateButtonReplyMessage.selectedDisplayText',
                    'ephemeralMessage.message.conversation', 'ephemeralMessage.message.extendedTextMessage.text']);
    $rotulos = ['imageMessage' => '📷 Foto', 'videoMessage' => '🎬 Vídeo', 'audioMessage' => '🎤 Áudio',
                'documentMessage' => '📄 Documento', 'stickerMessage' => '🩷 Figurinha', 'locationMessage' => '📍 Localização',
                'contactMessage' => '👤 Contato', 'reactionMessage' => '❤️ Reação'];
    $anexo = $rotulos[$t] ?? '';
    if ($texto === '' && $anexo === '' && $t !== 'conversation' && $t !== 'extendedTextMessage') $anexo = '💬 ' . $t;
    $familias = ['imageMessage' => 'imagem', 'videoMessage' => 'video', 'audioMessage' => 'audio',
                 'stickerMessage' => 'figurinha', 'documentMessage' => 'documento'];
    $corpo = $msg[$t] ?? [];
    return [
        'texto' => $texto, 'anexo' => $anexo,
        'midia_tipo' => $familias[$t] ?? '',
        'mime' => is_array($corpo) ? (string)($corpo['mimetype'] ?? '') : '',
        'arquivo' => is_array($corpo) ? (string)($corpo['fileName'] ?? '') : '',
    ];
}

/* ---------- conversas ---------- */
function msgConversas(string $tipo, int $id, string $busca = ''): array {
    $busca = mb_strtolower(trim($busca));
    $itens = [];

    if ($tipo === 'whatsapp') {
        $inst = whatsappInstanciaDe($id);
        $r = evo('POST', '/chat/findChats/' . $inst, []);
        foreach ((array)($r['dados'] ?? []) as $c) {
            if (!is_array($c) || empty($c['remoteJid'])) continue;
            $jid = (string)$c['remoteJid'];
            if (strpos($jid, '@broadcast') !== false || $jid === 'status@broadcast') continue;
            $ult = is_array($c['lastMessage'] ?? null) ? $c['lastMessage'] : [];
            $p = $ult ? msgTextoWa($ult) : ['texto' => '', 'anexo' => ''];
            $nome = trim((string)($c['pushName'] ?? '')) ?: trim((string)($ult['pushName'] ?? ''));
            if ($nome === 'Você') $nome = '';
            $quando = !empty($ult['messageTimestamp']) ? date('Y-m-d H:i:s', (int)$ult['messageTimestamp'])
                                                       : (!empty($c['updatedAt']) ? date('Y-m-d H:i:s', strtotime($c['updatedAt'])) : null);
            $itens[] = [
                'id' => $jid,
                'nome' => $nome ?: msgJidBonito($jid),
                'apelido' => msgJidBonito($jid),
                'foto' => (string)($c['profilePicUrl'] ?? ''),
                'ultima' => trim($p['texto'] !== '' ? $p['texto'] : $p['anexo']),
                'minha' => !empty($ult['key']['fromMe']),
                'quando' => $quando,
                'nao_lidas' => (int)($c['unreadCount'] ?? 0),
                'grupo' => strpos($jid, '@g.us') !== false,
            ];
        }
        usort($itens, fn($a, $b) => strcmp((string)$b['quando'], (string)$a['quando']));
    } elseif ($tipo === 'direct') {
        $db = dbIg();
        if (!$db) return [];
        $st = $db->prepare('SELECT id, remetente_id, nome, foto, ultima_msg, ultima_em, ultima_recebida_em, nao_lidas, segue
                            FROM dm_conversas WHERE cliente_id = ? ORDER BY ultima_em DESC LIMIT 300');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $c) {
            $itens[] = [
                'id' => (string)$c['id'],
                'nome' => $c['nome'] ? '@' . ltrim((string)$c['nome'], '@') : ('IGSID ' . $c['remetente_id']),
                'apelido' => $c['nome'] ? '@' . ltrim((string)$c['nome'], '@') : '',
                'foto' => (string)($c['foto'] ?? ''),
                'ultima' => (string)($c['ultima_msg'] ?? ''),
                'minha' => false,
                'quando' => $c['ultima_em'],
                'nao_lidas' => (int)$c['nao_lidas'],
                'grupo' => false,
                'janela_ok' => !empty($c['ultima_recebida_em']) && strtotime($c['ultima_recebida_em']) > time() - 24 * 3600,
            ];
        }
    }

    if ($busca !== '') {
        $itens = array_values(array_filter($itens, fn($i) =>
            mb_strpos(mb_strtolower($i['nome'] . ' ' . $i['apelido'] . ' ' . $i['ultima']), $busca) !== false));
    }
    return $itens;
}

/* ---------- mensagens de uma conversa ---------- */
function msgMensagens(string $tipo, int $id, string $conversa, int $limite = 60): array {
    $itens = [];

    if ($tipo === 'whatsapp') {
        $inst = whatsappInstanciaDe($id);
        $r = evo('POST', '/chat/findMessages/' . $inst,
                 ['where' => ['key' => ['remoteJid' => $conversa]], 'page' => 1, 'offset' => $limite], 25);
        $regs = $r['dados']['messages']['records'] ?? [];
        foreach ((array)$regs as $m) {
            if (!is_array($m)) continue;
            $p = msgTextoWa($m);
            $st = '';
            foreach ((array)($m['MessageUpdate'] ?? []) as $u) {
                $s = (string)($u['status'] ?? '');
                if ($s === 'READ') { $st = 'lida'; break; }
                if ($s === 'DELIVERY_ACK') $st = 'entregue';
                elseif ($st === '' && $s === 'SERVER_ACK') $st = 'enviada';
            }
            $itens[] = [
                'id' => (string)($m['id'] ?? ''),
                'minha' => !empty($m['key']['fromMe']),
                'autor' => (string)($m['pushName'] ?? ''),
                'texto' => $p['texto'],
                'anexo' => $p['anexo'],
                'midia_tipo' => $p['midia_tipo'],
                'midia_id' => $p['midia_tipo'] ? (string)($m['key']['id'] ?? '') : '',
                'mime' => $p['mime'],
                'arquivo' => $p['arquivo'],
                'quando' => !empty($m['messageTimestamp']) ? date('Y-m-d H:i:s', (int)$m['messageTimestamp']) : null,
                'situacao' => $st ?: (string)($m['status'] ?? ''),
            ];
        }
        usort($itens, fn($a, $b) => strcmp((string)$a['quando'], (string)$b['quando']));
    } elseif ($tipo === 'direct') {
        $db = dbIg();
        if (!$db) return [];
        /* a conversa precisa ser da conta escolhida */
        $dono = $db->prepare('SELECT id FROM dm_conversas WHERE id = ? AND cliente_id = ?');
        $dono->execute([(int)$conversa, $id]);
        if (!$dono->fetchColumn()) return [];
        $st = $db->prepare('SELECT id, direcao, texto, midia, midia_tipo, origem, criado_em FROM dm_mensagens
                            WHERE conversa_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limite)));
        $st->execute([(int)$conversa]);
        foreach (array_reverse($st->fetchAll()) as $m) {
            $itens[] = [
                'id' => (string)$m['id'],
                'minha' => $m['direcao'] === 'out',
                'autor' => '',
                'texto' => (string)$m['texto'],
                'anexo' => $m['midia'] ? ('📎 ' . ($m['midia_tipo'] ?: 'mídia')) : '',
                'midia_tipo' => $m['midia'] ? msgTipoIg((string)$m['midia_tipo'], (string)$m['midia']) : '',
                'midia_id' => $m['midia'] ? (string)$m['id'] : '',
                'mime' => '', 'arquivo' => '',
                'quando' => $m['criado_em'],
                'situacao' => $m['origem'] !== 'humano' ? 'automática' : '',
            ];
        }
    }
    return $itens;
}

/* ---------- responder ---------- */
function msgEnviar(string $tipo, int $id, string $conversa, string $texto): array {
    $texto = trim($texto);
    if ($texto === '') return ['ok' => false, 'erro' => 'Escreva alguma coisa antes de enviar.'];

    if ($tipo === 'whatsapp') {
        $inst = whatsappInstanciaDe($id);
        $r = evo('POST', '/message/sendText/' . $inst, ['number' => $conversa, 'text' => $texto], 25);
        $ok = $r['codigo'] >= 200 && $r['codigo'] < 300;
        if (!$ok) {
            $e = $r['dados']['response']['message'] ?? ($r['dados']['message'] ?? ('HTTP ' . $r['codigo']));
            return ['ok' => false, 'erro' => is_array($e) ? implode(' · ', array_map('strval', $e)) : (string)$e];
        }
        registrarEnvio(null, null, 'whatsapp', ['ok' => true, 'destino' => $conversa, 'detalhe' => 'caixa de mensagens'],
                       $texto, false, false, 1);
        return ['ok' => true];
    }

    if ($tipo === 'direct') {
        $db = dbIg();
        if (!$db) return ['ok' => false, 'erro' => 'Banco do Instagram indisponível.'];
        $st = $db->prepare('SELECT * FROM dm_conversas WHERE id = ? AND cliente_id = ?');
        $st->execute([(int)$conversa, $id]);
        $c = $st->fetch();
        if (!$c) return ['ok' => false, 'erro' => 'Conversa não encontrada nessa conta.'];
        $cli = igCliente($id);
        if (!$cli || empty($cli['access_token'])) {
            return ['ok' => false, 'erro' => 'A conta está sem token — reconecte em /agendamentos/cliente_instagram.php'];
        }
        if (empty($c['ultima_recebida_em']) || strtotime($c['ultima_recebida_em']) < time() - 24 * 3600) {
            return ['ok' => false, 'erro' => 'Passaram-se mais de 24 h desde a última mensagem da pessoa — a Meta bloqueia a resposta.'];
        }
        $r = http_json('POST', IG_GRAPH . '/me/messages?access_token=' . urlencode($cli['access_token']),
                       ['recipient' => ['id' => $c['remetente_id']], 'message' => ['text' => $texto]], [], 25);
        if ($r['codigo'] < 200 || $r['codigo'] >= 300) {
            return ['ok' => false, 'erro' => (string)($r['dados']['error']['message'] ?? ('HTTP ' . $r['codigo']))];
        }
        $mid = (string)($r['dados']['message_id'] ?? '');
        $db->prepare('INSERT INTO dm_mensagens (conversa_id, direcao, texto, mid, origem) VALUES (?, "out", ?, ?, "humano")')
           ->execute([(int)$conversa, $texto, $mid ?: null]);
        $db->prepare('UPDATE dm_conversas SET ultima_msg = ?, ultima_em = NOW() WHERE id = ?')
           ->execute([mb_substr($texto, 0, 500), (int)$conversa]);
        registrarEnvio(null, null, 'direct', ['ok' => true, 'destino' => '@' . $c['nome'], 'detalhe' => 'caixa de mensagens'],
                       $texto, false, false, 1);
        return ['ok' => true];
    }
    return ['ok' => false, 'erro' => 'Canal desconhecido.'];
}

/* Zera o contador de não lidas (só o Direct guarda isso no nosso banco). */
function msgMarcarLida(string $tipo, int $id, string $conversa): void {
    if ($tipo === 'direct' && ($db = dbIg())) {
        $db->prepare('UPDATE dm_conversas SET nao_lidas = 0 WHERE id = ? AND cliente_id = ?')->execute([(int)$conversa, $id]);
    } elseif ($tipo === 'whatsapp') {
        evo('POST', '/chat/markMessageAsRead/' . whatsappInstanciaDe($id), ['readMessages' => [['remoteJid' => $conversa]]], 10);
    }
}

/* O Direct guarda a URL da mídia; o tipo vem do campo ou da extensão. */
function msgTipoIg(string $tipo, string $url): string {
    $t = mb_strtolower($tipo);
    foreach (['imagem' => ['image', 'imagem', 'foto', 'photo'], 'video' => ['video', 'reel', 'ig_reel'],
              'audio' => ['audio', 'voice'], 'figurinha' => ['sticker', 'figurinha']] as $familia => $chaves) {
        foreach ($chaves as $c) if ($t !== '' && strpos($t, $c) !== false) return $familia;
    }
    $ext = mb_strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'imagem';
    if (in_array($ext, ['mp4', 'mov', 'webm'], true)) return 'video';
    if (in_array($ext, ['mp3', 'ogg', 'm4a', 'aac'], true)) return 'audio';
    return $url !== '' ? 'imagem' : '';
}

/* Baixa o arquivo de uma mensagem. Devolve ['mime' => ..., 'bytes' => ..., 'nome' => ...] ou null. */
function msgBaixarMidia(string $tipo, int $id, string $midiaId) {
    if ($midiaId === '') return null;

    if ($tipo === 'whatsapp') {
        $cache = MIDIA_CACHE_DIR . '/wa_' . preg_replace('/[^A-Za-z0-9]/', '', $midiaId);
        if (is_file($cache) && filesize($cache) > 0) {
            $meta = @json_decode((string)@file_get_contents($cache . '.json'), true) ?: [];
            return ['mime' => (string)($meta['mime'] ?? 'application/octet-stream'),
                    'nome' => (string)($meta['nome'] ?? 'arquivo'), 'bytes' => (string)file_get_contents($cache)];
        }
        $r = evo('POST', '/chat/getBase64FromMediaMessage/' . whatsappInstanciaDe($id),
                 ['message' => ['key' => ['id' => $midiaId]], 'convertToMp4' => false], 60);
        $b64 = (string)($r['dados']['base64'] ?? '');
        if ($b64 === '') return null;
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') return null;
        $mime = (string)($r['dados']['mimetype'] ?? 'application/octet-stream');
        $nome = (string)($r['dados']['fileName'] ?? 'arquivo');
        if (!is_dir(MIDIA_CACHE_DIR)) @mkdir(MIDIA_CACHE_DIR, 0775, true);
        @file_put_contents($cache, $bytes);
        @file_put_contents($cache . '.json', json_encode(['mime' => $mime, 'nome' => $nome]));
        return ['mime' => $mime, 'nome' => $nome, 'bytes' => $bytes];
    }

    if ($tipo === 'direct') {
        $db = dbIg();
        if (!$db) return null;
        $st = $db->prepare('SELECT m.midia FROM dm_mensagens m JOIN dm_conversas c ON c.id = m.conversa_id
                            WHERE m.id = ? AND c.cliente_id = ?');
        $st->execute([(int)$midiaId, $id]);
        $url = (string)$st->fetchColumn();
        if ($url === '' || !preg_match('#^https?://#', $url)) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
                                CURLOPT_USERAGENT => 'LembretesAlequizao/1.0']);
        $bytes = curl_exec($ch);
        $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($cod !== 200 || $bytes === false || $bytes === '') return null;
        return ['mime' => $mime ?: 'application/octet-stream', 'nome' => 'direct-' . (int)$midiaId, 'bytes' => (string)$bytes];
    }
    return null;
}

/* Envia um arquivo pela caixa de mensagens (usa o mesmo cofre de uploads dos lembretes). */
function msgEnviarMidia(string $tipo, int $id, string $conversa, array $arquivo, string $legenda = ''): array {
    $g = midiaGuardar($arquivo);
    if (empty($g['ok'])) return ['ok' => false, 'erro' => $g['erro']];
    $caminho = MIDIA_DIR . '/' . $g['arquivo'];
    $legenda = trim($legenda);
    $rotulo = '[' . $g['tipo'] . '] ' . $g['nome'] . ($legenda !== '' ? ' — ' . $legenda : '');

    if ($tipo === 'whatsapp') {
        $inst = whatsappInstanciaDe($id);
        $b64 = base64_encode((string)file_get_contents($caminho));
        if ($g['tipo'] === 'audio') {
            $r = evo('POST', '/message/sendWhatsAppAudio/' . $inst, ['number' => $conversa, 'audio' => $b64], 90);
        } else {
            $r = evo('POST', '/message/sendMedia/' . $inst, [
                'number' => $conversa,
                'mediatype' => in_array($g['tipo'], ['image', 'video'], true) ? $g['tipo'] : 'document',
                'mimetype' => $g['mime'], 'media' => $b64, 'fileName' => $g['nome'], 'caption' => $legenda,
            ], 180);
        }
        $ok = $r['codigo'] >= 200 && $r['codigo'] < 300;
        if (!$ok) {
            @unlink($caminho);
            $m = $r['dados']['response']['message'][0] ?? ($r['dados']['message'] ?? ('HTTP ' . $r['codigo']));
            return ['ok' => false, 'erro' => is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE) : (string)$m];
        }
        registrarEnvio(null, null, 'whatsapp', ['ok' => true, 'destino' => $conversa, 'detalhe' => 'caixa de mensagens'],
                       $rotulo, false, false, 1);
        return ['ok' => true];
    }

    if ($tipo === 'direct') {
        if ($g['tipo'] === 'document') { @unlink($caminho); return ['ok' => false, 'erro' => 'O Direct não aceita documentos — só imagem, vídeo ou áudio.']; }
        $db = dbIg();
        if (!$db) { @unlink($caminho); return ['ok' => false, 'erro' => 'Banco do Instagram indisponível.']; }
        $st = $db->prepare('SELECT * FROM dm_conversas WHERE id = ? AND cliente_id = ?');
        $st->execute([(int)$conversa, $id]);
        $c = $st->fetch();
        $cli = igCliente($id);
        if (!$c || !$cli || empty($cli['access_token'])) { @unlink($caminho); return ['ok' => false, 'erro' => 'Conversa ou token indisponível.']; }
        if (empty($c['ultima_recebida_em']) || strtotime($c['ultima_recebida_em']) < time() - 24 * 3600) {
            @unlink($caminho);
            return ['ok' => false, 'erro' => 'Passaram-se mais de 24 h desde a última mensagem da pessoa — a Meta bloqueia o envio.'];
        }
        $url = midiaUrlBase() . $g['arquivo'];
        $r = http_json('POST', IG_GRAPH . '/me/messages?access_token=' . urlencode($cli['access_token']),
            ['recipient' => ['id' => $c['remetente_id']],
             'message' => ['attachment' => ['type' => $g['tipo'], 'payload' => ['url' => $url]]]], [], 90);
        if ($r['codigo'] < 200 || $r['codigo'] >= 300) {
            return ['ok' => false, 'erro' => (string)($r['dados']['error']['message'] ?? ('HTTP ' . $r['codigo']))];
        }
        $db->prepare('INSERT INTO dm_mensagens (conversa_id, direcao, texto, midia, midia_tipo, mid, origem)
                      VALUES (?, "out", ?, ?, ?, ?, "humano")')
           ->execute([(int)$conversa, $legenda, $url, $g['tipo'], (string)($r['dados']['message_id'] ?? '') ?: null]);
        $db->prepare('UPDATE dm_conversas SET ultima_msg = ?, ultima_em = NOW() WHERE id = ?')
           ->execute([mb_substr($legenda !== '' ? $legenda : ('📎 ' . $g['tipo']), 0, 500), (int)$conversa]);
        registrarEnvio(null, null, 'direct', ['ok' => true, 'destino' => '@' . $c['nome'], 'detalhe' => 'caixa de mensagens'],
                       $rotulo, false, false, 1);
        /* o Direct baixa o arquivo da nossa URL, então ele precisa continuar existindo */
        return ['ok' => true];
    }
    @unlink($caminho);
    return ['ok' => false, 'erro' => 'Canal desconhecido.'];
}
