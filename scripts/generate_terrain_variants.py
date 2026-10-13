"""Generate candidate variants of each terrain tile, plus a preview.html to compare them.

Usage: python scripts/generate_terrain_variants.py <output-dir>
Use a folder beside the tiles (e.g. images/terrain/variants) so the preview can show the current tile.

Every variant of a terrain reuses that terrain's exact palette and motif; only layout,
size, mirroring and small shape jitter change. Darkness gets distinct "evil" icons.
Output is seeded per file, so re-running gives the same images. The picks that became
images/terrain/<biome>-<terrain>-1.svg to -7.svg were:

  plains-grass 03 04 06 08 10 13 17        plains-roads 02 10 13 15 16 18 20
  stone-stone-path 04 07 10 11 13 14 20    stone-crags 04 09 10 11 14 17 19
  water-open-water 03 05 08 09 11 16 20    water-shallow-water 01 04 11 15 17 18 20
  sand-desert 01 09 16 17 21 27 30         sand-dunes 03 05 12 13 15 17 18
  jungle-jungle 11 12 15 16 18 19 20       jungle-swamp 03 04 10 11 13 15 19
  ice-ice 02 06 10 12 13 18 19             ice-snow 04 08 11 17 23 25 30
  lava-basalt 13 14 19 21 23 27 29         lava-lava 11 14 16 17 20 21 25
  darkness-darkness 01 02 04 05 06 07 09   darkness-poison-pits 05 12 13 14 15 18 20

lava-basalt-25 became the primary lava-basalt.svg (basalt 11-30 meet each hex edge at its midpoint, so they tile).
"""
import math, os, random, sys

OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)
N = 10
HEAD = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="none" stroke-linecap="round" stroke-linejoin="round">\n'


def f(v):
    s = f"{v:.1f}"
    return s[:-2] if s.endswith(".0") else s


def svg(bg, parts):
    return HEAD + f'  <rect width="100" height="100" fill="{bg}"/>\n' + "".join("  " + p + "\n" for p in parts) + "</svg>\n"


# Tiles render as flat-top hexes inscribed in the 100x100 square.
def in_hex(x, y, m=0):
    dy = abs(y - 50)
    dx = abs(x - 50)
    return dy <= 43.3 - m and dx + dy / math.sqrt(3) <= 50 - m * 1.155


class T:
    """Maps stamp-local coordinates to tile coordinates (scale, mirror, translate)."""

    def __init__(self, x, y, s=1.0, flip=False):
        self.x, self.y, self.s, self.k = x, y, s, (-1 if flip else 1)

    def p(self, dx, dy):
        return f"{f(self.x + self.k * dx * self.s)} {f(self.y + dy * self.s)}"

    def pt(self, dx, dy):
        return (self.x + self.k * dx * self.s, self.y + dy * self.s)

    def d(self, cmds):
        """cmds: list of (letter, [dx, dy, dx, dy...]) in absolute stamp coords."""
        out = []
        for c, nums in cmds:
            pts = [self.p(nums[i], nums[i + 1]) for i in range(0, len(nums), 2)]
            out.append(c + " ".join(pts))
        return " ".join(out)


def place(rng, items, margin=8, tries=4000):
    """items: list of (radius, extra_margin). Returns centres that fit in the hex without overlapping."""
    for _ in range(200):
        pos = []
        ok = True
        for r, m in items:
            for _ in range(tries // 10):
                x, y = rng.uniform(5, 95), rng.uniform(5, 95)
                if not in_hex(x, y, margin + r + m):
                    continue
                if all(math.hypot(x - px, y - py) > r + pr + 3 for px, py, pr in pos):
                    pos.append((x, y, r))
                    break
            else:
                ok = False
                break
        if ok:
            return [(x, y) for x, y, _ in pos]
    raise RuntimeError("could not place")


# ---------------------------------------------------------------- plains
G_MID, G_DARK, G_BG = "#3b6340", "#2c4a2f", "#4a7c4e"


def tuft(t, rng, big):
    if big:
        tips = [(-4, -14), (0, -18), (5, -13)]
    else:
        tips = [(-3, -10), (1, -13), (5, -9)]
    tips = [(x + rng.uniform(-1.2, 1.2), y + rng.uniform(-1.5, 1.5)) for x, y in tips]
    return " ".join(f"M{t.p(0, 0)} L{t.p(*tp)}" for tp in tips)


def grass(rng):
    pos = place(rng, [(11, 0)] * 3 + [(8, 0)] * 3)
    big = [tuft(T(x, y + 9, rng.uniform(0.9, 1.1), rng.random() < .5), rng, True) for x, y in pos[:3]]
    small = [tuft(T(x, y + 6, rng.uniform(0.9, 1.1), rng.random() < .5), rng, False) for x, y in pos[3:]]
    return svg(G_BG, [
        f'<g stroke="{G_MID}" stroke-width="3">' + "".join(f'<path d="{d}"/>' for d in big) + "</g>",
        f'<g stroke="{G_DARK}" stroke-width="2.5">' + "".join(f'<path d="{d}"/>' for d in small) + "</g>",
    ])


def blade(rng, x, y, length, lean=4):
    lean = rng.uniform(-lean, lean)
    return f"M{f(x)} {f(y)} Q{f(x + lean * .2)} {f(y - length * .6)} {f(x + lean)} {f(y - length)}"


def grass_varied(rng, kind):
    """kind: 'blades' (single blades only), 'rows' (blades in rows) or 'bush' (one big tuft among blades)."""
    mid, dark = [], []
    if kind == "rows":
        n = 3
        top = rng.uniform(28, 34)
        gap = (72 - top) / (n - 1) if n > 1 else 0
        for r in range(n):
            y = top + r * gap + rng.uniform(-2, 2)
            half = 50 - abs(y - 50) / math.sqrt(3) - 14
            x = 50 - half + rng.uniform(0, 8)
            end = 50 + half - rng.uniform(0, 8)
            row = mid if r % 2 == 0 else dark
            while x <= end:
                row.append(blade(rng, x, y + rng.uniform(-1, 1), rng.uniform(7, 10), lean=2))
                x += rng.uniform(7, 9)
    else:
        bush = []
        if kind == "bush":
            sc = rng.uniform(.9, 1.15)
            bush = [(24 * sc, 0)]
        count = rng.randint(10, 15) if kind == "blades" else rng.randint(6, 10)
        pos = place(rng, bush + [(4, 0)] * count, margin=6)
        if kind == "bush":
            (x, y), pos = pos[0], pos[1:]
            by = y + 12 * sc
            fan = [-72 + k * 144 / (m - 1) + rng.uniform(-6, 6) for m in [rng.randint(9, 11)] for k in range(m)]
            for k, ang in enumerate(fan):
                ln = (24 - abs(ang) * .12 + rng.uniform(-3, 3)) * sc
                tip = (x + ln * math.sin(math.radians(ang)), by - ln * math.cos(math.radians(ang)))
                ctl = (x + ln * .55 * math.sin(math.radians(ang * .55)), by - ln * .7 * math.cos(math.radians(ang * .55)))
                (dark if k % 3 == 1 else mid).append(f"M{f(x)} {f(by)} Q{f(ctl[0])} {f(ctl[1])} {f(tip[0])} {f(tip[1])}")
        for x, y in pos:
            (mid if rng.random() < .5 else dark).append(blade(rng, x, y + 5, rng.uniform(8, 13)))
    return svg(G_BG, [
        f'<g stroke="{G_MID}" stroke-width="3">' + "".join(f'<path d="{d}"/>' for d in mid) + "</g>",
        f'<g stroke="{G_DARK}" stroke-width="2.5">' + "".join(f'<path d="{d}"/>' for d in dark) + "</g>",
    ])


# ---------------------------------------------------------------- stones (roads / stone path / shallow water)
S_FILL, S_LINE, S_HI = "#a3a3a3", "#4d4d4d", "#cfcfcf"
STONE_SIZES = [(15, 10), (10, 7.5), (7.5, 5.5), (6.5, 5), (5, 4)]


VARIED = False  # set for variants 11-20: varied stone counts, sizes and slightly irregular outlines


def varied_sizes(rng, scale=1.0):
    kind = rng.choice(["big", "many", "pair", "mixed", "mixed"])
    if kind == "big":
        rxs = [rng.uniform(17, 21)] + [rng.uniform(4, 7) for _ in range(rng.randint(2, 3))]
    elif kind == "many":
        rxs = [rng.uniform(4, 9) for _ in range(rng.randint(6, 8))]
    elif kind == "pair":
        rxs = [rng.uniform(12, 15), rng.uniform(10, 13)] + [rng.uniform(4, 6) for _ in range(rng.randint(1, 2))]
    else:
        rxs = [rng.uniform(5, 15) for _ in range(rng.randint(3, 6))]
    rxs = sorted((r * scale for r in rxs), reverse=True)
    return [(r, r * rng.uniform(.6, .8)) for r in rxs]


def stone_layout(rng, pad=0, extra=(), margin=6, scale=1.0):
    if VARIED:
        sizes = varied_sizes(rng, scale)
        while True:  # shrink the set until it fits the hex
            try:
                pos = place(rng, [(rx * .85 + pad, 1) for rx, _ in sizes] + list(extra), margin=margin)
                break
            except RuntimeError:
                sizes = [(rx * .92, ry * .92) for rx, ry in sizes]
    else:
        sizes = [(rx * rng.uniform(.9, 1.1), ry * rng.uniform(.9, 1.1)) for rx, ry in STONE_SIZES]
        pos = place(rng, [(rx + pad, 1) for rx, _ in sizes] + list(extra), margin=margin)
    stones = [(x, y, rx, ry, rng.choice([-20, -15, -10, -5, 0, 5, 10, 15, 20])) for (x, y), (rx, ry) in zip(pos, sizes)]
    if VARIED:  # each stone gets a lumpy outline seed
        stones = [st + ([rng.uniform(.88, 1.1) for _ in range(8)],) for st in stones]
    return stones, pos[len(sizes):]


def blob(x, y, rx, ry, radii):
    """Closed smooth outline through 8 jittered points of an ellipse (Catmull-Rom as cubic Beziers)."""
    pts = [(x + rx * k * math.cos(math.pi * i / 4), y + ry * k * math.sin(math.pi * i / 4)) for i, k in enumerate(radii)]
    d = f"M{f(pts[0][0])} {f(pts[0][1])}"
    for i in range(8):
        p0, p1, p2, p3 = pts[i - 1], pts[i], pts[(i + 1) % 8], pts[(i + 2) % 8]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        d += f" C{f(c1[0])} {f(c1[1])} {f(c2[0])} {f(c2[1])} {f(p2[0])} {f(p2[1])}"
    return d + " Z"


def stone(x, y, rx, ry, rot, radii=None):
    hi = (f"M{f(x - .55 * rx)} {f(y - .15 * ry)} Q{f(x - .2 * rx)} {f(y - .75 * ry)} {f(x + .35 * rx)} {f(y - .5 * ry)}")
    if radii:
        body = f'<path d="{blob(x, y, rx, ry, radii)}" fill="{S_FILL}" stroke="{S_LINE}" stroke-width="2.5"/>'
    else:
        body = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}" fill="{S_FILL}" stroke="{S_LINE}" stroke-width="2.5"/>'
    return f'<g transform="rotate({rot} {f(x)} {f(y)})">{body}<path d="{hi}" stroke="{S_HI}" stroke-width="2"/></g>'



def roads(rng):
    stones, extra = stone_layout(rng, extra=[(7, 0)] * 3)
    tufts = [tuft(T(x, y + 6, 0.85, rng.random() < .5), rng, True) for x, y in extra]
    return svg(G_BG, [
        "<g>" + "".join(f'<path d="{d}" stroke="{G_MID}" stroke-width="2.5"/>' for d in tufts) + "</g>",
        "<g>" + "".join(stone(*s) for s in stones) + "</g>",
    ])


def stone_path(rng):
    stones, extra = stone_layout(rng, extra=[(8, 0), (8, 0), (3, 0)])
    chev = []
    for x, y in extra[:2]:
        t = T(x - 7, y, rng.uniform(.9, 1.1), rng.random() < .5)
        if t.k < 0:
            t.x = x + 7
        chev.append(f'<path d="M{t.p(0, 0)} L{t.p(6, 4)} L{t.p(14, -1)}" stroke="#404040" stroke-width="2.5"/>')
    px, py = extra[2]
    return svg("#6b6b6b", [
        "<g>" + "".join(chev) + f'<circle cx="{f(px)}" cy="{f(py)}" r="2.5" stroke="#555555" stroke-width="2.5"/></g>',
        "<g>" + "".join(stone(*s) for s in stones) + "</g>",
    ])


