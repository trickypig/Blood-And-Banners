# Commander Names Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each player name their commander in the War Room lobby and the draft screen. Every other player, spectators and the combat log then see that name in place of "Rival" or "Commander 2".

**Architecture:**
- The name is seat metadata, stored in a new `game_memberships.commander_name` column.
- The `setCommanderName` command changes it. `GameService` handles it next to the lobby commands, outside the rules engine. It is allowed in the `lobby`, `setup` and `draft` lifecycles.
- The projection carries `memberships[].name`.
- On the client, one function, `SeatNames.Display`, replaces the four duplicated seat-naming helpers.
- A new `CommanderNameField` component renders the input in the lobby and in the draft.

**Tech Stack:**
- API: PHP 8.1+ with no dependencies, SQLite in tests and MySQL in production. Tests are hand-rolled and run with `php api/tests/run.php`.
- Client: Blazor (net10.0) with xUnit and bUnit tests.

**Spec:** `docs/superpowers/specs/2026-10-14-commander-names-design.md`

## Global Constraints

- **Name rules:** at most 24 characters, counted with `mb_strlen`.
- **Cleaning order:** collapse whitespace (`\s+` becomes one space), remove the remaining control characters (`\p{Cc}`), then trim. An empty result is stored as `null`.
- **Duplicates:** compared with `mb_strtolower` against *other* seats in the same game.
- **Edit window:** the lifecycle must be `lobby`, `setup` or `draft`. Anything else gives `illegalLifecycle` with the message "Commander names are locked once battle begins."
- **Error codes and messages:** the messages are shown to players exactly as the server sends them, through `GameExperience`'s `_error = error.Message`.
  - `unsupportedCommand`: "Commander names must be text."
  - `nameTooLong`: "Names can be at most 24 characters."
  - `nameTaken`: "Another commander already uses that name."
- **No event:** `setCommanderName` writes no game event.
- **Labels without names:** with no names set, every existing UI label stays byte-for-byte the same.
- **Remembered name:** the client keeps the last saved name under the key `bloodAndBanners.commanderName` in `IKeyValueStore`. That interface is already backed by localStorage on the web and SecureStorage on MAUI. This replaces the spec's `bb.commanderName` to match the existing key style, and it also works on MAUI.

### Note on migrations

The spec says nothing about this, but it's required. `Migrator` re-executes every `.sql` file on every run, so `ALTER TABLE … ADD COLUMN` would fail the second time it ran. Task 1 makes `Migrator` record the migrations it has applied in a `schema_migrations` table.

Migrations 001 and 002 use `CREATE … IF NOT EXISTS`, so an existing production database simply re-runs them once and then records them.

## Review Focus

1. **A malformed name payload** (not a string, or invalid UTF-8 bytes) is refused with `unsupportedCommand` and never causes a 500. The test is in Task 2.
2. **A production database that already ran 001 and 002 without a `schema_migrations` table** gets the new column exactly once, and later runs are no-ops. The test is in Task 1.
3. **A name containing markup** (`<b>Ash</b>`) is shown as literal text everywhere and never rendered as HTML. The test is in Task 4.
4. **Storage that is missing or throws** (private browsing, no `IKeyValueStore` registered) leaves the name field working, just without a prefill. The test is in Task 5.
5. **The server cleans a name into something other than what was typed** (`"  Lord   Ash "` becomes `"Lord Ash"`). The field then shows the server's value and remembers it, not the raw input. The test is in Task 5.

---

### Task 1: Migration tracking and the `commander_name` column

**Files:**
- Modify: `api/src/Database/Migrator.php`
- Create: `api/migrations/003_commander_names.sqlite.sql`
- Create: `api/migrations/003_commander_names.mysql.sql`
- Modify: `api/src/Game/GameService.php` (`memberships()` at about line 896, and the `memberships` projection map at about line 627)
- Test: `api/tests/FoundationTest.php`

**Interfaces:**
- Produces:
  - the column `game_memberships.commander_name` (nullable)
  - `GameService::memberships()` rows that include `commander_name`
  - projection `memberships[].name` (`string|null`)
  - the table `schema_migrations(name, applied_at)`

- [ ] **Step 1: Write the failing tests**

Add these entries to the array returned by `foundation_tests()` in `api/tests/FoundationTest.php`, after the existing `'migrations can run repeatedly without failing'` entry:

```php
        'migrations record what they applied and add the commander name column once' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            (new Migrator($db))->migrate();
            (new Migrator($db))->migrate();

            $applied = $db->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
            expect_same(['001_initial', '002_match_state', '003_commander_names'], $applied, 'each migration is recorded once');
            $columns = array_column($db->query('PRAGMA table_info(game_memberships)')->fetchAll(), 'name');
            expect_same(1, count(array_keys($columns, 'commander_name', true)), 'the name column exists exactly once');
        },

        'a database migrated before tracking existed upgrades cleanly' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            $root = dirname(__DIR__) . '/migrations/';
            $db->exec((string)file_get_contents($root . '001_initial.sqlite.sql'));
            $db->exec((string)file_get_contents($root . '002_match_state.sqlite.sql'));

            (new Migrator($db))->migrate();
            (new Migrator($db))->migrate();

            $columns = array_column($db->query('PRAGMA table_info(game_memberships)')->fetchAll(), 'name');
            expect_true(in_array('commander_name', $columns, true), 'the legacy database gains the name column');
        },

        'lobby projections carry a null name for unnamed seats' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $guest = $auth->createGuest('name-projection-device');
            $projection = $games->createGame($guest['identityId'], 'vanillaPvp', 'Training Grounds');

            expect_true(array_key_exists('name', $projection['memberships'][0]), 'memberships expose name');
            expect_same(null, $projection['memberships'][0]['name'], 'an unnamed seat projects null');
        },
```

