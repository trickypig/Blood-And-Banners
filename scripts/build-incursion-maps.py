"""Builds the five draft Void incursion maps (hexagonal, side 15) into api/scenarios/incursion-*.json.

Every map shares the brief: heroes start at the bottom point, the Void Portal stands at the top point, a river runs
across the middle through a central lake, and four zones lie between start and portal. Each version tests a different
idea of how those pieces connect.

    python scripts/build-incursion-maps.py [--preview DIR]

--preview also draws each map as a PNG (needs Pillow) and prints the map checks.
"""
from __future__ import annotations

import json
import math
import sys
from collections import deque
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SIDE = 15
RADIUS = SIDE - 1
CENTER_Q, CENTER_R = RADIUS - RADIUS // 2, RADIUS  # MapEditorDocument.NewHex's centre
DIRECTIONS = [(1, 0), (1, -1), (0, -1), (-1, 0), (-1, 1), (0, 1)]
OBSTACLES = {"tree", "rock"}
LANDMARKS = {"shrine", "fire-crystal", "forest-crystal", "ice-crystal"}
NEXUS = {"rune-of-focus", "rune-of-strength", "rune-of-warding"}
ITEMS = {"power-potion", "speed-potion", "health-potion"}
TERRAINS = {
    "plains": ["grass", "roads"], "water": ["open-water", "shallow-water"], "stone": ["stone-path", "crags"],
    "sand": ["desert", "dunes"], "jungle": ["jungle", "swamp"], "darkness": ["darkness", "poison-pits"],
    "lava": ["basalt", "lava"], "ice": ["snow", "ice"],
}
BIOME_OF = {terrain: biome for biome, terrains in TERRAINS.items() for terrain in terrains}

# Local coordinates: a hex is (a, b), its cube offset from the centre (a = dq, b = dr). On screen x = 0.866 b runs
# left to right and y = a + b / 2 runs top (-14, the portal's point) to bottom (+14, the heroes' point).


def xy(h):
    a, b = h
    return 0.8660254 * b, a + b / 2


def at(x, y):
    """The hex nearest the screen point."""
    b = x / 0.8660254
    a = y - b / 2
    c = -a - b
    ra, rb, rc = round(a), round(b), round(c)
    da, db, dc = abs(ra - a), abs(rb - b), abs(rc - c)
    if da > db and da > dc:
        ra = -rb - rc
    elif db > dc:
        rb = -ra - rc
    return ra, rb


def dist(h, k):
    da, db = h[0] - k[0], h[1] - k[1]
    return (abs(da) + abs(db) + abs(da + db)) // 2


def inside(h):
    a, b = h
    return max(abs(a), abs(b), abs(a + b)) <= RADIUS


def neighbours(h):
    return [(h[0] + da, h[1] + db) for da, db in DIRECTIONS]


def line(h, k):
    """Hexes on the straight line from h to k."""
    n = dist(h, k)
    out = []
    for i in range(n + 1):
        t = i / n if n else 0
        x0, y0 = xy(h)
        x1, y1 = xy(k)
        out.append(at(x0 + (x1 - x0) * t + 1e-6, y0 + (y1 - y0) * t + 1e-6))
    return list(dict.fromkeys(out))


def path(*points):
    """Hexes along a polyline of screen points, one hex wide."""
    out = []
    for p, q in zip(points, points[1:]):
        out.extend(line(at(*p), at(*q)))
    return list(dict.fromkeys(out))


def seg_distance(p, a, b):
    (px, py), (ax, ay), (bx, by) = p, a, b
    dx, dy = bx - ax, by - ay
    length = dx * dx + dy * dy
    t = 0 if length == 0 else max(0.0, min(1.0, ((px - ax) * dx + (py - ay) * dy) / length))
    return math.hypot(px - ax - t * dx, py - ay - t * dy)


def river_y(x, points):
    """The river's screen y under x, interpolated along its polyline."""
    for (x0, y0), (x1, y1) in zip(points, points[1:]):
        if x0 <= x <= x1:
            return y0 + (y1 - y0) * (x - x0) / (x1 - x0)
    return points[0][1] if x < points[0][0] else points[-1][1]


def poly_distance(p, points):
    return min(seg_distance(p, a, b) for a, b in zip(points, points[1:]))


def noise(h, seed, scale=3.0):
    """Smooth deterministic value noise in [0, 1)."""
    x, y = xy(h)
    x, y = x / scale + seed * 17.3, y / scale + seed * 9.1
    x0, y0 = math.floor(x), math.floor(y)

    def corner(i, j):
        n = (i * 374761393 + j * 668265263 + seed * 2147483647) & 0xFFFFFFFF
        n = (n ^ (n >> 13)) * 1274126177 & 0xFFFFFFFF
        return (n & 0xFFFF) / 65536

    sx, sy = x - x0, y - y0
    sx, sy = sx * sx * (3 - 2 * sx), sy * sy * (3 - 2 * sy)
    top = corner(x0, y0) * (1 - sx) + corner(x0 + 1, y0) * sx
    bottom = corner(x0, y0 + 1) * (1 - sx) + corner(x0 + 1, y0 + 1) * sx
    return top * (1 - sy) + bottom * sy


def jitter(h, seed):
    """Per-hex deterministic value in [0, 1)."""
    n = (h[0] * 73856093 ^ h[1] * 19349663 ^ seed * 83492791) & 0xFFFFFFFF
    n = (n ^ (n >> 15)) * 2246822519 & 0xFFFFFFFF
    return (n & 0xFFFF) / 65536


