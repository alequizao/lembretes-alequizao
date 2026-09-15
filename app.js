/*
 * Lembretes · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/* Lembretes — SPA (roteador por hash) */
'use strict';
const $ = (s, e = document) => e.querySelector(s);
const $$ = (s, e = document) => [...e.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const CANAIS = { whatsapp: { nome: 'WhatsApp', icone: '📱', cor: 'verde' }, direct: { nome: 'Direct', icone: '📷', cor: 'azul' }, email: { nome: 'E-mail', icone: '✉️', cor: 'amarelo' } };
const CONDICOES = {
  nenhuma:        { rotulo: 'Sempre enviar (sem condição)',        unidade: '',     padrao: 0,  horas: false },
  chuva_chance:   { rotulo: 'Chance de chuva hoje for pelo menos', unidade: '%',    padrao: 60, horas: false },
  chuva_horas:    { rotulo: 'For chover nas próximas horas',       unidade: '%',    padrao: 60, horas: true },
  chuva_mm:       { rotulo: 'Volume de chuva hoje for pelo menos', unidade: 'mm',   padrao: 5,  horas: false },
  tempestade:     { rotulo: 'Houver tempestade prevista',          unidade: '',     padrao: 0,  horas: true },
  temp_acima:     { rotulo: 'Temperatura agora estiver acima de',  unidade: '°C',   padrao: 33, horas: false },
  temp_abaixo:    { rotulo: 'Temperatura agora estiver abaixo de', unidade: '°C',   padrao: 18, horas: false },
  vento_acima:    { rotulo: 'Vento agora estiver acima de',        unidade: 'km/h', padrao: 40, horas: false },
  umidade_abaixo: { rotulo: 'Umidade agora estiver abaixo de',     unidade: '%',    padrao: 30, horas: false },
};
const REPETICOES = { nenhuma: 'Uma vez só', horaria: 'De hora em hora', diaria: 'Todo dia', semanal: 'Toda semana', quinzenal: 'A cada 15 dias', mensal: 'Todo mês', anual: 'Todo ano', dias: 'A cada N dias' };

let estado = { contatos: [], config: {}, usuario: null };

/* ---------- utilidades ---------- */
async function api(acao, dados, metodo = 'POST') {
  const opc = { method: metodo, headers: { 'Content-Type': 'application/json' } };
  if (metodo === 'POST') opc.body = JSON.stringify(dados || {});
  const r = await fetch('api.php?acao=' + acao, opc);
  if (r.status === 401) { mostrarLogin(); throw new Error('Sessão expirada'); }
  const j = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor' }));
  return j;
}
function aviso(texto, tipo = '') {
  const d = document.createElement('div');
  d.className = 'aviso ' + tipo;
  d.textContent = texto;
  $('#avisos').append(d);
  setTimeout(() => { d.style.opacity = 0; setTimeout(() => d.remove(), 300); }, 4200);
}
function numBr(v) { return String(v ?? 0).replace('.', ','); }
function dataBr(v) {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  if (isNaN(d)) return v;
  return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}
function relativo(v) {
  if (!v) return '';
  const d = new Date(v.replace(' ', 'T')), min = Math.round((d - Date.now()) / 60000);
  if (min < -1440) return 'atrasado';
  if (min < 0) return 'agora';
  if (min < 60) return `em ${min} min`;
  if (min < 1440) return `em ${Math.round(min / 60)} h`;
  return `em ${Math.round(min / 1440)} dia(s)`;
}
function paraInput(v) { return v ? v.replace(' ', 'T').slice(0, 16) : ''; }
function selosCanais(csv) {
  return '<span class="canais">' + String(csv || '').split(',').filter(Boolean)
    .map(c => `<span class="selo ${CANAIS[c]?.cor || ''}">${CANAIS[c]?.icone || ''} ${CANAIS[c]?.nome || c}</span>`).join('') + '</span>';
}
const carregando = '<div class="carregando">Carregando…</div>';

/* ---------- modal ---------- */
function abrirModal(html) {
  $('#modal').innerHTML = html;
  $('#modal-fundo').hidden = false;
  document.body.style.overflow = 'hidden';
  $$('.modal-x').forEach(b => b.onclick = fecharModal);
}
function fecharModal() { $('#modal-fundo').hidden = true; $('#modal').innerHTML = ''; document.body.style.overflow = ''; }
$('#modal-fundo').addEventListener('click', ev => { if (ev.target.id === 'modal-fundo') fecharModal(); });
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fecharModal(); });

/* ---------- login ---------- */
function mostrarLogin() { $('#tela-login').hidden = false; $('#app').hidden = true; }
function mostrarApp(u) {
  estado.usuario = u;
  $('#tela-login').hidden = true; $('#app').hidden = false;
  $('#nome-usuario').textContent = u.nome;
  $('#avatar').textContent = (u.nome || '?')[0].toUpperCase();
  navegar();
  lembrarWhatsapp(u);
}

/* A Jeovana precisa conectar o WhatsApp dela — lembramos a cada entrada, com o passo a passo. */
const USUARIOS_LEMBRAR_WA = ['jeovana'];
function lembrarWhatsapp(u) {
  const quem = String(u.usuario || '').toLowerCase();
  if (!USUARIOS_LEMBRAR_WA.includes(quem)) return;
  try { if (sessionStorage.getItem('aviso-wa-' + quem)) return; sessionStorage.setItem('aviso-wa-' + quem, '1'); } catch (e) {}
  setTimeout(() => guiaWhatsapp(true), 700);
}

window.guiaWhatsapp = function (naEntrada) {
  abrirModal(`<h2>📱 Conecte o seu WhatsApp <button class="modal-x">×</button></h2>
    <p class="dica">${naEntrada ? 'Bem-vinda! ' : ''}Para os lembretes saírem no <b>seu</b> número, o WhatsApp precisa estar conectado aqui.
      É rápido e só precisa ser feito uma vez (ou quando cair a conexão).</p>
    <ol class="passos">
      <li>Abra <b>Conexões</b> no menu ao lado (ícone 🔌).</li>
      <li>Em <b>Números de WhatsApp</b>, toque em <b>+ Adicionar número</b>.</li>
      <li>Dê um nome que você reconheça — por exemplo <b>WhatsApp da Jeovana</b> — e salve.</li>
      <li>Na linha do seu número, toque em <b>QR code</b>. Vai aparecer um quadrado preto e branco na tela.</li>
      <li>Pegue o celular: abra o <b>WhatsApp</b> → menu <b>⋮</b> → <b>Aparelhos conectados</b> → <b>Conectar aparelho</b>.</li>
      <li>Aponte a câmera do celular para o QR code que está nesta tela.</li>
      <li>Espere aparecer <b>Conectado</b> em verde. Pronto! 🎉</li>
    </ol>
    <p class="dica">⚠️ O celular precisa ter internet. Se o QR code expirar antes de você ler, é só tocar em <b>QR code</b> de novo.
      Se travar em alguma parte, me chame que eu resolvo.</p>
    <div class="modal-acoes">
      <button type="button" class="btn claro modal-x">Vejo depois</button>
      <button type="button" class="btn azul" onclick="fecharModal(); location.hash = '#/config';">Conectar agora</button>
    </div>`);
};
$('#form-login').addEventListener('submit', async ev => {
  ev.preventDefault();
  const f = ev.target, btn = f.querySelector('button');
  btn.disabled = true; btn.textContent = 'Entrando…';
  const r = await api('login', { usuario: f.usuario.value, senha: f.senha.value });
  btn.disabled = false; btn.textContent = 'Entrar';
  if (!r.ok) { const e = $('#login-erro'); e.hidden = false; e.textContent = r.erro; return; }
  $('#login-erro').hidden = true;
  mostrarApp(r.usuario);
});
$('#btn-sair').onclick = async () => { await api('sair'); location.reload(); };
const btnSenha = $('#btn-senha');
if (btnSenha) btnSenha.onclick = () => trocarSenha();
window.trocarSenha = function () {
  abrirModal(`<h2>Trocar minha senha <button class="modal-x">×</button></h2>
    <form id="form-senha">
      <div class="erro" id="erro-senha" hidden></div>
      <label>Senha atual<input type="password" name="atual" autocomplete="current-password" required></label>
      <label>Nova senha<input type="password" name="nova" autocomplete="new-password" minlength="4" required></label>
      <label>Repita a nova senha<input type="password" name="nova2" autocomplete="new-password" minlength="4" required></label>
      <div class="modal-acoes"><button type="button" class="btn claro modal-x">Cancelar</button><button class="btn azul" type="submit" id="btn-salvar-senha">Salvar</button></div>
    </form>`);
  $('#form-senha').onsubmit = async ev => {
    ev.preventDefault();
    const f = ev.target, erro = $('#erro-senha'), btn = $('#btn-salvar-senha');
    if (f.nova.value !== f.nova2.value) { erro.hidden = false; erro.textContent = 'As duas senhas novas não são iguais.'; return; }
    btn.disabled = true; btn.textContent = 'Salvando…';
    const r = await api('senha_trocar', { atual: f.atual.value, nova: f.nova.value });
    btn.disabled = false; btn.textContent = 'Salvar';
    if (!r.ok) { erro.hidden = false; erro.textContent = r.erro; return; }
    fecharModal(); aviso('Senha alterada!', 'ok');
  };
};

/* ---------- recuperação de senha ---------- */
const btnEsqueci = $('#btn-esqueci');
if (btnEsqueci) btnEsqueci.onclick = async () => {
  const f = $('#form-login');
  const quem = f.usuario.value.trim();
  const erro = $('#login-erro'), ok = $('#login-ok');
  erro.hidden = true; ok.hidden = true;
  if (!quem) { erro.hidden = false; erro.textContent = 'Escreva seu usuário ou e-mail primeiro.'; f.usuario.focus(); return; }
  btnEsqueci.disabled = true; btnEsqueci.textContent = 'Enviando…';
  const r = await api('senha_recuperar', { usuario: quem }).catch(() => ({ ok: false, msg: 'Sem conexão.' }));
  btnEsqueci.disabled = false; btnEsqueci.textContent = 'Esqueci minha senha';
  ok.hidden = false; ok.textContent = r.msg || 'Se esse usuário existir, o link chega em instantes.';
};
function telaRedefinir(token) {
  $('#tela-login').hidden = false; $('#app').hidden = true;
  abrirModal(`<h2>Criar uma nova senha</h2>
    <p class="dica">Escolha a nova senha do painel. O link só funciona uma vez.</p>
    <form id="form-redefinir">
      <div class="erro" id="erro-redefinir" hidden></div>
      <label>Nova senha<input type="password" name="nova" minlength="4" autocomplete="new-password" required autofocus></label>
      <label>Repita a nova senha<input type="password" name="nova2" minlength="4" autocomplete="new-password" required></label>
      <div class="modal-acoes"><button class="btn azul" type="submit" id="btn-redefinir">Salvar nova senha</button></div>
    </form>`);
  $('#form-redefinir').onsubmit = async ev => {
    ev.preventDefault();
    const f = ev.target, erro = $('#erro-redefinir'), btn = $('#btn-redefinir');
    if (f.nova.value !== f.nova2.value) { erro.hidden = false; erro.textContent = 'As duas senhas não são iguais.'; return; }
    btn.disabled = true; btn.textContent = 'Salvando…';
    const r = await api('senha_redefinir', { token, nova: f.nova.value });
    btn.disabled = false; btn.textContent = 'Salvar nova senha';
    if (!r.ok) { erro.hidden = false; erro.textContent = r.erro; return; }
    fecharModal();
    history.replaceState(null, '', location.pathname);
    const lf = $('#form-login');
    if (r.usuario) lf.usuario.value = r.usuario;
    const ok = $('#login-ok'); ok.hidden = false; ok.textContent = 'Senha alterada! Entre com a nova senha.';
    lf.senha.focus();
  };
}

/* ---------- roteador ---------- */
const ROTAS = { painel: ['Painel', telaPainel], lembretes: ['Lembretes', telaLembretes], agenda: ['Agenda do mês', telaAgenda], contatos: ['Contatos', telaContatos], envios: ['Envios', telaEnvios], clima: ['Alerta de chuva', telaClima], mensagens: ['Mensagens', telaMensagens], config: ['Conexões e configurações', telaConfig] };
function rotaAtual() { return (location.hash.replace('#/', '') || 'painel').split('/')[0]; }
async function navegar() {
  const r = rotaAtual(), [titulo, fn] = ROTAS[r] || ROTAS.painel;
  $('#titulo-pagina').textContent = titulo;
  $$('[data-rota]').forEach(a => a.classList.toggle('ativo', a.dataset.rota === r));
  $('#lateral').classList.remove('aberta'); $('#backdrop').classList.remove('on');
  $('#pagina').innerHTML = carregando;
  try { await fn(); } catch (e) { $('#pagina').innerHTML = `<div class="card"><b>Erro:</b> ${esc(e.message)}</div>`; }
}
window.addEventListener('hashchange', navegar);
$('#btn-menu').onclick = () => { $('#lateral').classList.toggle('aberta'); $('#backdrop').classList.toggle('on'); };
$('#backdrop').onclick = () => { $('#lateral').classList.remove('aberta'); $('#backdrop').classList.remove('on'); };
$('#btn-novo').onclick = () => editarLembrete(null);

/* ================= PAINEL ================= */
async function telaPainel() {
  const r = await api('painel', null, 'GET');
  if (!r.ok) throw new Error(r.erro || 'falha');
  const c = r.cartoes;
  const wa = r.whatsapp;
  atualizarSeloWa(wa);
  const prox = r.proximos.length ? `<div class="rolar"><table>
      <thead><tr><th>Lembrete</th><th>Quando</th><th>Para</th><th>Canais</th></tr></thead><tbody>
      ${r.proximos.map(l => `<tr>
        <td data-r="Lembrete"><b>${esc(l.titulo)}</b>${l.repeticao !== 'nenhuma' ? ` <span class="selo">🔁 ${REPETICOES[l.repeticao]}</span>` : ''}</td>
        <td data-r="Quando">${dataBr(l.proxima_em)}<br><small style="color:var(--texto2)">${relativo(l.proxima_em)}</small></td>
        <td data-r="Para">${esc(l.contatos || '—')}</td><td data-r="Canais">${selosCanais(l.canais)}</td></tr>`).join('')}
      </tbody></table></div>`
    : `<div class="vazio"><b>Nenhum lembrete agendado</b>Clique em “+ Novo lembrete” para criar o primeiro.</div>`;
  estado.envios = r.ultimos || [];
  const ult = r.ultimos.length ? `<div class="rolar"><table>
      <thead><tr><th>Quando</th><th>Lembrete</th><th>Para</th><th>Mensagem enviada</th><th>Canal</th><th>Situação</th></tr></thead><tbody>
      ${r.ultimos.map(e => linhaEnvio(e)).join('')}</tbody></table></div>`
    : `<div class="vazio">Nenhum envio ainda.</div>`;
  $('#pagina').innerHTML = `
    <div class="grade">
      <div class="metrica"><div class="rotulo">🔔 Lembretes ativos</div><div class="valor">${c.ativos}</div></div>
      <div class="metrica"><div class="rotulo">📅 Para hoje</div><div class="valor">${c.hoje}</div></div>
      <div class="metrica"><div class="rotulo">👤 Contatos</div><div class="valor">${c.contatos}</div></div>
      <div class="metrica ok"><div class="rotulo">✅ Enviados (7 dias)</div><div class="valor">${c.enviados7}</div></div>
      <div class="metrica ${c.erros7 ? 'erro' : ''}"><div class="rotulo">⚠️ Falhas (7 dias)</div><div class="valor">${c.erros7}</div></div>
    </div>
    <div class="card"><h2>Conexões</h2>
      <p class="dica">Estado ao vivo dos canais de envio — aqui você vê na hora se precisa reconectar alguma coisa.</p>
      <div id="conexoes-painel"></div></div>
    <div class="card"><h2>Próximos envios</h2><p class="dica">Os lembretes disparam sozinhos, minuto a minuto.</p>${prox}</div>
    <div class="card"><h2>Últimos envios</h2>${ult}</div>`;
  carregarConexoes('#conexoes-painel');
}
function atualizarSeloWa(wa) {
  const s = $('#selo-wa');
  s.className = 'selo ' + (wa.ok ? 'verde' : 'vermelho');
  s.textContent = wa.ok ? '📱 WhatsApp conectado' : '📱 WhatsApp offline';
}


