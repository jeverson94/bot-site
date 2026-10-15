# Empório dos Bots — site

Site estático (HTML/CSS/JS puro) do Empório dos Bots, publicado via deploy automático do Hostinger a partir deste branch (`main`), direto na pasta `public_html` de `seagreen-rat-589164.hostingersite.com`.

Fala com o backend (`eventsite.py`, rodando na VM Oracle) via `fetch` para tudo que é dado dinâmico: login (Discord OAuth), composições, eventos, biblioteca de builds. Não tem build step (sem Node/webpack) — é só HTML/CSS/JS direto, pra funcionar na hospedagem compartilhada do Hostinger sem complicação.

Em construção — ver histórico de commits.
