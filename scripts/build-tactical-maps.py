"""Build five handcrafted tactical scenarios; validate before writing any files.

python scripts/build-tactical-maps.py [--preview DIR] [--check]
Only this script's five JSON files are touched. No third-party dependencies.
"""
from __future__ import annotations

import argparse
from collections import Counter, deque
from html import escape
import json
import math
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIRECTIONS = ((1, 0), (1, -1), (0, -1), (-1, 0), (-1, 1), (0, 1))
TERRAINS = {
    "plains": ("grass", "roads"), "water": ("open-water", "shallow-water"),
    "stone": ("stone-path", "crags"), "sand": ("desert", "dunes"),
    "jungle": ("jungle", "swamp"), "darkness": ("darkness", "poison-pits"),
    "lava": ("basalt", "lava"), "ice": ("snow", "ice"),
}
BIOME = {t: b for b, ts in TERRAINS.items() for t in ts}
OBSTACLES = {"tree", "rock"}
LANDMARKS = {"shrine", "fire-crystal", "forest-crystal", "ice-crystal"}
HAZARDS = {"lava", "poison-pits", "open-water", "ice"}
COLOURS = dict(zip(BIOME, (
    "#87ad62", "#c4ad7c", "#3979b8", "#87c5d3", "#b7b4a2", "#827c78",
    "#ddc591", "#c9a869", "#397849", "#718353", "#756087", "#a3ae43",
    "#696264", "#f16d32", "#e2e9ec", "#9fd7eb")))


def axial(p):
    x, y = p
    return y - x // 2, x


def distance(a, b):
    aq, ar = axial(a)
    bq, br = axial(b)
    return max(abs(aq - bq), abs(ar - br), abs(aq + ar - bq - br))


