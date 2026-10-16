// Gráficos em SVG usados nas páginas de guildas: linha de tendência, horário nobre com seleção e barras por dia.
(function () {
  const NS = "http://www.w3.org/2000/svg";
  let tipEl = null;
  function tip() {
    if (!tipEl) {
      tipEl = document.createElement("div");
      tipEl.className = "gx-tip";
      document.body.appendChild(tipEl);
    }
    return tipEl;
  }
  function showTip(html, ev) {
    const t = tip();
    t.innerHTML = html;
    t.style.display = "block";
    const w = t.offsetWidth, h = t.offsetHeight;
    let x = ev.clientX + 14, y = ev.clientY - h - 10;
    if (x + w > window.innerWidth - 8) x = ev.clientX - w - 14;
    if (y < 8) y = ev.clientY + 16;
    t.style.left = x + "px"; t.style.top = y + "px";
  }
  function hideTip() { if (tipEl) tipEl.style.display = "none"; }
  function el(tag, attrs, parent) {
    const e = document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (parent) parent.appendChild(e);
    return e;
  }
  function svg(box, w, h) {
    box.innerHTML = "";
    const s = el("svg", { viewBox: "0 0 " + w + " " + h, width: "100%", height: h, preserveAspectRatio: "none", class: "gx" });
    box.appendChild(s);
    return s;
  }
  // Curva suave passando pelos pontos (Catmull-Rom convertida em Bézier).
  function smooth(pts) {
    if (pts.length < 2) return "";
    let d = "M" + pts[0][0] + "," + pts[0][1];
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
      const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
      const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
      // Mantém a curva entre os dois pontos, sem passar da linha de base nem do pico.
      const lo = Math.min(p1[1], p2[1]), hi = Math.max(p1[1], p2[1]);
      c1[1] = Math.max(lo, Math.min(hi, c1[1])); c2[1] = Math.max(lo, Math.min(hi, c2[1]));
      d += "C" + c1[0] + "," + c1[1] + " " + c2[0] + "," + c2[1] + " " + p2[0] + "," + p2[1];
    }
    return d;
  }
  // Rótulos do eixo em HTML, para o texto não esticar junto com o SVG.
  function axis(box, labels) {
    const row = document.createElement("div");
    row.className = "gx-axis-row";
    row.innerHTML = labels.map(function (l) { return "<span>" + l + "</span>"; }).join("");
    box.appendChild(row);
  }
  let gid = 0;
  function gradient(s, id, color) {
    const defs = el("defs", {}, s);
    const g = el("linearGradient", { id: id, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
    el("stop", { offset: "0%", "stop-color": color, "stop-opacity": ".38" }, g);
    el("stop", { offset: "100%", "stop-color": color, "stop-opacity": "0" }, g);
  }

  // Linha de tendência com área, destaque no maior valor e dica ao passar o mouse.
  function sparkline(box, values, opt) {
    opt = opt || {};
    const W = 300, H = opt.h || 64, pad = 6;
    const s = svg(box, W, H);
    const max = Math.max(1, Math.max.apply(null, values));
    const n = values.length;
    const xs = function (i) { return n < 2 ? W / 2 : pad + i * (W - pad * 2) / (n - 1); };
    const ys = function (v) { return H - 4 - (v / max) * (H - 18); };
    const pts = values.map(function (v, i) { return [xs(i), ys(v)]; });
    const id = "gx" + (++gid);
    gradient(s, id, "#e2bc62");
    el("line", { x1: 0, x2: W, y1: H - 4, y2: H - 4, stroke: "rgba(255,255,255,.08)", "stroke-width": 1, "vector-effect": "non-scaling-stroke" }, s);
    el("path", { d: smooth(pts) + "L" + pts[n - 1][0] + "," + (H - 4) + "L" + pts[0][0] + "," + (H - 4) + "Z", fill: "url(#" + id + ")" }, s);
    el("path", { d: smooth(pts), fill: "none", stroke: "#e2bc62", "stroke-width": 2, "vector-effect": "non-scaling-stroke", "stroke-linejoin": "round" }, s);
    // Maior valor marcado e rotulado.
    const mi = values.indexOf(Math.max.apply(null, values));
    if (values[mi] > 0) {
      const lab = document.createElement("span");
      lab.className = "gx-peak";
      lab.textContent = values[mi];
      lab.style.left = (pts[mi][0] / W * 100) + "%";
      lab.style.top = (pts[mi][1] - 16) + "px";
      box.appendChild(lab);
      const dot = document.createElement("span");
      dot.className = "gx-dot";
      dot.style.left = (pts[mi][0] / W * 100) + "%";
      dot.style.top = pts[mi][1] + "px";
      box.appendChild(dot);
    }
    const cur = document.createElement("span");
    cur.className = "gx-dot gx-cur";
    box.appendChild(cur);
    box.onmousemove = function (ev) {
      const r = box.getBoundingClientRect();
      const i = Math.max(0, Math.min(n - 1, Math.round(((ev.clientX - r.left) / r.width * W - pad) / ((W - pad * 2) / Math.max(1, n - 1)))));
      cur.style.display = "block";
      cur.style.left = (pts[i][0] / W * 100) + "%";
      cur.style.top = pts[i][1] + "px";
      showTip((opt.label ? opt.label(i) : "") + "<b>" + values[i] + "</b> " + (opt.unit || ""), ev);
    };
    box.onmouseleave = function () { cur.style.display = "none"; hideTip(); };
  }

  // Horário nobre: batalhas por hora UTC, com seleção de faixa arrastando o mouse.
  function hours(box, values, sel, onSelect) {
    const W = 720, H = 110, top = 10, bottom = 2;
    const s = svg(box, W, H);
    const max = Math.max(1, Math.max.apply(null, values));
    const bw = W / 24;
    const xs = function (i) { return i * bw + bw / 2; };
    const ys = function (v) { return H - bottom - (v / max) * (H - bottom - top); };
    const pts = values.map(function (v, i) { return [xs(i), ys(v)]; });
    const id = "gx" + (++gid);
    gradient(s, id, "#e2bc62");
    const band = el("rect", { y: top - 6, height: H - bottom - top + 6, fill: "rgba(226,188,98,.10)", stroke: "rgba(226,188,98,.45)", "stroke-width": 1, "vector-effect": "non-scaling-stroke", rx: 4, style: "display:none" }, s);
    el("line", { x1: 0, x2: W, y1: H - bottom, y2: H - bottom, stroke: "rgba(255,255,255,.1)", "stroke-width": 1, "vector-effect": "non-scaling-stroke" }, s);
    el("path", { d: smooth(pts) + "L" + pts[23][0] + "," + (H - bottom) + "L" + pts[0][0] + "," + (H - bottom) + "Z", fill: "url(#" + id + ")" }, s);
    el("path", { d: smooth(pts), fill: "none", stroke: "#e2bc62", "stroke-width": 2, "vector-effect": "non-scaling-stroke" }, s);
    axis(box, Array.from({ length: 24 }, function (_, i) { return i % 2 ? "" : String(i).padStart(2, "0"); }));
    function paint(a, b) {
      if (a == null || (a === 0 && b === 23)) { band.style.display = "none"; return; }
      band.style.display = "";
      if (a <= b) { band.setAttribute("x", a * bw); band.setAttribute("width", (b - a + 1) * bw); }
      else { band.setAttribute("x", a * bw); band.setAttribute("width", (24 - a) * bw); }
    }
    paint(sel[0], sel[1]);
    let start = null;
    const hourAt = function (ev) { const r = box.getBoundingClientRect(); return Math.max(0, Math.min(23, Math.floor((ev.clientX - r.left) / r.width * 24))); };
    box.onpointerdown = function (ev) { start = hourAt(ev); paint(start, start); box.setPointerCapture(ev.pointerId); };
    box.onpointermove = function (ev) {
      const h = hourAt(ev);
      showTip(String(h).padStart(2, "0") + "h UTC (" + String((h + 21) % 24).padStart(2, "0") + "h em Brasília)<br><b>" + values[h] + "</b> batalhas", ev);
      if (start != null) paint(Math.min(start, h), Math.max(start, h));
    };
    box.onpointerup = function (ev) {
      if (start == null) return;
      const h = hourAt(ev), a = Math.min(start, h), b = Math.max(start, h);
      start = null;
      onSelect(a === b ? [0, 23] : [a, b]);
    };
    box.onpointerleave = hideTip;
  }

  // Barras por dia (ou por categoria), com dica em cada barra.
  function bars(box, values, opt) {
    opt = opt || {};
    const W = 720, H = opt.h || 150, top = 12, bottom = 2;
    const s = svg(box, W, H);
    const max = Math.max(1, Math.max.apply(null, values));
    const n = values.length, bw = W / n, gap = Math.min(4, bw * .25);
    el("line", { x1: 0, x2: W, y1: H - bottom, y2: H - bottom, stroke: "rgba(255,255,255,.1)", "stroke-width": 1, "vector-effect": "non-scaling-stroke" }, s);
    values.forEach(function (v, i) {
      const h = (v / max) * (H - bottom - top);
      const x = i * bw + gap / 2, w = bw - gap;
      if (v > 0) {
        const r = Math.min(4, w / 2, h);
        // Barra com cantos arredondados só em cima, apoiada na base.
        const y = H - bottom - h;
        el("path", { d: "M" + x + "," + (H - bottom) + "V" + (y + r) + "Q" + x + "," + y + " " + (x + r) + "," + y + "H" + (x + w - r) + "Q" + (x + w) + "," + y + " " + (x + w) + "," + (y + r) + "V" + (H - bottom) + "Z", fill: opt.color || "#e2bc62", opacity: ".85" }, s);
      }
      const hit = el("rect", { x: i * bw, y: 0, width: bw, height: H, fill: "transparent" }, s);
      hit.addEventListener("mousemove", function (ev) { showTip((opt.label ? opt.label(i) : "") + "<b>" + (opt.fmt ? opt.fmt(v) : v) + "</b> " + (opt.unit || ""), ev); });
      hit.addEventListener("mouseleave", hideTip);
    });
    if (opt.labels) axis(box, opt.labels);
  }

  window.Graficos = { sparkline: sparkline, hours: hours, bars: bars, hideTip: hideTip };
})();
