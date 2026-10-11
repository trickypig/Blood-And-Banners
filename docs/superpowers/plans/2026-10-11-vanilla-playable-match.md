# Vanilla Playable Match Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two people in two browsers can play a complete, simplified Vanilla PvP match: d20 initiative, open draft, automatic placement, then move-and-attack turns with custom dice until one side is annihilated.

**Architecture:** A pure PHP rules engine (`api/src/Rules/Vanilla/`) takes `(state, seat, command, random)` and returns `(state, events)`. `GameService` persists that state in a new `game_states` table, inside the existing transactional command pipeline, and builds each viewer's projection from it, including server-computed `legalActions`. The Blazor UI renders the projection: a new `DraftTable`, and a playable `GameTable` that only highlights what `legalActions` lists.

**Tech Stack:** PHP 8.1+ with PDO (SQLite locally, MySQL in production), a plain-PHP test runner; .NET 10 Blazor (`BloodAndBanners.UI`), xUnit and bUnit.

**Spec:** `docs/superpowers/specs/2026-10-11-vanilla-playable-match-design.md`

## Global Constraints

- PHP stays dependency-free; every PHP file starts with `declare(strict_types=1);` and uses the `BloodAndBanners\` namespace (autoloaded from `api/src`).
- Contract changes are additive only. New `GameProjection` members are `init` properties with defaults.
- JSON property names are camelCase on both sides.
- Illegal commands never change state. Each command runs in one transaction: state row, version bump, events, snapshot and receipt.
- Hero and army stats are copied into the match state at pick time from `data/gameData.json`.
- Unit IDs: `s{seatIndex}-hero`, `s{seatIndex}-u{1..6}`, where `seatIndex` is the seat's position (1 or 2) in membership order.
- Error codes, verbatim from the spec:
  - **Draft:** `notYourPick`, `decisionAlreadyMade`, `heroTaken`, `armyTaken`, `unknownHero`, `unknownArmy`, `invalidStart`, `invalidSeat`.
  - **Battle:** `notYourTurn`, `unknownUnit`, `notYourUnit`, `unitAlreadyActivated`, `noActivationsRemaining`, `heroActivationRequired`, `alreadyMoved`, `unitNotActive`, `hexUnreachable`, `targetOutOfRange`, `noLineOfSight`, `matchCompleted`.
  - **Start:** `scenarioTooSmall`, `modeNotPlayable`.
- Dice faces (existing `CustomDice`): d6 rolls 1–3 are `sword`, 4–5 are `shield`, 6 is `blank`.
- A disengagement attack rolls exactly 1 attack die: a sword is 1 wound, otherwise 0. There is no defense roll.
- The client contains no rules logic. It only highlights and sends what `legalActions` lists.

## Review Focus

1. **Empty JSON maps.** PHP encodes an empty associative array as `[]`, which C# cannot read as a dictionary. Every seat-keyed map in a projection must therefore always contain both seats, with `null` values where nothing is decided yet. The Task 6 contract-shape test checks this.
2. **Retried commands after a state change.** A retried `commandId` must return the cached projection and must not apply the move twice. The Task 6 idempotency test covers this for draft commands.
3. **A player stuck with nothing to do.** If no unit can move or attack, the turn must pass instead of freezing. The Task 5 hero-limit and dead-hero tests cover auto-pass, and the Task 6 HTTP full-match test proves a whole match terminates.
4. **A disengagement hit that kills the moving unit.** The unit must be removed and its activation cleared without crashing, including when that was the side's last unit. Task 5 adds a lethal-disengagement test.
5. **Reconnecting mid-match after the session is cleared** (Return to war room). Polling must restart for a new game. Task 9 makes `StartPolling` restart a completed poll task. No automated test covers that restart, so the reviewer must check it by reading the code, and the Task 11 manual check exercises it (play, return to the war room, open a new room).

---

## File Structure

**PHP — create**
- `api/migrations/002_match_state.sqlite.sql`, `api/migrations/002_match_state.mysql.sql`: the `game_states` table.
- `api/src/Rules/Vanilla/Hex.php`: axial key parsing, distance, neighbours.
- `api/src/Rules/Vanilla/Catalog.php`: hero and army stats from `gameData.json`.
- `api/src/Rules/Vanilla/Board.php`: engine board from a scenario; home lookup.
- `api/src/Rules/Vanilla/Placement.php`: deterministic placement and the capacity check.
- `api/src/Rules/Vanilla/Turn.php`: turn records and activation counting.
- `api/src/Rules/Vanilla/DraftRules.php`: open draft and deployment.
- `api/src/Rules/Vanilla/Movement.php`: reachable hexes and disengagement.
- `api/src/Rules/Vanilla/Combat.php`: range, line of sight and dice.
- `api/src/Rules/Vanilla/BattleRules.php`: activation, move, attack, end commands, auto-pass and victory.
- `api/src/Rules/Vanilla/MatchEngine.php`: start, phase dispatch and the event helper.
- `api/scenarios/proving-field.json`: the 11×9 Vanilla map.
- `api/tests/fixtures/catalog.json`: a small deterministic catalog for engine tests.
- `api/tests/VanillaTestSupport.php`: engine test helpers.
- `api/tests/VanillaGeometryTest.php`, `VanillaDraftTest.php`, `VanillaBattleTest.php`, `VanillaMatchServiceTest.php`.

**PHP — modify**
- `api/src/Database/Migrator.php`: run every numbered migration.
- `api/src/Game/GameService.php`: engine integration, state storage, projections.
- `api/tests/run.php`, `FoundationTest.php`, `ApiTest.php`, `ScenarioTest.php`.

**.NET — create**
- `src/BloodAndBanners.Contracts/MatchProjection.cs`
- `src/BloodAndBanners.UI/Services/GameApiException.cs`, `GameCommandRequest.cs`, `CatalogImages.cs`, `CombatLogFormatter.cs`
- `src/BloodAndBanners.UI/Components/DraftTable.razor`, `DraftTable.razor.css`, `CombatLog.razor`
- `tests/fixtures/draft-projection.json`, `tests/fixtures/unit-projection.json`
- `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs`, `GameTableTests.cs`, `CombatLogFormatterTests.cs`

**.NET — modify**
- `src/BloodAndBanners.Contracts/GameProjection.cs`
- `src/BloodAndBanners.UI/Services/IGameApiClient.cs`, `GameApiClient.cs`, `IGameSessionCoordinator.cs`, `GameSessionCoordinator.cs`
- `src/BloodAndBanners.UI/Components/GameExperience.razor`, `GameTable.razor`, `GameTable.razor.css`
- `src/BloodAndBanners.Web/Pages/Home.razor`, `src/BloodAndBanners.Web/wwwroot/appsettings.Development.json`, `src/BloodAndBanners.Maui/Components/Pages/Home.razor`
- `tests/fixtures/game-projection.json`, `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`
- `tests/BloodAndBanners.UI.Tests/UnitTest1.cs`, `GameExperienceTests.cs`, `GameSessionCoordinatorTests.cs`, `GameClientTests.cs`
- `README.md`

---

### Task 1: Numbered migrations and the `game_states` table

**Files:**
- Create: `api/migrations/002_match_state.sqlite.sql`, `api/migrations/002_match_state.mysql.sql`
- Modify: `api/src/Database/Migrator.php`
- Test: `api/tests/FoundationTest.php`

**Interfaces:**
- Produces: table `game_states (game_id PK, version, state_json, updated_at)`. `Migrator::migrate()` runs `NNN_*.{dialect}.sql` in ascending order and is safe to run repeatedly.

- [ ] **Step 1: Write the failing test.** In `api/tests/FoundationTest.php`, in the test `'migrations create the complete persistence model'`, add `'game_states'` to the table list:

```php
            foreach ([
                'users', 'guest_identities', 'refresh_tokens', 'scenarios', 'scenario_revisions',
                'games', 'game_memberships', 'spectator_links', 'game_snapshots', 'game_events',
                'command_receipts', 'game_states',
            ] as $table) {
```

Then add this test directly after it:

```php
        'migrations can run repeatedly without failing' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            (new Migrator($db))->migrate();
            (new Migrator($db))->migrate();

            $count = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'game_states'")->fetchColumn();
            expect_same(1, $count, 'game_states should exist exactly once');
        },
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php api/tests/run.php`
Expected: `FAIL migrations create the complete persistence model: missing table game_states`

- [ ] **Step 3: Implement.** Create `api/migrations/002_match_state.sqlite.sql`:

```sql
CREATE TABLE IF NOT EXISTS game_states (
    game_id TEXT PRIMARY KEY,
    version INTEGER NOT NULL,
    state_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
);
```

Create `api/migrations/002_match_state.mysql.sql`:

```sql
CREATE TABLE IF NOT EXISTS game_states (
    game_id CHAR(32) PRIMARY KEY,
    version BIGINT NOT NULL,
    state_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_state_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

Replace the `migrate()` method in `api/src/Database/Migrator.php`:

```php
    public function migrate(): void
    {
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dialect = $driver === 'mysql' ? 'mysql' : 'sqlite';
        $paths = glob(dirname(__DIR__, 2) . "/migrations/*.{$dialect}.sql") ?: [];
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException("No {$dialect} migrations were found.");
        }
        foreach ($paths as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$path}");
            }
            $this->connection->exec($sql);
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`

- [ ] **Step 5: Commit**

```bash
git add api/migrations api/src/Database/Migrator.php api/tests/FoundationTest.php
git commit -m "Run numbered migrations and add match state table"
```

---

### Task 2: Hex geometry and the hero/army catalog

**Files:**
- Create: `api/src/Rules/Vanilla/Hex.php`, `api/src/Rules/Vanilla/Catalog.php`, `api/tests/fixtures/catalog.json`, `api/tests/VanillaTestSupport.php`, `api/tests/VanillaGeometryTest.php`
- Modify: `api/tests/run.php`

**Interfaces:**
- Produces:
  - `Hex::key(int $q, int $r): string`
  - `Hex::parse(string $key): array{int,int}`
  - `Hex::distance(string $from, string $to): int`
  - `Hex::neighbors(string $key, array $board): list<string>` (only keys present in `$board`)
  - `new Catalog(?string $path = null)`
  - `Catalog::heroes(): list<array>`, `Catalog::armies(): list<array>`
  - `Catalog::hero(string $name): ?array`, `Catalog::army(string $name): ?array`
  - Hero and unit stat arrays have the keys `name, image, move, range, power, defense, hp`. Army arrays have the keys `name, color, units`.
  - Test helpers `test_catalog(): Catalog` and `expect_game_error(string $code, callable $action): void`

- [ ] **Step 1: Create the test fixture and support file.** Create `api/tests/fixtures/catalog.json`:

```json
{
  "heroes": [
    {"name": "Test Captain", "image": "/images/heroes/captain.png", "move": 3, "range": 1, "power": 4, "defense": 3, "hp": 6, "abilities": []},
    {"name": "Test Marshal", "image": "/images/heroes/marshal.png", "move": 4, "range": 2, "power": 3, "defense": 3, "hp": 5, "abilities": []},
    {"name": "Test Warden", "image": "/images/heroes/warden.png", "move": 2, "range": 1, "power": 5, "defense": 4, "hp": 7, "abilities": []}
  ],
  "armies": [
    {"name": "Red Company", "color": "Red", "units": [
      {"name": "Red Spear", "image": "/images/units/red_spear.png", "move": 2, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Red Shield", "image": "/images/units/red_shield.png", "move": 2, "range": 1, "power": 2, "defense": 4, "hp": 4, "abilities": []},
      {"name": "Red Bow", "image": "/images/units/red_bow.png", "move": 3, "range": 5, "power": 2, "defense": 1, "hp": 3, "abilities": []},
      {"name": "Red Rider", "image": "/images/units/red_rider.png", "move": 4, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Red Brute", "image": "/images/units/red_brute.png", "move": 2, "range": 1, "power": 4, "defense": 2, "hp": 5, "abilities": []},
      {"name": "Red Mage", "image": "/images/units/red_mage.png", "move": 3, "range": 4, "power": 3, "defense": 1, "hp": 3, "abilities": []}
    ]},
    {"name": "Blue Company", "color": "Blue", "units": [
      {"name": "Blue Spear", "image": "/images/units/blue_spear.png", "move": 2, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Blue Shield", "image": "/images/units/blue_shield.png", "move": 2, "range": 1, "power": 2, "defense": 4, "hp": 4, "abilities": []},
      {"name": "Blue Bow", "image": "/images/units/blue_bow.png", "move": 3, "range": 5, "power": 2, "defense": 1, "hp": 3, "abilities": []},
      {"name": "Blue Rider", "image": "/images/units/blue_rider.png", "move": 4, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Blue Brute", "image": "/images/units/blue_brute.png", "move": 2, "range": 1, "power": 4, "defense": 2, "hp": 5, "abilities": []},
      {"name": "Blue Mage", "image": "/images/units/blue_mage.png", "move": 3, "range": 4, "power": 3, "defense": 1, "hp": 3, "abilities": []}
    ]},
    {"name": "Green Company", "color": "Green", "units": [
      {"name": "Green Spear", "image": "/images/units/green_spear.png", "move": 2, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Green Shield", "image": "/images/units/green_shield.png", "move": 2, "range": 1, "power": 2, "defense": 4, "hp": 4, "abilities": []},
      {"name": "Green Bow", "image": "/images/units/green_bow.png", "move": 3, "range": 5, "power": 2, "defense": 1, "hp": 3, "abilities": []},
      {"name": "Green Rider", "image": "/images/units/green_rider.png", "move": 4, "range": 1, "power": 3, "defense": 2, "hp": 3, "abilities": []},
      {"name": "Green Brute", "image": "/images/units/green_brute.png", "move": 2, "range": 1, "power": 4, "defense": 2, "hp": 5, "abilities": []},
      {"name": "Green Mage", "image": "/images/units/green_mage.png", "move": 3, "range": 4, "power": 3, "defense": 1, "hp": 3, "abilities": []}
    ]}
  ],
  "monsters": [],
  "terrainObjects": []
}
```

Create `api/tests/VanillaTestSupport.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Catalog;

function test_catalog(): Catalog
{
    return new Catalog(__DIR__ . '/fixtures/catalog.json');
}

function expect_game_error(string $code, callable $action): void
{
    try {
        $action();
    } catch (GameServiceException $error) {
        expect_same($code, $error->errorCode, "expected game error {$code}");
        return;
    }
    throw new RuntimeException("expected game error {$code} but the action succeeded");
}
```

- [ ] **Step 2: Write the failing tests.** Create `api/tests/VanillaGeometryTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;

function vanilla_geometry_tests(): array
{
    return [
        'hex distance and neighbours use axial coordinates' => static function (): void {
            expect_same(0, Hex::distance('2,3', '2,3'), 'a hex is zero steps from itself');
            expect_same(1, Hex::distance('0,0', '1,-1'), 'diagonal axial neighbour is one step');
            expect_same(3, Hex::distance('0,0', '-1,3'), 'distance follows cube rules');
            $board = ['0,0' => [], '1,0' => [], '0,1' => [], '5,5' => []];
            expect_same(['1,0', '0,1'], Hex::neighbors('0,0', $board), 'only on-board neighbours are returned in direction order');
            expect_same([-2, 7], Hex::parse('-2,7'), 'keys parse to integers');
        },

        'catalog copies hero and army stats from game data' => static function (): void {
            $catalog = test_catalog();
            expect_same(
                ['name' => 'Test Marshal', 'image' => '/images/heroes/marshal.png', 'move' => 4, 'range' => 2, 'power' => 3, 'defense' => 3, 'hp' => 5],
                $catalog->hero('Test Marshal'),
                'hero stats should be copied without abilities'
            );
            expect_same(null, $catalog->hero('Nobody'), 'unknown heroes resolve to null');
            $army = $catalog->army('Blue Company');
            expect_same('Blue', $army['color'], 'army colour should be kept');
            expect_same(6, count($army['units']), 'armies carry six units');
            expect_same('Blue Bow', $army['units'][2]['name'], 'army units keep catalog order');
            expect_same(3, count($catalog->heroes()), 'all heroes are listed');
        },

        'the shipped catalog loads every hero and army' => static function (): void {
            $catalog = new Catalog();
            expect_same(8, count($catalog->heroes()), 'gameData.json ships eight heroes');
            expect_same(5, count($catalog->armies()), 'gameData.json ships five armies');
            foreach ($catalog->armies() as $army) {
                expect_same(6, count($army['units']), "{$army['name']} should field six units");
            }
        },
    ];
}
```

In `api/tests/run.php`, add these requires after `require __DIR__ . '/ScenarioTest.php';`:

```php
require __DIR__ . '/VanillaTestSupport.php';
require __DIR__ . '/VanillaGeometryTest.php';
```

and extend the merge:

```php
$tests = array_merge(foundation_tests(), api_tests(), core_rules_tests(), scenario_tests(), vanilla_geometry_tests());
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the three new tests FAIL with `Class "BloodAndBanners\Rules\Vanilla\Hex" not found` (or the Catalog equivalent).

- [ ] **Step 4: Implement.** Create `api/src/Rules/Vanilla/Hex.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use InvalidArgumentException;

final class Hex
{
    public const DIRECTIONS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    public static function key(int $q, int $r): string
    {
        return "{$q},{$r}";
    }

    public static function parse(string $key): array
    {
        if (preg_match('/^(-?\d+),(-?\d+)$/', $key, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid axial hex key {$key}.");
        }
        return [(int)$matches[1], (int)$matches[2]];
    }

    public static function distance(string $from, string $to): int
    {
        [$fromQ, $fromR] = self::parse($from);
        [$toQ, $toR] = self::parse($to);
        $deltaQ = $toQ - $fromQ;
        $deltaR = $toR - $fromR;
        return intdiv(abs($deltaQ) + abs($deltaR) + abs($deltaQ + $deltaR), 2);
    }

    public static function neighbors(string $key, array $board): array
    {
        [$q, $r] = self::parse($key);
        $neighbors = [];
        foreach (self::DIRECTIONS as [$deltaQ, $deltaR]) {
            $neighbor = self::key($q + $deltaQ, $r + $deltaR);
            if (isset($board[$neighbor])) {
                $neighbors[] = $neighbor;
            }
        }
        return $neighbors;
    }
}
```

Create `api/src/Rules/Vanilla/Catalog.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use RuntimeException;

final class Catalog
{
    private array $heroes = [];
    private array $armies = [];

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 4) . '/data/gameData.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read the game catalog at {$path}.");
        }
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['heroes'] as $hero) {
            $this->heroes[(string)$hero['name']] = self::stats($hero);
        }
        foreach ($data['armies'] as $army) {
            $this->armies[(string)$army['name']] = [
                'name' => (string)$army['name'],
                'color' => (string)($army['color'] ?? ''),
                'units' => array_map(self::stats(...), $army['units']),
            ];
        }
    }

    public function heroes(): array
    {
        return array_values($this->heroes);
    }

    public function armies(): array
    {
        return array_values($this->armies);
    }

    public function hero(string $name): ?array
    {
        return $this->heroes[$name] ?? null;
    }

    public function army(string $name): ?array
    {
        return $this->armies[$name] ?? null;
    }

    private static function stats(array $card): array
    {
        return [
            'name' => (string)$card['name'],
            'image' => (string)($card['image'] ?? ''),
            'move' => (int)$card['move'],
            'range' => (int)$card['range'],
            'power' => (int)$card['power'],
            'defense' => (int)$card['defense'],
            'hp' => (int)$card['hp'],
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla api/tests
git commit -m "Add hex geometry and hero army catalog for Vanilla rules"
```

---

### Task 3: Proving Field map, engine board and deterministic placement

**Files:**
- Create: `api/scenarios/proving-field.json`, `api/src/Rules/Vanilla/Board.php`, `api/src/Rules/Vanilla/Placement.php`
- Modify: `api/tests/VanillaGeometryTest.php`, `api/tests/ScenarioTest.php`

**Interfaces:**
- Consumes: `Hex` (Task 2), `GameServiceException(string $errorCode, string $message, ?int $currentVersion = null)`.
- Produces:
  - `Board::fromScenario(array $definition): array<string, array{elevation:int, sightBlocker:bool, playerStart:?int}>`, keyed by `"q,r"`
  - `Board::homes(array $board): array<int,string>` (start number → hex key)
  - `Placement::place(array $board, string $homeKey, string $opponentHomeKey, array $unitIds, array $occupied): array<string,string>` (unitId → hex). Throws `scenarioTooSmall`.
  - `Placement::assertCapacity(array $board, int $unitsPerSide): void`. Throws `scenarioTooSmall`.
  - Test helper `proving_field_board(): array`

- [ ] **Step 1: Generate the map.** Save this one-off generator to the session scratchpad (not the repo) as `genmap.py`, then run `python genmap.py "api/scenarios/proving-field.json"` from the repo root:

```python
import json, sys
COLS, ROWS = 11, 9
special = {}
for c in (4, 5, 6):
    for r in range(2, 7):
        special[(c, r)] = dict(biome='stone', terrain='stone', elevation=1)
    special[(c, 4)] = dict(biome='stone', terrain='stone-path', elevation=1)
special[(5, 3)] = dict(biome='stone', terrain='crags', elevation=2)
special[(5, 5)] = dict(biome='stone', terrain='crags', elevation=2)
special[(5, 1)] = dict(biome='stone', terrain='crags', elevation=2, sightBlocker=True)
special[(5, 7)] = dict(biome='stone', terrain='crags', elevation=2, sightBlocker=True)
for cell in [(3, 1), (7, 7), (3, 6), (7, 2)]:
    special[cell] = dict(biome='plains', terrain='grass', elevation=0, object='statue', sightBlocker=True)
for cell in [(2, 7), (8, 1), (2, 1), (8, 7)]:
    special[cell] = dict(biome='plains', terrain='grass', elevation=1)
hexes = []
for r in range(ROWS):
    for c in range(COLS):
        h = dict(q=c - r // 2, r=r, biome='plains', terrain='grass', elevation=0, playerStart=None, portal=False,
                 spawnZone=None, piece=None, object=None, fountain=False, sightBlocker=False)
        if r == 4:
            h['terrain'] = 'roads'
        h.update(special.get((c, r), {}))
        if (c, r) == (1, 4):
            h.update(playerStart=1, portal=True)
        if (c, r) == (9, 4):
            h.update(playerStart=2, portal=True)
        hexes.append(h)
lines = ['{', '  "schemaVersion": 1,', '  "name": "Proving Field",', '  "modeCompatibility": ["vanillaPvp"],',
         '  "map": {', '    "columns": 11,', '    "rows": 9,', '    "hexes": [',
         ',\n'.join('      ' + json.dumps(h, separators=(',', ':')) for h in hexes),
         '    ]', '  },', '  "placements": [],', '  "objectives": [{"kind": "annihilation"}],',
         '  "scoring": {"target": 10, "enemyHero": 3, "boss": 2, "terrainObject": 1, "completeObjectSetBonus": 2}', '}']
open(sys.argv[1], 'w', newline='\n').write('\n'.join(lines) + '\n')
```

Check: `python -c "import json;d=json.load(open('api/scenarios/proving-field.json'));print(len(d['map']['hexes']),sum(h['sightBlocker'] for h in d['map']['hexes']))"`
Expected: `99 6`

- [ ] **Step 2: Write the failing tests.** Append these entries to the array returned by `vanilla_geometry_tests()` in `api/tests/VanillaGeometryTest.php`, and add `use BloodAndBanners\Rules\Vanilla\Placement;` to the imports:

```php
        'proving field places both armies deterministically beside their portals' => static function (): void {
            $board = proving_field_board();
            $ids = ['hero', 'u1', 'u2', 'u3', 'u4', 'u5', 'u6'];
            $first = Placement::place($board, '-1,4', '7,4', $ids, []);
            expect_same(
                ['hero' => '0,4', 'u1' => '-1,5', 'u2' => '0,3', 'u3' => '-2,4', 'u4' => '-2,5', 'u5' => '-1,3', 'u6' => '1,4'],
                $first,
                'start 1 fills its portal ring nearest the enemy, then clusters around home'
            );
            $second = Placement::place($board, '7,4', '-1,4', $ids, array_fill_keys(array_values($first), true));
            expect_same(
                ['hero' => '6,4', 'u1' => '6,5', 'u2' => '7,3', 'u3' => '7,5', 'u4' => '8,3', 'u5' => '8,4', 'u6' => '5,4'],
                $second,
                'start 2 mirrors the same rules'
            );
            Placement::assertCapacity($board, 7);
        },

        'training grounds is too small for two full armies' => static function (): void {
            $definition = json_decode((string)file_get_contents(dirname(__DIR__) . '/scenarios/training-grounds.json'), true, flags: JSON_THROW_ON_ERROR);
            expect_game_error('scenarioTooSmall', static fn() => Placement::assertCapacity(Board::fromScenario($definition), 7));
        },

        'engine board keeps elevation sight blockers and homes' => static function (): void {
            $board = proving_field_board();
            expect_same(99, count($board), 'every scenario hex becomes a board hex');
            expect_same(['elevation' => 2, 'sightBlocker' => true, 'playerStart' => null], $board['5,1'], 'tall crags block sight');
            expect_same(['elevation' => 0, 'sightBlocker' => true, 'playerStart' => null], $board['3,1'], 'statues block sight');
            expect_same([1 => '-1,4', 2 => '7,4'], Board::homes($board), 'homes are keyed by start number');
        },
```

Also add `use BloodAndBanners\Rules\Vanilla\Board;` to the imports. Append this helper to `api/tests/VanillaTestSupport.php` (add `use BloodAndBanners\Rules\Vanilla\Board;` at the top):

```php
function proving_field_board(): array
{
    $definition = json_decode(
        (string)file_get_contents(dirname(__DIR__) . '/scenarios/proving-field.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    return Board::fromScenario($definition);
}
```

In `api/tests/ScenarioTest.php`, test `'built in scenarios cover every game mode with valid maps'`, change the file count:

```php
            expect_same(4, count($files), 'four curated scenarios should ship');
```

(Axial `q = col − floor(row/2)`. On row 1 that offset is 0, so `'5,1'` is the tall crag at offset column 5 and `'3,1'` is the statue at offset column 3.)

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the new tests FAIL with `Class "BloodAndBanners\Rules\Vanilla\Placement" not found`. The scenario count test passes.

- [ ] **Step 4: Implement.** Create `api/src/Rules/Vanilla/Board.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Board
{
    public static function fromScenario(array $definition): array
    {
        $board = [];
        foreach ($definition['map']['hexes'] as $hex) {
            $board[Hex::key((int)$hex['q'], (int)$hex['r'])] = [
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => (bool)($hex['sightBlocker'] ?? false),
                'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
            ];
        }
        return $board;
    }

    public static function homes(array $board): array
    {
        $homes = [];
        foreach ($board as $key => $hex) {
            if ($hex['playerStart'] !== null) {
                $homes[$hex['playerStart']] = (string)$key;
            }
        }
        ksort($homes);
        return $homes;
    }
}
```

Create `api/src/Rules/Vanilla/Placement.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;

final class Placement
{
    public static function place(array $board, string $homeKey, string $opponentHomeKey, array $unitIds, array $occupied): array
    {
        $occupied[$homeKey] = true;
        $occupied[$opponentHomeKey] = true;
        $placed = [];
        foreach (array_values($unitIds) as $index => $unitId) {
            $anchors = $index < 4 ? [$homeKey] : array_values($placed);
            $candidates = [];
            foreach ($anchors as $anchor) {
                foreach (Hex::neighbors($anchor, $board) as $key) {
                    if (!$board[$key]['sightBlocker'] && !isset($occupied[$key])) {
                        $candidates[$key] = $key;
                    }
                }
            }
            if ($candidates === []) {
                throw self::tooSmall();
            }
            $candidates = array_values($candidates);
            usort(
                $candidates,
                static fn(string $a, string $b): int =>
                    self::rank($a, $index, $homeKey, $opponentHomeKey) <=> self::rank($b, $index, $homeKey, $opponentHomeKey)
            );
            $placed[(string)$unitId] = $candidates[0];
            $occupied[$candidates[0]] = true;
        }
        return $placed;
    }

    public static function assertCapacity(array $board, int $unitsPerSide): void
    {
        $homes = Board::homes($board);
        if (!isset($homes[1], $homes[2])) {
            throw self::tooSmall();
        }
        $probe = array_map(static fn(int $index): string => "probe-{$index}", range(1, $unitsPerSide));
        $first = self::place($board, $homes[1], $homes[2], $probe, []);
        self::place($board, $homes[2], $homes[1], $probe, array_fill_keys(array_values($first), true));
    }

    private static function rank(string $key, int $index, string $home, string $opponent): array
    {
        [$q, $r] = Hex::parse($key);
        return $index < 4
            ? [Hex::distance($key, $opponent), $q, $r]
            : [Hex::distance($key, $home), Hex::distance($key, $opponent), $q, $r];
    }

    private static function tooSmall(): GameServiceException
    {
        return new GameServiceException('scenarioTooSmall', 'This battlefield cannot fit both armies beside their home portals.');
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`

- [ ] **Step 6: Commit**

```bash
git add api/scenarios/proving-field.json api/src/Rules/Vanilla api/tests
git commit -m "Add Proving Field scenario and deterministic army placement"
```

---

### Task 4: Initiative, open draft and deployment

**Files:**
- Create: `api/src/Rules/Vanilla/Turn.php`, `api/src/Rules/Vanilla/DraftRules.php`, `api/src/Rules/Vanilla/MatchEngine.php`, `api/tests/VanillaDraftTest.php`
- Modify: `api/tests/run.php`

**Interfaces:**
- Consumes: `Catalog`, `Board`, `Placement` (Tasks 2–3); `InitiativeResolver::resolve(array $participants, RandomSource $random): array{order, rolls, final}`; `SequenceRandom` (test class in `CoreRulesTest.php`).
- Produces:
  - **State shape** (the JSON stored in `game_states`):
    ```
    phase: 'draft'|'active'|'completed'
    seats: [seatId1, seatId2]
    board: (Board::fromScenario output)
    initiative: {rolls: {seatId: [int]}, order: [seatId, seatId]}
    draft: {pickingSeatId: ?string, firstSeatId: ?string, startBySeat: {seatId: ?int}, heroBySeat: {seatId: ?string}, armyBySeat: {seatId: ?string}}
    units: {unitId: {unitId, seatId, isHero, name, image, move, range, power, defense, hp, wounds, hex: ?string}}
    turn: {round, activeSeatId: ?string, activeUnitId: ?string, activated: [unitId], moved: bool, allowance: int}
    result: null | {reason: 'annihilation', winningSeatId}
    ```
  - `new MatchEngine(Catalog $catalog)`
  - `MatchEngine::catalog(): Catalog`
  - `MatchEngine::start(array $seatIds, array $board, RandomSource $random): array{state, events}`
  - `MatchEngine::apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array{state, events}`
  - `MatchEngine::legalActions(array $state, ?string $seatId): list<array{kind, unitId, targetHexes}>`
  - `MatchEngine::event(string $kind, array $focusHexes, array $payload): array{kind, focusHexes, payload}`
  - `MatchEngine::otherSeat(array $state, string $seatId): string`
  - `Turn::idle(): array`, `Turn::fresh(array $state, string $seatId, int $round): array`, `Turn::activationsRemaining(array $state): int`
  - `MatchEngine` dispatches the `active` phase to `BattleRules` (created in Task 5). This task's tests never reach that branch.

- [ ] **Step 1: Write the failing tests.** Create `api/tests/VanillaDraftTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;

function vanilla_draft_tests(): array
{
    return [
        'initiative winner picks first and picks then alternate' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $started = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]));
            $state = $started['state'];

            expect_same('draft', $state['phase'], 'a started match enters the draft');
            expect_same('seat-b', $state['draft']['pickingSeatId'], 'the higher roll picks first');
            expect_same('initiativeRolled', $started['events'][0]['kind'], 'initiative is announced');
            expect_same(['seat-a' => [5], 'seat-b' => [17]], $started['events'][0]['payload']['rolls'], 'rolls are recorded per seat');
            expect_same([], $engine->legalActions($state, 'seat-a'), 'the waiting seat has no picks');
            expect_same(
                ['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'],
                array_column($engine->legalActions($state, 'seat-b'), 'kind'),
                'the picking seat may make any open decision'
            );

            $picked = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([]));
            expect_same('seat-a', $picked['state']['draft']['pickingSeatId'], 'picks alternate');
            expect_same('Test Captain', $picked['state']['draft']['heroBySeat']['seat-b'], 'the hero is claimed');
            expect_same(
                ['seatId' => 'seat-b', 'decision' => 'pickHero', 'value' => 'Test Captain'],
                $picked['events'][0]['payload'],
                'the pick is announced'
            );
        },

        'heroes and armies can only be claimed once' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]))['state'];
            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([]))['state'];
            expect_game_error('heroTaken', static fn() => $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([])));
            $state = $engine->apply($state, 'seat-a', 'pickArmy', ['army' => 'Red Company'], new SequenceRandom([]))['state'];
            expect_game_error('armyTaken', static fn() => $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Red Company'], new SequenceRandom([])));
        },

        'illegal draft picks are rejected' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]))['state'];
            $none = new SequenceRandom([]);
            expect_game_error('notYourPick', static fn() => $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], $none));
            expect_game_error('unknownHero', static fn() => $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Nobody'], $none));
            expect_game_error('unknownArmy', static fn() => $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Nobody'], $none));
            expect_game_error('invalidStart', static fn() => $engine->apply($state, 'seat-b', 'chooseStartPosition', ['start' => 3], $none));
            expect_game_error('invalidSeat', static fn() => $engine->apply($state, 'seat-b', 'chooseFirstPlayer', ['seatId' => 'seat-z'], $none));
            expect_game_error('unsupportedCommand', static fn() => $engine->apply($state, 'seat-b', 'moveUnit', [], $none));

            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Marshal'], $none)['state'];
            expect_game_error('decisionAlreadyMade', static fn() => $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Warden'], $none));
        },

        'a commander with nothing left to decide is skipped and the draft deploys both armies' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([17, 5]))['state'];
            $state = $engine->apply($state, 'seat-a', 'chooseFirstPlayer', ['seatId' => 'seat-b'], $none)['state'];
            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Marshal'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'chooseStartPosition', ['start' => 1], $none)['state'];
            expect_same(['seat-a' => 1, 'seat-b' => 2], $state['draft']['startBySeat'], 'choosing a start assigns the other one');
            $state = $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Blue Company'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], $none)['state'];
            expect_same('seat-a', $state['draft']['pickingSeatId'], 'seat-b has nothing left, so seat-a picks again');

            $final = $engine->apply($state, 'seat-a', 'pickArmy', ['army' => 'Red Company'], $none);
            $state = $final['state'];
            expect_same('active', $state['phase'], 'the finished draft starts the battle');
            expect_same(['draftPicked', 'armiesPlaced'], array_column($final['events'], 'kind'), 'deployment is announced');
            expect_same(14, count($state['units']), 'both armies take the field');
            expect_same('0,4', $state['units']['s1-hero']['hex'], 'seat-a holds start 1');
            expect_same('1,4', $state['units']['s1-u6']['hex'], 'the last seat-a unit clusters near home');
            expect_same('6,4', $state['units']['s2-hero']['hex'], 'seat-b holds start 2');
            expect_same('Test Marshal', $state['units']['s2-hero']['name'], 'hero stats are copied in');
            expect_same(true, $state['units']['s2-hero']['isHero'], 'the hero is flagged');
            expect_same('Red Mage', $state['units']['s1-u6']['name'], 'army units keep catalog order');
            expect_same(0, $state['units']['s1-u1']['wounds'], 'units start unwounded');
            expect_same(
                ['round' => 1, 'activeSeatId' => 'seat-b', 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 4],
                $state['turn'],
                'the chosen first player opens round one'
            );
        },
    ];
}
```

In `api/tests/run.php`, add `require __DIR__ . '/VanillaDraftTest.php';` after the geometry require, and add `vanilla_draft_tests()` to the `array_merge`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the new tests FAIL with `Class "BloodAndBanners\Rules\Vanilla\MatchEngine" not found`.

- [ ] **Step 3: Implement.** Create `api/src/Rules/Vanilla/Turn.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Turn
{
    public static function idle(): array
    {
        return ['round' => 0, 'activeSeatId' => null, 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 0];
    }

    public static function fresh(array $state, string $seatId, int $round): array
    {
        $alive = count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
        return [
            'round' => $round,
            'activeSeatId' => $seatId,
            'activeUnitId' => null,
            'activated' => [],
            'moved' => false,
            'allowance' => min(4, $alive),
        ];
    }

    public static function activationsRemaining(array $state): int
    {
        if ($state['phase'] !== 'active') {
            return 0;
        }
        return max(0, $state['turn']['allowance'] - count($state['turn']['activated']));
    }
}
```

Create `api/src/Rules/Vanilla/MatchEngine.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\InitiativeResolver;
use BloodAndBanners\Rules\RandomSource;

