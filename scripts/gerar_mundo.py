# Gera o fundo do mapa-múndi (continentes, biomas, relevo e litoral) a partir do mapa.json.
# Uso: python3 scripts/gerar_mundo.py
# Saída: assets/img/mundo/geral.webp (visão geral), assets/img/mundo/t_<col>_<lin>.webp (blocos com zoom)
# e assets/img/mundo/mundo.json (posição dos blocos no mapa).
import os, json, math
import numpy as np
from PIL import Image

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
OUT = os.path.join(ROOT, 'assets', 'img', 'mundo')
os.makedirs(OUT, exist_ok=True)
PX = 6.0        # pixels por unidade do mapa nos blocos
TILE = 512
MARGIN = 34.0

mapa = json.load(open(os.path.join(ROOT, 'assets', 'data', 'mapa.json')))
Z = mapa['z']
S = mapa['scale']
A = math.radians(mapa['rot'])
COS, SIN = math.cos(A), math.sin(A)

xs = [z['x'] for z in Z]
ys = [-z['y'] for z in Z]
X0, X1 = min(xs) - MARGIN, max(xs) + MARGIN
Y0, Y1 = min(ys) - MARGIN, max(ys) + MARGIN
W = int(math.ceil((X1 - X0) * PX / TILE)) * TILE
H = int(math.ceil((Y1 - Y0) * PX / TILE)) * TILE
X1, Y1 = X0 + W / PX, Y0 + H / PX
print('imagem', W, H)

rng = np.random.default_rng(20261015)


def fbm(octaves, base, gain=0.5, seed=0):
    # Ruído fractal: grades aleatórias ampliadas e somadas.
    r = np.random.default_rng(seed)
    out = np.zeros((H, W), np.float32)
    amp, tot = 1.0, 0.0
    cells = base
    for _ in range(octaves):
        gw, gh = max(2, int(W / cells)), max(2, int(H / cells))
        g = (r.random((gh, gw)) * 255).astype(np.uint8)
        im = Image.fromarray(g).resize((W, H), Image.BICUBIC)
        out += (np.asarray(im, np.float32) / 255.0 - 0.5) * amp
        tot += amp
        amp *= gain
        cells /= 2.0
    return out / tot


# Distância de cada pixel até a zona mais próxima (losango da zona), e qual zona é.
gx = (np.arange(W, dtype=np.float32) + 0.5) / PX + X0
gy = (np.arange(H, dtype=np.float32) + 0.5) / PX + Y0
DIST = np.full((H, W), 1e9, np.float32)
IDX = np.full((H, W), -1, np.int32)
warp_x = fbm(5, 300, gain=0.6, seed=1) * 16.0
warp_y = fbm(5, 300, gain=0.6, seed=2) * 16.0
for i, z in enumerate(Z):
    cx, cy = z['x'], -z['y']
    half = z['s'] / 2 * S
    R = half * 1.45 + 40
    c0, c1 = int((cx - R - X0) * PX), int((cx + R - X0) * PX)
    r0, r1 = int((cy - R - Y0) * PX), int((cy + R - Y0) * PX)
    c0, r0 = max(0, c0), max(0, r0)
    c1, r1 = min(W, c1), min(H, r1)
    if c1 <= c0 or r1 <= r0:
        continue
    dx = gx[None, c0:c1] + warp_x[r0:r1, c0:c1] - cx
    dy = -(gy[r0:r1, None] + warp_y[r0:r1, c0:c1] - cy)
    # Volta para o eixo da zona (o inverso do giro usado no site).
    u = (dx * COS + dy * SIN) / S
    v = (-dx * SIN + dy * COS) / S
    # Mistura de quadrado e círculo para o contorno não ficar reto.
    d = (0.45 * np.maximum(np.abs(u), np.abs(v)) + 0.55 * np.hypot(u, v) / 1.18 - z['s'] / 2) * S
    sub = DIST[r0:r1, c0:c1]
    m = d < sub
    sub[m] = d[m]
    IDX[r0:r1, c0:c1][m] = i

print('distancias ok')
coast_n = fbm(6, 420, gain=0.55, seed=3) + 0.35 * fbm(4, 60, seed=10)
land_v = 8.5 + coast_n * 18.0 - DIST
land = np.clip(land_v / 1.2, 0, 1)
# Plataforma rasa em volta da costa.
shelf = np.clip((land_v + 14) / 14, 0, 1) * (1 - land)

BIO = {
    'FR': (86, 104, 52),
    'HL': (118, 128, 70),
    'SW': (146, 138, 76),
    'ST': (206, 176, 116),
    'MN': (202, 210, 222),
}
bio_key = np.array([z['b'] if z['b'] in BIO else 'HL' for z in Z])
order = list(BIO.keys())
bi = np.array([order.index(b) for b in bio_key], np.int32)
B = np.where(IDX >= 0, bi[np.clip(IDX, 0, None)], 1)
pal = np.array([BIO[k] for k in order], np.float32) / 255.0

