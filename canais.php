<?php
/* Envio pelos três canais: WhatsApp (Evolution), Direct (Instagram Graph) e E-mail (SMTP). */
require_once __DIR__ . '/lib.php';

/* ================= HTTP ================= */
function http_json(string $metodo, string $url, array $corpo = null, array $cabecalhos = [], int $timeout = 20): array {
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $cabecalhos);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    $cod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['codigo' => 0, 'dados' => [], 'erro' => $err ?: 'falha de conexão'];
    $dados = json_decode($resp, true);
    return ['codigo' => $cod, 'dados' => is_array($dados) ? $dados : [], 'bruto' => $resp, 'erro' => ''];
}

/* ================= WhatsApp (Evolution API) ================= */
function evo(string $metodo, string $caminho, array $corpo = null, int $timeout = 20): array {
    return http_json($metodo, rtrim(EVO_URL, '/') . $caminho, $corpo, ['apikey: ' . EVO_KEY], $timeout);
}
function evoInstancia(): string { return cfg('evo_instancia', 'lembretes') ?: 'lembretes'; }

/* ---- vários números de WhatsApp ---- */
function whatsappLista(): array {
    return db()->query('SELECT * FROM whatsapps ORDER BY padrao DESC, id')->fetchAll();
}
function whatsappPadrao(): ?array {
    $r = db()->query('SELECT * FROM whatsapps WHERE ativo = 1 ORDER BY padrao DESC, id LIMIT 1')->fetch();
    return $r ?: null;
}
function whatsappPorId(?int $id): ?array {
    if ($id) {
        $st = db()->prepare('SELECT * FROM whatsapps WHERE id = ?');
        $st->execute([$id]);
        if ($r = $st->fetch()) return $r;
    }
    return whatsappPadrao();
}
function whatsappInstanciaDe(?int $id): string {
    $w = whatsappPorId($id);
    return $w['instancia'] ?? evoInstancia();
}