class Map:
    def __init__(self, name, role, tags):
        self.name, self.role, self.tags = name, role, tags
        self.hexes = {}
        for a in range(-RADIUS, RADIUS + 1):
            for b in range(-RADIUS, RADIUS + 1):
                if inside((a, b)):
                    self.hexes[(a, b)] = {"terrain": "grass", "elevation": 0, "object": None, "spawn": False, "radius": None}
        self.start = None
        self.portal = None
        self.crossings = {}

    def all(self):
        return list(self.hexes)

    def where(self, test):
        return [h for h in self.hexes if test(h, *xy(h))]

    def disc(self, centre, radius):
        centre = at(*centre) if isinstance(centre[0], float) else centre
        return [h for h in self.hexes if dist(h, centre) <= radius]

    def near(self, points, width):
        return [h for h in self.hexes if poly_distance(xy(h), points) <= width]

    def paint(self, hexes, terrain, elevation=None):
        for h in hexes:
            if h in self.hexes:
                self.hexes[h]["terrain"] = terrain
                if elevation is not None:
                    self.hexes[h]["elevation"] = elevation

    def raise_to(self, hexes, elevation):
        for h in hexes:
            if h in self.hexes:
                self.hexes[h]["elevation"] = elevation

    def place(self, h, kind, radius=None):
        h = at(*h) if isinstance(h[0], float) else h
        assert h in self.hexes, (self.name, kind, h)
        self.hexes[h]["object"] = kind
        self.hexes[h]["radius"] = radius if kind in NEXUS else None
        return h

    def clear(self, hexes):
        for h in hexes:
            if h in self.hexes and self.hexes[h]["object"] in OBSTACLES:
                self.hexes[h]["object"] = None

    def scatter(self, hexes, kind, density, seed, keep=()):
        keep = set(keep)
        for h in hexes:
            if h in self.hexes and h not in keep and self.hexes[h]["object"] is None and jitter(h, seed) < density:
                self.hexes[h]["object"] = kind

    def spawn(self, hexes):
        """Void Lord deployment ground; obstacles there are cleared so monsters can stand on it."""
        for h in hexes:
            if h in self.hexes and self.hexes[h]["object"] not in NEXUS | ITEMS | LANDMARKS:
                self.hexes[h]["spawn"] = True
                self.hexes[h]["object"] = None
                self.hexes[h]["radius"] = None

    def terrain(self, h):
        return self.hexes[h]["terrain"]

    def elevation(self, h):
        return self.hexes[h]["elevation"]

    def scenario(self):
        out = []
        for (a, b), cell in self.hexes.items():
            q, r = CENTER_Q + a, CENTER_R + b
            kind = cell["object"]
            hex_ = {
                "q": q, "r": r, "biome": BIOME_OF[cell["terrain"]], "terrain": cell["terrain"],
                "elevation": cell["elevation"], "playerStart": 1 if (a, b) == self.start else None,
                "portal": (a, b) == self.start, "spawnZone": "void" if cell["spawn"] else None, "piece": None,
                "object": kind, "fountain": False, "sightBlocker": kind in OBSTACLES,
            }
            if cell["radius"] is not None:
                hex_["radius"] = cell["radius"]
            if (a, b) == self.portal:
                hex_["voidPortal"] = True
            out.append(hex_)
        out.sort(key=lambda h: (h["r"], h["q"]))
        return {
            "schemaVersion": 1,
            "name": self.name,
            "modeCompatibility": ["coop"],
            "map": {"columns": 2 * SIDE - 1, "rows": 2 * SIDE - 1, "hexes": out},
            "placements": [],
            "objectives": [{"kind": "destroyVoidPortal"}],
            "scoring": {"target": 10, "enemyHero": 3, "boss": 2, "terrainObject": 1, "completeObjectSetBonus": 2},
            "coop": {"basePoints": 4, "pointsPerHeroPlayer": 8},
            "playerCounts": {"coop": 4},
        }


START = (11, 0)   # y = 11, the bottom point's deployment
PORTAL = (-12, 0)  # y = -12, the top point


def start_area(m, terrain="grass"):
    """Open, flat deployment ground: the heroes fill up to 32 hexes within 3 of the start."""
    m.start = START
    area = m.disc(START, 4) + m.where(lambda h, x, y: y >= 12)
    m.paint(area, terrain, 0)
    for h in area:
        m.hexes[h]["object"] = None
        m.hexes[h]["spawn"] = False
    m.paint([START], "roads")


def lake_and_river(m, river, lake_radius, width=1.0, river_terrain="open-water"):
    """Paints the river polyline (screen points, left edge to right edge) and the central lake; returns both sets."""
    water = m.near(river, width)
    lake = m.disc((0, 0), lake_radius)
    m.paint(water + lake, river_terrain, 0)
    return set(water), set(lake)


def water_spawns(m, size=3):
    """Deep-water deployment for the Void Lord's water-bound monsters: a small pool on each river flank, north of
    mid-river, and one on the lake's north shore. Crossings and the start's surroundings stay clear."""
    taken = set().union(*m.crossings.values()) if m.crossings else set()
    deep = {h for h in m.hexes if m.terrain(h) == "open-water" and h not in taken and dist(h, m.start) >= 8}
    for lo, hi in ((-RADIUS, -5.0), (5.0, RADIUS), (-2.5, 2.5)):
        side = [h for h in deep if lo <= xy(h)[0] <= hi]
        if not side:
            continue
        mid = (lo + hi) / 2
        seed = min(side, key=lambda h: (xy(h)[1] + 0.3 * abs(xy(h)[0] - mid), h))
        pool, queue = [seed], deque([seed])
        while queue and len(pool) < size:
            for n in sorted(neighbours(queue.popleft()), key=lambda h: (xy(h)[1], h)):
                if n in side and n not in pool and len(pool) < size:
                    pool.append(n)
                    queue.append(n)
        m.spawn(pool)


def portal_court(m, terrain="darkness", elevation=2):
    m.portal = PORTAL
    m.paint([PORTAL], terrain, elevation)
    m.hexes[PORTAL]["object"] = None
    m.spawn([PORTAL])


def court(m, base="darkness", pillars=((-2.6, -9.3), (2.6, -9.3), (-1.73, -8.0), (1.73, -8.0)), poison=True):
    """The portal's raised ground: a plateau around the gate, rock pillars as cover from its range-5 shots."""
    plateau = m.disc(PORTAL, 4)
    m.paint(plateau, base, 1)
    m.clear(plateau)
    m.paint(m.disc(PORTAL, 2), base, 2)
    if poison:
        m.paint([h for h in plateau if dist(h, PORTAL) >= 3 and abs(xy(h)[0]) > 2.8 and jitter(h, 5) < 0.7], "poison-pits")
    for p in pillars:
        m.place(p, "rock")
    portal_court(m, base, 2)
    m.spawn([h for h in m.disc(PORTAL, 2) if m.hexes[h]["object"] is None and h != PORTAL])


