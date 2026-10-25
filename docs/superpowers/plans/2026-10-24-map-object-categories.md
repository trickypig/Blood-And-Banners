# Map Object Categories Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split map objects into Obstacles, Landmarks, Nexus Points and Items with distinct movement/sight rules, art, and one map-editor button per category.

**Architecture:** A hex keeps one `object` string; a catalog in PHP (`Board`) and C# (`MapObjectArt`) maps each kind to its category. The engine board gains an `impassable` flag (obstacles + landmarks) separate from `sightBlocker` (obstacles only); movement code reads `impassable`, line of sight keeps `sightBlocker`. The UI catalog drives the art component and the editor's four category buttons.

**Tech Stack:** PHP 8 rules engine (`api/`, tests via `php api/tests/run.php`), .NET 10 Blazor UI + Contracts (bUnit/xUnit via `dotnet test`).

**Spec:** `docs/superpowers/specs/2026-10-24-map-object-categories-design.md`

## Global Constraints

- Kinds (exact ids): Obstacle `tree`, `rock`; Landmark `shrine`, `fire-crystal`, `forest-crystal`, `ice-crystal`; Nexus `rune-of-magic`, `rune-of-strength`, `rune-of-warding`; Item `power-potion`, `speed-potion`, `health-potion`.
- Display names: Tree, Rock, Shrine, Fire Crystal, Forest Crystal, Ice Crystal, Rune of Magic, Rune of Strength, Rune of Warding, Power Potion, Speed Potion, Health Potion. Category names: Obstacle, Landmark, Nexus Point, Item.
- A hex holds at most one of: start position, obstacle, landmark, nexus point, item. Placing any replaces the others.
- Obstacles block movement (flyers too) and sight. Landmarks block movement (flyers too), not sight. Nexus and Items block nothing and have no gameplay effect.
- No migration or compatibility handling for old maps or old payloads (user: there shouldn't be any). The art-less `sightBlocker` flag still means "blocks movement and sight" because the rules already read it.
- Items drawn at 0.8× hex radius, just below hex centre on a soft shadow, beneath units.
- Image paths: `images/obstacles/`, `images/landmarks/`, `images/nexus/`, `images/items/`, file `<kind>-<n>.png`.

## Review Focus

1. Swapping an obstacle for a landmark, rune or potion in the editor must drop the sight-blocker flag, or the hex keeps blocking sight — pinned in Task 4 (`SetObject` sets the flag from the kind; tree → fire-crystal assertion).
2. Engine boards built directly from flags (test helpers, `vanilla_board`) have no `impassable` key; `Board::impassable` treats a sight blocker as impassable so those keep blocking movement — pinned in Task 1.
3. Push and teleport previews on the client must refuse landmark hexes, not just obstacles — pinned in Task 2 (preview reads `Impassable`).
4. A potion placed on a start hex must remove the start (and vice versa) — pinned in Task 4.
5. Units deploying next to a start must not be placed on a landmark — Placement switches to `Board::impassable` in Task 1; covered by the full PHP suite plus the landmark movement test.

---

### Task 1: Rules — categories, `impassable`, movement call sites

**Files:**
- Modify: `api/src/Rules/Vanilla/Board.php`
- Modify: `api/src/Game/GameService.php:1268` (projection fields)
- Modify (sightBlocker → `Board::impassable`): `api/src/Rules/Vanilla/Terrain.php:71`, `Forced.php:72`, `Forced.php:130`, `Modes/CoopSetup.php:194`, `Placement.php:20`, `Abilities/SkyToss.php:39`, `Abilities/MyceliumNetwork.php:40`, `Abilities/TentacleRegeneration.php:28`
- Leave on `sightBlocker`: `api/src/Rules/HexLineOfSight.php`, `api/src/Rules/Vanilla/Combat.php:64`
- Test: `api/tests/VanillaTerrainTest.php` (replace the `'every obstacle kind blocks movement and sight even for flyers'` test, ~line 168)

**Interfaces:**
- Produces (PHP): `Board::OBSTACLES`, `Board::LANDMARKS`, `Board::NEXUS_POINTS`, `Board::ITEMS` (string lists); `Board::blocksSight(array $scenarioHex): bool`; `Board::blocksMovement(array $scenarioHex): bool`; `Board::impassable(array $boardTile): bool`. Board tiles gain `'impassable' => bool`. The map projection hex gains `'impassable' => bool`.

- [ ] **Step 1: Write the failing tests** — replace the existing obstacle test in `vanilla_terrain_tests()` with:

```php
        'obstacles block movement and sight even for flyers' => static function (): void {
            foreach (Board::OBSTACLES as $obstacle) {
                [$board, $state] = object_line($obstacle);
                expect_same(true, $board['2,0']['sightBlocker'], "a {$obstacle} blocks sight without the flag");
                expect_same(true, $board['2,0']['impassable'], "a {$obstacle} blocks movement");
                expect_same(['1,0'], array_column(Movement::options($state, 'a1', 4), 'destination'), "a flyer can neither enter nor pass a {$obstacle}");
                expect_same(false, Combat::visible($state, '0,0', '4,0'), "a {$obstacle} blocks line of sight");
            }
        },

        'landmarks block movement but not sight' => static function (): void {
            foreach (Board::LANDMARKS as $landmark) {
                [$board, $state] = object_line($landmark);
                expect_same(false, $board['2,0']['sightBlocker'], "a {$landmark} does not block sight");
                expect_same(['1,0'], array_column(Movement::options($state, 'a1', 4), 'destination'), "a flyer can neither enter nor pass a {$landmark}");
                expect_same(true, Combat::visible($state, '0,0', '4,0'), "line of sight passes a {$landmark}");
            }
        },

        'nexus points and items block nothing and units may stop on them' => static function (): void {
            foreach (array_merge(Board::NEXUS_POINTS, Board::ITEMS) as $kind) {
                [$board, $state] = object_line($kind);
                expect_same([false, false], [$board['2,0']['sightBlocker'], $board['2,0']['impassable']], "a {$kind} blocks nothing");
                expect_same(true, in_array('2,0', array_column(Movement::options($state, 'a1', 4), 'destination'), true), "a unit may end on a {$kind}");
                expect_same(true, Combat::visible($state, '0,0', '4,0'), "line of sight passes a {$kind}");
            }
        },

        'a sight blocker is always impassable' => static function (): void {
            expect_same(true, Board::impassable(['sightBlocker' => true]), 'flag-only tile');
            expect_same(false, Board::impassable(['sightBlocker' => false]), 'open tile');
            expect_same(true, Board::impassable(['sightBlocker' => false, 'impassable' => true]), 'landmark tile');
            expect_same(true, Board::blocksMovement(['object' => null, 'sightBlocker' => true]), 'art-less blocked hex');
        },
```

and add this helper next to `terrain_unit` near the top of the file:

```php
/** A five-hex line with `$kind` on 2,0, a flyer on 0,0 and an enemy on 4,0. */
function object_line(string $kind): array
{
    $hexes = [];
    for ($q = 0; $q < 5; $q++) {
        $hexes[] = [
            'q' => $q, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0,
            'object' => $q === 2 ? $kind : null, 'sightBlocker' => false,
        ];
    }
    $board = Board::fromScenario(['map' => ['hexes' => $hexes]]);
    return [$board, vanilla_battle($board, [
        terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 4]),
        vanilla_unit('b1', 'seat-b', '4,0'),
    ])];
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: FAIL on the four new tests (undefined `Board::LANDMARKS` / `impassable` index).

- [ ] **Step 3: Implement `Board`** — replace the constant and `blocks()` at the top of `api/src/Rules/Vanilla/Board.php`:

```php
    /** Obstacles fill their hex: nothing may enter it (flying units included) or see through it. */
    public const OBSTACLES = ['tree', 'rock'];
    /** Landmarks fill their hex for movement (flying included) but can be seen past. */
    public const LANDMARKS = ['shrine', 'fire-crystal', 'forest-crystal', 'ice-crystal'];
    /** Nexus points and items block nothing; a unit may stand on them. They have no rules effect yet. */
    public const NEXUS_POINTS = ['rune-of-magic', 'rune-of-strength', 'rune-of-warding'];
    public const ITEMS = ['power-potion', 'speed-potion', 'health-potion'];

    /** Whether a scenario hex blocks sight: an obstacle, or the art-less flag. */
    public static function blocksSight(array $hex): bool
    {
        return (bool)($hex['sightBlocker'] ?? false) || in_array($hex['object'] ?? null, self::OBSTACLES, true);
    }

    /** Whether a scenario hex blocks movement, flying included: anything that blocks sight, or a landmark. */
    public static function blocksMovement(array $hex): bool
    {
        return self::blocksSight($hex) || in_array($hex['object'] ?? null, self::LANDMARKS, true);
    }

    /** Whether nothing may enter an engine board tile. A sight blocker always is, so boards built from flags alone need no extra key. */
    public static function impassable(array $tile): bool
    {
        return (bool)($tile['impassable'] ?? $tile['sightBlocker'] ?? false);
    }
```

and in `fromScenario` replace `'sightBlocker' => self::blocks($hex),` with:

```php
                'sightBlocker' => self::blocksSight($hex),
                'impassable' => self::blocksMovement($hex),
```

- [ ] **Step 4: Switch movement call sites** — each line changes only the blocked check:
  - `Terrain.php:71`: `if ($destination === null || Board::impassable($destination)) {`
  - `Forced.php:72`: `if ($hex === null || Board::impassable($hex) || $hex['elevation'] - ...` (rest unchanged)
  - `Forced.php:130`: `if ($key === $origin || Board::impassable($hex) || self::occupantAt($state, $key) !== null) {`
  - `Modes/CoopSetup.php:194`: `return !Board::impassable($state['board'][$hex]) && (...)`
  - `Placement.php:20`: `if (!Board::impassable($board[$key]) && !isset($occupied[$key])) {`
  - `Abilities/SkyToss.php:39`: `if (!Board::impassable($hex) && !in_array(...`
  - `Abilities/MyceliumNetwork.php:40`: `if (Board::impassable($tile) || in_array($hex, $occupied, true)) {`
  - `Abilities/TentacleRegeneration.php:28`: `... && !Board::impassable($state['board'][$hex]) && ...`
  Add `use BloodAndBanners\Rules\Vanilla\Board;` to files outside the `BloodAndBanners\Rules\Vanilla` namespace (`Modes/`, `Abilities/`) if not already imported. Then run `grep -rn "sightBlocker" api/src/Rules` — only `Board.php`, `Combat.php:64` and `HexLineOfSight.php` may remain.

- [ ] **Step 5: Projection** — in `GameService::mapProjection` (`api/src/Game/GameService.php:1268`) replace `'sightBlocker' => Board::blocks($hex),` with:

```php
                'sightBlocker' => Board::blocksSight($hex),
                'impassable' => Board::blocksMovement($hex),
```

Run `grep -rn "Board::blocks(" api` — must return nothing.

- [ ] **Step 6: Run all PHP tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`.

- [ ] **Step 7: Commit**

```bash
git add api
git commit -m "Split map objects into obstacles, landmarks, nexus points and items in the rules"
```

---

### Task 2: Contracts + battlefield client use `impassable`

**Files:**
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs:30-43` (`HexProjection`)
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor:1048`, `:1070`
- Test: `tests/BloodAndBanners.Contracts.Tests/MapContractTests.cs`, `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs` (created in Task 3; put this task's UI test in `ObstacleTests.cs` for now — Task 3 renames that file)

**Interfaces:**
- Consumes: projection JSON field `impassable` from Task 1.
- Produces (C#): `HexProjection.Impassable` (`bool`, init, default false).

- [ ] **Step 1: Write the failing tests** — add to `MapContractTests`:

```csharp
    [Fact]
    public void Hex_projection_reads_the_impassable_flag()
    {
        const string json = """{"q":0,"r":0,"biome":"plains","terrain":"grass","elevation":0,"sightBlocker":false,"impassable":true,"occupantId":null,"object":"shrine"}""";
        var shrine = JsonSerializer.Deserialize<HexProjection>(json, ContractJson.Options)!;
        Assert.Equal((false, true), (shrine.SightBlocker, shrine.Impassable));
    }
```

(Add `using System.Text.Json;` and `using BloodAndBanners.Contracts;` if missing.) And add to `ObstacleTests` (UI tests):

```csharp
    [Fact]
    public void Teleport_targets_skip_impassable_hexes()
    {
        var fixture = BattleFixtures.Projection();
        var empty = fixture.Board.Hexes.First(hex => hex.OccupantId is null && !hex.SightBlocker);
        var hexes = fixture.Board.Hexes.Select(hex => hex == empty ? hex with { Impassable = true, Object = "shrine" } : hex).ToList();
        var table = new GameTable { Projection = fixture with { Board = fixture.Board with { Hexes = hexes } } };
        Assert.DoesNotContain($"{empty.Q},{empty.R}", table.TeleportTargetsFor(fixture.Units.First(unit => unit.Hex is not null).Id));
    }
```

Before writing this test, read `GameTable.razor` around line 1040 to find the real name and accessibility of the teleport-target method (the one containing `!hex.SightBlocker && hex.OccupantId is null`). If it is private, make it `internal` and confirm the UI project has `InternalsVisibleTo("BloodAndBanners.UI.Tests")` (`grep -rn InternalsVisibleTo src/BloodAndBanners.UI`); if neither is practical, drop this UI test and rely on Step 3's mechanical change plus the Task 4 eyeball check.

- [ ] **Step 2: Run to verify failure**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests --filter Hex_projection_reads_the_impassable_flag`
Expected: build error — `Impassable` not defined.

- [ ] **Step 3: Implement** — in `HexProjection`'s body:

```csharp
    public int? PlayerStart { get; init; }
    /// <summary>The map object on the hex: an obstacle, landmark, nexus point or item kind such as "tree" or "fire-crystal".</summary>
    public string? Object { get; init; }
    /// <summary>Nothing may enter the hex, flying included: obstacles and landmarks.</summary>
    public bool Impassable { get; init; }
```

In `GameTable.razor` change `!hex.SightBlocker` (line ~1048, teleport targets) to `!hex.Impassable`, and `next.SightBlocker` (line ~1070, push preview) to `next.Impassable`. Test fixtures that mark a hex `SightBlocker = true` to block movement must also set `Impassable = true` — run `grep -rn "SightBlocker = true" tests` and update each.

- [ ] **Step 4: Run tests**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests` then `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts src/BloodAndBanners.UI/Components/GameTable.razor tests
git commit -m "Use the impassable flag for push and teleport previews"
```

---

### Task 3: Art assets, `MapObjectArt` catalog, `HexMapObject` drawing

**Files:**
- Create: `images/landmarks/{fire-crystal,forest-crystal,ice-crystal}-1.png`, `images/nexus/{rune-of-magic,rune-of-strength,rune-of-warding}-1.png`, `images/items/{power-potion,speed-potion,health-potion}-1.png`
- Move: `images/obstacles/shrine-1.png` → `images/landmarks/shrine-1.png`
- Rename/rewrite: `src/BloodAndBanners.UI/Services/ObstacleArt.cs` → `MapObjectArt.cs`
- Rename/rewrite: `src/BloodAndBanners.UI/Components/HexObstacle.razor(.css)` → `HexMapObject.razor(.css)`
- Modify: `GameTable.razor:184`, `GameTable.razor:1854` (hex label), `MapEditor.razor:140`
- Rename/rewrite test: `tests/BloodAndBanners.UI.Tests/ObstacleTests.cs` → `MapObjectTests.cs` (art + battlefield tests here; editor tests in Task 4)

**Interfaces:**
- Produces (C#, `BloodAndBanners.UI.Services.MapObjectArt`):
  - `IReadOnlyList<string> Categories` = `["obstacle","landmark","nexus","item"]`
  - `IReadOnlyList<string> KindsIn(string category)` (catalog order)
  - `bool IsKnown(string? kind)`, `string? CategoryOf(string? kind)`
  - `bool BlocksSight(string? kind)` (obstacle), `bool Impassable(string? kind)` (obstacle or landmark)
  - `string Display(string kind)`, `string CategoryDisplay(string category)`
  - `int VariantCount(string kind)`, `int Variant(string kind, int q, int r)`
  - `string? ImagePath(string? kind, int variant = 1)`, `string? ImagePath(string? kind, int q, int r)`
- Produces (Razor): `<HexMapObject Tile ImageBaseUrl />`, rendering `<g class="hex-map-object map-object-{kind} category-{category}" data-map-object="{kind}" data-category="{category}">`.

- [ ] **Step 1: Copy and downscale the icons to 128×128** (PowerShell, from repo root):

```powershell
Add-Type -AssemblyName System.Drawing
$src = 'images/.temp/6000FantasyIcons/ProfessionIcons'
$map = @{
  'landmarks/fire-crystal-1.png' = "$src/ResourceIcons/Res_27.png"
  'landmarks/forest-crystal-1.png' = "$src/ResourceIcons/Res_29_stoneg.png"
  'landmarks/ice-crystal-1.png' = "$src/ResourceIcons/Res_75_crystalS.png"
  'nexus/rune-of-magic-1.png' = "$src/LootIcons/Loot_86_runestone.png"
  'nexus/rune-of-strength-1.png' = "$src/LootIcons/Loot_198_rune.png"
  'nexus/rune-of-warding-1.png' = "$src/ProfessionAndCraftIcons/Enchantment/Enchantment_58_runecrystal.png"
  'items/power-potion-1.png' = "$src/ResourceIcons/Res_102.png"
  'items/speed-potion-1.png' = "$src/ResourceIcons/Res_39_colbGreen.png"
  'items/health-potion-1.png' = "$src/ResourceIcons/Res_98.png"
}
foreach ($dest in $map.Keys) {
  $out = Join-Path (Resolve-Path images) $dest
  New-Item -ItemType Directory -Force (Split-Path $out) | Out-Null
  $img = [System.Drawing.Image]::FromFile((Resolve-Path $map[$dest]))
  $bmp = New-Object System.Drawing.Bitmap 128, 128
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.InterpolationMode = 'HighQualityBicubic'; $g.PixelOffsetMode = 'HighQuality'; $g.CompositingQuality = 'HighQuality'
  $g.DrawImage($img, 0, 0, 128, 128)
  $bmp.Save($out, [System.Drawing.Imaging.ImageFormat]::Png)
  $g.Dispose(); $bmp.Dispose(); $img.Dispose()
}
git mv images/obstacles/shrine-1.png images/landmarks/shrine-1.png
```

Verify: `file images/landmarks/*.png images/nexus/*.png images/items/*.png` shows 128 x 128 RGBA (shrine keeps its own size).

- [ ] **Step 2: Write the failing art + battlefield tests** — `git mv tests/BloodAndBanners.UI.Tests/ObstacleTests.cs tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`, rename the class to `MapObjectTests`, and replace `Each_hex_picks_a_stable_image_and_every_image_gets_used` and `The_battlefield_stands_obstacles_on_their_hexes` with:

```csharp
    [Fact]
    public void The_catalog_sorts_every_kind_into_its_category_with_its_own_images()
    {
        Assert.Equal(["obstacle", "landmark", "nexus", "item"], MapObjectArt.Categories);
        Assert.Equal(["tree", "rock"], MapObjectArt.KindsIn("obstacle"));
        Assert.Equal(["shrine", "fire-crystal", "forest-crystal", "ice-crystal"], MapObjectArt.KindsIn("landmark"));
        Assert.Equal(["rune-of-magic", "rune-of-strength", "rune-of-warding"], MapObjectArt.KindsIn("nexus"));
        Assert.Equal(["power-potion", "speed-potion", "health-potion"], MapObjectArt.KindsIn("item"));
        Assert.Equal((true, true), (MapObjectArt.BlocksSight("rock"), MapObjectArt.Impassable("rock")));
        Assert.Equal((false, true), (MapObjectArt.BlocksSight("shrine"), MapObjectArt.Impassable("shrine")));
        Assert.Equal((false, false), (MapObjectArt.BlocksSight("health-potion"), MapObjectArt.Impassable("rune-of-magic")));
        Assert.Equal("Fire Crystal", MapObjectArt.Display("fire-crystal"));
        Assert.Equal("Nexus Point", MapObjectArt.CategoryDisplay("nexus"));

        var picks = Enumerable.Range(0, 10).SelectMany(q => Enumerable.Range(0, 10).Select(r => MapObjectArt.Variant("tree", q, r))).ToList();
        Assert.Equal([1, 2, 3, 4], picks.Distinct().Order());
        Assert.Equal("images/landmarks/shrine-1.png", MapObjectArt.ImagePath("shrine", 3, 5));
        Assert.Equal("images/obstacles/rock-2.png", MapObjectArt.ImagePath("rock", 2));
        Assert.Equal("images/nexus/rune-of-warding-1.png", MapObjectArt.ImagePath("rune-of-warding", 4, 1));
        Assert.Equal("images/items/speed-potion-1.png", MapObjectArt.ImagePath("speed-potion"));
        Assert.Null(MapObjectArt.ImagePath("statue", 1));
        Assert.Null(MapObjectArt.CategoryOf("statue"));
    }

    [Theory]
    [InlineData("rock", "obstacle", "blocked by rock")]
    [InlineData("ice-crystal", "landmark", "landmark: ice crystal")]
    [InlineData("rune-of-magic", "nexus", "nexus point: rune of magic")]
    [InlineData("health-potion", "item", "item: health potion")]
    public void The_battlefield_draws_each_category_on_its_hex(string kind, string category, string label)
    {
        var fixture = BattleFixtures.Projection();
        var hexes = fixture.Board.Hexes.Select(hex => (hex.Q, hex.R) == (4, 4) ? hex with { Object = kind } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var drawn = Assert.Single(cut.FindAll("[data-map-object]"));
        Assert.Equal((kind, category), (drawn.GetAttribute("data-map-object"), drawn.GetAttribute("data-category")));
        Assert.Equal($"{Images}{MapObjectArt.ImagePath(kind, 4, 4)}", drawn.QuerySelector("image")!.GetAttribute("href"));
        Assert.Contains(label, cut.Find("[data-hex='4,4']").GetAttribute("aria-label"));
    }

    [Fact]
    public void Nexus_points_and_items_are_drawn_before_a_unit_standing_on_them()
    {
        var fixture = BattleFixtures.Projection();
        var occupied = fixture.Board.Hexes.First(hex => hex.OccupantId is not null);
        var hexes = fixture.Board.Hexes.Select(hex => hex == occupied ? hex with { Object = "power-potion" } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var markup = cut.Markup;
        var potion = markup.IndexOf("data-map-object=\"power-potion\"", StringComparison.Ordinal);
        var token = markup.IndexOf("unit-token", potion, StringComparison.Ordinal);
        Assert.True(potion >= 0 && token > potion, "the potion is painted before (beneath) the unit token");
    }
```

- [ ] **Step 3: Run to verify failure**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter MapObjectTests`
Expected: build error — `MapObjectArt` not defined.

- [ ] **Step 4: Implement `MapObjectArt`** — `git mv src/BloodAndBanners.UI/Services/ObstacleArt.cs src/BloodAndBanners.UI/Services/MapObjectArt.cs` and replace its contents:

```csharp
namespace BloodAndBanners.UI.Services;

/// <summary>
/// Map objects, one per hex in the hex's "object": obstacles (block movement, flying included, and sight), landmarks
/// (block movement only), nexus points and items (block nothing; no rules effect yet). Each kind has images at
/// images/&lt;folder&gt;/&lt;kind&gt;-1.png onwards, picked per hex by position like the terrain variants.
/// Must match the lists in Board.php.
/// </summary>
public static class MapObjectArt
{
    private sealed record Kind(string Id, string Display, string Category, int Variants);

    private static readonly (string Id, string Display, string Folder)[] CategoryInfo =
    [
        ("obstacle", "Obstacle", "obstacles"),
        ("landmark", "Landmark", "landmarks"),
        ("nexus", "Nexus Point", "nexus"),
        ("item", "Item", "items"),
    ];

    /// <summary>In the order the editor's buttons for each category cycle through them.</summary>
    private static readonly Kind[] Art =
    [
        new("tree", "Tree", "obstacle", 4),
        new("rock", "Rock", "obstacle", 4),
        new("shrine", "Shrine", "landmark", 1),
        new("fire-crystal", "Fire Crystal", "landmark", 1),
        new("forest-crystal", "Forest Crystal", "landmark", 1),
        new("ice-crystal", "Ice Crystal", "landmark", 1),
        new("rune-of-magic", "Rune of Magic", "nexus", 1),
        new("rune-of-strength", "Rune of Strength", "nexus", 1),
        new("rune-of-warding", "Rune of Warding", "nexus", 1),
        new("power-potion", "Power Potion", "item", 1),
        new("speed-potion", "Speed Potion", "item", 1),
        new("health-potion", "Health Potion", "item", 1),
    ];

    public static IReadOnlyList<string> Categories { get; } = CategoryInfo.Select(info => info.Id).ToList();

    public static IReadOnlyList<string> KindsIn(string category) => Art.Where(kind => kind.Category == category).Select(kind => kind.Id).ToList();

    private static Kind? Find(string? kind) => kind is null ? null : Art.FirstOrDefault(art => art.Id == kind);

    public static bool IsKnown(string? kind) => Find(kind) is not null;

    public static string? CategoryOf(string? kind) => Find(kind)?.Category;

    public static bool BlocksSight(string? kind) => CategoryOf(kind) == "obstacle";

    public static bool Impassable(string? kind) => CategoryOf(kind) is "obstacle" or "landmark";

    public static string Display(string kind) => Find(kind)!.Display;

    public static string CategoryDisplay(string category) => CategoryInfo.Single(info => info.Id == category).Display;

    /// <summary>Images available for a kind.</summary>
    public static int VariantCount(string kind) => Find(kind)!.Variants;

    /// <summary>
    /// Which of the kind's images a hex shows, from 1. Purely cosmetic, so never stored; salted so it does not
    /// simply follow the hex's terrain variant.
    /// </summary>
    public static int Variant(string kind, int q, int r) => TerrainArt.Variant(q * 31 + 17, r * 37 + 11) % VariantCount(kind) + 1;

    public static string? ImagePath(string? kind, int variant = 1) => Find(kind) is { } art
        ? $"images/{CategoryInfo.Single(info => info.Id == art.Category).Folder}/{art.Id}-{variant}.png"
        : null;

    public static string? ImagePath(string? kind, int q, int r) => IsKnown(kind) ? ImagePath(kind, Variant(kind!, q, r)) : null;
}
```

- [ ] **Step 5: Implement `HexMapObject`** — `git mv` `HexObstacle.razor` → `HexMapObject.razor` and `HexObstacle.razor.css` → `HexMapObject.razor.css`. Contents:

```razor
@namespace BloodAndBanners.UI.Components
@using System.Globalization
@using BloodAndBanners.UI.Services

@if (CatalogImages.Resolve(ImageBaseUrl, MapObjectArt.ImagePath(Tile.Hex.Object, Tile.Hex.Q, Tile.Hex.R)) is { } image)
{
    var kind = Tile.Hex.Object!;
    var category = MapObjectArt.CategoryOf(kind)!;
    var halfHeight = Math.Sqrt(3) / 2 * BoardLayout.Size;
    <g class="hex-map-object map-object-@kind category-@category" data-map-object="@kind" data-category="@category" aria-hidden="true">
        @if (category is "obstacle" or "landmark")
        {
            // Stands on the hex like a unit figure: its foot sits low on the face and a tall tree may rise into the hex behind.
            var (width, height) = kind switch
            {
                "tree" => (BoardLayout.Size * 1.5, halfHeight * 2),
                "rock" => (BoardLayout.Size * 1.3, halfHeight * 1.3),
                _ => (BoardLayout.Size * 1.4, halfHeight * 1.5),
            };
            var foot = Tile.Y + halfHeight * .55;
            <ellipse class="map-object-shadow" cx="@Num(Tile.X)" cy="@Num(foot - 3)" rx="@Num(BoardLayout.Size * .42)" ry="@Num(BoardLayout.Size * .14)" />
            <image href="@image" x="@Num(Tile.X - width / 2)" y="@Num(foot - height)" width="@Num(width)" height="@Num(height)"
                   preserveAspectRatio="xMidYMax meet" />
        }
        else if (category == "nexus")
        {
            // Set into the ground at the hex's centre with a soft glow; a unit standing here is drawn over it.
            var size = BoardLayout.Size * .75;
            <ellipse class="nexus-glow" cx="@Num(Tile.X)" cy="@Num(Tile.Y + halfHeight * .1)" rx="@Num(BoardLayout.Size * .55)" ry="@Num(BoardLayout.Size * .3)" />
            <image href="@image" x="@Num(Tile.X - size / 2)" y="@Num(Tile.Y + halfHeight * .2 - size)" width="@Num(size)" height="@Num(size)" />
        }
        else
        {
            // Lies just below the hex's centre on a small shadow; a unit standing here is drawn over it.
            var size = BoardLayout.Size * .8;
            <ellipse class="map-object-shadow" cx="@Num(Tile.X)" cy="@Num(Tile.Y + halfHeight * .42)" rx="@Num(size * .32)" ry="@Num(size * .1)" />
            <image href="@image" x="@Num(Tile.X - size / 2)" y="@Num(Tile.Y + halfHeight * .45 - size)" width="@Num(size)" height="@Num(size)" />
        }
    </g>
}

@code {
    [Parameter, EditorRequired] public HexTile Tile { get; set; } = default!;
    [Parameter] public string? ImageBaseUrl { get; set; }

    private static string Num(double value) => value.ToString("0.###", CultureInfo.InvariantCulture);
}
```

`HexMapObject.razor.css`:

```css
.hex-map-object { pointer-events: none; }
.map-object-shadow { fill: #000; fill-opacity: .3; }
.nexus-glow { fill: #9fd8ff; fill-opacity: .35; filter: blur(3px); }
.map-object-shrine image { image-rendering: pixelated; }
```

Replace `<HexObstacle` with `<HexMapObject` in `GameTable.razor:184` and `MapEditor.razor:140`. In `GameTable.razor` `HexLabel` (~line 1854) replace the obstacle line with:

```csharp
        (MapObjectArt.CategoryOf(hex.Object) switch
        {
            null => string.Empty,
            "obstacle" => $", blocked by {MapObjectArt.Display(hex.Object!).ToLowerInvariant()}",
            var category => $", {MapObjectArt.CategoryDisplay(category).ToLowerInvariant()}: {MapObjectArt.Display(hex.Object!).ToLowerInvariant()}",
        }) +
```

Remaining `ObstacleArt` references (in `MapEditor.razor`, `MapEditor.razor.cs`, `MapEditorDocument.cs`) are rewritten in Task 4; to keep this task building, replace them mechanically now: `ObstacleArt.Kinds` → `MapObjectArt.KindsIn("obstacle")`, `ObstacleArt.IsObstacle(x)` → `MapObjectArt.CategoryOf(x) == "obstacle"`, other members by the same name on `MapObjectArt`. Run `grep -rn "ObstacleArt\|HexObstacle" src tests` — nothing may remain.

- [ ] **Step 6: Run tests**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: all pass except the two editor tests carried over in `MapObjectTests` that expect shrines among obstacle-button picks / "Obstacle: Trees" — those get rewritten in Task 4. If they fail here, delete them now (Task 4 replaces them) and note it in the commit.

- [ ] **Step 7: Commit**

```bash
git add images/landmarks images/nexus images/items images/obstacles src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Draw landmarks, nexus points and items from a shared map object catalog"
```

---

### Task 4: Editor — one button per category, mutual exclusion

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/MapEditorDocument.cs:35-66`
- Modify: `src/BloodAndBanners.UI/Components/MapEditor.razor:69-90` (toolbar), `:102` (hex info)
- Modify: `src/BloodAndBanners.UI/Components/MapEditor.razor.cs:68-73`, `:93`, `:211`
- Modify: `src/BloodAndBanners.UI/Components/MapEditor.razor.css:75-76`
- Test: `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`

**Interfaces:**
- Consumes: `MapObjectArt` from Task 3.
- Produces: `MapEditorDocument.SetObject(int q, int r, string? kind)` (replaces `SetObstacle`; throws `ArgumentOutOfRangeException` for unknown kinds; null clears). Toolbar buttons `.object-button[data-category][data-object]`; `_tool` takes the category id while a category button is active.

- [ ] **Step 1: Write the failing tests** — in `MapObjectTests`, replace the two editor tests with:

```csharp
    [Fact]
    public void A_hex_holds_one_thing_and_each_placement_replaces_the_last()
    {
        var doc = new MapEditorDocument();
        doc.SetPlayerStart(0, 0, 1);
        doc.SetObject(0, 0, "tree");
        Assert.Equal(("tree", true, (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker, doc.SelectedHex.PlayerStart));

        doc.SetObject(0, 0, "fire-crystal");
        Assert.Equal(("fire-crystal", false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));
        doc.SetObject(0, 0, "rune-of-strength");
        Assert.Equal(("rune-of-strength", false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));
        doc.SetObject(0, 0, "speed-potion");
        Assert.Equal("speed-potion", doc.SelectedHex!.Object);

        doc.SetPlayerStart(0, 0, 2);
        Assert.Equal((null, false, (int?)2), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker, doc.SelectedHex.PlayerStart));
        doc.SetObject(0, 0, "health-potion");
        Assert.Equal(("health-potion", (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.PlayerStart));

        Assert.Throws<ArgumentOutOfRangeException>(() => doc.SetObject(0, 0, "statue"));
        doc.SetObject(0, 0, null);
        Assert.Equal((null, false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));

        doc.SetObject(1, 0, "rock");
        doc.SetObject(2, 0, "rune-of-magic");
        doc.Erase(1, 0);
        doc.Erase(2, 0);
        Assert.All(doc.Definition.Map.Hexes, h => Assert.Equal((null, false, (int?)null), (h.Object, h.SightBlocker, h.PlayerStart)));
    }

    [Theory]
    [InlineData("obstacle", new[] { "tree", "rock" }, "Obstacle: Tree")]
    [InlineData("landmark", new[] { "shrine", "fire-crystal", "forest-crystal", "ice-crystal" }, "Landmark: Shrine")]
    [InlineData("nexus", new[] { "rune-of-magic", "rune-of-strength", "rune-of-warding" }, "Nexus Point: Rune of Magic")]
    [InlineData("item", new[] { "power-potion", "speed-potion", "health-potion" }, "Item: Power Potion")]
    public void Each_category_button_cycles_its_kinds_and_places_them(string category, string[] kinds, string info)
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        var button = () => cut.Find($".object-button[data-category='{category}']");
        Assert.Equal("false", button().GetAttribute("aria-pressed"));

        button().Click();
        Assert.Equal(("true", kinds[0]), (button().GetAttribute("aria-pressed"), button().GetAttribute("data-object")));
        Assert.Equal($"{Images}{MapObjectArt.ImagePath(kinds[0])}", button().QuerySelector("img")!.GetAttribute("src"));
        cut.Find("[data-hex='0,0']").Click();
        var drawn = cut.Find("[data-hex='0,0'] [data-map-object]");
        Assert.Equal((kinds[0], category), (drawn.GetAttribute("data-map-object"), drawn.GetAttribute("data-category")));
        Assert.Contains(info, cut.Find(".hex-info").TextContent);

        foreach (var kind in kinds.Skip(1).Append(kinds[0]))
        {
            button().Click();
            Assert.Equal(kind, button().GetAttribute("data-object"));
        }
        Assert.Equal(4, cut.FindAll(".object-button").Count);
    }

    [Fact]
    public void Erase_removes_starts_and_every_kind_of_object()
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        var place = (string selector, string hex) => { cut.Find(selector).Click(); cut.Find($"[data-hex='{hex}']").Click(); };
        place(".object-button[data-category='obstacle']", "0,0");
        place(".object-button[data-category='landmark']", "1,0");
        place(".object-button[data-category='nexus']", "2,0");
        place(".object-button[data-category='item']", "3,0");
        place(".player-button", "4,0");
        place(".object-button[data-category='item']", "4,0");
        Assert.Empty(cut.FindAll(".start-label"));
        place(".player-button", "4,1");

        cut.Find("[data-action='erase']").Click();
        foreach (var hex in new[] { "0,0", "1,0", "2,0", "3,0", "4,0", "4,1" }) cut.Find($"[data-hex='{hex}']").Click();
        Assert.Empty(cut.FindAll("[data-map-object]"));
        Assert.Empty(cut.FindAll(".start-label"));
    }
```

Before writing these, check the editor's default map size (hexes `4,1` must exist; if not, use another existing hex such as `0,1`). Note the hex grid's second `place(".player-button", "4,1")` click cycles to player 2 because the player button was active — that's fine; the test only checks starts disappear.

- [ ] **Step 2: Run to verify failure**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter MapObjectTests`
Expected: build error — `SetObject` not defined.

- [ ] **Step 3: Implement `MapEditorDocument`** — replace `SetObstacle`, `Erase`, `WithoutObstacle` and the start-clearing call:

```csharp
        // A hex holds one thing, so a start clears any object from its hex.
        var hexes = Definition.Map.Hexes.Select(h => h.Q == q && h.R == r ? (player is null ? h : WithoutObject(h)) with { PlayerStart = player }
            : player is not null && h.PlayerStart == player ? h with { PlayerStart = null } : h).ToList();
```

```csharp
    /// <summary>
    /// Places an obstacle, landmark, nexus point or item, replacing whatever was on the hex (a start or another object);
    /// null clears it. Only obstacles carry the sight-blocker flag.
    /// </summary>
    public void SetObject(int q, int r, string? kind)
    {
        if (kind is not null && !MapObjectArt.IsKnown(kind)) throw new ArgumentOutOfRangeException(nameof(kind));
        Edit(q, r, h => kind is null ? WithoutObject(h) : h with { Object = kind, SightBlocker = MapObjectArt.BlocksSight(kind), PlayerStart = null });
    }
    /// <summary>Removes everything on the hex (start position and object), leaving its terrain and height.</summary>
    public void Erase(int q, int r) => Edit(q, r, h => WithoutObject(h) with { PlayerStart = null });
    private static ScenarioHex WithoutObject(ScenarioHex hex) =>
        hex.SightBlocker || hex.Object is not null ? hex with { Object = null, SightBlocker = false } : hex;
```

- [ ] **Step 4: Implement the toolbar** — in `MapEditor.razor.cs` replace `NextObstacle`/`SelectObstacle` and the `_obstacle` field:

```csharp
    private readonly Dictionary<string, string> _objectKinds = MapObjectArt.Categories.ToDictionary(category => category, category => MapObjectArt.KindsIn(category)[0]);
    private string NextKind(string category)
    {
        var kinds = MapObjectArt.KindsIn(category);
        return kinds[(kinds.ToList().IndexOf(_objectKinds[category]) + 1) % kinds.Count];
    }
    private void SelectObject(string category)
    {
        if (_tool == category) _objectKinds[category] = NextKind(category);
        _tool = category; _status = "";
    }
```

Remove `_obstacle = ObstacleArt.Kinds[0]` from the field line (~93). In `Edit` (~211) replace the obstacle branch:

```csharp
        else if (_objectKinds.TryGetValue(_tool, out var kind)) _document.SetObject(q, r, kind);
```

In `MapEditor.razor` replace the `addLabel` line and the obstacle button with:

```razor
                    @foreach (var category in MapObjectArt.Categories)
                    {
                        var kind = _objectKinds[category];
                        var label = $"Add {MapObjectArt.CategoryDisplay(category).ToLowerInvariant()}: {MapObjectArt.Display(kind)}. Click again to switch to {MapObjectArt.Display(NextKind(category))}.";
                        <button type="button" class="icon-button object-button" data-category="@category" data-object="@kind" title="@label" aria-label="@label" disabled="@_busy"
                                aria-pressed="@(_tool == category ? "true" : "false")" @onclick="() => SelectObject(category)">
                            @if (CatalogImages.Resolve(ImageBaseUrl, MapObjectArt.ImagePath(kind)) is { } preview)
                            {
                                <img src="@preview" alt="" draggable="false" />
                            }
                            else { <span aria-hidden="true">@MapObjectArt.Display(kind)[0]</span> }
                        </button>
                    }
```

Change the erase button's title/aria-label to `Erase start positions and map objects`. Replace the hex-info object fragment with:

```razor
@if (MapObjectArt.CategoryOf(selected.Object) is { } category) { <text> &middot; @MapObjectArt.CategoryDisplay(category): @MapObjectArt.Display(selected.Object!)</text> } else if (selected.SightBlocker) { <text> &middot; Blocked</text> }
```

In `MapEditor.razor.css` rename `.obstacle-button` → `.object-button` on both lines.

- [ ] **Step 5: Run all tests**

Run: `dotnet test tests/BloodAndBanners.UI.Tests` and `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: all pass. Then `grep -rn "SetObstacle\|obstacle-button\|data-obstacle" src tests` returns nothing.

- [ ] **Step 6: Check by eye** — follow the obstacles-slice recipe (publish Web to the scratchpad, `ImageBaseUrl` pointed at a local `python -m http.server` over the repo root, SPA fallback for `/editor`): place one of each kind in the editor at desktop and at a 390px-wide window (the toolbar should scroll sideways, not wrap or clip), and confirm potions read at 0.8× and units draw over runes/potions on the battlefield.

- [ ] **Step 7: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Give the map editor a button for each map object category"
```

---

## Deploy notes (not part of this plan's tasks)

Static images (`images/landmarks`, `images/nexus`, `images/items`) must be deployed before the game release, or the new objects render as nothing live. The APK needs rebuilding to pick up the client changes.