- [ ] **Step 2: Run the tests and check they fail**

Run: `php api/tests/run.php`

Expected: the three new tests FAIL. The first fails with an error such as "no such table: schema_migrations", and the last with "memberships expose name". Every other test PASSES.

- [ ] **Step 3: Write the migrations**

`api/migrations/003_commander_names.sqlite.sql`:

```sql
ALTER TABLE game_memberships ADD COLUMN commander_name TEXT NULL;
```

`api/migrations/003_commander_names.mysql.sql`:

```sql
ALTER TABLE game_memberships ADD COLUMN commander_name VARCHAR(24) NULL;
```

- [ ] **Step 4: Make `Migrator` track applied migrations**

Replace the body of `migrate()` in `api/src/Database/Migrator.php`:

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
        $this->connection->exec($dialect === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB'
            : 'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $applied = $this->connection->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($paths as $path) {
            $name = basename($path, ".{$dialect}.sql");
            if (in_array($name, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$path}");
            }
            $this->connection->exec($sql);
            $this->connection->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :applied)')
                ->execute(['name' => $name, 'applied' => gmdate('Y-m-d H:i:s')]);
        }
    }
```

Add a doc comment above `final class Migrator`:

```php
/** Applies each migration file once, recording it in schema_migrations; 001 and 002 are idempotent so pre-tracking databases re-run them harmlessly. */
```

- [ ] **Step 5: Select and project the name**

In `api/src/Game/GameService.php`, change the `memberships()` query to:

```php
            'SELECT id, identity_id, seat_index, role, is_host, ready, commander_name FROM game_memberships WHERE game_id = :game ORDER BY seat_index'
```

In `buildProjection`, add `name` to the `memberships` map:

```php
            'memberships' => array_map(static fn(array $seat): array => [
                'seatId' => $seat['id'],
                'seatIndex' => (int)$seat['seat_index'],
                'role' => (string)$seat['role'],
                'ready' => (bool)$seat['ready'],
                'isHost' => (bool)$seat['is_host'],
                'name' => $seat['commander_name'] === null ? null : (string)$seat['commander_name'],
            ], $memberships),
```

- [ ] **Step 6: Run the tests and check they pass**

Run: `php api/tests/run.php`

Expected: every test passes, ending with `0 failures`.

- [ ] **Step 7: Commit**

```bash
git add api/src/Database/Migrator.php api/migrations/003_commander_names.sqlite.sql api/migrations/003_commander_names.mysql.sql api/src/Game/GameService.php api/tests/FoundationTest.php
git commit -m "feat: track applied migrations and add commander names column"
```

---

### Task 2: The `setCommanderName` command and its legal action

**Files:**
- Modify: `api/src/Game/GameService.php` (`LOBBY_COMMANDS` at about line 223, `applyLobbyCommand`, `lobbyActions`, and the `$legalActions` match in `buildProjection`)
- Create: `api/tests/CommanderNameTest.php`
- Modify: `api/tests/run.php`
- Modify: `api/tests/VanillaMatchServiceTest.php` (lines 82 and 85)
- Modify: `api/tests/CoopServiceTest.php` (lines 78 and 79)

**Interfaces:**
- Consumes:
  - `commander_name` and projection `memberships[].name` (Task 1)
  - the test helpers `vanilla_service_match()`, `vanilla_submit($match, $who, $kind, $payload, $commandId = null)`, `vanilla_draft_picks($match)`, `coop_service_match($heroPlayers)`, `coop_ready_all($match)` and `expect_game_error($code, $callable)`
- Produces:
  - the command kind `setCommanderName` with payload `{ name: string }`
  - the legal action `{ kind: 'setCommanderName', unitId: null, targetHexes: [], ability: null }` for seated players in `lobby`, `setup` and `draft`

- [ ] **Step 1: Write the failing tests**

Create `api/tests/CommanderNameTest.php`:

```php
<?php
declare(strict_types=1);

function commander_names(array $projection): array
{
    return array_column($projection['memberships'], 'name', 'seatId');
}