final class MatchEngine
{
    public const UNITS_PER_SIDE = 7;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    public function start(array $seatIds, array $board, RandomSource $random): array
    {
        $seatIds = array_values($seatIds);
        if (count($seatIds) !== 2) {
            throw new GameServiceException('modeNotPlayable', 'A Vanilla duel needs exactly two commanders.');
        }
        Placement::assertCapacity($board, self::UNITS_PER_SIDE);
        $initiative = (new InitiativeResolver())->resolve($seatIds, $random);
        $undecided = array_fill_keys($seatIds, null);
        $state = [
            'phase' => 'draft',
            'seats' => $seatIds,
            'board' => $board,
            'initiative' => ['rolls' => $initiative['rolls'], 'order' => $initiative['order']],
            'draft' => [
                'pickingSeatId' => $initiative['order'][0],
                'firstSeatId' => null,
                'startBySeat' => $undecided,
                'heroBySeat' => $undecided,
                'armyBySeat' => $undecided,
            ],
            'units' => [],
            'turn' => Turn::idle(),
            'result' => null,
        ];
        return [
            'state' => $state,
            'events' => [self::event('initiativeRolled', [], [
                'rolls' => $initiative['rolls'],
                'order' => $initiative['order'],
            ])],
        ];
    }

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->apply($state, $seatId, $kind, $payload),
            'active' => (new BattleRules())->apply($state, $seatId, $kind, $payload, $random),
            default => throw new GameServiceException('matchCompleted', 'The match is already over.'),
        };
    }

    public function legalActions(array $state, ?string $seatId): array
    {
        if ($seatId === null) {
            return [];
        }
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->legalActions($state, $seatId),
            'active' => (new BattleRules())->legalActions($state, $seatId),
            default => [],
        };
    }

    public static function event(string $kind, array $focusHexes, array $payload): array
    {
        return ['kind' => $kind, 'focusHexes' => array_values($focusHexes), 'payload' => $payload];
    }

    public static function otherSeat(array $state, string $seatId): string
    {
        return $state['seats'][0] === $seatId ? $state['seats'][1] : $state['seats'][0];
    }
}
```

Create `api/src/Rules/Vanilla/DraftRules.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;

