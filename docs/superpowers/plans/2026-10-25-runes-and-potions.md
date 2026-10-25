# Runes and Potions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the three runes environmental stat auras with a per-placed radius, and make the three potions one-shot pickups that player units drink by stopping on them.

**Architecture:** PHP rules engine (`api/src/Rules/Vanilla`) gains `Runes` (stat sources, like `Crystals`) and `Potions` (drink-on-rest, hooked into `BattleRules` after each command settles). `Effects` gains a per-seat turn count. The map projection reads objects from the live state board and carries rune radius. The Blazor client (`src/BloodAndBanners.UI`) tints rune areas, cycles radius in the editor, shows turn-counted effects and logs drinking.

**Tech Stack:** PHP 8 (custom test runner `api/tests/run.php`), C# / Blazor, bUnit + xUnit.

**Spec:** `docs/superpowers/specs/2026-10-25-runes-and-potions-design.md`

## Global Constraints

- Kinds (exact ids): runes `rune-of-focus`, `rune-of-strength`, `rune-of-warding`; potions `power-potion`, `speed-potion`, `health-potion`. Display names: Rune of Focus, Rune of Strength, Rune of Warding, Power Potion, Speed Potion, Health Potion.
- Legacy id `rune-of-magic` is read as `rune-of-focus` (server board, projection, editor load/import). Nothing writes `rune-of-magic`.
- Rune radius: integer 1–4, absent/null = 1. Area = hexes at hex distance ≤ radius − 1. Elevation ignored.
- Rune bonuses (each +1, same kind counted once, different kinds add): Strength = Power on attacks with no `context['ability']`; Focus = Power on attacks with `context['ability']` set; Warding = Defense. Any unit in the area, Void Lord included.
- Potions: drunk by a unit at rest on the hex after a command/choice/turn change settles (`pending === null`); Void Lord units (`sides[...]['kind'] === 'void'`) never drink; drinking clears `board[hex]['object']`; event `potionDrunk {unitId, seatId, hex, kind}`. Power = +2 `power`, Speed = +2 `move`, both `Effects` named after the potion, expiring at the drinker's seat's `turnEnd` with `turns = 3`; Health sets `wounds` to 0.
- Turn counting: the turn it is drunk counts as the first of the 3.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_018GfkGVBukKcMgz2Sf5qwks
  ```
- PHP test command: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` (run from repo root). UI tests: `dotnet test tests/BloodAndBanners.UI.Tests`.
- Work on branch `runes-and-potions` (already created; spec committed there).

## Review Focus

1. A potion drunk during the drinker's own turn but just before the turn auto-passes (unit has nothing left to do) must still count that turn as turn 1 — drinking runs before `passTurn` in `BattleRules::apply`. Pinned in Task 5 (single-unit seat drink test).
2. A unit pushed onto a potion during the *enemy's* turn drinks it and gets three of its own turns (turn count only decrements at the drinker's seat's turn end). Pinned in Task 5 (Knockback test checks `turns === 3` after the enemy's turn).
3. Dragon Breath's power roll context lacked `ability`, so Rune of Strength would wrongly boost it — fixed and pinned in Task 3.
4. A seat wiped out with a turn-counted effect still pending must lose it (old code removed all its effects; decrement-only expiry would leave them) — `expire(..., all: true)` pinned in Task 4.
5. Placing a different rune on a rune hex, erasing, or placing a start must reset radius; cycling must wrap 4 → 1 — pinned in Task 2.

---

### Task 1: Rename Rune of Magic to Rune of Focus

**Files:**
- Modify: `api/src/Rules/Vanilla/Board.php`
- Modify: `src/BloodAndBanners.UI/Services/MapObjectArt.cs`
- Modify: `src/BloodAndBanners.UI/Services/MapEditorDocument.cs`
- Rename: `images/nexus/rune-of-magic-1.png` → `images/nexus/rune-of-focus-1.png`
- Test: `api/tests/CrystalTest.php`, `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`