ROAD_OVER = {"grass": "roads", "ice": "snow", "lava": "basalt", "dunes": "desert", "crags": "stone-path"}


def road(m, hexes):
    """A walkable track: cleared of obstacles, with slick or deadly ground underfoot made plain."""
    for h in hexes:
        if h not in m.hexes or m.terrain(h) in ("open-water", "shallow-water", "stone-path", "poison-pits"):
            continue
        cell = m.hexes[h]
        if cell["object"] in OBSTACLES:
            cell["object"] = None
        cell["terrain"] = ROAD_OVER.get(cell["terrain"], cell["terrain"])


# ---------------------------------------------------------------------------------------------------------------
# A. Twin Roads: pick a flank, then pick again. Rocky spines split both halves; the lake island lets a bold party
# switch flanks mid-map, under a Rune of Strength anyone can use.


def twin_roads():
    m = Map("Twin Roads", "Choose your flank", ["Forest or dunes", "Ice or fire", "Island crossing"])
    river = [(-13.0, 1.2), (-9.0, 0.2), (-5.5, 1.0), (-2.5, 0.2), (2.5, -0.2), (5.5, -1.0), (9.0, -0.2), (13.0, -1.2)]
    south = lambda h: xy(h)[1] > river_y(xy(h)[0], river)

    # SW Thornwood: dense forest, short sightlines, ambush ground.
    thornwood = m.where(lambda h, x, y: south(h) and x < 0)
    m.paint(thornwood, "jungle")
    m.paint([h for h in thornwood if poly_distance(xy(h), river) < 2.4 or noise(h, 4) > 0.72], "swamp")
    m.scatter([h for h in thornwood if noise(h, 1, 2.2) > 0.42], "tree", 0.6, 11)

    # SE Sunscar: open dunes, long sightlines for archers, crests for overwatch.
    sunscar = m.where(lambda h, x, y: south(h) and x >= 0)
    m.paint(sunscar, "desert")
    for h in sunscar:
        x, y = xy(h)
        crest = math.sin((x * 0.55 - y * 0.9) * 1.25)
        if crest > 0.55 and y > 2:
            m.paint([h], "dunes", 2 if crest > 0.92 else 1)
    m.scatter(sunscar, "rock", 0.05, 12)

    # NW Rimefields: snow with slick ice sheets that slide units, pines for cover.
    rime = m.where(lambda h, x, y: not south(h) and x < 0)
    m.paint(rime, "snow")
    m.paint([h for h in rime if noise(h, 7, 2.4) > 0.6], "ice")
    m.scatter([h for h in rime if m.terrain(h) == "snow"], "tree", 0.12, 13)

    # NE Cinderfall: basalt shelves cut by lava rills; knockback here is deadly.
    cinder = m.where(lambda h, x, y: not south(h) and x >= 0)
    m.paint(cinder, "basalt")
    for rill in ([(1.8, -2.4), (4.6, -4.6), (6.0, -8.5)], [(6.4, -1.6), (9.0, -3.6), (12.5, -3.9)]):
        m.paint([h for h in m.near(rill, 0.55) if h in set(cinder)], "lava")
    m.scatter([h for h in cinder if m.terrain(h) == "basalt"], "rock", 0.06, 14)

    # Rocky spines: the south one a climbable overlook with a ramp each side, the north one a wall with one pass.
    spine_s = m.where(lambda h, x, y: abs(x) < 1.0 and 4.2 <= y <= 8.2)
    m.paint(spine_s, "crags", 3)
    m.clear(spine_s)
    foot = m.where(lambda h, x, y: 0.9 <= abs(x) < 1.8 and 4.6 <= y <= 7.8)
    m.paint(foot, "crags", 1)
    m.clear(foot)
    m.paint([at(-1.73, 6.0), at(-0.87, 6.5)], "stone-path", 2)  # forest ramp
    m.paint([at(1.73, 5.0), at(0.87, 5.5)], "stone-path", 2)    # dune ramp
    spine_n = m.where(lambda h, x, y: abs(x) < 0.9 and -8.6 <= y <= -3.6)
    m.paint(spine_n, "crags", 2)
    for h in spine_n:
        m.hexes[h]["object"] = "rock" if abs(xy(h)[1] + 6.0) > 0.6 else None
    m.paint([h for h in spine_n if abs(xy(h)[1] + 6.0) <= 0.6], "stone-path", 0)

    # The river: a stone bridge west, a wide shallow ford east, and causeways to the lake island.
    water, lake = lake_and_river(m, river, 3)
    m.clear(water | lake)
    bridge = [h for h in water if abs(xy(h)[0] + 7.79) < 0.1]
    m.paint(bridge, "stone-path")
    ford = [h for h in water if abs(xy(h)[0] - 7.36) < 0.9]
    m.paint(ford, "shallow-water")
    island = m.disc((0, 0), 1)
    m.paint(island, "grass")
    causeways = [h for h in path((0.0, 1.5), (0.0, 3.5)) + path((0.0, -1.5), (0.0, -3.5)) if h in lake]
    m.paint(causeways, "shallow-water")
    m.crossings = {"west bridge": bridge, "east ford": ford, "island causeways": island + causeways}

    # Twin roads fork from the start to the bridge and the ford, then climb to the portal court.
    road(m, path((0.0, 11.0), (-3.5, 8.6), (-6.5, 5.5), (-7.79, 2.0)) + path((-7.79, -1.0), (-6.5, -4.5), (-3.0, -8.5)))
    road(m, path((0.0, 11.0), (3.5, 8.6), (6.5, 5.0), (7.36, 1.5)) + path((7.36, -2.5), (5.6, -5.6), (3.0, -8.5)))

    court(m, pillars=((-2.6, -9.3), (2.6, -9.3), (-1.73, -7.6), (1.73, -7.6), (-4.33, -8.5), (4.33, -8.5)))
    start_area(m)

    m.place((0.0, 0.0), "rune-of-strength", 1)
    m.place((-0.87, 0.5), "health-potion")
    m.place((0.0, 6.0), "rune-of-focus", 1)          # spine overlook: sees the dunes, not into the trees
    m.clear(m.disc((-6.06, 4.5), 1))
    m.place((-6.06, 4.5), "rune-of-warding", 2)      # forest clearing
    m.place((-10.4, 6.5), "speed-potion")
    m.place((-3.46, 4.0), "forest-crystal")
    m.place((6.06, 4.0), "rune-of-strength", 1)      # dune crest
    m.place((10.4, 5.5), "power-potion")
    m.place((4.33, 6.5), "shrine")
    m.place((-6.06, -4.5), "ice-crystal")
    m.place((-10.4, -3.0), "health-potion")
    m.place((6.93, -4.5), "fire-crystal")
    m.place((10.4, -6.0), "speed-potion")
    m.place((0.0, -6.0), "rune-of-warding", 1)       # the north pass
    m.spawn(m.disc((-9.53, 2.8), 1))
    m.spawn(m.disc((9.53, 2.4), 1))
    m.spawn(m.disc((-8.66, -6.0), 1))
    m.spawn(m.disc((8.66, -6.5), 1))
    return m


