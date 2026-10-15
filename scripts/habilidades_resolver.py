import xml.etree.ElementTree as ET, json, re, collections, sys
root = ET.parse('spells.xml').getroot()
SP = {e.get('uniquename'): e for e in root if e.get('uniquename')}
LOC = json.load(open('loc_spells_ptbr.json'))
ALIAS = {'channeling': ['channelingspell']}
TIME = {'time', 'interval', 'duration', 'recastdelay', 'castingtime', 'totalduration', 'timewindow', 'effectinterval', 'hitdelay', 'timeoutcooldown', 'initialinterval', 'connectiontime', 'movetimetocast'}
DIST = {'innerradius', 'outerradius', 'width_end', 'castrange', 'effectarearadius', 'radius_max', 'radius_start', 'radius_end', 'distance', 'auraradius', 'removeradius', 'aggroradius', 'maxdistance', 'distance_max', 'radius', 'height_start', 'height_max', 'width_start', 'expireafterdistance', 'castrangetolerance'}

def find_child(el, name, idx):
    names = [n.lower() for n in [name] + ALIAS.get(name, [])]
    if name.lower() == 'ifcharge':
        ic = next((c for c in el.iter() if c.tag.lower() == 'ifcharge'), None)
        kids = list(ic) if ic is not None else []
        return kids[idx] if len(kids) > idx else None
    kids = [c for c in el if c.tag.lower() in names]
    if len(kids) > idx: return kids[idx]
    desc = [c for c in el.iter() if c is not el and c.tag.lower() in names]
    return desc[idx] if len(desc) > idx else None

def computed(el, attr):
    base = re.sub(r'_(max|start|end)$', '', attr)
    if base != attr:
        if el.get(base) is not None: return el.get(base)
        for c in el.iter():
            if c.get(attr) is not None: return c.get(attr)
            if c.get(base) is not None: return c.get(base)
    if attr == 'energygained' and el.get('energyperhit'): return el.get('energyperhit')
    if el.tag == 'arc' and attr.startswith('width') and el.get('outerradius') and el.get('innerradius'):
        return float(el.get('outerradius')) - float(el.get('innerradius'))
    if attr in ('duration', 'time') and el.tag == 'knockback' and el.get('distance') and el.get('movespeed'):
        return float(el.get('distance')) / float(el.get('movespeed'))
    if attr in ('time', 'totalduration') and el.get('count') and el.get('interval'):
        return float(el.get('initialinterval', 0) or 0) + float(el.get('interval')) * (int(el.get('count')) - (1 if el.get('initialinterval') is not None else 0))
    if attr == 'totalchange' and el.get('count') and el.get('change'):
        return float(el.get('change')) * int(el.get('count'))
    if attr == 'totalduration':
        if el.tag == 'channelingspell':
            ii = float(el.get('initialeffectinterval', el.get('effectinterval', 0))); ei = float(el.get('effectinterval', 0)); n = int(el.get('effectcount', 1))
            return ii + ei * (n - 1)
        if el.get('time'): return float(el.get('time'))
        if el.get('interval') and el.get('count'): return float(el.get('interval')) * int(el.get('count'))
    if attr == 'totalchange':
        ch = el.get('change')
        if ch is not None:
            t = float(el.get('time', 0)); iv = float(el.get('interval', 1) or 1)
            return float(ch) * max(1, round(t / iv))
    return None

def resolve(spell, tag):
    v, info = resolve_raw(spell, tag)
    if v is not None: return v, info
    body = tag.strip('$')
    tries = []
    parts = body.split('.')
    # 1) último pedaço em minúsculas que não é atributo (ex.: ".times"): ignora
    if len(parts) > 1 and parts[-1].islower(): tries.append('.'.join(parts[:-1]))
    # 2) índice além do que existe: usa o primeiro
    tries.append(re.sub(r'\[[1-9]\d*\](?=[^\[]*$)', '[0]', body))
    # 3) feitiço "_CHANNELING" que não existe mais: usa a canalização do próprio feitiço
    m = re.match(r'(\w+?)_CHANNEL(?:ING)?\.(.*)', body)
    if m and m.group(1) in SP:
        tries.append(m.group(1) + '.channeling[0].' + m.group(2))
    for t in tries:
        pre = '$$' if tag.startswith('$$') else '$'
        v, info = resolve_raw(spell, pre + t + '$')
        if v is not None: return v, info
    return None, None

