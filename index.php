<?php
require_once __DIR__ . '/lib.php';
$u = usuarioAtual();
$v = APP_VERSAO . '.' . @filemtime(__DIR__ . '/app.js');
$dono = ehDono($u);   /* a caixa de mensagens é só do administrador */
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Lembretes — Alequizão</title>
<?php
$base = (isset($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'alequizao.com') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$ogImg = $base . 'og.php?v=' . APP_VERSAO;
$desc  = 'Agende um lembrete uma vez e o sistema avisa sozinho por WhatsApp, Direct do Instagram e e-mail — com repetição diária, semanal, mensal ou anual.';
?>
<meta name="description" content="<?= htmlspecialchars($desc) ?>">
<link rel="canonical" href="<?= htmlspecialchars($base) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Alequizão">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="Lembretes — avise quem importa, na hora certa">
<meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($base) ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImg) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($ogImg) ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Lembretes por WhatsApp, Direct do Instagram e e-mail">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Lembretes — avise quem importa, na hora certa">
<meta name="twitter:description" content="<?= htmlspecialchars($desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImg) ?>">
<meta name="theme-color" content="#2563EB">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="icone.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icone.svg">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($v) ?>">
</head>
<body class="<?= $u ? 'logado' : 'deslogado' ?>">

<!-- ===== Login (split screen) ===== -->
<div id="tela-login" class="login" <?= $u ? 'hidden' : '' ?>>
  <div class="login-arte">
    <div class="login-marca"><span class="sino">🔔</span><b>Lembretes</b></div>
    <h1>Nunca mais esqueça de avisar alguém.</h1>
    <p>Agende uma vez e o sistema entrega por <b>WhatsApp</b>, <b>Direct do Instagram</b> e <b>e-mail</b> na hora certa.</p>
    <ul class="login-lista">
      <li><span>📱</span> WhatsApp pela Evolution API</li>
      <li><span>📷</span> Direct do Instagram</li>
      <li><span>✉️</span> E-mail por SMTP</li>
      <li><span>🔁</span> Repetição diária, semanal, mensal ou anual</li>
    </ul>
  </div>
  <div class="login-form">
    <form id="form-login" autocomplete="on">
      <h2>Entrar</h2>
      <p class="sub">Use seu usuário e senha.</p>
      <label>Usuário<input name="usuario" autocomplete="username" required autofocus></label>
      <label>Senha<input name="senha" type="password" autocomplete="current-password" required></label>
      <button class="btn azul grande" type="submit">Entrar</button>
      <button type="button" class="link-senha" id="btn-esqueci">Esqueci minha senha</button>
      <div class="erro" id="login-erro" hidden></div>
      <div class="ok" id="login-ok" hidden></div>
    </form>
  </div>
</div>

<!-- ===== App ===== -->
<div id="app" class="app" <?= $u ? '' : 'hidden' ?>>
  <aside class="lateral" id="lateral">
    <div class="marca"><span class="sino">🔔</span><b>Lembretes</b></div>
    <nav>
      <a href="#/painel"      data-rota="painel"><i>▦</i> Painel</a>
      <a href="#/lembretes"   data-rota="lembretes"><i>🔔</i> Lembretes</a>
      <a href="#/agenda"      data-rota="agenda"><i>📅</i> Agenda</a>
      <a href="#/contatos"    data-rota="contatos"><i>👤</i> Contatos</a>
      <a href="#/envios"      data-rota="envios"><i>📨</i> Envios</a>
      <a href="#/clima"       data-rota="clima"><i>🌧️</i> Alerta de chuva</a>
<?php if ($dono): ?>      <a href="#/mensagens"   data-rota="mensagens"><i>💬</i> Mensagens</a>
<?php endif; ?>
      <a href="#/config"      data-rota="config"><i>🔌</i> Conexões</a>
    </nav>
    <div class="lateral-rodape">
      <div class="usuario"><span class="avatar" id="avatar">A</span><span id="nome-usuario"><?= htmlspecialchars($u['nome'] ?? '') ?></span></div>
      <button class="btn claro peq" id="btn-senha">Trocar senha</button>
      <button class="btn claro peq" id="btn-sair">Sair</button>
      <small>v<?= htmlspecialchars(APP_VERSAO) ?></small>
    </div>
  </aside>
  <div class="backdrop" id="backdrop"></div>

  <main class="conteudo">
    <header class="topo">
      <button class="hamburguer" id="btn-menu" aria-label="Menu">☰</button>
      <h1 id="titulo-pagina">Painel</h1>
      <div class="topo-acoes">
        <span class="selo" id="selo-wa" title="Estado do WhatsApp">WhatsApp…</span>
        <button class="btn azul" id="btn-novo">+ Novo lembrete</button>
      </div>
    </header>
    <div id="pagina"></div>
  </main>

  <nav class="tabbar">
    <a href="#/painel"    data-rota="painel"><i>▦</i><span>Painel</span></a>
    <a href="#/lembretes" data-rota="lembretes"><i>🔔</i><span>Lembretes</span></a>
    <a href="#/agenda"    data-rota="agenda"><i>📅</i><span>Agenda</span></a>
    <a href="#/contatos"  data-rota="contatos"><i>👤</i><span>Contatos</span></a>
    <a href="#/envios"    data-rota="envios"><i>📨</i><span>Envios</span></a>
    <a href="#/clima"     data-rota="clima"><i>🌧️</i><span>Chuva</span></a>
<?php if ($dono): ?>    <a href="#/mensagens" data-rota="mensagens"><i>💬</i><span>Mensagens</span></a>
<?php endif; ?>
    <a href="#/config"    data-rota="config"><i>🔌</i><span>Conexões</span></a>
  </nav>
</div>

<div class="modal-fundo" id="modal-fundo" hidden><div class="modal" id="modal"></div></div>
<div id="avisos" class="avisos"></div>

<script>window.EH_DONO = <?= $dono ? "true" : "false" ?>;</script>
<script src="app.js?v=<?= htmlspecialchars($v) ?>"></script>
</body>
</html>