def shallow(rng):
    stones, extra = stone_layout(rng, pad=1, extra=[(9, 0), (9, 0)], margin=1)
    rip = "".join(f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx + 5)}" ry="{f(ry + 3.5)}" transform="rotate({r} {f(x)} {f(y)})" '
                  f'stroke="#3b637e" stroke-width="2.5"/>' for x, y, rx, ry, r, *_ in stones)
    waves = "".join(f'<path d="M{f(x - 8)} {f(y)} Q{f(x - 4)} {f(y - 4)} {f(x)} {f(y)} T{f(x + 8)} {f(y)}" stroke="#2c4a5f" stroke-width="2.5"/>'
                    for x, y in extra)
    return svg("#4a7c9e", ["<g>" + rip + waves + "</g>", "<g>" + "".join(stone(*s) for s in stones) + "</g>"])


# ---------------------------------------------------------------- open water
def wave_row(x0, y, segs):
    d = f"M{f(x0)} {f(y)} Q{f(x0 + 8)} {f(y - 7)} {f(x0 + 16)} {f(y)}"
    for i in range(2, segs + 1):
        d += f" T{f(x0 + 16 * i)} {f(y)}"
    return d


def open_water(rng):
    rows = rng.choice([3, 3, 4])
    span = 34 if rows == 3 else 48
    top = rng.uniform(50 - span / 2 - 4, 50 - span / 2 + 4)
    gap = span / (rows - 1)
    mid, dark = [], []
    for i in range(rows):
        y = top + i * gap + rng.uniform(-2, 2)
        half = 50 - (abs(y - 50) / math.sqrt(3)) - 12
        maxsegs = int((2 * half) // 16)
        segs = max(2, min(maxsegs, rng.choice([2, 3, 3, 4])))
        lo, hi = 50 - half, 50 + half - 16 * segs
        x0 = rng.uniform(lo, max(lo, hi))
        (mid if i % 2 == 0 else dark).append(wave_row(x0, y, segs))
    return svg("#4a7c9e", [
        '<g stroke="#3b637e" stroke-width="3">' + "".join(f'<path d="{d}"/>' for d in mid) + "</g>",
        '<g stroke="#2c4a5f" stroke-width="2.5">' + "".join(f'<path d="{d}"/>' for d in dark) + "</g>",
    ])


def fish(rng, x, y):
    k = rng.choice([-1, 1])  # facing
    return (f'<path d="M{f(x - 7 * k)} {f(y)} Q{f(x)} {f(y - 5.5)} {f(x + 7 * k)} {f(y)} Q{f(x)} {f(y + 5.5)} {f(x - 7 * k)} {f(y)} Z '
            f'M{f(x - 7 * k)} {f(y)} L{f(x - 12 * k)} {f(y - 4)} L{f(x - 12 * k)} {f(y + 4)} Z"/>'
            f'<circle cx="{f(x + 3.5 * k)}" cy="{f(y - 1)}" r="0.6"/>')


def open_water_more(rng, with_fish):
    """Like open_water; a fish variant keeps a wider gap between two rows for the fish to swim in."""
    if not with_fish:
        return open_water(rng)
    ys = [rng.uniform(26, 32)]
    gap_at = rng.choice([0, 1])
    for j in range(2):
        ys.append(ys[-1] + (24 if j == gap_at else 16) + rng.uniform(-1.5, 1.5))
    mid, dark = [], []
    for i, y in enumerate(ys):
        half = 50 - (abs(y - 50) / math.sqrt(3)) - 12
        segs = max(2, min(int((2 * half) // 16), rng.choice([2, 3, 3, 4])))
        lo, hi = 50 - half, 50 + half - 16 * segs
        x0 = rng.uniform(lo, max(lo, hi))
        (mid if i % 2 == 0 else dark).append(wave_row(x0, y, segs))
    fy = ys[gap_at] + 12
    half = 50 - (abs(fy - 50) / math.sqrt(3)) - 20
    fx = rng.uniform(50 - half, 50 + half)
    return svg("#4a7c9e", [
        '<g stroke="#3b637e" stroke-width="3">' + "".join(f'<path d="{d}"/>' for d in mid) + "</g>",
        '<g stroke="#2c4a5f" stroke-width="2.5">' + "".join(f'<path d="{d}"/>' for d in dark) + fish(rng, fx, fy) + "</g>",
    ])


# ---------------------------------------------------------------- desert
D_CACT, D_PEB = "#81921c", "#a78454"


def desert(rng, counts=(1, 2, 2, 2)):
    """counts: saguaros (0/1), barrel cacti, pebbles, bird chevrons."""
    ns, nb, np_, nbd = counts
    s = rng.uniform(.75, 1.0)
    items = [(27 * s, 0) if counts == (1, 2, 2, 2) else (27 * s, 6)] * ns + [(8, 0)] * nb + [(5, 0)] * np_ + [(7, 0)] * nbd
    pos = place(rng, items, margin=4)
    sag, pos = pos[:ns], pos[ns:]
    barrels, pebbles, birds = pos[:nb], pos[nb:nb + np_], pos[nb + np_:]
    trunk = arms = ""
    if sag:
        (cx, cy), = sag
        t = T(cx, cy, s, rng.random() < .5)
        la, ra = rng.uniform(0, 8), rng.uniform(-8, 0)
        trunk = f'<path d="M{t.p(0, 26)} L{t.p(0, -27)}" style="stroke-width:4"/>'
        arms = (f'<path d="M{t.p(0, 4 + la)} L{t.p(-10, 4 + la)} L{t.p(-10, -6 + la)} '
                f'M{t.p(0, -2 + ra)} L{t.p(10, -2 + ra)} L{t.p(10, -10 + ra)}" style="stroke-width:4"/>')
    bar = "".join(f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rng.uniform(5, 6))}" ry="9"/>' for x, y in barrels)
    peb = "".join(f'<circle cx="{f(x)}" cy="{f(y)}" r="{f(rng.uniform(4, 5))}"/>' for x, y in pebbles)
    brd = "".join(f'<path d="M{f(x - 6)} {f(y + 2)} l6.3 -5.5 l5.2 4.5"/>' for x, y in birds)
    return svg("#d4a76a", [
        f'<g stroke="{D_CACT}" stroke-width="3">{bar}{trunk}{arms}</g>',
        f'<g stroke="{D_PEB}" stroke-width="2">{peb}{brd}</g>',
    ])


# ---------------------------------------------------------------- dunes
def dunes(rng):
    n = rng.choice([2, 2, 3])
    pos = place(rng, [(21, -8)] * n + [(11, -4)] * 2, margin=0)
    big = "".join(f'<path d="M{f(x - w)} {f(y + 4)} Q{f(x)} {f(y - 14)} {f(x + w)} {f(y + 4)}"/>'
                  for (x, y), w in ((p, rng.uniform(18, 23)) for p in pos[:n]))
    small = "".join(f'<path d="M{f(x - w)} {f(y + 2)} Q{f(x)} {f(y - 6)} {f(x + w)} {f(y + 2)}"/>'
                    for (x, y), w in ((p, rng.uniform(9, 11)) for p in pos[n:]))
    return svg("#d4a76a", [f'<g stroke="#aa8655" stroke-width="3">{big}</g>', f'<g stroke="#e3c397" stroke-width="2.5">{small}</g>'])


def dune_arc(x, y, w, h, skew=0.0):
    """A dune crest: an arc w wide and h tall; skew > 0 pushes the peak right for a steep lee side."""
    return f'<path d="M{f(x - w)} {f(y)} Q{f(x + skew * w)} {f(y - 2 * h)} {f(x + w)} {f(y)}"/>'


def ripple_row(x, y, w, amp=2.5, period=8):
    """Wind ripples centred on x, about 2w long."""
    n = max(2, int(4 * w // period))
    x0 = x - n * period / 4
    d = f"M{f(x0)} {f(y)} Q{f(x0 + period / 4)} {f(y - amp)} {f(x0 + period / 2)} {f(y)}"
    for k in range(2, n + 1):
        d += f" T{f(x0 + k * period / 2)} {f(y)}"
    return f'<path d="{d}"/>'


def barchan(x, y, w, h, k):
    """Crescent dune: a rounded back and a hollow slip face between the horns (k = facing)."""
    return (f'<path d="M{f(x - w)} {f(y)} Q{f(x - .2 * w * k)} {f(y - 2 * h)} {f(x + w)} {f(y)}"/>'
            f'<path d="M{f(x - w)} {f(y)} Q{f(x + .1 * w * k)} {f(y - .7 * h)} {f(x + w)} {f(y)}" stroke-width="2.5"/>')


def tracks(rng, x0, y0, x1, y1):
    """A line of paired footprints wandering across the sand."""
    out = ""
    n = int(math.hypot(x1 - x0, y1 - y0) // 7)
    for k in range(n + 1):
        t = k / max(1, n)
        x, y = x0 + (x1 - x0) * t, y0 + (y1 - y0) * t + 3 * math.sin(t * 6)
        off = 2 if k % 2 else -2
        out += f'<circle cx="{f(x)}" cy="{f(y + off)}" r="1"/>'
    return out


def dunes_mix(rng, kind):
    big, light = [], []
    if kind == "huge":
        x, y = rng.uniform(44, 56), rng.uniform(60, 68)
        big.append(dune_arc(x, y, rng.uniform(32, 36), rng.uniform(14, 18), rng.uniform(-.2, .2)))
        for j in range(3):
            light.append(ripple_row(x + rng.uniform(-6, 6), y + 10 + j * 7, rng.uniform(10, 16)))
    elif kind in ("barchans", "barchan+ripples"):
        n = 2 if kind == "barchans" else 1
        k = rng.choice([-1, 1])
        for x, y in place(rng, [(20, -6)] * n, margin=0):
            big.append(barchan(x, y + 6, rng.uniform(16, 20), rng.uniform(8, 10), k))
        if n == 1:
            for x, y in place(rng, [(12, -4)] * 3, margin=4):
                light.append(ripple_row(x, y, rng.uniform(9, 13)))
        else:
            for x, y in place(rng, [(10, -4)], margin=4):
                light.append(ripple_row(x, y, 10))
    elif kind in ("ripples", "ripples+dune"):
        if kind == "ripples+dune":
            x, y = rng.uniform(40, 60), rng.uniform(26, 34)
            big.append(dune_arc(x, y, rng.uniform(20, 24), 9))
            ys = [y + 14 + j * 9 for j in range(5)]
        else:
            ys = [26 + j * 12 + rng.uniform(-1.5, 1.5) for j in range(5)]
        for y in ys:
            half = 50 - abs(y - 50) / math.sqrt(3) - 10
            if half < 8:
                continue
            w = rng.uniform(.5, .9) * half
            light.append(ripple_row(50 + rng.uniform(-half + w, half - w), y, w))
    elif kind in ("nested", "nested+ripples"):
        x, y = rng.uniform(44, 56), rng.uniform(58, 68)
        for j, (w, h) in enumerate(((32, 15), (24, 11), (16, 7))):
            big.append(dune_arc(x + j * rng.uniform(-2, 2), y - j * 10, w, h))
        if kind == "nested+ripples":
            for xx, yy in [(x - 20, y + 12), (x + 18, y + 14)]:
                light.append(ripple_row(xx, yy, 9))
        else:
            light.append(dune_arc(x + 14, y - 34, 9, 4))
    elif kind == "skewed":
        skew = rng.choice([-1, 1]) * rng.uniform(.4, .6)
        for x, y in place(rng, [(18, -6)] * 3, margin=0):
            big.append(dune_arc(x, y + 4, rng.uniform(15, 19), rng.uniform(7, 9), skew))
        for x, y in place(rng, [(9, 0)], margin=6):
            light.append(dune_arc(x, y, 9, 3.5, skew))
    elif kind in ("tracks", "shadow+tracks"):
        x, y = rng.uniform(40, 60), rng.uniform(40, 50)
        big.append(dune_arc(x, y, rng.uniform(26, 30), rng.uniform(11, 13)))
        if kind == "shadow+tracks":
            light.append(dune_arc(x + 4, y + 2, 20, 6))
        else:
            light.append(ripple_row(x, y + 9, 14))
        k = rng.choice([-1, 1])
        big.append(f'<g stroke-width="2">{tracks(rng, 50 - 34 * k, 76 + rng.uniform(-4, 4), 50 + 30 * k, 64 + rng.uniform(-4, 4))}</g>')
    return svg("#d4a76a", [f'<g stroke="#aa8655" stroke-width="3">{"".join(big)}</g>', f'<g stroke="#e3c397" stroke-width="2.5">{"".join(light)}</g>'])


# ---------------------------------------------------------------- crags
def poly(t, pts):
    return " ".join(f"{f(a)},{f(b)}" for a, b in (t.pt(x, y) for x, y in pts))


def spire(t, rng):
    lean = rng.uniform(-3, 3)
    top = [(-4 + lean, -52), (4 + lean, -64)]
    return (f'<g><polygon points="{poly(t, [(-14, 0), *top, (14, 0)])}" fill="{S_FILL}" stroke="{S_LINE}" stroke-width="2.5"/>'
            f'<path d="M{t.p(4 + lean, -64)} L{t.p(0, 0)}" stroke="{S_LINE}" stroke-width="2"/>'
            f'<path d="M{t.p(-6 + lean * .6, -42)} L{t.p(-9, -22)}" stroke="{S_HI}" stroke-width="2"/></g>')


def boulder(t):
    return (f'<g><polygon points="{poly(t, [(-14, 10), (-8, -10), (6, -16), (14, 0), (8, 12)])}" fill="{S_FILL}" stroke="{S_LINE}" stroke-width="2.5"/>'
            f'<path d="M{t.p(6, -16)} L{t.p(0, -2)} L{t.p(-8, -10)} M{t.p(0, -2)} L{t.p(8, 12)}" stroke="{S_LINE}" stroke-width="2"/>'
            f'<path d="M{t.p(-6, -10)} L{t.p(3, -14)}" stroke="{S_HI}" stroke-width="2"/></g>')


def crags(rng):
    flip = rng.random() < .5
    s = rng.uniform(.8, 1.0)
    sx = rng.uniform(40, 50)
    base = rng.uniform(80, 86)
    if flip:
        sx = 100 - sx
    bx = sx + (1 if not flip else -1) * rng.uniform(24, 30)
    by = base - 10 + rng.uniform(-6, 2)
    parts = [spire(T(sx, base, s, rng.random() < .5), rng), boulder(T(bx, by, rng.uniform(.85, 1.05), rng.random() < .5))]
    occupied = [(sx, base - k, 16) for k in range(0, 66, 8)] + [(bx, by, 18)]
    if rng.random() < .35:  # a second, smaller boulder on the other side
        x2 = sx - (1 if not flip else -1) * rng.uniform(20, 24)
        parts.append(boulder(T(x2, base - 4, .6, rng.random() < .5)))
        occupied.append((x2, base - 4, 11))
    chips = []
    while len(chips) < 3:
        x, y = rng.uniform(15, 85), rng.uniform(15, 90)
        if in_hex(x, y, 6) and all(math.hypot(x - a, y - b) > r + 4 for a, b, r in occupied):
            occupied.append((x, y, 8))
            sz = rng.uniform(.8, 1.1)
            chips.append(f'<path d="M{f(x - 3.5 * sz)} {f(y + 2 * sz)} L{f(x)} {f(y - 2 * sz)} L{f(x + 3 * sz)} {f(y + 3 * sz)} Z"/>')
    return svg("#6b6b6b", parts + ['<g stroke="#404040" stroke-width="2.5">' + "".join(chips) + "</g>"])


def crags_mix(rng, kind):
    """kind: 'spires' (tall rocks only), 'boulders' (boulders only) or 'mixed'."""
    parts, occupied = [], []
    if kind in ("spires", "mixed"):
        n = rng.randint(2, 3) if kind == "spires" else rng.randint(1, 2)
        steps = [rng.uniform(17, 21) for _ in range(n - 1)]
        x = 50 - sum(steps) / 2 + rng.uniform(-4, 4) + (rng.uniform(-10, -4) if kind == "mixed" else 0)
        spires = []
        for j in range(n):
            sc = rng.uniform(.55, 1.0)
            spires.append((x, rng.uniform(78, 84), sc))
            x += steps[j] if j < n - 1 else 0
        if kind == "mixed" and rng.random() < .5:
            spires = [(100 - a, b, c) for a, b, c in spires]
        for sx, base, sc in sorted(spires, key=lambda t: t[1]):
            parts.append(spire(T(sx, base, sc, rng.random() < .5), rng))
            occupied += [(sx, base - k * sc, 16 * sc) for k in range(0, 66, 8)]
    if kind in ("boulders", "mixed"):
        n = rng.randint(2, 4) if kind == "boulders" else rng.randint(1, 3)
        placed = []
        for j in range(n):
            sc = rng.uniform(.7, 1.15) if j == 0 else rng.uniform(.5, .9)
            for _ in range(3000):
                x, y = rng.uniform(15, 85), rng.uniform(25 if kind == "boulders" else 55, 82)
                if in_hex(x, y, 15 * sc + 3) and all(math.hypot(x - a, y - b) > r + 15 * sc for a, b, r in occupied):
                    placed.append((x, y, sc))
                    occupied.append((x, y, 15 * sc))
                    break
        for x, y, sc in sorted(placed, key=lambda t: t[1]):
            parts.append(boulder(T(x, y, sc, rng.random() < .5)))
    chips = []
    for _ in range(4000):
        if len(chips) >= rng.randint(2, 4):
            break
        x, y = rng.uniform(15, 85), rng.uniform(15, 90)
        if in_hex(x, y, 6) and all(math.hypot(x - a, y - b) > r + 4 for a, b, r in occupied):
            occupied.append((x, y, 8))
            sz = rng.uniform(.8, 1.1)
            chips.append(f'<path d="M{f(x - 3.5 * sz)} {f(y + 2 * sz)} L{f(x)} {f(y - 2 * sz)} L{f(x + 3 * sz)} {f(y + 3 * sz)} Z"/>')
    return svg("#6b6b6b", parts + ['<g stroke="#404040" stroke-width="2.5">' + "".join(chips) + "</g>"])


# ---------------------------------------------------------------- jungle
J_BR, J_LG = "#7a5230", "#6fa83a"


def leaf(t):
    return f'<path d="M{t.p(0, 0)} Q{t.p(-2, -10)} {t.p(4, -18)} Q{t.p(8, -8)} {t.p(0, 0)} Z"/>'


def jungle(rng):
    s = rng.uniform(.85, 1.05)
    px = rng.uniform(38, 62)
    base = rng.uniform(80, 86)
    lean = rng.uniform(-8, 8)
    t = T(px, base, s)
    top = (lean, -40)
    trunk = f'<path d="M{t.p(0, 0)} Q{t.p(-4 + lean * .3, -20)} {t.p(*top)}"/>'
    tx, ty = t.pt(*top)
    ft = T(tx, ty, s)
    droop = rng.uniform(-3, 3)
    fr = [("M", [0, 0]), ("Q", [-12, -12, -28, -6 + droop]), ("M", [0, 0]), ("Q", [12, -12, 28, -6 - droop]),
          ("M", [0, 0]), ("Q", [-10, -2, -20, 10 + droop]), ("M", [0, 0]), ("Q", [10, -2, 20, 10 - droop])]
    fronds = f'<path d="{ft.d(fr)}"/>'
    leaves = []
    occupied = [(px, base - 20, 12), (tx, ty, 24)]
    want = rng.choice([2, 2, 3])
    while len(leaves) < want:
        x, y = rng.uniform(12, 88), rng.uniform(40, 88)
        if in_hex(x, y, 4) and in_hex(x, y - 18, 4) and all(math.hypot(x - a, y - 9 - b) > r + 9 for a, b, r in occupied):
            occupied.append((x, y - 9, 9))
            leaves.append(leaf(T(x, y, rng.uniform(.85, 1.05), x > 50)))
    return svg("#1c3510", [
        f'<g stroke="{J_BR}" stroke-width="3">{trunk}</g>',
        f'<g stroke="{J_LG}" stroke-width="3">{fronds}</g>',
        f'<g stroke="{J_LG}" stroke-width="2.5">' + "".join(leaves) + "</g>",
    ])


# ---------------------------------------------------------------- swamp
def swamp(rng):
    pos = place(rng, [(16, 0), (21, -6), (21, -6), (5, 0)], margin=2)
    (rx, ry), waves, (bx, by) = pos[0], pos[1:3], pos[3]
    stems, heads = [], []
    n = rng.choice([2, 2, 3])
    for i in range(n):
        x = rx - 6 + i * 8 + rng.uniform(-1, 1)
        bot = ry + 14 + rng.uniform(-2, 2)
        top = ry - 12 + rng.uniform(-4, 4) + (i % 2) * 6
        lean = rng.uniform(-2, 2)
        big = i % 2 == 0
        w, h = (6, 12) if big else (5, 10)
        hx, hy = x + lean, top - h / 2 + 2
        stems.append(f"M{f(x)} {f(bot)} L{f(x + lean)} {f(top)}")
        heads.append(f'<rect x="{f(hx - w / 2)}" y="{f(hy - h / 2)}" width="{w}" height="{h}" rx="{w / 2}"/>')
    wv = "".join(f'<path d="M{f(x - 20)} {f(y)} Q{f(x - 10)} {f(y - 6)} {f(x)} {f(y)} T{f(x + 20)} {f(y)}"/>' for x, y in waves)
    bub = f'<circle cx="{f(bx)}" cy="{f(by)}" r="3"/><circle cx="{f(bx + 8)}" cy="{f(by - 4)}" r="2"/>'
    return svg("#4f6224", [
        f'<g stroke="#384a18" stroke-width="3">{wv}<path d="{" ".join(stems)}"/></g>',
        f'<g stroke="#25320f" stroke-width="2.5">' + "".join(heads) + bub + "</g>",
    ])


def reed_cluster(rng, rx, ry, n):
    stems, heads = [], []
    for i in range(n):
        x = rx - (n - 1) * 4 + i * 8 + rng.uniform(-1, 1)
        bot = ry + 14 + rng.uniform(-2, 2)
        top = ry - 12 + rng.uniform(-4, 4) + (i % 2) * 6
        lean = rng.uniform(-2, 2)
        w, h = (6, 12) if i % 2 == 0 else (5, 10)
        hx, hy = x + lean, top - h / 2 + 2
        stems.append(f"M{f(x)} {f(bot)} L{f(x + lean)} {f(top)}")
        heads.append(f'<rect x="{f(hx - w / 2)}" y="{f(hy - h / 2)}" width="{w}" height="{h}" rx="{w / 2}"/>')
    return stems, heads


def lily(rng, x, y):
    r = rng.uniform(5, 7)
    a = rng.uniform(0, 360)
    p1 = (x + r * math.cos(math.radians(a - 20)), y + .6 * r * math.sin(math.radians(a - 20)))
    p2 = (x + r * math.cos(math.radians(a + 20)), y + .6 * r * math.sin(math.radians(a + 20)))
    return f'<path d="M{f(x)} {f(y)} L{f(p2[0])} {f(p2[1])} A{f(r)} {f(.6 * r)} 0 1 1 {f(p1[0])} {f(p1[1])} Z"/>'


def swamp_mix(rng, kind):
    """kind: reeds as 'none', 'two' (clusters), 'big' (one cluster of 4), 'single'; '+lily' adds lily pads."""
    reeds, lilies = kind.split("+")[0], kind.endswith("+lily")
    clusters = {"none": [], "two": [(16, 2), (16, 2)], "big": [(20, 4)], "single": [(16, 1)]}[reeds]
    n_waves = 3 if reeds == "none" else 2
    nb = 2 if reeds == "none" else 1
    items = [(r, 6) for r, _ in clusters] + [(18, -7)] * n_waves + [(6, 2)] * nb + [(6, 2)] * (2 if lilies else 0)
    pos = place(rng, items, margin=0)
    stems, heads = [], []
    for (x, y), (_, n) in zip(pos, clusters):
        st, hd = reed_cluster(rng, x, y + 6, n if reeds != "two" else rng.choice([2, 3]))
        stems += st
        heads += hd
    pos = pos[len(clusters):]
    waves, pos = pos[:n_waves], pos[n_waves:]
    bubbles, pads = pos[:nb], pos[nb:]
    wv = "".join(f'<path d="M{f(x - 20)} {f(y)} Q{f(x - 10)} {f(y - 6)} {f(x)} {f(y)} T{f(x + 20)} {f(y)}"/>' for x, y in waves)
    bub = "".join(f'<circle cx="{f(x)}" cy="{f(y)}" r="3"/><circle cx="{f(x + 8)}" cy="{f(y - 4)}" r="2"/>' for x, y in bubbles)
    lp = "".join(lily(rng, x, y) for x, y in pads)
    stem_path = f'<path d="{" ".join(stems)}"/>' if stems else ""
    return svg("#4f6224", [
        f'<g stroke="#384a18" stroke-width="3">{wv}{stem_path}</g>',
        f'<g stroke="#25320f" stroke-width="2.5">' + "".join(heads) + bub + lp + "</g>",
    ])


# ---- jungle plants: each returns (brown, green_w3, green_w2.5) snippets
def j_palm(rng, x, base, s, lean=None):
    lean = rng.uniform(-8, 8) if lean is None else lean
    t = T(x, base, s)
    top = (lean, -40)
    trunk = f'<path d="M{t.p(0, 0)} Q{t.p(-4 + lean * .3, -20)} {t.p(*top)}"/>'
    ft = T(*t.pt(*top), s)
    droop = rng.uniform(-3, 3)
    fr = [("M", [0, 0]), ("Q", [-12, -12, -28, -6 + droop]), ("M", [0, 0]), ("Q", [12, -12, 28, -6 - droop]),
          ("M", [0, 0]), ("Q", [-10, -2, -20, 10 + droop]), ("M", [0, 0]), ("Q", [10, -2, 20, 10 - droop])]
    return trunk, f'<path d="{ft.d(fr)}"/>', ""


def j_fern(rng, x, base, s, flip=False):
    t = T(x, base, s, flip)
    j = lambda: rng.uniform(-2, 2)
    leaves = [[("M", [0, 0]), ("Q", [-2, -18 + j(), 6 + j(), -28 + j()]), ("Q", [10, -14, 0, 0])],
              [("M", [0, 0]), ("Q", [-10, -12, -22 + j(), -12 + j()]), ("Q", [-14, 0, 0, 0])],
              [("M", [0, 0]), ("Q", [12, -10, 26 + j(), -8 + j()]), ("Q", [16, 2, 0, 0])]]
    return "", "", "".join(f'<path d="{t.d(l)} Z"/>' for l in leaves)


def j_vine(rng, dip):
    """Brown liana sagging across the top of the hex with leafy hanging strands."""
    y1, y2 = rng.uniform(8, 16), rng.uniform(8, 16)
    ctl = (y1 + y2) / 2 + 2 * dip
    vine = f'<path d="M14 {f(y1)} Q50 {f(ctl)} 86 {f(y2)}"/>'
    strands, leaves = "", ""
    for x in sorted(rng.sample([30, 38, 46, 56, 64, 70], rng.randint(2, 3))):
        tt = (x - 14) / 72
        yv = (1 - tt) ** 2 * y1 + 2 * tt * (1 - tt) * ctl + tt ** 2 * y2
        ln = rng.uniform(12, 20)
        strands += f'<path d="M{f(x)} {f(yv)} L{f(x)} {f(yv + ln)}"/>'
        for k, side in ((.55, -1), (1.0, 1)):
            ly = yv + ln * k
            leaves += f'<path d="M{f(x)} {f(ly)} Q{f(x + 6 * side)} {f(ly - 2)} {f(x + 8 * side)} {f(ly + 4)}"/>'
    return vine + strands, "", leaves


def j_strand(rng, x):
    """A single vine hanging from the canopy, leaves alternating down it."""
    ln = rng.uniform(40, 56)
    w = rng.uniform(4, 6)
    d = f'<path d="M{f(x)} 4 Q{f(x + w)} {f(4 + ln / 4)} {f(x)} {f(4 + ln / 2)} T{f(x)} {f(4 + ln)}"/>'
    leaves = ""
    for k in range(3):
        y = 4 + ln * (.35 + .25 * k)
        leaves += leaf(T(x, y, .6, k % 2 == 1))
    return d, "", leaves


def j_banana(rng, x, base, s, flip=False):
    t = T(x, base, s, flip)
    stem = f'<path d="M{t.p(0, 0)} Q{t.p(2, -14)} {t.p(0, -26)}"/>'
    l1 = f'M{t.p(0, -26)} Q{t.p(-14, -42)} {t.p(-32, -36)} Q{t.p(-18, -24)} {t.p(0, -26)} Z M{t.p(0, -26)} Q{t.p(-16, -34)} {t.p(-32, -36)}'
    l2 = f'M{t.p(0, -26)} Q{t.p(8, -48)} {t.p(26, -50)} Q{t.p(22, -32)} {t.p(0, -26)} Z M{t.p(0, -26)} Q{t.p(14, -38)} {t.p(26, -50)}'
    l3 = f'M{t.p(0, -14)} Q{t.p(14, -18)} {t.p(26, -8)} Q{t.p(12, -6)} {t.p(0, -14)} Z'
    return stem, "", f'<path d="{l1}"/><path d="{l2}"/><path d="{l3}"/>'


def j_spiky(rng, x, base, s):
    t = T(x, base, s)
    out = ""
    m = rng.randint(5, 7)
    for k in range(m):
        ang = math.radians(-60 + k * 120 / (m - 1) + rng.uniform(-5, 5))
        ln = (26 - abs(math.degrees(ang)) * .12) * rng.uniform(.9, 1.1)
        tip = (ln * math.sin(ang), -ln * math.cos(ang))
        px, py = math.cos(ang) * 3, math.sin(ang) * 3
        mx, my = tip[0] * .5, tip[1] * .5
        out += f'<path d="M{t.p(0, 0)} Q{t.p(mx - px, my - py)} {t.p(*tip)} Q{t.p(mx + px, my + py)} {t.p(0, 0)} Z"/>'
    return "", "", out


def j_leaves(rng, x, y, s=1.0):
    return "", "", leaf(T(x, y, s, x > 50))


JUNGLE_MIXES = [
    lambda r: [j_palm(r, r.uniform(34, 42), 84, .95), j_palm(r, r.uniform(68, 74), 86, .6)],
    lambda r: [j_vine(r, r.uniform(12, 18)), j_fern(r, r.uniform(44, 56), 86, 1.0, r.random() < .5)],
    lambda r: [j_banana(r, r.uniform(44, 54), 86, 1.0, r.random() < .5), j_leaves(r, 22, 84, .9), j_leaves(r, 78, 84, .9)],
    lambda r: [j_vine(r, r.uniform(6, 10)), j_palm(r, r.uniform(44, 58), 86, .85)],
    lambda r: [j_fern(r, 32, 86, .85), j_spiky(r, 68, 84, .8), j_strand(r, r.uniform(46, 54))],
    lambda r: [j_banana(r, 32, 84, .8, True), j_banana(r, 64, 80, .9)],
    lambda r: [j_spiky(r, r.uniform(28, 34), 84, .9), j_palm(r, r.uniform(62, 68), 84, .7)],
    lambda r: [j_vine(r, r.uniform(8, 12)), j_banana(r, r.uniform(44, 54), 88, .85, r.random() < .5)],
    lambda r: [j_strand(r, r.uniform(28, 34)), j_strand(r, r.uniform(66, 72)), j_fern(r, 50, 86, 1.0)],
    lambda r: [j_spiky(r, 30, 84, .8), j_fern(r, 68, 84, .85), j_vine(r, r.uniform(8, 14))],
]


def jungle_mix(rng, idx):
    parts = JUNGLE_MIXES[idx](rng)
    return svg("#1c3510", [
        f'<g stroke="{J_BR}" stroke-width="3">' + "".join(p[0] for p in parts) + "</g>",
        f'<g stroke="{J_LG}" stroke-width="3">' + "".join(p[1] for p in parts) + "</g>",
        f'<g stroke="{J_LG}" stroke-width="2.5">' + "".join(p[2] for p in parts) + "</g>",
    ])


# ---------------------------------------------------------------- ice
def walk(rng, x, y, ang, steps, step=(8, 12), turn=35):
    pts = [(x, y)]
    for _ in range(steps):
        ang += rng.uniform(-turn, turn)
        d = rng.uniform(*step)
        nx, ny = x + d * math.cos(math.radians(ang)), y + d * math.sin(math.radians(ang))
        if not in_hex(nx, ny, 10):
            break
        x, y = nx, ny
        pts.append((x, y))
    return pts


def ice(rng):
    for _ in range(100):
        a = rng.uniform(0, 360)
        sx, sy = 50 + 30 * math.cos(math.radians(a)), 50 + 26 * math.sin(math.radians(a))
        main = walk(rng, sx, sy, a + 180, 7)
        if len(main) >= 6:
            break
    j = rng.randrange(2, len(main) - 1)
    branch = walk(rng, *main[j], math.degrees(math.atan2(main[j + 1][1] - main[j][1], main[j + 1][0] - main[j][0])) + rng.choice([-70, 70]), 3)
    d = "M" + " L".join(f"{f(x)} {f(y)}" for x, y in main) + " M" + " L".join(f"{f(x)} {f(y)}" for x, y in branch)
    twigs = []
    for k in rng.sample(range(1, len(main)), 3):
        x, y = main[k]
        a = rng.uniform(0, 360)
        twigs.append(f"M{f(x)} {f(y)} L{f(x + 7 * math.cos(math.radians(a)))} {f(y + 7 * math.sin(math.radians(a)))}")
    crack = main + branch
    shines = []
    while len(shines) < 2:
        x, y = rng.uniform(15, 85), rng.uniform(15, 85)
        if in_hex(x, y, 12) and all(math.hypot(x - a, y - b) > 11 for a, b in crack) and all(math.hypot(x - a, y - b) > 20 for a, b, _ in shines):
            shines.append((x, y, len(shines) == 0 or rng.random() < .5))
    sh = ""
    for x, y, pair in shines:
        sh += f'<path d="M{f(x - 6)} {f(y + 6)} L{f(x + 6)} {f(y - 6)}" stroke-width="4"/>'
        if pair:
            sh += f'<path d="M{f(x - 1)} {f(y + 9)} L{f(x + 7)} {f(y + 1)}" stroke-width="2.5"/>'
    return svg("#a8d8e8", [
        f'<g stroke="#bde4f0">{sh}</g>',
        f'<g stroke="#7fb3c6" stroke-width="1.6"><path d="{d}"/></g>',
        f'<g stroke="#7fb3c6" stroke-width="1"><path d="{" ".join(twigs)}"/></g>',
    ])


def snow(rng):
    s = rng.uniform(.75, 1.05)
    pos = place(rng, [(15 * s, 0), (24, -10), (5, 0), (5, 0), (5, 0), (14, -4)], margin=2)
    (fx, fy), (dx, dy), sparks, (wx, wy) = pos[0], pos[1], pos[2:5], pos[5]
    t = T(fx, fy, s)
    flake = f'<path d="M{t.p(0, -13)} L{t.p(0, 13)} M{t.p(-11, -7)} L{t.p(11, 7)} M{t.p(-11, 7)} L{t.p(11, -7)}"/>'
    w = rng.uniform(32, 38)
    drift = f'<path d="M{f(dx - w)} {f(dy + 1)} Q{f(dx - w / 2)} {f(dy - 9)} {f(dx)} {f(dy + 1)} T{f(dx + w)} {f(dy - 1)}"/>'
    sp = "".join(f'<path d="M{f(x)} {f(y - 3)} L{f(x)} {f(y + 3)} M{f(x - 3)} {f(y)} L{f(x + 3)} {f(y)}"/>' for x, y in sparks)
    wd = f'<path d="M{f(wx - 14)} {f(wy + 1)} q14 -6 28 0"/>'
    return svg("#dbeff6", [f'<g stroke="#a8d8e8" stroke-width="3">{flake}{drift}</g>', f'<g stroke="#ffffff" stroke-width="2.5">{sp}{wd}</g>'])


def sheen(x, y, length, pair):
    h = length / 2
    out = f'<path d="M{f(x - h)} {f(y + h)} L{f(x + h)} {f(y - h)}" stroke-width="4"/>'
    if pair:
        out += f'<path d="M{f(x - h + 5)} {f(y + h + 3)} L{f(x + h - 1)} {f(y - h + 7)}" stroke-width="2.5"/>'
    return out


def ice_mix(rng, kind):
    """kind: 'sheen' (light streaks only, no crack) or 'bubbles' (small dark bubbles, optional sheen, no crack)."""
    parts = []
    if kind == "sheen":
        n = rng.randint(2, 4)
        pos = place(rng, [(rng.uniform(8, 13), 2) for _ in range(n)], margin=6)
        parts.append('<g stroke="#bde4f0">' + "".join(sheen(x, y, rng.uniform(10, 20), rng.random() < .6) for x, y in pos) + "</g>")
    else:
        with_sheen = kind == "bubbles+sheen"
        groups = rng.randint(2, 3)
        items = [(7, 0)] * groups + ([(10, 2)] if with_sheen else [])
        pos = place(rng, items, margin=6)
        if with_sheen:
            x, y = pos[-1]
            parts.append('<g stroke="#bde4f0">' + sheen(x, y, rng.uniform(12, 16), True) + "</g>")
        bub = ""
        for gx, gy in pos[:groups]:
            for _ in range(rng.randint(3, 5)):
                bub += f'<circle cx="{f(gx + rng.uniform(-6, 6))}" cy="{f(gy + rng.uniform(-6, 6))}" r="{f(rng.uniform(1.3, 3))}"/>'
        parts.append(f'<g stroke="#7fb3c6" stroke-width="1.4">{bub}</g>')
    return svg("#a8d8e8", parts)


def snow_mix(rng):
    """Snow with a varied number (1-6) and size of snowflakes."""
    n = rng.choice([1, 2, 3, 4, 5, 6])
    if n == 1:
        sizes = [rng.uniform(1.1, 1.5)]
    else:
        sizes = sorted((rng.uniform(.35, 1.1) for _ in range(n)), reverse=True)
    drifts = rng.choice([0, 1, 1, 2])
    sparks = rng.randint(1, 4)
    while True:
        items = [(13 * sc, 1) for sc in sizes] + [(20, -10)] * drifts + [(4, 0)] * sparks + [(13, -4)]
        try:
            pos = place(rng, items, margin=2)
            break
        except RuntimeError:
            drifts, sparks = max(0, drifts - 1), max(1, sparks - 1)
    flakes = ""
    for (fx, fy), sc in zip(pos, sizes):
        t = T(fx, fy, sc)
        sw = 3 if sc >= .6 else 2
        flakes += (f'<path d="M{t.p(0, -13)} L{t.p(0, 13)} M{t.p(-11, -7)} L{t.p(11, 7)} M{t.p(-11, 7)} L{t.p(11, -7)}" '
                   f'stroke-width="{sw}"/>')
    pos = pos[n:]
    drift = ""
    for dx, dy in pos[:drifts]:
        w = rng.uniform(18, 22)
        drift += f'<path d="M{f(dx - w)} {f(dy + 1)} Q{f(dx - w / 2)} {f(dy - 9)} {f(dx)} {f(dy + 1)} T{f(dx + w)} {f(dy - 1)}"/>'
    pos = pos[drifts:]
    sp = "".join(f'<path d="M{f(x)} {f(y - 3)} L{f(x)} {f(y + 3)} M{f(x - 3)} {f(y)} L{f(x + 3)} {f(y)}"/>' for x, y in pos[:sparks])
    wx, wy = pos[sparks]
    wd = f'<path d="M{f(wx - 13)} {f(wy + 1)} q13 -6 26 0"/>'
    return svg("#dbeff6", [f'<g stroke="#a8d8e8" stroke-width="3">{flakes}{drift}</g>', f'<g stroke="#ffffff" stroke-width="2.5">{sp}{wd}</g>'])


def snow_light(rng):
    """Sparser snow: mostly white flakes, at most one or two blue ones."""
    blue = sorted((rng.uniform(.5, 1.0) for _ in range(rng.choice([0, 1, 1, 2]))), reverse=True)
    white = [rng.uniform(.35, .7) for _ in range(rng.randint(2, 4))]
    drifts = rng.choice([0, 1])
    sparks = rng.randint(0, 2)
    items = [(13 * sc, 3) for sc in blue + white] + [(20, -10)] * drifts + [(4, 2)] * sparks
    pos = place(rng, items, margin=2)

    def flake(x, y, sc, sw):
        t = T(x, y, sc)
        return (f'<path d="M{t.p(0, -13)} L{t.p(0, 13)} M{t.p(-11, -7)} L{t.p(11, 7)} M{t.p(-11, 7)} L{t.p(11, -7)}" '
                f'stroke-width="{sw}"/>')

    nb, nw = len(blue), len(white)
    bl = "".join(flake(x, y, sc, 3 if sc >= .6 else 2) for (x, y), sc in zip(pos[:nb], blue))
    wh = "".join(flake(x, y, sc, 2.5 if sc >= .5 else 2) for (x, y), sc in zip(pos[nb:nb + nw], white))
    rest = pos[nb + nw:]
    drift = ""
    for dx, dy in rest[:drifts]:
        w = rng.uniform(18, 22)
        drift += f'<path d="M{f(dx - w)} {f(dy + 1)} Q{f(dx - w / 2)} {f(dy - 9)} {f(dx)} {f(dy + 1)} T{f(dx + w)} {f(dy - 1)}"/>'
    sp = "".join(f'<path d="M{f(x)} {f(y - 3)} L{f(x)} {f(y + 3)} M{f(x - 3)} {f(y)} L{f(x + 3)} {f(y)}"/>' for x, y in rest[drifts:])
    return svg("#dbeff6", [f'<g stroke="#a8d8e8" stroke-width="3">{bl}{drift}</g>', f'<g stroke="#ffffff" stroke-width="2.5">{wh}{sp}</g>'])


# ---------------------------------------------------------------- basalt
B_NODES = {"A": (22, 46), "B": (38, 30), "C": (58, 42), "D": (80, 34), "E": (54, 64), "F": (32, 70), "G": (76, 72)}
B_EDGES = [("A", "B"), ("B", "C"), ("C", "D"), ("C", "E"), ("E", "F"), ("E", "G"), ("A", "F"), ("D", "G")]
B_OUT = [("A", (0, 42)), ("B", (50, 10)), ("D", (100, 38)), ("F", (22, 94)), ("G", (78, 94))]


def basalt(rng, idx):
    rot = math.radians(60 * (idx % 6) + rng.uniform(-12, 12))
    flip = idx >= 5

    def tr(x, y, jit=0):
        x, y = x - 50, y - 50
        if flip:
            x = -x
        x, y = x * math.cos(rot) - y * math.sin(rot), x * math.sin(rot) + y * math.cos(rot)
        return 50 + x + rng.uniform(-jit, jit), 50 + y + rng.uniform(-jit, jit)

    nodes = {k: tr(*v, 5) for k, v in B_NODES.items()}
    segs = [(nodes[a], nodes[b]) for a, b in B_EDGES]
    for k, (ox, oy) in B_OUT:
        x, y = tr(ox, oy)
        r = math.hypot(x - 50, y - 50)
        segs.append((nodes[k], (50 + (x - 50) * 62 / r, 50 + (y - 50) * 62 / r)))
    d = " ".join(f"M{f(a[0])} {f(a[1])} L{f(b[0])} {f(b[1])}" for a, b in segs)

    def segdist(p, a, b):
        ax, ay, bx, by = *a, *b
        t = max(0, min(1, ((p[0] - ax) * (bx - ax) + (p[1] - ay) * (by - ay)) / ((bx - ax) ** 2 + (by - ay) ** 2)))
        return math.hypot(p[0] - ax - t * (bx - ax), p[1] - ay - t * (by - ay))

    dashes = []
    while len(dashes) < 4:
        x, y = rng.uniform(15, 85), rng.uniform(15, 85)
        if in_hex(x, y, 8) and all(segdist((x, y), a, b) > 8 for a, b in segs) and all(math.hypot(x - a, y - b) > 14 for a, b in dashes):
            dashes.append((x, y))
    ds = "".join(f'<path d="M{f(x - 4)} {f(y - 1.5)} L{f(x + 4)} {f(y + 1.5)}"/>' for x, y in dashes)
    return svg("#3a2622", [
        f'<g stroke="#c4381c" stroke-width="4.5"><path d="{d}"/></g>',
        f'<g stroke="#ffb347" stroke-width="1.5"><path d="{d}"/></g>',
        f'<g stroke="#55392f" stroke-width="2.5">{ds}</g>',
    ])


# Basalt cracks that tile: every variant leaves the hex at the midpoint of each of the six edges,
# so a crack always meets the matching crack in the neighbouring hex.
B_EXITS = [((50, 6.7), (0, -1)), ((87.5, 28.35), (.866, -.5)), ((87.5, 71.65), (.866, .5)),
           ((50, 93.3), (0, 1)), ((12.5, 71.65), (-.866, .5)), ((12.5, 28.35), (-.866, -.5))]


def segdist(p, a, b):
    ax, ay, bx, by = *a, *b
    t = max(0, min(1, ((p[0] - ax) * (bx - ax) + (p[1] - ay) * (by - ay)) / ((bx - ax) ** 2 + (by - ay) ** 2)))
    return math.hypot(p[0] - ax - t * (bx - ax), p[1] - ay - t * (by - ay))


def basalt_tiling(rng):
    n = rng.choice([3, 4, 4, 5])
    a0 = rng.uniform(0, 360)
    nodes = []
    for k in range(n):
        a = math.radians(a0 + k * 360 / n + rng.uniform(-15, 15))
        r = rng.uniform(14, 22)
        nodes.append((50 + r * math.cos(a), 50 + r * math.sin(a) * .95))
    segs = [(nodes[k], nodes[(k + 1) % n]) for k in range(n)]
    if n >= 5 and rng.random() < .6:  # a chord splits the central cell
        segs.append((nodes[0], nodes[2]))
    for (ex, ey), (nx, ny) in B_EXITS:
        near = min(nodes, key=lambda p: math.hypot(p[0] - ex, p[1] - ey))
        # bend through a jittered midpoint so the spokes are not dead straight
        mx = (near[0] + ex) / 2 + rng.uniform(-3, 3)
        my = (near[1] + ey) / 2 + rng.uniform(-3, 3)
        segs += [(near, (mx, my)), ((mx, my), (ex, ey)), ((ex, ey), (ex + 4 * nx, ey + 4 * ny))]
    d = " ".join(f"M{f(a[0])} {f(a[1])} L{f(b[0])} {f(b[1])}" for a, b in segs)
    dashes = []
    for _ in range(5000):
        if len(dashes) >= 4:
            break
        x, y = rng.uniform(15, 85), rng.uniform(15, 85)
        if in_hex(x, y, 8) and all(segdist((x, y), a, b) > 8 for a, b in segs) and all(math.hypot(x - a, y - b) > 14 for a, b in dashes):
            dashes.append((x, y))
    ds = "".join(f'<path d="M{f(x - 4)} {f(y - 1.5)} L{f(x + 4)} {f(y + 1.5)}"/>' for x, y in dashes)
    return svg("#3a2622", [
        f'<g stroke="#c4381c" stroke-width="4.5"><path d="{d}"/></g>',
        f'<g stroke="#ffb347" stroke-width="1.5"><path d="{d}"/></g>',
        f'<g stroke="#55392f" stroke-width="2.5">{ds}</g>',
    ])


def lava_bubble(x, y, r):
    out = f'<circle cx="{f(x)}" cy="{f(y)}" r="{f(r)}" stroke="#ff6a2a" stroke-width="2.5"/>'
    if r >= 3.5:
        out += (f'<path d="M{f(x - .6 * r)} {f(y - .1 * r)} Q{f(x - .56 * r)} {f(y - .6 * r)} {f(x - .1 * r)} {f(y - .64 * r)}" '
                f'stroke="#ffb347" stroke-width="2"/>')
    return out


def swirl(rng, x, y, r):
    turns = rng.uniform(1.2, 1.6)
    k = rng.choice([-1, 1])
    a0 = rng.uniform(0, 360)
    pts = []
    for i in range(41):
        t = i / 40
        a = math.radians(a0 + k * t * turns * 360)
        rr = r * (.15 + .85 * t)
        pts.append((x + rr * math.cos(a), y + rr * math.sin(a) * .8))
    color = rng.choice(["#ff6a2a", "#ffb347"])
    return f'<path d="M' + " L".join(f"{f(a)} {f(b)}" for a, b in pts) + f'" stroke="{color}" stroke-width="2.5"/>'


def dome(rng, x, y, s):
    """A bubble just swelling out of a small pool, before it pops."""
    return (f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(16 * s)}" ry="{f(5 * s)}" stroke="#9d2d16" stroke-width="3"/>'
            f'<path d="M{f(x - 9 * s)} {f(y)} A{f(9 * s)} {f(9 * s)} 0 0 1 {f(x + 9 * s)} {f(y)}" stroke="#ff6a2a" stroke-width="2.5"/>'
            f'<path d="M{f(x - 5 * s)} {f(y - 3 * s)} Q{f(x - 4.5 * s)} {f(y - 6.5 * s)} {f(x - 1 * s)} {f(y - 7 * s)}" stroke="#ffb347" stroke-width="2"/>')


def lava_mix(rng, kind):
    """kind: '+'-joined parts from bubbles, swirls, dome, pools."""
    want = kind.split("+")
    items = []
    if "dome" in want:
        sd = rng.uniform(.9, 1.15)
        items.append(("dome", 17 * sd, sd))
    if "pools" in want:
        for _ in range(2):
            sp = rng.uniform(.6, .8)
            items.append(("pool", 18 * sp, sp))
    if "swirls" in want:
        for _ in range(rng.randint(1, 2) if len(want) > 1 else rng.randint(2, 3)):
            r = rng.uniform(9, 13)
            items.append(("swirl", r, r))
    if "bubbles" in want:
        for _ in range(rng.randint(2, 4) if len(want) > 1 else rng.randint(5, 7)):
            r = rng.choice([2, 2.5, 3.5, 4.5, 5.5])
            items.append(("bubble", r, r))
    pos = place(rng, [(r, 2) for _, r, _ in items], margin=2)
    out = ""
    for (x, y), (what, _, v) in zip(pos, items):
        if what == "dome":
            out += dome(rng, x, y + 3, v)
        elif what == "pool":
            out += (f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(24 * v)}" ry="{f(8 * v)}" stroke="#9d2d16" stroke-width="3"/>'
                    f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(14 * v)}" ry="{f(4.5 * v)}" stroke="#ff6a2a" stroke-width="2.5"/>'
                    f'<circle cx="{f(x + rng.uniform(-6, 6))}" cy="{f(y - 10)}" r="2" fill="#ffb347"/>')
        elif what == "swirl":
            out += swirl(rng, x, y, v)
        else:
            out += lava_bubble(x, y, v)
    return svg("#c4381c", [f"<g>{out}</g>"])


def basalt_big(rng, split):
    """Tiling basalt with a large central plate, so the corner remainders stay small when three hexes meet.
    split: 0 (one plate), 1 (a chord makes two plates) or 3 (a centre node makes three plates)."""
    r_lo, r_hi = {0: (26, 30), 1: (30, 33), 3: (32, 35)}[split]
    nodes = []
    for (ex, ey), _ in B_EXITS:
        a = math.atan2(ey - 50, ex - 50) + math.radians(rng.uniform(-12, 12))
        r = rng.uniform(r_lo, r_hi)
        nodes.append((50 + r * math.cos(a), 50 + r * math.sin(a)))
    spokes = list(range(6))
    if split == 0 and rng.random() < .5:  # merge two neighbouring corners for a less regular five-sided plate
        k = rng.randrange(6)
        mid = ((nodes[k][0] + nodes[(k + 1) % 6][0]) / 2, (nodes[k][1] + nodes[(k + 1) % 6][1]) / 2)
        nodes[k] = nodes[(k + 1) % 6] = mid
    segs = [(nodes[k], nodes[(k + 1) % 6]) for k in range(6) if nodes[k] != nodes[(k + 1) % 6]]
    if split == 1:
        k = rng.randrange(3)
        segs.append((nodes[k], nodes[k + 3]))
    elif split == 3:
        c = (50 + rng.uniform(-5, 5), 50 + rng.uniform(-5, 5))
        k = rng.randrange(2)
        segs += [(c, nodes[k]), (c, nodes[k + 2]), (c, nodes[k + 4])]
    for k in spokes:
        (ex, ey), (nx, ny) = B_EXITS[k]
        segs += [(nodes[k], (ex, ey)), ((ex, ey), (ex + 4 * nx, ey + 4 * ny))]
    d = " ".join(f"M{f(a[0])} {f(a[1])} L{f(b[0])} {f(b[1])}" for a, b in segs)
    dashes = []
    for _ in range(5000):
        if len(dashes) >= 4:
            break
        x, y = rng.uniform(15, 85), rng.uniform(15, 85)
        if in_hex(x, y, 8) and all(segdist((x, y), a, b) > 7 for a, b in segs) and all(math.hypot(x - a, y - b) > 14 for a, b in dashes):
            dashes.append((x, y))
    ds = "".join(f'<path d="M{f(x - 4)} {f(y - 1.5)} L{f(x + 4)} {f(y + 1.5)}"/>' for x, y in dashes)
    return svg("#3a2622", [
        f'<g stroke="#c4381c" stroke-width="4.5"><path d="{d}"/></g>',
        f'<g stroke="#ffb347" stroke-width="1.5"><path d="{d}"/></g>',
        f'<g stroke="#55392f" stroke-width="2.5">{ds}</g>',
    ])


# ---- popping-bubble variations, each a different moment or shape of a burst
def dot(x, y, r=2):
    return f'<circle cx="{f(x)}" cy="{f(y)}" r="{f(r)}" fill="#ffb347"/>'


def pop_crown(rng, x, y, s):
    """Splash crown: spikes thrown up from the rim of a small pool, a droplet on each tip."""
    out = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(17 * s)}" ry="{f(5.5 * s)}" stroke="#9d2d16" stroke-width="3"/>'
    for a in (-60, -30, 0, 30, 60):
        bx, by = x + 12 * s * math.sin(math.radians(a)), y - 2 * s
        tx, ty = bx + 7 * s * math.sin(math.radians(a)), by - (11 - abs(a) * .06) * s * rng.uniform(.9, 1.1)
        out += f'<path d="M{f(bx)} {f(by)} Q{f(bx)} {f((by + ty) / 2)} {f(tx)} {f(ty)}" stroke="#ff6a2a" stroke-width="2.5"/>'
        out += dot(tx + 1.5 * math.sin(math.radians(a)), ty - 3.5, 1.6)
    return out


def pop_broken(rng, x, y, s):
    """The instant of popping: the bubble skin splits at the top and its pieces fly off."""
    r = 9 * s
    g = rng.uniform(-20, 20)
    a1, a2 = math.radians(-90 + g + 35), math.radians(-90 + g - 35)
    out = (f'<path d="M{f(x + r * math.cos(a1))} {f(y + r * math.sin(a1))} A{f(r)} {f(r)} 0 1 1 '
           f'{f(x + r * math.cos(a2))} {f(y + r * math.sin(a2))}" stroke="#ff6a2a" stroke-width="2.5"/>')
    for a in (-130, -90, -50):
        a = math.radians(a + g)
        px, py = x + (r + 7 * s) * math.cos(a), y + (r + 7 * s) * math.sin(a)
        out += (f'<path d="M{f(px - 3 * math.sin(a))} {f(py + 3 * math.cos(a))} L{f(px + 3 * math.sin(a))} {f(py - 3 * math.cos(a))}" '
                f'stroke="#ffb347" stroke-width="2.5"/>')
    out += dot(x - 2 * s, y + r * .1, 1.6) + dot(x + 3 * s, y - r * .25, 1.3)
    return out


def pop_ripple(rng, x, y, s):
    """Just after the pop: rings spreading out where the bubble was."""
    return (f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(19 * s)}" ry="{f(6.5 * s)}" stroke="#9d2d16" stroke-width="3"/>'
            f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(11 * s)}" ry="{f(3.8 * s)}" stroke="#ff6a2a" stroke-width="2.5"/>'
            + dot(x, y, 2.2) + dot(x + rng.uniform(-6, 6) * s, y - 9 * s, 1.6))