/* Estado da instância: aberta (conectada), fechada, inexistente. */
function whatsappEstado(?string $nome = null): array {
    $nome = $nome ?: (whatsappPadrao()['instancia'] ?? evoInstancia());
    $r = evo('GET', '/instance/fetchInstances?instanceName=' . rawurlencode($nome), null, 10);
    if ($r['codigo'] === 0) return ['ok' => false, 'estado' => 'offline', 'msg' => 'Evolution API não respondeu: ' . $r['erro']];
    $lista = $r['dados'];
    if (!$lista || !isset($lista[0]) || !is_array($lista[0])) return ['ok' => false, 'estado' => 'inexistente', 'msg' => 'Instância "' . $nome . '" ainda não foi criada.'];
    $i = $lista[0];
    $estado = $i['connectionStatus'] ?? ($i['instance']['state'] ?? ($i['status'] ?? 'desconhecido'));
    $numero = $i['ownerJid'] ?? ($i['number'] ?? '');
    return [
        'ok' => in_array($estado, ['open', 'connected'], true),
        'estado' => $estado, 'numero' => explode('@', (string)$numero)[0],
        'perfil' => $i['profileName'] ?? '', 'foto' => $i['profilePicUrl'] ?? '',
        'msg' => $estado === 'open' ? 'Conectado' : 'Instância criada, mas desconectada — leia o QR code.',
    ];
}
/* Cria a instância (se preciso) e devolve o QR code em base64. */
function whatsappQr(?string $nome = null): array {
    $nome = $nome ?: (whatsappPadrao()['instancia'] ?? evoInstancia());
    $est = whatsappEstado($nome);
    if (($est['estado'] ?? '') === 'inexistente') {
        $r = evo('POST', '/instance/create', ['instanceName' => $nome, 'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => true], 30);
        $d = $r['dados'];
        $b64 = $d['qrcode']['base64'] ?? ($d['qrcode']['code'] ?? '');
        if ($b64) return ['ok' => true, 'qr' => $b64, 'pareamento' => $d['qrcode']['pairingCode'] ?? ''];
        return ['ok' => false, 'msg' => 'Não consegui criar a instância: ' . ($d['response']['message'][0] ?? ($r['bruto'] ?? 'erro')) ];
    }
    $r = evo('GET', '/instance/connect/' . rawurlencode($nome), null, 30);
    $d = $r['dados'];
    $b64 = $d['base64'] ?? ($d['qrcode']['base64'] ?? '');
    if ($b64) return ['ok' => true, 'qr' => $b64, 'pareamento' => $d['pairingCode'] ?? ''];
    if (($d['instance']['state'] ?? '') === 'open') return ['ok' => true, 'qr' => '', 'msg' => 'Já está conectado.'];
    return ['ok' => false, 'msg' => 'A Evolution não devolveu QR code. Tente de novo em alguns segundos.'];
}
function whatsappDesconectar(?string $nome = null): array {
    $nome = $nome ?: (whatsappPadrao()['instancia'] ?? evoInstancia());
    $r = evo('DELETE', '/instance/logout/' . rawurlencode($nome), null, 15);
    return ['ok' => $r['codigo'] >= 200 && $r['codigo'] < 300, 'msg' => 'Desconectado.'];
}
function enviarWhatsapp(string $telefone, string $texto, ?string $instancia = null): array {
    $num = telefone_wa($telefone);
    if ($num === '') return ['ok' => false, 'detalhe' => 'Contato sem número de WhatsApp'];
    $inst = $instancia ?: (whatsappPadrao()['instancia'] ?? evoInstancia());
    $r = evo('POST', '/message/sendText/' . rawurlencode($inst), ['number' => $num, 'text' => $texto], 30);
    if ($r['codigo'] >= 200 && $r['codigo'] < 300) return ['ok' => true, 'destino' => $num, 'detalhe' => 'key: ' . ($r['dados']['key']['id'] ?? '-')];
    /* a Evolution às vezes devolve response.message como texto e às vezes como lista */
    $resp = $r['dados']['response']['message'] ?? null;
    if (is_array($resp)) $resp = reset($resp);
    $msg = $r['erro'] ?: ($resp ?: ($r['dados']['message'] ?? ('HTTP ' . $r['codigo'])));
    if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    return ['ok' => false, 'destino' => $num, 'detalhe' => (string)$msg];
}

/* ================= Direct do Instagram ================= */
function igCliente(?int $id = null): ?array {
    $db = dbIg();
    if (!$db) return null;
    $id = $id ?: (int)cfg('ig_cliente_id');
    if (!$id) return null;
    $st = $db->prepare('SELECT id, nome, ig_username, ig_user_id, access_token, token_expira_em FROM clientes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function igContas(): array {
    $db = dbIg();
    if (!$db) return [];
    return $db->query('SELECT id, nome, ig_username, token_expira_em, (access_token IS NOT NULL) AS tem_token FROM clientes ORDER BY nome')->fetchAll();
}
/* Conversas já existentes (só dá para enviar Direct para quem já falou com a conta). */
function igConversas(int $clienteId): array {
    $db = dbIg();
    if (!$db || !$clienteId) return [];
    $st = $db->prepare('SELECT id, remetente_id, nome, ultima_recebida_em FROM dm_conversas WHERE cliente_id = ? ORDER BY ultima_em DESC LIMIT 300');
    $st->execute([$clienteId]);
    return $st->fetchAll();
}
/* Acha o id interno (IGSID) de um @ nas conversas já existentes do Direct. */
function igResolverUsuario(string $usuario, ?int $clienteId = null): ?array {
    $db = dbIg();
    $u = ltrim(trim($usuario), '@');
    if (!$db || $u === '') return null;
    $preferida = $clienteId ?: (int)cfg('ig_cliente_id');
    /* 1) nome exato na conta preferida; 2) nome exato em qualquer conta; 3) aproximado em qualquer conta */
    $tentativas = [];
    if ($preferida) $tentativas[] = ['LOWER(nome) = LOWER(:u)', ['u' => $u], $preferida];
    $tentativas[] = ['LOWER(nome) = LOWER(:u)', ['u' => $u], 0];
    $tentativas[] = ['nome LIKE :u', ['u' => '%' . $u . '%'], 0];
    foreach ($tentativas as [$cond, $par, $cid]) {
        $sql = 'SELECT remetente_id, nome, cliente_id FROM dm_conversas WHERE ' . $cond;
        if ($cid) { $sql .= ' AND cliente_id = :c'; $par['c'] = $cid; }
        $sql .= ' ORDER BY ultima_em DESC LIMIT 1';
        $st = $db->prepare($sql);
        $st->execute($par);
        if ($r = $st->fetch()) return $r;
    }
    return null;
}

function enviarDirect(array $contato, string $texto): array {
    $cli = igCliente($contato['ig_cliente_id'] ? (int)$contato['ig_cliente_id'] : null);
    $destino = trim((string)($contato['ig_remetente_id'] ?? ''));
    if ($destino === '' && !empty($contato['ig_usuario'])) {
        $achado = igResolverUsuario((string)$contato['ig_usuario'], $contato['ig_cliente_id'] ? (int)$contato['ig_cliente_id'] : null);
        if ($achado) {
            $destino = $achado['remetente_id'];
            if (!empty($contato['id'])) {   // guarda para os próximos envios
                db()->prepare('UPDATE contatos SET ig_remetente_id = ?, ig_cliente_id = ? WHERE id = ?')
                    ->execute([$destino, (int)$achado['cliente_id'], (int)$contato['id']]);
            }
            $cli = igCliente((int)$achado['cliente_id']) ?: $cli;
        }
    }
    if ($destino === '') {
        return ['ok' => false, 'detalhe' => empty($contato['ig_usuario'])
            ? 'Contato sem Instagram informado'
            : 'Ainda não há conversa no Direct com @' . ltrim((string)$contato['ig_usuario'], '@') . ' — peça para a pessoa mandar uma mensagem primeiro (a Meta não deixa iniciar conversa)'];
    }
    if (!$cli) return ['ok' => false, 'detalhe' => 'Nenhuma conta do Instagram escolhida na aba Conexões'];
    if (empty($cli['access_token'])) return ['ok' => false, 'detalhe' => 'A conta @' . $cli['ig_username'] . ' está sem token — reconecte em /agendamentos/cliente_instagram.php'];
    $url = IG_GRAPH . '/me/messages?access_token=' . urlencode($cli['access_token']);
    $r = http_json('POST', $url, ['recipient' => ['id' => $destino], 'message' => ['text' => $texto]], [], 25);
    if ($r['codigo'] >= 200 && $r['codigo'] < 300) return ['ok' => true, 'destino' => '@' . ($contato['ig_usuario'] ?: $destino), 'detalhe' => 'mid: ' . ($r['dados']['message_id'] ?? '-')];
    $e = $r['dados']['error']['message'] ?? ($r['erro'] ?: ('HTTP ' . $r['codigo']));
    if (stripos($e, '24') !== false || stripos($e, 'window') !== false) {
        $e .= ' (a Meta só permite Direct até 24h depois da última mensagem da pessoa)';
    }
    return ['ok' => false, 'destino' => '@' . ($contato['ig_usuario'] ?: $destino), 'detalhe' => (string)$e];
}

/* ================= E-mail (SMTP puro, sem dependências) ================= */
function smtpEnviar(string $para, string $assunto, string $corpoHtml, string &$detalhe = '', array $anexos = []): bool {
    $host = trim((string)cfg('smtp_host'));
    if ($host === '') { $detalhe = 'SMTP não configurado (Configurações → E-mail)'; return false; }
    $porta = (int)(cfg('smtp_porta', 587) ?: 587);
    $seg   = cfg('smtp_seguranca', 'tls');           // tls (STARTTLS), ssl, nenhuma
    $user  = (string)cfg('smtp_usuario');
    $senha = (string)cfg('smtp_senha');
    $de    = (string)cfg('smtp_de') ?: $user;
    $nome  = (string)cfg('smtp_nome', 'Lembretes');

    $alvo = ($seg === 'ssl' ? 'ssl://' : '') . $host . ':' . $porta;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($alvo, $eno, $estr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $detalhe = "Não conectei em $alvo: $estr"; return false; }
    stream_set_timeout($fp, 20);

    $ler = function () use ($fp) {
        $saida = '';
        while (($linha = fgets($fp, 515)) !== false) { $saida .= $linha; if (strlen($linha) < 4 || $linha[3] === ' ') break; }
        return $saida;
    };
    $cmd = function (string $c, string $esperado) use ($fp, $ler, &$detalhe) {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $ler();
        if (strncmp($r, $esperado, strlen($esperado)) !== 0) {
            $detalhe = trim(($c !== '' ? explode(' ', $c)[0] . ': ' : '') . $r);
            return false;
        }
        return true;
    };
    $eu = gethostname() ?: 'localhost';
    if (!$cmd('', '220')) { fclose($fp); return false; }
    if (!$cmd('EHLO ' . $eu, '250')) { fclose($fp); return false; }
    if ($seg === 'tls') {
        if (!$cmd('STARTTLS', '220')) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $detalhe = 'Falha no STARTTLS'; fclose($fp); return false; }
        if (!$cmd('EHLO ' . $eu, '250')) { fclose($fp); return false; }
    }
    if ($user !== '') {
        if (!$cmd('AUTH LOGIN', '334')) { fclose($fp); return false; }
        if (!$cmd(base64_encode($user), '334')) { fclose($fp); return false; }
        if (!$cmd(base64_encode($senha), '235')) { $detalhe = 'Usuário ou senha do SMTP recusados'; fclose($fp); return false; }
    }
    if (!$cmd('MAIL FROM:<' . $de . '>', '250')) { fclose($fp); return false; }
    if (!$cmd('RCPT TO:<' . $para . '>', '250')) { fclose($fp); return false; }
    if (!$cmd('DATA', '354')) { fclose($fp); return false; }

    $alt = '=_alt_' . bin2hex(random_bytes(8));
    $mix = '=_mix_' . bin2hex(random_bytes(8));
    $texto  = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $corpoHtml)), ENT_QUOTES, 'UTF-8'));
    $texto  = preg_replace("/\n{3,}/", "\n\n", $texto);
    $temAnexo = count($anexos) > 0;
    $cab = [
        'Date: ' . date('r'),
        'From: ' . mb_encode_mimeheader($nome, 'UTF-8') . ' <' . $de . '>',
        'To: <' . $para . '>',
        'Subject: ' . mb_encode_mimeheader($assunto, 'UTF-8'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $eu . '>',
        'MIME-Version: 1.0',
        'Content-Type: ' . ($temAnexo ? 'multipart/mixed; boundary="' . $mix . '"' : 'multipart/alternative; boundary="' . $alt . '"'),
    ];
    $miolo = '--' . $alt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($texto)) . "\r\n"
        . '--' . $alt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($corpoHtml)) . "\r\n"
        . '--' . $alt . "--\r\n";

    $corpo = implode("\r\n", $cab) . "\r\n\r\n";
    if ($temAnexo) {
        $corpo .= '--' . $mix . "\r\nContent-Type: multipart/alternative; boundary=\"" . $alt . "\"\r\n\r\n" . $miolo . "\r\n";
        foreach ($anexos as $a) {
            $nomeArq = mb_encode_mimeheader($a['nome'], 'UTF-8');
            $corpo .= '--' . $mix . "\r\n"
                . 'Content-Type: ' . $a['mime'] . '; name="' . $nomeArq . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $nomeArq . "\"\r\n\r\n"
                . chunk_split(base64_encode($a['conteudo'])) . "\r\n";
        }
        $corpo .= '--' . $mix . "--\r\n";
    } else {
        $corpo .= $miolo;
    }
    /* protege linhas que começam com ponto */
    $corpo = preg_replace('/^\./m', '..', $corpo);
    fwrite($fp, $corpo . "\r\n.\r\n");
    $r = $ler();
    $okEnvio = strncmp($r, '250', 3) === 0;
    if (!$okEnvio) $detalhe = trim($r);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $okEnvio;
}
function emailModelo(string $titulo, string $mensagem, array $ctx = []): string {
    $links = (array)($ctx['links'] ?? []);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $assin = trim((string)cfg('assinatura'));
    $nome  = trim((string)($ctx['nome'] ?? ''));
    $quando = !empty($ctx['quando']) ? strtotime($ctx['quando']) : null;
    $repeticao = (string)($ctx['repeticao'] ?? '');
    $rotulos = ['diaria' => 'Todo dia', 'semanal' => 'Toda semana', 'quinzenal' => 'A cada 15 dias',
                'mensal' => 'Todo mês', 'anual' => 'Todo ano', 'dias' => 'A cada alguns dias'];
    $dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $dataLonga = $quando ? $dias[(int)date('w', $quando)] . ', ' . date('j', $quando) . ' de ' . $meses[(int)date('n', $quando)] . ' de ' . date('Y', $quando) : '';
    $hora = $quando ? date('H:i', $quando) : '';

    $corpo = nl2br($h($mensagem));
    $previa = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($mensagem))), 0, 110);
    $saudacao = $nome !== '' ? 'Olá, ' . $h($nome) . '!' : 'Olá!';

    $linhaData = $quando ? '
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px">
                <tr>
                  <td style="background:#EFF6FF;border-left:4px solid #2563EB;border-radius:10px;padding:16px 18px">
                    <div style="font:600 12px/1 Inter,Segoe UI,Arial,sans-serif;color:#2563EB;letter-spacing:.08em;text-transform:uppercase;margin:0 0 6px">Quando</div>
                    <div style="font:600 17px/1.4 Inter,Segoe UI,Arial,sans-serif;color:#111827">' . $h($dataLonga) . '</div>
                    <div style="font:400 15px/1.5 Inter,Segoe UI,Arial,sans-serif;color:#4B5563;margin-top:2px">às ' . $h($hora) . ' (horário de Maceió)</div>
                  </td>
                </tr>
              </table>' : '';

    $selo = isset($rotulos[$repeticao]) ? '
              <div style="font:500 13px/1 Inter,Segoe UI,Arial,sans-serif;color:#6B7280;margin:0 0 24px">
                <span style="display:inline-block;background:#F3F4F6;border-radius:999px;padding:7px 14px;color:#4B5563">&#128257; ' . $h($rotulos[$repeticao]) . '</span>
              </div>' : '';

    $blocoLinks = $links ? '
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px">
                <tr><td style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px">
                  <div style="font:600 12px/1 Inter,Segoe UI,Arial,sans-serif;color:#6B7280;letter-spacing:.08em;text-transform:uppercase;margin:0 0 10px">Arquivos</div>'
                  . implode('', array_map(fn($x) => '<div style="font:400 14px/1.8 Inter,Segoe UI,Arial,sans-serif"><a href="' . $h($x['url']) . '" style="color:#2563EB">&#128206; ' . $h($x['nome']) . '</a> <span style="color:#9CA3AF">(' . $h($x['tamanho']) . ')</span></div>', $links))
                  . '</td></tr>
              </table>' : '';

    $rodapeAssin = $assin !== '' ? '
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:26px 0 0">
                <tr><td style="border-top:1px solid #E5E7EB;padding-top:18px;font:400 14px/1.6 Inter,Segoe UI,Arial,sans-serif;color:#6B7280">' . nl2br($h($assin)) . '</td></tr>
              </table>' : '';

    return '<!doctype html>
