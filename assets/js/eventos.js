// Eventos criados no site: guardados no navegador e compartilhados por link
// (o evento vai inteiro no endereço, depois do #).
(function () {
  const KEY = "emp_eventos";

  function list() { try { return JSON.parse(localStorage.getItem(KEY) || "[]"); } catch (e) { return []; } }
  function put(ev) {
    const all = list().filter(function (x) { return x.id !== ev.id; });
    all.unshift(ev);
    try { localStorage.setItem(KEY, JSON.stringify(all.slice(0, 60))); } catch (e) {}
  }
  function remove(id) {
    try { localStorage.setItem(KEY, JSON.stringify(list().filter(function (x) { return x.id !== id; }))); } catch (e) {}
  }
  function encode(ev) {
    return btoa(unescape(encodeURIComponent(JSON.stringify(ev)))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }
  function decode(s) {
    return JSON.parse(decodeURIComponent(escape(atob(s.replace(/-/g, "+").replace(/_/g, "/")))));
  }
  function link(ev) { return location.origin + "/eventos/#" + encode(ev); }

  // Texto pronto para colar no Discord. <t:...:F> e <t:...:R> fazem o
  // Discord mostrar o horário no fuso de quem lê.
  function discordText(ev) {
    const unix = Math.floor(ev.at / 1000);
    let t = "**" + ev.titulo + "**" + (ev.conteudo ? " · " + ev.conteudo : "") + "\n";
    t += "Quando: <t:" + unix + ":F> (<t:" + unix + ":R>)\n";
    if (ev.local) t += "Ponto de encontro: " + ev.local + "\n";
    if (ev.ip) t += "IP mínimo: " + ev.ip + "\n";
    if (ev.vagas) t += "Vagas: " + ev.vagas + "\n";
    if (ev.comp) t += "Comp: " + ev.comp + "\n";
    if (ev.obs) t += "\n" + ev.obs + "\n";
    return t + "\nDetalhes: " + link(ev);
  }

  window.EmpEventos = { list: list, put: put, remove: remove, encode: encode, decode: decode, link: link, discordText: discordText };
})();
