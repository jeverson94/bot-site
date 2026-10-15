// Tooltip das habilidades: qualquer elemento com data-sp="ID_DA_HABILIDADE"
// (e opcionalmente data-k="Q", "P1"...) mostra o cartão ao passar o mouse
// ou tocar. Textos e números vêm de assets/data/habilidades.json, gerado a
// partir dos arquivos do jogo (spells.xml + localização PT-BR).
(function () {
  let HAB = null;
  let NAMES = null;
  const ready = Promise.all([
    fetch("/assets/data/habilidades.json?v=20261015d").then(function (r) { return r.json(); }).catch(function () { return {}; }),
    Emp.items(),
  ]).then(function (res) { HAB = res[0]; NAMES = res[1].spells || {}; });

  const KEY_TXT = { P1: "Passiva", P2: "Passiva", P3: "Passiva" };
  const tip = document.createElement("div");
  tip.className = "sp-tip";
  document.addEventListener("DOMContentLoaded", function () { document.body.appendChild(tip); });
  if (document.body) document.body.appendChild(tip);

  function markup(t) {
    return Emp.esc(t)
      .replace(/\[#\]\s*/g, "• ")
      .replace(/\[(dmg|heal|buff|debuff|cc|mobility|other)\]/g, '<span class="t-$1">')
      .replace(/\[\/(dmg|heal|buff|debuff|cc|mobility|other)\]/g, "</span>")
      .replace(/\[\/?[\w#]*\]/g, "")
      .replace(/\n/g, "<br>");
  }
  function name(id) { return NAMES && NAMES[id] ? NAMES[id].n : id; }
  function keyLabel(k) { return k ? (KEY_TXT[k] || "Tecla " + k) : ""; }

  let tipFor = null;
  function show(el) {
    tipFor = el;
    if (!HAB) { ready.then(function () { if (tipFor === el) show(el); }); }
    const id = el.dataset.sp;
    const h = (HAB && HAB[id]) || { d: "", s: [] };
    tip.innerHTML = '<div class="hd"><img src="' + Emp.spellIcon(id) + '" alt=""><div><b>' + Emp.esc(name(id)) + "</b><small>" + Emp.esc(keyLabel(el.dataset.k)) + "</small></div></div>" +
      (h.s.length ? '<div class="st">' + h.s.map(function (x) { return "<span>" + Emp.esc(x[0]) + "<b>" + Emp.esc(x[1]) + "</b></span>"; }).join("") + "</div>" : '<div style="height:10px"></div>') +
      '<div class="ds">' + (HAB ? (markup(h.d) || "Sem descrição.") : "Carregando...") + "</div>";
    tip.classList.add("show");
    const r = el.getBoundingClientRect(), tw = tip.offsetWidth, th = tip.offsetHeight;
    let x = r.left + r.width / 2 - tw / 2, y = r.bottom + 10;
    x = Math.max(12, Math.min(x, window.innerWidth - tw - 12));
    if (y + th > window.innerHeight - 12) y = Math.max(12, r.top - th - 10);
    tip.style.left = x + "px";
    tip.style.top = y + "px";
  }
  function hide() { tip.classList.remove("show"); tipFor = null; }

  document.addEventListener("mouseover", function (e) { const el = e.target.closest("[data-sp]"); if (el) show(el); });
  document.addEventListener("mouseout", function (e) { const el = e.target.closest("[data-sp]"); if (el && !el.contains(e.relatedTarget)) hide(); });
  // No toque: o primeiro toque mostra, o segundo esconde. Elementos com
  // data-sp-click (seleção de habilidade na oficina) tratam o clique sozinhos.
  document.addEventListener("click", function (e) {
    const el = e.target.closest("[data-sp]");
    if (!el) { hide(); return; }
    if (el.hasAttribute("data-sp-click")) { show(el); return; }
    if (tipFor === el) hide(); else show(el);
  });
  window.addEventListener("scroll", hide, { passive: true });

  window.EmpHab = { ready: ready, show: show, hide: hide, name: name, keyLabel: keyLabel };
})();