def pop_column(rng, x, y, s):
    """A jet thrown straight up, breaking into drops."""
    out = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(12 * s)}" ry="{f(4 * s)}" stroke="#9d2d16" stroke-width="3"/>'
    out += f'<path d="M{f(x - 3 * s)} {f(y - 1)} Q{f(x)} {f(y - 18 * s)} {f(x + 3 * s)} {f(y - 1)}" stroke="#ff6a2a" stroke-width="2.5"/>'
    lean = rng.uniform(-2, 2)
    for k, (h, r) in enumerate(((24, 2.2), (31, 1.8), (37, 1.4))):
        out += dot(x + lean * (k + 1) * s, y - h * s, r)
    return out


def pop_twin(rng, x, y, s):
    """Two bubbles of different sizes swelling side by side in one pool."""
    out = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(22 * s)}" ry="{f(6.5 * s)}" stroke="#9d2d16" stroke-width="3"/>'
    k = rng.choice([-1, 1])
    for dx, r in ((-6 * k, 8), (8 * k, 5)):
        cx, rr = x + dx * s, r * s
        out += f'<path d="M{f(cx - rr)} {f(y)} A{f(rr)} {f(rr)} 0 0 1 {f(cx + rr)} {f(y)}" stroke="#ff6a2a" stroke-width="2.5"/>'
        if r == 8:
            out += f'<path d="M{f(cx - .55 * rr)} {f(y - .35 * rr)} Q{f(cx - .5 * rr)} {f(y - .75 * rr)} {f(cx - .1 * rr)} {f(y - .8 * rr)}" stroke="#ffb347" stroke-width="2"/>'
    return out


