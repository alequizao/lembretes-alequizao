<?php
/* Anexos dos lembretes: upload, envio por WhatsApp/Direct/E-mail. */
require_once __DIR__ . '/canais.php';

define('MIDIA_DIR', __DIR__ . '/uploads');
define('MIDIA_MAX', 16 * 1024 * 1024);          // 16 MB (limite prático do WhatsApp)
define('EMAIL_ANEXO_MAX', 7 * 1024 * 1024);     // acima disso o e-mail manda o link

function midiaUrlBase(): string {
    $proto = (isset($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
              ($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'alequizao.com';
    return $proto . '://' . $host . '/lembretes/uploads/';
}

/* Classifica o arquivo em image | video | audio | document. */
function midiaTipo(string $mime, string $nome): string {
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    return 'document';
}

function midiaGuardar(array $arquivo): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msgs = [UPLOAD_ERR_INI_SIZE => 'Arquivo grande demais.', UPLOAD_ERR_FORM_SIZE => 'Arquivo grande demais.',
                 UPLOAD_ERR_PARTIAL => 'O envio foi interrompido.', UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.'];
        return ['ok' => false, 'erro' => $msgs[$arquivo['error'] ?? 0] ?? 'Falha no envio do arquivo.'];
    }
    if ($arquivo['size'] > MIDIA_MAX) return ['ok' => false, 'erro' => 'O arquivo passa de 16 MB.'];
    if (!is_dir(MIDIA_DIR)) @mkdir(MIDIA_DIR, 0775, true);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']) ?: ($arquivo['type'] ?? 'application/octet-stream');
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,5}$/', $ext)) $ext = 'bin';
    if (in_array($ext, ['php', 'phtml', 'phar', 'htaccess', 'sh', 'cgi', 'pl'], true)) return ['ok' => false, 'erro' => 'Tipo de arquivo não permitido.'];
    $nomeArq = date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($arquivo['tmp_name'], MIDIA_DIR . '/' . $nomeArq)) return ['ok' => false, 'erro' => 'Não consegui salvar o arquivo.'];
    @chmod(MIDIA_DIR . '/' . $nomeArq, 0644);
    return ['ok' => true, 'arquivo' => $nomeArq, 'nome' => mb_substr($arquivo['name'], 0, 190),
            'mime' => $mime, 'tipo' => midiaTipo($mime, $arquivo['name']), 'tamanho' => (int)$arquivo['size']];
}

function midiasDoLembrete(int $lembreteId): array {
    $st = db()->prepare('SELECT * FROM lembrete_midias WHERE lembrete_id = ? ORDER BY id');
    $st->execute([$lembreteId]);
    $itens = $st->fetchAll();
    foreach ($itens as &$m) $m['url'] = midiaUrlBase() . $m['arquivo'];
    return $itens;
}

function midiaExcluir(int $id): void {
    $st = db()->prepare('SELECT arquivo FROM lembrete_midias WHERE id = ?');
    $st->execute([$id]);
    if ($arq = $st->fetchColumn()) @unlink(MIDIA_DIR . '/' . $arq);
    db()->prepare('DELETE FROM lembrete_midias WHERE id = ?')->execute([$id]);
}

function tamanhoLegivel(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}

/* ---------------- WhatsApp ---------------- */
function enviarWhatsappMidia(string $telefone, array $m, string $legenda = '', ?string $instancia = null): array {
    $num = telefone_wa($telefone);
    if ($num === '') return ['ok' => false, 'detalhe' => 'Contato sem número de WhatsApp'];
    $caminho = MIDIA_DIR . '/' . $m['arquivo'];
    if (!is_file($caminho)) return ['ok' => false, 'detalhe' => 'Arquivo do anexo não encontrado no servidor'];
    $inst = rawurlencode($instancia ?: (whatsappPadrao()['instancia'] ?? evoInstancia()));
    $b64 = base64_encode((string)file_get_contents($caminho));
    if ($m['tipo'] === 'audio') {
        $r = evo('POST', '/message/sendWhatsAppAudio/' . $inst, ['number' => $num, 'audio' => $b64], 90);
    } else {
        $r = evo('POST', '/message/sendMedia/' . $inst, [
            'number' => $num,
            'mediatype' => in_array($m['tipo'], ['image', 'video'], true) ? $m['tipo'] : 'document',
            'mimetype' => $m['mime'],
            'media' => $b64,
            'fileName' => $m['nome'],
            'caption' => $legenda,
        ], 120);
    }
    if ($r['codigo'] >= 200 && $r['codigo'] < 300) return ['ok' => true, 'destino' => $num, 'detalhe' => 'anexo ' . $m['nome'] . ' enviado'];
    $msg = $r['dados']['response']['message'][0] ?? ($r['dados']['message'] ?? ($r['erro'] ?: 'HTTP ' . $r['codigo']));
    if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    return ['ok' => false, 'destino' => $num, 'detalhe' => 'anexo ' . $m['nome'] . ': ' . $msg];
}

/* ---------------- Direct ---------------- */
function enviarDirectMidia(array $contato, array $m): array {
    if ($m['tipo'] === 'document') return ['ok' => false, 'detalhe' => 'O Direct não aceita documentos — só imagem, vídeo ou áudio'];
    $cli = igCliente($contato['ig_cliente_id'] ? (int)$contato['ig_cliente_id'] : null);
    $destino = trim((string)($contato['ig_remetente_id'] ?? ''));
    if ($destino === '' && !empty($contato['ig_usuario'])) {
        $achado = igResolverUsuario((string)$contato['ig_usuario'], $contato['ig_cliente_id'] ? (int)$contato['ig_cliente_id'] : null);
        if ($achado) { $destino = $achado['remetente_id']; $cli = igCliente((int)$achado['cliente_id']) ?: $cli; }
    }
    if (!$cli || empty($cli['access_token'])) return ['ok' => false, 'detalhe' => 'Conta do Instagram sem token'];
    if ($destino === '') return ['ok' => false, 'detalhe' => 'Sem conversa do Direct para este contato'];
    $url = IG_GRAPH . '/me/messages?access_token=' . urlencode($cli['access_token']);
    $r = http_json('POST', $url, ['recipient' => ['id' => $destino],
        'message' => ['attachment' => ['type' => $m['tipo'], 'payload' => ['url' => midiaUrlBase() . $m['arquivo']]]]], [], 60);
    if ($r['codigo'] >= 200 && $r['codigo'] < 300) return ['ok' => true, 'destino' => '@' . ($contato['ig_usuario'] ?? ''), 'detalhe' => 'anexo ' . $m['nome'] . ' enviado'];
    return ['ok' => false, 'destino' => '@' . ($contato['ig_usuario'] ?? ''),
            'detalhe' => 'anexo ' . $m['nome'] . ': ' . ($r['dados']['error']['message'] ?? ('HTTP ' . $r['codigo']))];
}

/* ---------------- E-mail: anexos de verdade ---------------- */
function anexosParaEmail(array $midias): array {
    $anexos = [];
    foreach ($midias as $m) {
        $caminho = MIDIA_DIR . '/' . $m['arquivo'];
        if (is_file($caminho) && filesize($caminho) <= EMAIL_ANEXO_MAX) {
            $anexos[] = ['nome' => $m['nome'], 'mime' => $m['mime'], 'conteudo' => (string)file_get_contents($caminho)];
        }
    }
    return $anexos;
}
