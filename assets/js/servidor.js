// Servidores do Discord ligados ao bot (onde a conta logada é admin, gestor
// ou caller). As páginas de builds, composições e eventos usam isso pra
// trabalhar direto nos dados do bot, sem passar pelo painel antigo.
(function () {
  const KEY = "emp_srv";
  let cache = null;

  async function lista() {
    if (cache) return cache;
    const me = await window.EmporioAuth.me();
    cache = me ? (me.admin_guilds || []).filter(function (g) { return !g.personal; }) : [];
    return cache;
  }
  function atual() { try { return localStorage.getItem(KEY) || ""; } catch (e) { return ""; } }
  function escolher(id) { try { localStorage.setItem(KEY, id || ""); } catch (e) {} }

  async function api(gid, path, opts) {
    const sep = path.indexOf("?") >= 0 ? "&" : "?";
    const r = await window.EmporioAuth.apiFetch(path + sep + "guild_id=" + encodeURIComponent(gid), opts);
    let d = null;
    try { d = await r.json(); } catch (e) {}
    if (r.status === 401) throw new Error("Sua sessão expirou. Saia e entre de novo com o Discord.");
    if (r.status === 403) throw new Error("Você não tem permissão nesse servidor. Peça pra um admin te liberar como caller.");
    if (!r.ok) throw new Error((d && d.error) || "O bot não respondeu (" + r.status + "). Tente de novo.");
    return d;
  }
  function post(gid, path, body) {
    return api(gid, path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  }
  function del(gid, path) { return api(gid, path, { method: "DELETE" }); }

  function icone(g) {
    if (g.icon) return '<img src="https://cdn.discordapp.com/icons/' + g.id + "/" + g.icon + '.png?size=64" alt="" style="width:20px;height:20px;border-radius:6px">';
    return '<span style="width:20px;height:20px;border-radius:6px;background:var(--gold-soft);color:var(--gold);display:inline-grid;place-items:center;font-size:11px;font-weight:800">' + Emp.esc((g.name || "?").charAt(0)) + "</span>";
  }

  // Barra de escolha: "Neste navegador" (opcional) + um chip por servidor.
  async function barra(el, opts) {
    opts = opts || {};
    const gs = await lista();
    let sel = atual();
    if (sel && !gs.some(function (g) { return g.id === sel; })) sel = "";
    if (!sel && !opts.local && gs.length) sel = gs[0].id;
    function draw() {
      let h = '<div class="row wrap" style="gap:8px;margin:0 0 18px"><span class="muted small">' + (opts.rotulo || "Onde:") + "</span>";
      if (opts.local) h += '<span class="chip' + (sel ? "" : " on") + '" data-srv="">Neste navegador</span>';
      h += gs.map(function (g) { return '<span class="chip' + (g.id === sel ? " on" : "") + '" data-srv="' + g.id + '">' + icone(g) + Emp.esc(g.name) + "</span>"; }).join("");
      if (!gs.length) {
        h += window.EmporioAuth.getToken()
          ? '<span class="muted small">Nenhum servidor seu tem o bot. <a href="' + INVITE + '" target="_blank" rel="noopener">Adicionar o bot</a> e depois sair e entrar de novo.</span>'
          : '<a class="small" href="' + window.EmporioAuth.loginUrl() + '">Entre com o Discord pra usar os servidores do bot</a>';
      }
      el.innerHTML = h + "</div>";
    }
    draw();
    el.onclick = function (e) {
      const c = e.target.closest("[data-srv]");
      if (!c) return;
      sel = c.dataset.srv; escolher(sel); draw();
      if (opts.onChange) opts.onChange(sel ? gs.find(function (g) { return g.id === sel; }) : null);
    };
    const g = sel ? gs.find(function (x) { return x.id === sel; }) : null;
    if (opts.onChange) opts.onChange(g);
    return g;
  }

  const INVITE = "https://discord.com/oauth2/authorize?client_id=1536893845898264657&permissions=328851770448&scope=bot%20applications.commands";
  // papel das builds (site) <-> papel das vagas da comp
  window.EmpServidor = { lista: lista, atual: atual, escolher: escolher, api: api, post: post, del: del, barra: barra, icone: icone, INVITE: INVITE };
})();
