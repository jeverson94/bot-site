// Empório dos Bots: sessão (Discord OAuth).
// O backend (eventsite.py) redireciona pra /auth/discord/login, o Discord
// manda de volta pra /auth/discord/callback LÁ no backend, que por sua vez
// redireciona o navegador pra ESSE site em /auth/callback?token=.... Essa
// página salva o token (localStorage) e manda o usuário pra home.
(function () {
  const CFG = window.EMPORIO_CONFIG;
  const TOKEN_KEY = "emporio_session_token";

  function getToken() {
    try { return localStorage.getItem(TOKEN_KEY); } catch (e) { return null; }
  }
  function setToken(tok) {
    try { localStorage.setItem(TOKEN_KEY, tok); } catch (e) {}
  }
  function clearToken() {
    try { localStorage.removeItem(TOKEN_KEY); } catch (e) {}
  }
  function loginUrl() {
    return CFG.API_BASE + "/auth/discord/login";
  }
  async function apiFetch(path, opts) {
    opts = opts || {};
    const headers = Object.assign({}, opts.headers || {});
    const tok = getToken();
    if (tok) headers["Authorization"] = "Bearer " + tok;
    const resp = await fetch(CFG.API_BASE + path, Object.assign({}, opts, { headers }));
    return resp;
  }
  async function me() {
    const tok = getToken();
    if (!tok) return null;
    try {
      const resp = await apiFetch("/api/me");
      if (!resp.ok) { if (resp.status === 401) clearToken(); return null; }
      return await resp.json();
    } catch (e) { return null; }
  }
  async function logout() {
    try { await apiFetch("/auth/logout", { method: "POST" }); } catch (e) {}
    clearToken();
    window.location.href = "/";
  }

  window.EmporioAuth = { getToken, setToken, clearToken, loginUrl, apiFetch, me, logout };
})();