**Interfaces:**
- Produces (PHP): `Board::objectKind(array $hex): ?string` — the hex's object with legacy ids renamed. `Board::NEXUS_POINTS = ['rune-of-focus', 'rune-of-strength', 'rune-of-warding']`. `Board::fromScenario` tiles gain `'radius' => int` (default 1) and use `objectKind`.
- Produces (C#): `MapObjectArt.Renamed(string? kind): string?`.

- [ ] **Step 1: Write the failing PHP test** — in `api/tests/CrystalTest.php`, replace `'rune-of-magic'` on the line `$state['board']['5,0']['object'] = 'rune-of-magic';` with `'rune-of-focus'`, and replace the `'board tiles keep their map object'` test with:

```php
        'board tiles keep their map object, rune radius and renamed legacy runes' => static function (): void {
            $board = \BloodAndBanners\Rules\Vanilla\Board::fromScenario(['map' => ['hexes' => [
                ['q' => 0, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0, 'object' => 'ice-crystal', 'sightBlocker' => false],
                ['q' => 1, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0, 'object' => 'rune-of-magic', 'sightBlocker' => false, 'radius' => 3],
            ]]]);
            expect_same('ice-crystal', $board['0,0']['object'], 'object kept');
            expect_same(1, $board['0,0']['radius'], 'radius defaults to 1');
            expect_same('rune-of-focus', $board['1,0']['object'], 'the old Rune of Magic is read as Rune of Focus');
            expect_same(3, $board['1,0']['radius'], 'radius kept');
        },
```

- [ ] **Step 2: Run it to see it fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: FAIL on "the old Rune of Magic is read as Rune of Focus".

- [ ] **Step 3: Implement in `Board.php`**

Replace the nexus constant and its comment:

```php
    /** Nexus points (runes) block nothing; a unit may stand on them. Their auras are in Runes. */
    public const NEXUS_POINTS = ['rune-of-focus', 'rune-of-strength', 'rune-of-warding'];
    /** Items (potions) block nothing; a player unit that comes to rest on one drinks it (Potions). */
    public const ITEMS = ['power-potion', 'speed-potion', 'health-potion'];
    /** Object ids from saved maps that have since been renamed. */
    private const RENAMED = ['rune-of-magic' => 'rune-of-focus'];
```

Add after `impassable()`:

```php
    /** The scenario hex's object, with renamed legacy ids read as their new names. */
    public static function objectKind(array $hex): ?string
    {
        $kind = isset($hex['object']) ? (string)$hex['object'] : null;
        return $kind === null ? null : (self::RENAMED[$kind] ?? $kind);
    }
```

In `fromScenario`, replace the `'object' => ...` line with:

```php
                'object' => self::objectKind($hex),
                'radius' => isset($hex['radius']) ? (int)$hex['radius'] : 1,
```

- [ ] **Step 4: Run PHP tests** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → no FAIL.

- [ ] **Step 5: Write the failing UI tests** — in `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`:
  - Change `Assert.Equal(["rune-of-magic", "rune-of-strength", "rune-of-warding"], MapObjectArt.KindsIn("nexus"));` to `["rune-of-focus", "rune-of-strength", "rune-of-warding"]`.
  - Change `MapObjectArt.Impassable("rune-of-magic")` to `MapObjectArt.Impassable("rune-of-focus")`.
  - Change `[InlineData("rune-of-magic", "nexus", "nexus point: rune of magic")]` to `[InlineData("rune-of-focus", "nexus", "nexus point: rune of focus")]`.
  - Change `doc.SetObject(2, 0, "rune-of-magic");` to `doc.SetObject(2, 0, "rune-of-focus");`.
  - Change `[InlineData("nexus", new[] { "rune-of-magic", "rune-of-strength", "rune-of-warding" }, "Nexus Point: Rune of Magic")]` to `[InlineData("nexus", new[] { "rune-of-focus", "rune-of-strength", "rune-of-warding" }, "Nexus Point: Rune of Focus")]`.
  - Add:

```csharp
    [Fact]
    public void Saved_maps_with_the_old_rune_of_magic_open_as_rune_of_focus()
    {
        Assert.Equal("rune-of-focus", MapObjectArt.Renamed("rune-of-magic"));
        Assert.Equal("tree", MapObjectArt.Renamed("tree"));
        Assert.Null(MapObjectArt.Renamed(null));

        var doc = new MapEditorDocument();
        var hexes = doc.Definition.Map.Hexes.Select((h, i) => i == 0 ? h with { Object = "rune-of-magic" } : h).ToList();
        var json = MapFileCodec.ExportCanonical(doc.Definition with { Map = doc.Definition.Map with { Hexes = hexes } });
        doc.Import(json);
        Assert.Equal("rune-of-focus", doc.Definition.Map.Hexes[0].Object);
    }
```

- [ ] **Step 6: Run to see failures** — `dotnet test tests/BloodAndBanners.UI.Tests --filter MapObjectTests` → build error (`Renamed` missing).

- [ ] **Step 7: Implement in C#**

`MapObjectArt.cs`: update the summary line "nexus points and items (block nothing; no rules effect yet)" to "nexus points (rune auras) and items (potions drunk on stopping there); both block nothing", replace `new("rune-of-magic", "Rune of Magic", "nexus", 1),` with `new("rune-of-focus", "Rune of Focus", "nexus", 1),`, and add:

```csharp
    /// <summary>Object ids from saved maps that have since been renamed, read as their new names.</summary>
    public static string? Renamed(string? kind) => kind == "rune-of-magic" ? "rune-of-focus" : kind;
```

`MapEditorDocument.cs`: in `Import`, replace `Definition = definition;` with `Definition = Renamed(definition);`. In `Load`, change `Definition = map.Definition; Selection = null; MarkSaved(map, ownerId);` to `Selection = null; MarkSaved(map, ownerId); Definition = Renamed(Definition);`. Add:

```csharp
    private static ScenarioDefinition Renamed(ScenarioDefinition definition) =>
        definition.Map.Hexes.All(h => MapObjectArt.Renamed(h.Object) == h.Object) ? definition
            : definition with { Map = definition.Map with { Hexes = definition.Map.Hexes.Select(h => h with { Object = MapObjectArt.Renamed(h.Object) }).ToList() } };
```

- [ ] **Step 8: Rename the image**

```bash
git mv images/nexus/rune-of-magic-1.png images/nexus/rune-of-focus-1.png
```

Then check for any other copy of the image path: `git grep -n "rune-of-magic" -- ':!docs'` — the only hits left must be `Board.php`'s `RENAMED`, `MapObjectArt.Renamed`, and the two tests above.

- [ ] **Step 9: Run tests** — `dotnet test tests/BloodAndBanners.UI.Tests --filter MapObjectTests` and the PHP command → all pass.

- [ ] **Step 10: Commit**

```bash
git add -A api/src/Rules/Vanilla/Board.php api/tests/CrystalTest.php src/BloodAndBanners.UI/Services tests/BloodAndBanners.UI.Tests/MapObjectTests.cs images/nexus
git commit -m "Rename the Rune of Magic to the Rune of Focus and keep rune radius on the board"
```

---

### Task 2: Rune radius in map data and the editor

**Files:**
- Modify: `src/BloodAndBanners.Contracts/ScenarioDefinition.cs` (`ScenarioHex`)
- Modify: `api/src/Scenario/MapValidator.php`
- Modify: `src/BloodAndBanners.UI/Services/MapFileCodec.cs` (`Validate`)
- Modify: `src/BloodAndBanners.UI/Services/MapEditorDocument.cs`
- Modify: `src/BloodAndBanners.UI/Components/MapEditor.razor`, `MapEditor.razor.cs`, `MapEditor.razor.css`
- Test: `api/tests/MapEditorTest.php`, `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`

**Interfaces:**
- Consumes: Task 1's renamed kinds.
- Produces (C#): `ScenarioHex.Radius` (`int?`, init, omitted from JSON when null). `MapObjectArt.MaxRuneRadius = 4`. `HexProjection.Radius` (`int?`, init) — added here so the editor can draw it; Task 6 fills it from the server.

- [ ] **Step 1: Failing PHP validator test** — append to the array returned by `map_editor_tests()` in `api/tests/MapEditorTest.php`:

```php
        'rune radius must be 1 to 4 when present' => static function (): void {
            $definition = editor_definition();
            $definition['map']['hexes'][0]['object'] = 'rune-of-strength';
            $definition['map']['hexes'][0]['radius'] = 4;
            expect_same(4, \BloodAndBanners\Scenario\MapValidator::validate($definition)['map']['hexes'][0]['radius'], 'radius 4 accepted');
            $definition['map']['hexes'][0]['radius'] = null;
            \BloodAndBanners\Scenario\MapValidator::validate($definition);
            foreach ([0, 5, 'big'] as $bad) {
                $definition['map']['hexes'][0]['radius'] = $bad;
                try {
                    \BloodAndBanners\Scenario\MapValidator::validate($definition);
                    throw new RuntimeException("radius {$bad} was accepted");
                } catch (\BloodAndBanners\Scenario\MapException) {
                }
            }
        },
```

(If `MapException` lives in a different namespace, check `api/src/Scenario/MapValidator.php`'s `use`/`throw` lines and use that class.)

- [ ] **Step 2: Run** — PHP command → FAIL "radius 0 was accepted".

- [ ] **Step 3: Implement** — in `MapValidator::validate`, after the `playerStart` check inside the hex loop:

```php
            if (($hex['radius'] ?? null) !== null) {
                self::integer($hex['radius'], 1, 4, 'Rune radius must be 1–4.');
            }
```

- [ ] **Step 4: Run** — PHP command → pass.

- [ ] **Step 5: Failing UI tests** — add to `MapObjectTests.cs`:

```csharp
    [Fact]
    public void Placing_the_same_rune_again_cycles_its_radius_and_anything_else_resets_it()
    {
        var doc = new MapEditorDocument();
        doc.SetObject(0, 0, "rune-of-strength");
        Assert.Null(doc.SelectedHex!.Radius);
        var seen = new List<int?>();
        for (var click = 0; click < 4; click++)
        {
            doc.SetObject(0, 0, "rune-of-strength");
            seen.Add(doc.SelectedHex!.Radius);
        }
        Assert.Equal([2, 3, 4, null], seen);

        doc.SetObject(0, 0, "rune-of-strength");
        doc.SetObject(0, 0, "rune-of-warding");
        Assert.Equal(("rune-of-warding", (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.Radius));
        doc.SetObject(0, 0, "rune-of-warding");
        doc.SetObject(0, 0, "speed-potion");
        Assert.Null(doc.SelectedHex!.Radius);
        doc.SetObject(0, 0, "speed-potion");
        Assert.Equal(("speed-potion", (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.Radius));

        doc.SetObject(1, 0, "rune-of-focus");
        doc.SetObject(1, 0, "rune-of-focus");
        doc.Erase(1, 0);
        Assert.Null(doc.SelectedHex!.Radius);
        doc.SetObject(1, 0, "rune-of-focus");
        doc.SetObject(1, 0, "rune-of-focus");
        doc.SetPlayerStart(1, 0, 1);
        Assert.Null(doc.SelectedHex!.Radius);
    }

    [Fact]
    public void Radius_round_trips_through_the_map_file_and_is_validated()
    {
        var doc = new MapEditorDocument();
        doc.SetObject(0, 0, "rune-of-focus");
        doc.SetObject(0, 0, "rune-of-focus");
        var json = MapFileCodec.ExportCanonical(doc.Definition);
        Assert.Contains("\"radius\"", json);
        Assert.Single(System.Text.RegularExpressions.Regex.Matches(json, "\"radius\""));
        var copy = new MapEditorDocument();
        copy.Import(json);
        Assert.Equal(2, copy.Definition.Map.Hexes.Single(h => h.Object == "rune-of-focus").Radius);

        var bad = doc.Definition with { Map = doc.Definition.Map with { Hexes = doc.Definition.Map.Hexes.Select((h, i) => i == 0 ? h with { Radius = 5 } : h).ToList() } };
        Assert.Throws<FormatException>(() => MapFileCodec.Validate(bad));
    }

    [Fact]
    public void The_editor_shows_rune_radius_and_tints_its_area()
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        cut.Find(".object-button[data-category='nexus']").Click();
        cut.Find("[data-hex='2,2']").Click();
        Assert.Empty(cut.FindAll("[data-rune-radius]"));
        Assert.Single(cut.FindAll("[data-rune-area='rune-of-focus']"));

        cut.Find("[data-hex='2,2']").Click();
        Assert.Equal("2", cut.Find("[data-hex='2,2'] [data-rune-radius]").TextContent);
        Assert.Equal(7, cut.FindAll("[data-rune-area='rune-of-focus']").Count);
        Assert.Contains("Radius: 2", cut.Find(".hex-info").TextContent);
    }
```

(The editor's default 15×10 map puts `2,2` in the interior, so radius 2 covers 7 hexes.)

- [ ] **Step 6: Run** — `dotnet test tests/BloodAndBanners.UI.Tests --filter MapObjectTests` → build error (`Radius` missing).

- [ ] **Step 7: Contracts** — in `ScenarioHex`'s body (beside `ExtensionData`):

```csharp
    /// <summary>A rune's reach, 1–4: 1 is its own hex, each step adds a ring. Null means 1; other objects have none.</summary>
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)] public int? Radius { get; init; }
```

In `GameProjection.cs` `HexProjection`, after `ControlledBy`:

```csharp
    /// <summary>The rune's reach on a nexus-point hex (1–4); null on every other hex.</summary>
    public int? Radius { get; init; }
```

- [ ] **Step 8: Codec and document**

`MapFileCodec.Validate`, after the tile-metadata check:

```csharp
            if (hex.Radius is < 1 or > MapObjectArt.MaxRuneRadius) throw new FormatException("Rune radius must be 1–4.");
```

`MapObjectArt.cs`: add `public const int MaxRuneRadius = 4;`

`MapEditorDocument.cs` — replace `SetObject` and `WithoutObject`:

```csharp
    /// <summary>
    /// Places an obstacle, landmark, nexus point or item, replacing whatever was on the hex (a start or another object);
    /// null clears it. Only obstacles carry the sight-blocker flag. Placing a rune on the same rune instead widens its
    /// radius, wrapping from 4 back to 1.
    /// </summary>
    public void SetObject(int q, int r, string? kind)
    {
        if (kind is not null && !MapObjectArt.IsKnown(kind)) throw new ArgumentOutOfRangeException(nameof(kind));
        Edit(q, r, h => kind is null ? WithoutObject(h)
            : h.Object == kind && MapObjectArt.CategoryOf(kind) == "nexus" ? h with { Radius = NextRadius(h.Radius) }
            : h with { Object = kind, SightBlocker = MapObjectArt.BlocksSight(kind), PlayerStart = null, Radius = null });
    }
    private static int? NextRadius(int? radius) => (radius ?? 1) % MapObjectArt.MaxRuneRadius + 1 is var next && next > 1 ? next : null;
```

```csharp
    private static ScenarioHex WithoutObject(ScenarioHex hex) =>
        hex.SightBlocker || hex.Object is not null || hex.Radius is not null ? hex with { Object = null, SightBlocker = false, Radius = null } : hex;
```

- [ ] **Step 9: Shared area calculation and tint component**

Create `src/BloodAndBanners.UI/Services/RuneAreas.cs`:

```csharp
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>Which rune kinds reach each hex: every hex within radius − 1 steps of a rune, elevation ignored. Matches Runes.php.</summary>
public static class RuneAreas
{
    public static IReadOnlyDictionary<string, IReadOnlyList<string>> Of(IEnumerable<HexProjection> hexes)
    {
        var all = hexes.ToList();
        var runes = all.Where(hex => MapObjectArt.CategoryOf(hex.Object) == "nexus").ToList();
        var areas = new Dictionary<string, IReadOnlyList<string>>();
        if (runes.Count == 0) return areas;
        foreach (var hex in all)
        {
            var key = $"{hex.Q},{hex.R}";
            var kinds = runes
                .Where(rune => HexMath.Distance($"{rune.Q},{rune.R}", key) <= Math.Clamp(rune.Radius ?? 1, 1, MapObjectArt.MaxRuneRadius) - 1)
                .Select(rune => rune.Object!)
                .Distinct()
                .Order(StringComparer.Ordinal)
                .ToList();
            if (kinds.Count > 0) areas[key] = kinds;
        }
        return areas;
    }
}
```

Create `src/BloodAndBanners.UI/Components/RuneAreaTint.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.UI.Services

@foreach (var kind in Kinds)
{
    <polygon class="rune-area @kind" data-rune-area="@kind" points="@Tile.TopPoints" aria-hidden="true" />
}

@code {
    [Parameter, EditorRequired] public HexTile Tile { get; set; } = default!;
    [Parameter] public IReadOnlyList<string> Kinds { get; set; } = [];
}
```

Create `src/BloodAndBanners.UI/Components/RuneAreaTint.razor.css`:

```css
.rune-area { stroke: none; fill-opacity: .2; pointer-events: none; }
.rune-area.rune-of-strength { fill: #e0644b; }
.rune-area.rune-of-focus { fill: #a678e0; }
.rune-area.rune-of-warding { fill: #5fa8e0; }
```

- [ ] **Step 10: Editor display**

`MapEditor.razor.cs` — in the `Layout` getter, add `Radius = h.Radius` to the `HexProjection` initializer, and compute areas alongside the layout:

```csharp
                _hexes = _document.Definition.Map.Hexes.Select(h => new HexProjection(h.Q, h.R, h.Biome, h.Terrain, h.Elevation, h.SightBlocker, null) { PlayerStart = h.PlayerStart, Object = h.Object, Radius = h.Radius }).ToList();
                _layout = BoardLayout.For(_hexes);
                _runeAreas = RuneAreas.Of(_hexes);
```

with a field `private IReadOnlyDictionary<string, IReadOnlyList<string>> _runeAreas = new Dictionary<string, IReadOnlyList<string>>();`.

`MapEditor.razor` — inside the per-hex `<g>`, right after `<polygon class="hex-outline" .../>`:

```razor
                            @if (_runeAreas.TryGetValue($"{hex.Q},{hex.R}", out var runeKinds))
                            {
                                <RuneAreaTint Tile="tile" Kinds="runeKinds" />
                            }
```

and right after `<HexMapObject Tile="tile" ImageBaseUrl="@ImageBaseUrl" />`:

```razor
                            @if (hex.Radius is > 1 and var radius)
                            {
                                <!text class="rune-radius" data-rune-radius="@radius" x="@Num(tile.X + BoardLayout.Size * .42)" y="@Num(tile.Y - BoardLayout.Size * .5)">@radius</!text>
                            }
```

In the `.hex-info` span, after `@MapObjectArt.Display(selected.Object!)` inside that `<text>` block, append `@if (category == "nexus") { <text> &middot; Radius: @(selected.Radius ?? 1)</text> }`.

`MapEditor.razor.css`: add

```css
.rune-radius { font-size: 12px; font-weight: 700; fill: #fff; stroke: #2a1f3d; stroke-width: 3px; paint-order: stroke; text-anchor: middle; dominant-baseline: middle; pointer-events: none; }
```

- [ ] **Step 11: Run** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "MapObjectTests|MapEditor|MapFileCodec"` → pass. Also `dotnet test tests/BloodAndBanners.Contracts.Tests` → pass.

- [ ] **Step 12: Commit**

```bash
git add -A api/src/Scenario/MapValidator.php api/tests/MapEditorTest.php src/BloodAndBanners.Contracts src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Let map makers set each rune's radius by clicking it again in the editor"
```

---

### Task 3: Rune auras in the rules

**Files:**
- Create: `api/src/Rules/Vanilla/Runes.php`
- Modify: `api/src/Rules/Vanilla/Stats.php`, `api/src/Rules/Vanilla/StepRunner.php:132`
- Create: `api/tests/RuneTest.php`; Modify: `api/tests/run.php`

**Interfaces:**
- Consumes: board tiles `object` and `radius` (Task 1).
- Produces: `Runes::KINDS` (id → display), `Runes::coveringKinds(array $state, string $hex): list<string>`, `Runes::statSources(array $state, string $unitId, string $stat, array $context): list<array{source: string, amount: int}>`.

- [ ] **Step 1: Failing tests** — create `api/tests/RuneTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Runes;
use BloodAndBanners\Rules\Vanilla\Stats;

/** A 9×9 open board; $runes maps hex => [kind, radius]. */
function rune_battle(array $units, array $runes): array
{
    $elevations = [];
    for ($q = -4; $q <= 4; $q++) {
        for ($r = -4; $r <= 4; $r++) {
            $elevations["{$q},{$r}"] = 0;
        }
    }
    $state = vanilla_battle(vanilla_board($elevations), $units);
    foreach ($runes as $hex => [$kind, $radius]) {
        $state['board'][$hex]['object'] = $kind;
        $state['board'][$hex]['radius'] = $radius;
    }
    return $state;
}

function rune_tests(): array
{
    return [
        'a rune reaches radius minus one hexes, whatever the height' => static function (): void {
            $state = rune_battle([], ['0,0' => ['rune-of-warding', 1]]);
            expect_same(['rune-of-warding'], Runes::coveringKinds($state, '0,0'), 'radius 1 is its own hex');
            expect_same([], Runes::coveringKinds($state, '1,0'), 'radius 1 does not reach a neighbour');
            foreach ([2 => '1,0', 3 => '2,0', 4 => '3,0'] as $radius => $edge) {
                $state['board']['0,0']['radius'] = $radius;
                $state['board'][$edge]['elevation'] = 5;
                expect_same(['rune-of-warding'], Runes::coveringKinds($state, $edge), "radius {$radius} reaches distance " . ($radius - 1));
                expect_same([], Runes::coveringKinds($state, '4,0'), "radius {$radius} stops short of distance 4");
            }
        },

        'any unit in the area gets the bonus, the void lord included' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')], ['0,0' => ['rune-of-warding', 2]]);
            expect_same(2, Stats::effective($state, 'a1', 'defense'), 'a1 on the rune: 1 + 1');
            expect_same(2, Stats::effective($state, 'b1', 'defense'), 'the enemy beside it too');
            $state['units']['b1']['hex'] = '3,0';
            expect_same(1, Stats::effective($state, 'b1', 'defense'), 'out of reach');

            $coop = coop_battle([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('m1', 'void-lord', '1,0')]);
            $coop['board']['0,0']['object'] = 'rune-of-warding';
            $coop['board']['0,0']['radius'] = 2;
            expect_same((int)$coop['units']['m1']['defense'] + 1, Stats::effective($coop, 'm1', 'defense'), 'a monster guarding the rune benefits');
        },

        'strength boosts regular attacks and focus boosts special attacks' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')],
                ['0,0' => ['rune-of-strength', 1], '-1,0' => ['rune-of-focus', 2]]);
            $regular = ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => null];
            $special = ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => 'Fireball'];
            expect_same([['source' => 'Rune of Strength', 'amount' => 1]], Stats::breakdown($state, 'a1', 'power', $regular)['modifiers'], 'strength on a regular attack');
            expect_same([['source' => 'Rune of Focus', 'amount' => 1]], Stats::breakdown($state, 'a1', 'power', $special)['modifiers'], 'focus on a special attack');
            expect_same(2, Stats::effective($state, 'a1', 'power'), 'no attack, no power bonus');
            expect_same(2, Stats::effective($state, 'b1', 'power', ['attackerId' => 'b1', 'defenderId' => 'a1', 'ability' => null]), 'b1 is outside both areas');
        },

        'the same kind counts once and different kinds combine' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '3,0')],
                ['1,0' => ['rune-of-strength', 2], '-1,0' => ['rune-of-strength', 3], '0,1' => ['rune-of-warding', 2]]);
            expect_same(['rune-of-strength', 'rune-of-warding'], Runes::coveringKinds($state, '0,0'), 'each kind once');
            expect_same(3, Stats::effective($state, 'a1', 'power', ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => null]), 'two strength runes still +1');
            expect_same(2, Stats::effective($state, 'a1', 'defense'), 'warding adds alongside');
        },

        'dragon breath counts as a special attack for the runes' => static function (): void {
            $state = rune_battle([ability_unit('a1', 'seat-a', '0,0', ['Dragon Breath']), vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 20])],
                ['0,0' => ['rune-of-strength', 1]]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Dragon Breath', 'targetUnitIds' => ['b1']], array_fill(0, 20, 6));
            $attacks = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'));
            expect_true($attacks !== [], 'the breath was resolved');
            foreach ($attacks as $attack) {
                $sources = array_column($attack['payload']['attackBreakdown']['modifiers'] ?? [], 'source');
                expect_true(!in_array('Rune of Strength', $sources, true), 'strength does not boost dragon breath');
            }
        },
    ];
}
```

Before relying on the last test, open `api/tests/DragonBreathTest.php` and copy its exact setup for a Dragon Breath `specialAttack` (unit helper, payload keys, roll list, and the event/payload field that carries the power breakdown). Adjust the last test to that setup; its assertion must stay "no `Rune of Strength` source on any Dragon Breath power breakdown, and the rolled power equals the unbuffed value".

Register in `api/tests/run.php`: add `require __DIR__ . '/RuneTest.php';` after the `CrystalTest.php` require, and add `rune_tests(),` after `crystal_tests(),` in the `array_merge`.

- [ ] **Step 2: Run** — PHP command → FAIL (class `Runes` not found).

- [ ] **Step 3: Implement** — create `api/src/Rules/Vanilla/Runes.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Nexus-point runes. Every unit standing within a rune's reach (radius 1 = its own hex, each step adds a ring,
 * height ignored), on any side, gets its bonus. A kind counts once however many of its runes reach the unit.
 */