function commander_name_tests(): array
{
    return [
        'a commander names their seat in the lobby and every viewer sees it' => static function (): void {
            $match = vanilla_service_match();
            $before = $match['games']->getGame($match['gameId'], $match['host']['identity']);

            $named = vanilla_submit($match, 'host', 'setCommanderName', ['name' => "  Lord \t  Ash\u{0007}fang "]);

            expect_same($before['version'] + 1, $named['version'], 'naming bumps the version');
            expect_same('Lord Ashfang', commander_names($named)[$match['host']['seat']], 'whitespace collapses and control characters vanish');
            expect_same(null, commander_names($named)[$match['rival']['seat']], 'only the caller seat changes');
            expect_same('Lord Ashfang', commander_names($match['games']->getGame($match['gameId'], $match['rival']['identity']))[$match['host']['seat']], 'the rival sees the name');
            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('Lord Ashfang', commander_names($match['games']->getSpectatorGame($link['token']))[$match['host']['seat']], 'spectators see the name');
            $events = $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'];
            expect_same(false, in_array('setCommanderName', array_column($events, 'kind'), true), 'naming writes no event');
        },

        'an empty name clears the seat back to its default label' => static function (): void {
            $match = vanilla_service_match();
            vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Ashfang']);

            $cleared = vanilla_submit($match, 'host', 'setCommanderName', ['name' => "   \n "]);

            expect_same(null, commander_names($cleared)[$match['host']['seat']], 'blank input clears the name');
        },

        'names are limited to 24 characters, counted as characters' => static function (): void {
            $match = vanilla_service_match();
            expect_game_error('nameTooLong', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => str_repeat('a', 25)]));

            $accented = vanilla_submit($match, 'host', 'setCommanderName', ['name' => str_repeat('É', 24)]);

            expect_same(str_repeat('É', 24), commander_names($accented)[$match['host']['seat']], '24 multibyte characters fit');
        },

        'malformed names are refused without touching the seat' => static function (): void {
            $match = vanilla_service_match();
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => 5]));
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', []));
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => "Ash\xC3\x28"]));
            expect_same(null, commander_names($match['games']->getGame($match['gameId'], $match['host']['identity']))[$match['host']['seat']], 'refusals leave the name unset');
        },

        'two seats cannot share a name in any letter case' => static function (): void {
            $match = vanilla_service_match();
            vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Lord Ashfang']);

            expect_game_error('nameTaken', static fn() => vanilla_submit($match, 'rival', 'setCommanderName', ['name' => 'lord ASHFANG']));
            $resaved = vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Lord Ashfang']);

            expect_same('Lord Ashfang', commander_names($resaved)[$match['host']['seat']], 're-saving your own name is allowed');
        },

        'names stay editable through the draft and lock when battle begins' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $rivalDraft = $match['games']->getGame($match['gameId'], $match['rival']['identity']);
            expect_same(['setCommanderName'], array_column($rivalDraft['legalActions'], 'kind'), 'a waiting drafter may still rename');
            $named = vanilla_submit($match, 'rival', 'setCommanderName', ['name' => 'Iron Vow']);
            expect_same('Iron Vow', commander_names($named)[$match['rival']['seat']], 'renaming works during the draft');

            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $active = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same('active', $active['lifecycle'], 'the battle has begun');
            expect_same(false, in_array('setCommanderName', array_column($active['legalActions'], 'kind'), true), 'renaming is no longer offered');
            expect_game_error('illegalLifecycle', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Too Late']));
            expect_same('Iron Vow', commander_names($active)[$match['rival']['seat']], 'the drafted name carries into battle');
        },

        'co-op hero players can rename while the void lord sets up' => static function (): void {
            $match = coop_service_match();
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same(['setCommanderName'], array_column($hero['legalActions'], 'kind'), 'the hero may only rename during setup');
            $named = vanilla_submit($match, 'hero1', 'setCommanderName', ['name' => 'Seris']);

            expect_same('Seris', commander_names($named)[$match['hero1']['seat']], 'renaming works during setup');
        },

        'a retried rename returns the cached projection' => static function (): void {
            $match = vanilla_service_match();
            $first = vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Ashfang'], 'rename-once');

            $replayed = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => 'rename-once', 'expectedVersion' => $first['version'] - 1, 'kind' => 'setCommanderName', 'payload' => ['name' => 'Ashfang'],
            ]);

            expect_same($first['version'], $replayed['version'], 'the retry is idempotent');
        },
    ];
}
```

In `api/tests/run.php`, add `require __DIR__ . '/CommanderNameTest.php';` after the `CoopBattleTest.php` require. Then append `commander_name_tests()` to the end of the `array_merge(...)` argument list:

```php
require __DIR__ . '/CoopBattleTest.php';
require __DIR__ . '/CommanderNameTest.php';
```

```php
$tests = array_merge(config_tests(), http_request_factory_tests(), gateway_tests(), foundation_tests(), api_tests(), core_rules_tests(), scenario_tests(), vanilla_geometry_tests(), vanilla_draft_tests(), vanilla_battle_tests(), vanilla_ability_tests(), vanilla_forced_tests(), vanilla_terrain_tests(), vanilla_ice_tests(), vanilla_match_service_tests(), coop_service_tests(), coop_sides_tests(), coop_setup_tests(), coop_draft_tests(), mode_schedule_tests(), coop_battle_tests(), commander_name_tests());
```

The new legal action changes four existing assertions. Update them now, because they describe the intended behaviour.

`api/tests/VanillaMatchServiceTest.php` line 82:

```php
            expect_same(['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy', 'setCommanderName'], array_column($started['legalActions'], 'kind'), 'the picker sees every open decision');
```

`api/tests/VanillaMatchServiceTest.php` line 85:

```php
            expect_same(['setCommanderName'], array_column($match['games']->getGame($match['gameId'], $match['rival']['identity'])['legalActions'], 'kind'), 'the rival waits but may rename');
```

`api/tests/CoopServiceTest.php` lines 78–79:

```php
            expect_same(['placeMonster', 'setCommanderName'], array_column($started['legalActions'], 'kind'), 'the void lord may place monsters');
            expect_same(['setCommanderName'], array_column($match['games']->getGame($match['gameId'], $match['hero1']['identity'])['legalActions'], 'kind'), 'the hero player waits but may rename');
