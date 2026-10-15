// Empório dos Bots — topo (menu com grupos) e rodapé compartilhados.
// Estrutura de URLs espelha a do albionzvz.com, adaptada pro PT-BR.
(function () {
  const MENU = [
    { label: "Eventos", items: [
      { href: "/criar-evento/", label: "Criar evento", desc: "Monte a call e pingue no Discord" },
      { href: "/eventos/", label: "Eventos", desc: "Próximos eventos da guilda" },
      { href: "/painel/guildas/", label: "Minhas guildas", desc: "Configurações e membros" },
    ]},
    { label: "Batalhas", items: [
      { href: "/batalhas/", label: "Batalhas", desc: "Lutas recentes ao vivo" },
      { href: "/guildas/", label: "Ranking de guildas", desc: "Quem está dominando" },
      { href: "/batalhas/guerras/", label: "Guerras", desc: "Rivalidades acompanhadas" },
      { href: "/batalhas/meta/", label: "Tierlist de itens", desc: "O meta das lutas" },
    ]},
    { label: "Ferramentas", items: [
      { href: "/builds/", label: "Builds", desc: "Biblioteca da comunidade" },
      { href: "/composicoes/", label: "Composições", desc: "Comps prontas pra party" },
      { href: "/vod-review/", label: "VOD review", desc: "Analise as gravações" },
      { href: "/mapa/", label: "Mapa", desc: "Territórios e rotas" },
      { href: "/pontos-temporada/", label: "Pontos de temporada", desc: "Placar da season" },
      { href: "/mercado/", label: "Mercado", desc: "Preços dos itens" },
    ]},
    { label: "Diversão", items: [
      { href: "/disarray/", label: "Disarray", desc: "Calculadora de desordem" },
      { href: "/clipes/", label: "Clipes", desc: "As melhores jogadas" },
      { href: "/jogos/", label: "Jogos", desc: "Minijogos de Albion" },
    ]},
    { label: "Informação", items: [
      { href: "/docs/", label: "Documentação", desc: "Como usar tudo" },
      { href: "/recursos/eventos/", label: "Recursos", desc: "O que o Empório faz" },
      { href: "/status/", label: "Status dos servidores", desc: "Albion e o bot" },
      { href: "/manutencao-albion/", label: "Manutenção do Albion", desc: "Horários de downtime" },
      { href: "/blog/", label: "Blog", desc: "Novidades" },
      { href: "/sobre/", label: "Sobre nós", desc: "Quem faz o Empório" },
      { href: "/contato/", label: "Contato", desc: "Fale com a gente" },
    ]},
  ];

  const CSS = `
  .nav-group { position: relative; }
  .nav-group > button { background:none; border:none; color: var(--text-dim); font: inherit; font-size:13px; text-transform:uppercase; letter-spacing:1.1px; font-weight:600; cursor:pointer; padding:8px 4px; display:flex; align-items:center; gap:5px; }
  .nav-group > button:hover, .nav-group.active > button { color: var(--gold); }
  .nav-group > button .chev { font-size:9px; opacity:.7; transition: transform .15s; }
  .nav-group:hover > button .chev, .nav-group.open > button .chev { transform: rotate(180deg); }
  .nav-drop { position:absolute; top:100%; left:50%; transform: translateX(-50%) translateY(6px); min-width:270px; background: rgba(18,21,31,.98); border:1px solid var(--card-border); border-radius:14px; padding:8px; box-shadow: 0 18px 40px rgba(0,0,0,.5); opacity:0; pointer-events:none; transition: opacity .15s, transform .15s; z-index:30; }
  .nav-group:hover .nav-drop, .nav-group.open .nav-drop { opacity:1; pointer-events:auto; transform: translateX(-50%) translateY(0); }
  .topbar .nav-drop a { display:block; padding:9px 12px; border-radius:9px; text-transform:none; letter-spacing:0; }
  .topbar .nav-drop a:hover, .topbar .nav-drop a.active { background: var(--gold-soft); text-decoration:none; }
  .nav-drop a b { display:block; color: var(--text); font-size:13.5px; font-weight:600; }
  .nav-drop a span { display:block; color: var(--text-dim); font-size:12px; margin-top:2px; }
  .nav-drop a.active b { color: var(--gold); }
  .menu-toggle { display:none; background:none; border:1px solid var(--card-border); color: var(--gold); border-radius:8px; padding:6px 10px; font-size:16px; cursor:pointer; }
  @media (max-width: 900px) {
    .topbar nav { display:none; width:100%; flex-direction:column; align-items:stretch; gap:4px; }
    .topbar nav.open { display:flex; }
    .menu-toggle { display:block; }
    .nav-drop { position:static; transform:none; opacity:1; pointer-events:auto; box-shadow:none; display:none; min-width:0; }
    .nav-group.open .nav-drop { display:block; transform:none; }
    .nav-group:hover .nav-drop { transform:none; }
  }
  .site-footer { border-top:1px solid var(--card-border); margin-top:60px; padding:44px 32px 30px; }
  .site-footer .cols { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: 1.4fr repeat(5, 1fr); gap:28px; }
  .site-footer h4 { color: var(--gold); font-size:12px; text-transform:uppercase; letter-spacing:.8px; margin:0 0 12px; }
  .site-footer a { display:block; color: var(--text-dim); font-size:13px; padding:4px 0; }
  .site-footer a:hover { color: var(--gold); text-decoration:none; }
  .site-footer .legal { max-width:1280px; margin:30px auto 0; padding-top:20px; border-top:1px solid rgba(255,255,255,.05); display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; color: var(--text-dim); font-size:12px; }
  .site-footer .legal a { display:inline; margin-left:16px; }
  @media (max-width: 900px) { .site-footer .cols { grid-template-columns: 1fr 1fr; } }
  `;

  function injectCss() {
    if (document.getElementById("shell-css")) return;
    const s = document.createElement("style");
    s.id = "shell-css";
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  function norm(p) { return p.endsWith("/") ? p : p + "/"; }

  function renderTopbar(activePath) {
    injectCss();
    const here = norm(activePath || window.location.pathname);
    const root = document.getElementById("topbar-root");
    if (root) {
      const navHtml = MENU.map(function (g) {
        const groupActive = g.items.some(function (i) { return here.startsWith(i.href) && i.href !== "/"; });
        const items = g.items.map(function (i) {
          const act = here === i.href ? " active" : "";
          return '<a class="' + act.trim() + '" href="' + i.href + '"><b>' + i.label + '</b><span>' + i.desc + '</span></a>';
        }).join("");
        return '<div class="nav-group' + (groupActive ? " active" : "") + '"><button type="button">' + g.label +
          ' <span class="chev">▼</span></button><div class="nav-drop">' + items + '</div></div>';
      }).join("");
      root.innerHTML =
        '<div class="topbar">' +
        '<a class="brand-group" href="/" style="text-decoration:none;"><span class="logo-x">X</span>' +
        '<span class="brand">Empório <b>dos Bots</b></span></a>' +
        '<button class="menu-toggle" id="menu-toggle" aria-label="Menu">☰</button>' +
        '<nav id="main-nav">' + navHtml + '</nav>' +
        '<div id="topbar-account"></div>' +
        '</div>';
      document.getElementById("menu-toggle").addEventListener("click", function () {
        document.getElementById("main-nav").classList.toggle("open");
      });
      root.querySelectorAll(".nav-group > button").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const g = btn.parentElement;
          const wasOpen = g.classList.contains("open");
          root.querySelectorAll(".nav-group").forEach(function (x) { x.classList.remove("open"); });
          if (!wasOpen) g.classList.add("open");
        });
      });
      document.addEventListener("click", function (e) {
        if (!e.target.closest(".nav-group")) root.querySelectorAll(".nav-group").forEach(function (x) { x.classList.remove("open"); });
      });
      renderAccountArea();
    }
    renderFooter();
  }

  async function renderAccountArea() {
    const el = document.getElementById("topbar-account");
    if (!el) return;
    const user = await window.EmporioAuth.me();
    if (user) {
      const name = user.global_name || user.username || "Jogador";
      el.innerHTML =
        '<div style="display:flex; align-items:center; gap:10px;">' +
        '<a href="/painel/guildas/" style="color:var(--text-dim); font-size:13px;">' + name + "</a>" +
        '<button class="btn secondary" id="btn-logout" style="padding:7px 14px; font-size:12.5px;">Sair</button></div>';
      document.getElementById("btn-logout").addEventListener("click", function () { window.EmporioAuth.logout(); });
    } else {
      el.innerHTML = '<div style="display:flex; gap:8px;"><a class="btn secondary" href="/login/">Entrar</a><a class="btn" href="/cadastro/">Criar conta</a></div>';
    }
  }

  function renderFooter() {
    let f = document.getElementById("footer-root");
    if (!f) {
      document.querySelectorAll("body > .footer").forEach(function (x) { x.remove(); });
      f = document.createElement("div");
      f.id = "footer-root";
      const scripts = document.body.querySelector("script");
      document.body.insertBefore(f, scripts);
    }
    const cols = MENU.map(function (g) {
      return '<div><h4>' + g.label + '</h4>' + g.items.map(function (i) { return '<a href="' + i.href + '">' + i.label + '</a>'; }).join("") + '</div>';
    }).join("");
    f.innerHTML =
      '<footer class="site-footer"><div class="cols">' +
      '<div><div class="brand-group" style="display:flex;align-items:center;gap:10px;margin-bottom:12px;"><span class="logo-x" style="width:30px;height:30px;border-radius:8px;background:var(--gold-soft);display:flex;align-items:center;justify-content:center;color:var(--gold);font-weight:800;">X</span><b style="font-size:16px;">Empório <span style="color:var(--gold)">dos Bots</span></b></div>' +
      '<p style="color:var(--text-dim);font-size:13px;line-height:1.6;margin:0;">Builds, composições, eventos e o bot da sua guilda num lugar só. Sempre gratuito.</p></div>' +
      cols + '</div>' +
      '<div class="legal"><span>© ' + new Date().getFullYear() + ' Empório dos Bots · Site feito por fãs, sem vínculo com a Sandbox Interactive.</span>' +
      '<span><a href="/termos/">Termos de uso</a><a href="/privacidade/">Privacidade</a></span></div></footer>';
  }

  window.EmporioShell = { renderTopbar: renderTopbar, MENU: MENU };
})();