final class Runes
{
    public const KINDS = ['rune-of-strength' => 'Rune of Strength', 'rune-of-focus' => 'Rune of Focus', 'rune-of-warding' => 'Rune of Warding'];
    public const MAX_RADIUS = 4;

    /**
     * @return list<array{source: string, amount: int}>
     */
    public static function statSources(array $state, string $unitId, string $stat, array $context): array
    {
        $hex = $state['units'][$unitId]['hex'] ?? null;
        if ($hex === null || !in_array($stat, ['power', 'defense'], true)) {
            return [];
        }
        $sources = [];
        foreach (self::coveringKinds($state, $hex) as $kind) {
            if (self::applies($kind, $unitId, $stat, $context)) {
                $sources[] = ['source' => self::KINDS[$kind], 'amount' => 1];
            }
        }
        return $sources;
    }

    /** The rune kinds whose reach includes the hex, each once, in KINDS order. */
    public static function coveringKinds(array $state, string $hex): array
    {
        $found = [];
        foreach ($state['board'] as $key => $tile) {
            $kind = $tile['object'] ?? null;
            if ($kind !== null && isset(self::KINDS[$kind]) && Hex::distance((string)$key, $hex) <= self::radius($tile) - 1) {
                $found[$kind] = true;
            }
        }
        return array_values(array_filter(array_keys(self::KINDS), static fn(string $kind): bool => isset($found[$kind])));
    }