final class DraftRules
{
    private const KINDS = ['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'notYourPick' => 'It is not your pick.',
        'decisionAlreadyMade' => 'That decision has already been made.',
        'invalidSeat' => 'Choose one of the two commanders.',
        'invalidStart' => 'Choose start position 1 or 2.',
        'unknownHero' => 'That hero is not in the catalog.',
        'unknownArmy' => 'That army is not in the catalog.',
        'heroTaken' => 'Another commander has already claimed that hero.',
        'armyTaken' => 'Another commander has already claimed that army.',
    ];

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function openDecisions(array $state, string $seatId): array
    {
        $draft = $state['draft'];
        $open = [];
        if ($draft['firstSeatId'] === null) {
            $open[] = 'chooseFirstPlayer';
        }
        if ($draft['startBySeat'][$seatId] === null) {
            $open[] = 'chooseStartPosition';
        }
        if ($draft['heroBySeat'][$seatId] === null) {
            $open[] = 'pickHero';
        }
        if ($draft['armyBySeat'][$seatId] === null) {
            $open[] = 'pickArmy';
        }
        return $open;
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            return [];
        }
        return array_map(
            static fn(string $kind): array => ['kind' => $kind, 'unitId' => null, 'targetHexes' => []],
            $this->openDecisions($state, $seatId)
        );
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            self::fail('notYourPick');
        }
        if (!in_array($kind, $this->openDecisions($state, $seatId), true)) {
            self::fail('decisionAlreadyMade');
        }
        $other = MatchEngine::otherSeat($state, $seatId);
        $draft = $state['draft'];
        if ($kind === 'chooseFirstPlayer') {
            $value = (string)($payload['seatId'] ?? '');
            if (!in_array($value, $state['seats'], true)) {
                self::fail('invalidSeat');
            }
            $draft['firstSeatId'] = $value;
        } elseif ($kind === 'chooseStartPosition') {
            $value = $payload['start'] ?? null;
            if ($value !== 1 && $value !== 2) {
                self::fail('invalidStart');
            }
            $draft['startBySeat'][$seatId] = $value;
            $draft['startBySeat'][$other] = 3 - $value;
        } elseif ($kind === 'pickHero') {
            $value = (string)($payload['hero'] ?? '');
            if ($this->catalog->hero($value) === null) {
                self::fail('unknownHero');
            }
            if (in_array($value, $draft['heroBySeat'], true)) {
                self::fail('heroTaken');
            }
            $draft['heroBySeat'][$seatId] = $value;
        } else {
            $value = (string)($payload['army'] ?? '');
            if ($this->catalog->army($value) === null) {
                self::fail('unknownArmy');
            }
            if (in_array($value, $draft['armyBySeat'], true)) {
                self::fail('armyTaken');
            }
            $draft['armyBySeat'][$seatId] = $value;
        }
        $state['draft'] = $draft;
        $events = [MatchEngine::event('draftPicked', [], ['seatId' => $seatId, 'decision' => $kind, 'value' => $value])];

        if ($this->openDecisions($state, $other) !== []) {
            $state['draft']['pickingSeatId'] = $other;
        } elseif ($this->openDecisions($state, $seatId) !== []) {
            $state['draft']['pickingSeatId'] = $seatId;
        } else {
            $state['draft']['pickingSeatId'] = null;
            [$state, $placed] = $this->deploy($state);
            $events[] = MatchEngine::event('armiesPlaced', array_values($placed), ['units' => $placed]);
        }
        return ['state' => $state, 'events' => $events];
    }

    private function deploy(array $state): array
    {
        $homes = Board::homes($state['board']);
        $seatsByStart = [];
        foreach ($state['seats'] as $seatId) {
            $seatsByStart[$state['draft']['startBySeat'][$seatId]] = $seatId;
        }
        ksort($seatsByStart);
        $units = [];
        $placed = [];
        $occupied = [];
        foreach ($seatsByStart as $start => $seatId) {
            $prefix = 's' . (array_search($seatId, $state['seats'], true) + 1);
            $roster = [$prefix . '-hero' => ['isHero' => true] + $this->catalog->hero($state['draft']['heroBySeat'][$seatId])];
            foreach ($this->catalog->army($state['draft']['armyBySeat'][$seatId])['units'] as $index => $stats) {
                $roster[$prefix . '-u' . ($index + 1)] = ['isHero' => false] + $stats;
            }
            $positions = Placement::place($state['board'], $homes[$start], $homes[3 - $start], array_keys($roster), $occupied);
            foreach ($roster as $unitId => $stats) {
                $units[$unitId] = ['unitId' => $unitId, 'seatId' => $seatId] + $stats + ['wounds' => 0, 'hex' => $positions[$unitId]];
                $occupied[$positions[$unitId]] = true;
                $placed[$unitId] = $positions[$unitId];
            }
        }
        ksort($units, SORT_STRING);
        ksort($placed, SORT_STRING);
        $state['units'] = $units;
        $state['phase'] = 'active';
        $state['turn'] = Turn::fresh($state, $state['draft']['firstSeatId'], 1);
        return [$state, $placed];
    }

    private static function fail(string $code): never
    {
        throw new GameServiceException($code, self::MESSAGES[$code]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla api/tests
git commit -m "Add initiative open draft and army deployment rules"
```

---

### Task 5: Battle rules — activations, movement, disengagement, combat and victory

**Files:**
- Create: `api/src/Rules/Vanilla/Movement.php`, `api/src/Rules/Vanilla/Combat.php`, `api/src/Rules/Vanilla/BattleRules.php`, `api/tests/VanillaBattleTest.php`
- Modify: `api/tests/VanillaTestSupport.php`, `api/tests/run.php`

**Interfaces:**
- Consumes: the state shape and `Turn`/`MatchEngine` helpers from Task 4; `CustomDice::roll(int $count, RandomSource $random): array{faces, swords, shields}`; `HexLineOfSight::isVisible(string $startKey, string $targetKey, array $board): bool`, where each board entry has `elevation`, `sightBlocker` and `occupantId`.
- Produces:
  - `Movement::reachable(array $state, string $unitId): list<string>` (sorted)
  - `Movement::disengagedFrom(array $state, string $unitId, string $to): list<string>` (enemy unit IDs)
  - `Combat::targetError(array $state, string $attackerId, string $targetId): ?string`
  - `Combat::targets(array $state, string $attackerId): list<string>`
  - `Combat::attack(array $state, string $attackerId, string $targetId, RandomSource $random): array{attackFaces, defenseFaces, wounds}`
  - `Combat::disengagementHit(RandomSource $random): array{faces, wounds}`
  - `BattleRules::apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array{state, events}`
  - `BattleRules::legalActions(array $state, string $seatId): list<array{kind, unitId, targetHexes}>`
  - Event payloads:
    - `unitMoved {unitId, from, to, disengagement: [{byUnitId, faces, wounds}]}`
    - `attackResolved {unitId, targetUnitId, attackFaces, defenseFaces, wounds}`
    - `unitDefeated {unitId}`
    - `turnEnded {seatId, nextSeatId, round}`
    - `matchCompleted {winningSeatId, reason}`

- [ ] **Step 1: Add the battle test helpers.** Append to `api/tests/VanillaTestSupport.php` (add `use BloodAndBanners\Rules\Vanilla\MatchEngine;` and `use BloodAndBanners\Rules\Vanilla\Turn;` at the top):

```php
function vanilla_board(array $elevations, array $blockers = []): array
{
    $board = [];
    foreach ($elevations as $key => $elevation) {
        $board[(string)$key] = [
            'elevation' => $elevation,
            'sightBlocker' => in_array((string)$key, $blockers, true),
            'playerStart' => null,
        ];
    }
    return $board;
}

function vanilla_line(int $length): array
{
    $elevations = [];
    for ($q = 0; $q < $length; $q++) {
        $elevations["{$q},0"] = 0;
    }
    return $elevations;
}

function vanilla_unit(string $unitId, string $seatId, ?string $hex, array $stats = []): array
{
    return $stats + [
        'unitId' => $unitId, 'seatId' => $seatId, 'isHero' => false, 'name' => $unitId, 'image' => '',
        'move' => 2, 'range' => 1, 'power' => 2, 'defense' => 1, 'hp' => 3, 'wounds' => 0, 'hex' => $hex,
    ];
}

function vanilla_battle(array $board, array $units, string $activeSeat = 'seat-a'): array
{
    $state = [
        'phase' => 'active',
        'seats' => ['seat-a', 'seat-b'],
        'board' => $board,
        'initiative' => ['rolls' => ['seat-a' => [20], 'seat-b' => [1]], 'order' => ['seat-a', 'seat-b']],
        'draft' => [
            'pickingSeatId' => null,
            'firstSeatId' => 'seat-a',
            'startBySeat' => ['seat-a' => 1, 'seat-b' => 2],
            'heroBySeat' => ['seat-a' => 'Test Captain', 'seat-b' => 'Test Marshal'],
            'armyBySeat' => ['seat-a' => 'Red Company', 'seat-b' => 'Blue Company'],
        ],
        'units' => [],
        'turn' => Turn::idle(),
        'result' => null,
    ];
    foreach ($units as $unit) {
        $state['units'][$unit['unitId']] = $unit;
    }
    $state['turn'] = Turn::fresh($state, $activeSeat, 1);
    return $state;
}

function vanilla_command(array $state, string $seatId, string $kind, array $payload = [], array $rolls = []): array
{
    return (new MatchEngine(test_catalog()))->apply($state, $seatId, $kind, $payload, new SequenceRandom($rolls));
}
```

- [ ] **Step 2: Write the failing tests.** Create `api/tests/VanillaBattleTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Turn;

function vanilla_battle_tests(): array
{
    return [
        'movement climbs at most one level per step' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 0, '1,0' => 1, '2,0' => 3, '3,0' => 1]),
                [vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '3,0')]
            );
            expect_same(['1,0'], Movement::reachable($state, 'a1'), 'a two-level cliff stops movement');
        },

        'units pass through friends but not enemies and never stop on an occupied hex' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            expect_same(['2,0'], Movement::reachable($state, 'a1'), 'friends are passable, enemies block');
        },

        'sight blocking hexes are impassable' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '5,5' => 0], ['1,0']),
                [vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '5,5')]
            );
            expect_same([], Movement::reachable($state, 'a1'), 'a rock wall blocks the only path');
        },

        'moving away from an engaged enemy costs one disengagement die' => static function (): void {
            $units = [vanilla_unit('a1', 'seat-a', '1,0'), vanilla_unit('b1', 'seat-b', '0,0')];
            $struck = vanilla_command(vanilla_battle(vanilla_board(vanilla_line(4)), $units), 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [1]);
            expect_same('unitMoved', $struck['events'][0]['kind'], 'the move is announced first');
            expect_same(
                [['byUnitId' => 'b1', 'faces' => ['sword'], 'wounds' => 1]],
                $struck['events'][0]['payload']['disengagement'],
                'a sword is one wound'
            );
            expect_same(['1,0', '3,0'], $struck['events'][0]['focusHexes'], 'the move focuses both ends');
            expect_same(1, $struck['state']['units']['a1']['wounds'], 'the wound is applied');

            $blocked = vanilla_command(vanilla_battle(vanilla_board(vanilla_line(4)), $units), 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [4]);
            expect_same(0, $blocked['state']['units']['a1']['wounds'], 'a shield does nothing');
        },

        'enemies more than one level apart are not engaged' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 2, '1,0' => 0, '2,0' => 0, '3,0' => 0]),
                [vanilla_unit('a1', 'seat-a', '1,0'), vanilla_unit('b1', 'seat-b', '0,0')]
            );
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0']);
            expect_same([], $moved['events'][0]['payload']['disengagement'], 'no die is rolled');
        },

        'a lethal disengagement removes the mover and can end the match' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '1,0', ['hp' => 1]),
                vanilla_unit('b1', 'seat-b', '0,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [2]);
            expect_same(['unitMoved', 'unitDefeated', 'matchCompleted'], array_column($result['events'], 'kind'), 'defeat and victory follow the move');
            expect_same(null, $result['state']['units']['a1']['hex'], 'the defeated mover leaves the board');
            expect_same(null, $result['state']['turn']['activeUnitId'], 'the dead unit is no longer active');
            expect_same(['reason' => 'annihilation', 'winningSeatId' => 'seat-b'], $result['state']['result'], 'seat-b wins');
        },

        'a living hero limits a turn to three other activations' => static function (): void {
            $board = vanilla_board(vanilla_line(10) + ['0,5' => 0]);
            $state = vanilla_battle($board, [
                vanilla_unit('a-hero', 'seat-a', '0,0', ['isHero' => true]),
                vanilla_unit('a1', 'seat-a', '2,0'),
                vanilla_unit('a2', 'seat-a', '4,0'),
                vanilla_unit('a3', 'seat-a', '6,0'),
                vanilla_unit('a4', 'seat-a', '8,0'),
                vanilla_unit('b1', 'seat-b', '0,5'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '5,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '7,0'])['state'];
            expect_same(1, Turn::activationsRemaining($state), 'three of four activations are spent');
            expect_game_error('heroActivationRequired', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a4', 'to' => '9,0']));

            $last = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '1,0']);
            expect_same(['unitMoved', 'turnEnded'], array_column($last['events'], 'kind'), 'the turn passes when nothing can act');
            expect_same('seat-b', $last['state']['turn']['activeSeatId'], 'seat-b takes over');
            expect_same(['seatId' => 'seat-a', 'nextSeatId' => 'seat-b', 'round' => 1], $last['events'][1]['payload'], 'the round only advances on the first player');
        },

        'a dead hero lets any four units activate' => static function (): void {
            $board = vanilla_board(vanilla_line(10) + ['0,5' => 0]);
            $state = vanilla_battle($board, [
                vanilla_unit('a-hero', 'seat-a', null, ['isHero' => true]),
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('a2', 'seat-a', '2,0'),
                vanilla_unit('a3', 'seat-a', '4,0'),
                vanilla_unit('a4', 'seat-a', '6,0'),
                vanilla_unit('a5', 'seat-a', '8,0'),
                vanilla_unit('b1', 'seat-b', '0,5'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '3,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '5,0'])['state'];
            $result = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a4', 'to' => '7,0']);
            expect_same('seat-b', $result['state']['turn']['activeSeatId'], 'the fourth activation spends the turn');
        },

        'units activate once per turn and move once per activation' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 1, 'hp' => 5]),
                vanilla_unit('a3', 'seat-a', '2,0'),
                vanilla_unit('a2', 'seat-a', '3,0'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 6])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0']));
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '4,0'])['state'];
            expect_game_error('alreadyMoved', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '3,0']));
            expect_game_error('hexUnreachable', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '0,0']));
            expect_game_error('notYourUnit', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'b1', 'to' => '0,0']));
            expect_game_error('unknownUnit', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'ghost', 'to' => '0,0']));
            expect_game_error('notYourTurn', static fn() => vanilla_command($state, 'seat-b', 'endTurn'));
        },

        'attacks need range and a clear line of sight' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['range' => 2]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b2', 'seat-b', '2,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            expect_game_error('targetOutOfRange', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1']));
            expect_game_error('noLineOfSight', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b2']));
            expect_game_error('unknownUnit', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'a2']));
            $attacks = array_values(array_filter(
                (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'),
                static fn(array $action): bool => $action['kind'] === 'attackUnit'
            ));
            expect_same([['kind' => 'attackUnit', 'unitId' => 'a2', 'targetHexes' => ['2,0']]], $attacks, 'only the adjacent friend can strike');
        },

        'wounds are swords minus shields and lethal wounds remove the unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 2, 'hp' => 2]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 2, 3, 4, 6]);
            expect_same(['attackResolved', 'unitDefeated', 'turnEnded'], array_column($result['events'], 'kind'), 'attack, defeat, then the spent turn passes');
            expect_same(
                ['unitId' => 'a1', 'targetUnitId' => 'b1', 'attackFaces' => ['sword', 'sword', 'sword'], 'defenseFaces' => ['shield', 'blank'], 'wounds' => 2],
                $result['events'][0]['payload'],
                'dice faces and wounds are reported'
            );
            expect_same(null, $result['state']['units']['b1']['hex'], 'the defeated unit leaves the board');
            expect_same(2, $result['state']['units']['b1']['wounds'], 'wounds never exceed hit points');
        },

        'destroying the last enemy unit wins by annihilation' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(2)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 1, 'hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 6]);
            expect_same(['attackResolved', 'unitDefeated', 'matchCompleted'], array_column($result['events'], 'kind'), 'the match ends immediately');
            expect_same('completed', $result['state']['phase'], 'the phase is completed');
            expect_same(['reason' => 'annihilation', 'winningSeatId' => 'seat-a'], $result['state']['result'], 'seat-a wins');
            expect_game_error('matchCompleted', static fn() => vanilla_command($result['state'], 'seat-b', 'endTurn'));
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($result['state'], 'seat-a'), 'nothing is legal after victory');
        },

        'ending turns alternates seats and advances the round on the first player' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            $first = vanilla_command($state, 'seat-a', 'endTurn');
            expect_same(['seatId' => 'seat-a', 'nextSeatId' => 'seat-b', 'round' => 1], $first['events'][0]['payload'], 'seat-b follows in round one');
            $second = vanilla_command($first['state'], 'seat-b', 'endTurn');
            expect_same(['seatId' => 'seat-b', 'nextSeatId' => 'seat-a', 'round' => 2], $second['events'][0]['payload'], 'round two begins with the first player');
            expect_same(2, $second['state']['turn']['round'], 'the turn record advances');
        },

        'end activation only accepts the active unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('a2', 'seat-a', '5,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            expect_game_error('unitNotActive', static fn() => vanilla_command($state, 'seat-a', 'endActivation', ['unitId' => 'a2']));
            $ended = vanilla_command($state, 'seat-a', 'endActivation', ['unitId' => 'a1'])['state'];
            expect_same(null, $ended['turn']['activeUnitId'], 'the activation is closed');
            expect_same(['a1'], $ended['turn']['activated'], 'the spent unit stays spent');
        },

        'legal actions list reachable hexes for the active seat only' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $engine = new MatchEngine(test_catalog());
            expect_same(
                [
                    ['kind' => 'moveUnit', 'unitId' => 'a1', 'targetHexes' => ['1,0']],
                    ['kind' => 'endTurn', 'unitId' => null, 'targetHexes' => []],
                ],
                $engine->legalActions($state, 'seat-a'),
                'the active seat sees its moves and end turn'
            );
            expect_same([], $engine->legalActions($state, 'seat-b'), 'the waiting seat sees nothing');
        },
    ];
}
```

In `api/tests/run.php`, add `require __DIR__ . '/VanillaBattleTest.php';` after the draft require, and add `vanilla_battle_tests()` to the `array_merge`.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the new tests FAIL with `Class "BloodAndBanners\Rules\Vanilla\Movement" not found` or `... BattleRules" not found`.

- [ ] **Step 4: Implement movement.** Create `api/src/Rules/Vanilla/Movement.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Movement
{
    public static function reachable(array $state, string $unitId): array
    {
        $unit = $state['units'][$unitId];
        $board = $state['board'];
        $occupants = self::occupants($state);
        $frontier = [$unit['hex']];
        $seen = [$unit['hex'] => true];
        $reachable = [];
        for ($step = 0; $step < $unit['move']; $step++) {
            $next = [];
            foreach ($frontier as $from) {
                foreach (Hex::neighbors($from, $board) as $to) {
                    if (isset($seen[$to]) || $board[$to]['sightBlocker']) {
                        continue;
                    }
                    if (abs($board[$to]['elevation'] - $board[$from]['elevation']) > 1) {
                        continue;
                    }
                    $occupant = $occupants[$to] ?? null;
                    if ($occupant !== null && $occupant !== $unit['seatId']) {
                        continue;
                    }
                    $seen[$to] = true;
                    $next[] = $to;
                    if ($occupant === null) {
                        $reachable[] = $to;
                    }
                }
            }
            $frontier = $next;
        }
        sort($reachable, SORT_STRING);
        return $reachable;
    }

    public static function disengagedFrom(array $state, string $unitId, string $to): array
    {
        $unit = $state['units'][$unitId];
        $board = $state['board'];
        $enemies = [];
        foreach ($state['units'] as $other) {
            if ($other['hex'] === null || $other['seatId'] === $unit['seatId']) {
                continue;
            }
            $engaged = Hex::distance($unit['hex'], $other['hex']) === 1
                && abs($board[$unit['hex']]['elevation'] - $board[$other['hex']]['elevation']) <= 1;
            if ($engaged && Hex::distance($to, $other['hex']) !== 1) {
                $enemies[] = $other['unitId'];
            }
        }
        return $enemies;
    }

    private static function occupants(array $state): array
    {
        $occupants = [];
        foreach ($state['units'] as $unit) {
            if ($unit['hex'] !== null) {
                $occupants[$unit['hex']] = $unit['seatId'];
            }
        }
        return $occupants;
    }
}
```

- [ ] **Step 5: Implement combat.** Create `api/src/Rules/Vanilla/Combat.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\HexLineOfSight;
use BloodAndBanners\Rules\RandomSource;

final class Combat
{
    public static function targetError(array $state, string $attackerId, string $targetId): ?string
    {
        $attacker = $state['units'][$attackerId];
        $target = $state['units'][$targetId];
        if (Hex::distance($attacker['hex'], $target['hex']) > $attacker['range']) {
            return 'targetOutOfRange';
        }
        if (!(new HexLineOfSight())->isVisible($attacker['hex'], $target['hex'], self::sightBoard($state))) {
            return 'noLineOfSight';
        }
        return null;
    }

    public static function targets(array $state, string $attackerId): array
    {
        $attacker = $state['units'][$attackerId];
        $targets = [];
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] === null || $unit['seatId'] === $attacker['seatId']) {
                continue;
            }
            if (self::targetError($state, $attackerId, (string)$unitId) === null) {
                $targets[] = (string)$unitId;
            }
        }
        return $targets;
    }

    public static function attack(array $state, string $attackerId, string $targetId, RandomSource $random): array
    {
        $dice = new CustomDice();
        $attack = $dice->roll($state['units'][$attackerId]['power'], $random);
        $defense = $dice->roll($state['units'][$targetId]['defense'], $random);
        return [
            'attackFaces' => $attack['faces'],
            'defenseFaces' => $defense['faces'],
            'wounds' => max(0, $attack['swords'] - $defense['shields']),
        ];
    }

    public static function disengagementHit(RandomSource $random): array
    {
        $roll = (new CustomDice())->roll(1, $random);
        return ['faces' => $roll['faces'], 'wounds' => $roll['swords']];
    }

    private static function sightBoard(array $state): array
    {
        $board = [];
        foreach ($state['board'] as $key => $hex) {
            $board[(string)$key] = ['elevation' => $hex['elevation'], 'sightBlocker' => $hex['sightBlocker'], 'occupantId' => null];
        }
        foreach ($state['units'] as $unit) {
            if ($unit['hex'] !== null) {
                $board[$unit['hex']]['occupantId'] = $unit['unitId'];
            }
        }
        return $board;
    }
}
```