# ---------------------------------------------------------------------------------------------------------------
# B. Serpent's Coil: walls force a switchback through all four zones in turn (east, west over the ford, east again,
# then up to the portal). Two shortcuts reward risk: a single-file goat path over the south wall, and stepping
# stones across the lake that cost a turn in open water each way. Flyers skip the lake's water.


def serpents_coil():
    m = Map("Serpent's Coil", "The long road", ["Switchback", "Lake shortcut", "Goat path"])
    river = [(-13.0, 0.6), (-6.0, -0.4), (-2.5, 0.3), (2.5, -0.3), (6.0, 0.4), (13.0, -0.6)]
    south = lambda h: xy(h)[1] > river_y(xy(h)[0], river)
    wall_s, wall_n = 5.6, -5.6

    # Greenvale: farmland around the start, hedgerows funnel east.
    m.paint(m.where(lambda h, x, y: y > wall_s), "grass")
    m.scatter(m.where(lambda h, x, y: y > wall_s + 1.5 and noise(h, 21, 1.6) > 0.68), "tree", 0.8, 21)

    # Mirewood: drowned forest along the south bank, crossed west towards the ford.
    mire = m.where(lambda h, x, y: south(h) and y <= wall_s)
    m.paint(mire, "jungle")
    m.paint([h for h in mire if noise(h, 22, 2.0) > 0.58], "swamp")
    m.scatter([h for h in mire if noise(h, 23, 2.0) > 0.45], "tree", 0.55, 23)
    for pool in ((-3.46, 3.5), (4.33, 2.8)):
        m.paint(m.disc(pool, 1), "poison-pits")
        m.clear(m.disc(pool, 1))

    # Frostmarch: ice sheets in long east-west streaks, crossed east; a slip carries a unit the whole sheet.
    frost = m.where(lambda h, x, y: not south(h) and y >= wall_n)
    m.paint(frost, "snow")
    m.paint([h for h in frost if math.sin(xy(h)[1] * 2.1 + noise(h, 24, 3.0) * 2.0) > 0.55 and noise(h, 25, 2.5) > 0.35], "ice")
    m.scatter([h for h in frost if m.terrain(h) == "snow"], "tree", 0.1, 25)

    # Ashen Crown: basalt and lava below the portal, entered from the east.
    ash = m.where(lambda h, x, y: y < wall_n)
    m.paint(ash, "basalt")
    for rill in ([(-11.0, -6.8), (-6.0, -8.0), (-3.5, -9.8)], [(3.0, -7.0), (5.0, -9.0)]):
        m.paint(m.near(rill, 0.5), "lava")
    m.scatter([h for h in ash if m.terrain(h) == "basalt"], "rock", 0.07, 26)

    # Walls of sheer crags, open only at the east end.
    for y0 in (wall_s, wall_n):
        wall = m.where(lambda h, x, y: abs(y - y0) <= 1.0 and x < 7.4)
        m.paint(wall, "crags", 3)
        for h in wall:
            m.hexes[h]["object"] = "rock" if jitter(h, 27) < 0.55 else None
    # The goat path: a single-file stair over the south wall, walled in by rock.
    goat = [h for h in path((-4.33, 7.5), (-4.33, 3.5)) if abs(xy(h)[1] - wall_s) <= 2.0]
    for h in goat:
        m.paint([h], "stone-path", 2 if abs(xy(h)[1] - wall_s) <= 1.0 else 1)
        m.hexes[h]["object"] = None
    for h in goat:
        for n in neighbours(h):
            if n in m.hexes and n not in goat and abs(xy(n)[1] - wall_s) <= 1.0:
                m.hexes[n]["object"] = "rock"

    # River with one ford in the far west; the lake has stepping stones each side of its island.
    water, lake = lake_and_river(m, river, 3)
    m.clear(water | lake)
    ford = [h for h in water if abs(xy(h)[0] + 8.23) < 0.9]
    m.paint(ford, "shallow-water")
    m.paint(m.disc((0, 0), 1) + [at(0.0, 2.0), at(0.0, -2.0)], "grass")
    m.crossings = {"west ford": ford}

    road(m, path((0.0, 11.0), (5.0, 9.0), (9.0, 6.0), (8.0, 3.5), (2.0, 2.2), (-5.0, 2.8), (-8.23, 1.8)))
    road(m, path((-8.23, -1.6), (-4.0, -3.0), (4.0, -2.6), (9.0, -4.0), (9.5, -6.5), (5.0, -8.0), (2.0, -9.5)))

    court(m)
    start_area(m)

    m.place((-4.33, 5.5), "rune-of-warding", 1)      # top of the goat path
    m.place((9.53, 6.5), "rune-of-strength", 2)      # the south gap: first big fight
    m.place((-8.66, 4.0), "speed-potion")
    m.place((-0.87, 3.5), "forest-crystal")
    m.place((-6.93, 2.5), "rune-of-focus", 1)        # south end of the ford
    m.place((0.0, 0.0), "rune-of-strength", 1)       # the island
    m.place((0.87, -0.5), "health-potion")
    m.place((-5.2, -3.5), "ice-crystal")
    m.place((6.93, -2.5), "power-potion")
    m.place((9.53, -5.5), "rune-of-warding", 2)      # the north gap
    m.place((-6.93, -9.5), "fire-crystal")
    m.place((7.79, -8.5), "speed-potion")
    m.place((-8.66, 8.0), "health-potion")
    m.spawn(m.disc((8.66, 3.0), 1))
    m.spawn(m.disc((-10.4, 2.8), 1))
    m.spawn(m.disc((7.79, -3.5), 1))
    m.spawn(m.disc((-3.46, -4.0), 1))
    return m