def pop_jagged(rng, x, y, s):
    """A bubble tearing open: its top is ragged and spatters fly out."""
    r = 12 * s
    pts = [(x - r, y)]
    for k in range(1, 8):
        a = math.radians(180 + k * 180 / 8)
        rr = r * (1.0 if k in (1, 7) else rng.uniform(.45, .7) if k % 2 == 0 else rng.uniform(.95, 1.15))
        pts.append((x + rr * math.cos(a), y + rr * math.sin(a)))
    pts.append((x + r, y))
    out = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(17 * s)}" ry="{f(5 * s)}" stroke="#9d2d16" stroke-width="3"/>'
    out += '<path d="M' + " L".join(f"{f(a)} {f(b)}" for a, b in pts) + '" stroke="#ff6a2a" stroke-width="2.5"/>'
    for a in (-150, -110, -70, -30):
        a = math.radians(a + rng.uniform(-8, 8))
        out += dot(x + (r + 6 * s) * math.cos(a), y + (r + 6 * s) * math.sin(a), rng.uniform(1.3, 2))
    return out


# name: (draw, footprint radius, how far the shape rises above its pool)
POPS = {"crown": (pop_crown, 18, 16), "broken": (pop_broken, 17, 0), "ripple": (pop_ripple, 19, 0),
        "column": (pop_column, 21, 38), "twin": (pop_twin, 22, 8), "jagged": (pop_jagged, 18, 16)}


