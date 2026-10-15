// Empório dos Bots: funções usadas por várias páginas (formatação, ícones,
// catálogo de itens, servidor escolhido e chamadas ao proxy do Albion).
(function () {
  const CFG = window.EMPORIO_CONFIG;
  const REGIONS = [
    { id: "am", label: "Américas" },
    { id: "eu", label: "Europa" },
    { id: "as", label: "Ásia" },
  ];

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function num(n, dec) {
    n = Number(n) || 0;
    const abs = Math.abs(n);
    const d = dec == null ? 1 : dec;
    const f = function (v, suf) {
      return v.toLocaleString("pt-BR", { maximumFractionDigits: v < 10 ? d : 0 }) + suf;
    };
    if (abs >= 1e9) return f(n / 1e9, "B");
    if (abs >= 1e6) return f(n / 1e6, "M");
    if (abs >= 1e4) return f(n / 1e3, "K");
    return n.toLocaleString("pt-BR");
  }

  function int(n) { return (Number(n) || 0).toLocaleString("pt-BR"); }

  function ago(iso) {
    const t = typeof iso === "number" ? iso : Date.parse(iso);
    if (!t) return "";
    const s = Math.round((Date.now() - t) / 1000);
    if (s < 60) return "agora";
    if (s < 3600) return "há " + Math.round(s / 60) + " min";
    if (s < 86400) return "há " + Math.round(s / 3600) + " h";
    const d = Math.round(s / 86400);
    return "há " + d + (d === 1 ? " dia" : " dias");
  }

  function dateTime(iso) {
    const d = new Date(iso);
    return d.toLocaleString("pt-BR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
  }

  function duration(sec) {
    sec = Math.max(0, Math.round(sec));
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    if (h) return h + " h " + m + " min";
    if (m) return m + " min " + (s ? s + " s" : "");
    return s + " s";
  }

  function icon(id, quality) {
    return CFG.RENDER_BASE + "/" + encodeURIComponent(id) + ".png?count=1&quality=" + (quality || 1) + "&size=128";
  }

  function spellIcon(spellId) {
    return "https://render.albiononline.com/v1/spell/" + encodeURIComponent(spellId) + ".png";
  }

  let itemsPromise = null;
  function items() {
    if (!itemsPromise) {
      itemsPromise = fetch("/assets/data/itens.json")
        .then(function (r) { return r.json(); })
        .catch(function () { return { items: {}, spells: {} }; });
    }
    return itemsPromise;
  }

  function baseId(id) { return String(id || "").replace(/@\d+$/, ""); }
  function plainId(id) { return baseId(id).replace(/^T\d_/, ""); }

  function tierOf(id) {
    const m = /^T(\d+)_/.exec(id || "");
    const e = /@(\d+)$/.exec(id || "");
    return m ? m[1] + "." + (e ? e[1] : "0") : "";
  }

  // Nome em PT-BR. O catálogo só tem os itens equipáveis T4 a T8; para os
  // demais procura o mesmo item em outro tier antes de desistir.
  function itemName(DB, id) {
    const b = baseId(id);
    if (DB.items[b]) return DB.items[b].n;
    const p = plainId(id);
    for (let t = 8; t >= 1; t--) {
      const it = DB.items["T" + t + "_" + p];
      if (it) return it.n.replace(TIER_SUFFIX, "");
    }
    return p.replace(/_/g, " ").toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  const TIER_SUFFIX = / (do Grão-mestre|do Ancião|do Mestre|do Perito|do Adepto|do Iniciante|do Novato|do Calouro)$/;
  // Nome sem o sufixo do tier ("Capuz de Assassino"), para listas que juntam todos os tiers.
  function itemBaseName(DB, id) { return itemName(DB, id).replace(TIER_SUFFIX, ""); }

  function getRegion() {
    try { return localStorage.getItem("emp_region") || "am"; } catch (e) { return "am"; }
  }
  function setRegion(r) {
    try { localStorage.setItem("emp_region", r); } catch (e) {}
  }

  function regionTabs(el, onChange) {
    function draw() {
      const cur = getRegion();
      el.innerHTML = REGIONS.map(function (r) {
        return '<button type="button" class="tab' + (r.id === cur ? " on" : "") + '" data-r="' + r.id + '">' + r.label + "</button>";
      }).join("");
    }
    el.classList.add("tabs");
    draw();
    el.addEventListener("click", function (e) {
      const b = e.target.closest("[data-r]");
      if (!b) return;
      setRegion(b.dataset.r);
      draw();
      onChange(b.dataset.r);
    });
  }

  async function albion(route, params) {
    const q = new URLSearchParams(Object.assign({ r: route }, params || {}));
    const resp = await fetch(CFG.ALBION_PROXY + "?" + q.toString());
    let data = null;
    try { data = await resp.json(); } catch (e) {}
    if (!resp.ok) throw new Error((data && data.erro) || "Falha ao falar com a API do Albion");
    return data;
  }

  function loading(msg) {
    return '<div class="state"><span class="spin"></span>' + esc(msg || "Carregando...") + "</div>";
  }
  function emptyState(msg) {
    return '<div class="state">' + msg + "</div>";
  }

  window.Emp = {
    REGIONS: REGIONS, esc: esc, num: num, int: int, ago: ago, dateTime: dateTime, duration: duration,
    icon: icon, spellIcon: spellIcon, items: items, itemName: itemName, itemBaseName: itemBaseName, baseId: baseId, plainId: plainId,
    tierOf: tierOf, getRegion: getRegion, setRegion: setRegion, regionTabs: regionTabs, albion: albion,
    loading: loading, emptyState: emptyState,
  };
})();