<html lang="pt-BR" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>' . $h($titulo) . '</title>
<style>
  @media (max-width:620px){
    .envelope{padding:12px !important}
    .miolo{padding:26px 22px !important}
    .cabecalho{padding:26px 22px !important}
    .titulo{font-size:22px !important}
  }
</style>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;-webkit-font-smoothing:antialiased">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $h($previa) . '&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;</div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F3F4F6">
    <tr>
      <td align="center" class="envelope" style="padding:32px 16px">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:100%;background:#FFFFFF;border-radius:18px;overflow:hidden;box-shadow:0 2px 8px rgba(16,24,40,.08)">
          <tr>
            <td class="cabecalho" style="background:#2563EB;background-image:linear-gradient(135deg,#1E40AF,#3B82F6);padding:30px 34px">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="font:700 15px/1 Inter,Segoe UI,Arial,sans-serif;color:#DBEAFE;letter-spacing:.12em;text-transform:uppercase">&#128276;&nbsp; Lembrete</td>
                  <td align="right" style="font:500 13px/1 Inter,Segoe UI,Arial,sans-serif;color:#BFDBFE">' . $h($quando ? date('d/m/Y', $quando) : date('d/m/Y')) . '</td>
                </tr>
              </table>
              <div class="titulo" style="font:700 27px/1.3 Inter,Segoe UI,Arial,sans-serif;color:#FFFFFF;margin:14px 0 0">' . $h($titulo) . '</div>
            </td>
          </tr>
          <tr>
            <td class="miolo" style="padding:30px 34px">
              <div style="font:600 16px/1.5 Inter,Segoe UI,Arial,sans-serif;color:#111827;margin:0 0 14px">' . $saudacao . '</div>
              <div style="font:400 16px/1.7 Inter,Segoe UI,Arial,sans-serif;color:#374151;margin:0 0 24px">' . $corpo . '</div>
              ' . $linhaData . $blocoLinks . $selo . '
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="background:#2563EB;border-radius:10px">
                    <a href="https://alequizao.com/lembretes/" style="display:inline-block;padding:13px 26px;font:600 15px/1 Inter,Segoe UI,Arial,sans-serif;color:#FFFFFF;text-decoration:none">Abrir meus lembretes</a>
                  </td>
                </tr>
              </table>
              ' . $rodapeAssin . '
            </td>
          </tr>
          <tr>
            <td style="background:#F9FAFB;border-top:1px solid #F3F4F6;padding:18px 34px;font:400 12px/1.6 Inter,Segoe UI,Arial,sans-serif;color:#9CA3AF">
              Você recebeu este aviso porque está cadastrado no sistema de Lembretes do Alequizão.<br>
              <a href="https://alequizao.com/lembretes/" style="color:#6B7280;text-decoration:underline">alequizao.com/lembretes</a>
            </td>
          </tr>
        </table>
        <div style="font:400 12px/1.6 Inter,Segoe UI,Arial,sans-serif;color:#9CA3AF;margin:16px 0 0">Enviado automaticamente &middot; não é preciso responder</div>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function enviarEmail(string $para, string $titulo, string $mensagem, array $ctx = [], array $anexos = []): array {
    $para = trim($para);
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'detalhe' => 'Contato sem e-mail válido'];
    $detalhe = '';
    $ok = smtpEnviar($para, $titulo, emailModelo($titulo, $mensagem, $ctx), $detalhe, $anexos);
    return ['ok' => $ok, 'destino' => $para,
            'detalhe' => $ok ? ('entregue ao servidor SMTP' . ($anexos ? ' com ' . count($anexos) . ' anexo(s)' : '')) : $detalhe];
}