/* ================= MENSAGENS (caixa de entrada — só do administrador) ================= */
let msgEstado = { caixa: null, conversa: null, conversas: [], busca: '', timer: null,
                  assinaturaLista: '', assinaturaThread: '', ocupado: false };

/* assinatura simples do conteúdo, para não redesenhar a tela à toa */
function msgAssinatura(v) { try { return JSON.stringify(v); } catch (e) { return String(Math.random()); } }

function msgPararRelogio() {
  if (msgEstado.timer) { clearInterval(msgEstado.timer); msgEstado.timer = null; }
  document.removeEventListener('visibilitychange', msgAoVoltar);
}
function msgAoVoltar() { if (!document.hidden) msgAtualizar(); }

/* Uma rodada de atualização: as duas consultas saem juntas, sem esperar uma pela outra. */
async function msgAtualizar() {
  if (rotaAtual() !== 'mensagens') return msgPararRelogio();
  if (document.hidden || msgEstado.ocupado) return;
  msgEstado.ocupado = true;
  const pulso = $('#msg-pulso');
  if (pulso) pulso.classList.add('on');
  try {
    await Promise.all([
      msgCarregarConversas(true),
      msgEstado.conversa ? msgAbrir(msgEstado.conversa, true, true) : Promise.resolve(),
    ]);
  } catch (e) { /* silêncio: a próxima rodada tenta de novo */ }
  msgEstado.ocupado = false;
  if (pulso) setTimeout(() => pulso.classList.remove('on'), 400);
}

async function telaMensagens() {
  msgPararRelogio();
  if (!window.EH_DONO) {
    $('#pagina').innerHTML = '<div class="card"><div class="vazio"><b>Área restrita</b>Esta página é só do administrador.</div></div>';
    return;
  }
  const r = await api('msg_caixas', null, 'GET');
  if (!r.ok) { $('#pagina').innerHTML = `<div class="card"><div class="vazio"><b>${esc(r.erro || 'Não consegui abrir')}</b></div></div>`; return; }
  const caixas = r.itens || [];
  if (!msgEstado.caixa || !caixas.some(c => c.chave === msgEstado.caixa)) {
    msgEstado.caixa = (caixas.find(c => c.conectado) || caixas[0] || {}).chave || null;
    msgEstado.conversa = null;
  }
  $('#pagina').innerHTML = `<div class="card">
      <h2>Caixa de mensagens</h2>
      <p class="dica">Conversas de cada número de WhatsApp e de cada @ do Instagram. Só você vê esta página.</p>
      <div class="msg-caixas">${caixas.map(c => `
        <button class="msg-caixa ${c.chave === msgEstado.caixa ? 'ativa' : ''}" onclick="msgTrocarCaixa('${esc(c.chave)}')">
          <span class="msg-caixa-ic">${c.tipo === 'whatsapp' ? '📱' : '📷'}</span>
          <span class="msg-caixa-txt"><b>${esc(c.nome)}</b><small>${esc(c.apelido || '')}</small></span>
          <span class="selo ${c.conectado ? 'verde' : 'vermelho'}">${c.conectado ? 'on' : 'off'}</span>
        </button>`).join('') || '<div class="vazio">Nenhuma conexão cadastrada.</div>'}</div>
      <div class="msg-painel">
        <div class="msg-lista">
          <div class="msg-busca-linha">
            <input id="msg-busca" type="search" placeholder="🔎 Buscar conversa…" value="${esc(msgEstado.busca)}">
            <span class="msg-pulso" id="msg-pulso" title="Atualizando sozinho a cada 5 segundos"></span>
          </div>
          <div id="msg-conversas" class="msg-conversas">${carregando}</div>
        </div>
        <div class="msg-thread" id="msg-thread">
          <div class="vazio">Escolha uma conversa à esquerda.</div>
        </div>
      </div>
    </div>`;
  let t;
  $('#msg-busca').oninput = ev => { clearTimeout(t); t = setTimeout(() => { msgEstado.busca = ev.target.value.trim(); msgCarregarConversas(); }, 350); };
  await msgCarregarConversas();
  if (msgEstado.conversa) msgAbrir(msgEstado.conversa, true);
  /* atualização contínua: lista e conversa aberta ao mesmo tempo, de 5 em 5 segundos */
  msgEstado.timer = setInterval(msgAtualizar, 5000);
  document.addEventListener('visibilitychange', msgAoVoltar);
}

function msgCaixaAtual() {
  const [tipo, id] = String(msgEstado.caixa || '').split(':');
  return { tipo, id: +id || 0 };
}

/* No celular: volta da conversa para a lista. */
window.msgVoltarLista = function () {
  const painel = document.querySelector('.msg-painel');
  if (painel) painel.classList.remove('vendo-conversa');
  msgEstado.conversa = null;
  msgEstado.assinaturaLista = '';
  msgCarregarConversas();
};

window.msgTrocarCaixa = function (chave) {
  msgEstado.caixa = chave; msgEstado.conversa = null;
  const painel = document.querySelector('.msg-painel');
  if (painel) painel.classList.remove('vendo-conversa');
  msgEstado.assinaturaLista = ''; msgEstado.assinaturaThread = '';
  $('#msg-thread').innerHTML = '<div class="vazio">Escolha uma conversa à esquerda.</div>';
  $$('.msg-caixa').forEach(b => b.classList.toggle('ativa', b.getAttribute('onclick').includes("'" + chave + "'")));
  msgCarregarConversas();
};

async function msgCarregarConversas(silencioso) {
  const c = msgCaixaAtual();
  if (!c.tipo) return;
  const caixa = $('#msg-conversas'); if (!caixa) return;
  if (!silencioso) caixa.innerHTML = carregando;
  const r = await api('msg_conversas', { tipo: c.tipo, id: c.id, busca: msgEstado.busca }).catch(() => ({ ok: false }));
  if (!r.ok) { caixa.innerHTML = `<div class="vazio">${esc(r.erro || 'Não consegui carregar as conversas.')}</div>`; return; }
  msgEstado.conversas = r.itens || [];
  const assinatura = msgAssinatura([msgEstado.conversas, msgEstado.conversa]);
  if (silencioso && assinatura === msgEstado.assinaturaLista) return;
  msgEstado.assinaturaLista = assinatura;
  caixa.innerHTML = msgEstado.conversas.length ? msgEstado.conversas.map(k => `
    <button class="msg-item ${String(k.id) === String(msgEstado.conversa) ? 'ativa' : ''}" onclick="msgAbrir('${esc(String(k.id))}')">
      <span class="msg-av">${k.foto ? `<img src="${esc(k.foto)}" alt="" loading="lazy">` : (k.grupo ? '👥' : '👤')}</span>
      <span class="msg-item-txt">
        <b>${esc(k.nome)}</b>
        <small>${k.minha ? '↩︎ ' : ''}${esc((k.ultima || '').slice(0, 60))}</small>
      </span>
      <span class="msg-item-dir">
        <small>${esc(msgQuando(k.quando))}</small>
        ${+k.nao_lidas ? `<span class="selo azul">${k.nao_lidas}</span>` : ''}
      </span>
    </button>`).join('') : '<div class="vazio">Nenhuma conversa aqui ainda.</div>';
}

/* Prévia do arquivo dentro do balão. Vídeo e áudio só carregam quando a pessoa toca. */
function msgMidiaHtml(m, caixa) {
  if (!m.midia_tipo || !m.midia_id) return '';
  const url = `midia.php?tipo=${encodeURIComponent(caixa.tipo)}&id=${caixa.id}&m=${encodeURIComponent(m.midia_id)}`;
  switch (m.midia_tipo) {
    case 'imagem':
    case 'figurinha':
      return `<img class="balao-img ${m.midia_tipo === 'figurinha' ? 'figurinha' : ''}" src="${esc(url)}" alt="${esc(m.anexo || 'imagem')}"
                loading="lazy" onclick="msgVerGrande('${esc(url)}')" onerror="this.replaceWith(msgMidiaFalhou())">`;
    case 'video':
      return `<video class="balao-video" src="${esc(url)}" controls preload="none" playsinline></video>`;
    case 'audio':
      return `<audio class="balao-audio" src="${esc(url)}" controls preload="none"></audio>`;
    default:
      return `<a class="balao-doc" href="${esc(url)}&baixar=1" target="_blank" rel="noopener">📄 ${esc(m.arquivo || 'Baixar arquivo')}</a>`;
  }
}
window.msgMidiaFalhou = function () {
  const s = document.createElement('span');
  s.className = 'balao-anexo';
  s.textContent = '📎 arquivo indisponível';
  return s;
};
window.msgVerGrande = function (url) {
  abrirModal(`<h2>Arquivo <button class="modal-x">×</button></h2>
    <div class="midia-grande"><img src="${esc(url)}" alt=""></div>
    <div class="modal-acoes">
      <a class="btn claro" href="${esc(url)}&baixar=1" target="_blank" rel="noopener">Baixar</a>
      <button type="button" class="btn azul modal-x">Fechar</button>
    </div>`);
};

/* "14:32" hoje, "ontem", ou a data */
function msgQuando(v) {
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T')), hoje = new Date();
  const mesmoDia = d.toDateString() === hoje.toDateString();
  if (mesmoDia) return d.toTimeString().slice(0, 5);
  const ontem = new Date(hoje.getTime() - 86400000);
  if (d.toDateString() === ontem.toDateString()) return 'ontem';
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

window.msgAbrir = async function (id, manterRolagem, silencioso) {
  const c = msgCaixaAtual();
  const trocou = String(msgEstado.conversa) !== String(id);
  if (trocou) { msgEstado.assinaturaThread = ''; msgEstado.assinaturaLista = ''; }
  msgEstado.conversa = id;
  const painel = document.querySelector('.msg-painel');
  if (painel) painel.classList.add('vendo-conversa');   /* no celular, mostra só a conversa */
  const alvo = $('#msg-thread'); if (!alvo) return;
  const dados = msgEstado.conversas.find(k => String(k.id) === String(id)) || { nome: '' };
  if (!silencioso) alvo.innerHTML = carregando;
  $$('.msg-item').forEach(b => b.classList.toggle('ativa', b.getAttribute('onclick').includes("'" + id + "'")));
  const r = await api('msg_mensagens', { tipo: c.tipo, id: c.id, conversa: id, marcar_lida: trocou ? 1 : 0 }).catch(() => ({ ok: false }));
  if (!r.ok) { alvo.innerHTML = `<div class="vazio">${esc(r.erro || 'Não consegui abrir a conversa.')}</div>`; return; }
  const itens = r.itens || [];
  const bloqueado = c.tipo === 'direct' && dados.janela_ok === false;
  const assinatura = msgAssinatura([id, itens, bloqueado]);
  if (silencioso && assinatura === msgEstado.assinaturaThread) return;
  msgEstado.assinaturaThread = assinatura;
  const rolagem = alvo.querySelector('.msg-baloes');
  const estavaNoFim = !rolagem || (rolagem.scrollHeight - rolagem.scrollTop - rolagem.clientHeight < 80);
  /* guarda o que estava sendo digitado para não perder no refresh */
  const rascunho = alvo.querySelector('#msg-texto');
  const textoEmCurso = rascunho ? rascunho.value : '';
  const digitando = rascunho && document.activeElement === rascunho;
  const posicao = rascunho ? rascunho.selectionStart : 0;
  alvo.innerHTML = `
    <div class="msg-cabeca">
      <button type="button" class="msg-voltar" onclick="msgVoltarLista()" aria-label="Voltar para as conversas">←</button>
      <span class="msg-cabeca-txt">
      <b>${esc(dados.nome || '')}</b>
      <small>${esc(dados.apelido || '')}${c.tipo === 'direct' ? ' · Direct' : ' · WhatsApp'}</small>
      </span>
    </div>
    <div class="msg-baloes">${itens.length ? itens.map(m => `
      <div class="balao ${m.minha ? 'meu' : 'dele'}">
        ${msgMidiaHtml(m, c)}
        ${m.texto ? `<span class="balao-txt">${esc(m.texto)}</span>` : ''}
        ${m.anexo && !m.midia_tipo ? `<span class="balao-anexo">${esc(m.anexo)}</span>` : ''}
        <span class="balao-pe">${esc(msgQuando(m.quando))}${m.situacao ? ' · ' + esc(m.situacao) : ''}</span>
      </div>`).join('') : '<div class="vazio">Sem mensagens nesta conversa.</div>'}</div>
    ${bloqueado
      ? '<div class="dica msg-bloqueado">⚠️ Passaram-se mais de 24 h desde a última mensagem dela — a Meta não deixa responder por Direct agora.</div>'
      : `<form class="msg-responder" id="msg-form">
          <label class="msg-clipe" for="msg-arquivo" title="Enviar foto, vídeo, áudio ou documento">📎
            <input type="file" id="msg-arquivo" hidden multiple
              accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"></label>
          <textarea id="msg-texto" rows="2" placeholder="Escreva a resposta e aperte Enter…"></textarea>
          <button class="btn azul" type="submit" id="msg-enviar">Enviar</button>
        </form>`}`;
  const bal = alvo.querySelector('.msg-baloes');
  if (bal && (estavaNoFim || !manterRolagem)) bal.scrollTop = bal.scrollHeight;
  const f = $('#msg-form');
  if (f) {
    const campo = $('#msg-texto');
    campo.onkeydown = ev => { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); f.requestSubmit(); } };
    f.onsubmit = async ev => {
      ev.preventDefault();
      const texto = campo.value.trim();
      if (!texto) return;
      const btn = $('#msg-enviar');
      btn.disabled = true; btn.textContent = 'Enviando…';
      const env = await api('msg_enviar', { tipo: c.tipo, id: c.id, conversa: id, texto }).catch(() => ({ ok: false, erro: 'sem conexão' }));
      btn.disabled = false; btn.textContent = 'Enviar';
      if (!env.ok) return aviso(env.erro || 'Não consegui enviar.', 'ruim');
      campo.value = '';
      msgEstado.assinaturaThread = ''; msgEstado.assinaturaLista = '';
      await Promise.all([msgAbrir(id, false, true), msgCarregarConversas(true)]);
    };
    if (textoEmCurso) {
      campo.value = textoEmCurso;
      if (digitando) { campo.focus(); try { campo.setSelectionRange(posicao, posicao); } catch (e) {} }
    }
    const entrada = $('#msg-arquivo');
    if (entrada) entrada.onchange = async ev => {
      const arquivos = [...ev.target.files];
      ev.target.value = '';
      await msgEnviarArquivos(arquivos, c, id, campo);
    };
    if (!silencioso) campo.focus();
  }
};

