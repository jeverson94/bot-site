# Empório dos Bots: site

Site estático (HTML, CSS e JS sem build) do Empório dos Bots, publicado pelo deploy automático do Hostinger a partir da branch `main`, na pasta `public_html` de `seagreen-rat-589164.hostingersite.com`.

## De onde vêm os dados

- **Backend do Empório** (`eventsite.py`, na VM): login com Discord (`/auth/discord/*`, `/api/me`) e a biblioteca de builds (`/api/public/builds`). Endereço em `assets/js/config.js`.
- **`api/albion.php`**: proxy da API pública do Albion (batalhas, busca de guildas, status dos servidores), que não aceita chamadas direto do navegador. Só repassa rotas fixas, valida os parâmetros e guarda cache em `api/cache/` (fora do git, bloqueado por `.htaccess`).
- **Albion Data Project**: preços do mercado, chamado direto do navegador.
- **`assets/data/`**: catálogo de itens e habilidades em PT-BR (`itens.json`), grafo do mapa (`mapa.json`) e tabelas de temporada (`temporada.json`), gerados a partir do ao-bin-dumps. `clipes.json` é a lista de clipes publicados, editada à mão.

## O que fica no navegador

Composições, eventos, reviews de vídeo e placares dos jogos ficam no `localStorage` de quem usa, e são compartilhados por link (o conteúdo vai codificado depois do `#`).

## Rotas

As rotas dinâmicas (`/builds/<id>`, `/batalhas/<id>`, `/clipes/<id>`, `/composicoes/ver/`) e os redirecionamentos estão no `.htaccess`.

## Painel (/painel/)

Barra lateral em `assets/js/painel.js`. Perfil, Builds (lista e oficina), Guildas, Presença, Maestria e Configurações.
As builds da oficina ficam no `localStorage` (`emp_builds_v1`, ver `assets/js/minhasbuilds.js`) no mesmo formato de
equipamento da biblioteca pública, mais as habilidades escolhidas em `sp`. O link `/builds/ver/#...` abre a build na
página de detalhe. O tooltip das habilidades está em `assets/js/habilidades.js`.
