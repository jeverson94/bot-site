// Painel: barra lateral compartilhada pelas páginas em /painel/.
// Uso: <aside class="painel-side" id="painel-side"></aside> e
// EmporioPainel.render("/painel/builds/") com o link ativo.
(function () {
  const I = {
    home: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    swords: '<path d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l6-6M16 16l4 4M19 21l2-2M9.5 6.5L13 3h3v3l-3.5 3.5M5 14l4 4M7 17l-3 3M3 19l2 2"/>',
    comps: '<path d="M4 4v16M9 4v16M14 4l6 16"/>',
    builds: '<path d="M5 3h10l4 4v14H5z"/><path d="M9 13a3 3 0 1 0 6 0 3 3 0 0 0-6 0zM14 15.5l2.5 2.5"/>',
    vod: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 5v14M17 5v14M3 9h4M3 15h4M17 9h4M17 15h4"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0M16 4a3 3 0 0 1 0 6M18 20a6 6 0 0 0-3-5"/>',
    medal: '<circle cx="12" cy="14" r="6"/><path d="M9 3h6l-1.5 5h-3zM12 11v6M9.5 14h5"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    coins: '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7M3 12v5c0 1.7 2.7 3 6 3 1.3 0 2.5-.2 3.5-.6"/><ellipse cx="17" cy="15" rx="4" ry="2"/><path d="M13 15v3c0 1.1 1.8 2 4 2s4-.9 4-2v-3"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  };
  const SIDE = [
    { items: [{ href: "/painel/", label: "Perfil", ic: "home" }] },
    { label: "Jogar", items: [
      { href: "/eventos/", label: "Eventos", ic: "swords" },
      { href: "/composicoes/", label: "Comps", ic: "comps" },
      { href: "/painel/builds/", label: "Builds", ic: "builds" },
      { href: "/vod-review/", label: "Análises de VOD", ic: "vod" },
      { href: "/disarray/", label: "Disarray", ic: "chart" },
    ]},
    { label: "Guilda", items: [
      { href: "/painel/guildas/", label: "Guildas", ic: "users" },
      { href: "/painel/guildas/membros/", label: "Membros", ic: "list" },
      { href: "/painel/guildas/batalhas/", label: "Batalhas", ic: "swords" },
      { href: "/painel/guildas/regears/", label: "Regears", ic: "shield" },
      { href: "/painel/guildas/saldo/", label: "Saldo", ic: "coins" },
      { href: "/painel/guildas/equipe/", label: "Equipe do bot", ic: "shield" },
    ]},
    { label: "Estatísticas", items: [
      { href: "/painel/presenca/", label: "Presença", ic: "chart" },
      { href: "/painel/maestria/", label: "Maestria", ic: "medal" },
    ]},
    { label: "Conta", items: [{ href: "/painel/configuracoes/", label: "Configurações", ic: "gear" }] },
  ];

  function svg(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (I[name] || "") + "</svg>";
  }

  function closedGroups() { try { return JSON.parse(localStorage.getItem("emp_painel_fechados") || "[]"); } catch (e) { return []; } }
  function setClosed(list) { try { localStorage.setItem("emp_painel_fechados", JSON.stringify(list)); } catch (e) {} }

  function allLinks() { return SIDE.reduce(function (a, g) { return a.concat(g.items); }, []); }
  // Link da barra que corresponde a um endereço (o prefixo mais longo).
  function activeFor(path) {
    let best = null;
    allLinks().forEach(function (i) {
      if (path.indexOf(i.href) === 0 && (!best || i.href.length > best.length)) best = i.href;
    });
    if (!best && path.indexOf("/criar-evento/") === 0) best = "/eventos/";
    if (!best && path.indexOf("/builds/ver/") === 0) best = "/painel/builds/";
    return best;
  }
  function setActive(path) {
    const href = activeFor(path);
    document.querySelectorAll("#painel-side a.lnk").forEach(function (a) { a.classList.toggle("on", a.getAttribute("href") === href); });
  }

  // Troca só o conteúdo da direita: a página escolhida abre num iframe sem
  // topo nem barra lateral, e a barra de endereço acompanha.
  let frame = null;
  function fitFrame() {
    if (!frame) return;
    const top = frame.getBoundingClientRect().top + window.scrollY;
    frame.style.height = Math.max(400, window.innerHeight - top) + "px";
  }
  function go(url, push) {
    const main = document.querySelector(".painel-main");
    if (!main) { location.href = url; return; }
    if (!frame) {
      main.innerHTML = "";
      main.classList.add("framed");
      frame = document.createElement("iframe");
      frame.className = "painel-frame";
      frame.title = "Conteúdo do painel";
      main.appendChild(frame);
      window.addEventListener("resize", fitFrame);
    }
    frame.src = url;
    if (push) history.pushState({ painel: url }, "", url);
    setActive(new URL(url, location.href).pathname);
    window.scrollTo(0, 0);
    fitFrame();
    const side = document.getElementById("painel-side");
    if (side) side.classList.remove("open");
  }
  window.addEventListener("message", function (e) {
    if (e.origin !== location.origin || !e.data || e.data.type !== "emp-nav" || !frame || e.source !== frame.contentWindow) return;
    if (e.data.url !== location.pathname + location.search + location.hash) history.replaceState({ painel: e.data.url }, "", e.data.url);
    if (e.data.title) document.title = e.data.title;
    setActive(new URL(e.data.url, location.href).pathname);
  });
  window.addEventListener("popstate", function () {
    if (document.getElementById("painel-side")) go(location.pathname + location.search + location.hash, false);
  });

  function render(active) {
    const el = document.getElementById("painel-side");
    if (!el) return;
    if (window.EmporioShell && window.EmporioShell.EMBED) return;
    const closed = closedGroups();
    el.innerHTML =
      '<div class="row between"><h2>Painel</h2><button class="btn secondary side-toggle" id="side-toggle" style="padding:6px 12px">Menu</button></div>' +
      '<div class="side-body"><input class="input side-search" id="side-search" placeholder="Buscar">' +
      SIDE.map(function (g) {
        const links = g.items.map(function (i) {
          return '<a class="lnk' + (i.href === active ? " on" : "") + '" href="' + i.href + '" data-t="' + i.label.toLowerCase() + '">' + svg(i.ic) + i.label + "</a>";
        }).join("");
        if (!g.label) return '<div class="links">' + links + "</div>";
        return '<div class="grp' + (closed.indexOf(g.label) >= 0 ? " closed" : "") + '" data-g="' + g.label + '"><div class="grp-h">' + g.label + '</div><div class="links">' + links + "</div></div>";
      }).join("") + "</div>";
    el.querySelectorAll(".grp-h").forEach(function (h) {
      h.addEventListener("click", function () {
        const g = h.parentElement;
        g.classList.toggle("closed");
        setClosed(Array.from(el.querySelectorAll(".grp.closed")).map(function (x) { return x.dataset.g; }));
      });
    });
    document.getElementById("side-search").addEventListener("input", function () {
      const q = this.value.trim().toLowerCase();
      el.querySelectorAll("a.lnk").forEach(function (a) { a.style.display = !q || a.dataset.t.indexOf(q) >= 0 ? "" : "none"; });
      if (q) el.querySelectorAll(".grp").forEach(function (g) { g.classList.remove("closed"); });
    });
    document.getElementById("side-toggle").addEventListener("click", function () { el.classList.toggle("open"); });
    el.addEventListener("click", function (e) {
      const a = e.target.closest("a.lnk");
      if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      go(a.getAttribute("href"), true);
    });
  }

  // Busca de guilda ou jogador na API do Albion, com lista de sugestões.
  // kind: "guilds" ou "players". onPick recebe {id, n, extra}.
  function picker(input, box, kind, onPick, regionFn) {
    let timer = null, seq = 0;
    box.classList.add("pick-opts");
    const region = regionFn || Emp.getRegion;
    function row(id, n, extra) {
      return '<div data-id="' + Emp.esc(id) + '" data-n="' + Emp.esc(n) + '" data-x="' + Emp.esc(extra) + '"><b>' + Emp.esc(n) + '</b> <span class="muted">' + Emp.esc(extra) + "</span></div>";
    }
    input.addEventListener("input", function () {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { box.classList.remove("open"); return; }
      timer = setTimeout(async function () {
        const my = ++seq;
        const found = {};
        let html = "";
        // Guildas: primeiro o banco do site (na hora), depois a API oficial completa o que faltar.
        if (kind === "guilds") {
          try {
            const fast = await Emp.albion("buscaguilda", { s: region(), q: q });
            if (my !== seq) return;
            fast.forEach(function (x) { found[x.id] = 1; html += row(x.id, x.n, x.a ? "[" + x.a + "]" : ""); });
            box.innerHTML = (html || "") + '<div class="muted small" id="pk-more">Procurando mais na API do Albion...</div>';
            box.classList.add("open");
          } catch (e) {}
        }
        try {
          const r = await Emp.albion("search", { s: region(), q: q });
          if (my !== seq) return;
          (r[kind] || []).slice(0, 12).forEach(function (x) {
            if (found[x.Id]) return;
            const extra = kind === "guilds" ? (x.AllianceName ? "[" + x.AllianceName + "]" : "") : (x.GuildName || "Sem guilda");
            html += row(x.Id, x.Name, extra);
          });
          box.innerHTML = html || '<div class="muted">Nada encontrado com esse nome neste servidor.</div>';
          box.classList.add("open");
        } catch (e) {
          if (my !== seq) return;
          box.innerHTML = html || '<div class="muted">' + Emp.esc(e.message) + "</div>";
          box.classList.add("open");
        }
      }, 300);
    });
    box.addEventListener("click", function (e) {
      const o = e.target.closest("[data-id]");
      if (!o) return;
      box.classList.remove("open");
      input.value = o.dataset.n;
      onPick({ id: o.dataset.id, n: o.dataset.n, extra: o.dataset.x });
    });
    document.addEventListener("click", function (e) { if (!box.contains(e.target) && e.target !== input) box.classList.remove("open"); });
  }

  // Personagem e guilda do jogo ligados a este navegador.
  function getLink(k) { try { return JSON.parse(localStorage.getItem("emp_" + k) || "null"); } catch (e) { return null; } }
  function setLink(k, v) { try { if (v) localStorage.setItem("emp_" + k, JSON.stringify(v)); else localStorage.removeItem("emp_" + k); } catch (e) {} }

  // Barra "Guilda: X [trocar]" usada nas páginas da seção Guilda.
  // onGuild(G) é chamado com {id, n, s} quando há guilda escolhida.
  function guildBar(el, onGuild) {
    function draw() {
      const G = getLink("guilda");
      el.innerHTML = '<div class="card row between wrap" style="padding:12px 16px;margin-bottom:18px"><div class="row" style="gap:10px"><span class="muted small">Guilda</span><b style="font-size:16px">' +
        (G ? Emp.esc(G.n) : "nenhuma escolhida") + "</b>" + (G ? '<span class="badge">' + Emp.esc((Emp.REGIONS.find(function (r) { return r.id === G.s; }) || {}).label || "") + "</span>" : "") +
        '</div><div class="pick-wrap" style="min-width:260px"><input class="input" id="gb-q" placeholder="' + (G ? "Trocar de guilda" : "Buscar a guilda pelo nome") + '" style="width:100%"><div id="gb-o"></div></div></div>';
      picker(document.getElementById("gb-q"), document.getElementById("gb-o"), "guilds", function (x) {
        setLink("guilda", { id: x.id, n: x.n, s: Emp.getRegion() });
        draw();
      });
      onGuild(G);
    }
    draw();
  }

  // Batalhas recentes da guilda (até pages x 50), guardadas por alguns minutos.
  const battleCache = {};
  async function guildBattles(G, pages) {
    const key = G.s + G.id + pages;
    if (battleCache[key] && Date.now() - battleCache[key].t < 300000) return battleCache[key].v;
    const out = [];
    for (let i = 0; i < pages; i++) {
      const page = await Emp.albion("battles", { s: G.s, guildId: G.id, offset: i * 50, limit: 50 });
      page.forEach(function (b) { out.push(b); });
      if (page.length < 50) break;
    }
    battleCache[key] = { t: Date.now(), v: out };
    return out;
  }

  // Lado da guilda numa batalha: jogadores, abates, mortes e fama.
  function guildSide(b, gid) {
    const s = { p: 0, k: 0, d: 0, f: 0, tot: 0 };
    Object.values(b.players || {}).forEach(function (p) {
      s.tot++;
      if (p.guildId !== gid) return;
      s.p++; s.k += p.kills; s.d += p.deaths; s.f += p.killFame;
    });
    return s;
  }

  window.EmporioPainel = { render: render, picker: picker, getLink: getLink, setLink: setLink, guildBar: guildBar, guildBattles: guildBattles, guildSide: guildSide };
})();