# Bordas entre biomas suavizadas: média das cores numa vizinhança.
col = pal[B]
small = Image.fromarray((col * 255).astype(np.uint8)).resize((W // 6, H // 6), Image.BILINEAR).resize((W, H), Image.BICUBIC)
col = np.asarray(small, np.float32) / 255.0
isMN = np.asarray(Image.fromarray(((B == order.index('MN')) * 255).astype(np.uint8)).resize((W // 6, H // 6), Image.BILINEAR).resize((W, H), Image.BICUBIC), np.float32) / 255.0
isST = np.asarray(Image.fromarray(((B == order.index('ST')) * 255).astype(np.uint8)).resize((W // 6, H // 6), Image.BILINEAR).resize((W, H), Image.BICUBIC), np.float32) / 255.0
isSW = np.asarray(Image.fromarray(((B == order.index('SW')) * 255).astype(np.uint8)).resize((W // 6, H // 6), Image.BILINEAR).resize((W, H), Image.BICUBIC), np.float32) / 255.0
print('cores ok')

# Relevo: montanhas bem marcadas, estepe com dunas, o resto suave.
hn = fbm(7, 300, gain=0.55, seed=4)
ridge = 1 - np.abs(fbm(6, 160, gain=0.55, seed=5)) * 2
hgt = hn * (0.6 + 2.2 * isMN) + ridge * (0.15 + 1.1 * isMN) + fbm(5, 90, seed=6) * 0.25 * isST
hgt *= land
dyh, dxh = np.gradient(hgt * PX * 4)
shade = np.clip(1 + (-dxh - dyh) * 0.55, 0.55, 1.45)
detail = fbm(4, 24, seed=7)
img = col * shade[..., None] * (1 + detail[..., None] * 0.12)
# Neve mais clara no alto das montanhas.
snow = np.clip((hgt - 0.05) * 3, 0, 1) * isMN
img = img * (1 - snow[..., None] * 0.35) + np.array([0.93, 0.95, 0.98], np.float32) * snow[..., None] * 0.35
# Pântano: poças e alagados.
wet = np.clip((fbm(5, 70, seed=8) - 0.06) * 9, 0, 1) * isSW * land
water = np.array([0.33, 0.47, 0.56], np.float32)
img = img * (1 - wet[..., None] * 0.8) + water * wet[..., None] * 0.8
# Rios finos cortando as terras baixas.
riv = np.abs(fbm(5, 380, gain=0.5, seed=9))
rivm = np.clip((0.012 - riv) / 0.012, 0, 1) * np.clip(land_v / 6 - 0.5, 0, 1) * (1 - isMN) * (1 - isST * 0.6)
img = img * (1 - rivm[..., None] * 0.75) + water * rivm[..., None] * 0.75
# Litoral levemente mais claro (areia) e sombra na água junto à costa.
sand = np.clip(1 - np.abs(land_v - 1.0) / 1.6, 0, 1) * land
img = img * (1 - sand[..., None] * 0.25) + np.array([0.82, 0.76, 0.6], np.float32) * sand[..., None] * 0.25

rgb = np.clip(img, 0, 1)
shelf_col = np.array([0.36, 0.52, 0.58], np.float32)
out_rgb = rgb * land[..., None] + shelf_col * (1 - land[..., None])
alpha = np.clip(land + shelf * 0.55, 0, 1)
rgba = np.dstack([out_rgb, alpha])
im = Image.fromarray((rgba * 255).astype(np.uint8), 'RGBA')
print('render ok')

for f in os.listdir(OUT):
    if f.startswith('t_'):
        os.remove(os.path.join(OUT, f))
tiles = []
for ty in range(H // TILE):
    for tx in range(W // TILE):
        t = im.crop((tx * TILE, ty * TILE, (tx + 1) * TILE, (ty + 1) * TILE))
        if t.getchannel('A').getextrema()[1] < 4:
            continue
        t.save(os.path.join(OUT, 't_%d_%d.webp' % (tx, ty)), 'WEBP', quality=74, method=6)
        tiles.append([tx, ty])
im.resize((W // 4, H // 4), Image.LANCZOS).save(os.path.join(OUT, 'geral.webp'), 'WEBP', quality=78, method=6)
json.dump({'x0': round(X0, 3), 'y0': round(Y0, 3), 'px': PX, 'tile': TILE, 'w': W, 'h': H, 'tiles': tiles},
          open(os.path.join(OUT, 'mundo.json'), 'w'), separators=(',', ':'))
print('blocos', len(tiles))
