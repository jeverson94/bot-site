// Ping pelo bot, direto do site (sem o painel antigo).
// <div id="ponte-bot" data-modo="criar"> mostra o formulário + pings abertos;
// data-modo="lista" mostra só os pings do servidor com um atalho pra criar.
(function () {
  const S = window.EmpServidor;
  const esc = function (t) { return String(t == null ? "" : t).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); };
  let G = null, COMPS = [], root = null, modo = "criar";

  function card(inner) { return '<div class="card" style="margin-bottom:22px;border-color:rgba(226,188,98,.35)">' + inner + "</div>"; }

  async function render(el) {
    root = el; if (!root) return;
    modo = root.dataset.modo || "criar";
    if (!window.EmporioAuth.getToken() || !(await window.EmporioAuth.me())) {
      root.innerHTML = card('<h2 style="margin:0 0 6px">Postar pelo bot no Discord</h2><p class="muted" style="margin:0 0 14px">Entre com o Discord e o bot posta o evento no canal do seu servidor, com as vagas, o loot de cada uma e a build por DM pra quem confirmar.</p><a class="btn" href="' + window.EmporioAuth.loginUrl() + '">Entrar com Discord</a>');
      return;
    }
    root.innerHTML = card('<h2 style="margin:0 0 4px">' + (modo === "lista" ? "Pings no Discord" : "Postar pelo bot no Discord") + '</h2><p class="muted small" style="margin:0 0 12px">' +
      (modo === "lista" ? "Os pings que o bot postou no servidor. Encerrar aqui trava as vagas no Discord." : "O bot posta no canal escolhido e cada um confirma a vaga pelos botões.") + '</p><div id="pb-srv"></div><div id="pb-body"></div>');
    await S.barra(document.getElementById("pb-srv"), { rotulo: "Servidor:", onChange: function (g) { G = g; carregar(); } });
  }

  async function carregar() {
    const body = document.getElementById("pb-body");
    if (!G) { body.innerHTML = '<p class="muted small">Nenhum servidor com o bot. <a href="' + S.INVITE + '" target="_blank" rel="noopener">Adicionar o bot</a>, depois sair e entrar de novo no site.</p>'; return; }
    body.innerHTML = '<p class="muted small">Carregando…</p>';
    try {
      if (modo === "lista") { body.innerHTML = '<div id="pb-pings"></div><a class="btn" href="/criar-evento/" style="margin-top:12px">Criar ping no Discord</a>'; return pings(); }
      const r = await Promise.all([S.api(G.id, "/api/discord/channels"), S.api(G.id, "/api/servidor/comps")]);
      COMPS = r[1].comps || [];
      const pre = new URLSearchParams(location.search).get("comp") || "";
      const hoje = new Date();
      const iso = hoje.getFullYear() + "-" + String(hoje.getMonth() + 1).padStart(2, "0") + "-" + String(hoje.getDate()).padStart(2, "0");
      body.innerHTML =
        '<div class="grid2">' +
        '<label class="field">Canal<select class="select" id="pb-canal">' + (r[0].channels || []).map(function (c) { return '<option value="' + c.id + '">#' + esc(c.name) + (c.category ? " · " + esc(c.category) : "") + "</option>"; }).join("") + "</select></label>" +
        '<label class="field">Composição<select class="select" id="pb-comp"><option value="">Escolha uma</option>' + COMPS.map(function (c) { return '<option value="' + esc(c.id) + '"' + (c.id === pre ? " selected" : "") + ">" + esc(c.nome) + "</option>"; }).join("") + "</select></label></div>" +
        '<div class="grid2" style="margin-top:10px"><label class="field">Título<input class="input" id="pb-titulo" maxlength="80" placeholder="Ex.: B1B da segundona"></label>' +
        '<div class="grid2"><label class="field">Data<input class="input" id="pb-data" type="date" value="' + iso + '"></label><label class="field">Hora (Brasília)<input class="input" id="pb-hora" type="time"></label></div></div>' +
        '<div class="grid2" style="margin-top:10px"><label class="field">Local<input class="input" id="pb-local" maxlength="60" placeholder="Ex.: Avalon, portal"></label><label class="field">Fee<input class="input" id="pb-fee" maxlength="40" value="Grátis"></label></div>' +
        '<label class="field" style="margin-top:10px">Vagas (uma por linha, <code>Nome | Loot: X</code>)<textarea class="input" id="pb-vagas" rows="8" placeholder="Main Tank | Loot: Mapas&#10;Healer | Loot: Cabeças"></textarea></label>' +
        '<p class="muted small" style="margin:6px 0 0">Hora vazia = ping ao vivo agora.</p>' +
        '<div class="row" style="margin-top:12px"><button class="btn" id="pb-ok">Postar no Discord</button><span class="muted small" id="pb-msg"></span></div>' +
        '<div class="section" style="margin-top:22px"><h3 style="margin:0 0 8px">Pings abertos</h3><div id="pb-pings"></div></div>';
      document.getElementById("pb-comp").onchange = preencher;
      document.getElementById("pb-ok").onclick = postar;
      if (pre) { preencher(); root.scrollIntoView({ behavior: "smooth" }); }
      pings();
    } catch (e) { body.innerHTML = '<p class="muted">' + esc(e.message) + "</p>"; }
  }

  async function preencher() {
    const id = document.getElementById("pb-comp").value;
    if (!id) return;
    const c = COMPS.find(function (x) { return x.id === id; });
    if (c && !document.getElementById("pb-titulo").value) document.getElementById("pb-titulo").placeholder = c.nome;
    try { document.getElementById("pb-vagas").value = (await S.api(G.id, "/api/servidor/vagas-texto/" + encodeURIComponent(id))).texto; } catch (e) {}
  }

  async function postar() {
    const m = document.getElementById("pb-msg");
    const comp = document.getElementById("pb-comp").value;
    if (!comp) { m.textContent = "Escolha a composição."; return; }
    if (!document.getElementById("pb-vagas").value.trim()) { m.textContent = "Coloque as vagas."; return; }
    let horario = "";
    const d = document.getElementById("pb-data").value, h = document.getElementById("pb-hora").value;
    if (h) { const p = d.split("-"); horario = p[2] + "/" + p[1] + " " + h; }
    m.textContent = "Postando…";
    document.getElementById("pb-ok").disabled = true;
    try {
      await S.post(G.id, "/api/pings", {
        canal_id: document.getElementById("pb-canal").value, composicao_id: comp,
        titulo: document.getElementById("pb-titulo").value, local: document.getElementById("pb-local").value,
        fee: document.getElementById("pb-fee").value, horario: horario, vagas_texto: document.getElementById("pb-vagas").value,
      });
      m.textContent = "Postado no Discord.";
      pings();
    } catch (e) { m.textContent = e.message; }
    document.getElementById("pb-ok").disabled = false;
  }

  async function pings() {
    const el = document.getElementById("pb-pings");
    if (!el) return;
    try {
      const lista = (await S.api(G.id, "/api/pings?limit=20")).filter(function (p) { return modo === "lista" || !p.encerrado; });
      el.innerHTML = lista.length ? '<div class="table-wrap"><table class="table"><tbody>' + lista.map(function (p) {
        const quando = p.quando ? new Date(p.quando * 1000).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" }) : "ao vivo";
        return "<tr><td><b>" + esc(p.titulo) + '</b><div class="muted small">' + quando + " · por " + esc(p.criado_por_nome || "?") + "</div></td>" +
          '<td class="num">' + p.confirmados + "/" + p.vagas_total + "</td>" +
          '<td class="num">' + (p.encerrado ? '<span class="badge">Encerrado</span>' : '<button class="btn secondary" data-enc="' + esc(p.key) + '" style="padding:5px 10px;font-size:12px">Encerrar</button>') + "</td></tr>";
      }).join("") + "</tbody></table></div>" : '<p class="muted small">Nenhum ping aberto.</p>';
    } catch (e) { el.innerHTML = '<p class="muted small">' + esc(e.message) + "</p>"; }
  }

  document.addEventListener("click", async function (e) {
    const b = e.target.closest("[data-enc]");
    if (!b || !G) return;
    if (!confirm("Encerrar esse ping? As vagas travam no Discord.")) return;
    try { await S.post(G.id, "/api/pings/" + encodeURIComponent(b.dataset.enc) + "/encerrar", {}); pings(); } catch (err) { alert(err.message); }
  });

  window.EmporioPonteBot = { render: render };
  function go() { render(document.getElementById("ponte-bot")); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", go); else go();
})();