    private static function radius(array $tile): int
    {
        return max(1, min(self::MAX_RADIUS, (int)($tile['radius'] ?? 1)));
    }

    private static function applies(string $kind, string $unitId, string $stat, array $context): bool
    {
        $attacking = $stat === 'power' && ($context['attackerId'] ?? null) === $unitId;
        $special = ($context['ability'] ?? null) !== null;
        return match ($kind) {
            'rune-of-strength' => $attacking && !$special,
            'rune-of-focus' => $attacking && $special,
            'rune-of-warding' => $stat === 'defense',
        };
    }
}
```

In `Stats::breakdown`, after the `Crystals::statSources` loop:

```php
        foreach (Runes::statSources($state, $unitId, $stat, $context) as $source) {
            $modifiers[] = $source;
        }
```

In `StepRunner.php` (the Dragon Breath roll, currently line 132), change the context to include the ability:

```php
        $context = ['attackerId' => $step['attackerId'], 'defenderId' => $step['targetId'], 'ability' => $step['ability'] ?? null, 'damageType' => 'fire', 'originHex' => $source];
```

- [ ] **Step 4: Run** — PHP command → no FAIL.

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Runes.php api/src/Rules/Vanilla/Stats.php api/src/Rules/Vanilla/StepRunner.php api/tests/RuneTest.php api/tests/run.php
git commit -m "Give units standing in a rune's reach its bonus"
```