class Map:
    def __init__(self, name, w, h, duel=False):
        self.name, self.w, self.h, self.duel = name, w, h, duel
        self.cells = {}
        for x in range(w):
            for y in range(h):
                q, r = axial((x, y))
                self.cells[x, y] = dict(q=q, r=r, biome="plains", terrain="grass", elevation=0,
                    playerStart=None, portal=False, spawnZone=None, piece=None, object=None,
                    fountain=False, sightBlocker=False)
        self.starts = []
        self.portal = None
        self.crossings = {}

    def neighbours(self, p):
        q, r = axial(p)
        for dq, dr in DIRECTIONS:
            nr = r + dr
            n = (nr, q + dq + nr // 2)
            if n in self.cells:
                yield n

    def disc(self, p, radius):
        return [n for n in self.cells if distance(p, n) <= radius]

    def region(self, test):
        return [p for p in self.cells if test(*p)]

    def paint(self, points, terrain, elevation=None):
        for p in points:
            if p in self.cells:
                c = self.cells[p]
                c.update(terrain=terrain, biome=BIOME[terrain])
                if elevation is not None:
                    c["elevation"] = elevation

    def object(self, p, kind, radius=1):
        c = self.cells[p]
        assert c["object"] is None, (self.name, p, kind, c["object"])
        c.update(object=kind, sightBlocker=kind in OBSTACLES)
        if kind.startswith("rune-"):
            c["radius"] = radius

    def objects(self, points, kind):
        for p in points:
            self.object(p, kind)

    def hill(self, p, radius=2, terrain="crags"):
        self.paint(self.disc(p, radius), terrain, 1)
        self.paint([p], terrain, 2)

    def spawn(self, p, radius=1, terrain=None):
        points = self.disc(p, radius)
        for n in points:
            c = self.cells[n]
            if c["object"] is not None or c["terrain"] in {"lava", "poison-pits", "ice"}:
                continue
            if terrain and c["terrain"] != terrain:
                continue
            c["spawnZone"] = "void"

    def deployment(self, p, player):
        self.starts.append(p)
        self.paint(self.disc(p, 3 if not self.duel else 2), "grass", 0)
        for n in self.disc(p, 3 if not self.duel else 2):
            c = self.cells[n]
            assert c["object"] is None and c["spawnZone"] is None, (self.name, "deployment", n)
        self.paint([p], "roads")
        self.cells[p].update(playerStart=player, portal=True)

    def gate(self, p):
        self.portal = p
        self.paint(self.disc(p, 2), "darkness", 0)
        self.paint(self.disc(p, 1), "darkness", 1)
        self.cells[p]["voidPortal"] = True
        self.cells[p]["spawnZone"] = "void"

    def mirror(self):
        # An even number of odd-offset columns makes a rectangle exactly rotation symmetric.
        assert self.w % 2 == 0
        return lambda p: (self.w - 1 - p[0], self.h - 1 - p[1])

    def symmetric_paint(self, points, terrain, elevation=None):
        points = list(points)
        self.paint(points + [self.mirror()(p) for p in points], terrain, elevation)

    def symmetric_object(self, p, kind, radius=1):
        self.object(p, kind, radius)
        self.object(self.mirror()(p), kind, radius)

    def definition(self):
        result = dict(schemaVersion=1, name=self.name,
            modeCompatibility=["vanillaPvp" if self.duel else "coop"],
            map=dict(columns=self.w, rows=self.h,
                     hexes=sorted(self.cells.values(), key=lambda c: (c["r"], c["q"]))),
            placements=[], objectives=[dict(kind="annihilation" if self.duel else "destroyVoidPortal")],
            scoring=dict(target=10, enemyHero=3, boss=2, terrainObject=1, completeObjectSetBonus=2),
            playerCounts={"vanillaPvp" if self.duel else "coop": 2 if self.duel else 4})
        if not self.duel:
            result["coop"] = dict(basePoints=4, pointsPerHeroPlayer=8)
        return result


def threefold_ford():
    m = Map("Threefold Ford", 18, 20)
    m.paint(m.region(lambda x, y: x <= 5 and y < 14), "jungle")
    m.paint(m.region(lambda x, y: x >= 12 and y < 14), "desert")
    m.paint(m.region(lambda x, y: x <= 5 and y <= 5), "snow")
    m.paint(m.region(lambda x, y: x >= 12 and y <= 5), "basalt")
    m.hill((13, 7), terrain="dunes")
    m.hill((4, 6), terrain="snow")
    m.paint(m.region(lambda x, y: y in (9, 10)), "open-water", 0)
    for name, columns, terrain in [("woodland ford", (3, 4), "shallow-water"),
                                   ("exposed bridge", (8, 9), "stone-path"),
                                   ("dune ford", (14, 15), "shallow-water")]:
        crossing = m.region(lambda x, y: x in columns and y in (9, 10))
        m.paint(crossing, terrain)
        m.crossings[name] = crossing
    m.paint([(2, 7), (3, 7), (15, 3), (15, 4)], "ice")
    m.paint([(13, 3), (14, 3), (14, 4)], "lava")
    m.objects([(3, 12), (5, 12), (2, 8), (5, 8), (6, 6), (11, 12), (13, 11),
               (11, 8), (15, 6), (7, 5), (10, 5)], "tree")
    m.objects([(6, 11), (11, 10), (7, 7), (10, 7)], "rock")
    m.object((4, 10), "rune-of-warding", 1)
    m.object((9, 9), "rune-of-strength", 1)
    m.object((14, 10), "rune-of-focus", 1)
    for p, obj in [((2, 6), "forest-crystal"), ((5, 3), "ice-crystal"),
                   ((13, 5), "fire-crystal"), ((2, 11), "speed-potion"),
                   ((3, 8), "health-potion"), ((15, 8), "power-potion"), ((10, 6), "health-potion")]:
        m.object(p, obj)
    for p in [(3, 5), (14, 6), (4, 11), (14, 11)]:
        m.spawn(p)
    m.spawn((10, 10), terrain="open-water")  # River monsters have genuine water deployment.
    m.gate((8, 2))
    m.deployment((8, 16), 1)
    return m


def cinder_switch():
    m = Map("Cinder Switch", 20, 18)
    m.paint(m.region(lambda x, y: x < 7 and y < 12), "jungle")
    m.paint(m.region(lambda x, y: x > 12 and y < 12), "snow")
    m.paint(m.region(lambda x, y: 7 <= x <= 12 and y < 12), "basalt")
    m.hill((9, 8), 3, "basalt")
    m.paint([(9, 7), (10, 7), (10, 8)], "lava")
    m.paint([(3, 8), (4, 8), (3, 9)], "swamp")
    m.paint([(3, 7), (4, 7)], "poison-pits")
    m.paint([(16, 7), (16, 8), (17, 7)], "ice")
    m.paint(m.disc((16, 4), 1), "open-water", 0)
    m.paint(m.region(lambda x, y: x in (5, 14) and 4 <= y <= 12), "stone-path", 0)
    m.objects([(2, 10), (4, 10), (6, 8), (3, 5), (6, 4)], "tree")
    m.objects([(13, 9), (15, 10), (18, 9), (14, 5), (17, 6), (8, 5), (11, 5),
               (8, 11), (11, 11)], "rock")
    for p, obj in [((8, 8), "rune-of-focus"), ((5, 7), "rune-of-warding"),
                   ((14, 7), "rune-of-strength"), ((4, 6), "forest-crystal"),
                   ((15, 6), "ice-crystal"), ((11, 8), "fire-crystal"),
                   ((5, 10), "health-potion"), ((14, 10), "speed-potion"),
                   ((9, 6), "power-potion"), ((12, 4), "health-potion")]:
        m.object(p, obj)
    for p in [(2, 6), (17, 10), (10, 5), (13, 3)]:
        m.spawn(p)
    m.spawn((16, 4), terrain="open-water")
    m.gate((9, 2))
    m.deployment((9, 14), 1)
    return m


def thornwatch():
    m = Map("Thornwatch", 18, 18)
    m.paint(m.region(lambda x, y: y < 12), "jungle")
    m.paint(m.region(lambda x, y: x >= 12 and y < 12), "desert")
    m.paint(m.region(lambda x, y: x <= 5 and y < 6), "darkness")
    m.paint(m.region(lambda x, y: 6 <= x <= 11 and y < 6), "snow")
    m.paint(m.disc((3, 8), 2), "swamp")
    m.paint(m.disc((3, 8), 1), "open-water", 0)
    m.paint([(2, 4), (3, 4), (4, 4)], "poison-pits")
    m.paint([(8, 4), (9, 4), (10, 4)], "ice")
    m.hill((13, 8), 2, "dunes")
    m.paint(m.region(lambda x, y: x in (6, 10) and 5 <= y <= 11), "roads", 0)
    m.objects([(5, 11), (7, 10), (9, 10), (11, 11), (5, 8), (7, 8),
               (9, 7), (11, 6), (4, 6), (6, 4), (12, 10), (15, 7)], "tree")
    m.objects([(8, 9), (12, 5), (6, 6), (11, 9), (7, 5), (10, 5)], "rock")
    for p, obj in [((6, 8), "rune-of-warding"), ((10, 8), "rune-of-focus"),
                   ((14, 9), "rune-of-strength"), ((2, 6), "forest-crystal"),
                   ((10, 3), "ice-crystal"), ((15, 5), "shrine"),
                   ((3, 10), "health-potion"), ((14, 10), "speed-potion"),
                   ((8, 6), "power-potion"), ((12, 3), "health-potion")]:
        m.object(p, obj)
    for p in [(3, 5), (7, 7), (14, 6), (12, 2)]:
        m.spawn(p)
    m.spawn((3, 8), terrain="open-water")
    m.gate((8, 2))
    m.deployment((8, 14), 1)
    return m


def splitstone():
    m = Map("Splitstone", 18, 16, True)
    m.symmetric_paint(m.region(lambda x, y: x < 7 and y < 6), "jungle")
    m.symmetric_paint(m.region(lambda x, y: x < 7 and y > 9), "desert")
    m.symmetric_paint(m.disc((8, 4), 2), "crags", 1)
    m.symmetric_paint([(8, 4)], "crags", 2)
    m.symmetric_paint(m.region(lambda x, y: x in (8, 9) and 6 <= y <= 9), "shallow-water", 0)
    for p in [(5, 6), (6, 3), (7, 7), (7, 10), (9, 2)]:
        m.symmetric_object(p, "rock")
    for p in [(4, 4), (5, 10), (7, 5)]:
        m.symmetric_object(p, "tree")
    m.symmetric_object((8, 6), "rune-of-strength")
    m.symmetric_object((8, 10), "rune-of-warding")
    m.symmetric_object((8, 2), "health-potion")
    m.symmetric_object((7, 12), "speed-potion")
    m.symmetric_object((10, 4), "shrine")
    m.deployment((1, 7), 1)
    m.deployment(m.mirror()((1, 7)), 2)
    return m


def rimefire_crossing():
    m = Map("Rimefire Crossing", 20, 18, True)
    m.symmetric_paint(m.region(lambda x, y: x < 10 and y < 8), "snow")
    m.symmetric_paint(m.region(lambda x, y: x < 10 and y > 9), "basalt")
    m.symmetric_paint(m.region(lambda x, y: x in (9, 10)), "open-water", 0)
    for y in (3, 8, 14):
        m.symmetric_paint([(9, y), (10, y)], "stone-path", 0)
    m.symmetric_paint([(7, 3), (7, 4), (7, 5)], "ice")
    m.symmetric_paint([(6, 12), (7, 12), (7, 13)], "lava")
    m.symmetric_paint(m.disc((7, 8), 2), "crags", 1)
    m.symmetric_paint([(7, 8)], "crags", 2)
    # Restore river after the hills: the three crossings are the only dry passages.
    m.symmetric_paint(m.region(lambda x, y: x in (9, 10)), "open-water", 0)
    for y in (3, 8, 14):
        m.symmetric_paint([(9, y), (10, y)], "stone-path", 0)
    for p in [(5, 5), (6, 7), (7, 10), (8, 2), (8, 13)]:
        m.symmetric_object(p, "rock")
    for p in [(4, 3), (5, 10), (6, 15)]:
        m.symmetric_object(p, "tree")
    m.symmetric_object((9, 3), "rune-of-focus")
    m.symmetric_object((9, 8), "rune-of-warding")
    m.symmetric_object((8, 4), "speed-potion")
    m.symmetric_object((8, 12), "power-potion")
    m.symmetric_object((8, 6), "health-potion")
    m.symmetric_object((7, 2), "ice-crystal")
    m.symmetric_object((6, 13), "fire-crystal")
    m.deployment((1, 8), 1)
    m.deployment(m.mirror()((1, 8)), 2)
    return m


BUILDERS = [threefold_ford, cinder_switch, thornwatch, splitstone, rimefire_crossing]


def reachable(m, start, blocked=frozenset()):
    """Safe ordinary-walker graph. Excludes ice rather than approximating forced slides."""
    seen = {start: 0}
    queue = deque([start])
    while queue:
        p = queue.popleft()
        for n in m.neighbours(p):
            c = m.cells[n]
            if (n in seen or n in blocked or c["terrain"] in HAZARDS
                    or c["object"] in OBSTACLES | LANDMARKS
                    or c["elevation"] - m.cells[p]["elevation"] > 1):
                continue
            seen[n] = seen[p] + 1
            queue.append(n)
    return seen


def validate(m):
    assert 15 <= m.w <= 20 and 15 <= m.h <= 20
    assert len(m.cells) == m.w * m.h
    assert len(m.starts) == (2 if m.duel else 1)
    start = m.starts[0]
    goal = m.starts[1] if m.duel else m.portal
    routes = reachable(m, start)
    assert goal in routes, (m.name, "no safe walking route")
    # No individual middle hex may sever every safe approach.
    for p in routes:
        if distance(p, start) > 2 and distance(p, goal) > 2:
            assert goal in reachable(m, start, {p}), (m.name, "single-hex choke", p)
    for p, c in m.cells.items():
        if c["object"] in LANDMARKS or (c["object"] or "").endswith("potion"):
            assert all(distance(p, s) > 5 for s in m.starts), (m.name, "reward too near start", p)
        if c["object"] and c["object"] not in OBSTACLES:
            targets = list(m.neighbours(p)) if c["object"] in LANDMARKS else [p]
            assert any(t in routes for t in targets), (m.name, "unreachable reward", p)
        assert not (c["object"] and c["spawnZone"]), (m.name, "object on spawn", p)
    spawn = [p for p, c in m.cells.items() if c["spawnZone"]]
    if not m.duel:
        assert len(spawn) >= 24
        assert len({m.cells[p]["biome"] for p in spawn if p != goal}) >= 4
        assert all(distance(start, p) >= 6 for p in spawn)
        room = [p for p in m.disc(start, 3) if p != start and p in routes
                and m.cells[p]["object"] not in LANDMARKS | OBSTACLES and p not in spawn]
        assert len(room) >= 32, (m.name, "deployment capacity", len(room))
        for name, crossing in m.crossings.items():
            closed = {p for other, ps in m.crossings.items() if other != name for p in ps}
            assert goal in reachable(m, start, closed), (m.name, name, "not independently viable")
        if m.crossings:
            assert goal not in reachable(m, start, {p for ps in m.crossings.values() for p in ps})
    else:
        for p, c in m.cells.items():
            twin = m.cells[m.mirror()(p)]
            for field in ("terrain", "elevation", "object", "radius", "sightBlocker"):
                assert c.get(field) == twin.get(field), (m.name, "asymmetry", p, field)
    return dict(hexes=len(m.cells), safeWalkingSteps=routes[goal], spawnHexes=len(spawn),
                spawnBiomes=dict(Counter(m.cells[p]["biome"] for p in spawn)))


def preview(m, file):
    """Self-contained SVG: terrain, elevation, objects, starts and spawn outlines."""
    size = 19
    width, height = m.w * size * 1.5 + 55, (m.h + 1) * size * math.sqrt(3) + 130
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}">',
             '<rect width="100%" height="100%" fill="#20242a"/>',
             f'<text x="20" y="25" fill="white" font-family="sans-serif" font-size="18">{escape(m.name)} | {m.w} x {m.h}</text>']
    labels = {"tree": "T", "rock": "R", "shrine": "Sh", "forest-crystal": "FC",
              "fire-crystal": "VC", "ice-crystal": "IC", "rune-of-focus": "NF",
              "rune-of-strength": "NS", "rune-of-warding": "NW", "health-potion": "HP",
              "speed-potion": "SP", "power-potion": "PP"}
    for (x, y), c in m.cells.items():
        cx, cy = 30 + x * size * 1.5, 60 + (y + 0.5 * (x % 2)) * size * math.sqrt(3)
        points = " ".join(f'{cx + size * math.cos(i * math.pi / 3):.1f},{cy + size * math.sin(i * math.pi / 3):.1f}' for i in range(6))
        stroke = "#ef64ff" if c["spawnZone"] else "#303438"
        parts.append(f'<polygon points="{points}" fill="{COLOURS[c["terrain"]]}" stroke="{stroke}" stroke-width="{3 if c["spawnZone"] else 1}"><title>{x},{y}: {c["terrain"]}, height {c["elevation"]}</title></polygon>')
        label = f'P{c["playerStart"]}' if c["playerStart"] else "VP" if c.get("voidPortal") else labels.get(c["object"], "")
        if label:
            parts.append(f'<text x="{cx}" y="{cy+4}" text-anchor="middle" fill="white" stroke="#111" stroke-width="0.5" paint-order="stroke" font-family="sans-serif" font-size="12" font-weight="bold">{label}</text>')
        if c["elevation"]:
            parts.append(f'<text x="{cx-9}" y="{cy-8}" fill="white" font-size="10">{c["elevation"]}</text>')
    for i, line in enumerate(["Purple outline: Void deployment | P1/P2: starts | VP: Void Portal | T/R: blocked",
                              "NF/NS/NW: focus/strength/warding nexus | HP/SP/PP: health/speed/power pickup",
                              "FC/VC/IC/Sh: forest/fire/ice crystal/shrine | Small number: elevation"]):
        parts.append(f'<text x="15" y="{height-60+i*19}" fill="white" font-family="sans-serif" font-size="12">{line}</text>')
    parts.append('</svg>')
    file.write_text("\n".join(parts), encoding="utf-8")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--preview", type=Path)
    parser.add_argument("--check", action="store_true", help="Verify checked-in JSON without overwriting it")
    args = parser.parse_args()
    maps = [build() for build in BUILDERS]
    for m in maps:
        print(m.name, json.dumps(validate(m)))
    for m in maps:
        slug = ("duel-" if m.duel else "incursion-") + m.name.lower().replace(" ", "-")
        path = ROOT / "api/scenarios" / f"{slug}.json"
        if args.check:
            assert json.loads(path.read_text(encoding="utf-8")) == m.definition(), f"Regenerate {path}"
        else:
            path.write_text(json.dumps(m.definition(), indent=4) + "\n", encoding="utf-8")
        if args.preview:
            args.preview.mkdir(parents=True, exist_ok=True)
            preview(m, args.preview / f"{slug}.svg")


if __name__ == "__main__":
    main()