- [ ] **Step 6: Implement the battle flow.** Create `api/src/Rules/Vanilla/BattleRules.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\RandomSource;

final class BattleRules
{
    private const KINDS = ['moveUnit', 'attackUnit', 'endActivation', 'endTurn'];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'notYourTurn' => 'It is not your turn.',
        'unknownUnit' => 'That unit is not on the battlefield.',
        'notYourUnit' => 'You can only command your own units.',
        'unitAlreadyActivated' => 'That unit has already been activated this turn.',
        'noActivationsRemaining' => 'You have no activations left this turn.',
        'heroActivationRequired' => 'While your hero lives, only three other units may activate each turn.',
        'alreadyMoved' => 'That unit has already moved this activation.',
        'unitNotActive' => 'That unit is not the active unit.',
        'hexUnreachable' => 'That unit cannot reach that hex.',
        'targetOutOfRange' => 'That target is out of range.',
        'noLineOfSight' => 'That target is not in line of sight.',
    ];

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['turn']['activeSeatId'] !== $seatId) {
            self::fail('notYourTurn');
        }
        if ($kind === 'endTurn') {
            [$state, $event] = self::passTurn($state);
            return ['state' => $state, 'events' => [$event]];
        }

        $events = [];
        if ($kind === 'moveUnit') {
            $state = $this->move($state, $seatId, $payload, $random, $events);
        } elseif ($kind === 'attackUnit') {
            $state = $this->attack($state, $seatId, $payload, $random, $events);
        } else {
            $unitId = (string)($payload['unitId'] ?? '');
            if ($state['turn']['activeUnitId'] === null || $state['turn']['activeUnitId'] !== $unitId) {
                self::fail('unitNotActive');
            }
            $state['turn']['activeUnitId'] = null;
        }

        if ($state['phase'] === 'active' && !$this->canAct($state, $seatId)) {
            [$state, $event] = self::passTurn($state);
            $events[] = $event;
        }
        return ['state' => $state, 'events' => $events];
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['phase'] !== 'active' || $state['turn']['activeSeatId'] !== $seatId) {
            return [];
        }
        $actions = [];
        foreach ($state['units'] as $unitId => $unit) {
            $unitId = (string)$unitId;
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null) {
                continue;
            }
            $active = $state['turn']['activeUnitId'] === $unitId;
            if (!$active && $this->activationError($state, $unitId) !== null) {
                continue;
            }
            if (!$active || !$state['turn']['moved']) {
                $hexes = Movement::reachable($state, $unitId);
                if ($hexes !== []) {
                    $actions[] = ['kind' => 'moveUnit', 'unitId' => $unitId, 'targetHexes' => $hexes];
                }
            }
            $targets = Combat::targets($state, $unitId);
            if ($targets !== []) {
                $actions[] = [
                    'kind' => 'attackUnit',
                    'unitId' => $unitId,
                    'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                ];
            }
        }
        if ($state['turn']['activeUnitId'] !== null) {
            $actions[] = ['kind' => 'endActivation', 'unitId' => $state['turn']['activeUnitId'], 'targetHexes' => []];
        }
        $actions[] = ['kind' => 'endTurn', 'unitId' => null, 'targetHexes' => []];
        return $actions;
    }

    private function move(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = (string)($payload['unitId'] ?? '');
        $to = (string)($payload['to'] ?? '');
        $state = $this->activate($state, $seatId, $unitId);
        if ($state['turn']['moved']) {
            self::fail('alreadyMoved');
        }
        if (!in_array($to, Movement::reachable($state, $unitId), true)) {
            self::fail('hexUnreachable');
        }
        $from = $state['units'][$unitId]['hex'];
        $hits = [];
        foreach (Movement::disengagedFrom($state, $unitId, $to) as $enemyId) {
            $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random);
        }
        $state['units'][$unitId]['hex'] = $to;
        $state['turn']['moved'] = true;
        $events[] = MatchEngine::event('unitMoved', [$from, $to], [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'disengagement' => $hits,
        ]);
        return $this->wound($state, $unitId, array_sum(array_column($hits, 'wounds')), $events);
    }

    private function attack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = (string)($payload['unitId'] ?? '');
        $targetId = (string)($payload['targetUnitId'] ?? '');
        $state = $this->activate($state, $seatId, $unitId);
        $target = $state['units'][$targetId] ?? null;
        if ($target === null || $target['hex'] === null || $target['seatId'] === $seatId) {
            self::fail('unknownUnit');
        }
        $blocked = Combat::targetError($state, $unitId, $targetId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $roll = Combat::attack($state, $unitId, $targetId, $random);
        $events[] = MatchEngine::event(
            'attackResolved',
            [$state['units'][$unitId]['hex'], $target['hex']],
            ['unitId' => $unitId, 'targetUnitId' => $targetId] + $roll
        );
        $state['turn']['activeUnitId'] = null;
        return $this->wound($state, $targetId, $roll['wounds'], $events);
    }

    private function activate(array $state, string $seatId, string $unitId): array
    {
        $unit = $state['units'][$unitId] ?? null;
        if ($unit === null || $unit['hex'] === null) {
            self::fail('unknownUnit');
        }
        if ($unit['seatId'] !== $seatId) {
            self::fail('notYourUnit');
        }
        if ($state['turn']['activeUnitId'] === $unitId) {
            return $state;
        }
        $blocked = $this->activationError($state, $unitId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $state['turn']['activeUnitId'] = $unitId;
        $state['turn']['activated'][] = $unitId;
        $state['turn']['moved'] = false;
        return $state;
    }

    private function activationError(array $state, string $unitId): ?string
    {
        $activated = $state['turn']['activated'];
        if (in_array($unitId, $activated, true)) {
            return 'unitAlreadyActivated';
        }
        if (Turn::activationsRemaining($state) <= 0) {
            return 'noActivationsRemaining';
        }
        $unit = $state['units'][$unitId];
        if (!$unit['isHero'] && self::heroAlive($state, $unit['seatId'])) {
            $others = count(array_filter($activated, static fn(string $id): bool => !$state['units'][$id]['isHero']));
            if ($others >= 3) {
                return 'heroActivationRequired';
            }
        }
        return null;
    }

    private function canAct(array $state, string $seatId): bool
    {
        foreach ($this->legalActions($state, $seatId) as $action) {
            if ($action['kind'] === 'moveUnit' || $action['kind'] === 'attackUnit') {
                return true;
            }
        }
        return false;
    }

    private function wound(array $state, string $unitId, int $wounds, array &$events): array
    {
        if ($wounds <= 0) {
            return $state;
        }
        $unit = $state['units'][$unitId];
        $state['units'][$unitId]['wounds'] = min($unit['hp'], $unit['wounds'] + $wounds);
        if ($state['units'][$unitId]['wounds'] < $unit['hp']) {
            return $state;
        }
        $events[] = MatchEngine::event('unitDefeated', [$unit['hex']], ['unitId' => $unitId]);
        $state['units'][$unitId]['hex'] = null;
        if ($state['turn']['activeUnitId'] === $unitId) {
            $state['turn']['activeUnitId'] = null;
        }
        foreach ($state['seats'] as $seatId) {
            if (!self::anyAlive($state, $seatId)) {
                $winner = MatchEngine::otherSeat($state, $seatId);
                $state['phase'] = 'completed';
                $state['result'] = ['reason' => 'annihilation', 'winningSeatId' => $winner];
                $state['turn']['activeUnitId'] = null;
                $events[] = MatchEngine::event('matchCompleted', [], ['winningSeatId' => $winner, 'reason' => 'annihilation']);
                break;
            }
        }
        return $state;
    }

    private static function passTurn(array $state): array
    {
        $current = $state['turn']['activeSeatId'];
        $next = MatchEngine::otherSeat($state, $current);
        $round = $state['turn']['round'] + ($next === $state['draft']['firstSeatId'] ? 1 : 0);
        $state['turn'] = Turn::fresh($state, $next, $round);
        return [$state, MatchEngine::event('turnEnded', [], ['seatId' => $current, 'nextSeatId' => $next, 'round' => $round])];
    }

    private static function heroAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['isHero'] && $unit['hex'] !== null) {
                return true;
            }
        }
        return false;
    }

    private static function anyAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['hex'] !== null) {
                return true;
            }
        }
        return false;
    }

    private static function fail(string $code): never
    {
        throw new GameServiceException($code, self::MESSAGES[$code]);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`. If a battle test fails, use superpowers:systematic-debugging. Do not change the expected values without checking them against the rules in the spec.

- [ ] **Step 8: Commit**

```bash
git add api/src/Rules/Vanilla api/tests
git commit -m "Add Vanilla battle rules for movement combat and victory"
```

---

### Task 6: Wire the engine into `GameService`

**Files:**
- Modify: `api/src/Game/GameService.php`, `api/tests/FoundationTest.php`, `api/tests/ApiTest.php`, `api/tests/run.php`
- Create: `api/tests/VanillaMatchServiceTest.php`

**Interfaces:**
- Consumes: `MatchEngine`, `Board`, `Turn`, `Catalog` (Tasks 2–5).
- Produces:
  - `new GameService(PDO $connection, ?ScenarioService $scenarios = null, ?RandomSource $random = null, ?MatchEngine $engine = null)`
  - Projection fields:
    - `turn.activeUnitId` and `turn.activationsRemaining`
    - `draft`: `null`, or `{initiativeRolls, pickingSeatId, firstSeatId, startBySeat, heroBySeat, armyBySeat, availableHeroes[], availableArmies[]}`. Hero cards are `{name, image, move, range, power, defense, hp, takenBySeatId}`. Army cards are `{name, color, units[], takenBySeatId}`.
    - `units`: a list of `{unitId, seatId, isHero, name, image, move, range, power, defense, hp, wounds, hex, activatedThisTurn}`
    - `result`: `null`, or `{reason, winningSeatId, finalScores}`
    - `board.hexes[].occupantId` is the live unit ID once a match state exists.

- [ ] **Step 1: Write the failing tests.** Create `api/tests/VanillaMatchServiceTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

final class ScriptedRandom implements RandomSource
{
    private int $d20 = 0;

    public function roll(int $sides): int
    {
        if ($sides === 20) {
            return [20, 10][$this->d20++ % 2];
        }
        return 1;
    }
}

function vanilla_service_match(string $scenario = 'Proving Field', string $mode = 'vanillaPvp'): array
{
    [$db, $auth] = test_services();
    $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(test_catalog()));
    $host = $auth->createGuest('vanilla-host-' . bin2hex(random_bytes(4)));
    $rival = $auth->createGuest('vanilla-rival-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], $mode, $scenario);
    $joined = $games->joinGame($created['gameId'], $rival['identityId']);
    $games->setReady($created['gameId'], $host['identityId'], $created['seatToken'], true);
    $games->setReady($created['gameId'], $rival['identityId'], $joined['seatToken'], true);
    return [
        'db' => $db,
        'games' => $games,
        'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']],
        'rival' => ['identity' => $rival['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']],
    ];
}

function vanilla_submit(array $match, string $who, string $kind, array $payload, ?string $commandId = null): array
{
    $current = $match['games']->getGame($match['gameId'], $match[$who]['identity']);
    return $match['games']->submitCommand($match['gameId'], $match[$who]['identity'], $match[$who]['token'], [
        'commandId' => $commandId ?? bin2hex(random_bytes(8)),
        'expectedVersion' => $current['version'],
        'kind' => $kind,
        'payload' => $payload,
    ]);
}

function vanilla_draft_picks(array $match): array
{
    return [
        ['host', 'chooseFirstPlayer', ['seatId' => $match['host']['seat']]],
        ['rival', 'pickHero', ['hero' => 'Test Marshal']],
        ['host', 'chooseStartPosition', ['start' => 1]],
        ['rival', 'pickArmy', ['army' => 'Blue Company']],
        ['host', 'pickHero', ['hero' => 'Test Captain']],
        ['host', 'pickArmy', ['army' => 'Red Company']],
    ];
}

function vanilla_match_service_tests(): array
{
    return [
        'starting a vanilla duel rolls initiative and opens the draft for the winner' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_same('draft', $started['lifecycle'], 'the match enters the draft');
            expect_same($match['host']['seat'], $started['draft']['pickingSeatId'], 'the host rolled 20 and picks first');
            expect_same([20], $started['draft']['initiativeRolls'][$match['host']['seat']], 'rolls are projected per seat');
            expect_same(
                [$match['host']['seat'] => null, $match['rival']['seat'] => null],
                $started['draft']['heroBySeat'],
                'undecided maps always carry both seats'
            );
            expect_same(['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'], array_column($started['legalActions'], 'kind'), 'the picker sees every open decision');
            expect_same(3, count($started['draft']['availableHeroes']), 'the catalog heroes are offered');
            expect_same(null, $started['draft']['availableHeroes'][0]['takenBySeatId'], 'no hero is taken yet');
            expect_same([], $match['games']->getGame($match['gameId'], $match['rival']['identity'])['legalActions'], 'the rival waits');
            $events = $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'];
            expect_same(['gameStarted', 'initiativeRolled'], array_slice(array_column($events, 'kind'), -2), 'start and initiative are logged');
        },

        'only vanilla duels on a large enough map can start' => static function (): void {
            $coop = vanilla_service_match('Portal Watch', 'coop');
            expect_game_error('modeNotPlayable', static fn() => $coop['games']->startGame($coop['gameId'], $coop['host']['identity'], $coop['host']['token']));
            expect_same('lobby', $coop['games']->getGame($coop['gameId'], $coop['host']['identity'])['lifecycle'], 'a refused start leaves the lobby intact');

            $small = vanilla_service_match('Training Grounds');
            expect_game_error('scenarioTooSmall', static fn() => $small['games']->startGame($small['gameId'], $small['host']['identity'], $small['host']['token']));
        },

        'draft commands are validated versioned and idempotent' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $first = vanilla_submit($match, 'host', 'pickHero', ['hero' => 'Test Captain'], 'pick-once');
            expect_same($started['version'] + 1, $first['version'], 'a legal pick advances the version');
            expect_same('Test Captain', $first['draft']['heroBySeat'][$match['host']['seat']], 'the pick is projected');

            $replayed = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => 'pick-once', 'expectedVersion' => $started['version'], 'kind' => 'pickHero', 'payload' => ['hero' => 'Test Captain'],
            ]);
            expect_same($first['version'], $replayed['version'], 'a retried command returns the cached projection');

            try {
                vanilla_submit($match, 'rival', 'pickHero', ['hero' => 'Test Captain']);
                throw new RuntimeException('a taken hero was accepted');
            } catch (\BloodAndBanners\Game\GameServiceException $error) {
                expect_same('heroTaken', $error->errorCode, 'taken heroes are refused');
                expect_same($first['version'], $error->currentVersion, 'refusals report the current version');
            }
            expect_same($first['version'], $match['games']->getGame($match['gameId'], $match['host']['identity'])['version'], 'a refusal changes nothing');
        },

        'a drafted match projects units turn state and battle actions' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                $projection = vanilla_submit($match, $who, $kind, $payload);
            }

            expect_same('active', $projection['lifecycle'], 'the finished draft starts the battle');
            expect_same(null, $projection['draft'], 'the draft block disappears after the draft');
            expect_same(14, count($projection['units']), 'both armies are projected');
            expect_same(
                ['unitId', 'seatId', 'isHero', 'name', 'image', 'move', 'range', 'power', 'defense', 'hp', 'wounds', 'hex', 'activatedThisTurn'],
                array_keys($projection['units'][0]),
                'unit projections match the shared contract'
            );
            expect_same(['round' => 1, 'activeSeatId' => $match['host']['seat'], 'activationsRemaining' => 4, 'activeUnitId' => null], $projection['turn'], 'the host opens round one');
            expect_true(in_array('moveUnit', array_column($projection['legalActions'], 'kind'), true), 'the host may move');
            $occupied = array_values(array_filter($projection['board']['hexes'], static fn(array $hex): bool => $hex['q'] === 0 && $hex['r'] === 4));
            expect_same('s1-hero', $occupied[0]['occupantId'], 'the board shows live occupants');

            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $spectated = $match['games']->getSpectatorGame($link['token']);
            expect_same([], $spectated['legalActions'], 'spectators cannot act');
            expect_same(14, count($spectated['units']), 'spectators still see the armies');
        },
    ];
}
```

Append this test to the array returned by `api_tests()` in `api/tests/ApiTest.php`:

```php
        'two commanders can play a vanilla duel to annihilation over HTTP' => static function (): void {
            [$db, $auth] = test_services();
            $app = new Application($auth, new GameService($db, null, new ScriptedRandom(), new \BloodAndBanners\Rules\Vanilla\MatchEngine(test_catalog())));
            $headers = [];
            foreach (['host', 'rival'] as $who) {
                $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => "duel-{$who}"]));
                $headers[$who] = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            }
            $created = $app->handle(new Request('POST', '/api/v1/games', $headers['host'], ['mode' => 'vanillaPvp', 'scenarioName' => 'Proving Field']));
            $gameId = $created->body['game']['gameId'];
            $headers['host']['x-seat-token'] = $created->body['seatToken'];
            $joined = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/join", $headers['rival']));
            $headers['rival']['x-seat-token'] = $joined->body['seatToken'];
            $seats = ['host' => $created->body['game']['viewer']['seatId'], 'rival' => $joined->body['game']['viewer']['seatId']];

            $send = static function (string $who, string $kind, array $payload) use ($app, $headers, $gameId): array {
                $view = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $headers[$who]))->body;
                $response = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $headers[$who], [
                    'commandId' => bin2hex(random_bytes(8)), 'expectedVersion' => $view['version'], 'kind' => $kind, 'payload' => $payload,
                ]));
                expect_same(200, $response->status, "{$kind} by {$who} should succeed: " . json_encode($response->body));
                return $response->body;
            };
            $send('host', 'setReady', ['ready' => true]);
            $send('rival', 'setReady', ['ready' => true]);
            $started = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/start", $headers['host']));
            expect_same(200, $started->status, 'the host starts the draft');

            $match = ['host' => ['seat' => $seats['host']]];
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                $state = $send($who, $kind, $payload);
            }
            for ($commands = 0; $commands < 600 && $state['lifecycle'] !== 'completed'; $commands++) {
                $who = $state['turn']['activeSeatId'] === $seats['host'] ? 'host' : 'rival';
                $view = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $headers[$who]))->body;
                [$kind, $payload] = vanilla_bot_choice($view);
                $state = $send($who, $kind, $payload);
            }

            expect_same('completed', $state['lifecycle'], 'the scripted duel should finish');
            expect_same('annihilation', $state['result']['reason'], 'the duel ends by annihilation');
            expect_true(in_array($state['result']['winningSeatId'], $seats, true), 'a seated commander wins');
            $events = $app->handle(new Request('GET', "/api/v1/games/{$gameId}/events", $headers['host'], query: ['after' => '0']))->body['events'];
            expect_true(in_array('attackResolved', array_column($events, 'kind'), true), 'attacks are logged');
        },
```

Add this helper after `test_application_with_db()` in `api/tests/ApiTest.php`:

```php
function vanilla_bot_choice(array $view): array
{
    $unitsByHex = [];
    $enemyHexes = [];
    foreach ($view['units'] as $unit) {
        if ($unit['hex'] === null) {
            continue;
        }
        $unitsByHex[$unit['hex']] = $unit['unitId'];
        if ($unit['seatId'] !== $view['viewer']['seatId']) {
            $enemyHexes[] = $unit['hex'];
        }
    }
    foreach ($view['legalActions'] as $action) {
        if ($action['kind'] === 'attackUnit') {
            return ['attackUnit', ['unitId' => $action['unitId'], 'targetUnitId' => $unitsByHex[$action['targetHexes'][0]]]];
        }
    }
    $best = null;
    foreach ($view['legalActions'] as $action) {
        if ($action['kind'] !== 'moveUnit') {
            continue;
        }
        foreach ($action['targetHexes'] as $hex) {
            $distance = min(array_map(static fn(string $enemy): int => \BloodAndBanners\Rules\Vanilla\Hex::distance($hex, $enemy), $enemyHexes));
            if ($best === null || $distance < $best[0]) {
                $best = [$distance, $action['unitId'], $hex];
            }
        }
    }
    return $best === null ? ['endTurn', []] : ['moveUnit', ['unitId' => $best[1], 'to' => $best[2]]];
}
```

In `api/tests/run.php`, add `require __DIR__ . '/VanillaMatchServiceTest.php';` after the battle require, and add `vanilla_match_service_tests()` to the `array_merge`.

In `api/tests/FoundationTest.php`, move the three tests that start a match onto Proving Field:
- `'only the host can start a ready vanilla lobby'` (line 62): `'Training Grounds'` → `'Proving Field'`.
- `'failed start rolls back lifecycle version and snapshot together'` (line 97): `'Training Grounds'` → `'Proving Field'`.
- `'games freeze a compatible immutable scenario revision and project its board'` (line 310): `'Training Grounds'` → `'Proving Field'`. In the same test, change the expectations at lines 313, 314 and 322:

```php
            expect_same(11, $created['board']['columns'], 'selected scenario columns should be projected in the lobby');
            expect_same(99, count($created['board']['hexes']), 'selected scenario hexes should be projected in the lobby');
```
```php
            expect_same(99, count($started['board']['hexes']), 'started match should use the frozen scenario board');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the new service tests FAIL. The likely errors are `Undefined array key "draft"`, or `ArgumentCountError`/`TypeError` from the `GameService` constructor receiving a `RandomSource`.

- [ ] **Step 3: Implement — constructor and imports.** In `api/src/Game/GameService.php`, extend the `use` block:

```php
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\SecureRandomSource;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;
```

Replace the property declaration and constructor:

```php
    private readonly ScenarioService $scenarios;
    private readonly RandomSource $random;
    private readonly MatchEngine $engine;

    public function __construct(
        private readonly PDO $connection,
        ?ScenarioService $scenarios = null,
        ?RandomSource $random = null,
        ?MatchEngine $engine = null,
    ) {
        $this->scenarios = $scenarios ?? new ScenarioService($connection);
        $this->random = $random ?? new SecureRandomSource();
        $this->engine = $engine ?? new MatchEngine(new Catalog());
    }
```

- [ ] **Step 4: Implement — `startGame`.** In `startGame`, replace everything from `$version = (int)$game['version'] + 1;` through `$this->writeSnapshot($gameId, $version, $projection);` with:

```php
            if ($game['mode'] !== 'vanillaPvp') {
                throw new GameServiceException('modeNotPlayable', 'Only Vanilla duels can be played in this version.', (int)$game['version']);
            }
            try {
                $started = $this->engine->start(
                    array_column($memberships, 'id'),
                    Board::fromScenario($this->scenarioDefinition($game)),
                    $this->random
                );
            } catch (GameServiceException $error) {
                throw new GameServiceException($error->errorCode, $error->getMessage(), (int)$game['version']);
            }
            $version = (int)$game['version'] + 1;
            $this->connection->prepare(
                'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated WHERE id = :id'
            )->execute(['lifecycle' => 'draft', 'version' => $version, 'updated' => self::now(), 'id' => $gameId]);
            $this->saveState($gameId, $version, $started['state']);
            $projection = $this->projection($gameId, $identityId);
            $this->appendEvent($gameId, $version, 'gameStarted', $identityId, ['lifecycle' => 'draft']);
            foreach ($started['events'] as $event) {
                $this->appendEvent($gameId, $version, $event['kind'], $identityId, $event['payload'], $event['focusHexes']);
            }
            $this->writeSnapshot($gameId, $version, $projection);
```

- [ ] **Step 5: Implement — `submitCommand` dispatch.** In `submitCommand`, change the credential line to keep the membership:

```php
            $membership = $this->requireSeatCredential($gameId, $identityId, $seatToken);
```

Then replace everything from `if ($command['kind'] !== 'setReady' || ...` through `$response = $this->projection($gameId, $identityId);` with:

```php
            $newVersion = $currentVersion + 1;
            if ($command['kind'] === 'setReady') {
                if (!is_bool($command['payload']['ready'] ?? null)) {
                    throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
                }
                if ($game['lifecycle'] !== 'lobby') {
                    throw new GameServiceException('illegalLifecycle', 'Readiness can only change in the lobby.', $currentVersion);
                }
                $this->connection->prepare(
                    'UPDATE game_memberships SET ready = :ready WHERE game_id = :game AND identity_id = :identity'
                )->execute(['ready' => $command['payload']['ready'] ? 1 : 0, 'game' => $gameId, 'identity' => $identityId]);
                $this->connection->prepare('UPDATE games SET version = :version, updated_at = :updated WHERE id = :id')
                    ->execute(['version' => $newVersion, 'updated' => self::now(), 'id' => $gameId]);
                $this->appendEvent($gameId, $newVersion, 'readinessChanged', $identityId, ['ready' => $command['payload']['ready']]);
            } else {
                $this->applyMatchCommand($game, (string)$membership['id'], $identityId, $command, $newVersion);
            }
            $response = $this->projection($gameId, $identityId);
```

Add this private method after `submitCommand`:

```php
    private function applyMatchCommand(array $game, string $seatId, string $identityId, array $command, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $state = $this->loadState($game['id']);
        if ($state === null || !in_array($game['lifecycle'], ['draft', 'active', 'completed'], true)) {
            throw new GameServiceException('illegalLifecycle', 'This command is not legal in the current game phase.', $currentVersion);
        }
        try {
            $result = $this->engine->apply(
                $state,
                $seatId,
                (string)$command['kind'],
                is_array($command['payload']) ? $command['payload'] : [],
                $this->random
            );
        } catch (GameServiceException $error) {
            throw new GameServiceException($error->errorCode, $error->getMessage(), $currentVersion);
        }
        $this->connection->prepare(
            'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated WHERE id = :id'
        )->execute(['lifecycle' => $result['state']['phase'], 'version' => $newVersion, 'updated' => self::now(), 'id' => $game['id']]);
        $this->saveState($game['id'], $newVersion, $result['state']);
        foreach ($result['events'] as $event) {
            $this->appendEvent($game['id'], $newVersion, $event['kind'], $identityId, $event['payload'], $event['focusHexes']);
        }
    }
```

- [ ] **Step 6: Implement — projections.** Replace the private `projection()` and `boardProjection()` methods with the code below. Then, in `getSpectatorGame`, replace everything after `$game = $this->gameRow($gameId);` with:

```php
        return $this->buildProjection($game, $this->memberships($gameId), [
            'role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false,
        ]);
```

New projection methods:

```php
    private function projection(string $gameId, string $identityId): array
    {
        $game = $this->gameRow($gameId);
        $memberships = $this->memberships($gameId);
        $viewerSeat = null;
        foreach ($memberships as $membership) {
            if ($membership['identity_id'] === $identityId) {
                $viewerSeat = $membership;
            }
        }
        return $this->buildProjection($game, $memberships, [
            'role' => 'player',
            'seatId' => $viewerSeat['id'] ?? null,
            'isHost' => $game['host_identity_id'] === $identityId,
            'canCommand' => true,
        ]);
    }

    private function buildProjection(array $game, array $memberships, array $viewer): array
    {
        $state = $this->loadState($game['id']);
        $scores = [];
        foreach ($memberships as $membership) {
            $scores[$membership['id']] = 0;
        }
        $legalActions = match (true) {
            $viewer['role'] !== 'player' => [],
            $game['lifecycle'] === 'lobby' => [['kind' => 'setReady', 'unitId' => null, 'targetHexes' => []]],
            $state !== null => $this->engine->legalActions($state, $viewer['seatId']),
            default => [],
        };
        return [
            'gameId' => $game['id'],
            'version' => (int)$game['version'],
            'lifecycle' => $game['lifecycle'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'viewer' => $viewer,
            'board' => $this->boardProjection($game, $state),
            'turn' => [
                'round' => $state['turn']['round'] ?? 0,
                'activeSeatId' => $state['turn']['activeSeatId'] ?? null,
                'activationsRemaining' => $state === null ? 0 : Turn::activationsRemaining($state),
                'activeUnitId' => $state['turn']['activeUnitId'] ?? null,
            ],
            'pendingChoice' => null,
            'legalActions' => $legalActions,
            'scores' => $scores,
            'result' => ($state['result'] ?? null) === null ? null : $state['result'] + ['finalScores' => $scores],
            'memberships' => array_map(static fn(array $seat): array => [
                'seatId' => $seat['id'],
                'seatIndex' => (int)$seat['seat_index'],
                'ready' => (bool)$seat['ready'],
                'isHost' => (bool)$seat['is_host'],
            ], $memberships),
            'draft' => $state !== null && $state['phase'] === 'draft' ? $this->draftProjection($state) : null,
            'units' => $state === null ? [] : array_values(array_map(static fn(array $unit): array => [
                'unitId' => $unit['unitId'],
                'seatId' => $unit['seatId'],
                'isHero' => $unit['isHero'],
                'name' => $unit['name'],
                'image' => $unit['image'],
                'move' => $unit['move'],
                'range' => $unit['range'],
                'power' => $unit['power'],
                'defense' => $unit['defense'],
                'hp' => $unit['hp'],
                'wounds' => $unit['wounds'],
                'hex' => $unit['hex'],
                'activatedThisTurn' => in_array($unit['unitId'], $state['turn']['activated'], true),
            ], $state['units'])),
        ];
    }

    private function draftProjection(array $state): array
    {
        $draft = $state['draft'];
        $takenBy = static function (array $claims, string $name): ?string {
            $seat = array_search($name, $claims, true);
            return $seat === false ? null : (string)$seat;
        };
        $catalog = $this->engine->catalog();
        return [
            'initiativeRolls' => $state['initiative']['rolls'],
            'pickingSeatId' => $draft['pickingSeatId'],
            'firstSeatId' => $draft['firstSeatId'],
            'startBySeat' => $draft['startBySeat'],
            'heroBySeat' => $draft['heroBySeat'],
            'armyBySeat' => $draft['armyBySeat'],
            'availableHeroes' => array_map(
                static fn(array $hero): array => $hero + ['takenBySeatId' => $takenBy($draft['heroBySeat'], $hero['name'])],
                $catalog->heroes()
            ),
            'availableArmies' => array_map(
                static fn(array $army): array => $army + ['takenBySeatId' => $takenBy($draft['armyBySeat'], $army['name'])],
                $catalog->armies()
            ),
        ];
    }

    private function boardProjection(array $game, ?array $state): array
    {
        $definition = $this->scenarioDefinition($game);
        $occupants = [];
        foreach ($state['units'] ?? [] as $unit) {
            if ($unit['hex'] !== null) {
                $occupants[$unit['hex']] = $unit['unitId'];
            }
        }
        return [
            'columns' => (int)$definition['map']['columns'],
            'rows' => (int)$definition['map']['rows'],
            'hexes' => array_map(static fn(array $hex): array => [
                'q' => (int)$hex['q'],
                'r' => (int)$hex['r'],
                'biome' => (string)$hex['biome'],
                'terrain' => (string)$hex['terrain'],
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => (bool)($hex['sightBlocker'] ?? false),
                'occupantId' => $state !== null
                    ? ($occupants["{$hex['q']},{$hex['r']}"] ?? null)
                    : (isset($hex['piece']) ? (string)$hex['piece'] : null),
            ], $definition['map']['hexes']),
        ];
    }

    private function scenarioDefinition(array $game): array
    {
        $revisionId = (string)($game['scenario_revision_id'] ?? '');
        if ($revisionId !== '') {
            return $this->scenarios->getRevision($revisionId);
        }
        $scenario = $this->scenarios->resolveBuiltInRevision($game['scenario_name'], $game['mode']);
        $this->connection->prepare('UPDATE games SET scenario_revision_id = :revision WHERE id = :game')
            ->execute(['revision' => $scenario['revisionId'], 'game' => $game['id']]);
        return $scenario['definition'];
    }
```

- [ ] **Step 7: Implement — state storage and event focus.** Replace `appendEvent` with this version, which takes focus hexes:

```php
    private function appendEvent(string $gameId, int $version, string $kind, string $actor, array $payload, array $focusHexes = []): void
    {
        $sequence = (int)$this->scalar(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM game_events WHERE game_id = :game',
            ['game' => $gameId]
        );
        $this->connection->prepare(
            'INSERT INTO game_events
             (game_id, sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at)
             VALUES (:game, :sequence, :version, :kind, :actor, :focus, :payload, :created)'
        )->execute([
            'game' => $gameId, 'sequence' => $sequence, 'version' => $version, 'kind' => $kind, 'actor' => $actor,
            'focus' => json_encode(array_values($focusHexes), JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created' => self::now(),
        ]);
    }
```

Add these after `writeSnapshot`:

```php
    private function loadState(string $gameId): ?array
    {
        $json = $this->scalar('SELECT state_json FROM game_states WHERE game_id = :game', ['game' => $gameId]);
        return $json === false ? null : json_decode((string)$json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function saveState(string $gameId, int $version, array $state): void
    {
        $sql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO game_states (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON DUPLICATE KEY UPDATE version = VALUES(version), state_json = VALUES(state_json), updated_at = VALUES(updated_at)'
            : 'INSERT INTO game_states (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON CONFLICT(game_id) DO UPDATE SET version = excluded.version, state_json = excluded.state_json, updated_at = excluded.updated_at';
        $this->connection->prepare($sql)->execute([
            'game' => $gameId, 'version' => $version,
            'state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated' => self::now(),
        ]);
    }
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all PASS, `0 failures`. The HTTP duel test has a 600-command cap. If it reports `the scripted duel should finish`, debug with superpowers:systematic-debugging: print the turn and legal actions on the last few iterations. Do not raise the cap blindly.

- [ ] **Step 9: Check that the local launcher still works**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1`
Expected: the script reports success.

- [ ] **Step 10: Commit**

```bash
git add api/src/Game/GameService.php api/tests
git commit -m "Run Vanilla draft and battle commands through the game service"
```

---

### Task 7: Shared contracts and fixtures

**Files:**
- Create: `src/BloodAndBanners.Contracts/MatchProjection.cs`, `tests/fixtures/draft-projection.json`, `tests/fixtures/unit-projection.json`
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs`, `tests/fixtures/game-projection.json`, `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`

**Interfaces:**
- Produces (C#, namespace `BloodAndBanners.Contracts`):
  - `UnitCard(string Name, string Image, int Move, int Range, int Power, int Defense, int Hp)`
  - `HeroCard(string Name, string Image, int Move, int Range, int Power, int Defense, int Hp, string? TakenBySeatId)`
  - `ArmyCard(string Name, string Color, IReadOnlyList<UnitCard> Units, string? TakenBySeatId)`
  - `DraftProjection(IReadOnlyDictionary<string, IReadOnlyList<int>> InitiativeRolls, string? PickingSeatId, string? FirstSeatId, IReadOnlyDictionary<string, int?> StartBySeat, IReadOnlyDictionary<string, string?> HeroBySeat, IReadOnlyDictionary<string, string?> ArmyBySeat, IReadOnlyList<HeroCard> AvailableHeroes, IReadOnlyList<ArmyCard> AvailableArmies)`
  - `UnitProjection(string UnitId, string SeatId, bool IsHero, string Name, string Image, int Move, int Range, int Power, int Defense, int Hp, int Wounds, string? Hex, bool ActivatedThisTurn)`
  - `GameProjection.Draft { get; init; }` (`DraftProjection?`) and `GameProjection.Units { get; init; }` (`IReadOnlyList<UnitProjection>`, default `[]`)
  - `TurnProjection.ActiveUnitId { get; init; }` (`string?`)

- [ ] **Step 1: Write the failing tests.** Create `tests/fixtures/draft-projection.json`:

```json
{
  "initiativeRolls": { "seat-red": [12, 18], "seat-blue": [12, 4] },
  "pickingSeatId": "seat-blue",
  "firstSeatId": null,
  "startBySeat": { "seat-red": 1, "seat-blue": 2 },
  "heroBySeat": { "seat-red": "Thorgrim Shattershield", "seat-blue": null },
  "armyBySeat": { "seat-red": null, "seat-blue": null },
  "availableHeroes": [
    { "name": "Thorgrim Shattershield", "image": "/images/heroes/Thorgrim Shattershield_card.png", "move": 3, "range": 1, "power": 5, "defense": 5, "hp": 7, "takenBySeatId": "seat-red" }
  ],
  "availableArmies": [
    {
      "name": "Blackmaw", "color": "Black", "takenBySeatId": null,
      "units": [ { "name": "Grunt", "image": "/images/units/black_grunt_card.png", "move": 2, "range": 1, "power": 4, "defense": 3, "hp": 5 } ]
    }
  ]
}
```

Create `tests/fixtures/unit-projection.json`:

```json
{
  "unitId": "s1-u3",
  "seatId": "seat-red",
  "isHero": false,
  "name": "Ranger",
  "image": "/images/units/gold_ranger_card.png",
  "move": 3,
  "range": 6,
  "power": 3,
  "defense": 2,
  "hp": 4,
  "wounds": 1,
  "hex": "2,4",
  "activatedThisTurn": true
}
```

In `tests/fixtures/game-projection.json`, replace the `"turn"` object and add a `"units"` array after `"memberships"`:

```json
  "turn": {
    "round": 2,
    "activeSeatId": "seat-red",
    "activationsRemaining": 3,
    "activeUnitId": "unit-thorgrim"
  },
```
```json
  "units": [
    {
      "unitId": "unit-thorgrim", "seatId": "seat-red", "isHero": true, "name": "Thorgrim Shattershield",
      "image": "/images/heroes/Thorgrim Shattershield_card.png", "move": 3, "range": 1, "power": 5,
      "defense": 5, "hp": 7, "wounds": 2, "hex": "0,0", "activatedThisTurn": true
    }
  ],
```

In `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`, add these assertions to `GameProjection_fixture_round_trips_with_camel_case_contract` before the `using var serialized` line:

```csharp
        Assert.Equal("unit-thorgrim", projection.Turn.ActiveUnitId);
        Assert.Null(projection.Draft);
        Assert.True(projection.Units.Single().IsHero);
        Assert.Equal(2, projection.Units.Single().Wounds);
```

Then add these tests before `FixturePath`:

```csharp
    [Fact]
    public void Draft_fixture_carries_rolls_open_claims_and_catalog_cards()
    {
        var draft = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.DraftProjection>(
            File.ReadAllText(FixturePath("draft-projection.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(draft);
        Assert.Equal(new[] { 12, 18 }, draft.InitiativeRolls["seat-red"]);
        Assert.Equal("seat-blue", draft.PickingSeatId);
        Assert.Null(draft.FirstSeatId);
        Assert.Equal(2, draft.StartBySeat["seat-blue"]);
        Assert.Null(draft.HeroBySeat["seat-blue"]);
        Assert.Equal("seat-red", draft.AvailableHeroes.Single().TakenBySeatId);
        Assert.Equal("Grunt", draft.AvailableArmies.Single().Units.Single().Name);
    }

    [Fact]
    public void Unit_fixture_carries_live_battle_state()
    {
        var unit = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.UnitProjection>(
            File.ReadAllText(FixturePath("unit-projection.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(unit);
        Assert.Equal("s1-u3", unit.UnitId);
        Assert.Equal(6, unit.Range);
        Assert.Equal("2,4", unit.Hex);
        Assert.True(unit.ActivatedThisTurn);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
Expected: build FAILS with `CS0234: The type or namespace name 'DraftProjection' does not exist` and `'TurnProjection' does not contain a definition for 'ActiveUnitId'`.

- [ ] **Step 3: Implement.** Create `src/BloodAndBanners.Contracts/MatchProjection.cs`:

```csharp
namespace BloodAndBanners.Contracts;

public sealed record UnitCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp);

public sealed record HeroCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp,
    string? TakenBySeatId);

public sealed record ArmyCard(
    string Name,
    string Color,
    IReadOnlyList<UnitCard> Units,
    string? TakenBySeatId);

public sealed record DraftProjection(
    IReadOnlyDictionary<string, IReadOnlyList<int>> InitiativeRolls,
    string? PickingSeatId,
    string? FirstSeatId,
    IReadOnlyDictionary<string, int?> StartBySeat,
    IReadOnlyDictionary<string, string?> HeroBySeat,
    IReadOnlyDictionary<string, string?> ArmyBySeat,
    IReadOnlyList<HeroCard> AvailableHeroes,
    IReadOnlyList<ArmyCard> AvailableArmies);

public sealed record UnitProjection(
    string UnitId,
    string SeatId,
    bool IsHero,
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp,
    int Wounds,
    string? Hex,
    bool ActivatedThisTurn);
```

In `src/BloodAndBanners.Contracts/GameProjection.cs`, replace `TurnProjection`:

```csharp
public sealed record TurnProjection(
    int Round,
    string? ActiveSeatId,
    int ActivationsRemaining)
{
    public string? ActiveUnitId { get; init; }
}
```

and extend the body of `GameProjection`:

```csharp
{
    public string ScenarioName { get; init; } = string.Empty;
    public IReadOnlyList<MembershipProjection> Memberships { get; init; } = [];
    public DraftProjection? Draft { get; init; }
    public IReadOnlyList<UnitProjection> Units { get; init; } = [];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts tests/fixtures tests/BloodAndBanners.Contracts.Tests
git commit -m "Add draft and unit projections to shared contracts"
```

---

### Task 8: Client services — API errors, events and generic commands

**Files:**
- Create: `src/BloodAndBanners.UI/Services/GameApiException.cs`, `src/BloodAndBanners.UI/Services/GameCommandRequest.cs`
- Modify: `src/BloodAndBanners.UI/Services/IGameApiClient.cs`, `GameApiClient.cs`, `IGameSessionCoordinator.cs`, `GameSessionCoordinator.cs`; `tests/BloodAndBanners.UI.Tests/GameSessionCoordinatorTests.cs`, `GameClientTests.cs`, `GameExperienceTests.cs`

**Interfaces:**
- Produces:
  - `GameApiException(HttpStatusCode statusCode, string code, string message) : HttpRequestException`, with `string Code`
  - `GameCommandRequest(string Kind, object Payload)`
  - `IGameApiClient.GetEventsAsync(AuthSession session, string gameId, string seatToken, long afterSequence, CancellationToken cancellationToken = default): Task<EventPage>`
  - `IGameSessionCoordinator.SubmitCommandAsync(long expectedVersion, string kind, object payload, CancellationToken cancellationToken = default): Task<GameProjection>`
  - `IGameSessionCoordinator.GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default): Task<EventPage>`
  - `IGameSessionCoordinator.LeaveAsync(): Task`

- [ ] **Step 1: Write the failing tests.** In `tests/BloodAndBanners.UI.Tests/GameSessionCoordinatorTests.cs`, add before `private static GameProjection Projection`:

```csharp
    [Fact]
    public async Task Battle_commands_build_an_envelope_from_the_kind_payload_and_version()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("device-1", identity, "game-1", "seat-secret") };
        var moved = Projection("game-1") with { Version = 13 };
        var api = new FakeGameApiClient { Commanded = moved };
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.SubmitCommandAsync(12, "moveUnit", new { unitId = "s1-u2", to = "3,4" });

        Assert.Same(moved, result);
        Assert.Equal(12, api.CommandRequest?.Command.ExpectedVersion);
        Assert.Equal("moveUnit", api.CommandRequest?.Command.Kind);
        Assert.Equal("3,4", api.CommandRequest?.Command.Payload.GetProperty("to").GetString());
        Assert.Equal(32, api.CommandRequest?.Command.CommandId.Length);
    }

    [Fact]
    public async Task Events_use_the_saved_seat_and_leaving_clears_the_session()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("device-1", identity, "game-1", "seat-secret") };
        var api = new FakeGameApiClient { Events = new EventPage([], 9) };
        var coordinator = new GameSessionCoordinator(api, store);

        var page = await coordinator.GetEventsAsync(4);
        await coordinator.LeaveAsync();

        Assert.Equal(9, page.LastSequence);
        Assert.Equal((identity, "game-1", "seat-secret", 4L), api.EventsRequest);
        Assert.Null(store.Current);
    }
```

In the same file's `FakeGameApiClient`, add these members:

```csharp
        public EventPage? Events { get; init; }
        public (AuthSession Identity, string GameId, string SeatToken, long After)? EventsRequest { get; private set; }

        public Task<EventPage> GetEventsAsync(
            AuthSession session,
            string gameId,
            string seatToken,
            long afterSequence,
            CancellationToken cancellationToken = default)
        {
            EventsRequest = (session, gameId, seatToken, afterSequence);
            return Task.FromResult(Events ?? throw new InvalidOperationException("Events response was not configured."));
        }
```

Check `MemoryGameSessionStore.ClearAsync` (around line 204). If it does not set `Current = null`, make it do so:

```csharp
        public ValueTask ClearAsync()
        {
            Current = null;
            return ValueTask.CompletedTask;
        }
```

In `tests/BloodAndBanners.UI.Tests/GameClientTests.cs`, add these tests before the `QueueHandler` class:

```csharp
    [Fact]
    public async Task Events_are_polled_after_a_sequence_with_the_seat_credential()
    {
        const string page = """
            {"events":[{"sequence":5,"version":9,"kind":"attackResolved","actingSeat":"guest-1","focusHexes":["1,0","2,0"],
              "payload":{"wounds":2},"timestamp":"2026-10-11T12:00:00Z"}],"lastSequence":5}
            """;
        var handler = new QueueHandler(page);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var events = await client.GetEventsAsync(new AuthSession("guest-1", "guest", "access-1", "refresh-1"), "game-1", "seat-secret", 4);

        Assert.EndsWith("api/v1/games/game-1/events?after=4", handler.Requests[0].RequestUri?.ToString());
        Assert.Equal("seat-secret", handler.Requests[0].Headers.GetValues("X-Seat-Token").Single());
        Assert.Equal(new[] { "1,0", "2,0" }, events.Events.Single().FocusHexes);
        Assert.Equal(5, events.LastSequence);
    }

    [Fact]
    public async Task Rejected_commands_surface_the_server_error_code_and_message()
    {
        const string error = """{"error":{"code":"heroTaken","message":"Another commander has already claimed that hero.","currentVersion":8}}""";
        var handler = new StatusHandler(HttpStatusCode.UnprocessableEntity, error);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });
        var command = new CommandEnvelope("c-1", 8, "pickHero", System.Text.Json.JsonSerializer.SerializeToElement(new { hero = "X" }));

        var failure = await Assert.ThrowsAsync<GameApiException>(() =>
            client.SubmitCommandAsync(new AuthSession("guest-1", "guest", "access-1", "refresh-1"), "game-1", "seat-secret", command));

        Assert.Equal(HttpStatusCode.UnprocessableEntity, failure.StatusCode);
        Assert.Equal("heroTaken", failure.Code);
        Assert.Equal("Another commander has already claimed that hero.", failure.Message);
    }

    private sealed class StatusHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") });
    }