def lava_pop(rng, kind):
    """kind: a burst from POPS, optionally '+bubbles', '+swirl' or '+<another burst>'."""
    want = kind.split("+")
    items = []
    for w in want:
        if w in POPS:
            sc = rng.uniform(.9, 1.1)
            items.append((w, POPS[w][1] * sc, sc))
        elif w == "swirl":
            r = rng.uniform(9, 12)
            items.append(("swirl", r, r))
        elif w == "bubbles":
            for _ in range(rng.randint(2, 3)):
                r = rng.choice([2, 2.5, 3.5, 4.5])
                items.append(("bubble", r, r))
    pos = place(rng, [(r, 2) for _, r, _ in items], margin=2)
    out = ""
    for (x, y), (what, _, v) in zip(pos, items):
        if what in POPS:
            # centre the shape on its footprint: its pool sits half its rise below the anchor
            out += POPS[what][0](rng, x, y + POPS[what][2] * v / 2, v)
        elif what == "swirl":
            out += swirl(rng, x, y, v)
        else:
            out += lava_bubble(x, y, v)
    return svg("#c4381c", [f"<g>{out}</g>"])


# ---------------------------------------------------------------- lava
def lava(rng):
    s = rng.uniform(.8, 1.05)
    cx, cy = rng.uniform(42, 58), rng.uniform(58, 66)
    t = T(cx, cy, s, rng.random() < .5)
    h = rng.uniform(.85, 1.15)
    pool = (f'<ellipse cx="{f(cx)}" cy="{f(cy)}" rx="{f(24 * s)}" ry="{f(8 * s)}"/>')
    inner = f'<ellipse cx="{f(cx)}" cy="{f(cy)}" rx="{f(14 * s)}" ry="{f(4.5 * s)}"/>'
    splash = (f'<path d="M{t.p(-10, -2)} C{t.p(-5.5, -5.5 * h)} {t.p(-6, -14 * h)} {t.p(-12.7, -20.3 * h)}"/>'
              f'<path d="M{t.p(12.8, -21 * h)} C{t.p(6.5, -14.2 * h)} {t.p(7, -4.4 * h)} {t.p(10, -2)}"/>')
    dx, dy = t.pt(rng.uniform(4, 16), -42 * h)
    drop = f'<circle cx="{f(dx)}" cy="{f(dy)}" r="4"/>'
    dots = "".join(f'<circle cx="{f(a)}" cy="{f(b)}" r="2"/>' for a, b in
                   [t.pt(-7.3 + rng.uniform(-3, 3), -38.7 * h), t.pt(-17 + rng.uniform(-2, 2), -26 * h), t.pt(17 + rng.uniform(-2, 2), -26 * h)])
    occupied = [(cx, cy, 26 * s), (cx, cy - 25 * h * s, 20), (dx, dy, 8)]
    bubbles = []
    for r in rng.sample([5, 5, 3.5, 4], 3):
        for _ in range(2000):
            x, y = rng.uniform(10, 90), rng.uniform(10, 90)
            if in_hex(x, y, r + 5) and all(math.hypot(x - a, y - b) > rr + r + 3 for a, b, rr in occupied):
                occupied.append((x, y, r))
                bubbles.append((x, y, r))
                break
    bub = "".join(f'<circle cx="{f(x)}" cy="{f(y)}" r="{f(r)}"/>' for x, y, r in bubbles)
    hil = "".join(f'<path d="M{f(x - 3)} {f(y - .5)} Q{f(x - 2.8)} {f(y - 3)} {f(x - .5)} {f(y - 3.2)}"/>' for x, y, r in bubbles if r >= 4)
    return svg("#c4381c", [
        f'<g stroke="#9d2d16" stroke-width="3">{pool}</g>',
        f'<g stroke="#ff6a2a" stroke-width="2.5">{inner}</g>',
        f'<g stroke="#ffb347" stroke-width="2.5">{splash}{drop}</g>',
        f'<g fill="#ffb347">{dots}</g>',
        f'<g stroke="#ff6a2a" stroke-width="2.5">{bub}</g>',
        f'<g stroke="#ffb347" stroke-width="2">{hil}</g>',
    ])