/* Manda cada arquivo escolhido; o texto do campo vira legenda do primeiro. */
async function msgEnviarArquivos(arquivos, caixa, conversa, campo) {
  const btn = $('#msg-enviar');
  for (let i = 0; i < arquivos.length; i++) {
    const arq = arquivos[i];
    if (arq.size > 16 * 1024 * 1024) { aviso(`"${arq.name}" passa de 16 MB.`, 'ruim'); continue; }
    if (btn) { btn.disabled = true; btn.textContent = `Enviando ${i + 1}/${arquivos.length}…`; }
    const fd = new FormData();
    fd.append('arquivo', arq);
    fd.append('tipo', caixa.tipo);
    fd.append('id', caixa.id);
    fd.append('conversa', conversa);
    if (i === 0 && campo && campo.value.trim()) fd.append('legenda', campo.value.trim());
    const r = await fetch('api.php?acao=msg_enviar_midia', { method: 'POST', body: fd })
      .then(x => x.json()).catch(() => ({ ok: false, erro: 'Falha no envio' }));
    if (!r.ok) aviso(r.erro || `Não consegui enviar "${arq.name}".`, 'ruim');
    else if (i === 0 && campo) campo.value = '';
  }
  if (btn) { btn.disabled = false; btn.textContent = 'Enviar'; }
  msgEstado.assinaturaThread = ''; msgEstado.assinaturaLista = '';
  await Promise.all([msgAbrir(conversa, false, true), msgCarregarConversas(true)]);
}

/* ================= LEMBRETES ================= */
async function telaLembretes() {
  const [r, rc] = await Promise.all([api('lembretes', null, 'GET'), api('contatos', null, 'GET')]);
  estado.contatos = rc.itens || [];
  const itens = r.itens || [];
  estado.lembretes = itens;
  const linhas = itens.map(l => `<tr data-busca="${esc(((l.titulo || '') + ' ' + (l.mensagem || '') + ' ' + (l.contatos || '')).toLowerCase())}" data-status="${esc(l.status)}" data-canais="${esc(l.canais)}">
      <td data-r="Lembrete"><b>${esc(l.titulo)}</b><br><small style="color:var(--texto2)">${esc((l.mensagem || '').slice(0, 70))}${(l.mensagem || '').length > 70 ? '…' : ''}</small></td>
      <td data-r="Próximo envio">${dataBr(l.proxima_em || l.quando)}<br><small style="color:var(--texto2)">${l.status === 'ativo' ? relativo(l.proxima_em) : ''}</small></td>
      <td data-r="Repetição"><span class="selo">🔁 ${REPETICOES[l.repeticao]}${l.repeticao === 'dias' ? ' (' + l.intervalo_dias + ')' : ''}</span>${+l.antecedencia ? `<br><span class="selo amarelo">⏰ avisa ${textoAntecedencia(+l.antecedencia)} antes</span>` : ''}${textoCondicao(l)}</td>
      <td data-r="Canais">${selosCanais(l.canais)}${+l.qtd_midias ? ` <span class="selo">📎 ${l.qtd_midias}</span>` : ''}</td>
      <td data-r="Para">${esc(l.contatos || '—')}</td>
      <td data-r="Situação"><span class="selo ${l.status === 'ativo' ? 'verde' : l.status === 'pausado' ? 'amarelo' : ''}">${l.status}</span></td>
      <td class="acoes">
        <button class="btn claro peq" onclick="testarLembrete(${l.id})">Testar</button>
        <button class="btn claro peq" onclick="alternarLembrete(${l.id},'${l.status}')">${l.status === 'ativo' ? 'Pausar' : 'Ativar'}</button>
        <button class="btn claro peq" onclick="adiarLembrete(${l.id})">Adiar</button>
        <button class="btn claro peq" onclick="duplicarLembrete(${l.id})">Duplicar</button>
        <button class="btn claro peq" onclick="editarLembrete(${l.id})">Editar</button>
        <button class="btn perigo peq" onclick="excluirLembrete(${l.id})">Excluir</button>
      </td></tr>`).join('');
  $('#pagina').innerHTML = `<div class="card">
      <h2>Meus lembretes</h2><p class="dica">Use <b>{nome}</b>, <b>{titulo}</b>, <b>{data}</b> e <b>{hora}</b> na mensagem — o sistema troca pelos dados de cada contato.</p>
      <div class="filtros">
        <input id="busca-lembretes" type="search" placeholder="🔎 Buscar por título, mensagem ou contato…">
        <select id="filtro-status"><option value="">Todas as situações</option><option value="ativo">Ativos</option><option value="pausado">Pausados</option><option value="concluido">Concluídos</option></select>
        <select id="filtro-canal"><option value="">Todos os canais</option><option value="whatsapp">📱 WhatsApp</option><option value="direct">📷 Direct</option><option value="email">✉️ E-mail</option></select>
        <span class="filtro-conta" id="conta-lembretes"></span>
      </div>
      <div class="barra-vars"><button class="btn claro peq" onclick="modeloClima()">🌦️ Criar alerta de chuva</button></div>
      ${itens.length ? `<div class="rolar"><table><thead><tr><th>Lembrete</th><th>Próximo envio</th><th>Repetição</th><th>Canais</th><th>Para</th><th>Situação</th><th></th></tr></thead><tbody>${linhas}</tbody></table></div>`
        : `<div class="vazio"><b>Nenhum lembrete ainda</b>Crie o primeiro no botão “+ Novo lembrete”.</div>`}
    </div>`;
  ['#busca-lembretes', '#filtro-status', '#filtro-canal'].forEach(sel => {
    const el = $(sel); if (el) el.oninput = el.onchange = filtrarLembretes;
  });
  filtrarLembretes();
}
/* selo que resume a condição de clima do lembrete */
function textoCondicao(l) {
  const k = l.condicao_tipo || 'nenhuma';
  if (k === 'nenhuma') return '';
  const c = CONDICOES[k]; if (!c) return '';
  const v = c.unidade ? ' ' + (+l.condicao_valor || 0) + c.unidade : '';
  const h = c.horas ? ` · ${+l.condicao_horas || 6}h à frente` : '';
  return `<br><span class="selo azul">🌦️ só se ${esc(c.rotulo.toLowerCase())}${esc(v)}${esc(h)}</span>`;
}

