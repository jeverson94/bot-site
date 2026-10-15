// Empório dos Bots — configuração compartilhada
// API_BASE é o backend real (eventsite.py, rodando na VM) que já cuida de
// login com Discord, composições, eventos e da biblioteca de builds. Esse
// site estático só chama ele via fetch - nenhuma lógica de conta/associação
// fica duplicada aqui.
window.EMPORIO_CONFIG = {
  API_BASE: "https://146-235-57-112.sslip.io",
  RENDER_BASE: "https://render.albiononline.com/v1/item",
};