```

- [ ] **Step 2: Run the tests and check they fail**

Run: `php api/tests/run.php`

Expected: the new `commander_name_tests` and the four edited assertions FAIL, with errors such as "expected game error nameTooLong" and "unsupportedCommand" for every `setCommanderName` call. Every other test PASSES.

- [ ] **Step 3: Implement the command**

In `api/src/Game/GameService.php`, extend the lobby command list:

```php
    private const LOBBY_COMMANDS = ['setReady', 'claimVoidLord', 'releaseVoidLord', 'assignVoidLord', 'setCommanderName'];

    private const NAMING_LIFECYCLES = ['lobby', 'setup', 'draft'];
```

At the top of `applyLobbyCommand`, directly after the existing `$payload = ...` line and before `if ($kind === 'setReady')`, add:

```php
        if ($kind === 'setCommanderName') {
            $this->setCommanderName($game, $membership, $payload, $newVersion);
            return;
        }
```

Add the method directly after `applyLobbyCommand`:

```php
    /** Seat metadata, not game state: no rules engine, no event, and locked once battle begins. */
    private function setCommanderName(array $game, array $membership, array $payload, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $raw = $payload['name'] ?? null;
        $collapsed = is_string($raw) ? preg_replace('/\s+/u', ' ', $raw) : null;
        if ($collapsed === null) {
            throw new GameServiceException('unsupportedCommand', 'Commander names must be text.', $currentVersion);
        }
        if (!in_array($game['lifecycle'], self::NAMING_LIFECYCLES, true)) {
            throw new GameServiceException('illegalLifecycle', 'Commander names are locked once battle begins.', $currentVersion);
        }
        $name = trim((string)preg_replace('/\p{Cc}/u', '', $collapsed));
        $name = $name === '' ? null : $name;
        if ($name !== null && mb_strlen($name) > 24) {
            throw new GameServiceException('nameTooLong', 'Names can be at most 24 characters.', $currentVersion);
        }
        if ($name !== null) {
            foreach ($this->memberships($game['id']) as $seat) {
                if ((string)$seat['id'] !== (string)$membership['id']
                    && $seat['commander_name'] !== null
                    && mb_strtolower((string)$seat['commander_name']) === mb_strtolower($name)) {
                    throw new GameServiceException('nameTaken', 'Another commander already uses that name.', $currentVersion);
                }
            }
        }
        $this->connection->prepare('UPDATE game_memberships SET commander_name = :name WHERE game_id = :game AND id = :seat')
            ->execute(['name' => $name, 'game' => $game['id'], 'seat' => $membership['id']]);
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
    }
```

`preg_replace` with `/u` returns `null` on invalid UTF-8, so the `$collapsed === null` check covers both non-strings and bad bytes.

- [ ] **Step 4: Offer the legal action**

In `lobbyActions`, change the first line to:

```php
        $actions = [['kind' => 'setReady', 'unitId' => null, 'targetHexes' => []], ['kind' => 'setCommanderName', 'unitId' => null, 'targetHexes' => []]];
```

Run `php api/tests/run.php` right after this step. If an existing lobby assertion compares the full lobby action list, update it so `setCommanderName` follows `setReady`, as in Step 1.

In `buildProjection`, add this after the existing `$legalActions = match (true) { ... };` block and before the `array_map` that adds `'ability' => null`:

```php
        if ($viewer['role'] === 'player' && in_array($game['lifecycle'], ['setup', 'draft'], true)) {
            $legalActions[] = ['kind' => 'setCommanderName', 'unitId' => null, 'targetHexes' => []];
        }
```

The lobby case is already covered by `lobbyActions`. Spectators have `role` `'spectator'`, so they never get the action.

- [ ] **Step 5: Run the tests and check they pass**

Run: `php api/tests/run.php`

Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api/src/Game/GameService.php api/tests/CommanderNameTest.php api/tests/run.php api/tests/VanillaMatchServiceTest.php api/tests/CoopServiceTest.php
git commit -m "feat: let commanders name their seat before battle"
```

---

### Task 3: Contract field

**Files:**
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs:98-105`
- Modify: `tests/fixtures/game-projection.json` (the `seat-blue` membership, about line 52)
- Test: `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`

**Interfaces:**
- Consumes: the JSON field `memberships[].name` (Task 1)
- Produces: `MembershipProjection.Name` (`string?`, an init property, default `null`)

- [ ] **Step 1: Write the failing test**

In `tests/fixtures/game-projection.json`, add `"name": "Lord Ashfang"` to the `seat-blue` membership:

```json
    {
      "seatId": "seat-blue",
      "seatIndex": 2,
      "ready": true,
      "isHost": false,
      "name": "Lord Ashfang"
    }
```

In `GameProjection_fixture_round_trips_with_camel_case_contract`, after `Assert.True(projection.Memberships[1].Ready);`, add:

```csharp
        Assert.Null(projection.Memberships[0].Name);
        Assert.Equal("Lord Ashfang", projection.Memberships[1].Name);
```

- [ ] **Step 2: Run the test and check it fails**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`

Expected: the build FAILS with `'MembershipProjection' does not contain a definition for 'Name'`.

- [ ] **Step 3: Add the property**

