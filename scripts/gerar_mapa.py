# Gera assets/data/mapa.json a partir do world.xml do ao-bin-dumps.
# Uso: python3 scripts/gerar_mapa.py caminho/para/world.xml
import sys, os
SRC = sys.argv[1] if len(sys.argv) > 1 else 'world.xml'
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'data', 'mapa.json')
import xml.etree.ElementTree as ET, re, json, collections, math
t = ET.parse(SRC).getroot()
cl = t.find('clusters')
keep = re.compile(r'^(OPENPVP_|SAFEAREA$|STARTAREA|STARTINGCITY|PLAYERCITY_SAFEAREA_0|PLAYERCITY_BLACK_ROYAL$|PLAYERCITY_BLACK_PORTALCITY|PLAYERCITY_BLACK_REST|PLAYERCITY_BLACK$)')
raw = {}
for c in cl.findall('cluster'):
    ty = c.get('type') or ''
    if not keep.match(ty) or not c.get('worldmapposition') or c.get('enabled') != 'true': continue
    raw[c.get('id')] = c

def cat(ty):
    if ty.startswith('OPENPVP_BLACK'): return 'black'
    if ty == 'OPENPVP_RED': return 'red'
    if ty == 'OPENPVP_YELLOW': return 'yellow'
    if ty.startswith('PLAYERCITY') or ty == 'STARTINGCITY': return 'city'
    return 'blue'

MARK = {'Castle', 'Castle_Outpost', 'dungeon_solo', 'dungeon_group', 'dungeon_elite', 'hell_gate', 'hell_corrupted_dungeon', 'hell_dungeon_entrance',
        'hell_hellden_portal', 'Bank', 'Marketplace', 'Harbor', 'smuggler_marketplace', 'smuggler_trader', 'Blackmarket', 'Blackmarket_Bank', 'Arena',
        'Expedition', 'outlands_teleportation_portal', 'IslandMerchant', 'Icon_Gvg_Seasons', 'mist_trader'}
def r1(v): return round(float(v))

ids = sorted(raw)
idx = {k: i for i, k in enumerate(ids)}
zones = []
edges = {}
for cid in ids:
    c = raw[cid]
    f = c.get('file') or ''
    parts = f.split('_')
    tier = int(re.search(r'_T(\d)_', f).group(1)) if re.search(r'_T(\d)_', f) else 0
    q = re.match(r'OUT_Q(\d)', c.get('rareresourcedistribution') or '')
    x, y = map(float, c.get('worldmapposition').split())
    size = float((c.get('size') or '930 930').split()[0])
    marks = []
    mm = c.find('minimapmarkers')
    if mm is not None:
        for m in mm:
            ty = m.get('type') or ''
            if ty in MARK or ty.startswith('Portal_'):
                px, py = m.get('pos').split()
                marks.append([ty, r1(px), r1(py)])
    exits = []
    ex = c.find('exits')
    if ex is not None:
        for e in ex:
            tid = e.get('targetid').split('@')[-1]
            tt = e.get('targettype')
            px, py = e.get('pos').split()
            if tt == 'Cluster' and tid in idx:
                kind = 'z'
                if tid != cid: edges[tuple(sorted((cid, tid)))] = 0
            elif tid.startswith('DNG-') or tt.startswith('Dungeon'): kind = 'd'
            elif tid.startswith('PSG-'): kind = 'p'
            elif tid.startswith('BLAC'): kind = 'r'
            else: kind = 'o'
            exits.append([kind, r1(px), r1(py), idx.get(tid, -1)])
    ters = []
    tr = c.find('territories')
    if tr is not None:
        for x2 in tr:
            cx, cy = (x2.get('center') or '0 0').split()
            sub = x2.get('castletype') or x2.get('resourcetype') or ''
            ters.append([x2.get('name') or '', x2.get('territorytype'), sub, r1(cx), r1(cy)])
    zones.append({'n': c.get('displayname'), 'k': cat(c.get('type')), 't': tier, 'q': int(q.group(1)) if q else 0,
                  'b': parts[2] if len(parts) > 2 else '', 'x': round(x, 2), 'y': round(y, 2), 's': round(size),
                  'm': marks, 'e': exits, 'tr': ters})
byname = {z['n']: i for i, z in enumerate(zones)}
for city in ['Lymhurst', 'Bridgewatch', 'Martlock', 'Thetford', 'Fort Sterling']:
    a, b = byname.get(city), byname.get(city + ' Portal')
    if a is not None and b is not None: edges[tuple(sorted((ids[a], ids[b])))] = 1
out = {'v': 2, 'scale': 0.0145, 'rot': -45, 'z': zones, 'g': [[idx[a], idx[b]] + ([1] if p else []) for (a, b), p in edges.items()]}
json.dump(out, open(OUT, 'w'), separators=(',', ':'), ensure_ascii=False)
import os
print(len(zones), len(out['g']), os.path.getsize(OUT))
print(json.dumps(zones[byname['Deepwood Gorge']], ensure_ascii=False)[:700])
