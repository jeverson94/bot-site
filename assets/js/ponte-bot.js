// Ponte entre o site e o bot do Discord.
// Os botões abrem o painel do bot (eventsite.py, na VM) já logado na guilda.
// O que for criado ou editado lá grava nos mesmos arquivos que o bot usa,
// então o ping aparece no servidor do Discord na hora.
(function () {
  const CFG = window.EMPORIO_CONFIG;
  const INVITE = "https://discord.com/oauth2/authorize?client_id=1536893845898264657&permissions=8&scope=bot%20applications.commands";

  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }

  function ponte(guildId, next) {
    return CFG.API_BASE + "/legacy/enter?token=" + encodeURIComponent(window.EmporioAuth.getToken() || "") +
      "&guild_id=" + encodeURIComponent(guildId) + "&next=" + encodeURIComponent(next);
  }

  function icone(g) {
    if (g.icon) return '<img src="https://cdn.discordapp.com/icons/' + g.id + "/" + g.icon + '.png?size=64" alt="" style="width:100%;height:100%">';
    return (g.name || "?").trim().charAt(0).toUpperCase();
  }

  async function render(el) {
    if (!el) return;
    const base = '<div class="card" style="margin-bottom:22px;border-color:rgba(226,188,98,.35)">';
    const user = await window.EmporioAuth.me();
    if (!user) {
      el.innerHTML = base + '<h2 style="margin:0 0 6px">Postar pelo bot no Discord</h2>' +
        '<p class="muted" style="margin:0 0 14px">Entre com o Discord e o bot posta o evento direto no canal do seu servidor, com vagas, composição e DM da build pra cada membro.</p>' +
        '<a class="btn" href="' + window.EmporioAuth.loginUrl() + '">Entrar com Discord</a></div>';
      return;
    }
    const guilds = (user.admin_guilds || []).filter(function (g) { return !g.personal; });
    if (!guilds.length) {
      el.innerHTML = base + '<h2 style="margin:0 0 6px">Postar pelo bot no Discord</h2>' +
        '<p class="muted" style="margin:0 0 14px">Nenhum servidor seu tem o bot ainda. Você precisa ser administrador do servidor. Depois de adicionar o bot, saia e entre de novo no site.</p>' +
        '<a class="btn" href="' + INVITE + '" target="_blank" rel="noopener">Adicionar o bot ao servidor</a></div>';
      return;
    }
    el.innerHTML = base + '<h2 style="margin:0 0 6px">Postar pelo bot no Discord</h2>' +
      '<p class="muted" style="margin:0 0 14px">Criar, editar ou encerrar por aqui atualiza a mensagem no servidor na hora.</p>' +
      guilds.map(function (g) {
        return '<div class="row wrap" style="gap:10px;padding:10px 0;border-top:1px solid rgba(255,255,255,.05)">' +
          '<span style="width:34px;height:34px;border-radius:10px;overflow:hidden;background:var(--gold-soft);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:800">' + icone(g) + "</span>" +
          '<b style="flex:1;min-width:120px">' + esc(g.name) + "</b>" +
          '<a class="btn" href="' + ponte(g.id, "/pings-painel/novo") + '">Criar evento no Discord</a>' +
          '<a class="btn secondary" href="' + ponte(g.id, "/pings-painel") + '">Eventos ativos</a>' +
          '<a class="btn secondary" href="' + ponte(g.id, "/") + '">Composições</a></div>';
      }).join("") + "</div>";
  }

  window.EmporioPonteBot = { render: render, INVITE: INVITE };
  document.addEventListener("DOMContentLoaded", function () { render(document.getElementById("ponte-bot")); });
  if (document.readyState !== "loading") render(document.getElementById("ponte-bot"));
})();