# ---------------------------------------------------------------------------------------------------------------
# C. The Gorge: a vertical map. The river runs at the bottom of a canyon; two high bridges cross at rim height and
# a pushed unit falls three levels into the water. The lake sits in a bowl whose sloped sides are the slow, safe
# crossing. Terraces climb to the portal's crown, with cliffs that only ramps climb.


def the_gorge():
    m = Map("The Gorge", "High ground", ["Cliffs", "High bridges", "Lake bowl"])
    river = [(-13.0, 0.8), (-7.0, -0.5), (-3.0, 0.4), (3.0, -0.4), (7.0, 0.5), (13.0, -0.8)]
    south = lambda h: xy(h)[1] > river_y(xy(h)[0], river)

    # Lowmeadow (0) below the Stepwood terrace (2) and the south rim (3).
    meadow = m.where(lambda h, x, y: y > 7.5)
    m.paint(meadow, "grass", 0)
    m.raise_to([h for h in meadow if xy(h)[1] > 9.0 and noise(h, 31, 2.0) > 0.7], 1)  # hillocks clear of the cliff
    step = m.where(lambda h, x, y: south(h) and 4.0 < y <= 7.5)
    m.paint(step, "jungle", 2)
    m.scatter([h for h in step if noise(h, 32, 2.0) > 0.4], "tree", 0.5, 32)
    rim_s = m.where(lambda h, x, y: south(h) and y <= 4.0)
    m.paint(rim_s, "crags", 3)
    m.paint([h for h in rim_s if noise(h, 33, 2.0) > 0.5], "stone-path")
    m.scatter(rim_s, "rock", 0.07, 33)

    # North rim (3), the highland shelf (4) and, past a cliff, the crown (6) with the portal on its dais.
    rim_n = m.where(lambda h, x, y: not south(h) and y > -3.8)
    m.paint(rim_n, "crags", 3)
    m.paint([h for h in rim_n if noise(h, 34, 2.0) > 0.5], "stone-path")
    shelf = m.where(lambda h, x, y: not south(h) and -7.6 < y <= -3.8)
    m.paint(shelf, "snow", 4)
    m.paint([h for h in shelf if noise(h, 35, 2.0) > 0.66], "ice")
    m.scatter([h for h in shelf if m.terrain(h) == "snow"], "tree", 0.12, 35)
    crown = m.where(lambda h, x, y: y <= -7.6)
    m.paint(crown, "darkness", 6)
    m.clear(crown)

    # Ramps: the only ways up each cliff (meadow 0 to terrace 2, shelf 4 to crown 6).
    for x in (-7.79, -1.73, 5.2):
        for h in m.where(lambda h, hx, hy: abs(hx - x) < 0.1 and 6.8 < hy < 8.3):
            m.paint([h], "stone-path", 1)
            m.hexes[h]["object"] = None

    # The gorge and the bowl: lake at 0, rings rising 1, 2 to the rims at 3.
    water, lake = lake_and_river(m, river, 3)
    m.clear(water | lake)
    for ring, height in ((4, 1), (5, 2)):
        m.paint([h for h in m.hexes if dist(h, (0, 0)) == ring and h not in water], "desert", height)
    m.clear([h for h in m.hexes if dist(h, (0, 0)) in (4, 5)])
    causeway = [h for h in path((0.0, 3.5), (0.0, -3.5)) if h in lake]
    m.paint(causeway, "shallow-water")
    m.paint(m.disc((0, 0), 1), "desert", 0)
    bridges = {}
    for name, x in (("west bridge", -7.79), ("east bridge", 7.79)):
        span = [h for h in water if abs(xy(h)[0] - x) < 0.1]
        m.paint(span, "stone-path", 3)
        bridges[name] = span
    # Beaches with stairs, so a unit thrown into the gorge can climb back out.
    for x, side in ((-10.39, 1), (-4.33, -1), (4.33, 1), (10.39, -1)):
        y0 = river_y(x, river)
        for i, height in enumerate((0, 1, 2)):
            h = at(x, y0 + side * (1.6 + i))
            if h in m.hexes and h not in water:
                m.paint([h], "desert", height)
                m.hexes[h]["object"] = None
    m.crossings = {**bridges, "bowl ford": causeway + m.disc((0, 0), 1)}

    court(m, pillars=((-2.6, -9.3), (2.6, -9.3), (-1.73, -8.0), (1.73, -8.0), (-4.33, -8.5), (4.33, -8.5)), poison=False)
    m.raise_to([h for h in m.disc(PORTAL, 4) if xy(h)[1] <= -7.6], 6)
    m.raise_to(m.disc(PORTAL, 1), 7)
    for x in (-6.06, 0.0, 6.06):
        for h in m.where(lambda h, hx, hy: abs(hx - x) < 0.1 and -8.2 < hy < -7.0):
            m.paint([h], "stone-path", 5)
            m.hexes[h]["object"] = None
    start_area(m)

    m.place((-7.79, 0.5), "rune-of-warding", 1)      # middle of the west bridge
    m.place((7.79, -0.5), "rune-of-strength", 1)     # middle of the east bridge
    m.place((0.0, 0.0), "health-potion")             # bowl island
    m.place((-2.6, 2.5), "rune-of-focus", 2)         # south rim overlook: shoot across the gorge
    m.place((-9.53, 5.5), "speed-potion")
    m.place((4.33, 5.5), "forest-crystal")
    m.place((-6.93, -5.5), "ice-crystal")
    m.place((9.53, -4.5), "power-potion")
    m.place((2.6, -6.5), "rune-of-focus", 1)         # below the centre ramp
    m.place((-4.33, -9.5), "fire-crystal")
    m.spawn(m.disc((6.93, 5.0), 1))
    m.spawn(m.disc((-7.79, -2.6), 1))
    m.spawn(m.disc((6.93, -5.0), 1))
    return m