/* ================= despacho ================= */
function enviarPorCanal(string $canal, array $contato, string $titulo, string $texto, array $ctx = [], array $anexos = []): array {
    switch ($canal) {
        case 'whatsapp': return enviarWhatsapp((string)($contato['whatsapp'] ?? ''), '*' . $titulo . "*\n\n" . $texto,
                             (string)($ctx['instancia'] ?? '') ?: null);
        case 'direct':   return enviarDirect($contato, $titulo . "\n\n" . $texto);
        case 'email':    return enviarEmail((string)($contato['email'] ?? ''), $titulo, $texto,
                             $ctx + ['nome' => (string)($contato['nome'] ?? '')], $anexos);
    }
    return ['ok' => false, 'detalhe' => 'Canal desconhecido'];
}
function registrarEnvio(?int $lembreteId, ?int $contatoId, string $canal, array $r, string $msg, bool $teste = false,
                        bool $previo = false, int $tentativas = 1, string $origem = ''): int {
    /* origem: rótulo de quem mandou, para envios que não pertencem a um lembrete
       (alerta de chuva, teste de canal). Sem ele a lista mostrava só "—". */
    $st = db()->prepare('INSERT INTO envios (lembrete_id, contato_id, canal, destino, mensagem, status, detalhe, teste, previo, tentativas, origem)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$lembreteId, $contatoId, $canal, mb_substr((string)($r['destino'] ?? ''), 0, 190), mb_substr($msg, 0, 2000),
        $r['ok'] ? 'enviado' : 'erro', mb_substr((string)($r['detalhe'] ?? ''), 0, 500), $teste ? 1 : 0, $previo ? 1 : 0, $tentativas,
        mb_substr($origem, 0, 60) ?: null]);
    $id = (int)db()->lastInsertId();
    if (empty($r['ok']) && !$teste) {
        /* falha de envio real: avisa o desenvolvedor (agrupado por canal) */
        $nomeCanal = ['whatsapp' => 'WhatsApp', 'direct' => 'Direct do Instagram', 'email' => 'e-mail'][$canal] ?? $canal;
        avisarDesenvolvedor('Falha de envio no ' . $nomeCanal,
            $nomeCanal . ' → ' . (string)($r['destino'] ?? '?') . ' · ' . (string)($r['detalhe'] ?? 'erro desconhecido')
            . ' · tentativas: ' . $tentativas, 'envio-' . $canal);
    }
    return $id;
}