```csharp
public sealed record MembershipProjection(
    string SeatId,
    int SeatIndex,
    bool Ready,
    bool IsHost)
{
    public string Role { get; init; } = "player";

    public string? Name { get; init; }
}
```

- [ ] **Step 4: Run the test and check it passes**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts/GameProjection.cs tests/fixtures/game-projection.json tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs
git commit -m "feat: carry commander names in the membership contract"
```

---

### Task 4: One seat-naming function that uses chosen names

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/SeatNames.cs` (replace `Coop` with `Display`)
- Modify: `src/BloodAndBanners.UI/Components/DraftTable.razor` (`SeatName`)
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`:
  - `TurnStatus`, at about lines 609–616
  - `SeatName`, at about line 1189
  - `RosterTitle`, at about line 1207
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs` (`Seat`, at about line 210)
- Modify: `src/BloodAndBanners.UI/Components/WarRoomLobby.razor:55`
- Create: `tests/BloodAndBanners.UI.Tests/SeatNamesTests.cs`
- Modify: `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs`
- Modify: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`
- Modify: `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`
- Modify: `tests/BloodAndBanners.UI.Tests/WarRoomLobbyTests.cs`

**Interfaces:**
- Consumes: `MembershipProjection.Name` (Task 3)
- Produces: `SeatNames.Display(GameProjection projection, string? seatId) -> string`. `SeatNames.Coop` is removed.

- [ ] **Step 1: Write the failing tests**

Create `tests/BloodAndBanners.UI.Tests/SeatNamesTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class SeatNamesTests
{
    private static GameProjection Duel(string role, string? viewerSeat, string? blueName = null) =>
        BattleFixtures.Projection() with
        {
            Viewer = new ViewerPermissions(role, viewerSeat, role == "player", false),
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = blueName },
            ],
        };

    private static GameProjection Coop(string? voidLordName = null, string? heroName = null) =>
        BattleFixtures.Projection() with
        {
            Mode = GameMode.Coop,
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Role = "voidLord", Name = voidLordName },
                new MembershipProjection("seat-green", 3, true, false) { Name = heroName },
            ],
        };

    [Fact]
    public void The_viewer_is_always_you_even_when_named()
    {
        var projection = Duel("player", "seat-blue", blueName: "Lord Ashfang");

        Assert.Equal("You", SeatNames.Display(projection, "seat-blue"));
    }

    [Fact]
    public void Chosen_names_replace_the_default_labels()
    {
        Assert.Equal("Lord Ashfang", SeatNames.Display(Duel("player", "seat-red", "Lord Ashfang"), "seat-blue"));
        Assert.Equal("Lord Ashfang", SeatNames.Display(Duel("spectator", null, "Lord Ashfang"), "seat-blue"));
        Assert.Equal("Seris", SeatNames.Display(Coop(heroName: "Seris"), "seat-green"));
        Assert.Equal("Morrow (Void Lord)", SeatNames.Display(Coop(voidLordName: "Morrow"), "seat-blue"));
    }

    [Fact]
    public void Unnamed_seats_keep_todays_labels()
    {
        Assert.Equal("Rival", SeatNames.Display(Duel("player", "seat-red"), "seat-blue"));
        Assert.Equal("Commander 2", SeatNames.Display(Duel("spectator", null), "seat-blue"));
        Assert.Equal("Void Lord", SeatNames.Display(Coop(), "seat-blue"));
        Assert.Equal("Commander 3", SeatNames.Display(Coop(), "seat-green"));
        Assert.Equal("A commander", SeatNames.Display(Coop(), null));
    }
}
```

`BattleFixtures.Projection()` uses viewer `seat-red` in `VanillaPvp`. It is defined in `GameTableTests.cs` at about line 800.

Append these tests to `DraftTableTests`:

```csharp
    [Fact]
    public void Chosen_names_appear_in_claims_and_initiative()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "Lord Ashfang" },
            ],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("Claimed by Lord Ashfang", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);
        Assert.Contains("Lord Ashfang rolled 4", cut.Find("[data-testid=initiative]").TextContent);
    }

    [Fact]
    public void Names_with_markup_render_as_plain_text()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "<b>Ash</b>" },
            ],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("Claimed by <b>Ash</b>", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);
        Assert.Empty(cut.FindAll(".taken-by b"));
    }
```

Append this test to `CombatLogFormatterTests`:

```csharp
    [Fact]
    public void Turn_lines_use_the_chosen_name()
    {
        var projection = BattleFixtures.Projection() with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "Lord Ashfang" },
            ],
        };

        Assert.Equal(
            "Round 3: Lord Ashfang's turn.",
            CombatLogFormatter.Describe(Event("turnEnded", """{"seatId":"seat-red","nextSeatId":"seat-blue","round":3}"""), projection));
    }
```

Append this test to `GameTableTests` in `GameTableTests.cs`. Before adding it, check how the existing `GameTableTests` render the table (the parameter names `Projection` and `Events`), and follow that pattern:

```csharp
    [Fact]
    public void The_turn_banner_names_a_named_opponent()
    {
        var projection = BattleFixtures.Projection() with
        {
            Turn = new TurnProjection(2, "seat-blue", 3),
            LegalActions = [],
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "Lord Ashfang" },
            ],
        };

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.Events, new List<GameEvent>()));

        Assert.Contains("Lord Ashfang's turn", cut.Find("[data-testid=turn-status]").TextContent);
        Assert.Contains("Lord Ashfang's army", cut.Markup);
    }