# ---------------------------------------------------------------- poison pits
def poison(rng):
    n = rng.choice([2, 2, 3])
    sizes = [(rng.uniform(13, 16), rng.uniform(6, 7)) for _ in range(n)] if n == 2 else [(rng.uniform(10, 12), 5) for _ in range(n)]
    pos = place(rng, [(rx + 2, 4) for rx, _ in sizes], margin=0)
    pits, lines, bub = "", "", ""
    for (x, y), (rx, ry) in zip(pos, sizes):
        y += 8
        pits += f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}"/>'
        lines += f"M{f(x - rx / 2)} {f(y)} L{f(x + rx / 2)} {f(y)} "
        k = rng.choice([-1, 1])
        bub += f'<circle cx="{f(x - 4 * k)}" cy="{f(y - 14)}" r="3"/><circle cx="{f(x + 3 * k)}" cy="{f(y - 22)}" r="2"/>'
    return svg("#1a1a1a", [
        f'<g stroke="#2f6b1f" stroke-width="3">{pits}</g>',
        f'<g stroke="#7ccf45" stroke-width="2.5">{bub}<path d="{lines.strip()}"/></g>',
    ])


PZ_DARK, PZ_LIT = "#2f6b1f", "#7ccf45"


def pz_pit(rng, x, y, rx, ry, bubbles=2, shape="ellipse"):
    """A pit (oval or lumpy puddle) with its surface line, plus rising bubbles. Returns (dark, lit)."""
    if shape == "blob":
        dark = f'<path d="{blob(x, y, rx, ry, [rng.uniform(.8, 1.15) for _ in range(8)])}"/>'
    else:
        dark = f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}"/>'
    lit = f'<path d="M{f(x - rx / 2)} {f(y)} L{f(x + rx / 2)} {f(y)}"/>'
    k = rng.choice([-1, 1])
    for j in range(bubbles):
        bx = x + k * rng.uniform(-5, 5)
        by = y - 12 - j * 8 + rng.uniform(-1.5, 1.5)
        lit += f'<circle cx="{f(bx)}" cy="{f(by)}" r="{f(max(1.2, 3.2 - j * .7))}"/>'
        k = -k
    return dark, lit