/* ===================== Aviso de falhas para o desenvolvedor ===================== */

/* Manda um e-mail para o desenvolvedor quando um envio falha (ou quando a API dá erro).
   Junta as falhas numa janela de tempo para não virar spam: no máximo 1 e-mail por chave a cada X minutos. */
function avisarDesenvolvedor(string $assunto, string $detalhe, string $chave = 'geral'): bool {
    if ((string)cfg('avisar_dev', '1') !== '1') return false;
    $para = trim((string)cfg('email_dev', 'alequizao.dev@gmail.com'));
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    if (trim((string)cfg('smtp_host', '')) === '') return false;      /* sem SMTP não há como avisar */

    $janela = max(1, (int)cfg('avisar_dev_minutos', 30));
    $slot = 'avisodev_' . substr(md5($chave), 0, 16);
    $ultimo = (string)cfg($slot, '');
    $pendentes = json_decode((string)cfg($slot . '_fila', '[]'), true) ?: [];
    $pendentes[] = ['em' => agora(), 'texto' => mb_substr($detalhe, 0, 800)];
    if (count($pendentes) > 50) $pendentes = array_slice($pendentes, -50);

    if ($ultimo !== '' && strtotime($ultimo) > time() - $janela * 60) {
        cfgSet($slot . '_fila', json_encode($pendentes, JSON_UNESCAPED_UNICODE));   /* ainda dentro da janela: só acumula */
        return false;
    }
    $linhas = '';
    foreach ($pendentes as $p) {
        $linhas .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;color:#6b7280">'
                 . htmlspecialchars(date('d/m/Y H:i', strtotime($p['em'])))
                 . '</td><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb">' . htmlspecialchars($p['texto']) . '</td></tr>';
    }
    $n = count($pendentes);
    $corpo = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px">'
           . '<h2 style="color:#b91c1c;margin:0 0 6px">⚠️ Falha no sistema de Lembretes</h2>'
           . '<p style="margin:0 0 14px;color:#374151">' . htmlspecialchars($assunto) . ' — ' . $n . ' ocorrência(s) desde o último aviso.</p>'
           . '<table style="border-collapse:collapse;width:100%;font-size:13px"><tbody>' . $linhas . '</tbody></table>'
           . '<p style="margin:16px 0 0;font-size:12px;color:#6b7280">Servidor: ' . htmlspecialchars(gethostname() ?: '?')
           . ' · <a href="https://alequizao.com/lembretes/#/envios">abrir o histórico de envios</a><br>'
           . 'Avisos agrupados a cada ' . $janela . ' minutos. Para desligar: aba Conexões → Avisos ao desenvolvedor.</p></div>';
    $det = '';
    $ok = smtpEnviar($para, '[Lembretes] ' . $assunto, $corpo, $det);
    cfgSet($slot, agora());
    cfgSet($slot . '_fila', '[]');
    logar('aviso-dev', ($ok ? 'enviado' : 'falhou') . ": $assunto ($n) $det");
    return $ok;
}