```

If `GameTable.Events` is not a `List<GameEvent>`, use the type the existing tests pass.

Append this test to `WarRoomLobbyTests`:

```csharp
    [Fact]
    public void Named_seats_show_their_name_in_the_muster()
    {
        var projection = CoopLobby("seat-1", true, null) with
        {
            Memberships =
            [
                new MembershipProjection("seat-1", 1, true, true) { Name = "Seris" },
                new MembershipProjection("seat-2", 2, true, false),
            ],
        };

        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, projection));

        Assert.Contains("Seris (You)", cut.Find("li[data-seat='1'] strong").TextContent);
        Assert.Contains("Commander 2", cut.Find("li[data-seat='2'] strong").TextContent);
    }
```

- [ ] **Step 2: Run the tests and check they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`

Expected: the build FAILS because `SeatNames.Display` does not exist.

- [ ] **Step 3: Implement `SeatNames.Display`**

Replace the `Coop` method in `src/BloodAndBanners.UI/Services/SeatNames.cs`, and update the class summary:

```csharp
/// <summary>How screens name seats: the viewer is "You", then a chosen commander name, then the mode's default label.</summary>
public static class SeatNames
{
    public static bool IsVoidLord(GameProjection projection, string? seatId) =>
        seatId is not null && projection.Memberships.Any(member => member.SeatId == seatId && member.Role == "voidLord");

    public static string Display(GameProjection projection, string? seatId)
    {
        if (seatId is null)
        {
            return "A commander";
        }
        if (seatId == projection.Viewer.SeatId)
        {
            return "You";
        }
        var seat = projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        var voidLord = IsVoidLord(projection, seatId);
        if (!string.IsNullOrEmpty(seat?.Name))
        {
            return voidLord ? $"{seat.Name} (Void Lord)" : seat.Name;
        }
        if (projection.Mode == GameMode.Coop && voidLord)
        {
            return "Void Lord";
        }
        if (projection.Mode != GameMode.Coop && projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        return seat is null ? "A commander" : $"Commander {seat.SeatIndex}";
    }

    public static string? SideOf(GameProjection projection, string? seatId) =>
        seatId is null
            ? null
            : projection.Sides?.FirstOrDefault(side => side.Value.SeatIds.Contains(seatId)).Key ?? seatId;
}
```

- [ ] **Step 4: Replace the duplicated helpers**

`DraftTable.razor`: replace the whole `SeatName` method with:

```csharp
    private string SeatName(string seatId) => SeatNames.Display(Projection, seatId);
```

`CombatLogFormatter.cs`: replace the whole `Seat` method with:

```csharp
    private static string Seat(GameProjection projection, string seatId) => SeatNames.Display(projection, seatId);
```

Then replace any remaining `SeatNames.Coop(` in that file with `SeatNames.Display(`.

`GameTable.razor`:
- Replace the whole `SeatName` method with:

  ```csharp
      private string SeatName(string seatId) => SeatNames.Display(Projection, seatId);
  ```

- Replace every `SeatNames.Coop(` with `SeatNames.Display(`.
- In `TurnStatus`, change the final vanilla line to:

  ```csharp
              var opponent = Projection.Memberships.FirstOrDefault(member => member.SeatId == Projection.Turn.ActiveSeatId)?.Name;
              return Projection.Viewer.CanCommand
                  ? $"{round} · {(string.IsNullOrEmpty(opponent) ? "Opponent" : opponent)}'s turn"
                  : $"{round} · {SeatName(Projection.Turn.ActiveSeatId)}'s turn";
  ```

- In `RosterTitle`, change the last vanilla branch so a named rival's roster reads "{name}'s army":

  ```csharp
          : seat.SeatId == Projection.Viewer.SeatId
              ? "Your army"
              : !string.IsNullOrEmpty(seat.Name) ? $"{seat.Name}'s army"
              : Projection.Viewer.Role == "player" ? "Rival army" : $"Commander {seat.SeatIndex}";
  ```

`WarRoomLobby.razor` line 55:

```razor
                            <strong>@(string.IsNullOrEmpty(seat.Name) ? $"Commander {seat.SeatIndex}" : seat.Name) @ViewerLabel(seat)</strong>
```

Then run `grep -rn "SeatNames.Coop" src tests --include=*.cs --include=*.razor`. Expected: no output.

- [ ] **Step 5: Run the tests and check they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`

Expected: every test passes. The existing tests stay green without edits.

One known risk: `Display` returns "A commander" for an unknown seat, where the old `DraftTable`/`GameTable` helpers returned "Commander". If an existing assertion pins "Commander" for a missing seat, keep the new behaviour and update that assertion. Note the change in the commit message.

- [ ] **Step 6: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "feat: show chosen commander names across the table, draft, log and lobby"
```

---

### Task 5: `CommanderNameField` in the lobby and the draft

**Files:**
- Create: `src/BloodAndBanners.UI/Components/CommanderNameField.razor`
- Create: `src/BloodAndBanners.UI/Components/CommanderNameField.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/DraftTable.razor` (the header ribbon)
- Modify: `src/BloodAndBanners.UI/Components/WarRoomLobby.razor` (the muster section, after `</ol>`)
- Create: `tests/BloodAndBanners.UI.Tests/CommanderNameFieldTests.cs`
- Modify (do not stage): `docs/todo.md` — it is untracked; remove "Ability to name yourself before joining" locally only