---

### Task 4: Effects that last a number of turns

**Files:**
- Modify: `api/src/Rules/Vanilla/Effects.php`, `api/src/Rules/Vanilla/Wounds.php:68-72`, `api/src/Game/GameService.php` (unit projection `effects`)
- Modify: `src/BloodAndBanners.Contracts/MatchProjection.cs` (`EffectProjection`), `src/BloodAndBanners.UI/Components/GameTable.razor` (`EffectSources`)
- Test: `api/tests/PotionTest.php` (new, started here), `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`

**Interfaces:**
- Produces (PHP): `Effects::add(array $state, string $name, string $sourceUnitId, string $targetUnitId, string $stat, int $amount, string $seatId, string $at, array &$events, ?int $turns = null): array` — with `$turns`, the stored effect carries `'turns' => $turns`. `Effects::expire(array $state, string $seatId, string $at, array &$events, bool $all = false): array` — an effect with `turns > 1` is decremented and kept unless `$all`.
- Produces (projection): each unit effect `{name, stat, amount, expires: 'endOfTurn'|'nextTurn'|'turns', turnsLeft: ?int}`.
- Produces (C#): `EffectProjection.TurnsLeft` (`int?`, init).

- [ ] **Step 1: Failing PHP test** — create `api/tests/PotionTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Effects;

function potion_tests(): array
{
    return [
        'a turn-counted effect lasts that many of its seat\'s turn ends' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            $events = [];
            $state = Effects::add($state, 'Power Potion', 'a1', 'a1', 'power', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, 3);
            expect_same(3, $state['effects'][0]['turns'], 'starts at 3');
            $state = Effects::expire($state, 'seat-b', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(3, $state['effects'][0]['turns'], 'another seat\'s turn end does not count');
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(1, $state['effects'][0]['turns'], 'two turn ends counted');
            $events = [];
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same([], $state['effects'], 'gone after the third');
            expect_same(['effectExpired'], array_column($events, 'kind'), 'expiry reported once, at the end');

            $state = Effects::add($state, 'Speed Potion', 'a1', 'a1', 'move', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, 3);
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, true);
            expect_same([], $state['effects'], 'expiring everything ignores the count');

            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_true(!array_key_exists('turns', $state['effects'][0]), 'plain effects carry no count');
        },
    ];
}
```

Register in `api/tests/run.php`: `require __DIR__ . '/PotionTest.php';` after `RuneTest.php`, and `potion_tests(),` after `rune_tests(),`.

- [ ] **Step 2: Run** — PHP command → FAIL (unknown argument / missing `turns`).

- [ ] **Step 3: Implement `Effects.php`** — update the class comment to: `/** Timed stat changes. An effect expires when the named seat's turn starts (UNTIL_NEXT_TURN) or ends (UNTIL_END_OF_TURN); one with a turn count lasts that many of those turn ends. */`. Add `?int $turns = null` as the last parameter of `add`, and build the effect as:

```php
        $effect = [
            'name' => $name,
            'sourceUnitId' => $sourceUnitId,
            'targetUnitId' => $targetUnitId,
            'stat' => $stat,
            'amount' => $amount,
            'expires' => ['seatId' => $seatId, 'at' => $at],
        ] + ($turns === null ? [] : ['turns' => $turns]);
```

Replace `expire` with:

```php
    /** Ends the seat's effects due at this point; a counted effect instead loses a turn until its last. $all ends them regardless. */
    public static function expire(array $state, string $seatId, string $at, array &$events, bool $all = false): array
    {
        $kept = [];
        foreach ($state['effects'] as $effect) {
            if ($effect['expires']['seatId'] === $seatId && $effect['expires']['at'] === $at) {
                if (!$all && (int)($effect['turns'] ?? 1) > 1) {
                    $effect['turns']--;
                    $kept[] = $effect;
                    continue;
                }
                $events[] = MatchEngine::event('effectExpired', [], ['name' => $effect['name'], 'targetUnitId' => $effect['targetUnitId']]);
                continue;
            }
            $kept[] = $effect;
        }
        $state['effects'] = $kept;
        return $state;
    }
```

In `Wounds.php`, the wiped-out-seat block: pass `true` as the fifth argument to both `Effects::expire` calls.

- [ ] **Step 4: Projection** — in `GameService::unitProjection`, replace the `'effects' => array_map(...)` mapper body with:

```php
            'effects' => array_map(static fn(array $effect): array => [
                'name' => $effect['name'],
                'stat' => $effect['stat'],
                'amount' => $effect['amount'],
                'expires' => isset($effect['turns']) ? 'turns' : ($effect['expires']['at'] === Effects::UNTIL_END_OF_TURN ? 'endOfTurn' : 'nextTurn'),
                'turnsLeft' => isset($effect['turns']) ? (int)$effect['turns'] : null,
            ], Effects::forUnit($state, $id)),
```

- [ ] **Step 5: Run** — PHP command → no FAIL.

- [ ] **Step 6: Failing UI test** — in `GameTableTests.cs`, in the test containing `Assert.Contains("Shield Wall +1 until your next turn", ...)`, change the effects list to:

```csharp
                    Effects = [
                        new EffectProjection("Shield Wall", "defense", 1, "nextTurn"),
                        new EffectProjection("Iron Draught", "defense", 1, "turns") { TurnsLeft = 2 },
                        new EffectProjection("Last Sip", "defense", 1, "turns") { TurnsLeft = 1 },
                    ],
```

set `EffectiveDefense = 6` and the expected text `"2 → 6"`, and add:

```csharp
        Assert.Contains("Iron Draught +1 for 2 more turns", defense.GetAttribute("title"));
        Assert.Contains("Last Sip +1 for 1 more turn", defense.GetAttribute("title"));
```

- [ ] **Step 7: Run** — `dotnet test tests/BloodAndBanners.UI.Tests --filter GameTableTests` → build error (`TurnsLeft`).

- [ ] **Step 8: Implement C#** — `MatchProjection.cs`:

```csharp
public sealed record EffectProjection(string Name, string Stat, int Amount, string Expires)
{
    /// <summary>For an effect that lasts a number of its owner's turns ("turns"), how many are left, this one included.</summary>
    public int? TurnsLeft { get; init; }
}
```

`GameTable.razor` `EffectSources`: replace the effect `Select` with `.Select(effect => $"{effect.Name} {(effect.Amount > 0 ? "+" : "")}{effect.Amount} {EffectExpiry(effect)}")` and add:

```csharp
    private static string EffectExpiry(EffectProjection effect) => effect.Expires switch
    {
        "endOfTurn" => "until end of turn",
        "turns" => effect.TurnsLeft == 1 ? "for 1 more turn" : $"for {effect.TurnsLeft} more turns",
        _ => "until your next turn",
    };
```

- [ ] **Step 9: Run** — `dotnet test tests/BloodAndBanners.UI.Tests --filter GameTableTests` → pass; PHP command → pass.

- [ ] **Step 10: Commit**

```bash
git add api/src/Rules/Vanilla/Effects.php api/src/Rules/Vanilla/Wounds.php api/src/Game/GameService.php api/tests/PotionTest.php api/tests/run.php src/BloodAndBanners.Contracts/MatchProjection.cs src/BloodAndBanners.UI/Components/GameTable.razor tests/BloodAndBanners.UI.Tests/GameTableTests.cs
git commit -m "Let timed effects last a number of their owner's turns"
```

---

### Task 5: Drinking potions

**Files:**
- Create: `api/src/Rules/Vanilla/Potions.php`
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (`apply`, `resolveChoice`, `passTurn`)
- Test: `api/tests/PotionTest.php`

**Interfaces:**
- Consumes: `Effects::add(..., ?int $turns)` (Task 4); `Sides::ofUnit`.
- Produces: `Potions::KINDS` (id → display), `Potions::drinkAll(array $state, array &$events): array`; event `potionDrunk` with payload `{unitId, seatId, hex, kind}` and hexes `[hex]`.

- [ ] **Step 1: Failing tests** — append to the array in `potion_tests()` (add `use BloodAndBanners\Rules\Vanilla\Stats;` at the top of the file):

```php
        'ending a move on a potion drinks it and removes it' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'power-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            $drunk = array_values(array_filter($moved['events'], static fn(array $e): bool => $e['kind'] === 'potionDrunk'));
            expect_same([['unitId' => 'a1', 'seatId' => 'seat-a', 'hex' => '2,0', 'kind' => 'power-potion']], array_column($drunk, 'payload'), 'one drink reported');
            expect_same(null, $moved['state']['board']['2,0']['object'], 'the potion is gone');
            expect_same(4, Stats::effective($moved['state'], 'a1', 'power'), '+2 power');
            expect_same(['seatId' => 'seat-a', 'at' => Effects::UNTIL_END_OF_TURN], $moved['state']['effects'][0]['expires'], 'counted on the drinker\'s turn ends');
            expect_same(3, $moved['state']['effects'][0]['turns'], 'this turn is the first of three');
        },

        'passing over a potion mid-move does not drink it' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['1,0']['object'] = 'speed-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('speed-potion', $moved['state']['board']['1,0']['object'], 'still there');
            expect_same([], $moved['state']['effects'], 'no effect');
        },

        'a drink just before the turn passes on its own still counts that turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['2,0']['object'] = 'speed-potion';
            // a1 spends its whole move and has no one in reach, so seat-a's turn passes on the move command itself.
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('seat-b', $moved['state']['turn']['activeSeatId'], 'seat-a had nothing left and passed');
            expect_same(2, $moved['state']['effects'][0]['turns'], 'the drinking turn was counted');
        },

        'speed and power last through the end of the drinker\'s third turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'speed-potion';
            $s = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0'])['state'];
            expect_same(4, Stats::effective($s, 'a1', 'move'), '+2 move');
            $ends = [];
            for ($i = 0; $i < 5; $i++) {
                $s = vanilla_command($s, $s['turn']['activeSeatId'], 'endTurn')['state'];
                $ends[] = Stats::effective($s, 'a1', 'move');
            }
            expect_same([4, 4, 4, 4, 2], $ends, 'a-end, b-end, a-end, b-end: still on; third a-end: gone');
        },

        'health heals every wound, even when wasted on an unhurt unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['hp' => 5, 'wounds' => 4]), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'health-potion';
            $state['board']['0,2'] = $state['board']['0,0'];
            $state['board']['0,2']['object'] = 'health-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same(0, $moved['state']['units']['a1']['wounds'], 'fully healed');
            $wasted = vanilla_command($moved['state'], 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '0,2']);
            expect_same(null, $wasted['state']['board']['0,2']['object'], 'an unhurt unit still drinks it');
        },

        'a unit pushed onto a potion drinks it, and on the enemy turn it still gets three of its own' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $state['board']['2,0']['object'] = 'power-potion';
            $pushed = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 5]);
            expect_same('2,0', $pushed['state']['units']['b1']['hex'], 'pushed onto the potion');
            expect_same(null, $pushed['state']['board']['2,0']['object'], 'and drank it');
            $effect = array_values(array_filter($pushed['state']['effects'], static fn(array $e): bool => $e['targetUnitId'] === 'b1'))[0];
            expect_same(['seat-b', 3], [$effect['expires']['seatId'], $effect['turns']], 'three of seat-b\'s turns, even after seat-a\'s turn ends');
        },

        'a teleported unit drinks the potion it lands on' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '4,0' => 0, '5,0' => 0, '6,0' => 0]), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0', ['wounds' => 2]),
                vanilla_unit('b1', 'seat-b', '6,0'),
            ]);
            $state['board']['3,0']['object'] = 'health-potion';
            $blink = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => ['a2'], 'toHex' => '3,0']);
            expect_same('3,0', $blink['state']['units']['a2']['hex'], 'landed');
            expect_same(0, $blink['state']['units']['a2']['wounds'], 'drank on landing');
        },

        'the void lord\'s monsters never drink potions' => static function (): void {
            $state = coop_battle([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('m1', 'void-lord', '4,0', ['move' => 2, 'wounds' => 1, 'hp' => 3])], 'void-lord');
            $state['board']['2,0']['object'] = 'health-potion';
            $moved = vanilla_command($state, 'void-lord', 'moveUnit', ['unitId' => 'm1', 'to' => '2,0']);
            expect_same('2,0', $moved['state']['units']['m1']['hex'], 'the monster stands on it');
            expect_same('health-potion', $moved['state']['board']['2,0']['object'], 'the potion stays for the players');
            expect_same(1, $moved['state']['units']['m1']['wounds'], 'no healing');
        },
```

Before running, check `api/tests/CoopTestSupport.php`'s `coop_battle` board: if it lacks hexes `2,0`/`4,0`, or the Void Lord seat id is not `'void-lord'`, adapt the last test's coordinates/seat to that helper (as `CrystalTest`'s co-op test does). Tests that move two seat-a units rely on the second unit keeping seat-a's turn alive; tests with one seat-a unit expect the turn to auto-pass after it is spent.

- [ ] **Step 2: Run** — PHP command → FAIL (no `potionDrunk`).

- [ ] **Step 3: Implement** — create `api/src/Rules/Vanilla/Potions.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Potions on the map. A player unit that comes to rest on one drinks it at once and it is gone. The Void Lord
 * places them as rewards his monsters guard, so his units never drink.
 */
final class Potions
{
    public const KINDS = ['power-potion' => 'Power Potion', 'speed-potion' => 'Speed Potion', 'health-potion' => 'Health Potion'];
    private const TURNS = 3;
    private const BONUS = 2;

    /** Every player unit now standing on a potion drinks it. Call once the board has settled: nothing pending. */
    public static function drinkAll(array $state, array &$events): array
    {
        if ($state['phase'] !== 'active' || $state['pending'] !== null) {
            return $state;
        }
        $unitIds = array_map('strval', array_keys($state['units']));
        sort($unitIds, SORT_STRING);
        foreach ($unitIds as $unitId) {
            $hex = $state['units'][$unitId]['hex'];
            $kind = $hex === null ? null : ($state['board'][$hex]['object'] ?? null);
            if ($kind === null || !isset(self::KINDS[$kind])
                || ($state['sides'][Sides::ofUnit($state, $unitId)]['kind'] ?? null) === 'void') {
                continue;
            }
            $state = self::drink($state, $unitId, $hex, $kind, $events);
        }
        return $state;
    }

    private static function drink(array $state, string $unitId, string $hex, string $kind, array &$events): array
    {
        $seatId = (string)$state['units'][$unitId]['seatId'];
        $state['board'][$hex]['object'] = null;
        $events[] = MatchEngine::event('potionDrunk', [$hex], ['unitId' => $unitId, 'seatId' => $seatId, 'hex' => $hex, 'kind' => $kind]);
        if ($kind === 'health-potion') {
            $state['units'][$unitId]['wounds'] = 0;
            return $state;
        }
        $stat = $kind === 'power-potion' ? 'power' : 'move';
        return Effects::add($state, self::KINDS[$kind], $unitId, $unitId, $stat, self::BONUS, $seatId, Effects::UNTIL_END_OF_TURN, $events, self::TURNS);
    }
}
```

`BattleRules.php`:
1. In `apply`, immediately before `if ($state['phase'] === 'active') { $state = Charm::cleanup(...` add:

```php
        // Units come to rest only once the command has resolved, so a potion passed over mid-move stays put.
        $state = Potions::drinkAll($state, $events);
```

2. In `resolveChoice`'s `iceCollision` branch, immediately before `$active = $state['turn']['activeSeatId'];` add `$state = Potions::drinkAll($state, $events);`. In the general branch, immediately after `$state = (new StepRunner($random, $this->now))->resolve($state, $option, $events);` add the same line.

3. In `passTurn`, immediately before `return [$state, $events];` at the end (after `UnitConditions::atTurnStart`), add `$state = Potions::drinkAll($state, $events);` so slides at a turn's end or start can land a unit on a potion.

- [ ] **Step 4: Run** — PHP command → no FAIL.

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Potions.php api/src/Rules/Vanilla/BattleRules.php api/tests/PotionTest.php
git commit -m "Let player units drink the potions they stop on"
```

---

### Task 6: Project the live board and rune radius

**Files:**
- Modify: `api/src/Rules/Vanilla/Board.php`, `api/src/Game/GameService.php` (`boardProjection`, `scenarioBoard`, `mapProjection`)
- Test: `api/tests/PotionTest.php`, `api/tests/VanillaMatchServiceTest.php:172`

**Interfaces:**
- Consumes: `Board::objectKind` (Task 1), `HexProjection.Radius` (Task 2).
- Produces: `Board::projectedObject(array $hex, ?array $board): ?string`; projection hexes gain `'radius' => ?int`.

- [ ] **Step 1: Failing tests** — append to `potion_tests()`:

```php
        'the projected board shows the live object, so a drunk potion disappears' => static function (): void {
            $hex = ['q' => 2, 'r' => 0, 'object' => 'speed-potion'];
            expect_same('speed-potion', \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, null), 'no game yet: the map\'s object');
            expect_same(null, \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, ['2,0' => ['object' => null]]), 'drunk in play');
            expect_same('rune-of-focus', \BloodAndBanners\Rules\Vanilla\Board::projectedObject(['q' => 0, 'r' => 0, 'object' => 'rune-of-magic'], null), 'renamed');
        },