/* ===================== Diagnóstico das conexões ===================== */

/* Confere o token da conta do Instagram na própria Meta (com cache curto). */
function igTokenEstado(?int $clienteId = null): array {
    $cli = igCliente($clienteId);
    if (!$cli) return ['ok' => false, 'situacao' => 'sem_conta', 'msg' => 'Nenhuma conta do Instagram escolhida.'];
    $chave = 'ig_check_' . $cli['id'];
    $cache = json_decode((string)cfg($chave), true);
    if (is_array($cache) && !empty($cache['em']) && strtotime($cache['em']) > time() - 900) {
        $cache['cache'] = true;
        return $cache;
    }
    $r = ['conta' => $cli['ig_username'], 'expira_em' => $cli['token_expira_em']];
    if (empty($cli['access_token'])) {
        $r += ['ok' => false, 'situacao' => 'sem_token', 'msg' => 'A conta @' . $cli['ig_username'] . ' está sem token.'];
    } else {
        $h = http_json('GET', IG_GRAPH . '/me?fields=id,username&access_token=' . urlencode($cli['access_token']), null, [], 10);
        if ($h['codigo'] >= 200 && $h['codigo'] < 300 && !empty($h['dados']['id'])) {
            $r += ['ok' => true, 'situacao' => 'valido', 'msg' => 'Token válido para @' . ($h['dados']['username'] ?? $cli['ig_username']) . '.'];
        } else {
            $r += ['ok' => false, 'situacao' => 'invalido',
                   'msg' => 'Token recusado pela Meta: ' . ($h['dados']['error']['message'] ?? ('HTTP ' . $h['codigo'])) . ' — reconecte a conta em /agendamentos/cliente_instagram.php'];
        }
    }
    /* aviso de validade */
    $r['dias_restantes'] = null;
    if (!empty($cli['token_expira_em'])) {
        $r['dias_restantes'] = (int)floor((strtotime($cli['token_expira_em']) - time()) / 86400);
        if ($r['ok'] && $r['dias_restantes'] <= 7) {
            $r['situacao'] = 'expirando';
            $r['msg'] = 'Token válido, mas vence em ' . max(0, $r['dias_restantes']) . ' dia(s) — renove a conexão da conta @' . $cli['ig_username'] . '.';
        }
    }
    $r['em'] = agora();
    cfgSet($chave, json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

/* Testa só o diálogo/autenticação do SMTP, sem enviar mensagem. */
function smtpEstado(): array {
    $host = trim((string)cfg('smtp_host'));
    if ($host === '') return ['ok' => false, 'situacao' => 'nao_configurado', 'msg' => 'SMTP ainda não configurado.'];
    $porta = (int)(cfg('smtp_porta', 587) ?: 587);
    $seg = cfg('smtp_seguranca', 'tls');
    $user = (string)cfg('smtp_usuario');
    $senha = (string)cfg('smtp_senha');
    $alvo = ($seg === 'ssl' ? 'ssl://' : '') . $host . ':' . $porta;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($alvo, $eno, $estr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'situacao' => 'sem_conexao', 'servidor' => $host, 'conta' => $user, 'msg' => "Não conectei em $alvo: $estr"];
    stream_set_timeout($fp, 12);
    $ler = function () use ($fp) { $s = ''; while (($l = fgets($fp, 515)) !== false) { $s .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $s; };
    $cmd = function (string $c, string $esp) use ($fp, $ler) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $ler(); return strncmp($r, $esp, strlen($esp)) === 0 ? '' : trim($r); };
    $eu = gethostname() ?: 'localhost';
    $base = ['servidor' => $host . ':' . $porta, 'conta' => $user];
    if ($e = $cmd('', '220'))            { fclose($fp); return $base + ['ok' => false, 'situacao' => 'recusado', 'msg' => $e]; }
    if ($e = $cmd('EHLO ' . $eu, '250')) { fclose($fp); return $base + ['ok' => false, 'situacao' => 'recusado', 'msg' => $e]; }
    if ($seg === 'tls') {
        if ($e = $cmd('STARTTLS', '220')) { fclose($fp); return $base + ['ok' => false, 'situacao' => 'tls', 'msg' => $e]; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return $base + ['ok' => false, 'situacao' => 'tls', 'msg' => 'Falha no STARTTLS']; }
        $cmd('EHLO ' . $eu, '250');
    }
    if ($user !== '') {
        if ($cmd('AUTH LOGIN', '334') || $cmd(base64_encode($user), '334') || ($e = $cmd(base64_encode($senha), '235'))) {
            fwrite($fp, "QUIT\r\n"); fclose($fp);
            return $base + ['ok' => false, 'situacao' => 'login', 'msg' => 'Login recusado pelo servidor — gere uma nova senha de app do Gmail.'];
        }
    }
    fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $base + ['ok' => true, 'situacao' => 'valido', 'msg' => 'Conectado e autenticado em ' . $host . '.'];
}

/* Último envio bem-sucedido e último erro de cada canal. */
function ultimoPorCanal(string $canal): array {
    $db = db();
    $st = $db->prepare("SELECT criado_em FROM envios WHERE canal = ? AND status = 'enviado' ORDER BY id DESC LIMIT 1");
    $st->execute([$canal]);
    $ok = $st->fetchColumn();
    $st = $db->prepare("SELECT criado_em, detalhe FROM envios WHERE canal = ? AND status = 'erro' ORDER BY id DESC LIMIT 1");
    $st->execute([$canal]);
    $erro = $st->fetch();
    return ['ultimo_ok' => $ok ?: null, 'ultimo_erro' => $erro['criado_em'] ?? null, 'ultimo_erro_msg' => $erro['detalhe'] ?? null];
}

/* Panorama dos três canais para a tela de Conexões e o Painel. */
function conexoesEstado(bool $completo = true): array {
    $numeros = [];
    foreach (whatsappLista() as $w) {
        $e = whatsappEstado($w['instancia']);
        if (($e['numero'] ?? '') !== '' && $e['numero'] !== $w['numero']) {
            db()->prepare('UPDATE whatsapps SET numero = ? WHERE id = ?')->execute([$e['numero'], $w['id']]);
        }
        $numeros[] = ['id' => (int)$w['id'], 'nome' => $w['nome'], 'instancia' => $w['instancia'],
                      'padrao' => (int)$w['padrao'], 'ok' => (bool)$e['ok'], 'situacao' => $e['estado'],
                      'numero' => $e['numero'] ?? '', 'perfil' => $e['perfil'] ?? '', 'msg' => $e['msg'] ?? ''];
    }
    $wa = whatsappEstado();
    $whats = [
        'canal' => 'whatsapp', 'nome' => 'WhatsApp', 'ok' => (bool)$wa['ok'],
        'situacao' => $wa['estado'], 'conta' => $wa['numero'] ?? '', 'perfil' => $wa['perfil'] ?? '',
        'msg' => $wa['msg'] ?? '',
        'acao' => $wa['ok'] ? '' : 'Leia o QR code para reconectar o número.',
        'numeros' => $numeros,
    ] + ultimoPorCanal('whatsapp');

    $ig = $completo ? igTokenEstado() : ['ok' => null, 'situacao' => 'nao_verificado', 'msg' => ''];
    $insta = [
        'canal' => 'direct', 'nome' => 'Direct do Instagram', 'ok' => $ig['ok'],
        'situacao' => $ig['situacao'], 'conta' => isset($ig['conta']) ? '@' . $ig['conta'] : '',
        'msg' => $ig['msg'], 'expira_em' => $ig['expira_em'] ?? null, 'dias_restantes' => $ig['dias_restantes'] ?? null,
        'acao' => in_array($ig['situacao'], ['invalido', 'sem_token', 'expirando'], true)
            ? 'Reconecte a conta em alequizao.com/agendamentos/cliente_instagram.php' : '',
    ] + ultimoPorCanal('direct');

    $sm = $completo ? smtpEstado() : ['ok' => null, 'situacao' => 'nao_verificado', 'msg' => ''];
    $mail = [
        'canal' => 'email', 'nome' => 'E-mail (SMTP)', 'ok' => $sm['ok'], 'situacao' => $sm['situacao'],
        'conta' => $sm['conta'] ?? '', 'servidor' => $sm['servidor'] ?? '', 'msg' => $sm['msg'],
        'acao' => in_array($sm['situacao'], ['login', 'sem_conexao', 'tls', 'recusado'], true)
            ? 'Confira o servidor/porta e gere uma nova senha de app.' : '',
    ] + ultimoPorCanal('email');

    return ['verificado_em' => agora(), 'canais' => [$whats, $insta, $mail]];
}
