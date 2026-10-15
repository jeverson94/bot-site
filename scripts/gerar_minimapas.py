# Gera o minimapa de cada zona a partir dos arquivos de cluster e template do ao-bin-dumps.
# Uso: python3 scripts/gerar_minimapas.py caminho/do/ao-bin-dumps
# Saída: assets/img/mapa/<indice>.webp (um por zona, na ordem do mapa.json) e assets/img/mapa/atlas.webp
import sys, os, re, json, math, subprocess, functools
import xml.etree.ElementTree as ET
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

DUMPS = sys.argv[1] if len(sys.argv) > 1 else 'ao-bin-dumps'
ONLY = sys.argv[2:]  # nomes de zona para gerar só algumas (teste)
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
OUTDIR = os.path.join(ROOT, 'assets', 'img', 'mapa')
os.makedirs(OUTDIR, exist_ok=True)
RES = 512       # lado do minimapa final
THUMB = 96      # lado de cada minimapa no atlas da visão geral


def blob(path):
    # Lê do clone (pode ser parcial, então usa o git quando o arquivo não está no disco).
    p = os.path.join(DUMPS, path)
    if os.path.exists(p):
        return open(p, 'rb').read()
    r = subprocess.run(['git', '-C', DUMPS, 'cat-file', '-p', 'HEAD:' + path], capture_output=True)
    return r.stdout if r.returncode == 0 else None


TEMPLATES = {}
for line in subprocess.run(['git', '-C', DUMPS, 'ls-tree', '-r', '--name-only', 'HEAD', 'templates/'], capture_output=True, text=True).stdout.split():
    m = re.match(r'templates/([A-Z]+)/(.+)\.template\.xml$', line)
    if m:
        TEMPLATES.setdefault(m.group(2), {})[m.group(1)] = line


@functools.lru_cache(maxsize=None)
def template(name, folder):
    opts = TEMPLATES.get(name)
    if not opts:
        return None
    path = opts.get(folder) or opts.get('GREEN') or opts.get('NONE') or next(iter(opts.values()))
    data = blob(path)
    return ET.fromstring(data) if data else None


def collect(node, active, out, layer=None):
    # Junta tiles e estradas; os que estão dentro de uma <layer> só entram se a camada estiver ativa.
    for ch in node:
        tag = ch.tag
        if tag == 'layer':
            if ch.get('id') in active:
                collect(ch, active, out, ch.get('id'))
        elif tag in ('layergroup', 'tiles', 'criticalscenemembers', 'criticalscenemember'):
            if tag == 'criticalscenemember' and ch.get('layerId') and ch.get('layerId') not in active:
                continue
            collect(ch, active, out, layer)
        elif tag in ('tile', 'compoundtile'):
            if ch.get('activelayers') is not None and layer is None:
                pass
            out.append(('t', ch.get('name') or '', ch.get('pos') or '0 0 0', float(ch.get('roty') or 0), ch.get('scale')))
        elif tag == 'roads':
            out.append(('r', ch.get('nodes') or '', ch.get('links') or '', 0, None))


def rot(x, z, deg):
    a = math.radians(deg)
    c, s = math.cos(a), math.sin(a)
    return x * c + z * s, -x * s + z * c


SIZE_RE = re.compile(r'(\d+)[xX](\d+)')