```

In `VanillaMatchServiceTest.php`, after the `controlledBy` assertion line (172), add:

```php
            expect_true(array_key_exists('radius', $occupied[0]) && $occupied[0]['radius'] === null, 'hexes project rune radius, null off a rune');
```

- [ ] **Step 2: Run** — PHP command → FAIL.

- [ ] **Step 3: Implement** — `Board.php`, after `objectKind`:

```php
    /** What a projected hex shows: the live board's object once a game is in play (potions get drunk), else the map's. */
    public static function projectedObject(array $hex, ?array $board): ?string
    {
        $key = Hex::key((int)$hex['q'], (int)$hex['r']);
        return $board !== null && isset($board[$key]) ? ($board[$key]['object'] ?? null) : self::objectKind($hex);
    }
```

`GameService.php`: change `mapProjection`'s signature to `private static function mapProjection(array $definition, callable $occupant, array $crystals = [], ?array $board = null): array`; in the hex mapper replace the `'object'` line with:

```php
                'object' => Board::projectedObject($hex, $board),
```

and after `'controlledBy'` add:

```php
                'radius' => in_array(Board::objectKind($hex), Board::NEXUS_POINTS, true) ? (int)($hex['radius'] ?? 1) : null,
```

In `boardProjection`, pass `$state['board'] ?? null` as the fourth argument to `mapProjection`.

- [ ] **Step 4: Run** — PHP command → no FAIL. `dotnet test tests/BloodAndBanners.Contracts.Tests` → pass.

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Board.php api/src/Game/GameService.php api/tests/PotionTest.php api/tests/VanillaMatchServiceTest.php
git commit -m "Show drunk potions gone and each rune's radius on the projected board"
```

