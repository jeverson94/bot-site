// Builds do jogador, guardadas neste navegador. O formato do equipamento é o
// mesmo da biblioteca pública ({slot: {item, swaps}}), com as habilidades
// escolhidas em "sp" ({slot: {Q: "ID", W: "ID", P1: "ID"}}).
(function () {
  const KEY = "emp_builds_v1";

  const SLOTS = [
    { k: "bag", n: "Bolsa", s: "bag" }, { k: "head", n: "Cabeça", s: "head" }, { k: "cape", n: "Capa", s: "cape" },
    { k: "mainhand", n: "Mão principal", s: "mainhand" }, { k: "chest", n: "Peito", s: "chest" }, { k: "offhand", n: "Mão secundária", s: "offhand" },
    { k: "potion", n: "Poção", s: "potion" }, { k: "shoes", n: "Sapatos", s: "shoes" }, { k: "food", n: "Comida", s: "food" },
    { k: "mount", n: "Montaria", s: "mount" },
  ];
  const ROLES = [
    { k: "caller", n: "Caller", c: "#b48ef0" }, { k: "dtank", n: "DTank", c: "#5b8def" }, { k: "offtank", n: "OffTank", c: "#7fb2ff" },
    { k: "suporte", n: "Suporte", c: "#e8d36a" }, { k: "healer", n: "Healer", c: "#6fcf8e" }, { k: "dps", n: "DPS", c: "#e05b5b" },
    { k: "battlemount", n: "BattleMount", c: "#f0a35b" },
  ];
  const CONTENT = ["ZvZ", "Pequena escala", "Ganking", "Guerra de facções", "Hellgate", "Liga de Cristal", "Estradas de Avalon", "PvE", "Coleta"];

  function list() { try { return JSON.parse(localStorage.getItem(KEY) || "[]"); } catch (e) { return []; } }
  function saveAll(all) { try { localStorage.setItem(KEY, JSON.stringify(all)); return true; } catch (e) { return false; } }
  function get(id) { return list().find(function (b) { return b.id === id; }) || null; }
  function put(b) {
    b.atualizado = Date.now();
    const all = list();
    const i = all.findIndex(function (x) { return x.id === b.id; });
    if (i >= 0) all[i] = b; else all.unshift(b);
    return saveAll(all);
  }
  function remove(id) { return saveAll(list().filter(function (b) { return b.id !== id; })); }
  function blank() {
    return { id: "b" + Date.now().toString(36) + Math.random().toString(36).slice(2, 6), nome: "", papel: "", conteudo: [], notas: "", equip: {}, sp: {}, atualizado: Date.now() };
  }
  function encode(b) {
    const slim = { nome: b.nome, papel: b.papel, conteudo: b.conteudo, notas: b.notas, equip: b.equip, sp: b.sp };
    return btoa(unescape(encodeURIComponent(JSON.stringify(slim)))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }
  function decode(s) { return JSON.parse(decodeURIComponent(escape(atob(s.replace(/-/g, "+").replace(/_/g, "/"))))); }
  function shareUrl(b) { return location.origin + "/builds/ver/#" + encode(b); }
  function role(k) { return ROLES.find(function (r) { return r.k === k; }) || null; }

  window.MinhasBuilds = { SLOTS: SLOTS, ROLES: ROLES, CONTENT: CONTENT, list: list, saveAll: saveAll, get: get, put: put, remove: remove, blank: blank, encode: encode, decode: decode, shareUrl: shareUrl, role: role };
})();
