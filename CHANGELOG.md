# Histórico de versões

## v1.3.1 — 15/09/2026

### Alerta automático de chuva
- Vigia a previsão hora a hora (Open-Meteo, sem chave) e avisa sozinho quando a chuva se aproxima.
- Calcula a **janela** da chuva ("das 13h até 17h") e a **intensidade** (fraca, moderada, forte, tempestade).
- **Um aviso por chuva**, com intervalo mínimo entre avisos e janela de silêncio noturno — tempestade pode furar o silêncio.
- **Reavisa quando piora**: chance sobe 25 pontos ou vira tempestade.
- Avisa de novo quando a chuva **passa**.
- **Uma cidade por contato**: quem tem cidade na ficha recebe a previsão dela; os demais, a cidade padrão.
- **Cartão da previsão em imagem** (GD, 1080×1350) junto do aviso: legenda no WhatsApp, mensagem seguinte no Direct, anexo no e-mail.
- Tela própria (`#/clima`) com painel do tempo, próximas horas, textos editáveis, prévia e botão de teste.

### Correções
- `envios.origem`: avisos que não pertencem a um lembrete (alerta de chuva, teste de canal) deixaram de aparecer como "—" no painel, no histórico e no CSV.
- `{cidade}` nos alertas passou a usar a cidade analisada — um alerta de São Luís chegava escrito "Maceió".
- Barras de chance de chuva em escala fixa de 0 a 100% (antes eram relativas ao maior valor do dia, e 4% parecia barra cheia).
- Rolagem lateral no celular causada por inputs ocultos dos chips e pela barra de abas com 7 itens.
- `uploads/.htaccess` com `php_flag` derrubava os anexos com HTTP 500 sob PHP-FPM.
- Cartão gerado em JPEG: o Direct do Instagram recusa PNG vindo por URL.

### Segurança
- `install.php` cria o primeiro administrador com **senha sorteada**, mostrada uma única vez (`ADMIN_USUARIO` / `ADMIN_SENHA` para escolher).
- Endereço público do sistema configurável por `APP_URL`, em vez de domínio fixo no código.

## v1.2.0 — 14/09/2026

- Condições de clima nos lembretes ("só envie se a chance de chuva passar de X%").
- Previsão do tempo como API interna (`clima.php`) e 14 variáveis de clima nas mensagens.
- Anexos de até 16 MB nos três canais; vários números de WhatsApp.
- Painel de conexões com diagnóstico ao vivo e renovação automática do token do Instagram.
- Aviso antecipado, reenvio de falhas, duplicar lembrete, agenda mensal, importação de contatos por CSV.

## v1.0.0 — 14/09/2026

- Primeira versão: lembretes com repetição entregues por WhatsApp, Direct do Instagram e e-mail.