# ---------------------------------------------------------------------------------------------------------------
# D. Lakeheart: a hub. The big lake's island (the Heart) joins all four quadrants by shallow causeways and carries a
# radius-2 Rune of Strength. Holding the Heart is the fastest way through and the Void Lord knows it; the long way
# round uses the river bridges near the edges.


def lakeheart():
    m = Map("Lakeheart", "Hold the Heart", ["Central hub", "Four causeways", "Quadrants"])
    rivers = ([(-13.0, 0.6), (-8.0, -0.4), (-4.0, 0.0)], [(4.0, 0.0), (8.0, 0.4), (13.0, -0.6)])

    quadrant = lambda x, y: ("s" if y > 0 else "n") + ("w" if x < 0 else "e")
    sw = m.where(lambda h, x, y: quadrant(x, y) == "sw")
    m.paint(sw, "swamp")
    m.paint([h for h in sw if noise(h, 41, 2.0) > 0.55], "jungle")
    m.scatter([h for h in sw if noise(h, 42, 2.0) > 0.45], "tree", 0.6, 42)
    for pool in ((-9.0, 4.5), (-4.5, 7.0)):
        m.paint(m.disc(pool, 1), "poison-pits")
        m.clear(m.disc(pool, 1))
    se = m.where(lambda h, x, y: quadrant(x, y) == "se")
    m.paint(se, "desert")
    m.paint([h for h in se if noise(h, 43, 2.2) > 0.6], "dunes", 1)
    m.scatter(se, "rock", 0.08, 43)
    nw = m.where(lambda h, x, y: quadrant(x, y) == "nw")
    m.paint(nw, "snow")
    m.paint([h for h in nw if noise(h, 44, 2.0) > 0.8], "ice")
    m.scatter([h for h in nw if m.terrain(h) == "snow" and noise(h, 45, 2.0) > 0.45], "tree", 0.5, 45)
    ne = m.where(lambda h, x, y: quadrant(x, y) == "ne")
    m.paint(ne, "basalt")
    for rill in ([(3.5, -4.5), (7.0, -6.5)], [(8.0, -2.0), (11.0, -4.5)]):
        m.paint(m.near(rill, 0.5), "lava")
    m.scatter([h for h in ne if m.terrain(h) == "basalt"], "rock", 0.07, 46)

    # Dividers: standing stones in the south and a poisoned scar in the north, each with one gap.
    for h in m.where(lambda h, x, y: abs(x) < 0.9 and 4.6 <= y <= 8.0):
        gap = abs(xy(h)[1] - 6.5) <= 0.6
        m.paint([h], "stone-path" if gap else "crags", 0 if gap else 2)
        m.hexes[h]["object"] = None if gap else "rock"
    for h in m.where(lambda h, x, y: abs(x) < 0.9 and -8.2 <= y <= -4.6):
        gap = abs(xy(h)[1] + 6.0) <= 0.6
        m.paint([h], "stone-path" if gap else "poison-pits", 0)
        m.hexes[h]["object"] = None if gap else ("rock" if jitter(h, 47) < 0.5 else None)

    water = set(m.near(rivers[0], 1.0) + m.near(rivers[1], 1.0))
    lake = set(m.disc((0, 0), 4))
    m.paint(water | lake, "open-water", 0)
    m.clear(water | lake)
    heart = m.disc((0, 0), 1)
    m.paint(heart, "grass")
    causeways = []
    for x, y in ((-3.9, 2.25), (3.9, 2.25), (-3.9, -2.25), (3.9, -2.25)):
        causeways += [h for h in path((0.0, 0.0), (x * 1.1, y * 1.1)) if h in lake and h not in heart]
    m.paint(causeways, "shallow-water")
    bridges = {}
    for name, x in (("west bridge", -9.53), ("east bridge", 9.53)):
        span = [h for h in water if abs(xy(h)[0] - x) < 0.1]
        m.paint(span, "stone-path")
        bridges[name] = span
    m.crossings = {**bridges, "the Heart": heart + causeways}

    court(m)
    start_area(m)

    m.place((0.0, 0.0), "rune-of-strength", 2)
    m.place((0.87, 0.5), "health-potion")
    m.spawn(heart)
    m.place((-6.93, 5.0), "rune-of-warding", 1)
    m.place((-10.39, 7.0), "speed-potion")
    m.place((-5.2, 2.5), "forest-crystal")
    m.place((6.93, 4.0), "rune-of-focus", 1)
    m.place((10.39, 6.0), "power-potion")
    m.place((-6.06, -4.5), "ice-crystal")
    m.place((-10.39, -3.5), "health-potion")
    m.place((6.06, -4.5), "fire-crystal")
    m.place((10.39, -5.5), "speed-potion")
    m.place((0.0, -6.0), "rune-of-warding", 1)
    m.spawn(m.disc((-8.66, -6.0), 1))
    m.spawn(m.disc((8.66, -7.0), 1))
    m.spawn(m.disc((8.66, 3.0), 1))
    return m


# ---------------------------------------------------------------------------------------------------------------
# E. Blighted March: four full-width bands, each nastier than the last. Three river crossings lead into three lanes
# of the Ember Fields, split by lava: the safe west lane, the island's centre lane under a Rune of Strength, and the
# east lane with a power potion behind lava rills.