```

In `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`, add these members to `FakeSessionCoordinator` so it still compiles:

```csharp
        public GameProjection? Commanded { get; init; }
        public Exception? CommandFailure { get; init; }
        public (long Version, string Kind, object Payload)? CommandRequest { get; private set; }
        public EventPage Events { get; set; } = new([], 0);
        public bool Left { get; private set; }

        public Task<GameProjection> SubmitCommandAsync(long expectedVersion, string kind, object payload, CancellationToken cancellationToken = default)
        {
            CommandRequest = (expectedVersion, kind, payload);
            if (CommandFailure is not null)
            {
                return Task.FromException<GameProjection>(CommandFailure);
            }
            return Task.FromResult(Commanded ?? throw new InvalidOperationException());
        }

        public Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default) =>
            Task.FromResult(Events);

        public Task LeaveAsync()
        {
            Left = true;
            return Task.CompletedTask;
        }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: build FAILS with `'GameSessionCoordinator' does not contain a definition for 'SubmitCommandAsync'` and `The type or namespace name 'GameApiException' could not be found`.

- [ ] **Step 3: Implement.** Create `src/BloodAndBanners.UI/Services/GameApiException.cs`:

```csharp
using System.Net;

namespace BloodAndBanners.UI.Services;

public sealed class GameApiException(HttpStatusCode statusCode, string code, string message)
    : HttpRequestException(message, null, statusCode)
{
    public string Code { get; } = code;
}
```

Create `src/BloodAndBanners.UI/Services/GameCommandRequest.cs`:

```csharp
namespace BloodAndBanners.UI.Services;

public sealed record GameCommandRequest(string Kind, object Payload);
```

In `IGameApiClient.cs`, add:

```csharp
    Task<EventPage> GetEventsAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        long afterSequence,
        CancellationToken cancellationToken = default);
```

In `GameApiClient.cs`, add this method after `StartGameAsync`:

```csharp
    public async Task<EventPage> GetEventsAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        long afterSequence,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(
            HttpMethod.Get,
            $"api/v1/games/{gameId}/events?after={afterSequence}",
            session,
            seatToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<EventPage>(response, cancellationToken);
    }
```

and replace `ReadAsync` with:

```csharp
    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (!response.IsSuccessStatusCode)
        {
            throw await ErrorAsync(response, cancellationToken);
        }
        await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        return await JsonSerializer.DeserializeAsync<T>(stream, ContractJson.Options, cancellationToken)
            ?? throw new JsonException("The API returned an empty JSON response.");
    }

    private static async Task<GameApiException> ErrorAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        ApiError? error = null;
        try
        {
            error = (await response.Content.ReadFromJsonAsync<ApiErrorResponse>(ContractJson.Options, cancellationToken))?.Error;
        }
        catch (JsonException)
        {
        }
        return new GameApiException(
            response.StatusCode,
            error?.Code ?? "httpError",
            error?.Message ?? $"The server returned HTTP {(int)response.StatusCode}.");
    }
```

In `IGameSessionCoordinator.cs`, add:

```csharp
    Task<GameProjection> SubmitCommandAsync(long expectedVersion, string kind, object payload, CancellationToken cancellationToken = default);
    Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default);
    Task LeaveAsync();
```

In `GameSessionCoordinator.cs`, replace `SetReadyAsync` with these members:

```csharp
    public Task<GameProjection> SetReadyAsync(
        long expectedVersion,
        bool ready,
        CancellationToken cancellationToken = default) =>
        SubmitCommandAsync(expectedVersion, "setReady", new { ready }, cancellationToken);

    public async Task<GameProjection> SubmitCommandAsync(
        long expectedVersion,
        string kind,
        object payload,
        CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        var command = new CommandEnvelope(
            Guid.NewGuid().ToString("N"),
            expectedVersion,
            kind,
            System.Text.Json.JsonSerializer.SerializeToElement(payload, payload.GetType(), ContractJson.Options));
        return await api.SubmitCommandAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            command,
            cancellationToken);
    }

    public async Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        return await api.GetEventsAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            afterSequence,
            cancellationToken);
    }

    public async Task LeaveAsync() => await store.ClearAsync();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: all tests pass, including the existing token-refresh test (`GameApiException` is still an `HttpRequestException` carrying `StatusCode`).

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI/Services tests/BloodAndBanners.UI.Tests
git commit -m "Add event polling generic commands and structured API errors to the client"
```

---

### Task 9: Draft screen and match routing

**Files:**
- Create: `src/BloodAndBanners.UI/Services/CatalogImages.cs`, `src/BloodAndBanners.UI/Components/DraftTable.razor`, `src/BloodAndBanners.UI/Components/DraftTable.razor.css`, `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs`
- Modify: `src/BloodAndBanners.UI/Components/GameExperience.razor`, `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`, `src/BloodAndBanners.Web/Pages/Home.razor`, `src/BloodAndBanners.Web/wwwroot/appsettings.Development.json`, `src/BloodAndBanners.Maui/Components/Pages/Home.razor`

**Interfaces:**
- Consumes: `GameCommandRequest`, `IGameSessionCoordinator.SubmitCommandAsync/GetEventsAsync/LeaveAsync`, `GameApiException` (Task 8); `DraftProjection` (Task 7).
- Produces:
  - `CatalogImages.Resolve(string? baseUrl, string? path): string?`
  - `<DraftTable Projection IsBusy ErrorMessage ImageBaseUrl OnCommand />`
  - `GameExperience` gains the parameter `ImageBaseUrl` and the private fields `_events` (`List<GameEvent>`) and `_lastSequence`, which Task 10 uses.
  - Vanilla duels default to `"Proving Field"`.

- [ ] **Step 1: Write the failing tests.** Create `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class DraftTableTests : BunitContext
{
    [Fact]
    public void The_picking_commander_can_claim_open_heroes_and_sees_taken_ones_locked()
    {
        GameCommandRequest? sent = null;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red"))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        Assert.Equal("Your pick", cut.Find("[data-testid=draft-status]").TextContent.Trim());
        Assert.True(cut.Find("button[data-hero='Malachar the Voidcaller']").HasAttribute("disabled"));
        Assert.Contains("Rival", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);

        cut.Find("button[data-hero='Thorgrim Shattershield']").Click();

        Assert.Equal("pickHero", sent?.Kind);
        Assert.Equal("Thorgrim Shattershield", JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("hero").GetString());
    }

    [Fact]
    public void A_waiting_commander_cannot_pick_anything()
    {
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-blue", picking: "seat-red") with { LegalActions = [] }));

        Assert.Equal("Waiting for the opposing commander", cut.Find("[data-testid=draft-status]").TextContent.Trim());
        Assert.All(cut.FindAll("button[data-action]"), button => Assert.True(button.HasAttribute("disabled")));
    }

    [Fact]
    public void First_player_and_start_choices_send_their_values()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red"))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("button[data-action=chooseFirstPlayer][data-seat=seat-blue]").Click();
        cut.Find("button[data-action=chooseStartPosition][data-start='2']").Click();

        Assert.Equal("seat-blue", JsonSerializer.SerializeToElement(sent[0].Payload).GetProperty("seatId").GetString());
        Assert.Equal(2, JsonSerializer.SerializeToElement(sent[1].Payload).GetProperty("start").GetInt32());
        Assert.Contains("won initiative", cut.Find("[data-testid=initiative]").TextContent);
    }

    [Fact]
    public void Catalog_images_resolve_against_a_configured_base_and_escape_spaces()
    {
        Assert.Equal(
            "https://site.test/blood/public/images/heroes/Thorgrim%20Shattershield_card.png",
            CatalogImages.Resolve("https://site.test/blood/public/", "/images/heroes/Thorgrim Shattershield_card.png"));
        Assert.Null(CatalogImages.Resolve(null, "/images/heroes/x.png"));
    }
}

internal static class DraftFixtures
{
    public static GameProjection Projection(string viewerSeat, string picking) => new(
        "game-fixture",
        9,
        GameLifecycle.Draft,
        GameMode.VanillaPvp,
        new ViewerPermissions("player", viewerSeat, true, viewerSeat == "seat-red"),
        new BoardProjection(11, 9, []),
        new TurnProjection(0, null, 0),
        null,
        [
            new LegalAction("chooseFirstPlayer", null, []),
            new LegalAction("chooseStartPosition", null, []),
            new LegalAction("pickHero", null, []),
            new LegalAction("pickArmy", null, []),
        ],
        new Dictionary<string, int> { ["seat-red"] = 0, ["seat-blue"] = 0 },
        null)
    {
        ScenarioName = "Proving Field",
        Memberships = [
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, true, false),
        ],
        Draft = new DraftProjection(
            new Dictionary<string, IReadOnlyList<int>> { ["seat-red"] = [18], ["seat-blue"] = [4] },
            picking,
            null,
            new Dictionary<string, int?> { ["seat-red"] = null, ["seat-blue"] = null },
            new Dictionary<string, string?> { ["seat-red"] = null, ["seat-blue"] = "Malachar the Voidcaller" },
            new Dictionary<string, string?> { ["seat-red"] = null, ["seat-blue"] = null },
            [
                new HeroCard("Thorgrim Shattershield", "/images/heroes/Thorgrim Shattershield_card.png", 3, 1, 5, 5, 7, null),
                new HeroCard("Malachar the Voidcaller", "/images/heroes/Malachar the Voidcaller_card.png", 4, 5, 4, 3, 6, "seat-blue"),
            ],
            [
                new ArmyCard("Blackmaw", "Black", [new UnitCard("Grunt", "/images/units/black_grunt_card.png", 2, 1, 4, 3, 5)], null),
            ])
    };
}
```

In `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`, add these tests to the class (they use `DraftFixtures` from the file above):

```csharp
    [Fact]
    public void Draft_projection_opens_the_draft_table_and_sends_picks_at_the_visible_version()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator { Reconnected = draft, Commanded = draft with { Version = 10 } };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));

        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() =>
        {
            Assert.Equal(9, api.CommandRequest?.Version);
            Assert.Equal("pickHero", api.CommandRequest?.Kind);
        });
    }

    [Fact]
    public void A_version_conflict_tells_the_commander_to_try_again()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator
        {
            Reconnected = draft,
            CommandFailure = new GameApiException(System.Net.HttpStatusCode.Conflict, "versionConflict", "The game changed before this command arrived.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() => Assert.Contains("The battlefield changed — try again.", cut.Markup));
    }

    [Fact]
    public void A_rejected_order_shows_the_server_reason()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator
        {
            Reconnected = draft,
            CommandFailure = new GameApiException(System.Net.HttpStatusCode.UnprocessableEntity, "heroTaken", "Another commander has already claimed that hero.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() => Assert.Contains("Another commander has already claimed that hero.", cut.Markup));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: build FAILS with `The type or namespace name 'DraftTable' could not be found` and `'CatalogImages' does not exist`.

- [ ] **Step 3: Implement the image helper.** Create `src/BloodAndBanners.UI/Services/CatalogImages.cs`:

```csharp
namespace BloodAndBanners.UI.Services;

public static class CatalogImages
{
    public static string? Resolve(string? baseUrl, string? path)
    {
        if (string.IsNullOrWhiteSpace(baseUrl) || string.IsNullOrWhiteSpace(path))
        {
            return null;
        }
        var escaped = string.Join('/', path.Split('/').Select(Uri.EscapeDataString));
        return baseUrl.TrimEnd('/') + (escaped.StartsWith('/') ? escaped : "/" + escaped);
    }
}
```

- [ ] **Step 4: Implement the draft table.** Create `src/BloodAndBanners.UI/Components/DraftTable.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services

<main class="draft-table" aria-labelledby="draft-title">
    <header class="draft-ribbon">
        <div>
            <p class="draft-eyebrow">Vanilla duel · @Projection.ScenarioName</p>
            <h1 id="draft-title">Muster your host</h1>
        </div>
        <p data-testid="initiative" class="initiative">@InitiativeText</p>
        <p data-testid="draft-status" class="draft-status @(IsMyPick ? "my-pick" : null)">@StatusText</p>
    </header>

    @if (!string.IsNullOrEmpty(ErrorMessage))
    {
        <p class="draft-error" role="alert">@ErrorMessage</p>
    }

    <div class="decision-row">
        <section class="decision" aria-labelledby="first-player-title">
            <h2 id="first-player-title">First turn</h2>
            @if (Draft.FirstSeatId is { } first)
            {
                <p class="decided">@(first == ViewerSeat ? "You move first." : $"{SeatName(first)} moves first.")</p>
            }
            else
            {
                <div class="choice-buttons">
                    @foreach (var seat in Projection.Memberships)
                    {
                        <button type="button"
                                data-action="chooseFirstPlayer"
                                data-seat="@seat.SeatId"
                                disabled="@(!Can("chooseFirstPlayer"))"
                                @onclick="() => ChooseFirst(seat.SeatId)">
                            @(seat.SeatId == ViewerSeat ? "I move first" : $"{SeatName(seat.SeatId)} moves first")
                        </button>
                    }
                </div>
            }
        </section>

        <section class="decision" aria-labelledby="start-title">
            <h2 id="start-title">Start position</h2>
            @if (Draft.StartBySeat.Values.All(start => start is not null))
            {
                <p class="decided">
                    @string.Join(" · ", Projection.Memberships.Select(seat => $"{SeatName(seat.SeatId)}: start {Draft.StartBySeat[seat.SeatId]}"))
                </p>
            }
            else
            {
                <div class="choice-buttons">
                    @foreach (var start in new[] { 1, 2 })
                    {
                        <button type="button"
                                data-action="chooseStartPosition"
                                data-start="@start"
                                disabled="@(!Can("chooseStartPosition"))"
                                @onclick="() => ChooseStart(start)">
                            Take start @start
                        </button>
                    }
                </div>
            }
        </section>
    </div>

    <section class="roster-section" aria-labelledby="hero-title">
        <h2 id="hero-title">Heroes</h2>
        <div class="card-grid">
            @foreach (var hero in Draft.AvailableHeroes)
            {
                var taken = hero.TakenBySeatId is not null;
                <button type="button"
                        class="draft-card @(taken ? "taken" : null) @(taken && hero.TakenBySeatId == ViewerSeat ? "mine" : null)"
                        data-action="pickHero"
                        data-hero="@hero.Name"
                        disabled="@(taken || !Can("pickHero"))"
                        @onclick="() => PickHero(hero.Name)">
                    @if (CatalogImages.Resolve(ImageBaseUrl, hero.Image) is { } heroImage)
                    {
                        <img src="@heroImage" alt="" loading="lazy" />
                    }
                    <strong>@hero.Name</strong>
                    <span class="stat-line">Move @hero.Move · Range @hero.Range · Power @hero.Power · Defense @hero.Defense · HP @hero.Hp</span>
                    @if (taken)
                    {
                        <span class="taken-by">@ClaimText(hero.TakenBySeatId!)</span>
                    }
                </button>
            }
        </div>
    </section>

    <section class="roster-section" aria-labelledby="army-title">
        <h2 id="army-title">Armies</h2>
        <div class="card-grid army-grid">
            @foreach (var army in Draft.AvailableArmies)
            {
                var taken = army.TakenBySeatId is not null;
                <button type="button"
                        class="draft-card army-card @(taken ? "taken" : null) @(taken && army.TakenBySeatId == ViewerSeat ? "mine" : null)"
                        data-action="pickArmy"
                        data-army="@army.Name"
                        disabled="@(taken || !Can("pickArmy"))"
                        @onclick="() => PickArmy(army.Name)">
                    <strong>@army.Name</strong>
                    <span class="army-colour">@army.Color banner</span>
                    <ul>
                        @foreach (var unit in army.Units)
                        {
                            <li>@unit.Name <small>M@unit.Move R@unit.Range P@unit.Power D@unit.Defense HP@unit.Hp</small></li>
                        }
                    </ul>
                    @if (taken)
                    {
                        <span class="taken-by">@ClaimText(army.TakenBySeatId!)</span>
                    }
                </button>
            }
        </div>
    </section>
</main>

