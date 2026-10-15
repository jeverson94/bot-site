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
    { label: "Guilda", items: [{ href: "/painel/guildas/", label: "Guildas", ic: "users" }] },
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

  function render(active) {
    const el = document.getElementById("painel-side");
    if (!el) return;
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
  }

  // Busca de guilda ou jogador na API do Albion, com lista de sugestões.
  // kind: "guilds" ou "players". onPick recebe {id, n, extra}.
  function picker(input, box, kind, onPick) {
    let timer = null;
    box.classList.add("pick-opts");
    input.addEventListener("input", function () {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { box.classList.remove("open"); return; }
      timer = setTimeout(async function () {
        try {
          const r = await Emp.albion("search", { s: Emp.getRegion(), q: q });
          const list = (r[kind] || []).slice(0, 12);
          box.innerHTML = list.length ? list.map(function (x) {
            const extra = kind === "guilds" ? (x.AllianceName ? "[" + x.AllianceName + "]" : "") : (x.GuildName || "Sem guilda");
            return '<div data-id="' + Emp.esc(x.Id) + '" data-n="' + Emp.esc(x.Name) + '" data-x="' + Emp.esc(extra) + '"><b>' + Emp.esc(x.Name) + '</b> <span class="muted">' + Emp.esc(extra) + "</span></div>";
          }).join("") : '<div class="muted">Nada encontrado com esse nome neste servidor.</div>';
          box.classList.add("open");
        } catch (e) { box.innerHTML = '<div class="muted">' + Emp.esc(e.message) + "</div>"; box.classList.add("open"); }
      }, 350);
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

  window.EmporioPainel = { render: render, picker: picker, getLink: getLink, setLink: setLink };
})();