def blighted_march():
    m = Map("Blighted March", "Escalating danger", ["Hazard bands", "Three crossings", "Lava lanes"])
    river = [(-13.0, -0.3), (-8.0, 0.6), (-3.0, -0.2), (3.0, 0.2), (8.0, -0.6), (13.0, 0.3)]
    south = lambda h: xy(h)[1] > river_y(xy(h)[0], river)

    # Greenvale: fields with hedgerows (tree lines with gaps).
    m.paint(m.where(lambda h, x, y: y > 7.0), "grass")
    m.scatter(m.where(lambda h, x, y: abs(y - 8.0) < 0.6 and abs(x) > 2.5 and math.sin(x * 1.3) < 0.55), "tree", 0.9, 51)

    # Drowned Wood: swamp, dense trees, poison pools.
    wood = m.where(lambda h, x, y: south(h) and y <= 7.0)
    m.paint(wood, "jungle")
    m.paint([h for h in wood if noise(h, 52, 2.0) > 0.5], "swamp")
    m.scatter([h for h in wood if noise(h, 53, 1.8) > 0.42], "tree", 0.55, 53)
    for pool in ((-2.6, 4.5), (5.2, 5.0), (-9.53, 3.5)):
        m.paint(m.disc(pool, 1), "poison-pits")
        m.clear(m.disc(pool, 1))

    # Ember Fields: basalt in three lanes divided by lava, one basalt crossing in each divider.
    ember = m.where(lambda h, x, y: not south(h) and y >= -7.0)
    m.paint(ember, "basalt")
    for x0, gap in ((-4.33, -4.5), (4.33, -3.5)):
        rill = [h for h in ember if abs(xy(h)[0] - x0 - math.sin(xy(h)[1]) * 0.6) < 0.5 and xy(h)[1] < -1.6]
        m.paint(rill, "lava")
        m.paint([h for h in rill if abs(xy(h)[1] - gap) < 0.6], "basalt")
    m.paint([h for h in ember if xy(h)[0] > 6.0 and math.sin(xy(h)[1] * 1.7) > 0.8], "lava")
    m.scatter([h for h in ember if m.terrain(h) == "basalt"], "rock", 0.06, 54)

    # Void Scar: darkness and poison around the portal.
    scar = m.where(lambda h, x, y: y < -7.0)
    m.paint(scar, "darkness")
    m.paint([h for h in scar if noise(h, 55, 1.8) > 0.62 and abs(xy(h)[0]) > 2.0], "poison-pits")

    water, lake = lake_and_river(m, river, 3)
    m.clear(water | lake)
    bridge = [h for h in water if abs(xy(h)[0] + 8.66) < 0.1]
    m.paint(bridge, "stone-path")
    ford = [h for h in water if abs(xy(h)[0] - 8.66) < 0.9]
    m.paint(ford, "shallow-water")
    island = m.disc((0, 0), 1)
    m.paint(island, "grass")
    causeways = [h for h in path((0.0, 1.5), (0.0, 3.5)) + path((0.0, -1.5), (0.0, -3.5)) if h in lake]
    m.paint(causeways, "shallow-water")
    m.crossings = {"west bridge": bridge, "island causeways": island + causeways, "east ford": ford}

    road(m, path((0.0, 11.0), (0.0, 8.5), (-4.0, 6.0), (-8.66, 2.0)))
    road(m, path((0.0, 8.5), (4.0, 6.5), (8.66, 2.0)))

    court(m, pillars=((-2.6, -9.3), (2.6, -9.3), (-1.73, -7.6), (1.73, -7.6), (0.0, -8.0)))
    start_area(m)

    m.place((-6.93, 6.0), "speed-potion")
    m.clear(m.disc((0.0, 6.5), 1))
    m.place((0.0, 6.5), "rune-of-focus", 2)          # the wood's central clearing
    m.place((-6.06, 3.0), "forest-crystal")
    m.place((7.79, 4.5), "health-potion")
    m.place((-8.66, 0.5), "rune-of-warding", 1)      # on the west bridge
    m.place((0.0, 0.0), "rune-of-strength", 1)
    m.place((-8.66, -4.0), "fire-crystal")           # safe west lane
    m.place((0.0, -4.5), "rune-of-strength", 1)      # centre lane
    m.place((8.66, -3.5), "power-potion")            # east lane, behind the rills
    m.place((6.06, -6.0), "ice-crystal")
    m.place((-6.06, -8.5), "health-potion")
    m.place((0.0, -9.0), "rune-of-warding", 1)       # before the gate
    m.spawn(m.disc((-5.2, 4.8), 1))
    m.spawn(m.disc((5.2, 2.6), 1))
    m.spawn(m.disc((-6.93, -5.0), 1))
    m.spawn(m.disc((2.6, -5.5), 1))
    return m


MAPS = [twin_roads, serpents_coil, the_gorge, lakeheart, blighted_march]



# ---------------------------------------------------------------------------------------------------------------
# Checks: the editor's Co-op map check (MapValidation.cs) plus walking routes from the start to the portal.


def can_step(m, f, t):
    """Terrain.canEnter for a walker taking a voluntary step."""
    cell = m.hexes.get(t)
    if cell is None or cell["object"] in OBSTACLES | LANDMARKS:
        return False
    rise = cell["elevation"] - m.elevation(f)
    if cell["terrain"] == "ice" and rise > 0:
        return False
    if rise < 0:
        return True
    leaving_water = m.terrain(f) == "open-water"
    return cell["elevation"] + (1 if leaving_water else 0) - m.elevation(f) <= 1


def route(m, avoid, blocked=frozenset()):
    """Fewest steps from the start to a hex beside the portal, avoiding the given terrains and hexes."""
    seen = {m.start: 0}
    queue = deque([m.start])
    goal = set(neighbours(m.portal))
    while queue:
        h = queue.popleft()
        if h in goal:
            return seen[h]
        for n in neighbours(h):
            if n in seen or n == m.portal or n in blocked or not can_step(m, h, n) or m.terrain(n) in avoid:
                continue
            seen[n] = seen[h] + 1
            queue.append(n)
    return None


