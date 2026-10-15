// Empório dos Bots: configuração compartilhada.
// API_BASE é o backend (eventsite.py, na VM) que cuida do login com Discord e
// da biblioteca de builds. ALBION_PROXY é o arquivo PHP deste site que repassa
// a API pública do Albion (ela não aceita chamadas direto do navegador).
window.EMPORIO_CONFIG = {
  API_BASE: "https://146-235-57-112.sslip.io",
  RENDER_BASE: "https://render.albiononline.com/v1/item",
  ALBION_PROXY: "/api/albion.php",
  // Link de convite do servidor do Discord, usado na página de contato.
  DISCORD_INVITE: "",
};