def classify(name):
    n = name.lstrip('_').upper()
    if 'FISHINGZONE' in n or 'SPAWNPOINT' in n or 'ATMOSPHERE' in n or 'ENV_CAM' in n or 'PLACEHOLDER' in n or n.startswith('EXIT') or 'TUNNEL_EXIT' in n:
        return None
    if 'BLOCK_CONSTRUCTION' in n or 'REALESTATE' in n or 'CONSTRUCTION_' in n:
        return None
    if 'XMAS' in n or 'HALLOWEEN' in n or 'EASTER' in n or 'ANNIVERSARY' in n:
        return None
    if 'WATER' in n or 'RIVER' in n or 'LAKE' in n or 'OCEAN' in n or 'POND' in n or 'PUDDLE' in n:
        return 'water'
    if 'BRIDGE' in n:
        return 'bridge'
    if 'ROAD' in n or 'STREET' in n or 'PATH' in n or 'BOARDWALK' in n:
        return 'road'
    if 'CLIFF' in n:
        return 'cliff'
    if 'PLATEAU' in n and 'ROCK' not in n or 'HILL' in n or 'RAMP' in n:
        return 'ledge'
    if 'ROCK' in n or 'STONE' in n or 'BOULDER' in n:
        return 'rock'
    if 'GROUND' in n:
        return 'ground'
    if 'CASTLE' in n or 'WALL' in n or 'HOUSE' in n or 'BUILDING' in n or 'MARKETPLACE' in n or 'BANK' in n or 'CONSTRUCTION' in n or 'TOWER' in n or 'REALESTATE' in n:
        return 'build'
    if 'MOBCAMP' in n or 'BBS_' in n or 'DUNGEON' in n:
        return 'camp'
    if 'BLOCKER' in n:
        return 'block'
    if re.search(r'_(SUB_)?\d+X\d+(_[A-Z])?(_\d+)?$', n):
        return 'fill'
    if 'VEG' in n or 'TREE' in n or 'BUSH' in n or 'GRASS' in n or 'FERN' in n or 'PLANT' in n or 'FLOWER' in n:
        return 'veg'
    return None


def footprint(name, cat):
    m = SIZE_RE.search(name)
    if m:
        return float(m.group(1)), float(m.group(2))
    if cat in ('cliff', 'ledge'):
        if 'LARGE' in name.upper():
            return 20.0, 20.0
        return 10.0, 10.0
    if cat == 'water':
        return 10.0, 10.0
    if cat == 'rock':
        mm = re.search(r'_(\d+)M', name.upper())
        r = float(mm.group(1)) if mm else 6.0
        return r, r
    if cat == 'road':
        return 20.0, 10.0
    if cat == 'build':
        return 8.0, 8.0
    if cat == 'veg':
        return 3.0, 3.0
    return 10.0, 10.0


# Cores por bioma: chão andável, vegetação densa (fora do caminho), escarpa.
PAL = {
    'FR': {'ground': (96, 122, 70), 'fill': (46, 70, 38), 'cliff': (40, 44, 36), 'road': (178, 160, 118)},
    'SW': {'ground': (88, 104, 74), 'fill': (46, 60, 46), 'cliff': (38, 42, 38), 'road': (160, 146, 110)},
    'ST': {'ground': (164, 146, 96), 'fill': (118, 112, 70), 'cliff': (88, 74, 52), 'road': (204, 186, 140)},
    'HL': {'ground': (126, 120, 90), 'fill': (86, 92, 64), 'cliff': (66, 60, 50), 'road': (190, 172, 132)},
    'MN': {'ground': (150, 154, 160), 'fill': (98, 110, 112), 'cliff': (70, 74, 82), 'road': (206, 206, 208)},
}
WATER = (52, 92, 120)
WATER_DEEP = (34, 64, 92)