def check(m):
    issues = []
    spawn = [h for h, c in m.hexes.items() if c["spawn"]]
    if len(spawn) < 24:
        issues.append(f"only {len(spawn)} spawn hexes")
    wet_spawn = [h for h in spawn if m.terrain(h) in ("open-water", "shallow-water")]
    if len(wet_spawn) < 6:
        issues.append(f"only {len(wet_spawn)} spawn hexes in water")
    room = [h for h in m.disc(m.start, 3) if h != m.start and m.hexes[h]["object"] not in OBSTACLES | LANDMARKS
            and m.terrain(h) not in ("lava", "poison-pits") and not m.hexes[h]["spawn"] and h != m.portal]
    if len(room) < 32:
        issues.append(f"start room {len(room)} < 32")
    for h, c in m.hexes.items():
        if c["object"] in LANDMARKS | ITEMS and dist(h, m.start) <= 5:
            issues.append(f"{c['object']} {dist(h, m.start)} from start")
        if c["object"] and (c["spawn"] or h == m.portal):
            issues.append(f"{c['object']} on spawn/portal at {h}")
    hazards = {"open-water", "lava", "poison-pits"}
    dry = route(m, hazards)
    wet = route(m, {"lava", "poison-pits"})
    if dry is None:
        issues.append("no dry route to the portal")
    crossings = {}
    every = set().union(*m.crossings.values()) if m.crossings else set()
    for name, hexes in m.crossings.items():
        crossings[name] = route(m, hazards, frozenset(every - set(hexes)))
        if crossings[name] is None:
            issues.append(f"no dry route over the {name}")
    if m.crossings and route(m, hazards, frozenset(every)) is not None:
        issues.append("the river can be crossed dry away from the named crossings")
    counts = {}
    for c in m.hexes.values():
        if c["object"]:
            counts[c["object"]] = counts.get(c["object"], 0) + 1
    return issues, {"spawn": len(spawn), "water spawn": len(wet_spawn), "room": len(room), "dry route": dry, "wet route": wet, "crossings": crossings,
                    "objects": dict(sorted(counts.items()))}


# ---------------------------------------------------------------------------------------------------------------
# Preview: a PNG per map for eyeballing the layout.

COLOURS = {
    "grass": (120, 168, 82), "roads": (178, 156, 112), "open-water": (52, 104, 178), "shallow-water": (110, 170, 212),
    "stone-path": (160, 160, 150), "crags": (120, 112, 104), "desert": (222, 198, 136), "dunes": (204, 168, 98),
    "jungle": (54, 120, 60), "swamp": (88, 104, 62), "darkness": (62, 48, 84), "poison-pits": (120, 170, 40),
    "basalt": (70, 64, 62), "lava": (232, 92, 30), "snow": (226, 232, 238), "ice": (160, 214, 236),
}
GLYPHS = {
    "tree": ("T", (20, 70, 20)), "rock": ("R", (60, 60, 60)), "shrine": ("Sh", (255, 255, 255)),
    "fire-crystal": ("FC", (255, 80, 0)), "forest-crystal": ("GC", (0, 200, 0)), "ice-crystal": ("IC", (0, 200, 255)),
    "rune-of-focus": ("rF", (200, 0, 200)), "rune-of-strength": ("rS", (200, 0, 0)), "rune-of-warding": ("rW", (0, 0, 200)),
    "power-potion": ("pP", (255, 0, 0)), "speed-potion": ("pS", (0, 120, 255)), "health-potion": ("pH", (0, 160, 0)),
}


def preview(m, file):
    from PIL import Image, ImageDraw

    s = 16
    width, height = int(3 * s * RADIUS + 4 * s), int(math.sqrt(3) * s * (2 * RADIUS + 1) + 2 * s)
    image = Image.new("RGB", (width, height + 40), (30, 30, 30))
    draw = ImageDraw.Draw(image)
    cx, cy = width / 2, height / 2

    def centre(h):
        return cx + 1.5 * s * h[1], cy + math.sqrt(3) * s * (h[0] + h[1] / 2)

    for h, c in m.hexes.items():
        x, y = centre(h)
        corners = [(x + s * math.cos(math.pi / 3 * i), y + s * math.sin(math.pi / 3 * i)) for i in range(6)]
        r, g, b = COLOURS[c["terrain"]]
        k = 1 + 0.1 * c["elevation"]
        fill = tuple(min(255, int(v * k)) for v in (r, g, b))
        draw.polygon(corners, fill=fill, outline=(170, 0, 255) if c["spawn"] else (40, 40, 40), width=3 if c["spawn"] else 1)
        if c["elevation"]:
            draw.text((x - s * 0.8, y - s * 0.75), str(c["elevation"]), fill=(0, 0, 0))
        if c["object"]:
            text, colour = GLYPHS[c["object"]]
            if c["object"] in NEXUS:
                text += str(c["radius"])
            draw.text((x - 6, y - 5), text, fill=colour)
        if h == m.start:
            draw.ellipse((x - 7, y - 7, x + 7, y + 7), fill=(255, 215, 0))
        if h == m.portal:
            draw.ellipse((x - 9, y - 9, x + 9, y + 9), fill=(220, 0, 220))
    draw.text((10, height + 10), f"{m.name} - {m.role}", fill=(255, 255, 255))
    image.save(file)


def main():
    preview_dir = None
    if "--preview" in sys.argv:
        preview_dir = Path(sys.argv[sys.argv.index("--preview") + 1])
        preview_dir.mkdir(parents=True, exist_ok=True)
    failed = False
    for build in MAPS:
        m = build()
        water_spawns(m)
        issues, stats = check(m)
        slug = "incursion-" + m.name.lower().replace("'", "").replace(" ", "-")
        print(f"{m.name}: {stats}")
        for issue in issues:
            print(f"  ! {issue}")
            failed = True
        (ROOT / "api" / "scenarios" / f"{slug}.json").write_text(
            json.dumps(m.scenario(), indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
        if preview_dir:
            preview(m, preview_dir / f"{slug}.png")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