**Interfaces:**
- Consumes:
  - `SeatNames` (Task 4)
  - `MembershipProjection.Name` (Task 3)
  - the legal action kind `setCommanderName` (Task 2)
  - `IKeyValueStore` (`GetAsync`, `SetAsync`, `RemoveAsync`) from `Services/GameSessionStore.cs`
  - `GameCommandRequest(string Kind, object Payload)`
- Produces: `<CommanderNameField Projection IsBusy OnCommand />` and the constant `CommanderNameField.StorageKey = "bloodAndBanners.commanderName"`

- [ ] **Step 1: Write the failing tests**

Create `tests/BloodAndBanners.UI.Tests/CommanderNameFieldTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

public sealed class CommanderNameFieldTests : BunitContext
{
    private sealed class MemoryStore : IKeyValueStore
    {
        public Dictionary<string, string> Values { get; } = [];

        public ValueTask<string?> GetAsync(string key) => ValueTask.FromResult(Values.GetValueOrDefault(key));

        public ValueTask SetAsync(string key, string value)
        {
            Values[key] = value;
            return ValueTask.CompletedTask;
        }

        public ValueTask RemoveAsync(string key)
        {
            Values.Remove(key);
            return ValueTask.CompletedTask;
        }
    }

    private sealed class BrokenStore : IKeyValueStore
    {
        public ValueTask<string?> GetAsync(string key) => throw new InvalidOperationException("storage blocked");

        public ValueTask SetAsync(string key, string value) => throw new InvalidOperationException("storage blocked");

        public ValueTask RemoveAsync(string key) => throw new InvalidOperationException("storage blocked");
    }

    private static GameProjection Draft(string? myName = null, bool canRename = true) =>
        DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") with
        {
            LegalActions = canRename ? [new LegalAction("setCommanderName", null, [])] : [],
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true) { Name = myName },
                new MembershipProjection("seat-blue", 2, true, false),
            ],
        };

    [Fact]
    public void The_field_is_hidden_when_renaming_is_not_legal()
    {
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft(canRename: false)));

        Assert.Empty(cut.FindAll("[data-testid=commander-name]"));
    }

    [Fact]
    public void The_current_name_wins_over_the_remembered_one()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Remembered";
        Services.AddSingleton<IKeyValueStore>(store);

        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft(myName: "Current")));

        cut.WaitForAssertion(() => Assert.Equal("Current", cut.Find("input").GetAttribute("value")));
        Assert.True(cut.Find("button[data-action=setCommanderName]").HasAttribute("disabled"));
    }

    [Fact]
    public void An_unnamed_seat_is_prefilled_from_memory_and_saves_it()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Lord Ashfang";
        Services.AddSingleton<IKeyValueStore>(store);
        GameCommandRequest? sent = null;

        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent = request));
        cut.WaitForAssertion(() => Assert.Equal("Lord Ashfang", cut.Find("input").GetAttribute("value")));
        cut.Find("form").Submit();

        Assert.Equal("setCommanderName", sent?.Kind);
        Assert.Equal("Lord Ashfang", JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("name").GetString());
    }

    [Fact]
    public void The_server_cleaned_name_replaces_the_input_and_is_remembered()
    {
        var store = new MemoryStore();
        Services.AddSingleton<IKeyValueStore>(store);
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft()));
        cut.Find("input").Input("  Lord   Ash ");

        cut.Render(parameters => parameters.Add(c => c.Projection, Draft(myName: "Lord Ash")));

        cut.WaitForAssertion(() => Assert.Equal("Lord Ash", cut.Find("input").GetAttribute("value")));
        Assert.Equal("Lord Ash", store.Values[CommanderNameField.StorageKey]);
    }

    [Fact]
    public void Broken_or_missing_storage_still_allows_naming()
    {
        Services.AddSingleton<IKeyValueStore>(new BrokenStore());
        GameCommandRequest? sent = null;
        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent = request));

        cut.WaitForAssertion(() => Assert.Equal(string.Empty, cut.Find("input").GetAttribute("value") ?? string.Empty));
        cut.Find("input").Input("Seris");
        cut.Find("form").Submit();
        cut.Render(parameters => parameters.Add(c => c.Projection, Draft(myName: "Seris")));

        Assert.Equal("Seris", JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("name").GetString());
    }

    [Fact]
    public void Saving_is_disabled_while_busy()
    {
        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.IsBusy, true));
        cut.Find("input").Input("Seris");

        Assert.True(cut.Find("button[data-action=setCommanderName]").HasAttribute("disabled"));
    }
}
```

`Broken_or_missing_storage_still_allows_naming` uses a broken store. The "missing" case is covered by `The_field_is_hidden_when_renaming_is_not_legal` and `Saving_is_disabled_while_busy`, which register no store at all.

Append these tests to `DraftTableTests` and `WarRoomLobbyTests`. In `DraftTableTests`:

```csharp
    [Fact]
    public void The_draft_ribbon_offers_the_name_field_when_renaming_is_legal()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            LegalActions = [new LegalAction("pickHero", null, []), new LegalAction("setCommanderName", null, [])],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.NotNull(cut.Find(".draft-ribbon [data-testid=commander-name]"));
    }
```