/* Modelo pronto: alerta de chuva vigiando de hora em hora. */
window.modeloClima = function () {
  editarLembrete(0, {
    titulo: 'Alerta de chuva',
    mensagem: 'Olha a chuva, {primeiro_nome}! 🌧️\nEm {cidade} agora: {clima}.\nChance de chuva hoje: {chance_chuva} · Próximas horas: {clima_proximas_horas}',
    quando: (() => { const d = new Date(Date.now() + 5 * 60000);
      const z = n => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())} ${z(d.getHours())}:${z(d.getMinutes())}:00`; })(),
    repeticao: 'horaria',
    condicao_tipo: 'chuva_horas', condicao_valor: 60, condicao_horas: 6, condicao_antirrepete: 1,
  });
};

/* filtro da lista (roda no navegador, sem recarregar) */
function filtrarLembretes() {
  const b = ($('#busca-lembretes')?.value || '').trim().toLowerCase();
  const st = $('#filtro-status')?.value || '', ca = $('#filtro-canal')?.value || '';
  let n = 0, total = 0;
  $$('#pagina tbody tr[data-busca]').forEach(tr => {
    total++;
    const ok = (!b || tr.dataset.busca.includes(b)) && (!st || tr.dataset.status === st)
            && (!ca || tr.dataset.canais.split(',').includes(ca));
    tr.hidden = !ok; if (ok) n++;
  });
  const c = $('#conta-lembretes');
  if (c) c.textContent = n === total ? `${total} lembrete(s)` : `${n} de ${total} lembrete(s)`;
}
function textoAntecedencia(min) {
  if (min < 60) return min + ' min';
  if (min < 1440) return Math.round(min / 60) + ' h';
  return Math.round(min / 1440) + ' dia(s)';
}
window.editarLembrete = async function (id, modelo) {
  if (!estado.contatos.length) { const rc = await api('contatos', null, 'GET'); estado.contatos = rc.itens || []; }
  if (!estado.whats) { const rw = await api('wa_lista', null, 'GET'); estado.whats = rw.itens || []; }
  if (!estado.contatos.length) { aviso('Cadastre um contato antes de criar um lembrete.', 'ruim'); location.hash = '#/contatos'; return; }
  let l = { titulo: '', mensagem: '', quando: '', repeticao: 'nenhuma', intervalo_dias: 3, repetir_ate: '', canais: 'whatsapp', status: 'ativo', contato_ids: '',
            condicao_tipo: 'nenhuma', condicao_valor: 0, condicao_horas: 6, condicao_cidade: '', condicao_antirrepete: 1 };
  if (modelo) l = Object.assign(l, modelo);
  if (id) {
    if (!estado.lembretes) { const r = await api('lembretes', null, 'GET'); estado.lembretes = r.itens; }
    l = estado.lembretes.find(x => +x.id === +id) || l;
  }
  const marcados = String(l.contato_ids || '').split(',').filter(Boolean).map(Number);
  const canais = String(l.canais).split(',');
  abrirModal(`<h2>${id ? 'Editar lembrete' : 'Novo lembrete'} <button class="modal-x">×</button></h2>
    <form id="form-lembrete">
      <div class="erro" id="erro-lembrete" hidden></div>
      <label>Título<input name="titulo" value="${esc(l.titulo)}" maxlength="160" placeholder="Ex.: Consulta da Jeovana"></label>
      <label>Mensagem<textarea name="mensagem" placeholder="{saudacao}, {primeiro_nome}! Lembrete: {titulo} — {dia_semana}, dia {data} às {hora}.">${esc(l.mensagem)}</textarea></label>
      <div class="barra-vars">
        <button type="button" class="btn claro peq" onclick="abrirVariaveis()">➕ Inserir variável</button>
        <button type="button" class="btn claro peq" onclick="verPrevia()">👁️ Ver prévia</button>
      </div>
      <div id="painel-vars" class="painel-vars" hidden></div>
      <div id="previa-msg" class="previa" hidden></div>
      <div style="font-weight:500;margin-bottom:6px">Anexos <small style="color:var(--texto2);font-weight:400">(imagem, vídeo, áudio ou documento, até 16 MB)</small></div>
      <div class="anexos" id="lista-anexos"></div>
      <label class="botao-anexo" for="arquivo-anexo">📎 Anexar arquivo<input type="file" id="arquivo-anexo" hidden multiple accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"></label>
      <div class="dica" id="anexo-dica">O texto vai primeiro; cada anexo sai em seguida. No e-mail, arquivos de até 7 MB vão anexados — acima disso, vira link.</div>
      <div class="form-grade">
        <label>Data e hora<input type="datetime-local" name="quando" value="${paraInput(l.proxima_em || l.quando)}"></label>
        <label>Repetição<select name="repeticao">${Object.entries(REPETICOES).map(([k, v]) => `<option value="${k}" ${l.repeticao === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        <label id="campo-dias" ${l.repeticao === 'dias' ? '' : 'hidden'}>A cada quantos dias<input type="number" name="intervalo_dias" min="1" max="365" value="${l.intervalo_dias || 3}"></label>
        <label>Repetir até (opcional)<input type="date" name="repetir_ate" value="${esc(l.repetir_ate || '')}"></label>
        <label>Avisar antes<select name="antecedencia">${
          [[0, 'Não avisar antes'], [10, '10 minutos antes'], [30, '30 minutos antes'], [60, '1 hora antes'], [180, '3 horas antes'],
           [360, '6 horas antes'], [720, '12 horas antes'], [1440, '1 dia antes'], [2880, '2 dias antes'], [10080, '1 semana antes']]
          .map(([v, t]) => `<option value="${v}" ${+l.antecedencia === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
      </div>
      <div class="dica">O aviso antecipado é uma mensagem a mais, só com o texto (sem anexos), antes da hora marcada.</div>
      <div style="font-weight:500;margin:14px 0 2px">🌦️ Enviar só se… <small style="color:var(--texto2);font-weight:400">(condição de tempo)</small></div>
      <div class="form-grade">
        <label>Condição<select name="condicao_tipo">${Object.entries(CONDICOES)
          .map(([k, c]) => `<option value="${k}" ${(l.condicao_tipo || 'nenhuma') === k ? 'selected' : ''}>${c.rotulo}</option>`).join('')}</select></label>
        <label id="campo-cond-valor" hidden>Valor<input type="number" name="condicao_valor" step="0.1" value="${esc(String(+l.condicao_valor || 0))}"><small class="dica" id="cond-unidade"></small></label>
        <label id="campo-cond-horas" hidden>Olhar quantas horas à frente<input type="number" name="condicao_horas" min="1" max="48" value="${+l.condicao_horas || 6}"></label>
        <label id="campo-cond-cidade" hidden>Cidade da previsão<input name="condicao_cidade" value="${esc(l.condicao_cidade || '')}" placeholder="vazio = cidade padrão de Conexões"></label>
      </div>
      <label class="opcao ${+l.condicao_antirrepete ? 'marcada' : ''}" id="campo-cond-anti" hidden>
        <input type="checkbox" name="condicao_antirrepete" ${+l.condicao_antirrepete ? 'checked' : ''}> Avisar no máximo uma vez por dia</label>
      <div id="bloco-cond-teste" hidden>
        <button type="button" class="btn claro peq" onclick="testarCondicao()">🔎 Testar a condição agora</button>
        <div class="dica" id="cond-resultado"></div>
        <div class="dica">Com uma condição ligada, o lembrete vira uma <b>verificação</b>: na hora marcada ele consulta a previsão e só envia se a condição bater. Para vigiar o dia inteiro, use a repetição <b>De hora em hora</b>.</div>
      </div>
      <div style="font-weight:500;margin-bottom:2px">Canais</div>
      <div class="opcoes">${Object.entries(CANAIS).map(([k, c]) => `
        <label class="opcao ${canais.includes(k) ? 'marcada' : ''}"><input type="checkbox" name="canais" value="${k}" ${canais.includes(k) ? 'checked' : ''}> ${c.icone} ${c.nome}</label>`).join('')}</div>
      ${(estado.whats || []).length > 1 ? `<label>Enviar o WhatsApp por
        <select name="whatsapp_id">
          <option value="0">Número padrão (${esc((estado.whats.find(w => +w.padrao) || {}).nome || '—')})</option>
          ${estado.whats.map(w => `<option value="${w.id}" ${+l.whatsapp_id === +w.id ? 'selected' : ''}>${esc(w.nome)}${w.numero ? ' · ' + esc(w.numero) : ''}</option>`).join('')}
        </select></label>` : ''}
      <div style="font-weight:500;margin-bottom:6px">Enviar para</div>
      <div class="lista-contatos">${estado.contatos.map(c => `
        <label class="opcao ${marcados.includes(+c.id) ? 'marcada' : ''}"><input type="checkbox" name="contatos" value="${c.id}" ${marcados.includes(+c.id) ? 'checked' : ''}>
          ${esc(c.nome)} <small style="color:var(--texto2);font-weight:400">${esc([c.whatsapp, c.email, c.ig_usuario ? '@' + c.ig_usuario : ''].filter(Boolean).join(' · '))}</small></label>`).join('')}</div>
      <label>Situação<select name="status"><option value="ativo" ${l.status === 'ativo' ? 'selected' : ''}>Ativo</option><option value="pausado" ${l.status === 'pausado' ? 'selected' : ''}>Pausado</option></select></label>
      <div class="modal-acoes"><button type="button" class="btn claro modal-x">Cancelar</button><button class="btn azul" type="submit" id="btn-salvar-lembrete">Salvar</button></div>
    </form>`);
  const f = $('#form-lembrete');
  anexosAtuais = [];
  desenharAnexos();
  if (id) {
    api('midias', { lembrete_id: id }).then(r => { if (r.ok) { anexosAtuais = r.itens; desenharAnexos(); } });
  }
  $('#arquivo-anexo').onchange = ev => { enviarAnexos([...ev.target.files], id || 0); ev.target.value = ''; };
  f.repeticao.onchange = () => { $('#campo-dias').hidden = f.repeticao.value !== 'dias'; };
  const pintarCondicao = (trocouUsuario) => {
    const k = f.condicao_tipo.value, c = CONDICOES[k] || CONDICOES.nenhuma, tem = k !== 'nenhuma';
    const precisaValor = tem && !!c.unidade;
    $('#campo-cond-valor').hidden = !precisaValor;
    $('#campo-cond-horas').hidden = !(tem && c.horas);
    $('#campo-cond-cidade').hidden = !tem;
    $('#campo-cond-anti').hidden = !tem;
    $('#bloco-cond-teste').hidden = !tem;
    $('#cond-unidade').textContent = c.unidade ? 'em ' + c.unidade : '';
    if (trocouUsuario && precisaValor) f.condicao_valor.value = c.padrao;
    if (trocouUsuario && tem && f.repeticao.value === 'nenhuma') {
      f.repeticao.value = 'diaria';
      f.repeticao.onchange();
    }
    $('#cond-resultado').textContent = '';
  };
  f.condicao_tipo.onchange = () => pintarCondicao(true);
  pintarCondicao(false);
  window.testarCondicao = async () => {
    const box = $('#cond-resultado');
    box.textContent = 'Consultando a previsão…';
    const r = await api('clima_testar', {
      condicao_tipo: f.condicao_tipo.value, condicao_valor: +f.condicao_valor.value || 0,
      condicao_horas: +f.condicao_horas.value || 6, condicao_cidade: f.condicao_cidade.value,
    }).catch(() => ({ ok: false }));
    if (!r.ok) { box.textContent = 'Não consegui consultar a previsão agora.'; return; }
    box.innerHTML = (r.atende ? '✅ <b>Enviaria agora</b> — ' : '⏸️ <b>Não enviaria agora</b> — ') + esc(String(r.resumo || ''));
  };
  f.addEventListener('change', ev => { const o = ev.target.closest('.opcao'); if (o) o.classList.toggle('marcada', ev.target.checked); });
  const erroBox = $('#erro-lembrete');
  const mostrarErro = (msg, campo) => {
    erroBox.hidden = false; erroBox.textContent = msg;
    erroBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (campo) { campo.focus(); campo.style.borderColor = 'var(--vermelho)'; setTimeout(() => campo.style.borderColor = '', 2500); }
  };
  f.addEventListener('input', () => { erroBox.hidden = true; });
  f.onsubmit = async ev => {
    ev.preventDefault();
    const btn = $('#btn-salvar-lembrete');
    const canais = $$('input[name=canais]:checked', f).map(i => i.value);
    const contatos = $$('input[name=contatos]:checked', f).map(i => +i.value);
    if (!f.titulo.value.trim()) return mostrarErro('Escreva um título para o lembrete.', f.titulo);
    if (!f.quando.value) return mostrarErro('Escolha a data e a hora do envio.', f.quando);
    if (!canais.length) return mostrarErro('Marque pelo menos um canal (WhatsApp, Direct ou E-mail).');
    if (!contatos.length) return mostrarErro('Escolha pelo menos um contato para receber.');
    const d = {
      id: id || 0, titulo: f.titulo.value.trim(), mensagem: f.mensagem.value, quando: f.quando.value,
      repeticao: f.repeticao.value, intervalo_dias: +f.intervalo_dias.value || 0, repetir_ate: f.repetir_ate.value || null,
      status: f.status.value, canais, contatos,
      midias: anexosAtuais.map(m => +m.id),
      whatsapp_id: f.whatsapp_id ? +f.whatsapp_id.value : 0,
      antecedencia: +f.antecedencia.value || 0,
      condicao_tipo: f.condicao_tipo.value,
      condicao_valor: +f.condicao_valor.value || 0,
      condicao_horas: +f.condicao_horas.value || 6,
      condicao_cidade: f.condicao_cidade.value.trim(),
      condicao_antirrepete: f.condicao_antirrepete.checked ? 1 : 0,
    };
    btn.disabled = true; btn.textContent = 'Salvando…';
    let r;
    try { r = await api('lembrete_salvar', d); }
    catch (e) { r = { ok: false, erro: 'Sem conexão com o servidor. Tente de novo.' }; }
    btn.disabled = false; btn.textContent = 'Salvar';
    if (!r.ok) return mostrarErro(r.erro || 'Não consegui salvar.');
    fecharModal();
    aviso(id ? 'Lembrete atualizado!' : 'Lembrete criado!', 'ok');
    estado.lembretes = null;
    if (rotaAtual() === 'lembretes') navegar(); else location.hash = '#/lembretes';
  };
};
/* ---------- anexos ---------- */
let anexosAtuais = [];
const ICONE_MIDIA = { image: '🖼️', video: '🎬', audio: '🎵', document: '📄' };
function desenharAnexos() {
  const box = $('#lista-anexos');
  if (!box) return;
  box.innerHTML = anexosAtuais.map(m => `<div class="anexo">
      ${m.tipo === 'image' ? `<img src="${esc(m.url)}" alt="">` : `<span class="anexo-ico">${ICONE_MIDIA[m.tipo] || '📄'}</span>`}
      <div class="anexo-info"><b>${esc(m.nome)}</b><small>${esc(m.tamanho_txt || '')}</small></div>
      <button type="button" class="anexo-x" data-id="${m.id}" title="Remover">×</button>
    </div>`).join('') || '<div class="dica" style="margin:0">Nenhum anexo.</div>';
  $$('.anexo-x', box).forEach(b => b.onclick = async () => {
    if (!confirm('Remover este anexo?')) return;
    await api('midia_excluir', { id: +b.dataset.id });
    anexosAtuais = anexosAtuais.filter(x => +x.id !== +b.dataset.id);
    desenharAnexos();
  });
}
async function enviarAnexos(arquivos, lembreteId) {
  const dica = $('#anexo-dica');
  for (const arq of arquivos) {
    if (arq.size > 16 * 1024 * 1024) { aviso(`"${arq.name}" passa de 16 MB.`, 'ruim'); continue; }
    dica.textContent = `Enviando ${arq.name}…`;
    const fd = new FormData();
    fd.append('arquivo', arq);
    if (lembreteId) fd.append('lembrete_id', lembreteId);
    const r = await fetch('api.php?acao=midia_upload', { method: 'POST', body: fd })
      .then(x => x.json()).catch(() => ({ ok: false, erro: 'Falha no envio' }));
    if (!r.ok) { aviso(r.erro || 'Não consegui enviar o arquivo.', 'ruim'); continue; }
    anexosAtuais.push(r.midia);
    desenharAnexos();
  }
  dica.textContent = 'O texto vai primeiro; cada anexo sai em seguida. No e-mail, arquivos de até 7 MB vão anexados — acima disso, vira link.';
}

/* insere uma variável na posição do cursor da mensagem */
window.inserirVariavel = function (chave) {
  const t = $('#form-lembrete textarea[name=mensagem]');
  if (!t) return;
  const i = t.selectionStart ?? t.value.length, f = t.selectionEnd ?? i;
  t.value = t.value.slice(0, i) + chave + t.value.slice(f);
  t.focus();
  t.selectionStart = t.selectionEnd = i + chave.length;

};
window.abrirVariaveis = async function () {
  const box = $('#painel-vars');
  if (!box) return;
  if (!box.hidden) { box.hidden = true; return; }           // segundo clique fecha
  box.hidden = false;
  box.innerHTML = '<div class="carregando">Carregando variáveis…</div>';
  const r = await api('variaveis', null, 'GET');
  if (!r.ok) { box.innerHTML = '<div class="dica">Não consegui carregar as variáveis.</div>'; return; }
  box.innerHTML = `<div class="previa-topo">Clique para inserir na mensagem — o sistema troca pelos dados reais no envio (exemplo com um contato de verdade).</div>
    ${Object.entries(r.grupos).map(([grupo, itens]) => `
      <h3 class="vars-titulo">${esc(grupo)}</h3>
      <div class="vars">${itens.map(v => `
        <button type="button" class="var" data-chave="${esc(v.chave)}">
          <b>${esc(v.chave)}</b><small>${esc(v.descricao)}</small><i>${esc(v.exemplo || '—')}</i>
        </button>`).join('')}</div>`).join('')}
    <button type="button" class="btn claro peq" style="margin-top:12px" onclick="document.getElementById('painel-vars').hidden=true">Fechar lista</button>`;
  $$('.var', box).forEach(b => b.onclick = () => inserirVariavel(b.dataset.chave));
};
window.verPrevia = async function () {
  const f = $('#form-lembrete'), box = $('#previa-msg');
  const primeiro = $$('input[name=contatos]:checked', f)[0];
  const r = await api('previa', {
    titulo: f.titulo.value, mensagem: f.mensagem.value, quando: f.quando.value,
    repeticao: f.repeticao.value, intervalo_dias: +f.intervalo_dias.value || 0,
    contato_id: primeiro ? +primeiro.value : 0,
  });
  if (!r.ok) return aviso('Não consegui gerar a prévia.', 'ruim');
  box.hidden = false;
  box.innerHTML = `<div class="previa-topo">Como vai chegar para <b>${esc(r.contato || 'o contato')}</b></div>
    <div class="previa-balao"><b>${esc(r.titulo)}</b><br>${esc(r.mensagem).replace(/\n/g, '<br>')}</div>`;
};

window.alternarLembrete = async function (id, atual) {
  const r = await api('lembrete_status', { id, status: atual === 'ativo' ? 'pausado' : 'ativo' });
  if (!r.ok) return aviso(r.erro, 'ruim');
  estado.lembretes = null; navegar();
};
window.duplicarLembrete = async function (id) {
  const r = await api('lembrete_duplicar', { id });
  if (!r.ok) return aviso(r.erro, 'ruim');
  aviso('Cópia criada (pausada) — ajuste a data e ative.', 'ok');
  estado.lembretes = null;
  await navegar();
  editarLembrete(r.id);
};
window.adiarLembrete = function (id) {
  const l = (estado.lembretes || []).find(x => +x.id === +id) || {};
  const atual = l.proxima_em || l.quando || '';
  abrirModal(`<h2>Adiar lembrete <button class="modal-x">×</button></h2>
    <p class="dica">Vale só para o próximo envio: <b>${dataBr(atual)}</b>. A repetição continua igual.</p>
    <div class="opcoes">
      <button class="btn claro" onclick="aplicarAdiar(${id},10)">+ 10 minutos</button>
      <button class="btn claro" onclick="aplicarAdiar(${id},60)">+ 1 hora</button>
      <button class="btn claro" onclick="aplicarAdiar(${id},180)">+ 3 horas</button>
      <button class="btn claro" onclick="aplicarAdiar(${id},1440)">+ 1 dia</button>
      <button class="btn claro" onclick="aplicarAdiar(${id},10080)">+ 1 semana</button>
    </div>
    <label style="margin-top:14px">Ou escolha a data e a hora<input type="datetime-local" id="adiar-para" value="${paraInput(atual)}"></label>
    <div class="modal-acoes"><button class="btn claro modal-x">Cancelar</button>
      <button class="btn azul" onclick="aplicarAdiar(${id},0,document.getElementById('adiar-para').value)">Adiar para essa data</button></div>`);
};
window.aplicarAdiar = async function (id, minutos, para) {
  const r = await api('lembrete_adiar', { id, minutos: minutos || 0, para: para || '' });
  if (!r.ok) return aviso(r.erro, 'ruim');
  fecharModal();
  aviso('Adiado para ' + dataBr(r.proxima_em) + '.', 'ok');
  estado.lembretes = null; navegar();
};
window.excluirLembrete = async function (id) {
  if (!confirm('Excluir este lembrete? Os envios já feitos continuam no histórico.')) return;
  await api('lembrete_excluir', { id });
  aviso('Lembrete excluído.'); estado.lembretes = null; navegar();
};
window.testarLembrete = async function (id) {
  aviso('Enviando teste…');
  const r = await api('lembrete_testar', { id });
  if (!r.ok) return aviso(r.erro, 'ruim');
  const res = r.resultado;
  abrirModal(`<h2>Resultado do teste <button class="modal-x">×</button></h2>
    <p class="dica">${res.enviados} enviado(s), ${res.erros} erro(s).</p>
    <div class="rolar"><table><thead><tr><th>Canal</th><th>Contato</th><th>Situação</th></tr></thead><tbody>
      ${res.itens.map(i => `<tr><td data-r="Canal">${CANAIS[i.canal]?.icone || ''} ${esc(CANAIS[i.canal]?.nome || i.canal)}</td><td data-r="Para">${esc(i.contato)}</td>
        <td data-r="Resultado"><span class="selo ${i.ok ? 'verde' : 'vermelho'}">${i.ok ? 'enviado' : 'erro'}</span> <small style="color:var(--texto2)">${esc(i.detalhe)}</small></td></tr>`).join('')}
    </tbody></table></div><div class="modal-acoes"><button class="btn azul modal-x">Fechar</button></div>`);
};

/* ================= AGENDA DO MÊS ================= */
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
let mesAgenda = null;
async function telaAgenda() {
  if (!mesAgenda) { const h = new Date(); mesAgenda = h.getFullYear() + '-' + String(h.getMonth() + 1).padStart(2, '0'); }
  const r = await api('agenda_mes&mes=' + mesAgenda, null, 'GET');
  const itens = r.itens || [];
  const [ano, mes] = mesAgenda.split('-').map(Number);
  const primeiro = new Date(ano, mes - 1, 1), dias = new Date(ano, mes, 0).getDate();
  const porDia = {};
  itens.forEach(i => { const d = +i.quando.slice(8, 10); (porDia[d] = porDia[d] || []).push(i); });
  const hoje = new Date(), ehMesAtual = hoje.getFullYear() === ano && hoje.getMonth() + 1 === mes;
  let celulas = '';
  for (let i = 0; i < primeiro.getDay(); i++) celulas += '<div class="dia fora"></div>';
  for (let d = 1; d <= dias; d++) {
    const lista = porDia[d] || [];
    celulas += `<div class="dia ${ehMesAtual && hoje.getDate() === d ? 'hoje' : ''} ${lista.length ? 'cheio' : ''}">
      <b>${d}</b>
      ${lista.slice(0, 4).map(i => `<span class="ev ${i.status === 'pausado' ? 'pausado' : ''}" title="${esc(i.titulo)} — ${esc(i.contatos || '')}"
          onclick="editarLembrete(${i.id})">${i.quando.slice(11, 16)} ${esc(i.titulo)}</span>`).join('')}
      ${lista.length > 4 ? `<span class="ev mais">+${lista.length - 4} outro(s)</span>` : ''}
    </div>`;
  }
  $('#pagina').innerHTML = `<div class="card">
    <div class="agenda-topo">
      <button class="btn claro peq" onclick="mudarMes(-1)">‹ Mês anterior</button>
      <h2 style="margin:0">${MESES[mes - 1]} de ${ano}</h2>
      <button class="btn claro peq" onclick="mudarMes(1)">Próximo mês ›</button>
    </div>
    <p class="dica">Todas as ocorrências previstas no mês, já considerando a repetição. Clique em uma para editar o lembrete.</p>
    <div class="calendario">
      ${['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'].map(d => `<div class="cab-dia">${d}</div>`).join('')}
      ${celulas}
    </div>
    ${itens.length ? '' : '<div class="vazio">Nenhum lembrete previsto neste mês.</div>'}
  </div>`;
}
window.mudarMes = function (n) {
  const [a, m] = mesAgenda.split('-').map(Number);
  const d = new Date(a, m - 1 + n, 1);
  mesAgenda = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  telaAgenda();
};

/* ================= CONTATOS ================= */
async function telaContatos() {
  const r = await api('contatos', null, 'GET');
  estado.contatos = r.itens || [];
  const linhas = estado.contatos.map(c => `<tr>
      <td data-r="Contato"><b>${esc(c.nome)}</b>${c.observacao ? `<br><small style="color:var(--texto2)">${esc(c.observacao)}</small>` : ''}</td>
      <td data-r="WhatsApp">${c.whatsapp ? '📱 ' + esc(c.whatsapp) : '<span style="color:var(--texto3)">—</span>'}</td>
      <td data-r="E-mail">${c.email ? '✉️ ' + esc(c.email) : '<span style="color:var(--texto3)">—</span>'}</td>
      <td data-r="Instagram">${c.ig_usuario ? '📷 @' + esc(c.ig_usuario) : '<span style="color:var(--texto3)">—</span>'}</td>
      <td data-r="Situação"><span class="selo ${+c.ativo ? 'verde' : ''}">${+c.ativo ? 'ativo' : 'inativo'}</span></td>
      <td class="acoes"><button class="btn claro peq" onclick="editarContato(${c.id})">Editar</button>
        <button class="btn perigo peq" onclick="excluirContato(${c.id})">Excluir</button></td></tr>`).join('');
  $('#pagina').innerHTML = `<div class="card">
      <h2>Contatos</h2><p class="dica">Quem vai receber os lembretes. Preencha só os canais que você pretende usar para cada pessoa.</p>
      <div class="opcoes" style="margin-bottom:16px">
        <button class="btn azul" onclick="editarContato(null)">+ Novo contato</button>
        <button class="btn claro" onclick="importarContatos()">📥 Importar CSV</button>
      </div>
      ${estado.contatos.length ? `<div class="rolar"><table><thead><tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th>Instagram</th><th>Situação</th><th></th></tr></thead><tbody>${linhas}</tbody></table></div>`
        : `<div class="vazio"><b>Nenhum contato</b>Cadastre a primeira pessoa para começar.</div>`}
    </div>`;
}
window.editarContato = async function (id) {
  const c = id ? estado.contatos.find(x => +x.id === +id) : { nome: '', whatsapp: '', email: '', ig_usuario: '', ig_remetente_id: '', ig_cliente_id: '', observacao: '', ativo: 1, alerta_chuva: 1 };
  const rc = await api('ig_conversas', null, 'GET');
  const conversas = rc.itens || [];
  abrirModal(`<h2>${id ? 'Editar contato' : 'Novo contato'} <button class="modal-x">×</button></h2>
    <form id="form-contato">
      <div class="erro" id="erro-contato" hidden></div>
      <label>Nome<input name="nome" value="${esc(c.nome)}"></label>
      <div class="form-grade">
        <label>WhatsApp<input name="whatsapp" value="${esc(c.whatsapp || '')}" placeholder="(82) 98871-7072"></label>
        <label>E-mail<input name="email" type="email" value="${esc(c.email || '')}" placeholder="pessoa@email.com"></label>
      </div>
      <label>Instagram (@ do destinatário)
        <input name="ig_usuario" list="lista-ig" value="${esc(c.ig_usuario ? '@' + String(c.ig_usuario).replace(/^@/, '') : '')}" placeholder="@usuario — escolha da lista ou digite">
      </label>
      <datalist id="lista-ig">${conversas.map(v => `<option value="@${esc(v.nome || v.remetente_id)}">`).join('')}</datalist>
      <input type="hidden" name="ig_remetente_id" value="${esc(c.ig_remetente_id || '')}">
      <p class="dica" id="ig-aviso">${conversas.length
        ? 'Escolha uma das conversas já existentes ou digite o @ da pessoa. A Meta só entrega Direct para quem já falou com a conta, até 24h depois da última mensagem dela.'
        : 'Nenhuma conversa encontrada — escolha a conta do Instagram na aba Conexões.'}</p>
      <div class="form-grade">
        <label>Cidade (para a previsão do tempo)<input name="cidade" value="${esc(c.cidade || '')}" placeholder="deixe vazio para usar a cidade padrão"></label>
        <label>Observação<input name="observacao" value="${esc(c.observacao || '')}"></label>
      </div>
      <label class="opcao" style="display:inline-flex"><input type="checkbox" name="ativo" ${+c.ativo ? 'checked' : ''}> Contato ativo</label>
      <label class="opcao" style="display:inline-flex"><input type="checkbox" name="alerta_chuva" ${(c.alerta_chuva === undefined || +c.alerta_chuva) ? 'checked' : ''}> Receber o alerta automático de chuva</label>
      <div class="modal-acoes"><button type="button" class="btn claro modal-x">Cancelar</button><button class="btn azul" type="submit" id="btn-salvar-contato">Salvar</button></div>
    </form>`);
  const fc = $('#form-contato');
  const mapa = {};
  conversas.forEach(v => { mapa['@' + String(v.nome || v.remetente_id).toLowerCase()] = v.remetente_id; });
  fc.ig_usuario.addEventListener('change', async () => {
    const v = fc.ig_usuario.value.trim();
    const av = $('#ig-aviso');
    if (!v) { fc.ig_remetente_id.value = ''; return; }
    const direto = mapa['@' + v.replace(/^@/, '').toLowerCase()];
    if (direto) { fc.ig_remetente_id.value = direto; av.innerHTML = '<span class="selo verde">conversa encontrada</span> Direct pronto para este contato.'; return; }
    fc.ig_remetente_id.value = '';
    const r = await api('ig_procurar', { usuario: v });
    if (r.ok) {
      fc.ig_remetente_id.value = r.conversa.remetente_id;
      av.innerHTML = `<span class="selo verde">conversa encontrada</span> Vinculado a <b>@${esc(r.conversa.nome || '')}</b>${r.conversa.conta ? ` — será enviado pela conta <b>@${esc(r.conversa.conta)}</b>` : ''}.`;
    } else {
      av.innerHTML = '<span class="selo amarelo">sem conversa ainda</span> O @ fica salvo, mas o Direct só sai depois que a pessoa mandar uma mensagem para a conta (regra da Meta). O sistema faz o vínculo sozinho quando isso acontecer.';
    }
  });
  fc.onsubmit = async ev => {
    ev.preventDefault();
    const f = ev.target, cx = $('#erro-contato'), btn = $('#btn-salvar-contato');
    if (!f.nome.value.trim()) { cx.hidden = false; cx.textContent = 'Escreva o nome do contato.'; f.nome.focus(); return; }
    if (!f.whatsapp.value.trim() && !f.email.value.trim() && !f.ig_usuario.value.trim()) {
      cx.hidden = false; cx.textContent = 'Informe pelo menos um canal: WhatsApp, e-mail ou @ do Instagram.'; return;
    }
    cx.hidden = true; btn.disabled = true; btn.textContent = 'Salvando…';
    const r = await api('contato_salvar', {
      id: id || 0, nome: f.nome.value, whatsapp: f.whatsapp.value, email: f.email.value,
      ig_remetente_id: f.ig_remetente_id.value, ig_usuario: f.ig_usuario.value.trim().replace(/^@/, ''),
      observacao: f.observacao.value, cidade: f.cidade.value, ativo: f.ativo.checked ? 1 : 0,
      alerta_chuva: f.alerta_chuva.checked ? 1 : 0,
    }).catch(() => ({ ok: false, erro: 'Sem conexão com o servidor.' }));
    btn.disabled = false; btn.textContent = 'Salvar';
    if (!r.ok) { cx.hidden = false; cx.textContent = r.erro; return; }
    fecharModal(); aviso('Contato salvo!', 'ok');
    if (rotaAtual() === 'contatos') navegar(); else location.hash = '#/contatos';
  };
};
window.importarContatos = function () {
  abrirModal(`<h2>Importar contatos (CSV) <button class="modal-x">×</button></h2>
    <p class="dica">O arquivo precisa ter uma linha de cabeçalho com <b>nome</b> e, se quiser, <b>whatsapp</b>, <b>email</b>, <b>instagram</b>, <b>cidade</b> e <b>observacao</b>.
    Separador , ou ;. Quem já existe (mesmo WhatsApp ou e-mail) é atualizado em vez de duplicado.</p>
    <label class="botao-anexo" for="arq-csv">📎 Escolher arquivo CSV<input type="file" id="arq-csv" accept=".csv,text/csv,text/plain" hidden></label>
    <label>Ou cole aqui o conteúdo<textarea id="csv-texto" rows="8" placeholder="nome;whatsapp;email&#10;Maria Silva;82988717072;maria@email.com"></textarea></label>
    <div class="erro" id="erro-csv" hidden></div>
    <div class="modal-acoes"><button class="btn claro modal-x">Cancelar</button><button class="btn azul" id="btn-importar">Importar</button></div>`);
  $('#arq-csv').onchange = ev => {
    const a = ev.target.files[0]; if (!a) return;
    const fr = new FileReader();
    fr.onload = () => { $('#csv-texto').value = fr.result; aviso('Arquivo lido: ' + a.name); };
    fr.readAsText(a, 'utf-8');
  };
  $('#btn-importar').onclick = async () => {
    const btn = $('#btn-importar'), erro = $('#erro-csv');
    const csv = $('#csv-texto').value;
    if (!csv.trim()) { erro.hidden = false; erro.textContent = 'Escolha um arquivo ou cole o conteúdo.'; return; }
    btn.disabled = true; btn.textContent = 'Importando…';
    const r = await api('contatos_importar', { csv });
    btn.disabled = false; btn.textContent = 'Importar';
    if (!r.ok) { erro.hidden = false; erro.textContent = r.erro; return; }
    fecharModal();
    aviso(`${r.novos} novo(s), ${r.atualizados} atualizado(s)${r.ignorados ? ', ' + r.ignorados + ' ignorado(s)' : ''}.`, 'ok');
    navegar();
  };
};
window.excluirContato = async function (id) {
  if (!confirm('Excluir este contato? Ele sai também dos lembretes em que estava.')) return;
  await api('contato_excluir', { id }); aviso('Contato excluído.'); navegar();
};

/* ================= ENVIOS ================= */
function linhaEnvio(e, comAcoes) {
  return `<tr><td data-r="Quando">${dataBr(e.criado_em)}</td>
    <td data-r="Lembrete">${esc(e.titulo || (e.teste == 1 ? 'Teste de canal' : '—'))}${!e.lembrete_id && /chuva/i.test(e.titulo || '') ? ' <span class="selo azul">🌧️ automático</span>' : ''}${+e.teste ? ' <span class="selo">teste</span>' : ''}${+e.previo ? ' <span class="selo amarelo">⏰ aviso antes</span>' : ''}</td>
    <td data-r="Para">${esc(e.contato || e.destino || '—')}${e.destino && e.contato ? `<br><small style="color:var(--texto2)">${esc(e.destino)}</small>` : ''}</td>
    <td class="col-msg" data-r="Mensagem">${e.mensagem ? `<span class="msg-previa">${esc(String(e.mensagem).slice(0, 80))}${String(e.mensagem).length > 80 ? '…' : ''}</span>
      <button class="btn claro peq" onclick="verMensagem(${e.id})">Ver</button>` : '<small style="color:var(--texto2)">—</small>'}</td>
    <td data-r="Canal">${CANAIS[e.canal]?.icone || ''} ${esc(CANAIS[e.canal]?.nome || e.canal)}</td>
    <td data-r="Situação"><span class="selo ${e.status === 'enviado' ? 'verde' : 'vermelho'}">${e.status}</span>
      ${+e.tentativas > 1 ? ` <small style="color:var(--texto2)">${e.tentativas} tentativas</small>` : ''}
      ${e.status === 'erro' ? `<br><small style="color:var(--texto2)">${esc(e.detalhe || '')}</small>` : ''}</td>
    ${comAcoes ? `<td class="acoes">${e.status === 'erro' ? `<button class="btn claro peq" onclick="reenviarEnvio(${e.id},this)">Reenviar</button>` : ''}</td>` : ''}</tr>`;
}
let filtrosEnvios = { canal: '', status: '', dias: 0, tipo: '', busca: '' };
async function telaEnvios() {
  const q = new URLSearchParams({ limite: 300, ...filtrosEnvios }).toString();
  const r = await api('envios&' + q, null, 'GET');
  const itens = r.itens || [];
  estado.envios = itens;
  const sel = (id, opcoes, valor) => `<select id="${id}">${opcoes.map(([v, t]) => `<option value="${v}" ${String(valor) === String(v) ? 'selected' : ''}>${t}</option>`).join('')}</select>`;
  $('#pagina').innerHTML = `<div class="card"><h2>Histórico de envios</h2>
    <p class="dica">Até 300 envios por consulta. Limpar o histórico não apaga lembretes nem contatos.</p>
    <div class="filtros">
      <input id="f-busca" type="search" placeholder="🔎 Buscar por lembrete, contato ou texto…" value="${esc(filtrosEnvios.busca)}">
      ${sel('f-canal', [['', 'Todos os canais'], ['whatsapp', '📱 WhatsApp'], ['direct', '📷 Direct'], ['email', '✉️ E-mail']], filtrosEnvios.canal)}
      ${sel('f-status', [['', 'Enviados e erros'], ['enviado', '✅ Só enviados'], ['erro', '⚠️ Só erros']], filtrosEnvios.status)}
      ${sel('f-dias', [[0, 'Qualquer data'], [1, 'Últimas 24 h'], [7, 'Últimos 7 dias'], [30, 'Últimos 30 dias'], [90, 'Últimos 90 dias']], filtrosEnvios.dias)}
      ${sel('f-tipo', [['', 'Reais e testes'], ['reais', 'Só envios reais'], ['testes', 'Só testes']], filtrosEnvios.tipo)}
      <span class="filtro-conta">${itens.length} registro(s)</span>
      <a class="btn claro peq" href="api.php?acao=envios_csv&${q}">⬇️ Baixar CSV</a>
    </div>
    <div class="opcoes">
      <button class="btn claro peq" onclick="limparEnvios('testes')">🧪 Apagar testes</button>
      <button class="btn claro peq" onclick="limparEnvios('erros')">⚠️ Apagar erros</button>
      <button class="btn claro peq" onclick="limparEnvios('7dias')">🗓️ Apagar com mais de 7 dias</button>
      <button class="btn claro peq" onclick="limparEnvios('30dias')">🗓️ Apagar com mais de 30 dias</button>
      <button class="btn perigo peq" onclick="limparEnvios('tudo')">🗑️ Apagar tudo</button>
      <button class="btn claro peq" onclick="navegar()">↻ Atualizar</button>
    </div>
    ${itens.length ? `<div class="rolar"><table><thead><tr><th>Quando</th><th>Lembrete</th><th>Para</th><th>Mensagem enviada</th><th>Canal</th><th>Situação</th><th></th></tr></thead><tbody>${itens.map(e => linhaEnvio(e, true)).join('')}</tbody></table></div>`
      : '<div class="vazio">Nenhum envio com esses filtros.</div>'}</div>`;
  const aplicar = () => {
    filtrosEnvios = { canal: $('#f-canal').value, status: $('#f-status').value, dias: +$('#f-dias').value,
                      tipo: $('#f-tipo').value, busca: $('#f-busca').value.trim() };
    telaEnvios();
  };
  ['#f-canal', '#f-status', '#f-dias', '#f-tipo'].forEach(x => $(x).onchange = aplicar);
  let t; $('#f-busca').oninput = () => { clearTimeout(t); t = setTimeout(aplicar, 400); };
}
/* Mostra exatamente o texto que saiu, com as variáveis já trocadas. */
window.verMensagem = function (id) {
  const e = (estado.envios || []).find(x => +x.id === +id);
  if (!e) return;
  const canal = CANAIS[e.canal] || {};
  abrirModal(`<h2>Mensagem enviada <button class="modal-x">×</button></h2>
    <p class="dica">${esc(dataBr(e.criado_em))} · ${esc(canal.icone || '')} ${esc(canal.nome || e.canal)}
      · para <b>${esc(e.contato || e.destino || '—')}</b>${e.destino && e.contato ? ' (' + esc(e.destino) + ')' : ''}
      ${+e.teste ? ' · <span class="selo amarelo">teste</span>' : ''}${+e.previo ? ' · <span class="selo amarelo">aviso antes</span>' : ''}</p>
    <div class="previa" style="white-space:pre-wrap">${esc(e.mensagem || '')}</div>
    ${e.status === 'erro' ? `<div class="erro">Não foi entregue: ${esc(e.detalhe || '')}</div>` : ''}
    <div class="modal-acoes">
      <button type="button" class="btn claro" onclick="copiarMensagem(${e.id},this)">Copiar texto</button>
      <button type="button" class="btn azul modal-x">Fechar</button>
    </div>`);
};
window.copiarMensagem = async function (id, botao) {
  const e = (estado.envios || []).find(x => +x.id === +id);
  if (!e) return;
  try { await navigator.clipboard.writeText(e.mensagem || ''); botao.textContent = 'Copiado!'; }
  catch (err) { botao.textContent = 'Não consegui copiar'; }
  setTimeout(() => botao.textContent = 'Copiar texto', 2000);
};
window.reenviarEnvio = async function (id, botao) {
  botao.disabled = true; botao.textContent = 'Enviando…';
  const r = await api('envio_reenviar', { id });
  botao.disabled = false; botao.textContent = 'Reenviar';
  aviso(r.ok ? 'Reenviado com sucesso!' : ('Não saiu: ' + (r.detalhe || r.erro || 'erro')), r.ok ? 'ok' : 'ruim');
  if (r.ok) telaEnvios();
};
window.limparEnvios = async function (tipo) {
  const texto = { tudo: 'TODO o histórico de envios', testes: 'os envios de teste', erros: 'os envios com erro', '7dias': 'os envios com mais de 7 dias', '30dias': 'os envios com mais de 30 dias' }[tipo];
  if (!confirm('Apagar ' + texto + '? Não dá para desfazer.')) return;
  const r = await api('envios_limpar', { tipo });
  if (!r.ok) return aviso(r.erro, 'ruim');
  aviso(r.removidos + ' registro(s) apagado(s).', 'ok');
  navegar();
};

/* ================= CONEXÕES (diagnóstico) ================= */
const SITUACOES = {
  open: ['verde', 'Conectado'], connected: ['verde', 'Conectado'], valido: ['verde', 'Funcionando'],
  connecting: ['amarelo', 'Aguardando leitura do QR'], close: ['vermelho', 'Desconectado'],
  expirando: ['amarelo', 'Vence em breve'], invalido: ['vermelho', 'Precisa reconectar'],
  sem_token: ['vermelho', 'Sem token'], sem_conta: ['', 'Não escolhida'],
  nao_configurado: ['', 'Não configurado'], login: ['vermelho', 'Login recusado'],
  sem_conexao: ['vermelho', 'Servidor fora do ar'], tls: ['vermelho', 'Falha no TLS'],
  recusado: ['vermelho', 'Recusado'], inexistente: ['vermelho', 'Não criada'],
  nao_verificado: ['', 'Verificando…'],
};
const ICONE_CANAL = { whatsapp: '📱', direct: '📷', email: '✉️' };

function cartaoConexao(c) {
  const [cor, rotulo] = SITUACOES[c.situacao] || ['', c.situacao || '—'];
  const botao = { whatsapp: `<button class="btn azul peq" onclick="conectarWa()">Reconectar (QR code)</button>`,
                  direct: `<a class="btn claro peq" href="https://alequizao.com/agendamentos/cliente_instagram.php" target="_blank" rel="noopener">Reconectar conta</a>`,
                  email: `<button class="btn claro peq" onclick="location.hash='#/config'">Ajustar SMTP</button>` }[c.canal];
  const validade = c.dias_restantes != null
    ? `<div class="linha-con"><span>Validade</span><b>${c.dias_restantes > 0 ? `vence em ${c.dias_restantes} dia(s) — ${dataBr(c.expira_em)}` : 'vencido'}</b></div>` : '';
  return `<div class="conexao ${cor || 'neutra'}">
    <div class="conexao-topo">
      <div class="conexao-nome">${ICONE_CANAL[c.canal]} <b>${esc(c.nome)}</b></div>
      <span class="selo ${cor}">${esc(rotulo)}</span>
    </div>
    ${c.conta ? `<div class="linha-con"><span>Conta</span><b>${esc(c.conta)}${c.perfil ? ' · ' + esc(c.perfil) : ''}${c.servidor ? ' · ' + esc(c.servidor) : ''}</b></div>` : ''}
    ${validade}
    <div class="linha-con"><span>Último envio</span><b>${c.ultimo_ok ? dataBr(c.ultimo_ok) : 'nenhum ainda'}</b></div>
    ${c.ultimo_erro ? `<div class="linha-con"><span>Última falha</span><b style="color:var(--vermelho)">${dataBr(c.ultimo_erro)}</b></div>
      <div class="con-msg" style="color:var(--vermelho)">${esc(String(c.ultimo_erro_msg || '').slice(0, 160))}</div>` : ''}
    <div class="con-msg">${esc(c.msg || '')}</div>
    ${c.acao ? `<div class="con-acao">⚠️ ${esc(c.acao)}</div>` : ''}
    <div class="conexao-acoes">${botao}<button class="btn claro peq" onclick="testarCanal('${c.canal}')">Enviar teste</button></div>
  </div>`;
}

function linhasDiag(c) {
  const linhas = [];
  if (c.conta) linhas.push(['Conta', esc(c.conta) + (c.perfil ? ' · ' + esc(c.perfil) : '') + (c.servidor ? ' · ' + esc(c.servidor) : '')]);
  if (c.dias_restantes != null) linhas.push(['Validade do token', c.dias_restantes > 0 ? `vence em ${c.dias_restantes} dia(s) — ${dataBr(c.expira_em)}` : 'vencido']);
  linhas.push(['Último envio', c.ultimo_ok ? dataBr(c.ultimo_ok) : 'nenhum ainda']);
  if (c.ultimo_erro) linhas.push(['Última falha', `<span style="color:var(--vermelho)">${dataBr(c.ultimo_erro)} — ${esc(String(c.ultimo_erro_msg || '').slice(0, 140))}</span>`]);
  return `${linhas.map(([r, v]) => `<div class="linha-con"><span>${r}</span><b>${v}</b></div>`).join('')}
    <div class="con-msg">${esc(c.msg || '')}</div>
    ${c.acao ? `<div class="con-acao">⚠️ ${esc(c.acao)}</div>` : ''}`;
}

/* Sem destino = tela de Conexões (preenche os selos de cada card).
   Com destino  = Painel (desenha os cartões de resumo). */
async function carregarConexoes(destino) {
  const resumoEl = destino ? $(destino) : $('#resumo-con');
  if (!resumoEl) return;
  if (destino) resumoEl.innerHTML = '<div class="carregando">Verificando as conexões…</div>';
  const r = await api('conexoes', null, 'GET');
  if (!r.ok) { resumoEl.innerHTML = '<div class="dica">Não consegui verificar agora.</div>'; return; }
  const problemas = r.canais.filter(c => c.ok === false).length;
  const textoResumo = `${problemas ? `⚠️ <b>${problemas} conexão(ões) precisam de atenção</b>` : '✅ <b>As três conexões estão funcionando</b>'}
      <small>verificado às ${dataBr(r.verificado_em).slice(11)}</small>
      <button class="btn claro peq" onclick="carregarConexoes(${destino ? `'${destino}'` : ''})">↻ Verificar de novo</button>`;

  if (destino) {
    resumoEl.innerHTML = `<div class="resumo-con ${problemas ? 'alerta' : 'bom'}">${textoResumo}</div>
      <div class="conexoes">${r.canais.map(cartaoConexao).join('')}</div>`;
  } else {
    resumoEl.className = 'resumo-con ' + (problemas ? 'alerta' : 'bom');
    resumoEl.innerHTML = textoResumo;
    r.canais.forEach(c => {
      const [cor, rotulo] = SITUACOES[c.situacao] || ['', c.situacao || '—'];
      const selo = $('#selo-c-' + c.canal), diag = $('#diag-' + c.canal), card = $('#card-' + c.canal);
      if (selo) { selo.className = 'selo ' + cor; selo.textContent = rotulo; }
      if (diag) diag.innerHTML = linhasDiag(c);
      if (card) card.className = 'card canal ' + (cor || '');
    });
  }
  const wa = r.canais.find(c => c.canal === 'whatsapp');
  if (wa) atualizarSeloWa({ ok: wa.ok });
}

/* ================= CONFIGURAÇÕES ================= */
async function telaConfig() {
  const r = await api('config', null, 'GET');
  estado.config = r.config || {};
  const c = estado.config;
  $('#pagina').innerHTML = `
    <div id="resumo-con" class="resumo-con neutro">Verificando as conexões…</div>

    <div class="card canal" id="card-whatsapp">
      <div class="canal-topo">
        <h2>📱 WhatsApp</h2>
        <span class="selo" id="selo-c-whatsapp">verificando…</span>
      </div>
      <div class="canal-diag" id="diag-whatsapp"><div class="con-msg">Verificando a sessão…</div></div>
      <p class="dica">Envio pela Evolution API. Conecte quantos números quiser — cada lembrete escolhe por qual deles sai, e o marcado como padrão vale quando nada for escolhido.</p>
      <div id="lista-whats"></div>
      <div class="canal-acoes">
        <button class="btn claro" onclick="editarWhats(null)">+ Adicionar número</button>
        <button class="btn claro" onclick="testarCanal('whatsapp')">Enviar teste</button>
      </div>
    </div>

    <div class="card canal" id="card-direct">
      <div class="canal-topo">
        <h2>📷 Direct do Instagram</h2>
        <span class="selo" id="selo-c-direct">verificando…</span>
      </div>
      <div class="canal-diag" id="diag-direct"><div class="con-msg">Verificando o token na Meta…</div></div>
      <p class="dica">Usa as contas conectadas no Agenda Social. A Meta só entrega Direct para quem mandou mensagem nas últimas 24 horas — e o token é renovado sozinho duas vezes por dia.</p>
      <label>Conta que envia<select id="ig_cliente_id">
        <option value="">— nenhuma —</option>
        ${(r.contas_ig || []).map(x => `<option value="${x.id}" ${String(c.ig_cliente_id) === String(x.id) ? 'selected' : ''}>@${esc(x.ig_username || x.nome)}${+x.tem_token ? '' : ' (sem token)'}</option>`).join('')}
      </select></label>
      <div class="canal-acoes">
        <a class="btn claro" href="https://alequizao.com/agendamentos/cliente_instagram.php" target="_blank" rel="noopener">Reconectar conta</a>
        <button class="btn claro" onclick="testarCanal('direct')">Enviar teste</button>
      </div>
    </div>

    <div class="card canal" id="card-email">
      <div class="canal-topo">
        <h2>✉️ E-mail (SMTP)</h2>
        <span class="selo" id="selo-c-email">verificando…</span>
      </div>
      <div class="canal-diag" id="diag-email"><div class="con-msg">Testando o login no servidor de e-mail…</div></div>
      <p class="dica">Ex.: Gmail → <b>smtp.gmail.com</b>, porta <b>587</b>, segurança <b>TLS</b> e uma <b>senha de app</b> (não a senha normal da conta).</p>
      <div class="form-grade">
        <label>Servidor<input id="smtp_host" value="${esc(c.smtp_host || '')}" placeholder="smtp.gmail.com"></label>
        <label>Porta<input id="smtp_porta" value="${esc(c.smtp_porta || '587')}"></label>
        <label>Segurança<select id="smtp_seguranca">
          ${['tls', 'ssl', 'nenhuma'].map(s => `<option value="${s}" ${c.smtp_seguranca === s ? 'selected' : ''}>${s === 'tls' ? 'TLS (587)' : s === 'ssl' ? 'SSL (465)' : 'Nenhuma'}</option>`).join('')}
        </select></label>
        <label>Usuário<input id="smtp_usuario" value="${esc(c.smtp_usuario || '')}" autocomplete="off"></label>
        <label>Senha<input id="smtp_senha" type="password" value="${esc(c.smtp_senha || '')}" autocomplete="new-password"></label>
        <label>Remetente (De:)<input id="smtp_de" value="${esc(c.smtp_de || '')}" placeholder="voce@gmail.com"></label>
        <label>Nome do remetente<input id="smtp_nome" value="${esc(c.smtp_nome || 'Lembretes')}"></label>
        <label class="largo">Assinatura dos e-mails<textarea id="assinatura" placeholder="Ex.: Equipe Alequizão · (82) 98871-7072">${esc(c.assinatura || '')}</textarea></label>
      </div>
      <div class="canal-acoes"><button class="btn claro" onclick="testarCanal('email')">Enviar teste</button></div>
    </div>

    <div class="card">
      <h2>🌤️ Previsão do tempo</h2>
      <p class="dica">Alimenta as variáveis <b>{clima}</b>, <b>{temperatura}</b>, <b>{clima_amanha}</b>… Cada contato pode ter a cidade dele; sem isso vale esta. Fonte: Open-Meteo (grátis, sem chave).</p>
      <div class="form-grade">
        <label>Cidade padrão<input id="clima_cidade" value="${esc(c.clima_cidade || 'Maceió')}" placeholder="Maceió"></label>
      </div>
      <div id="clima-agora" class="dica">—</div>
      <div class="canal-acoes">
        <button class="btn claro" onclick="verClima()">Ver previsão agora</button>
        <a class="btn claro" href="clima.php?cidade=Maceio&dias=3" target="_blank" rel="noopener">Abrir a API</a>
      </div>
    </div>

    <div class="card">
      <h2>⏱️ Motor de envio (cron)</h2>
      <div id="cron-info" class="dica">Carregando…</div>
      <div class="form-grade">
        <label>Verificar lembretes a cada
          <select id="cron_intervalo">${[1, 2, 5, 10, 15, 30, 60].map(m => `<option value="${m}">${m === 60 ? '1 hora' : m + ' minuto' + (m > 1 ? 's' : '')}</option>`).join('')}</select></label>
      </div>
      <p class="dica">Quanto menor o intervalo, mais no horário exato o lembrete sai. Com 15 minutos, um lembrete das 10:00 pode sair até 10:15.</p>
      <div class="canal-acoes">
        <button class="btn azul" onclick="salvarCron()">Salvar intervalo</button>
        <button class="btn claro" onclick="rodarAgora()">▶ Rodar agora</button>
      </div>
    </div>

    <div class="card">
      <h2>🔐 Minha conta</h2>
      <p class="dica">O e-mail é para onde vai o link de recuperação, se você esquecer a senha. Sem e-mail cadastrado, a recuperação não funciona.</p>
      <div class="form-grade">
        <label>Meu nome<input id="perfil_nome" value=""></label>
        <label>Meu e-mail<input id="perfil_email" type="email" value="" placeholder="voce@email.com"></label>
      </div>
      <div class="canal-acoes">
        <button class="btn azul" onclick="salvarPerfil()">Salvar meus dados</button>
        <button class="btn claro" onclick="trocarSenha()">Trocar senha</button>
      </div>
    </div>

    <div class="card">
      <h2>🛟 Avisos ao desenvolvedor</h2>
      <p class="dica">Quando um envio falhar (ou a API der erro), o sistema manda um e-mail com o que aconteceu. As falhas são agrupadas para não virar spam.</p>
      <div class="form-grade">
        <label>E-mail do desenvolvedor<input id="email_dev" value="${esc(c.email_dev || 'alequizao.dev@gmail.com')}" placeholder="alequizao.dev@gmail.com"></label>
        <label>Agrupar avisos a cada<select id="avisar_dev_minutos">${[5, 15, 30, 60, 180, 360].map(m => `<option value="${m}" ${String(c.avisar_dev_minutos || 30) === String(m) ? 'selected' : ''}>${m >= 60 ? (m / 60) + ' hora(s)' : m + ' minutos'}</option>`).join('')}</select></label>
      </div>
      <label class="opcao" style="display:inline-flex"><input type="checkbox" id="avisar_dev" ${String(c.avisar_dev ?? '1') === '1' ? 'checked' : ''}> Avisar por e-mail quando houver falha</label>
      <div class="canal-acoes"><button class="btn claro" onclick="testarAvisoDev()">Enviar aviso de teste</button></div>
    </div>

    <div class="card salvar-tudo">
      <div>
        <b>Salvar as configurações</b>
        <p class="dica" style="margin:4px 0 0">Guarda a conta do Instagram, os dados do e-mail, a assinatura e a cidade da previsão. Os números de WhatsApp e o intervalo do cron são salvos nos próprios botões.</p>
      </div>
      <button class="btn azul grande" style="max-width:240px" onclick="salvarConfig()">Salvar configurações</button>
    </div>`;
  carregarCron();
  carregarWhats();
  carregarConexoes();
  verClima();
  carregarPerfil();
}
async function carregarPerfil() {
  const r = await api('perfil', null, 'GET');
  if (!r.ok) return;
  const n = $('#perfil_nome'), em = $('#perfil_email');
  if (n) n.value = r.perfil.nome || '';
  if (em) em.value = r.perfil.email || '';
}
window.salvarPerfil = async function () {
  const r = await api('perfil_salvar', { nome: $('#perfil_nome').value, email: $('#perfil_email').value });
  aviso(r.ok ? 'Dados salvos!' : r.erro, r.ok ? 'ok' : 'ruim');
  if (r.ok) $('#nome-usuario').textContent = $('#perfil_nome').value;
};


/* ---------- motor de envio (cron) ---------- */
async function carregarCron() {
  const r = await api('cron_estado', null, 'GET');
  if (!r.ok) return;
  const sel = $('#cron_intervalo');
  if (sel) sel.value = r.intervalo;
  const info = $('#cron-info');
  if (!info) return;
  const aplicado = r.aplicado === null ? 'não encontrado' : (r.aplicado === 60 ? '1 hora' : r.aplicado + ' min');
  info.innerHTML = `<span class="selo ${r.sincronizado ? 'verde' : 'amarelo'}">${r.sincronizado ? 'ativo' : 'aguardando aplicar'}</span>
    Rodando a cada <b>${esc(aplicado)}</b> no servidor · última verificação: <b>${dataBr(r.ultima_batida)}</b> · último disparo: <b>${dataBr(r.ultimo_disparo)}</b>
    ${r.sincronizado ? '' : '<br>O novo intervalo é aplicado pelo servidor em até 5 minutos.'}`;
}
window.salvarCron = async function () {
  const r = await api('cron_salvar', { intervalo: +$('#cron_intervalo').value });
  aviso(r.ok ? 'Intervalo salvo — o servidor aplica em até 5 minutos.' : r.erro, r.ok ? 'ok' : 'ruim');
  carregarCron();
};
window.rodarAgora = async function () {
  aviso('Verificando lembretes…');
  const r = await api('cron_rodar');
  aviso(r.ok ? `Verificação feita: ${r.disparados} lembrete(s) disparado(s).` : r.erro, r.ok ? 'ok' : 'ruim');
  carregarCron();
};

/* ---------- números de WhatsApp ---------- */
async function carregarWhats() {
  const box = $('#lista-whats');
  if (!box) return;
  const r = await api('wa_lista', null, 'GET');
  if (!r.ok) return;
  estado.whats = r.itens;
  box.innerHTML = r.itens.map(w => `<div class="whats-linha">
      <div class="whats-info">
        <b>${esc(w.nome)}</b> ${+w.padrao ? '<span class="selo azul">padrão</span>' : ''} ${+w.ativo ? '' : '<span class="selo">desativado</span>'}
        <small>${w.numero ? '📞 ' + esc(w.numero) : 'ainda não conectado'} · instância <code>${esc(w.instancia)}</code></small>
      </div>
      <div class="whats-acoes">
        <button class="btn azul peq" onclick="conectarWa(${w.id})">QR code</button>
        ${+w.padrao ? '' : `<button class="btn claro peq" onclick="tornarPadrao(${w.id})">Tornar padrão</button>`}
        <button class="btn claro peq" onclick="editarWhats(${w.id})">Editar</button>
        <button class="btn claro peq" onclick="testarCanal('whatsapp', ${w.id})">Testar</button>
        <button class="btn perigo peq" onclick="excluirWhats(${w.id})">Excluir</button>
      </div>
    </div>`).join('');
}
window.editarWhats = function (id) {
  const w = id ? (estado.whats || []).find(x => +x.id === +id) : { nome: '', instancia: '', ativo: 1 };
  abrirModal(`<h2>${id ? 'Editar número' : 'Novo número de WhatsApp'} <button class="modal-x">×</button></h2>
    <form id="form-whats">
      <div class="erro" id="erro-whats" hidden></div>
      <label>Nome (como você reconhece esse número)<input name="nome" value="${esc(w.nome)}" placeholder="Ex.: Comercial, Pessoal, Clínica"></label>
      <label>Nome interno da instância<input name="instancia" value="${esc(w.instancia)}" placeholder="deixe vazio para gerar automaticamente"></label>
      <p class="dica">O nome interno identifica a sessão na Evolution API. Só letras, números, ponto e hífen — não pode repetir.</p>
      <label class="opcao" style="display:inline-flex"><input type="checkbox" name="ativo" ${+w.ativo ? 'checked' : ''}> Número ativo</label>
      <div class="modal-acoes"><button type="button" class="btn claro modal-x">Cancelar</button><button class="btn azul" type="submit">Salvar</button></div>
    </form>`);
  $('#form-whats').onsubmit = async ev => {
    ev.preventDefault();
    const f = ev.target;
    const r = await api('wa_salvar', { id: id || 0, nome: f.nome.value, instancia: f.instancia.value.trim(), ativo: f.ativo.checked ? 1 : 0 });
    if (!r.ok) { const x = $('#erro-whats'); x.hidden = false; x.textContent = r.erro; return; }
    fecharModal(); aviso('Número salvo!', 'ok');
    await carregarWhats();
    if (!id) conectarWa(r.id);
  };
};
window.tornarPadrao = async function (id) { await api('wa_padrao', { id }); aviso('Número padrão alterado.', 'ok'); carregarWhats(); };
window.excluirWhats = async function (id) {
  if (!confirm('Excluir este número? Os lembretes que usavam ele passam a usar o padrão.')) return;
  const apagar = confirm('Apagar também a sessão na Evolution API? (OK = apaga, Cancelar = mantém)');
  const r = await api('wa_excluir', { id, apagar_instancia: apagar ? '1' : '0' });
  aviso(r.ok ? 'Número excluído.' : r.erro, r.ok ? 'ok' : 'ruim');
  carregarWhats(); carregarConexoes();
};

/* ---------- previsão do tempo ---------- */
window.verClima = async function () {
  const box = $('#clima-agora');
  if (!box) return;
  box.textContent = 'Consultando…';
  const cidade = $('#clima_cidade') ? $('#clima_cidade').value : '';
  const r = await fetch('clima.php?dias=3&cidade=' + encodeURIComponent(cidade)).then(x => x.json()).catch(() => ({ ok: false }));
  if (!r.ok) { box.textContent = r.erro || 'Não consegui consultar agora.'; return; }
  box.innerHTML = `<b>${esc(r.cidade)}</b> — agora ${esc(r.agora.icone)} ${esc(r.agora.descricao)}, <b>${r.agora.temperatura}°C</b>
    (sensação ${r.agora.sensacao}°C · umidade ${r.agora.umidade}% · vento ${r.agora.vento} km/h)
    <div class="clima-dias">${r.dias.map(d => `<div class="clima-dia"><span>${esc(d.dia_semana.slice(0, 3))}</span><b>${esc(d.icone)}</b>
      <i>${d.min}° / ${d.max}°</i><small>💧 ${d.chance_chuva}%</small></div>`).join('')}</div>`;
};

window.salvarConfig = async function () {
  const campos = ['evo_instancia', 'smtp_host', 'smtp_porta', 'smtp_seguranca', 'smtp_usuario', 'smtp_senha', 'smtp_de', 'smtp_nome', 'ig_cliente_id', 'assinatura',
                  'clima_cidade', 'email_dev', 'avisar_dev_minutos'];
  const d = {}; campos.forEach(k => { const el = document.getElementById(k); if (el) d[k] = el.value; });
  const chk = document.getElementById('avisar_dev'); if (chk) d.avisar_dev = chk.checked ? '1' : '0';
  const r = await api('config_salvar', d);
  aviso(r.ok ? 'Configurações salvas!' : (r.erro || 'Erro'), r.ok ? 'ok' : 'ruim');
};
window.testarAvisoDev = async function () {
  await salvarConfig();
  aviso('Enviando aviso de teste…');
  const r = await api('teste_aviso_dev');
  aviso(r.ok ? 'Aviso de teste enviado para o e-mail do desenvolvedor.' : (r.erro || 'Não consegui enviar.'), r.ok ? 'ok' : 'ruim');
};
window.conectarWa = async function (id) {
  await salvarConfig();
  abrirModal('<h2>Conectando… <button class="modal-x">×</button></h2><div class="carregando">Pedindo o QR code para a Evolution API…</div>');
  const r = await api('wa_qr', { id: id || 0 });
  if (!r.ok) return abrirModal(`<h2>Não deu certo <button class="modal-x">×</button></h2><p class="dica">${esc(r.msg || r.erro || '')}</p><div class="modal-acoes"><button class="btn azul modal-x">Fechar</button></div>`);
  if (!r.qr) { fecharModal(); aviso(r.msg || 'Já está conectado.', 'ok'); return telaConfig(); }
  const img = r.qr.startsWith('data:') ? r.qr : 'data:image/png;base64,' + r.qr;
  abrirModal(`<h2>Ler QR code <button class="modal-x">×</button></h2>
    <div class="qr"><img src="${img}" alt="QR code">
      <p class="dica">No celular: WhatsApp → <b>Aparelhos conectados</b> → <b>Conectar aparelho</b> → aponte para o código.</p>
      ${r.pareamento ? `<p>Ou use o código de pareamento: <b style="font-size:20px;letter-spacing:2px">${esc(r.pareamento)}</b></p>` : ''}
      <div id="qr-estado" class="selo">Aguardando leitura…</div></div>
    <div class="modal-acoes"><button class="btn claro modal-x">Fechar</button></div>`);
  const t = setInterval(async () => {
    if ($('#modal-fundo').hidden) return clearInterval(t);
    const e = await api('wa_estado', { id: id || 0 });
    if (e.whatsapp?.ok) { clearInterval(t); fecharModal(); aviso('WhatsApp conectado! 🎉', 'ok'); telaConfig(); }
  }, 4000);
};
window.desconectarWa = async function () {
  if (!confirm('Desconectar o WhatsApp? Os lembretes desse canal deixam de sair.')) return;
  await api('wa_desconectar'); aviso('Desconectado.'); telaConfig();
};
window.testarCanal = async function (canal, whatsId) {
  await salvarConfig();
  const rotulo = { whatsapp: 'Número de WhatsApp (com DDD)', direct: 'Conversa do Direct', email: 'E-mail de destino' }[canal];
  let extra = `<label>${rotulo}<input id="alvo-teste" placeholder="${canal === 'email' ? 'voce@email.com' : canal === 'whatsapp' ? '82988717072' : ''}"></label>`;
  if (canal === 'direct') {
    const rc = await api('ig_conversas', null, 'GET');
    extra = `<label>${rotulo}<input id="alvo-teste" list="lista-ig-teste" placeholder="@usuario — escolha da lista ou digite"></label>
      <datalist id="lista-ig-teste">${(rc.itens || []).map(v => `<option value="@${esc(v.nome || v.remetente_id)}">`).join('')}</datalist>
      <p class="dica">A Meta só entrega Direct para quem mandou mensagem para a conta nas últimas 24 horas.</p>`;
  }
  abrirModal(`<h2>Testar ${CANAIS[canal].nome} <button class="modal-x">×</button></h2>${extra}
    <div class="modal-acoes"><button class="btn claro modal-x">Cancelar</button><button class="btn azul" id="btn-testar">Enviar teste</button></div>`);
  $('#btn-testar').onclick = async () => {
    const v = $('#alvo-teste').value;
    $('#btn-testar').disabled = true; $('#btn-testar').textContent = 'Enviando…';
    const d = { canal, whatsapp: canal === 'whatsapp' ? v : '', email: canal === 'email' ? v : '',
                ig_usuario: canal === 'direct' ? v.trim().replace(/^@/, '') : '', ig_remetente_id: '',
                whatsapp_id: whatsId || 0 };
    const r = await api('teste_canal', d);
    fecharModal();
    aviso(r.ok ? `Teste enviado para ${r.destino}!` : 'Falhou: ' + r.detalhe, r.ok ? 'ok' : 'ruim');
  };
};



/* ================= ALERTA DE CHUVA ================= */
async function telaClima() {
  const r = await api('chuva_estado', null, 'GET');
  if (!r.ok) throw new Error(r.erro || 'falha');
  estado.chuva = r;
  const c = r.config, a = r.analise || {}, canais = r.canais || [];
  const ligado = String(c.chuva_ativo) === '1';
  const ag = a.agora || {};
  const horas = a.lista_horas || [];
  const faixa = horas.map(h => {
    const ch = Math.max(0, Math.min(100, +h.chance_chuva || 0));       /* sempre 0 a 100% */
    const molhada = ch >= +c.chuva_minimo || (+h.chuva_mm >= 0.5);
    return `<div class="clima-hora ${molhada ? 'molhada' : ''}" title="${esc(h.descricao)} · ${numBr(h.chuva_mm)} mm · chance de ${ch}%">
        <div class="h">${esc(String(h.hora).slice(0, 2))}h</div>
        <div class="i">${esc(h.icone)}</div>
        <div class="t">${h.temperatura}°</div>
        <div class="clima-barra" aria-label="chance de chuva ${ch}%"><i style="height:${Math.round(ch * 0.32)}px"></i></div>
        <div class="p">${ch}%</div>
      </div>`;
  }).join('');

  const hero = a.ok ? `
    <div class="clima-hero ${a.vai_chover ? 'chuva' : ''}">
      <div class="clima-topo">
        <div>
          <div class="clima-cidade">📍 ${esc(a.cidade)}</div>
          <div class="clima-temp"><span class="ic">${esc(ag.icone || '🌤️')}</span><b>${ag.temperatura}°</b></div>
          <div class="clima-cond">${esc(ag.descricao || '')}</div>
        </div>
        <div class="clima-tags">
          <span class="clima-tag">🌡️ sensação ${ag.sensacao}°</span>
          <span class="clima-tag">💧 umidade ${ag.umidade}%</span>
          <span class="clima-tag">🍃 vento ${ag.vento} km/h</span>
          ${a.hoje ? `<span class="clima-tag">⬆ ${a.hoje.max}° ⬇ ${a.hoje.min}°</span>` : ''}
        </div>
      </div>
      <div class="clima-veredito">
        <span class="ic">${a.vai_chover ? (a.icone || '🌧️') : '☀️'}</span>
        <div><b>${a.vai_chover ? `Vai chover ${esc(a.quando)}` : 'Sem chuva à vista'}</b>${esc(a.resumo)}</div>
      </div>
      ${horas.length ? `<div class="clima-horas">${faixa}</div>` : ''}
    </div>` : `<div class="resumo-con neutro">Não consegui ler a previsão agora: ${esc(a.erro || '—')}</div>`;

  $('#pagina').innerHTML = `
    ${hero}

    <div class="card">
      <div class="canal-topo">
        <h2>🌧️ Alerta automático</h2>
        <span class="selo ${ligado ? 'verde' : ''}">${ligado ? 'ligado' : 'desligado'}</span>
      </div>
      <p class="dica">O sistema confere a previsão a cada 15 minutos e, quando a chuva está chegando, avisa sozinho
        <b>${r.destinatarios} contato${r.destinatarios === 1 ? '' : 's'}</b> — sem você precisar criar lembrete.</p>

      <label class="chave ${ligado ? 'on' : ''}" id="chave-chuva">
        <input type="checkbox" id="chuva_ativo" ${ligado ? 'checked' : ''}>
        <span class="pino"></span>
        <span class="txt"><b>Avisar quando for chover</b><small>${r.no_horario ? 'Agora está dentro do horário de avisos.' : 'Agora está fora do horário de avisos.'}</small></span>
      </label>

      <div class="clima-secao">
        <h3>Quando disparar</h3>
        <div class="form-grade">
          <label>Cidade vigiada<input id="chuva_cidade" value="${esc(c.chuva_cidade || 'Maceió')}" placeholder="Maceió"></label>
          <label>A partir de que chance de chuva
            <select id="chuva_minimo">${[30, 40, 50, 60, 70, 80].map(v => `<option value="${v}" ${String(c.chuva_minimo) === String(v) ? 'selected' : ''}>${v}%</option>`).join('')}</select></label>
          <label>Olhando as próximas
            <select id="chuva_horas">${[1, 2, 3, 4, 6, 8, 12, 24].map(v => `<option value="${v}" ${String(c.chuva_horas) === String(v) ? 'selected' : ''}>${v} hora${v > 1 ? 's' : ''}</option>`).join('')}</select></label>
        </div>
      </div>

      <div class="clima-secao">
        <h3>Para não virar spam</h3>
        <div class="form-grade">
          <label>Esperar entre um aviso e outro
            <select id="chuva_intervalo">${[1, 2, 3, 6, 12, 24].map(v => `<option value="${v}" ${String(c.chuva_intervalo) === String(v) ? 'selected' : ''}>${v} hora${v > 1 ? 's' : ''}</option>`).join('')}</select></label>
          <label>Não avisar antes das<input id="chuva_inicio" type="time" value="${esc(c.chuva_inicio || '06:00')}"></label>
          <label>Nem depois das<input id="chuva_fim" type="time" value="${esc(c.chuva_fim || '22:00')}"></label>
        </div>
        <div class="chips">
          <label class="chip ${String(c.chuva_tempestade) === '1' ? 'on' : ''}"><input type="checkbox" id="chuva_tempestade" ${String(c.chuva_tempestade) === '1' ? 'checked' : ''}>⛈️ Tempestade avisa fora do horário</label>
          <label class="chip ${String(c.chuva_passou) === '1' ? 'on' : ''}"><input type="checkbox" id="chuva_passou" ${String(c.chuva_passou) === '1' ? 'checked' : ''}>🌤️ Avisar quando a chuva passar</label>
        </div>
        <p class="clima-nota"><span>🔁</span><span>Cada chuva gera <b>um aviso só</b>. Enquanto a mesma chuva estiver na previsão, o sistema fica quieto.</span></p>
      </div>

      <div class="clima-secao">
        <h3>Por onde avisar</h3>
        <div class="chips">
          ${[['whatsapp', '📱', 'WhatsApp'], ['direct', '📷', 'Direct'], ['email', '✉️', 'E-mail']].map(([k, ic, rot]) =>
            `<label class="chip ${canais.includes(k) ? 'on' : ''}"><input type="checkbox" class="chuva-canal" value="${k}" ${canais.includes(k) ? 'checked' : ''}>${ic} ${rot}</label>`).join('')}
          <label class="chip ${String(c.chuva_imagem) === '1' ? 'on' : ''}"><input type="checkbox" id="chuva_imagem" ${String(c.chuva_imagem) === '1' ? 'checked' : ''}>🖼️ Mandar o cartão da previsão</label>
        </div>
        <p class="dica" style="margin-top:10px">A imagem sai igual ao painel aí de cima — no WhatsApp ela vai com o texto como legenda; no e-mail, anexada.
          <a href="clima-cartao.php" target="_blank" rel="noopener"><b>Ver o cartão de agora</b></a></p>
        ${(r.cidades || []).length > 1 ? `<p class="clima-nota"><span>🗺️</span><span>Vigiando <b>${(r.cidades || []).map(esc).join('</b>, <b>')}</b> — cada contato recebe a previsão da cidade da ficha dele; sem cidade, vale a padrão.</span></p>` : ''}
        <p class="clima-nota"><span>👤</span><span>Cada pessoa recebe só pelos canais preenchidos na ficha dela. Quem não quiser receber é só desmarcar
          “Receber o alerta automático de chuva” em <a href="#/contatos"><b>Contatos</b></a>.</span></p>
      </div>

      <div class="clima-acoes">
        <button class="btn azul" onclick="salvarChuva()">Salvar alterações</button>
        <button class="btn claro" onclick="previaChuva()">👁 Ver a mensagem</button>
        <button class="btn claro" onclick="testarChuva()">📨 Enviar teste</button>
        <button class="btn claro espaco" onclick="rodarChuva(0)">▶ Verificar agora</button>
        <button class="btn claro" onclick="rodarChuva(1)">📣 Avisar agora</button>
      </div>
    </div>

    <div class="card">
      <h2>✍️ Texto do aviso</h2>
      <p class="dica">Toque numa variável para inserir onde o cursor estiver.</p>
      <div class="vars">${['{primeiro_nome}', '{nome}', '{saudacao}', '{cidade}', '{quando_chuva}', '{hora_chuva}', '{condicao_chuva}',
        '{chance_chuva_alerta}', '{chuva_mm_alerta}', '{janela_chuva}', '{intensidade_chuva}', '{clima}', '{temperatura}', '{clima_proximas_horas}']
        .map(v => `<button type="button" onclick="inserirVarChuva('${v}')">${v}</button>`).join('')}</div>
      <label>Título<input id="chuva_titulo" value="${esc(c.chuva_titulo || '')}"></label>
      <label>Mensagem<textarea id="chuva_mensagem" rows="8">${esc(c.chuva_mensagem || '')}</textarea></label>
      <div class="clima-secao">
        <h3>Quando a chuva passa</h3>
        <label>Título<input id="chuva_titulo_fim" value="${esc(c.chuva_titulo_fim || '')}"></label>
        <label>Mensagem<textarea id="chuva_msg_fim" rows="4">${esc(c.chuva_msg_fim || '')}</textarea></label>
      </div>
      <div class="clima-acoes">
        <button class="btn azul" onclick="salvarChuva()">Salvar texto</button>
        <button class="btn claro" onclick="previaChuva()">👁 Ver como fica</button>
      </div>
    </div>

    <div class="card">
      <h2>🕘 Último aviso</h2>
      <div class="clima-ult">
        <span class="bolota">${r.ultimo_em ? '📣' : '💤'}</span>
        <div>
          ${r.ultimo_em
            ? `<div class="quando">${esc(dataBr(r.ultimo_em))}</div><p class="dica" style="margin:4px 0 0">${esc(r.ultimo_resumo || '')}</p>`
            : `<div class="quando">nenhum até agora</div><p class="dica" style="margin:4px 0 0">Quando a chuva aparecer na previsão, o aviso sai sozinho e aparece aqui.</p>`}
        </div>
      </div>
      <div class="clima-acoes"><a class="btn claro" href="#/envios">Ver histórico de envios</a></div>
    </div>`;

  /* interruptor e chips acendem na hora */
  const chave = $('#chave-chuva');
  $('#chuva_ativo').onchange = e => chave.classList.toggle('on', e.target.checked);
  $$('.chip input').forEach(i => i.onchange = () => i.closest('.chip').classList.toggle('on', i.checked));
}
function dataBr(v) { return String(v || '').replace(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}).*/, '$3/$2/$1 às $4'); }
window.inserirVarChuva = function (v) {
  const alvo = document.activeElement && /TEXTAREA|INPUT/.test(document.activeElement.tagName) && document.activeElement.id.startsWith('chuva_')
    ? document.activeElement : $('#chuva_mensagem');
  const i = alvo.selectionStart ?? alvo.value.length;
  alvo.value = alvo.value.slice(0, i) + v + alvo.value.slice(alvo.selectionEnd ?? i);
  alvo.focus(); alvo.selectionStart = alvo.selectionEnd = i + v.length;
};
function dadosChuva() {
  return {
    chuva_ativo: $('#chuva_ativo').checked ? '1' : '0',
    chuva_cidade: $('#chuva_cidade').value.trim() || 'Maceió',
    chuva_minimo: $('#chuva_minimo').value,
    chuva_horas: $('#chuva_horas').value,
    chuva_intervalo: $('#chuva_intervalo').value,
    chuva_inicio: $('#chuva_inicio').value || '06:00',
    chuva_fim: $('#chuva_fim').value || '22:00',
    chuva_tempestade: $('#chuva_tempestade').checked ? '1' : '0',
    chuva_passou: $('#chuva_passou').checked ? '1' : '0',
    chuva_canais: $$('.chuva-canal').filter(i => i.checked).map(i => i.value).join(','),
    chuva_imagem: $('#chuva_imagem').checked ? '1' : '0',
    chuva_titulo: $('#chuva_titulo').value,
    chuva_mensagem: $('#chuva_mensagem').value,
    chuva_titulo_fim: $('#chuva_titulo_fim').value,
    chuva_msg_fim: $('#chuva_msg_fim').value,
  };
}
window.salvarChuva = async function () {
  const r = await api('chuva_salvar', dadosChuva());
  aviso(r.ok ? 'Alerta de chuva salvo!' : (r.erro || 'Não consegui salvar'), r.ok ? 'ok' : 'ruim');
  if (r.ok) navegar();
};
window.previaChuva = async function () {
  const d = dadosChuva();
  await api('chuva_salvar', d);
  const r = await api('chuva_previa', { cidade: d.chuva_cidade, minimo: d.chuva_minimo, horas: d.chuva_horas });
  if (!r.ok) { aviso(r.erro || 'Previsão indisponível', 'ruim'); return; }
  abrirModal(`<h2>Como o aviso vai chegar <button class="modal-x">×</button></h2>
    <p class="dica">Exemplo para <b>${esc(r.contato)}</b>, pelo WhatsApp.${r.exemplo ? ' Não há chuva prevista agora — os números são de demonstração.' : ''}</p>
    <div class="previa-zap"><div class="bolha"><b class="tit">${esc(r.titulo)}</b>${esc(r.mensagem)}<span class="hora">${new Date().toTimeString().slice(0, 5)} ✓✓</span></div></div>
    <div class="modal-acoes"><button class="btn azul modal-x">Fechar</button></div>`);
};
window.testarChuva = async function () {
  await api('chuva_salvar', dadosChuva());
  const lista = (estado.contatos && estado.contatos.length) ? estado.contatos : ((await api('contatos', null, 'GET')).itens || []);
  estado.contatos = lista;
  const ativos = lista.filter(c => +c.ativo);
  if (!ativos.length) { aviso('Cadastre um contato antes de testar.', 'ruim'); return; }
  abrirModal(`<h2>Enviar um teste <button class="modal-x">×</button></h2>
    <p class="dica">Vai um aviso marcado como <b>[TESTE]</b>, com o cartão da previsão, só para quem você escolher.
      Não mexe na antirrepetição nem conta nas estatísticas.</p>
    <label>Para quem<select id="teste-contato">${ativos.map(c => `<option value="${c.id}">${esc(c.nome)}${c.cidade ? ' — ' + esc(c.cidade) : ''}</option>`).join('')}</select></label>
    <div class="modal-acoes"><button class="btn claro modal-x">Cancelar</button><button class="btn azul" id="btn-teste-chuva">Enviar teste</button></div>`);
  $('#btn-teste-chuva').onclick = async () => {
    const b = $('#btn-teste-chuva'); b.disabled = true; b.textContent = 'Enviando…';
    const r = await api('chuva_teste', { contato_id: $('#teste-contato').value });
    fecharModal();
    if (!r.ok) { aviso(r.erro || 'Falhou', 'ruim'); return; }
    aviso(`Teste para ${r.contato} (${r.cidade}): ${r.enviados} enviado(s), ${r.erros} erro(s)`, r.erros ? 'ruim' : 'ok');
  };
};
window.rodarChuva = async function (forcar) {
  if (forcar && !confirm('Enviar o alerta de chuva AGORA para todos os contatos, mesmo fora do horário?')) return;
  aviso('Consultando a previsão…');
  const r = await api('chuva_rodar', { forcar: forcar ? '1' : '0' });
  const env = r.envio ? ` — ${r.envio.enviados} enviados, ${r.envio.erros} erros` : '';
  aviso((r.resumo || r.acao || 'pronto') + env, r.acao === 'avisado' ? 'ok' : '');
  navegar();
};

/* ---------- início ---------- */
(async function () {
  const token = new URLSearchParams(location.search).get('recuperar');
  if (token) { mostrarLogin(); telaRedefinir(token); return; }
  const r = await api('sessao', null, 'GET').catch(() => ({ ok: false }));
  if (r.ok && r.usuario) mostrarApp(r.usuario); else mostrarLogin();
})();