def pz_fumes(rng, x, y, n=2):
    """Wavy fumes rising from a pit."""
    out = ""
    for j in range(n):
        fx = x + (j - (n - 1) / 2) * 9 + rng.uniform(-1, 1)
        w = rng.choice([-1, 1]) * 3
        out += f'<path d="M{f(fx)} {f(y - 6)} Q{f(fx + w)} {f(y - 12)} {f(fx)} {f(y - 18)} T{f(fx)} {f(y - 30)}"/>'
    return out


def pz_rings(x, y, rx, ry):
    return (f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx * .62)}" ry="{f(ry * .62)}"/>'
            f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx * .28)}" ry="{f(ry * .28)}"/>')


def pz_pop(x, y):
    """A bubble bursting in the pit: little spikes and drops thrown up."""
    out = ""
    for a in (-45, -15, 15, 45):
        bx = x + 7 * math.sin(math.radians(a))
        tx, ty = bx + 4 * math.sin(math.radians(a)), y - 9 + abs(a) * .05
        out += f'<path d="M{f(bx)} {f(y - 1)} L{f(tx)} {f(ty)}"/><circle cx="{f(tx + 1.2 * math.sin(math.radians(a)))}" cy="{f(ty - 3.5)}" r="1.2"/>'
    return out


def poison_mix(rng, kind):
    dark, lit = [], []

    def pits(specs, margin=0):
        pos = place(rng, [(rx + 2, 6) for rx, _ in specs], margin=margin)
        return [(x, y + 8, rx, ry) for (x, y), (rx, ry) in zip(pos, specs)]

    if kind == "one-big":
        x, y, rx, ry = pits([(rng.uniform(20, 24), rng.uniform(8, 9))])[0]
        d, l = pz_pit(rng, x, y, rx, ry, bubbles=4)
        dark.append(d); lit.append(l)
        for _ in range(2):
            lit.append(f'<circle cx="{f(x + rng.uniform(-rx, rx) * .7)}" cy="{f(y - rng.uniform(8, 16))}" r="{f(rng.uniform(1.2, 2))}"/>')
    elif kind in ("three-small", "four-tiny"):
        n, size = (3, (9, 11)) if kind == "three-small" else (4, (6, 8))
        for x, y, rx, ry in pits([(rng.uniform(*size), size[0] * .45) for _ in range(n)]):
            d, l = pz_pit(rng, x, y, rx, ry, bubbles=rng.choice([1, 2]))
            dark.append(d); lit.append(l)
    elif kind == "fumes":
        x, y, rx, ry = pits([(rng.uniform(15, 18), 6.5)])[0]
        y += 10
        d, l = pz_pit(rng, x, y, rx, ry, bubbles=0)
        dark += [d, pz_fumes(rng, x, y, rng.choice([2, 3]))]; lit.append(l)
        lit.append(f'<circle cx="{f(x + rng.choice([-1, 1]) * (rx + 4))}" cy="{f(y - 8)}" r="2.5"/>')
    elif kind == "blobs":
        for x, y, rx, ry in pits([(rng.uniform(13, 16), 7), (rng.uniform(10, 12), 5.5)]):
            d, l = pz_pit(rng, x, y, rx, ry, bubbles=rng.choice([1, 2]), shape="blob")
            dark.append(d); lit.append(l)
    elif kind == "rings":
        x, y, rx, ry = pits([(rng.uniform(19, 22), 8)])[0]
        dark.append(f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}"/>')
        lit.append(pz_rings(x, y, rx, ry))
        lit.append(f'<circle cx="{f(x + rng.uniform(-4, 4))}" cy="{f(y - 14)}" r="2.5"/>')
    elif kind == "bubbles":
        x, y, rx, ry = pits([(9, 4)])[0]
        d, l = pz_pit(rng, x, y, rx, ry, bubbles=0)
        dark.append(d); lit.append(l)
        for bx, by in place(rng, [(4, 2)] * rng.randint(5, 7), margin=4):
            if math.hypot(bx - x, by - y) > rx + 5:
                lit.append(f'<circle cx="{f(bx)}" cy="{f(by)}" r="{f(rng.uniform(1.5, 3.2))}"/>')
    elif kind == "pop":
        x, y, rx, ry = pits([(rng.uniform(15, 18), 6.5)])[0]
        y += 6
        dark.append(f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}"/>')
        lit.append(pz_pop(x, y))
        lit.append(f'<path d="M{f(x - rx / 2)} {f(y + 1.5)} L{f(x - 9)} {f(y + 1.5)} M{f(x + 9)} {f(y + 1.5)} L{f(x + rx / 2)} {f(y + 1.5)}"/>')
    elif kind == "blob-fumes":
        x, y, rx, ry = pits([(rng.uniform(15, 18), 7)])[0]
        y += 10
        d, l = pz_pit(rng, x, y, rx, ry, bubbles=0, shape="blob")
        dark += [d, pz_fumes(rng, x, y, 2)]; lit.append(l)
        lit.append(f'<circle cx="{f(x + rng.choice([-1, 1]) * (rx + 4))}" cy="{f(y - 10)}" r="2.5"/>')
    elif kind == "big-small":
        for (x, y, rx, ry), nb in zip(pits([(rng.uniform(17, 20), 7.5), (rng.uniform(7, 9), 3.5)]), (3, 1)):
            d, l = pz_pit(rng, x, y, rx, ry, bubbles=nb)
            dark.append(d); lit.append(l)
    return svg("#1a1a1a", [
        f'<g stroke="{PZ_DARK}" stroke-width="3">' + "".join(dark) + "</g>",
        f'<g stroke="{PZ_LIT}" stroke-width="2.5">' + "".join(lit) + "</g>",
    ])


# ---------------------------------------------------------------- darkness: distinct evil icons, shared palette
DK_BG, DK, RED = "#1a1a1a", "#2e2e2e", "#550000"


def dark(main, accent="", w=4):
    parts = [f'<g stroke="{DK}" stroke-width="{w}">{main}</g>']
    if accent:
        parts.append(f'<g stroke="{RED}" stroke-width="1.5">{accent}</g>')
    return svg(DK_BG, parts)


def star(cx, cy, r, inverted=True):
    pts = []
    for i in range(5):
        a = math.radians(90 + i * 144) if inverted else math.radians(-90 + i * 144)
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    return "M" + " L".join(f"{f(x)} {f(y)}" for x, y in pts) + " Z"


