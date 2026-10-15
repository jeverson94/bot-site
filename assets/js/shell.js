// Empório dos Bots — topo (nav) compartilhado entre as páginas estáticas.
(function () {
  function renderTopbar(activePath) {
    const root = document.getElementById("topbar-root");
    if (!root) return;
    const links = [
      { href: "/builds/", label: "Builds" },
      { href: "/composicoes/", label: "Composições" },
      { href: "/eventos/", label: "Eventos" },
      { href: "/ferramentas/", label: "Ferramentas" },
    ];
    const navHtml = links.map(function (l) {
      const active = activePath === l.href ? " active" : "";
      return '<a class="' + active.trim() + '" href="' + l.href + '">' + l.label + "</a>";
    }).join("");
    root.innerHTML =
      '<div class="topbar">' +
      '<a class="brand-group" href="/" style="text-decoration:none;">' +
      '<span class="logo-x">X</span>' +
      '<span class="brand">Empório <b>dos Bots</b></span>' +
      "</a>" +
      '<nav>' + navHtml + '</nav>' +
      '<div id="topbar-account"></div>' +
      "</div>";
    renderAccountArea();
  }

  async function renderAccountArea() {
    const el = document.getElementById("topbar-account");
    if (!el) return;
    const user = await window.EmporioAuth.me();
    if (user) {
      const name = user.global_name || user.username || "Jogador";
      el.innerHTML =
        '<div style="display:flex; align-items:center; gap:10px;">' +
        '<span style="color:var(--text-dim); font-size:13px;">' + name + "</span>" +
        '<button class="btn secondary" id="btn-logout" style="padding:7px 14px; font-size:12.5px;">Sair</button>' +
        "</div>";
      document.getElementById("btn-logout").addEventListener("click", function () {
        window.EmporioAuth.logout();
      });
    } else {
      el.innerHTML = '<a class="btn" href="/login/">Entrar / Criar conta</a>';
    }
  }

  window.EmporioShell = { renderTopbar: renderTopbar };
})();