def resolve_raw(spell, tag):
    if not tag.startswith('$'):
        return tag, ('literal', None)
    body = tag.strip('$')
    parts = body.split('.')
    if tag.startswith('$$'):
        el = SP.get(parts[0]); parts = parts[1:]
    else:
        el = spell
    if el is None: return None, None
    for p in parts[:-1]:
        m = re.match(r'(\w+)\[(\d+)\]$', p) or re.match(r'(\w+)$', p)
        if not m: return None, None
        el = find_child(el, m.group(1), int(m.group(2)) if m.lastindex == 2 else 0)
        if el is None: return None, None
    attr = parts[-1]
    v = el.get(attr)
    if v is None: v = computed(el, attr)
    return v, (attr, el)

def num(x):
    x = float(x)
    if abs(x - round(x)) < 1e-9: return str(int(round(x)))
    return ('%.2f' % x).rstrip('0').rstrip('.')

def fmt(v, attr, el, after):
    if attr == 'literal': return str(v)
    # Curvas de animação ("A 0:4;0.8:4;0.81:0"): usa o maior valor da curva.
    if isinstance(v, str) and re.match(r'^\s*[A-Z]{1,2}\s*-?[\d.]+\s*:', v):
        body = re.sub(r'^\s*[A-Z]{1,2}', '', v)
        vals = [float(p.split(':')[1]) for p in body.split(';') if ':' in p and p.split(':')[1].strip()]
        v = max(vals) if vals else 0
    try: f = float(v)
    except Exception: return str(v)
    pct_after = after.lstrip().startswith('%')
    if attr in TIME: return num(f) + 's'
    if attr in DIST: return num(f) + 'm'
    # Frações entre 0 e 1 no arquivo do jogo são porcentagens (0.7 = 70%).
    if 0 < abs(f) < 1 and attr not in ('change', 'totalchange'):
        return num(abs(f) * 100) + ('' if pct_after else '%')
    if attr in ('change', 'totalchange', 'damageabsorbed', 'amount'): return num(abs(f))
    if attr == 'energyusage' and el is not None and el.get('energyusageisrelative') == 'true': return num(f * 100) + '%'
    if attr in ('value', 'factor', 'attributestealfactor', 'percentage', 'amountpercent') or attr.endswith('factor') or attr.endswith('damage') or attr.endswith('healing'):
        if abs(f) <= 3 and not float(f).is_integer():
            return num(abs(f) * 100) + ('' if pct_after else '%')
        if abs(f) <= 3 and attr == 'value' and el is not None and el.tag in ('buffovertime', 'buff'):
            return num(abs(f) * 100) + ('' if pct_after else '%')
    return num(abs(f)) if attr not in ('mintier', 'maxtier') else num(f)

def describe(key):
    e = SP[key]
    dk = e.get('descriptionlocatag') or '@SPELLS_' + key + '_DESC'
    tpl = LOC.get(dk)
    if not tpl: return None, ['sem texto ' + dk]
    refs = []
    lr = e.find('locareferences')
    if lr is not None and lr.find('description') is not None:
        refs = [r.get('tag') for r in lr.find('description').findall('locareference')]
    bad = []
    def sub(m):
        i = int(m.group(1))
        if i >= len(refs): bad.append('sem ref %d' % i); return '?'
        v, info = resolve(e, refs[i])
        if v is None: bad.append('nao resolvido ' + refs[i]); return '?'
        after = tpl[m.end():m.end() + 2]
        return fmt(v, info[0], info[1], after)
    out = re.sub(r'\{(\d+)\}', sub, tpl)
    # Referências escritas direto no texto: $caminho$ ou $$OUTRA.caminho$
    def inline(m):
        t = m.group(0)
        v, info = resolve(e, t)
        if v is None: bad.append('inline ' + t); return '?'
        return fmt(v, info[0], info[1], out[m.end():m.end() + 2])
    out = re.sub(r'\$\$?[A-Za-z_][\w\[\]\.]*\$', inline, out)
    return out, bad

def stats(key):
    e = SP[key]
    st = {}
    if e.tag != 'activespell': return st
    if e.get('energyusage') is not None:
        st['energia'] = fmt(e.get('energyusage'), 'energyusage', e, '')
    st['conjuracao'] = num(e.get('castingtime', 0)) + 's'
    if float(e.get('castrange', 0) or 0) > 0: st['alcance'] = num(e.get('castrange')) + 'm'
    st['recarga'] = num(e.get('recastdelay', 0)) + 's'
    return st

if __name__ == '__main__':
    cat = json.load(open('../assets/data/itens.json'))['spells']
    probs = collections.Counter(); okc = 0
    for k in cat:
        d, bad = describe(k)
        if not bad: okc += 1
        for b in bad: probs[re.sub(r'\[\d+\]', '[]', re.sub(r'\$\$\w+\.', '$$X.', b))] += 1
    print('ok', okc, 'de', len(cat)); print(probs.most_common(25))
    for k in sys.argv[1:]:
        print('\n##', k, cat[k]['n'], stats(k)); print(describe(k))
