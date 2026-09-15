<?php
/* Lembretes — configuração.
   Copie este arquivo para config.php e preencha com os dados do seu servidor.
   O config.php nunca vai para o git (está no .gitignore). */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'nome_do_banco');
define('DB_USER', 'usuario_do_banco');
define('DB_PASS', 'senha_do_banco');

define('APP_NOME', 'Lembretes');
define('APP_VERSAO', '1.3.1');
define('APP_TZ', 'America/Maceio');
/* Endereço público do sistema — usado pelos envios que rodam no cron (CLI),
   onde não existe host na requisição. Ex.: https://seusite.com/lembretes */
define('APP_URL', 'https://seusite.com/lembretes');

/* Evolution API (WhatsApp) */
define('EVO_URL', 'http://127.0.0.1:8081');
define('EVO_KEY', 'chave_da_evolution');

/* Banco de onde vêm os tokens do Direct do Instagram (projeto Agenda Social).
   Se você não usa o canal Direct, deixe como está — o sistema apenas não oferece esse canal. */
define('IG_DB_NAME', 'nome_do_banco_instagram');
define('IG_DB_USER', 'usuario_do_banco');
define('IG_DB_PASS', 'senha_do_banco');
define('IG_GRAPH', 'https://graph.instagram.com/v21.0');
