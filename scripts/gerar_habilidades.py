import json, re
from habilidades_resolver import SP, describe, num, fmt
cat = json.load(open('../assets/data/itens.json'))['spells']
out = {}
for k in cat:
    d, bad = describe(k)
    e = SP[k]
    st = []
    if e.tag == 'activespell':
        if e.get('energyusage') not in (None, '0', '0.0'):
            st.append(['Energia', fmt(e.get('energyusage'), 'energyusage', e, '')])
        st.append(['Conjuração', num(e.get('castingtime', 0) or 0) + 's'])
        ch = e.find('channelingspell')
        if ch is not None:
            ii = float(ch.get('initialeffectinterval', ch.get('effectinterval', 0)) or 0); ei = float(ch.get('effectinterval', 0) or 0); n = int(ch.get('effectcount', 1) or 1)
            st.append(['Canalização', num(ii + ei * (n - 1)) + 's'])
        if float(e.get('castrange', 0) or 0) > 0: st.append(['Alcance', num(e.get('castrange')) + 'm'])
        if float(e.get('recastdelay', 0) or 0) > 0: st.append(['Recarga', num(e.get('recastdelay')) + 's'])
    if d:
        # A tradução do jogo às vezes inverte a ordem: "{0}raio de" vira "raio de {0}".
        d = re.sub(r'(\d+(?:\.\d+)?)m? ?raio de ?\[/other\]', r'raio de \1m[/other]', d)
        d = re.sub(r'(\d(?:\.\d+)?m)(?=[a-zà-ú])', r'\1 ', d)
        # "0.19 da sua Energia Máxima" é fração: mostra como porcentagem.
        d = re.sub(r'\[(\w+)\](0\.\d+)\[/\1\](\s+d[aoe]s?\s+(?:sua|seu|\[))',
                   lambda m: '[%s]%s%%[/%s]%s' % (m.group(1), num(float(m.group(2)) * 100), m.group(1), m.group(3)), d)
        d = re.sub(r'[ \t]+\n', '\n', d).strip()
    out[k] = {'d': d or '', 's': st}
json.dump(out, open('../assets/data/habilidades.json', 'w'), ensure_ascii=False, separators=(',', ':'))
import os; print(os.path.getsize('../assets/data/habilidades.json'), sum(1 for v in out.values() if not v['d']), 'sem texto')
print(json.dumps(out['LEGBREAKER'], ensure_ascii=False)[:400])