In `WarRoomLobbyTests`:

```csharp
    [Fact]
    public void The_muster_offers_the_name_field()
    {
        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, CoopLobby("seat-1", true, null, "setReady", "setCommanderName")));

        Assert.NotNull(cut.Find(".war-room__muster [data-testid=commander-name]"));
    }
```

- [ ] **Step 2: Run the tests and check they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`

Expected: the build FAILS because `CommanderNameField` does not exist.

- [ ] **Step 3: Implement the component**

Create `src/BloodAndBanners.UI/Components/CommanderNameField.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services
@inject IServiceProvider ServiceProvider

@if (CanRename)
{
    <form class="commander-name" data-testid="commander-name" @onsubmit="Save" @onsubmit:preventDefault>
        <label for="commander-name-input">Commander name</label>
        <input id="commander-name-input"
               maxlength="24"
               autocomplete="nickname"
               placeholder="Name your commander"
               @bind="_draft"
               @bind:event="oninput" />
        <button type="submit" data-action="setCommanderName" disabled="@(IsBusy || Unchanged)">Save</button>
    </form>
}

@code {
    /// <summary>Remembers the last saved name so the next match is prefilled; never sent without the player pressing Save.</summary>
    public const string StorageKey = "bloodAndBanners.commanderName";

    [Parameter, EditorRequired]
    public GameProjection Projection { get; set; } = default!;

    [Parameter]
    public bool IsBusy { get; set; }

    [Parameter]
    public EventCallback<GameCommandRequest> OnCommand { get; set; }

    private string _draft = string.Empty;
    private string? _syncedName;
    private bool _initialised;

    private string? CurrentName =>
        Projection.Memberships.FirstOrDefault(member => member.SeatId == Projection.Viewer.SeatId)?.Name;

    private bool CanRename =>
        Projection.Viewer.CanCommand && Projection.LegalActions.Any(action => action.Kind == "setCommanderName");

    private bool Unchanged => _draft.Trim() == (CurrentName ?? string.Empty);

    // Optional so screens and tests without storage still work.
    private IKeyValueStore? Store => ServiceProvider.GetService(typeof(IKeyValueStore)) as IKeyValueStore;

    protected override async Task OnParametersSetAsync()
    {
        var current = CurrentName;
        if (!_initialised)
        {
            _initialised = true;
            _syncedName = current;
            _draft = current ?? await Remembered() ?? string.Empty;
            return;
        }
        if (current != _syncedName)
        {
            _syncedName = current;
            _draft = current ?? string.Empty;
            if (current is not null)
            {
                await Remember(current);
            }
        }
    }

    private Task Save() => OnCommand.InvokeAsync(new GameCommandRequest("setCommanderName", new { name = _draft }));

    private async Task<string?> Remembered()
    {
        try
        {
            return Store is { } store ? await store.GetAsync(StorageKey) : null;
        }
        catch (Exception)
        {
            return null;
        }
    }

    private async Task Remember(string name)
    {
        try
        {
            if (Store is { } store)
            {
                await store.SetAsync(StorageKey, name);
            }
        }
        catch (Exception)
        {
            // Remembering is a convenience; naming still works without storage.
        }
    }
}
```

Create `src/BloodAndBanners.UI/Components/CommanderNameField.razor.css`. Before writing it, read `DraftTable.razor.css` and use the same CSS variables and font sizes. This skeleton uses `currentColor` so it is themable:

```css
.commander-name {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.commander-name label {
    font-size: 0.85rem;
    opacity: 0.8;
}

.commander-name input {
    min-width: 0;
    flex: 1 1 10rem;
    max-width: 16rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid currentColor;
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
}
```

- [ ] **Step 4: Place the field**

In `DraftTable.razor`, add this inside `<header class="draft-ribbon">`, after the `draft-status` paragraph:

```razor
        <CommanderNameField Projection="Projection" IsBusy="IsBusy" OnCommand="OnCommand" />
```

In `WarRoomLobby.razor`, add this inside `<section class="war-room__muster">`, directly after `</ol>`:

```razor
        <CommanderNameField Projection="Projection" IsBusy="IsBusy" OnCommand="OnCommand" />
```

In `docs/todo.md`, delete the line `- Ability to name yourself before joining`. Do **not** `git add` this file: it is untracked and belongs to the user.

- [ ] **Step 5: Run the tests and check they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`

Expected: every test passes.

Also run `php api/tests/run.php` and `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`. Expected: both green.

- [ ] **Step 6: Check it in the real app**

Use the `run` skill, or the README's dev instructions, to start the API and the web client. Then:

1. Open two browser profiles.
2. Create a duel and join it from the second profile.
3. Name one commander in the lobby and check the other profile shows it.
4. Start the draft and rename. Check the claim text and the initiative line.
5. Finish the draft and check:
   - the field disappears
   - the turn banner and combat log use the name
   - a reload prefills the field in a new match

- [ ] **Step 7: Commit**

```bash
git add src/BloodAndBanners.UI/Components/CommanderNameField.razor src/BloodAndBanners.UI/Components/CommanderNameField.razor.css src/BloodAndBanners.UI/Components/DraftTable.razor src/BloodAndBanners.UI/Components/WarRoomLobby.razor tests/BloodAndBanners.UI.Tests
git commit -m "feat: name your commander from the lobby and the draft"
```
