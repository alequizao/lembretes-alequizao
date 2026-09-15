<?php
/* Esquema do banco — rodar pelo CLI: /www/server/php/83/bin/php install.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente CLI'); }
require_once __DIR__ . '/lib.php';
$db = db();
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  senha VARCHAR(255) NOT NULL,
  email VARCHAR(160) NULL,
  admin TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS config (
  chave VARCHAR(60) NOT NULL PRIMARY KEY,
  valor TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  instancia VARCHAR(80) NOT NULL UNIQUE,
  numero VARCHAR(30) NULL,
  padrao TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contatos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  whatsapp VARCHAR(30) NULL,
  email VARCHAR(160) NULL,
  ig_conversa_id BIGINT UNSIGNED NULL,
  ig_usuario VARCHAR(120) NULL,
  ig_remetente_id VARCHAR(60) NULL,
  ig_cliente_id INT UNSIGNED NULL,
  observacao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lembretes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(160) NOT NULL,
  mensagem TEXT NOT NULL,
  quando DATETIME NOT NULL,
  proxima_em DATETIME NULL,
  repeticao ENUM('nenhuma','diaria','semanal','quinzenal','mensal','anual','dias') NOT NULL DEFAULT 'nenhuma',
  intervalo_dias INT UNSIGNED NOT NULL DEFAULT 0,
  repetir_ate DATE NULL,
  canais VARCHAR(60) NOT NULL DEFAULT 'whatsapp',
  status ENUM('ativo','pausado','concluido') NOT NULL DEFAULT 'ativo',
  ultima_em DATETIME NULL,
  total_envios INT UNSIGNED NOT NULL DEFAULT 0,
  criado_por INT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status, proxima_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lembrete_contatos (
  lembrete_id INT UNSIGNED NOT NULL,
  contato_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (lembrete_id, contato_id),
  FOREIGN KEY (lembrete_id) REFERENCES lembretes(id) ON DELETE CASCADE,
  FOREIGN KEY (contato_id) REFERENCES contatos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lembrete_midias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lembrete_id INT UNSIGNED NULL,
  arquivo VARCHAR(190) NOT NULL,
  nome VARCHAR(190) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  tipo ENUM('image','video','audio','document') NOT NULL DEFAULT 'document',
  tamanho INT UNSIGNED NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (lembrete_id),
  FOREIGN KEY (lembrete_id) REFERENCES lembretes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS envios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lembrete_id INT UNSIGNED NULL,
  contato_id INT UNSIGNED NULL,
  canal ENUM('whatsapp','direct','email') NOT NULL,
  destino VARCHAR(190) NULL,
  mensagem TEXT NULL,
  status ENUM('enviado','erro') NOT NULL,
  detalhe VARCHAR(500) NULL,
  teste TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (lembrete_id), INDEX (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS senha_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  token CHAR(64) NOT NULL UNIQUE,
  expira_em DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (usuario_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(40) NOT NULL,
  texto VARCHAR(1000) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $q) { if ($q !== '') $db->exec($q); }

/* usuários master */
foreach ([['alequizao', 'Alequizão'], ['jeovana', 'Jeovana']] as [$login, $nome]) {
    $st = $db->prepare('SELECT id FROM usuarios WHERE usuario = ?');
    $st->execute([$login]);
    if (!$st->fetch()) {
        $db->prepare('INSERT INTO usuarios (usuario, nome, senha, admin) VALUES (?,?,?,1)')
           ->execute([$login, $nome, password_hash($login, PASSWORD_DEFAULT)]);
        echo "usuário master criado: $login/$login\n";
    }
}
/* padrões de configuração */
$padroes = [
    'evo_instancia' => 'lembretes',
    'smtp_host' => '', 'smtp_porta' => '587', 'smtp_seguranca' => 'tls',
    'smtp_usuario' => '', 'smtp_senha' => '', 'smtp_de' => '', 'smtp_nome' => 'Lembretes',
    'ig_cliente_id' => '', 'assinatura' => '', 'cron_intervalo' => '1', 'clima_cidade' => 'Maceió',
    'email_dev' => 'alequizao.dev@gmail.com', 'avisar_dev' => '1', 'avisar_dev_minutos' => '30',
    'chuva_ativo' => '1', 'chuva_cidade' => 'Maceió', 'chuva_minimo' => '60', 'chuva_horas' => '4',
    'chuva_intervalo' => '6', 'chuva_inicio' => '06:00', 'chuva_fim' => '22:00',
    'chuva_tempestade' => '1', 'chuva_passou' => '1', 'chuva_canais' => 'whatsapp,email,direct', 'chuva_imagem' => '1',
];
foreach ($padroes as $k => $v) {
    $c = $db->prepare('INSERT IGNORE INTO config (chave, valor) VALUES (?,?)');
    $c->execute([$k, $v]);
}
/* colunas acrescentadas depois da 1ª versão */
$colunas = [
    'lembretes' => ['whatsapp_id' => 'INT UNSIGNED NULL',
                    'antecedencia' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'aviso_em' => 'DATETIME NULL',
                    'condicao_tipo' => "VARCHAR(24) NOT NULL DEFAULT 'nenhuma'",
                    'condicao_valor' => 'DECIMAL(6,1) NOT NULL DEFAULT 0',
                    'condicao_horas' => 'TINYINT UNSIGNED NOT NULL DEFAULT 6',
                    'condicao_cidade' => 'VARCHAR(120) NULL',
                    'condicao_antirrepete' => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'condicao_ultimo_ok' => 'DATE NULL'],
    'envios'    => ['tentativas' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                    'previo' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'origem' => 'VARCHAR(60) NULL'],
    'contatos'  => ['cidade' => 'VARCHAR(120) NULL',
                    'alerta_chuva' => 'TINYINT(1) NOT NULL DEFAULT 1'],
];
foreach ($colunas as $tabela => $novas) {
    $tem = [];
    foreach ($db->query("SHOW COLUMNS FROM $tabela") as $c) $tem[] = $c['Field'];
    foreach ($novas as $nome => $tipo) {
        if (!in_array($nome, $tem, true)) { $db->exec("ALTER TABLE $tabela ADD COLUMN $nome $tipo"); echo "coluna $tabela.$nome criada\n"; }
    }
}
/* repetição de hora em hora (usada pelos lembretes com condição de clima) */
$rep = $db->query("SHOW COLUMNS FROM lembretes LIKE 'repeticao'")->fetch();
if ($rep && strpos((string)$rep['Type'], "'horaria'") === false) {
    $db->exec("ALTER TABLE lembretes MODIFY repeticao ENUM('nenhuma','horaria','diaria','semanal','quinzenal','mensal','anual','dias') NOT NULL DEFAULT 'nenhuma'");
    echo "repetição \"horaria\" liberada\n";
}

/* primeiro número de WhatsApp = a instância que já existia */
if (!(int)$db->query('SELECT COUNT(*) FROM whatsapps')->fetchColumn()) {
    $inst = (string)(cfg('evo_instancia', 'lembretes') ?: 'lembretes');
    $db->prepare('INSERT INTO whatsapps (nome, instancia, padrao) VALUES (?,?,1)')->execute(['Principal', $inst]);
    echo "número de WhatsApp \"$inst\" registrado como principal\n";
}
echo "esquema pronto\n";
