# Scripts de dados

Geram `assets/data/habilidades.json` (descrição e números de cada habilidade, em PT-BR).

1. Baixe do repositório `ao-data/ao-bin-dumps` os arquivos `spells.xml` e `localization.xml`.
2. Extraia os textos PT-BR das habilidades para `loc_spells_ptbr.json` (chaves `@SPELLS_*`).
3. Coloque `spells.xml` e `loc_spells_ptbr.json` nesta pasta (`scripts/`) e rode `python3 gerar_habilidades.py` daqui.

Os ícones em `assets/img/spells/` vêm de `https://render.albiononline.com/v1/spell/<NOME>.png?size=64`, convertidos para WebP.