---

### Task 7: Rune areas and potion drinking in the game client

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor` (`BoardIndex`, hex loop)
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`
- Test: `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`, `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`

**Interfaces:**
- Consumes: `RuneAreas.Of`, `RuneAreaTint` (Task 2), `HexProjection.Radius`.

- [ ] **Step 1: Failing tests** — `MapObjectTests.cs`:

```csharp
    [Fact]
    public void Rune_areas_are_tinted_on_the_battlefield()
    {
        var fixture = BattleFixtures.Projection();
        var rune = fixture.Board.Hexes.First(hex => hex.OccupantId is null && hex.Q == 2 && hex.R == 2);
        var hexes = fixture.Board.Hexes.Select(hex => hex == rune ? hex with { Object = "rune-of-warding", Radius = 2 } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var tinted = cut.FindAll("[data-rune-area='rune-of-warding']");
        var expected = hexes.Count(hex => HexMath.Distance($"{hex.Q},{hex.R}", "2,2") <= 1);
        Assert.Equal(expected, tinted.Count);
        var markup = cut.Markup;
        Assert.True(markup.IndexOf("data-rune-area", StringComparison.Ordinal) < markup.IndexOf("unit-token", StringComparison.Ordinal), "the tint is beneath the units");
    }
```

(If `BattleFixtures.Projection()` has no empty hex `2,2`, pick any unoccupied hex from it and use its key in both places.)