DARKNESS = [
    # 01 demon face: curved horns, slanted eyes, fanged grin
    dark('<path d="M34 40 Q22 30 24 14 Q32 26 42 30 M66 40 Q78 30 76 14 Q68 26 58 30"/>'
         '<path d="M30 46 Q30 30 50 28 Q70 30 70 46 Q70 64 58 74 L50 78 L42 74 Q30 64 30 46 Z"/>'
         '<path d="M38 60 Q50 68 62 60"/>',
         '<path d="M36 46 L46 50 L38 52 Z M64 46 L54 50 L62 52 Z M44 62 L46 67 L48 63 M56 62 L54 67 L52 63"/>'),
    # 02 dragon head in profile, jaws open
    dark('<path d="M28 86 Q30 64 40 50 Q46 40 58 38 L76 44 L84 50 L80 54 L62 56 L82 62 L76 68 L56 68 Q46 72 44 86"/>'
         '<path d="M44 44 Q32 34 22 18 Q40 26 50 39 M54 39 Q50 26 42 14 Q60 22 62 40"/>'
         '<path d="M36 62 L30 64 M38 70 L32 74"/>',
         '<path d="M58 45 L66 45 L60 48 Z M68 56 L70 60 M74 56 L75 59 M70 63 L71 60 M64 62 L65 59 M78 48 L79 49"/>'),
    # 03 horned skull
    dark('<path d="M36 50 Q26 38 18 24 Q32 30 40 38 M64 50 Q74 38 82 24 Q68 30 60 38"/>'
         '<path d="M36 62 Q30 36 50 32 Q70 36 64 62 L60 64 V74 H40 V64 Z"/>'
         '<circle cx="43" cy="50" r="5"/><circle cx="57" cy="50" r="5"/><path d="M45 74 V68 M50 74 V68 M55 74 V68"/>',
         '<path d="M50 56 L48 61 H52 Z"/>'),
    # 04 skull and crossbones
    dark('<path d="M26 66 L74 86 M74 66 L26 86"/><circle cx="24" cy="64" r="3"/><circle cx="76" cy="64" r="3"/><circle cx="24" cy="88" r="3"/><circle cx="76" cy="88" r="3"/>'
         '<path d="M38 54 Q32 26 50 22 Q68 26 62 54 L58 56 V66 H42 V56 Z"/>'
         '<circle cx="44" cy="42" r="5"/><circle cx="56" cy="42" r="5"/><path d="M46 66 V61 M50 66 V61 M54 66 V61"/>',
         star(44, 42, 3.5) + star(56, 42, 3.5)),
    # 05 inverted pentagram in a circle
    dark('<circle cx="50" cy="50" r="30"/>' + f'<path d="{star(50, 50, 30)}" stroke-width="3"/>',
         '<circle cx="50" cy="50" r="5"/><circle cx="50" cy="50" r="35"/>'),
    # 06 bat, wings spread
    dark('<path d="M50 40 Q44 40 44 48 Q44 58 50 60 Q56 58 56 48 Q56 40 50 40 Z M45 42 L42 34 L48 39 M55 42 L58 34 L52 39"/>'
         '<path d="M44 48 Q32 36 14 42 Q22 48 20 56 Q28 52 32 60 Q38 54 44 56 M56 48 Q68 36 86 42 Q78 48 80 56 Q72 52 68 60 Q62 54 56 56"/>',
         '<path d="M46 46 L49 47 L46 49 Z M54 46 L51 47 L54 49 Z"/>'),
    # 07 evil eye with slit pupil and spiked lashes
    dark('<path d="M18 50 Q50 22 82 50 Q50 78 18 50 Z"/><path d="M30 34 L26 26 M42 30 L40 20 M58 30 L60 20 M70 34 L74 26"/>',
         '<circle cx="50" cy="50" r="12"/><path d="M50 40 Q46 50 50 60 Q54 50 50 40 Z"/>'),
    # 08 hooded wraith, red eyes in the void
    dark('<path d="M50 18 Q26 22 24 52 Q24 72 18 84 Q34 78 50 84 Q66 78 82 84 Q76 72 76 52 Q74 22 50 18 Z"/>'
         '<path d="M36 56 Q36 36 50 34 Q64 36 64 56 Q58 66 50 66 Q42 66 36 56 Z" stroke-width="3"/>',
         '<path d="M42 50 L47 52 L42 53 Z M58 50 L53 52 L58 53 Z"/>'),
    # 09 goat skull with curling horns
    dark('<path d="M38 34 Q50 28 62 34 Q68 46 60 56 Q56 70 54 80 Q50 84 46 80 Q44 70 40 56 Q32 46 38 34 Z"/>'
         '<path d="M38 36 Q22 36 18 24 Q16 12 28 14 Q34 18 30 24 M62 36 Q78 36 82 24 Q84 12 72 14 Q66 18 70 24"/>'
         '<path d="M40 44 L48 48 L42 52 Z M60 44 L52 48 L58 52 Z" stroke-width="3"/><path d="M47 72 L48 75 M53 72 L52 75" stroke-width="3"/>',
         '<circle cx="44" cy="48" r="1.5"/><circle cx="56" cy="48" r="1.5"/><path d="M50 36 V42"/>'),
    # 10 cracked fanged skull
    dark('<path d="M32 56 Q26 24 50 20 Q74 24 68 56 Q66 66 58 70 L42 70 Q34 66 32 56 Z"/>'
         '<path d="M50 20 L46 30 L52 34 L48 42" stroke-width="2.5"/>'
         '<circle cx="41" cy="48" r="6"/><circle cx="59" cy="48" r="6"/>'
         '<path d="M40 70 L42 80 L46 72 L50 82 L54 72 L58 80 L60 70" stroke-width="3"/>',
         '<path d="M50 54 L47 61 H53 Z"/>'),
]

TERRAINS = {
    "plains-grass": grass, "plains-roads": roads, "stone-stone-path": stone_path, "stone-crags": crags,
    "water-open-water": open_water, "water-shallow-water": shallow, "sand-desert": desert, "sand-dunes": dunes,
    "jungle-jungle": jungle, "jungle-swamp": swamp, "ice-ice": ice, "ice-snow": snow,
    "lava-basalt": None, "lava-lava": lava, "darkness-darkness": None, "darkness-poison-pits": poison,
}

MORE = {"plains-roads", "stone-stone-path", "water-shallow-water",  # varied stones
        "plains-grass", "water-open-water", "stone-crags", "sand-desert",
        "jungle-swamp", "jungle-jungle", "ice-ice", "ice-snow",
        "lava-basalt", "lava-lava", "darkness-poison-pits", "sand-dunes"}  # round 2: blades, fish, crag compositions
CRAG_KINDS = ["spires", "spires", "spires", "boulders", "boulders", "boulders", "mixed", "mixed", "mixed", "mixed"]
FISH = {12, 15, 18}  # open-water variants (0-based) that get a fish
DESERT_COUNTS = [(1, 0, 0, 0), (1, 0, 1, 0), (1, 1, 0, 0), (0, 1, 1, 0), (0, 2, 0, 0),
                 (0, 0, 2, 1), (1, 0, 0, 1), (0, 1, 0, 0), (1, 1, 1, 0), (0, 1, 0, 1),  # sparse sand-desert 11-20
                 (1, 1, 1, 1), (1, 2, 1, 0), (1, 1, 0, 2), (0, 2, 1, 1), (1, 0, 2, 1),  # in-between 21-30
                 (1, 2, 0, 1), (0, 1, 2, 2), (1, 1, 2, 0), (0, 2, 2, 0), (1, 1, 1, 2)]
SWAMP_KINDS = ["none", "none", "none+lily", "two", "two", "two+lily", "big", "single", "big+lily", "two"]
ICE_KINDS = ["sheen"] * 5 + ["bubbles"] * 3 + ["bubbles+sheen"] * 2
GRASS_KINDS = ["blades", "blades", "blades", "rows", "rows", "rows", "bush", "bush", "bush", "bush"]
DUNE_KINDS = ["huge", "barchans", "ripples", "nested", "skewed", "tracks", "ripples+dune", "barchan+ripples",
              "nested+ripples", "shadow+tracks"]
POISON_KINDS = ["one-big", "three-small", "fumes", "blobs", "rings", "bubbles", "pop", "four-tiny", "blob-fumes", "big-small"]
BASALT_SPLITS = [0, 0, 0, 0, 1, 1, 1, 3, 3, 3]  # lava-basalt 21-30
LAVA_POPS = ["crown+bubbles", "broken+bubbles", "ripple+bubbles", "column+swirl", "twin+bubbles",
             "jagged+bubbles", "crown+swirl", "broken+ripple", "column+bubbles", "jagged+ripple"]  # lava-lava 21-30
LAVA_KINDS = ["bubbles", "bubbles", "swirls", "swirls", "swirls+bubbles", "dome+bubbles", "dome+swirls",
              "bubbles+swirls", "pools", "dome+swirls+bubbles"]
COUNT = {name: 20 if name in MORE else N for name in TERRAINS}
COUNT["sand-desert"] = 30
COUNT["ice-snow"] = 30
COUNT["lava-basalt"] = 30
COUNT["lava-lava"] = 30

for name, fn in TERRAINS.items():
    for i in range(COUNT[name]):
        VARIED = i >= N
        rng = random.Random(f"{name}-{i}")
        if name == "darkness-darkness":
            body = DARKNESS[i]
        elif name == "lava-basalt":
            body = basalt(rng, i) if i < N else basalt_tiling(rng) if i < 20 else basalt_big(rng, BASALT_SPLITS[i - 20])
        else:
            if i >= N and name == "plains-grass":
                body = grass_varied(rng, GRASS_KINDS[i - N])
            elif i >= N and name == "water-open-water":
                body = open_water_more(rng, i in FISH)
            elif i >= N and name == "stone-crags":
                body = crags_mix(rng, CRAG_KINDS[i - N])
            elif i >= N and name == "jungle-swamp":
                body = swamp_mix(rng, SWAMP_KINDS[i - N])
            elif i >= N and name == "jungle-jungle":
                body = jungle_mix(rng, i - N)
            elif i >= N and name == "ice-ice":
                body = ice_mix(rng, ICE_KINDS[i - N])
            elif i >= N and name == "ice-snow":
                body = snow_mix(rng) if i < 20 else snow_light(rng)
            elif i >= N and name == "lava-lava":
                body = lava_mix(rng, LAVA_KINDS[i - N]) if i < 20 else lava_pop(rng, LAVA_POPS[i - 20])
            elif i >= N and name == "darkness-poison-pits":
                body = poison_mix(rng, POISON_KINDS[i - N])
            elif i >= N and name == "sand-dunes":
                body = dunes_mix(rng, DUNE_KINDS[i - N])
            elif i >= N and name == "sand-desert":
                body = desert(rng, DESERT_COUNTS[i - N])
            else:
                body = fn(rng)
        with open(os.path.join(OUT, f"{name}-{i + 1:02d}.svg"), "w", newline="\n") as fh:
            fh.write(body)

# ---------------------------------------------------------------- preview page
CLIP = "clip-path:polygon(0 50%,25% 6.7%,75% 6.7%,100% 50%,75% 93.3%,25% 93.3%)"
rows = []
for name in TERRAINS:
    figs = f'<figure><img src="../{name}.svg"><figcaption>current</figcaption></figure>'
    figs += "".join(f'<figure><img src="{name}-{i:02d}.svg"><figcaption>{i:02d}</figcaption></figure>' for i in range(1, COUNT[name] + 1))
    # honeycomb patch mixing all variants, to judge whether they read as one terrain
    W = 64
    H = W * 0.866
    cells = ""
    for c in range(6):
        for r in range(3):
            i = (c * 3 + r) % COUNT[name] + 1
            cells += f'<img style="left:{c * W * .75:.0f}px;top:{r * H + (H / 2 if c % 2 else 0):.0f}px" src="{name}-{i:02d}.svg">'
    rows.append(f'<section><h2>{name}</h2><div class=row>{figs}<div class=comb>{cells}</div></div></section>')

html = f"""<!doctype html><meta charset=utf-8><title>Terrain variants</title>
<style>body{{background:#222;color:#ddd;font:13px sans-serif;padding:16px;margin:0}}
h2{{font-size:15px;margin:18px 0 6px}}.row{{display:flex;flex-wrap:wrap;gap:8px;align-items:center}}
figure{{margin:0;text-align:center}}figure img{{width:96px;height:96px;{CLIP}}}
.comb{{position:relative;width:{6 * 64 * .75 + 16:.0f}px;height:{3.5 * 64 * .866:.0f}px;margin-left:12px}}
.comb img{{position:absolute;width:64px;height:64px;{CLIP}}}</style>
<h1 style="font-size:18px">Terrain variants &mdash; current tile, then 01&ndash;10, then a mixed patch</h1>
{''.join(rows)}"""
with open(os.path.join(OUT, "preview.html"), "w", newline="\n") as fh:
    fh.write(html)
print("ok")