def render(z, cl):
    biome = z['b'] if z['b'] in PAL else 'FR'
    pal = PAL[biome]
    folder = 'DEAD' if z['k'] == 'black' else 'RED' if z['k'] == 'red' else 'GREEN'
    half = z['s'] / 2.0
    N = 1024
    sc = N / (2 * half)

    def P(x, y):
        return ((x + half) * sc, (half - y) * sc)

    items = []
    for inst in cl.findall('templateinstance'):
        ref = inst.get('ref')
        t = template(ref, folder)
        if t is None:
            continue
        active = {a.get('id') for a in inst.findall('activelayer')}
        px, py, pz = [float(v) for v in (inst.get('pos') or '0 0 0').split()]
        r = float(inst.get('rot') or 0)
        found = []
        tiles = t.find('tiles')
        if tiles is not None:
            collect(tiles, active, found)
        cs = t.find('criticalscenemembers')
        if cs is not None:
            collect(cs, active, found)
        for it in found:
            items.append((it, px, py, pz, r))

    ground = Image.new('L', (N, N), 0)       # máscara do chão andável
    height = Image.new('L', (N, N), 128)     # altura do chão, codificada em 0..255
    masks = {k: Image.new('L', (N, N), 0) for k in ('fill', 'block', 'water', 'cliff', 'ledge', 'rock', 'road', 'build', 'camp', 'veg', 'bridge')}
    dg = ImageDraw.Draw(ground)
    dh = ImageDraw.Draw(height)
    dm = {k: ImageDraw.Draw(v) for k, v in masks.items()}
    roads = []

    for (kind, name, pos, roty, scale), px, py, pz, r in items:
        if kind == 'r':
            pts = []
            for p in name.split(';'):
                if not p.strip():
                    continue
                a, b = [float(v) for v in p.split()]
                x, y = rot(a, b, r)
                pts.append((x + px, y + pz))
            for l in pos.split(';'):
                if not l.strip():
                    continue
                i, j = [int(v) for v in l.split()]
                if i < len(pts) and j < len(pts):
                    roads.append((pts[i], pts[j]))
            continue
        cat = classify(name)
        if not cat:
            continue
        x, y, zz = [float(v) for v in pos.split()]
        w, d = footprint(name, cat)
        if scale:
            s = [float(v) for v in scale.split()]
            w *= s[0]
            d *= s[2] if len(s) > 2 else s[0]
        # Cantos do retângulo na base do tile, girados pelo tile e depois pela instância.
        corners = []
        for cx, cz in ((-w / 2, -d / 2), (w / 2, -d / 2), (w / 2, d / 2), (-w / 2, d / 2)):
            a, b = rot(cx, cz, roty)
            a, b = rot(a + x, b + zz, r)
            corners.append(P(a + px, b + pz))
        h = y + py
        if cat == 'ground':
            dg.polygon(corners, fill=255)
            dh.polygon(corners, fill=int(max(0, min(255, 128 + h * 3))))
        elif cat == 'veg':
            dm['veg'].polygon(corners, fill=255)
        else:
            dm[cat].polygon(corners, fill=255)
            if cat in ('fill', 'block'):
                pass
    for a, b in roads:
        dm['road'].line([P(*a), P(*b)], fill=255, width=max(2, int(7 * sc)))
        for p in (a, b):
            q = P(*p)
            rr = 3.5 * sc
            dm['road'].ellipse([q[0] - rr, q[1] - rr, q[0] + rr, q[1] + rr], fill=255)

    A = lambda im: np.asarray(im, dtype=np.float32) / 255.0
    g = A(ground.filter(ImageFilter.GaussianBlur(1.2)))
    # Altura média só onde há chão (convolução normalizada), para não marcar a borda de cada tile.
    hm = np.asarray(ground, dtype=np.float32) / 255.0
    hv = (np.asarray(height, dtype=np.float32) - 128) / 3 * hm
    blur = lambda a, r: np.asarray(Image.fromarray(a.astype(np.float32)).resize((N // 4, N // 4), Image.BILINEAR).resize((N, N), Image.BICUBIC), dtype=np.float32) if r else a
    num = blur(hv, 1); den = blur(hm, 1)
    H = np.where(den > 0.05, num / np.maximum(den, 1e-3), 0)
    H = blur(H, 1)
    fill = A(masks['fill'].filter(ImageFilter.GaussianBlur(5)))
    water = A(masks['water'].filter(ImageFilter.MaxFilter(5)).filter(ImageFilter.GaussianBlur(3)))
    cliff = A(masks['cliff'].filter(ImageFilter.GaussianBlur(2)))
    ledge = A(masks['ledge'].filter(ImageFilter.GaussianBlur(2)))
    rock = A(masks['rock'].filter(ImageFilter.GaussianBlur(1.5)))
    road = A(masks['road'].filter(ImageFilter.GaussianBlur(1.6)))
    build = A(masks['build'].filter(ImageFilter.GaussianBlur(1)))
    veg = A(masks['veg'].filter(ImageFilter.GaussianBlur(2)))
    block = A(masks['block'].filter(ImageFilter.GaussianBlur(3)))
    bridge = A(masks['bridge'].filter(ImageFilter.GaussianBlur(1)))

    rng = np.random.default_rng(abs(hash(z['n'])) % (2 ** 32))
    noise = Image.fromarray((rng.random((N // 8, N // 8)) * 255).astype(np.uint8)).resize((N, N), Image.BICUBIC)
    noise = (np.asarray(noise, dtype=np.float32) / 255.0 - 0.5)
    fine = (rng.random((N, N)).astype(np.float32) - 0.5)

    col = lambda c: np.array(c, dtype=np.float32)[None, None, :] / 255.0
    hr = np.clip(H / 24.0, -1, 1)
    # Chão: cor do bioma, mais clara no alto, com variação suave.
    img = np.ones((N, N, 3), np.float32) * col(pal['ground'])
    img *= (1 + 0.22 * hr[..., None]) * (1 + 0.12 * noise[..., None] + 0.05 * fine[..., None])
    # Sombreamento pela altura (luz vindo de noroeste).
    gy, gx = np.gradient(H)
    img *= np.clip(1 + (-gx + gy) * 0.08, 0.6, 1.35)[..., None]
    mix = lambda base, m, c, k=1.0: base * (1 - m[..., None] * k) + col(c) * m[..., None] * k
    # Mata fechada e bloqueios, com bordas irregulares.
    tv = np.maximum(fill, block) + 0.35 * noise + 0.1 * fine
    trees = np.clip((tv - 0.32) * 3.2, 0, 1)
    tcol = col(pal['fill']) * (1 + 0.25 * noise[..., None] + 0.12 * fine[..., None])
    img = img * (1 - trees[..., None] * 0.85) + tcol * trees[..., None] * 0.85
    img = mix(img, veg, pal['fill'], 0.3)
    img = mix(img, ledge, pal['cliff'], 0.5)
    img = mix(img, cliff, pal['cliff'], 0.85)
    img = mix(img, rock, (112, 110, 104), 0.7)
    wcol = col(WATER) * (1 + 0.06 * noise[..., None])
    img = img * (1 - water[..., None]) + wcol * water[..., None]
    img = mix(img, bridge, (120, 96, 70), 0.9)
    img = mix(img, road, pal['road'], 0.9)
    img = mix(img, build, (150, 122, 94), 0.75)
    # Fora da área jogável (além das saídas) escurece como no jogo.
    lim = max([max(abs(e[1]), abs(e[2])) for e in z.get('e', [])] + [half * 0.68]) + 12
    yy, xx = np.mgrid[0:N, 0:N]
    lx = np.abs(xx / sc - half)
    ly = np.abs(yy / sc - half)
    edge = np.clip((np.maximum(lx, ly) - lim) / 40.0, 0, 1)
    img *= (1 - 0.55 * edge)[..., None]
    out = Image.fromarray(np.clip(img * 255, 0, 255).astype(np.uint8))
    return out.resize((RES, RES), Image.LANCZOS)


def job(a):
    i, z, f = a
    data = blob('cluster/' + f) if f else None
    if not data:
        print('sem cluster', z['n'])
        return (i, None)
    im = render(z, ET.fromstring(data))
    im.save(os.path.join(OUTDIR, '%d.webp' % i), 'WEBP', quality=72, method=6)
    return (i, im.resize((THUMB, THUMB), Image.LANCZOS))


def main():
    mapa = json.load(open(os.path.join(ROOT, 'assets', 'data', 'mapa.json')))
    world = ET.fromstring(blob('cluster/world.xml'))
    bypos = {}
    for c in world.find('clusters').findall('cluster'):
        if c.get('worldmapposition') and c.get('file'):
            x, y = [round(float(v), 2) for v in c.get('worldmapposition').split()]
            bypos[(c.get('displayname'), x, y)] = c.get('file')
    jobs = []
    for i, z in enumerate(mapa['z']):
        if ONLY and z['n'] not in ONLY:
            continue
        jobs.append((i, z, bypos.get((z['n'], z['x'], z['y']))))
    from multiprocessing import Pool
    with Pool(os.cpu_count()) as pool:
        thumbs = pool.map(job, jobs, chunksize=4)
    if ONLY:
        return
    cols = 21
    rows = math.ceil(len(mapa['z']) / cols)
    atlas = Image.new('RGB', (cols * THUMB, rows * THUMB), (0, 0, 0))
    for i, t in thumbs:
        if t is not None:
            atlas.paste(t, ((i % cols) * THUMB, (i // cols) * THUMB))
    atlas.save(os.path.join(OUTDIR, 'atlas.webp'), 'WEBP', quality=70, method=6)
    print('ok', len(thumbs))


if __name__ == '__main__':
    main()