`CombatLogFormatterTests.cs`:

```csharp
    [Fact]
    public void Drinking_a_potion_names_the_unit_and_the_potion()
    {
        var payload = JsonSerializer.Serialize(new { unitId = "s1-u3", seatId = "seat-red", hex = "4,4", kind = "speed-potion" });
        var line = CombatLogFormatter.Describe(Event("potionDrunk", payload), BattleFixtures.Projection());
        Assert.EndsWith("drinks a Speed Potion.", line);
    }
```

- [ ] **Step 2: Run** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "MapObjectTests|CombatLogFormatterTests"` → FAIL.

- [ ] **Step 3: Implement**

`GameTable.razor` `BoardIndex`: add a property

```csharp
        public required IReadOnlyDictionary<string, IReadOnlyList<string>> RuneAreaByKey { get; init; }
```

and in `For`'s returned initializer: `RuneAreaByKey = RuneAreas.Of(projection.Board.Hexes),`.

In the hex loop, immediately before `<HexMapObject Tile="tile" ImageBaseUrl="@ImageBaseUrl" />` (after the crystal ring block):

```razor
                    @if (boardIndex.RuneAreaByKey.TryGetValue(key, out var runeKinds))
                    {
                        <RuneAreaTint Tile="tile" Kinds="runeKinds" />
                    }
```

`CombatLogFormatter.cs`, in the `switch` after `"crystalClaimed"`:

```csharp
            "potionDrunk" => $"{Unit(projection, Text(payload, "unitId"))} drinks a {MapObjectArt.Display(Text(payload, "kind"))}.",
```

- [ ] **Step 4: Run** — `dotnet test tests/BloodAndBanners.UI.Tests` → all pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Tint rune areas on the battlefield and log potions drunk"
```

---

### Task 8: Rule book

**Files:**
- Modify: `rulebook.html:1504-1505`

- [ ] **Step 1: Replace the two bullets** with:

```html
                        <li><strong>Nexus Points</strong> (Rune of Strength, Rune of Focus, Rune of Warding) block nothing; a unit may stand on one. Each rune has a radius of 1–4 set by the map maker: radius 1 reaches only its own hex, and each step more adds a ring of hexes around it (height does not matter). Every unit in a rune's reach, on any side, Void Lord monsters included, gets its bonus while it stays there: <em>Rune of Strength</em> +1 Power on regular attacks, <em>Rune of Focus</em> +1 Power on special-ability attacks (spells), <em>Rune of Warding</em> +1 Defense. Two runes of the same kind give the bonus once; different kinds add up. (The Rune Smith's own "Rune of Warding" ability is unrelated.)</li>
                        <li><strong>Items</strong> (Power, Speed and Health Potions) block nothing. A player's unit that comes to rest on a potion — by its own move, or pushed, teleported or sliding there — drinks it and the potion is gone for good; passing over one mid-move does not. <em>Power Potion</em>: +2 Power and <em>Speed Potion</em>: +2 Move, each for three of the drinker's turns, counting the turn it is drunk. <em>Health Potion</em>: heals every wound. The Void Lord's monsters never drink potions; they guard them.</li>
```

- [ ] **Step 2: Check** — `grep -n "not yet in play" rulebook.html` returns nothing for runes/potions.

- [ ] **Step 3: Commit**

```bash
git add rulebook.html
git commit -m "Describe rune auras and potions in the rule book"
```

---

### Task 9: Full verification

- [ ] **Step 1:** `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → no FAIL.
- [ ] **Step 2:** `dotnet test tests/BloodAndBanners.UI.Tests` and `dotnet test tests/BloodAndBanners.Contracts.Tests` → all pass (the AttackPopup queued test is known-flaky on main; re-run once if only it fails).
- [ ] **Step 3:** `git grep -n "rune-of-magic" -- ':!docs'` → only `Board.php` `RENAMED`, `MapObjectArt.Renamed`, and the rename tests.
- [ ] **Step 4:** `git status` clean; `git log --oneline main..` shows the spec plus Tasks 1–8 commits.