@code {
    [Parameter, EditorRequired]
    public GameProjection Projection { get; set; } = default!;

    [Parameter]
    public bool IsBusy { get; set; }

    [Parameter]
    public string? ErrorMessage { get; set; }

    [Parameter]
    public string? ImageBaseUrl { get; set; }

    [Parameter]
    public EventCallback<GameCommandRequest> OnCommand { get; set; }

    private DraftProjection Draft => Projection.Draft!;

    private string? ViewerSeat => Projection.Viewer.SeatId;

    private bool IsMyPick => ViewerSeat is not null && Draft.PickingSeatId == ViewerSeat;

    private bool Can(string kind) =>
        !IsBusy && Projection.Viewer.CanCommand && Projection.LegalActions.Any(action => action.Kind == kind);

    private string StatusText => IsMyPick
        ? "Your pick"
        : Draft.PickingSeatId is null
            ? "The armies are taking the field"
            : Projection.Viewer.Role == "player"
                ? "Waiting for the opposing commander"
                : $"{SeatName(Draft.PickingSeatId)} is choosing";

    private string InitiativeText
    {
        get
        {
            var rolls = Projection.Memberships
                .Where(seat => Draft.InitiativeRolls.ContainsKey(seat.SeatId))
                .Select(seat => $"{SeatName(seat.SeatId)} rolled {string.Join(" → ", Draft.InitiativeRolls[seat.SeatId])}");
            var winner = Draft.InitiativeRolls.MaxBy(pair => pair.Value.Count == 0 ? 0 : pair.Value[^1]).Key;
            return $"{string.Join(" · ", rolls)} — {SeatName(winner)} won initiative";
        }
    }

    private string SeatName(string seatId)
    {
        if (seatId == ViewerSeat)
        {
            return "You";
        }
        if (Projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        var seat = Projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? "Commander" : $"Commander {seat.SeatIndex}";
    }

    private string ClaimText(string seatId) => seatId == ViewerSeat ? "Yours" : $"Claimed by {SeatName(seatId)}";

    private Task Send(string kind, object payload) => OnCommand.InvokeAsync(new GameCommandRequest(kind, payload));

    private Task ChooseFirst(string seatId) => Send("chooseFirstPlayer", new { seatId });

    private Task ChooseStart(int start) => Send("chooseStartPosition", new { start });

    private Task PickHero(string hero) => Send("pickHero", new { hero });

    private Task PickArmy(string army) => Send("pickArmy", new { army });
}
```

Create `src/BloodAndBanners.UI/Components/DraftTable.razor.css`:

```css
.draft-table {
    --ink: #181711;
    --oxblood: #681e24;
    --brass: #b08b42;
    --parchment: #e4dcc7;
    min-height: 100dvh;
    padding: clamp(1rem, 4vw, 2.5rem);
    color: var(--parchment);
    background: linear-gradient(110deg, rgba(21, 22, 19, .98), rgba(38, 37, 32, .94)), repeating-linear-gradient(135deg, #24251f 0 3px, #1d1e1b 3px 11px);
    font-family: "Segoe UI", system-ui, sans-serif;
}

.draft-ribbon,
.decision-row,
.roster-section,
.draft-error { width: min(72rem, 100%); margin-inline: auto; }

.draft-ribbon { display: grid; gap: .5rem; padding-bottom: 1rem; border-bottom: .25rem solid var(--brass); }
.draft-ribbon h1 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: clamp(1.8rem, 5vw, 2.8rem); }
.draft-eyebrow { margin: 0; color: #c9b27a; letter-spacing: .08em; text-transform: uppercase; font-size: .8rem; }
.initiative { margin: 0; color: #cfc6b2; }
.draft-status { margin: 0; font-weight: 700; }
.draft-status.my-pick { color: #f0cf7a; }
.draft-error { margin-top: 1rem; padding: .75rem 1rem; background: #4b1b1f; border-left: .3rem solid #d26a5a; }

.decision-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; margin-top: 1.5rem; }
.decision { padding: 1rem; background: rgba(0, 0, 0, .25); border: 1px solid #5a5548; }
.decision h2,
.roster-section h2 { margin: 0 0 .75rem; font-family: Georgia, serif; color: #ddbf76; font-size: 1.2rem; }
.decided { margin: 0; }
.choice-buttons { display: flex; flex-wrap: wrap; gap: .5rem; }
.choice-buttons button { min-height: 2.75rem; padding: .5rem .9rem; color: #f8f0dc; background: var(--oxblood); border: 1px solid #b18b48; }

.roster-section { margin-top: 1.75rem; }
.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: .85rem; }
.army-grid { grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); }
.draft-card { display: grid; gap: .35rem; align-content: start; padding: .85rem; text-align: left; color: var(--parchment); background: #2c2d28; border: 1px solid #6a6252; box-shadow: inset .25rem 0 0 var(--brass); }
.draft-card img { width: 100%; aspect-ratio: 2 / 3; object-fit: cover; border: 1px solid #6a6252; }
.draft-card strong { font-family: Georgia, serif; font-size: 1.05rem; }
.stat-line,
.army-colour { color: #c9c2b2; font-size: .85rem; }
.draft-card ul { margin: .25rem 0 0; padding-left: 1rem; font-size: .85rem; }
.draft-card small { color: #a9a190; }
.draft-card:not(:disabled):hover { background: #3a3a33; }
.draft-card:disabled { cursor: not-allowed; }
.draft-card.taken { opacity: .55; filter: grayscale(.6); }
.draft-card.mine { opacity: 1; filter: none; box-shadow: inset .25rem 0 0 #f0cf7a, 0 0 0 2px #f0cf7a; }
.taken-by { font-weight: 700; color: #f0cf7a; }
button:focus-visible { outline: .22rem solid #fff3aa; outline-offset: .16rem; }

@media (max-width: 520px) {
    .card-grid,
    .army-grid { grid-template-columns: 1fr; }
}
```

- [ ] **Step 5: Implement routing, commands and events in `GameExperience.razor`.**
  1. Add `@using System.Net` to the usings at the top.
  2. Replace the final `else { <GameTable Projection="_game" /> }` branch with:

```razor
else if (_game.Lifecycle == GameLifecycle.Draft && _game.Draft is not null)
{
    <DraftTable Projection="_game"
                IsBusy="_isBusy"
                ErrorMessage="@_error"
                ImageBaseUrl="@ImageBaseUrl"
                OnCommand="SubmitGameCommand" />
}
else
{
    <GameTable Projection="_game" />
}
```

  3. Add this parameter next to the others:

```csharp
    [Parameter]
    public string? ImageBaseUrl { get; set; }
```

  4. Add these fields next to `_game`:

```csharp
    private readonly List<GameEvent> _events = [];
    private long _lastSequence;
```

  5. Add these methods after `StartGame`:

```csharp
    private async Task SubmitGameCommand(GameCommandRequest request)
    {
        if (_game is null)
        {
            return;
        }

        var expectedVersion = _game.Version;
        _isBusy = true;
        _error = null;
        try
        {
            ApplyProjection(await Sessions.SubmitCommandAsync(expectedVersion, request.Kind, request.Payload, _pollCancellation.Token));
            ApplyEvents(await Sessions.GetEventsAsync(_lastSequence, _pollCancellation.Token));
        }
        catch (OperationCanceledException) when (_pollCancellation.IsCancellationRequested)
        {
        }
        catch (GameApiException error) when (error.StatusCode == HttpStatusCode.Conflict)
        {
            _error = "The battlefield changed — try again.";
            await RefreshAfterFailure();
        }
        catch (GameApiException error)
        {
            _error = error.Message;
            await RefreshAfterFailure();
        }
        catch (Exception)
        {
            _error = "The order could not be delivered. Check your connection and try again.";
            await RefreshAfterFailure();
        }
        finally
        {
            _isBusy = false;
        }
    }

    private async Task ReturnToWarRoom()
    {
        await Sessions.LeaveAsync();
        _game = null;
        _events.Clear();
        _lastSequence = 0;
        _error = null;
        _status = null;
    }

    private void ApplyEvents(EventPage? page)
    {
        if (page is null)
        {
            return;
        }
        _events.AddRange(page.Events.Where(gameEvent => gameEvent.Sequence > _lastSequence));
        if (_events.Count > 200)
        {
            _events.RemoveRange(0, _events.Count - 200);
        }
        _lastSequence = Math.Max(_lastSequence, page.LastSequence);
    }
```

  6. In `StartPolling`, allow a finished poll loop to restart:

```csharp
        if (_game is not null && (_pollTask is null || _pollTask.IsCompleted))
```

  7. In `PollAsync`, make the turn-aware cadence count the draft picker. Then fetch events with each refresh by replacing the block from `var refreshed = await Sessions.ReconnectAsync(cancellationToken);` through its closing `}` with:

```csharp
                var refreshed = await Sessions.ReconnectAsync(cancellationToken);
                EventPage? page = null;
                if (refreshed is not null && refreshed.Lifecycle != GameLifecycle.Lobby)
                {
                    page = await Sessions.GetEventsAsync(_lastSequence, cancellationToken);
                }
                if (refreshed is not null)
                {
                    await InvokeAsync(() =>
                    {
                        ApplyProjection(refreshed);
                        ApplyEvents(page);
                        failures = 0;
                        StateHasChanged();
                    });
                }
```

     and change the cadence call's second argument to:

```csharp
                current.PendingChoice?.SeatId == current.Viewer.SeatId || current.Draft?.PickingSeatId == current.Viewer.SeatId,
```

  8. In `DefaultScenario`, change both `"Training Grounds"` values to `"Proving Field"`.

- [ ] **Step 6: Wire up the image base URL in the hosts.** Replace `src/BloodAndBanners.Web/Pages/Home.razor` with:

```razor
@page "/"

@using BloodAndBanners.UI.Components
@using BloodAndBanners.UI.Services
@using Microsoft.Extensions.Configuration
@inject NavigationManager Navigation
@inject IConfiguration Configuration

<PageTitle>Blood &amp; Banners</PageTitle>

<GameExperience ClientKind="web"
                InitialJoinCode="@InviteLinks.GameIdFromUri(Navigation.Uri)"
                InviteBaseUri="@Navigation.BaseUri"
                ImageBaseUrl="@Configuration["ImageBaseUrl"]" />
```

Replace `src/BloodAndBanners.Web/wwwroot/appsettings.Development.json` with:

```json
{
  "ApiBaseUrl": "http://localhost:8080/",
  "ImageBaseUrl": "https://trickypig.com/blood/public"
}
```

In `src/BloodAndBanners.Maui/Components/Pages/Home.razor`, change the component line to:

```razor
<GameExperience ClientKind="maui" InviteBaseUri="https://bloodandbanners.com/" ImageBaseUrl="https://trickypig.com/blood/public" />
```

- [ ] **Step 7: Run the tests and builds to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: all tests pass.
Run: `dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj`
Expected: `Build succeeded.`

- [ ] **Step 8: Commit**

```bash
git add src tests/BloodAndBanners.UI.Tests
git commit -m "Add the draft table and route matches through it"
```

---

### Task 10: Playable battlefield, combat log and match end

**Files:**
- Create: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`, `src/BloodAndBanners.UI/Components/CombatLog.razor`, `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`, `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`, `GameExperience.razor`, `tests/BloodAndBanners.UI.Tests/UnitTest1.cs`

**Interfaces:**
- Consumes: `GameCommandRequest`, `CatalogImages` (Tasks 8–9); `UnitProjection` and `TurnProjection.ActiveUnitId` (Task 7); the event payloads from Task 5.
- Produces:
  - `CombatLogFormatter.Describe(GameEvent gameEvent, GameProjection projection): string?`
  - `<CombatLog Projection Events />`
  - `<GameTable Projection Events IsBusy ErrorMessage ImageBaseUrl OnCommand OnReturnToWarRoom OnChoice OnFollowEvents />`

- [ ] **Step 1: Write the failing tests.** Create `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class CombatLogFormatterTests
{
    [Fact]
    public void Attacks_show_both_dice_rolls_and_wounds()
    {
        var line = CombatLogFormatter.Describe(
            Event("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","attackFaces":["sword","sword","sword","shield"],"defenseFaces":["shield","blank"],"wounds":2}"""),
            BattleFixtures.Projection());

        Assert.Equal("Ranger → Grunt: ⚔⚔⚔🛡 vs 🛡⬚ — 2 wounds", line);
    }

    [Fact]
    public void Moves_report_disengagement_strikes_and_turns_name_the_next_commander()
    {
        var projection = BattleFixtures.Projection();

        Assert.Equal(
            "Ranger moves to 2,4. Grunt strikes as it disengages: ⚔ — 1 wound.",
            CombatLogFormatter.Describe(Event("unitMoved", """{"unitId":"s1-u3","from":"1,4","to":"2,4","disengagement":[{"byUnitId":"s2-u1","faces":["sword"],"wounds":1}]}"""), projection));
        Assert.Equal(
            "Round 3: Rival's turn.",
            CombatLogFormatter.Describe(Event("turnEnded", """{"seatId":"seat-red","nextSeatId":"seat-blue","round":3}"""), projection));
        Assert.Equal(
            "You won by annihilation.",
            CombatLogFormatter.Describe(Event("matchCompleted", """{"winningSeatId":"seat-red","reason":"annihilation"}"""), projection));
        Assert.Null(CombatLogFormatter.Describe(Event("readinessChanged", """{"ready":true}"""), projection));
    }

    private static GameEvent Event(string kind, string payload) =>
        new(1, 1, kind, null, [], JsonSerializer.Deserialize<JsonElement>(payload), DateTimeOffset.UnixEpoch);
}
```

Create `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class GameTableTests : BunitContext
{
    [Fact]
    public void Selecting_a_unit_highlights_its_legal_moves_and_attacks_and_sends_them()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();

        Assert.Contains("selected", cut.Find("[data-hex='1,4']").ClassList);
        Assert.Contains("reachable", cut.Find("[data-hex='2,4']").ClassList);
        Assert.Contains("attackable", cut.Find("[data-hex='3,4']").ClassList);
        Assert.Equal("Ranger", cut.Find("[data-testid=selected-unit] h3").TextContent);

        cut.Find("[data-hex='3,4']").Click();

        Assert.Equal("attackUnit", sent.Single().Kind);
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("s1-u3", payload.GetProperty("unitId").GetString());
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitId").GetString());
    }

    [Fact]
    public void End_turn_is_offered_as_an_order_and_rosters_strike_out_the_fallen()
    {
        GameCommandRequest? sent = null;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        cut.Find("button[data-action=endTurn]").Click();

        Assert.Equal("endTurn", sent?.Kind);
        Assert.Contains("defeated", cut.Find("[data-roster-unit=s2-u2]").ClassList);
        Assert.Contains("Your turn · 3 activations", cut.Find("[data-testid=turn-status]").TextContent);
    }

    [Fact]
    public void The_combat_log_lists_newest_events_first()
    {
        var events = new[]
        {
            new GameEvent(1, 5, "turnEnded", null, [], JsonSerializer.Deserialize<JsonElement>("""{"seatId":"seat-blue","nextSeatId":"seat-red","round":2}"""), DateTimeOffset.UnixEpoch),
            new GameEvent(2, 6, "unitDefeated", null, ["4,4"], JsonSerializer.Deserialize<JsonElement>("""{"unitId":"s2-u2"}"""), DateTimeOffset.UnixEpoch),
        };

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.Events, events));

        var lines = cut.FindAll(".combat-log li");
        Assert.Equal("Brute is defeated.", lines[0].TextContent);
        Assert.Equal("Round 2: Your turn.", lines[1].TextContent);
        Assert.Contains("focus", cut.Find("[data-hex='4,4']").ClassList);
    }

    [Fact]
    public void A_decided_match_shows_the_result_and_a_way_back_to_the_war_room()
    {
        var returned = false;
        var finished = BattleFixtures.Projection() with
        {
            Lifecycle = GameLifecycle.Completed,
            LegalActions = [],
            Result = new GameResult("annihilation", "seat-red", new Dictionary<string, int>())
        };

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, finished)
            .Add(component => component.OnReturnToWarRoom, () => returned = true));

        Assert.Equal("Victory", cut.Find("#match-end-title").TextContent);
        cut.Find("button[data-action=returnToWarRoom]").Click();
        Assert.True(returned);
    }
}

internal static class BattleFixtures
{
    public static GameProjection Projection() => new(
        "game-fixture",
        21,
        GameLifecycle.Active,
        GameMode.VanillaPvp,
        new ViewerPermissions("player", "seat-red", true, true),
        new BoardProjection(11, 9, [
            new HexProjection(1, 4, "plains", "roads", 0, false, "s1-u3"),
            new HexProjection(2, 4, "plains", "roads", 0, false, null),
            new HexProjection(3, 4, "stone", "stone-path", 1, false, "s2-u1"),
            new HexProjection(4, 4, "stone", "stone-path", 1, false, null),
        ]),
        new TurnProjection(2, "seat-red", 3),
        null,
        [
            new LegalAction("moveUnit", "s1-u3", ["2,4"]),
            new LegalAction("attackUnit", "s1-u3", ["3,4"]),
            new LegalAction("endTurn", null, []),
        ],
        new Dictionary<string, int> { ["seat-red"] = 0, ["seat-blue"] = 0 },
        null)
    {
        ScenarioName = "Proving Field",
        Memberships = [
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, true, false),
        ],
        Units = [
            new UnitProjection("s1-u3", "seat-red", false, "Ranger", "/images/units/gold_ranger_card.png", 3, 6, 3, 2, 4, 1, "1,4", false),
            new UnitProjection("s2-u1", "seat-blue", false, "Grunt", "/images/units/black_grunt_card.png", 2, 1, 4, 3, 5, 0, "3,4", false),
            new UnitProjection("s2-u2", "seat-blue", false, "Brute", "/images/units/black_tank_card.png", 2, 1, 4, 4, 6, 6, null, false),
        ]
    };
}
```

In `tests/BloodAndBanners.UI.Tests/UnitTest1.cs`, replace the body of `Player_projection_renders_board_status_and_legal_actions` with:

```csharp
        GameCommandRequest? sent = null;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, Projection(canCommand: true))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        Assert.Equal("Battlefield, 3 columns by 2 rows", cut.Find("[role=grid]").GetAttribute("aria-label"));
        Assert.Contains("Round 2", cut.Find("[data-testid=turn-status]").TextContent);
        Assert.Equal("Plains grass, elevation 0, occupied by Thorgrim", cut.Find("[data-hex='0,0']").GetAttribute("aria-label"));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-hex='1,0']").Click();

        Assert.Equal("moveUnit", sent?.Kind);
        Assert.Equal("1,0", System.Text.Json.JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("to").GetString());
```

Add `using BloodAndBanners.UI.Services;` to that file's usings if it is missing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: build FAILS with `'GameTable' does not contain a definition for 'OnCommand'` and `'CombatLogFormatter' does not exist`.

- [ ] **Step 3: Implement the formatter.** Create `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`:

```csharp
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public static class CombatLogFormatter
{
    public static string? Describe(GameEvent gameEvent, GameProjection projection)
    {
        var payload = gameEvent.Payload;
        return gameEvent.Kind switch
        {
            "initiativeRolled" => "Initiative is rolled.",
            "draftPicked" => $"{Seat(projection, Text(payload, "seatId"))} chose {DraftChoice(projection, payload)}.",
            "armiesPlaced" => "Both armies take the field.",
            "unitMoved" => Moved(projection, payload),
            "attackResolved" =>
                $"{Unit(projection, Text(payload, "unitId"))} → {Unit(projection, Text(payload, "targetUnitId"))}: " +
                $"{Faces(payload.GetProperty("attackFaces"))} vs {Faces(payload.GetProperty("defenseFaces"))} — " +
                Wounds(payload.GetProperty("wounds").GetInt32()),
            "unitDefeated" => $"{Unit(projection, Text(payload, "unitId"))} is defeated.",
            "turnEnded" => $"Round {payload.GetProperty("round").GetInt32()}: {Possessive(Seat(projection, Text(payload, "nextSeatId")))} turn.",
            "matchCompleted" => $"{Seat(projection, Text(payload, "winningSeatId"))} won by annihilation.",
            _ => null,
        };
    }

    private static string Moved(GameProjection projection, JsonElement payload)
    {
        var line = $"{Unit(projection, Text(payload, "unitId"))} moves to {Text(payload, "to")}.";
        foreach (var hit in payload.GetProperty("disengagement").EnumerateArray())
        {
            line += $" {Unit(projection, Text(hit, "byUnitId"))} strikes as it disengages: {Faces(hit.GetProperty("faces"))} — {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
        return line;
    }

    private static string DraftChoice(GameProjection projection, JsonElement payload)
    {
        var value = payload.GetProperty("value").ToString();
        return Text(payload, "decision") switch
        {
            "chooseFirstPlayer" => $"{Seat(projection, value)} to move first",
            "chooseStartPosition" => $"start {value}",
            _ => value,
        };
    }

    private static string Seat(GameProjection projection, string seatId)
    {
        if (seatId == projection.Viewer.SeatId)
        {
            return "You";
        }
        if (projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        var seat = projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? "A commander" : $"Commander {seat.SeatIndex}";
    }

    private static string Possessive(string name) => name == "You" ? "Your" : $"{name}'s";

    private static string Unit(GameProjection projection, string unitId) =>
        projection.Units.FirstOrDefault(unit => unit.UnitId == unitId)?.Name ?? unitId;

    private static string Faces(JsonElement faces)
    {
        var symbols = string.Concat(faces.EnumerateArray().Select(face => face.GetString() switch
        {
            "sword" => "⚔",
            "shield" => "🛡",
            _ => "⬚",
        }));
        return symbols.Length == 0 ? "—" : symbols;
    }

    private static string Wounds(int wounds) => wounds == 1 ? "1 wound" : $"{wounds} wounds";

    private static string Text(JsonElement element, string property) =>
        element.GetProperty(property).GetString() ?? string.Empty;
}
```

- [ ] **Step 4: Implement the combat log component.** Create `src/BloodAndBanners.UI/Components/CombatLog.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services

<section class="combat-log" aria-label="Battle log">
    <h2>Battle log</h2>
    @if (Lines.Count == 0)
    {
        <p>No blows struck yet.</p>
    }
    else
    {
        <ol aria-live="polite">
            @foreach (var line in Lines)
            {
                <li data-event="@line.Kind">@line.Text</li>
            }
        </ol>
    }
</section>

@code {
    [Parameter, EditorRequired]
    public GameProjection Projection { get; set; } = default!;

    [Parameter]
    public IReadOnlyList<GameEvent> Events { get; set; } = [];

    private IReadOnlyList<(string Kind, string Text)> Lines => Events
        .Reverse()
        .Select(gameEvent => (gameEvent.Kind, Text: CombatLogFormatter.Describe(gameEvent, Projection)))
        .Where(line => line.Text is not null)
        .Take(40)
        .Select(line => (line.Kind, line.Text!))
        .ToList();
}
```

- [ ] **Step 5: Implement the battlefield.** Replace `src/BloodAndBanners.UI/Components/GameTable.razor` with:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services

<main class="game-table">
    <header class="turn-ribbon">
        <div>
            <span class="mode-mark" aria-hidden="true">⚔</span>
            <strong>@ModeLabel(Projection.Mode)</strong>
        </div>
        <p data-testid="turn-status">@TurnStatus</p>
        <p data-testid="viewer-role" class="viewer-role">
            @(Projection.Viewer.CanCommand ? "Commanding" : "Spectating")
        </p>
    </header>

    <section class="battlefield-panel" aria-label="Game board">
        <div class="board-controls" aria-label="Board view controls">
            <button type="button" aria-label="Zoom out">−</button>
            <button type="button" aria-label="Reset board view">Reset</button>
            <button type="button" aria-label="Zoom in">+</button>
        </div>
        <div class="hex-board"
             role="grid"
             style="--columns:@Projection.Board.Columns; --rows:@Projection.Board.Rows"
             aria-label="Battlefield, @Projection.Board.Columns columns by @Projection.Board.Rows rows">
            @foreach (var hex in Projection.Board.Hexes)
            {
                var key = HexKey(hex);
                var unit = UnitById(hex.OccupantId);
                <button type="button"
                        role="gridcell"
                        class="hex @TerrainClass(hex) @HexState(key) @(hex.OccupantId is null ? null : "occupied")"
                        style="--q:@hex.Q; --r:@hex.R; --elevation:@hex.Elevation"
                        data-hex="@key"
                        aria-label="@HexLabel(hex, unit)"
                        @onclick="() => SelectHex(hex)">
                    <span class="hex-elevation" aria-hidden="true">@hex.Elevation</span>
                    @if (hex.OccupantId is not null)
                    {
                        <span class="unit-token @SeatClass(unit?.SeatId) @(unit?.IsHero == true ? "hero" : null)"
                              style="@TokenStyle(unit)"
                              aria-hidden="true">
                            @(unit is null ? Initials(hex.OccupantId) : Initials(unit.Name))
                            @if (unit is not null)
                            {
                                <span class="hp-bar" style="--health:@HealthPercent(unit)%"></span>
                            }
                        </span>
                    }
                </button>
            }
        </div>
    </section>

    <aside class="command-rail" aria-label="Game commands">
        @if (!string.IsNullOrEmpty(ErrorMessage))
        {
            <p class="rail-error" role="alert">@ErrorMessage</p>
        }

        @if (Projection.PendingChoice is not null && Projection.PendingChoice.SeatId == Projection.Viewer.SeatId)
        {
            <section class="choice-prompt" role="dialog" aria-modal="false" aria-labelledby="choice-title">
                <h2 id="choice-title">@Humanize(Projection.PendingChoice.Kind)</h2>
                <p>The battle is waiting for your decision.</p>
                <div class="choice-options">
                    @foreach (var option in Projection.PendingChoice.Options)
                    {
                        <button type="button" data-choice="@option" @onclick="() => OnChoice.InvokeAsync(option)">@option</button>
                    }
                </div>
            </section>
        }
        else if (Projection.Viewer.CanCommand)
        {
            <section class="action-tray" aria-label="Your orders">
                <h2>Your orders</h2>
                @if (SelectedUnit is { } selected)
                {
                    <article class="unit-card" data-testid="selected-unit">
                        <h3>@selected.Name</h3>
                        <p>Move @selected.Move · Range @selected.Range · Power @selected.Power · Defense @selected.Defense</p>
                        <p>Wounds @selected.Wounds / @selected.Hp</p>
                    </article>
                }
                else if (IsMyTurn)
                {
                    <p>Select one of your units on the board.</p>
                }
                @foreach (var action in Projection.LegalActions.Where(action => action.Kind is "endActivation" or "endTurn"))
                {
                    <button type="button"
                            class="action-button"
                            data-action="@action.Kind"
                            disabled="@IsBusy"
                            @onclick="() => Send(action.Kind, ActionPayload(action))">
                        @ActionLabel(action.Kind)
                    </button>
                }
            </section>
        }
        else
        {
            <section class="spectator-controls">
                <h2>Battle camera</h2>
                <button type="button" data-follow-events @onclick="() => OnFollowEvents.InvokeAsync()">
                    Follow the action
                </button>
                <p>Moving the board pauses automatic following.</p>
            </section>
        }

        <section class="rosters" aria-label="Armies">
            @foreach (var seat in Projection.Memberships)
            {
                <div class="roster @SeatClass(seat.SeatId)">
                    <h2>@RosterTitle(seat)</h2>
                    <ul>
                        @foreach (var unit in Projection.Units.Where(unit => unit.SeatId == seat.SeatId))
                        {
                            <li class="@(unit.Hex is null ? "defeated" : null)" data-roster-unit="@unit.UnitId">
                                @unit.Name <span>@(unit.Hp - unit.Wounds)/@unit.Hp</span>
                            </li>
                        }
                    </ul>
                </div>
            }
        </section>

        <CombatLog Projection="Projection" Events="Events" />
    </aside>

    @if (Projection.Result is { } result)
    {
        <section class="match-end" role="dialog" aria-modal="true" aria-labelledby="match-end-title" data-testid="match-end">
            <h2 id="match-end-title">@ResultTitle(result)</h2>
            <p>Every enemy unit has fallen.</p>
            @if (Projection.Viewer.CanCommand)
            {
                <button type="button" data-action="returnToWarRoom" @onclick="() => OnReturnToWarRoom.InvokeAsync()">Return to war room</button>
            }
        </section>
    }
</main>

@code {
    [Parameter, EditorRequired]
    public GameProjection Projection { get; set; } = default!;

    [Parameter]
    public IReadOnlyList<GameEvent> Events { get; set; } = [];

    [Parameter]
    public bool IsBusy { get; set; }

    [Parameter]
    public string? ErrorMessage { get; set; }

    [Parameter]
    public string? ImageBaseUrl { get; set; }

    [Parameter]
    public EventCallback<GameCommandRequest> OnCommand { get; set; }

    [Parameter]
    public EventCallback OnReturnToWarRoom { get; set; }

    [Parameter]
    public EventCallback<string> OnChoice { get; set; }

    [Parameter]
    public EventCallback OnFollowEvents { get; set; }

    private string? _selectedUnitId;

    protected override void OnParametersSet()
    {
        if (_selectedUnitId is not null && !Selectable(_selectedUnitId))
        {
            _selectedUnitId = null;
        }
        if (_selectedUnitId is null && Projection.Turn.ActiveUnitId is { } active && Selectable(active))
        {
            _selectedUnitId = active;
        }
    }

    private bool IsMyTurn => Projection.Viewer.SeatId is not null && Projection.Turn.ActiveSeatId == Projection.Viewer.SeatId;

    private UnitProjection? SelectedUnit => UnitById(_selectedUnitId);

    private string? SelectedHex => Projection.Board.Hexes
        .Where(hex => _selectedUnitId is not null && hex.OccupantId == _selectedUnitId)
        .Select(HexKey)
        .FirstOrDefault();

    private IReadOnlyList<string> FocusHexes => Events.Count == 0 ? [] : Events[^1].FocusHexes;

    private string TurnStatus
    {
        get
        {
            var round = $"Round {Projection.Turn.Round}";
            if (Projection.Result is not null)
            {
                return $"{round} · The battle is decided";
            }
            if (Projection.Turn.ActiveSeatId is null)
            {
                return round;
            }
            if (IsMyTurn)
            {
                return $"{round} · Your turn · {Projection.Turn.ActivationsRemaining} activations";
            }
            return Projection.Viewer.CanCommand ? $"{round} · Opponent's turn" : $"{round} · {SeatName(Projection.Turn.ActiveSeatId)}'s turn";
        }
    }

    private bool Selectable(string unitId) =>
        Projection.Viewer.CanCommand &&
        Projection.LegalActions.Any(action => action.UnitId == unitId && (action.Kind is "moveUnit" or "attackUnit"));

    private IReadOnlyList<string> Targets(string kind) => _selectedUnitId is null
        ? []
        : Projection.LegalActions
            .Where(action => action.Kind == kind && action.UnitId == _selectedUnitId)
            .SelectMany(action => action.TargetHexes)
            .ToList();

    private async Task SelectHex(HexProjection hex)
    {
        if (!Projection.Viewer.CanCommand || IsBusy)
        {
            return;
        }
        var key = HexKey(hex);
        if (_selectedUnitId is { } unitId)
        {
            if (Targets("moveUnit").Contains(key))
            {
                await Send("moveUnit", new { unitId, to = key });
                return;
            }
            if (Targets("attackUnit").Contains(key) && hex.OccupantId is { } targetUnitId)
            {
                await Send("attackUnit", new { unitId, targetUnitId });
                return;
            }
        }
        _selectedUnitId = hex.OccupantId is { } occupant && Selectable(occupant) ? occupant : null;
    }

    private Task Send(string kind, object payload) => OnCommand.InvokeAsync(new GameCommandRequest(kind, payload));

    private static object ActionPayload(LegalAction action) =>
        action.Kind == "endTurn" ? new { } : new { unitId = action.UnitId };

    private string? HexState(string key)
    {
        if (key == SelectedHex)
        {
            return "selected";
        }
        if (Targets("attackUnit").Contains(key))
        {
            return "attackable";
        }
        if (Targets("moveUnit").Contains(key))
        {
            return "reachable";
        }
        return FocusHexes.Contains(key) ? "focus" : null;
    }

    private UnitProjection? UnitById(string? unitId) =>
        unitId is null ? null : Projection.Units.FirstOrDefault(unit => unit.UnitId == unitId);

    private string? SeatClass(string? seatId)
    {
        var seat = Projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? null : $"seat-{seat.SeatIndex}";
    }

    private string SeatName(string seatId)
    {
        if (seatId == Projection.Viewer.SeatId)
        {
            return "You";
        }
        if (Projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        var seat = Projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? "Commander" : $"Commander {seat.SeatIndex}";
    }

    private string RosterTitle(MembershipProjection seat) =>
        seat.SeatId == Projection.Viewer.SeatId
            ? "Your army"
            : Projection.Viewer.Role == "player" ? "Rival army" : $"Commander {seat.SeatIndex}";

    private string ResultTitle(GameResult result) =>
        result.WinningSeatId == Projection.Viewer.SeatId
            ? "Victory"
            : Projection.Viewer.Role == "player" ? "Defeat" : $"{SeatName(result.WinningSeatId ?? string.Empty)} wins";

    private string? TokenStyle(UnitProjection? unit) =>
        CatalogImages.Resolve(ImageBaseUrl, unit?.Image) is { } image ? $"background-image:url('{image}')" : null;

    private static int HealthPercent(UnitProjection unit) =>
        unit.Hp <= 0 ? 0 : (unit.Hp - unit.Wounds) * 100 / unit.Hp;

    private static string HexKey(HexProjection hex) => $"{hex.Q},{hex.R}";

    private static string TerrainClass(HexProjection hex) =>
        $"terrain-{hex.Biome.ToLowerInvariant()}-{hex.Terrain.ToLowerInvariant().Replace(' ', '-')}";

    private static string HexLabel(HexProjection hex, UnitProjection? unit) =>
        $"{Title(hex.Biome)} {hex.Terrain}, elevation {hex.Elevation}" +
        (hex.OccupantId is null ? string.Empty : $", occupied by {unit?.Name ?? hex.OccupantId}");

    private static string Initials(string value) => string.Concat(
        value.Split(' ', StringSplitOptions.RemoveEmptyEntries).Take(2).Select(part => char.ToUpperInvariant(part[0])));

    private static string Title(string value) =>
        string.IsNullOrEmpty(value) ? value : char.ToUpperInvariant(value[0]) + value[1..];

    private static string Humanize(string value)
    {
        var spaced = System.Text.RegularExpressions.Regex.Replace(value, "([a-z])([A-Z])", "$1 $2");
        return Title(spaced.ToLowerInvariant());
    }

    private static string ActionLabel(string kind) => kind switch
    {
        "endActivation" => "End activation",
        "endTurn" => "End turn",
        _ => Humanize(kind),
    };

    private static string ModeLabel(GameMode mode) => mode switch
    {
        GameMode.VanillaPvp => "Vanilla duel",
        GameMode.Coop => "Void incursion",
        GameMode.BattleRoyale => "Battle royale",
        _ => mode.ToString(),
    };
}
```

- [ ] **Step 6: Style the battlefield.** In `src/BloodAndBanners.UI/Components/GameTable.razor.css`, replace the `.hex-board` rule with:

```css
.hex-board {
    position: relative;
    width: max(36rem, calc(9rem + var(--columns, 3) * 5.35rem));
    min-height: max(31rem, calc(6rem + var(--rows, 2) * 6.15rem));
    transform-origin: center;
}
```

Replace the `.unit-token` rule and the `.score-strip …` rules with these, and append the remaining rules before the `@media (max-width: 760px)` block:

```css
.unit-token { position: relative; width: 2.65rem; height: 2.65rem; display: grid; place-items: center; color: #f5ead2; background: var(--oxblood) center / cover no-repeat; border: 3px double #d6b76d; border-radius: 50%; font-family: Georgia, serif; font-weight: 700; text-shadow: 0 1px 2px #000; box-shadow: 0 .2rem .35rem rgba(0,0,0,.45); }
.unit-token.seat-2 { background-color: #1f4a6b; border-color: #9cc3df; }
.unit-token.hero::after { content: "⚑"; position: absolute; top: -.85rem; right: -.45rem; color: #f0cf7a; font-size: 1rem; text-shadow: 0 1px 2px #000; }
.hp-bar { position: absolute; left: .1rem; right: .1rem; bottom: -.55rem; height: .3rem; background: linear-gradient(90deg, #7fd36b var(--health), #3a1414 var(--health)); border: 1px solid #111; }

.hex.reachable::before { border-color: #f0cf7a; border-width: .22rem; background: rgba(240, 207, 122, .18); }
.hex.attackable::before { border-color: #ff6b5a; border-width: .26rem; background: rgba(255, 107, 90, .2); }
.hex.selected::before { border-color: #fff3aa; border-width: .3rem; }
.hex.focus::before { animation: focus-pulse 900ms ease-out 2; }
@keyframes focus-pulse { from { background: rgba(255, 243, 170, .55); } to { background: transparent; } }

.rail-error { margin: 0 0 1rem; padding: .6rem .8rem; color: #ffe4dc; background: #4b1b1f; border-left: .3rem solid #d26a5a; }
.unit-card { padding: .65rem .8rem; background: #1f201d; border: 1px solid #5a5548; }
.unit-card h3 { margin: 0 0 .35rem; font-family: Georgia, serif; color: #f0cf7a; }
.unit-card p { margin: .15rem 0; }
.rosters { display: grid; gap: 1rem; }
.roster ul { margin: 0; padding: 0; list-style: none; }
.roster li { display: flex; justify-content: space-between; padding: .2rem 0; border-bottom: 1px solid #3c3a33; }
.roster li.defeated { color: #8b8578; text-decoration: line-through; }
.roster.seat-1 h2 { color: #e2a29a; }
.roster.seat-2 h2 { color: #9cc3df; }
.combat-log ol { margin: 0; padding-left: 1.1rem; max-height: 16rem; overflow: auto; }
.combat-log li { padding: .2rem 0; line-height: 1.35; }
.match-end { position: fixed; inset: 0; z-index: 10; display: grid; place-content: center; gap: 1rem; text-align: center; color: #f6efdd; background: rgba(12, 12, 10, .82); }
.match-end h2 { margin: 0; font-family: Georgia, serif; font-size: clamp(2.5rem, 9vw, 5rem); color: #f0cf7a; }
.match-end button { justify-self: center; min-height: 3rem; padding: .6rem 1.4rem; color: #f8f0dc; background: var(--oxblood); border: 1px solid #b18b48; }
```

In `@media (prefers-reduced-motion: reduce)` there should be no pulse animation, so append:

```css
@media (prefers-reduced-motion: reduce) {
    .hex.focus::before { animation: none; background: rgba(255, 243, 170, .3); }
}
```

- [ ] **Step 7: Pass the battle parameters from `GameExperience`.** In `GameExperience.razor`, replace the remaining `<GameTable Projection="_game" />` with:

```razor
    <GameTable Projection="_game"
               Events="_events"
               IsBusy="_isBusy"
               ErrorMessage="@_error"
               ImageBaseUrl="@ImageBaseUrl"
               OnCommand="SubmitGameCommand"
               OnReturnToWarRoom="ReturnToWarRoom" />
```

- [ ] **Step 8: Run the tests and builds to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: all tests pass, including the existing spectator test. It asserts that there are no `[data-action]` elements, and hex buttons carry `data-hex`, not `data-action`.
Run: `dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj`
Expected: `Build succeeded.`

- [ ] **Step 9: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Make the battlefield playable with a combat log and match end"
```

---

### Task 11: README walkthrough and full verification

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update the README.** In `README.md`:
  - Rename the section `### Test a two-browser Vanilla lobby` to `### Play a two-browser Vanilla duel`.
  - Replace steps 6–7 and the following "This milestone ends at the draft boundary…" paragraph with:

```markdown
6. Start the match from the host browser. Both clients show the draft table with both initiative rolls.
7. Take turns picking. On your pick, choose any open decision: who moves first, your start position, your hero, or your army. Heroes and armies can each be claimed once.
8. When the last pick lands, both armies are placed beside their portals on Proving Field and the first player's turn begins.
9. On your turn, click one of your units. Gold hexes are reachable, red hexes are attackable enemies. Click a hex to move, then click an enemy to attack. Each turn allows your hero plus three other units, or any four units if your hero has fallen.
10. The battle log in the side rail shows each roll. The match ends when one side has no units left. Choose **Return to war room** to leave.
11. Refresh either browser at any point to verify that it reconnects to the same seat and state.

This slice implements the core Vanilla rules only. Abilities, water, ice, poison, stun, flying, terrain objects, and the Co-op and Battle Royale modes are not yet playable.

Hero and unit artwork loads from `ImageBaseUrl` in `wwwroot/appsettings.Development.json`. Without it, tokens fall back to initials.
```

- [ ] **Step 2: Run the full verification suite**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/local-api-launch.ps1
php api/tests/run.php
dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0
```

Expected: every command succeeds. PHP reports `0 failures`.

- [ ] **Step 3: Run a manual two-browser check.** Start the API and the PWA as described in the README. Play through the draft and at least two turns in two browser profiles. Confirm:
  - Picks and moves appear in the other browser within about 2 seconds.
  - Highlights match legal moves.
  - The battle log shows the dice.
  - Refreshing mid-battle restores the seat.
  - After a finished match, **Return to war room** opens the quick start, and a new room polls normally.

  Report anything that fails. Do not claim it works without having watched it.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Document the playable Vanilla duel walkthrough"
```
