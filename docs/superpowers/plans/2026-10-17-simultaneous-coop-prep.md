# Simultaneous Co-op Preparation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In Co-op, the Void Lord places monsters while the war room is still open. After Start, the heroes draft while the Void Lord finishes. The battle begins once both sides are done. Each side's choices stay hidden from the other until then.

**Architecture:**
- **Lobby state:** a setup-only Co-op state is saved as soon as the lobby has a Void Lord. Its budget is recalculated on every membership change.
- **`prep` phase:** Start produces a new engine phase, `prep`, stored as lifecycle `draft`. In `prep`, a new `CoopPrep` router accepts both setup and draft commands and deploys the heroes when both are finished.
- **Hiding information:**
  - `GameService::buildProjection` redacts per viewer side.
  - A new `visible_side` column hides each side's prep events from the other until the battle.
  - The client re-fetches the whole log when the battle starts.

**Tech Stack:** PHP 8.3 (no framework; tests via `php api/tests/run.php`), .NET 10 Blazor component library + bUnit/xUnit (`dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`).

**Spec:** `docs/superpowers/specs/2026-10-17-simultaneous-coop-prep-design.md`

## Global Constraints

- Budget formula is unchanged: `basePoints` (default 4) + `pointsPerHeroPlayer` (default 8) × hero seats. The rules come from the scenario's `coop` block.
- Hidden until battle: hero players and spectators never receive void units, setup numbers or monster events before the engine phase is `active`. The Void Lord and spectators never receive hero picks before then.
- The hero draft opens only after Start. `confirmSetup` is refused in the lobby with `rosterNotLocked`. Confirming is final.
- Games saved in engine phase `setup` (old flow) must still go setup → draft → active unchanged.
- Vanilla PvP behaviour and projections must not change. The whole PHP suite and the whole UI suite stay green.
- New error message text, verbatim: `rosterNotLocked` → "The Void Lord confirms once the host starts the match."

## Deviations from the spec (simplifications, flagged to the user)

- **No `battleBegins` event.** The existing public `armiesPlaced` event already marks the battle start. The client re-fetches the full event history when a Co-op game moves from Draft to Active, which is how the hidden log "fills in".
- **No Start button on the setup board.** The Void Lord's board in the lobby shows the war-room status line and a "Back to war room" button. Start stays in the war room.

## Review Focus

1. **A hero leaves after the Void Lord has spent the budget.** The budget shrinks, monsters stay, Confirm is not offered, and a `confirmSetup` command is refused with `budgetExceeded`. *(Task 1 test "an over-budget horde cannot be confirmed"; Task 2 test "leaves shrink the lobby budget".)*
2. **The Void Lord role changes hands in the lobby after monsters are placed.** The monsters belong to the new Void Lord; the old one loses setup actions. *(Task 2 test "reassigning the void lord hands over the monsters".)*
3. **Leaks through any projection field.** A hero's or spectator's JSON projection during lobby or prep never contains a monster unit id. *(Task 3 test "no projection field leaks a monster id".)*
4. **The client's event cursor at the reveal.** The poll or command path must fetch from sequence 0 after the Draft → Active switch, or the hidden history is lost. *(Task 5 test "the reveal re-fetches the whole log".)*
5. **The Void Lord confirms first and the last hero pick ends the draft.** That pick must deploy and start turn 1, with `armiesPlaced` left public. *(Task 2 test "confirm first, then the draft finishes the battle start"; Task 3 test "events stay with their side until the battle".)*

---

### Task 1: Engine — the `prep` phase

**Files:**
- Create: `api/src/Rules/Vanilla/Modes/CoopPrep.php`
- Modify: `api/src/Rules/Vanilla/Modes/CoopMode.php`, `api/src/Rules/Vanilla/Modes/CoopSetup.php`, `api/src/Rules/Vanilla/Modes/CoopDraft.php`, `api/src/Rules/Vanilla/MatchEngine.php`
- Create test: `api/tests/CoopPrepTest.php` (register in `api/tests/run.php`: add the `require` after `CoopDraftTest.php` and add `coop_prep_tests()` after `coop_draft_tests()` in the `array_merge`)
- Modify tests: `api/tests/CoopSetupTest.php`, `api/tests/CoopTestSupport.php` (only if a helper needs it)

**Interfaces:**
- Produces:
  - `CoopMode::budget(array $rules, int $heroPlayers): int`
  - `CoopMode::lobbySetup(array $board, string $voidLordSeatId, int $budget): array`
  - `CoopMode::handOver(array $state, string $voidLordSeatId): array`
  - `CoopMode::start(...)` accepts `$options['setupState']` (?array) and returns `phase: 'prep'`
  - `CoopDraft::complete(array $state): bool`
  - `CoopDraft::deploy(array $state): array` (now public; returns `[$state, $placed]`)
  - `CoopSetup` refuses everything after confirm with `setupConfirmed` and refuses an over-budget confirm with `budgetExceeded`

- [ ] **Step 1: Write the failing tests** in `api/tests/CoopPrepTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;

/** A legacy save: the old flow where setup came before the draft. */
function coop_legacy_setup(): array
{
    $state = coop_start()['state'];
    $state['phase'] = 'setup';
    $state['draft']['pickingSeatId'] = null;
    return $state;
}

function coop_prep_tests(): array
{
    return [
        'co-op opens in prep with the draft and setup both open' => static function (): void {
            $state = coop_start()['state'];
            $engine = new MatchEngine(test_catalog());
            expect_same('prep', $state['phase'], 'preparation is simultaneous');
            expect_same('hero-a', $state['draft']['pickingSeatId'], 'the initiative winner picks at once');
            expect_same(['pickHero', 'pickArmy'], array_column($engine->legalActions($state, 'hero-a'), 'kind'), 'the first hero may pick');
            expect_same([], $engine->legalActions($state, 'hero-b'), 'the second hero waits for their pick');
            expect_same(['placeMonster'], array_values(array_unique(array_column($engine->legalActions($state, 'void-lord'), 'kind'))), 'the void lord places meanwhile');
        },

        'the draft can finish first and waits for the void lord' => static function (): void {
            $state = coop_command(coop_start()['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $state = coop_command($state, $seat, $kind, $payload)['state'];
            }
            expect_same(['prep', null], [$state['phase'], $state['draft']['pickingSeatId']], 'the heroes wait in prep');
            expect_same(['v-m1'], array_keys($state['units']), 'no hero is deployed yet');
            $done = coop_command($state, 'void-lord', 'confirmSetup');
            expect_same('active', $done['state']['phase'], 'the confirm starts the battle');
            expect_same(['setupConfirmed', 'armiesPlaced'], array_column($done['events'], 'kind'), 'deployment follows the confirm');
            expect_same(['hero-a', 1], [$done['state']['turn']['activeSeatId'], $done['state']['turn']['round']], 'the first hero opens round one');
            expect_same(17, count($done['state']['units']), 'sixteen hero units join the wolf');
        },

        'the void lord can confirm first and the last pick starts the battle' => static function (): void {
            $state = coop_ready_draft();
            expect_same(['prep', true], [$state['phase'], $state['setup']['confirmed']], 'confirming keeps the draft running');
            $result = null;
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $result = coop_command($state, $seat, $kind, $payload);
                $state = $result['state'];
            }
            expect_same('active', $state['phase'], 'the last pick starts the battle');
            expect_same(['draftPicked', 'armiesPlaced'], array_column($result['events'], 'kind'), 'deployment follows the pick');
        },

        'an over-budget horde cannot be confirmed' => static function (): void {
            $state = coop_command(coop_start()['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '7,4'])['state'];
            $state['setup']['budget'] = 2;
            $engine = new MatchEngine(test_catalog());
            expect_true(!in_array('confirmSetup', array_column($engine->legalActions($state, 'void-lord'), 'kind'), true), 'confirm is not offered');
            expect_game_error('budgetExceeded', static fn() => coop_command($state, 'void-lord', 'confirmSetup'));
            $state = coop_command($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1'])['state'];
            $state = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            expect_same(true, coop_command($state, 'void-lord', 'confirmSetup')['state']['setup']['confirmed'], 'back in budget it confirms');
        },

        'after confirming the void lord has no setup orders' => static function (): void {
            $state = coop_ready_draft();
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($state, 'void-lord'), 'nothing is offered');
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,1']));
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1']));
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'confirmSetup'));
        },

        'a legacy setup save still runs setup then draft' => static function (): void {
            $state = coop_command(coop_legacy_setup(), 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($state, 'hero-a'), 'heroes wait in the old setup');
            $state = coop_command($state, 'void-lord', 'confirmSetup')['state'];
            expect_same(['draft', 'hero-a'], [$state['phase'], $state['draft']['pickingSeatId']], 'the old confirm opens the draft');
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $state = coop_command($state, $seat, $kind, $payload)['state'];
            }
            expect_same('active', $state['phase'], 'the old draft still ends in battle');
        },

        'start carries the lobby horde over and sets the final budget' => static function (): void {
            $lobby = CoopMode::lobbySetup(coop_board(), 'void-lord', CoopMode::budget(['basePoints' => 4, 'pointsPerHeroPlayer' => 8], 3));
            expect_same(['setup', 28, []], [$lobby['phase'], $lobby['setup']['budget'], $lobby['initiative']['order']], 'the lobby board has no heroes yet');
            $lobby = coop_command($lobby, 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '7,4'])['state'];
            $lobby = coop_command($lobby, 'void-lord', 'placeMonster', ['monster' => 'Test Golem', 'hex' => '7,5'])['state'];
            $state = (new MatchEngine(test_catalog()))->start(
                ['hero-a', 'hero-b', 'void-lord'], coop_board(), new SequenceRandom([15, 5]), 'coop',
                ['voidLordSeatId' => 'void-lord', 'coop' => ['basePoints' => 4, 'pointsPerHeroPlayer' => 8], 'setupState' => $lobby]
            )['state'];
            expect_same(['v-m1', 'v-m2'], array_keys($state['units']), 'placed monsters carry over');
            expect_same([20, 6, 3], [$state['setup']['budget'], $state['setup']['spent'], $state['setup']['nextMonster']], 'spend and ids carry over; budget follows the locked roster');
        },

        'handing over moves every monster to the new void lord' => static function (): void {
            $lobby = CoopMode::lobbySetup(coop_board(), 'old-lord', 12);
            $lobby = coop_command($lobby, 'old-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            $moved = CoopMode::handOver($lobby, 'new-lord');
            expect_same(['new-lord', 'new-lord', ['new-lord']], [$moved['voidLordSeatId'], $moved['units']['v-m1']['seatId'], $moved['sides']['void']['seatIds']], 'the horde changes hands');
            expect_game_error('notVoidLord', static fn() => coop_command($moved, 'old-lord', 'removeMonster', ['unitId' => 'v-m1']));
        },
    ];
}
```

Also update the existing tests in `api/tests/CoopSetupTest.php`:
- `'a co-op match opens with the void lord setting up and hero initiative rolled'`: change the phase expectation to `'prep'`. Change the hero assertion to `expect_same(['pickHero', 'pickArmy'], array_column($engine->legalActions($state, 'hero-a'), 'kind'), 'the first hero drafts during setup');`.
- `'confirming setup needs a monster and opens the hero draft'`: rename it to `'confirming setup needs a monster and locks the horde'`. Expect `'prep'` instead of `'draft'`, and keep the `pickingSeatId` and event assertions.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: the new `coop_prep` tests FAIL (undefined `CoopMode::lobbySetup`, phase `setup` instead of `prep`), along with the two edited setup tests.

- [ ] **Step 3: Implement**

`api/src/Rules/Vanilla/Modes/CoopMode.php`:
- Add the three static helpers below `layout()`.
- Change `start()` to build the state with `'phase' => 'prep'` and an open draft, carrying `setupState` over.
- Route `prep` to `CoopPrep`.

```php
    /** Tier points for the Void Lord: base points plus a share per hero player. */
    public static function budget(array $rules, int $heroPlayers): int
    {
        return (int)($rules['basePoints'] ?? 4) + (int)($rules['pointsPerHeroPlayer'] ?? 8) * $heroPlayers;
    }

    /** The Void Lord's board while the war room is still open: no hero seats, initiative or draft yet. */
    public static function lobbySetup(array $board, string $voidLordSeatId, int $budget): array
    {
        self::layout($board);
        return [
            'mode' => 'coop',
            'phase' => 'setup',
            'seats' => [$voidLordSeatId],
            'voidLordSeatId' => $voidLordSeatId,
            'sides' => [
                'heroes' => ['kind' => 'heroes', 'seatIds' => []],
                'void' => ['kind' => 'void', 'seatIds' => [$voidLordSeatId]],
            ],
            'board' => $board,
            'initiative' => ['rolls' => [], 'order' => []],
            'setup' => ['budget' => $budget, 'spent' => 0, 'nextMonster' => 1, 'confirmed' => false],
            'draft' => ['pickingSeatId' => null, 'pickIndex' => 0, 'firstSeatId' => null, 'heroesBySeat' => [], 'armyBySeat' => []],
            'units' => [],
            'effects' => [],
            'conditions' => [],
            'pending' => null,
            'turn' => Turn::idle(),
            'result' => null,
        ];
    }

    /** The lobby horde follows the Void Lord role to its new seat. */
    public static function handOver(array $state, string $voidLordSeatId): array
    {
        foreach ($state['units'] as $unitId => $unit) {
            $state['units'][$unitId]['seatId'] = $voidLordSeatId;
        }
        $state['voidLordSeatId'] = $voidLordSeatId;
        $state['seats'] = [$voidLordSeatId];
        $state['sides']['void']['seatIds'] = [$voidLordSeatId];
        return $state;
    }
```

In `start()`, replace the budget line and the state literal's `phase`/`setup`/`draft`/`units`/`effects`/`conditions` entries:

```php
        $budget = self::budget($options['coop'] ?? [], count($heroSeats));
        $lobby = is_array($options['setupState'] ?? null) ? $options['setupState'] : null;
        $state = [
            'mode' => 'coop',
            'phase' => 'prep',
            // ... seats, voidLordSeatId, sides, board, initiative unchanged ...
            'setup' => [
                'budget' => $budget,
                'spent' => (int)($lobby['setup']['spent'] ?? 0),
                'nextMonster' => (int)($lobby['setup']['nextMonster'] ?? 1),
                'confirmed' => false,
            ],
            'draft' => [
                'pickingSeatId' => $order[0],
                'pickIndex' => 0,
                'firstSeatId' => null,
                'heroesBySeat' => array_fill_keys($order, []),
                'armyBySeat' => array_fill_keys($order, null),
            ],
            'units' => $lobby['units'] ?? [],
            'effects' => $lobby['effects'] ?? [],
            'conditions' => $lobby['conditions'] ?? [],
            // ... pending, turn, result unchanged ...
        ];
```

Replace `applyPreBattle` / `legalPreBattle`:

```php
    public function applyPreBattle(array $state, string $seatId, string $kind, array $payload): array
    {
        return match ($state['phase']) {
            'setup' => (new CoopSetup($this->catalog()))->apply($state, $seatId, $kind, $payload),
            'prep' => (new CoopPrep($this->catalog()))->apply($state, $seatId, $kind, $payload),
            default => (new CoopDraft($this->catalog()))->apply($state, $seatId, $kind, $payload),
        };
    }

    public function legalPreBattle(array $state, string $seatId): array
    {
        return match ($state['phase']) {
            'setup' => (new CoopSetup($this->catalog()))->legalActions($state, $seatId),
            'prep' => (new CoopPrep($this->catalog()))->legalActions($state, $seatId),
            default => (new CoopDraft($this->catalog()))->legalActions($state, $seatId),
        };
    }
```

Create `api/src/Rules/Vanilla/Modes/CoopPrep.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** The Void Lord's setup and the hero draft run side by side; the battle begins when both are done. */
final class CoopPrep
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function legalActions(array $state, string $seatId): array
    {
        return array_merge(
            (new CoopSetup($this->catalog))->legalActions($state, $seatId),
            (new CoopDraft($this->catalog))->legalActions($state, $seatId)
        );
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (!in_array($kind, CoopSetup::KINDS, true)) {
            return (new CoopDraft($this->catalog))->apply($state, $seatId, $kind, $payload);
        }
        $result = (new CoopSetup($this->catalog))->apply($state, $seatId, $kind, $payload);
        if ($kind !== 'confirmSetup' || !CoopDraft::complete($result['state'])) {
            return $result;
        }
        [$state, $placed] = (new CoopDraft($this->catalog))->deploy($result['state']);
        $result['events'][] = MatchEngine::event('armiesPlaced', array_values($placed), ['units' => $placed]);
        return ['state' => $state, 'events' => $result['events']];
    }
}
```

`api/src/Rules/Vanilla/Modes/CoopSetup.php`:
- Add `'setupConfirmed' => 'The Void Lord has already confirmed the monsters.',` to `MESSAGES`.
- In `legalActions`, change the guard to `if ($seatId !== $state['voidLordSeatId'] || $state['setup']['confirmed']) { return []; }`. Change the confirm offer to `if ($state['units'] !== [] && $state['setup']['spent'] <= $state['setup']['budget']) {`.
- In `apply`, add right after the `notVoidLord` check:

```php
        if ($state['setup']['confirmed']) {
            self::fail('setupConfirmed');
        }
```

- Replace the confirm branch body:

```php
            if ($state['units'] === []) {
                self::fail('setupEmpty');
            }
            if ($state['setup']['spent'] > $state['setup']['budget']) {
                self::fail('budgetExceeded');
            }
            $state['setup']['confirmed'] = true;
            if ($state['phase'] === 'setup') {
                // Saves from before simultaneous preparation open the draft only now.
                $state['phase'] = 'draft';
                $state['draft']['pickingSeatId'] = $state['initiative']['order'][0];
                $state['draft']['pickIndex'] = 0;
            }
            $events[] = MatchEngine::event('setupConfirmed', [], ['monsters' => count($state['units'])]);
```

`api/src/Rules/Vanilla/Modes/CoopDraft.php`:
- Make `deploy` public and give it a docblock: `/** Places every hero seat around the shared start and opens round one. @return array{0: array, 1: array<string,string>} */`.
- Add `complete`:

```php
    /** True once every hero seat holds two heroes and an army. */
    public static function complete(array $state): bool
    {
        foreach ($state['initiative']['order'] as $seatId) {
            if (self::openDecisions($state, $seatId) !== []) {
                return false;
            }
        }
        return true;
    }
```

- In `apply`, replace the tail after `$state['draft']['pickingSeatId'] = null;`:

```php
        $state['draft']['pickingSeatId'] = null;
        if (!$state['setup']['confirmed']) {
            // The heroes are ready first; the Void Lord's confirm starts the battle.
            return ['state' => $state, 'events' => $events];
        }
        [$state, $placed] = $this->deploy($state);
```

`api/src/Rules/Vanilla/MatchEngine.php`: in both `apply` and `legalActions`, change `'setup', 'draft' =>` to `'setup', 'prep', 'draft' =>`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: no `FAIL` lines from `coop_prep`, `coop_setup`, `coop_draft` or `coop_battle`. Service-level tests (`CoopServiceTest`, `MonsterAbilityTest`) may still fail on lifecycle `setup` vs `draft`; Task 2 fixes those. Note which ones fail.

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/CoopPrepTest.php api/tests/CoopSetupTest.php api/tests/run.php
git commit -m "feat(rules): simultaneous Co-op prep phase"
```

---

### Task 2: Service — lobby setup, live budget, start carry-over

**Files:**
- Modify: `api/src/Game/GameService.php`
- Create test: `api/tests/CoopLobbySetupTest.php` (register in `run.php` after `CoopServiceTest.php`; add `coop_lobby_setup_tests()` after `coop_service_tests()`)
- Modify tests: `api/tests/CoopServiceTest.php`, `api/tests/MonsterAbilityTest.php` (lifecycle expectations only)

**Interfaces:**
- Consumes: `CoopMode::budget`, `CoopMode::lobbySetup`, `CoopMode::handOver`, `CoopMode::start` option `setupState`, phase `prep` (Task 1).
- Produces:
  - `GameService::syncLobbySetup(array $game, int $version): void` (private)
  - `GameService::lifecycleOf(array $state): string` (private static; `prep` → `draft`)
  - lobby `placeMonster` / `removeMonster` commands
  - `rosterNotLocked`

- [ ] **Step 1: Write the failing tests** in `api/tests/CoopLobbySetupTest.php`:

```php
<?php
declare(strict_types=1);

/** The first legal spawn hex for $monster in a projection's legal actions. */
function coop_lobby_spawn(array $projection, string $monster): string
{
    foreach ($projection['legalActions'] as $action) {
        if ($action['kind'] === 'placeMonster' && ($action['monster'] ?? null) === $monster) {
            return $action['targetHexes'][0];
        }
    }
    throw new RuntimeException("{$monster} has nowhere to go");
}

function coop_lobby_place(array $match, string $who, string $monster): array
{
    $view = $match['games']->getGame($match['gameId'], $match[$who]['identity']);
    return vanilla_submit($match, $who, 'placeMonster', ['monster' => $monster, 'hex' => coop_lobby_spawn($view, $monster)]);
}

function coop_lobby_state_count(array $match): int
{
    $statement = $match['db']->prepare('SELECT COUNT(*) FROM game_states WHERE game_id = :game');
    $statement->execute(['game' => $match['gameId']]);
    return (int)$statement->fetchColumn();
}

function coop_lobby_setup_tests(): array
{
    return [
        'the void lord places monsters in the war room on a live budget' => static function (): void {
            $match = coop_service_match(2);
            $claimed = vanilla_submit($match, 'host', 'claimVoidLord', []);
            expect_same(20, $claimed['setup']['budget'], 'two hero players: 4 + 8 × 2');
            expect_same('lobby', $claimed['lifecycle'], 'the war room stays open');
            $placed = coop_lobby_place($match, 'host', 'Test Wolf');
            expect_same(['v-m1'], array_column($placed['units'], 'unitId'), 'the wolf is on the board');
            expect_same(1, $placed['setup']['spent'], 'its cost is spent');
            expect_true(!in_array('confirmSetup', array_column($placed['legalActions'], 'kind'), true), 'confirm waits for the start');
            expect_game_error('rosterNotLocked', static fn() => vanilla_submit($match, 'host', 'confirmSetup', []));
            expect_game_error('notVoidLord', static fn() => vanilla_submit($match, 'hero1', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $placed['setup']['spawnZoneHexes'][5]]));
        },

        'leaves shrink the lobby budget and joins grow it, without moving monsters' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            foreach (range(1, 3) as $ignored) {
                coop_lobby_place($match, 'host', 'Test Wyrm');
            }
            $match['games']->leaveGame($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']);
            $after = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same([4, 12, 3], [$after['setup']['budget'], $after['setup']['spent'], count($after['units'])], 'over budget, monsters stay');
            expect_true(!in_array('placeMonster', array_column($after['legalActions'], 'kind'), true), 'nothing more is affordable');
            $guest = $match['auth']->createGuest('coop-late-' . bin2hex(random_bytes(4)));
            $match['games']->joinGame($match['gameId'], $guest['identityId']);
            expect_same(12, $match['games']->getGame($match['gameId'], $match['host']['identity'])['setup']['budget'], 'a join grows it back');
        },

        'reassigning the void lord hands over the monsters; releasing clears them' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            vanilla_submit($match, 'host', 'assignVoidLord', ['seatId' => $match['hero1']['seat']]);
            $heir = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([$match['hero1']['seat']], array_column($heir['units'], 'seatId'), 'the wolf follows the role');
            expect_true(in_array('removeMonster', array_column($heir['legalActions'], 'kind'), true), 'the new void lord commands it');
            $old = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same([[], null], [$old['units'], $old['setup']], 'the old void lord no longer sees it');
            vanilla_submit($match, 'hero1', 'releaseVoidLord', []);
            expect_same(0, coop_lobby_state_count($match), 'releasing the role clears the board');
            $again = vanilla_submit($match, 'host', 'claimVoidLord', []);
            expect_same([[], 0], [$again['units'], $again['setup']['spent']], 'a new claim starts empty');
        },

        'lobby setup orders are refused outside a co-op war room' => static function (): void {
            $duel = vanilla_service_match();
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($duel, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '0,0']));
            $match = coop_service_match(1);
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '0,0']));
        },

        'the lobby board is deleted with the room' => static function (): void {
            $match = coop_service_match(0);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            expect_same(1, coop_lobby_state_count($match), 'the board is stored');
            $match['games']->leaveGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same(0, coop_lobby_state_count($match), 'the empty room takes the board with it');
        },

        'start carries the lobby horde into prep and opens the draft' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('draft', $started['lifecycle'], 'prep shows as the draft lifecycle');
            expect_same(['v-m1'], array_column($started['units'], 'unitId'), 'the lobby wolf is kept');
            expect_same([12, 1], [$started['setup']['budget'], $started['setup']['spent']], 'budget and spend carry over');
            expect_true(in_array('confirmSetup', array_column($started['legalActions'], 'kind'), true), 'the void lord may now confirm');
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same(['pickHero', 'pickArmy', 'setCommanderName'], array_column($hero['legalActions'], 'kind'), 'the hero drafts at once');
        },

        'draft first, then the confirm starts the battle' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            coop_lobby_place($match, 'host', 'Test Wolf');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            $waiting = vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            expect_same('draft', $waiting['lifecycle'], 'the heroes wait for the void lord');
            $active = vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_same('active', $active['lifecycle'], 'the confirm starts the battle');
            expect_same($match['hero1']['seat'], $active['turn']['activeSeatId'], 'the hero acts first');
        },

        'confirm first, then the draft finishes the battle start' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $confirmed = vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_same(['draft', true], [$confirmed['lifecycle'], $confirmed['setup']['confirmed']], 'the void lord waits');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            $active = vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            expect_same('active', $active['lifecycle'], 'the last pick starts the battle');
        },
    ];
}
```

Also, in `api/tests/CoopServiceTest.php`, add `'auth' => $auth` to the array `coop_service_match` builds (next to `'db'`). `coop_ready_all` skips it because it has no `token`.

Update `api/tests/CoopServiceTest.php`:
- `'co-op start needs a void lord and a hero player, then opens setup'`: expect lifecycle `'draft'`. The hero's legal actions become `['pickHero', 'pickArmy', 'setCommanderName']`.
- `'a scripted co-op match runs from setup through draft to a hero victory'`: after `confirmSetup`, expect lifecycle `'draft'` as before (the confirm no longer changes it). Everything else stays.

Update any `MonsterAbilityTest.php` assertions that expect lifecycle `'setup'` after start to expect `'draft'`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: `coop_lobby_setup` tests FAIL (`claimed['setup']` is null, and `placeMonster` in the lobby gives `illegalLifecycle`).

- [ ] **Step 3: Implement** in `api/src/Game/GameService.php`

Add `use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;` next to the `CoopSetup` import.

Add the helpers below `bumpVersion`:

```php
    /** Keeps the Void Lord's lobby board in step with the war room: created on a claim, budget on every roster change. */
    private function syncLobbySetup(array $game, int $version): void
    {
        if ($game['mode'] !== 'coop' || $game['lifecycle'] !== 'lobby') {
            return;
        }
        $memberships = $this->memberships($game['id']);
        $voidLord = null;
        foreach ($memberships as $seat) {
            if ($seat['role'] === 'voidLord') {
                $voidLord = (string)$seat['id'];
            }
        }
        if ($voidLord === null) {
            $this->connection->prepare('DELETE FROM game_states WHERE game_id = :game')->execute(['game' => $game['id']]);
            return;
        }
        $definition = $this->scenarioDefinition($game);
        $budget = CoopMode::budget($definition['coop'] ?? [], count($memberships) - 1);
        $state = $this->loadState($game['id']);
        $state = $state === null
            ? CoopMode::lobbySetup(Board::fromScenario($definition), $voidLord, $budget)
            : CoopMode::handOver($state, $voidLord);
        $state['setup']['budget'] = $budget;
        $this->saveState($game['id'], $version, $state);
    }

    /** Simultaneous preparation is shown to clients as the draft. */
    private static function lifecycleOf(array $state): string
    {
        return $state['phase'] === 'prep' ? 'draft' : (string)$state['phase'];
    }

    private function applyLobbySetupCommand(array $game, string $seatId, string $identityId, array $command, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $state = $game['mode'] === 'coop' ? $this->loadState($game['id']) : null;
        if ($state === null) {
            throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
        }
        if ($state['voidLordSeatId'] !== $seatId) {
            throw new GameServiceException('notVoidLord', 'Only the Void Lord sets up the monsters.', $currentVersion);
        }
        if ($command['kind'] === 'confirmSetup') {
            throw new GameServiceException('rosterNotLocked', 'The Void Lord confirms once the host starts the match.', $currentVersion);
        }
        try {
            $result = (new CoopSetup($this->engine->catalog()))->apply(
                MatchEngine::normalize($state),
                $seatId,
                (string)$command['kind'],
                is_array($command['payload']) ? $command['payload'] : []
            );
        } catch (GameServiceException $error) {
            throw new GameServiceException($error->errorCode, $error->getMessage(), $currentVersion);
        }
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
        $this->saveState($game['id'], $newVersion, $result['state']);
        foreach ($result['events'] as $event) {
            $this->appendEvent($game['id'], $newVersion, $event['kind'], $identityId, $event['payload'], $event['focusHexes']);
        }
    }
```

(Task 3 adds the `'void'` visibility argument to that `appendEvent` call.)

Wire the sync in:
- `joinGame`: after the `UPDATE games SET version` line, add `$this->syncLobbySetup($game, $version);`.
- `vacateLobbySeat`: after `$this->bumpVersion(...)`, add `$this->syncLobbySetup($game, $newVersion);`.
- `applyLobbyCommand` (Void Lord branch): after the final `bumpVersion`, add `$this->syncLobbySetup($game, $newVersion);`.

Route lobby setup commands in `submitCommand`:

```php
            if (in_array($command['kind'], self::LOBBY_COMMANDS, true)) {
                $this->applyLobbyCommand($game, $membership, $identityId, $command, $newVersion);
            } elseif ($game['lifecycle'] === 'lobby' && in_array($command['kind'], CoopSetup::KINDS, true)) {
                $this->applyLobbySetupCommand($game, (string)$membership['id'], $identityId, $command, $newVersion);
            } else {
                $this->applyMatchCommand($game, (string)$membership['id'], $identityId, $command, $newVersion);
            }
```

`startGame`:
- change `$options = [...]` for Co-op to add `'setupState' => $this->loadState($gameId)`;
- in the `UPDATE games SET lifecycle` execute, use `self::lifecycleOf($started['state'])`;
- in the `gameStarted` event payload, use `['lifecycle' => self::lifecycleOf($started['state'])]`.

`applyMatchCommand`: in the version bump, use `'lifecycle' => self::lifecycleOf($result['state'])`.

Lobby legal actions: change the signature to `lobbyActions(array $game, array $memberships, array $viewer, ?array $lobbySetup)`. Before the `assignVoidLord` block's `return`, append the Void Lord's setup actions:

```php
        if ($lobbySetup !== null && $lobbySetup['voidLordSeatId'] === $viewer['seatId']) {
            foreach ((new CoopSetup($this->engine->catalog()))->legalActions($lobbySetup, (string)$viewer['seatId']) as $action) {
                if ($action['kind'] !== 'confirmSetup') {
                    $actions[] = $action;
                }
            }
        }
        return $actions;
```

`buildProjection` (minimal change for this task; Task 3 adds the redaction):

```php
        $state = $this->loadState($game['id']);
        $state = $state === null ? null : MatchEngine::normalize($state);
        $lobbySetup = null;
        if ($game['lifecycle'] === 'lobby') {
            // The lobby board belongs to the Void Lord alone; the match itself has not begun.
            $lobbySetup = $state;
            $state = null;
        }
        $viewerIsVoidLord = $lobbySetup !== null && $lobbySetup['voidLordSeatId'] === $viewer['seatId'];
        $shown = $lobbySetup !== null ? ($viewerIsVoidLord ? $lobbySetup : null) : $state;
```

Then:
- pass `$lobbySetup` to `lobbyActions`;
- change `'board' => $this->boardProjection($game, $shown)`;
- change `'setup'` to `$lobbySetup !== null ? ($viewerIsVoidLord ? $this->setupProjection($lobbySetup) : null) : (...existing expression...)`;
- change `'units'` to map over `$shown` (`$shown === null ? [] : array_values(array_map(fn(array $unit): array => $this->unitProjection($shown, $unit), self::orderedUnits($shown)))`);
- in `'draft'`, change the phase check to `in_array($state['phase'], ['draft', 'prep'], true)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: no FAIL lines.

- [ ] **Step 5: Commit**

```bash
git add api/src/Game/GameService.php api/tests
git commit -m "feat: Void Lord sets up in the war room on a live budget"
```

---

### Task 3: Hidden information — projections, event feed, snapshots

**Files:**
- Create: `api/migrations/004_event_visibility.sqlite.sql`, `api/migrations/004_event_visibility.mysql.sql`
- Modify: `api/src/Game/GameService.php`
- Create test: `api/tests/CoopHiddenInfoTest.php` (register in `run.php` after `CoopLobbySetupTest.php`; add `coop_hidden_info_tests()`)

**Interfaces:**
- Consumes: lobby setup and `prep` (Tasks 1–2).
- Produces projection fields:
  - `setup.redacted` (bool)
  - `draft.picksMade` (int), `draft.picksTotal` (int), `draft.redacted` (bool)
  - `appendEvent(..., ?string $visibleSide = null)`
  - `eventPage(string $gameId, int $afterSequence, ?string $side)`

- [ ] **Step 1: Write the failing tests** in `api/tests/CoopHiddenInfoTest.php`:

```php
<?php
declare(strict_types=1);

/** One hero, the host as Void Lord with a wolf placed in the lobby, the match started and one hero pick made. */
function coop_hidden_prep(): array
{
    $match = coop_service_match(1);
    vanilla_submit($match, 'host', 'claimVoidLord', []);
    coop_lobby_place($match, 'host', 'Test Wolf');
    coop_ready_all($match);
    $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
    vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
    return $match;
}

function coop_event_kinds(array $page): array
{
    return array_column($page['events'], 'kind');
}

function coop_hidden_info_tests(): array
{
    return [
        'heroes and spectators cannot see the lobby horde' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([[], null], [$hero['units'], $hero['setup']], 'the hero sees no monsters or budget');
            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([[], null], [$watched['units'], $watched['setup']], 'nor does a spectator');
        },

        'prep projections show each side only its own choices' => static function (): void {
            $match = coop_hidden_prep();
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([], $hero['units'], 'no monsters for the hero');
            expect_same([true, false], [$hero['setup']['redacted'], $hero['setup']['confirmed']], 'the hero only learns whether the void lord is done');
            expect_same(['Test Captain'], $hero['draft']['heroesBySeat'][$match['hero1']['seat']], 'the hero sees the draft');
            expect_same([1, 3, false], [$hero['draft']['picksMade'], $hero['draft']['picksTotal'], $hero['draft']['redacted']], 'with progress');

            $lord = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same(['v-m1'], array_column($lord['units'], 'unitId'), 'the void lord sees the horde');
            expect_same(false, $lord['setup']['redacted'], 'and the full setup');
            expect_same([[], true, 1], [$lord['draft']['heroesBySeat'][$match['hero1']['seat']], $lord['draft']['redacted'], $lord['draft']['picksMade']], 'but only the pick count');
            expect_same(null, array_column($lord['draft']['availableHeroes'], 'takenBySeatId', 'name')['Test Captain'], 'claims are blanked');
            expect_same([$match['host']['seat']], array_keys($lord['armies']), 'only the horde emblem is projected');

            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([[], true, true], [$watched['units'], $watched['setup']['redacted'], $watched['draft']['redacted']], 'spectators see neither side');
        },

        'no projection field leaks a monster id to a hero or spectator' => static function (): void {
            $match = coop_hidden_prep();
            $hero = json_encode($match['games']->getGame($match['gameId'], $match['hero1']['identity']), JSON_THROW_ON_ERROR);
            $watched = json_encode($match['games']->getWatchedGame($match['gameId']), JSON_THROW_ON_ERROR);
            expect_true(!str_contains($hero, 'v-m1'), 'the hero projection never names the wolf');
            expect_true(!str_contains($watched, 'v-m1'), 'the spectator projection never names the wolf');
        },

        'events stay with their side until the battle' => static function (): void {
            $match = coop_hidden_prep();
            $heroEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0));
            $lordEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0));
            $watchedEvents = coop_event_kinds($match['games']->getWatchedEvents($match['gameId'], 0));
            expect_true(!in_array('monsterPlaced', $heroEvents, true) && in_array('draftPicked', $heroEvents, true), 'heroes see picks, not placements');
            expect_true(in_array('monsterPlaced', $lordEvents, true) && !in_array('draftPicked', $lordEvents, true), 'the void lord sees placements, not picks');
            expect_true(!in_array('monsterPlaced', $watchedEvents, true) && !in_array('draftPicked', $watchedEvents, true), 'spectators see neither');

            vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_true(in_array('setupConfirmed', coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0)), true), 'the confirm itself is public');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            foreach ([
                coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0)),
                coop_event_kinds($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)),
                coop_event_kinds($match['games']->getWatchedEvents($match['gameId'], 0)),
            ] as $kinds) {
                expect_true(in_array('monsterPlaced', $kinds, true) && in_array('draftPicked', $kinds, true) && in_array('armiesPlaced', $kinds, true), 'the battle reveals everything');
            }
            $active = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_true(in_array('v-m1', array_column($active['units'], 'unitId'), true), 'the hero sees the horde in battle');
        },
    ];
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: the `coop_hidden_info` tests FAIL. The lobby test should already pass from Task 2; the others fail (heroes see `v-m1`, and the `redacted` key is missing).

- [ ] **Step 3: Implement**

`api/migrations/004_event_visibility.sqlite.sql`:

```sql
ALTER TABLE game_events ADD COLUMN visible_side TEXT NULL;
```

`api/migrations/004_event_visibility.mysql.sql`:

```sql
ALTER TABLE game_events ADD COLUMN visible_side VARCHAR(16) NULL;
```

`GameService.php`, `appendEvent`: add the parameter `?string $visibleSide = null`, add `visible_side` to the column list and `:side` to the values, and add `'side' => $visibleSide` to the execute array.

Event tagging in `applyMatchCommand`. Before `$this->engine->apply`, compute:

```php
        $concealedSide = ($state['mode'] ?? null) === 'coop' && $state['phase'] === 'prep'
            ? ($seatId === $state['voidLordSeatId'] ? 'void' : 'heroes')
            : null;
```

and change the event loop to:

```php
        foreach ($result['events'] as $event) {
            $side = in_array($event['kind'], self::PUBLIC_PREP_EVENTS, true) ? null : $concealedSide;
            $this->appendEvent($game['id'], $newVersion, $event['kind'], $identityId, $event['payload'], $event['focusHexes'], $side);
        }
```

Add the constant next to `LOBBY_COMMANDS`: `private const PUBLIC_PREP_EVENTS = ['setupConfirmed', 'armiesPlaced'];`.

In `applyLobbySetupCommand`, pass `'void'` as the last `appendEvent` argument.

The event feed:

```php
    public function getEvents(string $gameId, string $identityId, string $seatToken, int $afterSequence): array
    {
        $membership = $this->requireSeatCredential($gameId, $identityId, $seatToken);
        return $this->eventPage($gameId, $afterSequence, $membership['role'] === 'voidLord' ? 'void' : 'heroes');
    }

    /** Anyone may read a game's event log by its id; before a Co-op battle it carries neither side's preparations. */
    public function getWatchedEvents(string $gameId, int $afterSequence): array
    {
        $this->gameRow($gameId);
        return $this->eventPage($gameId, $afterSequence, null);
    }
```

In `eventPage`, add `?string $side`. Look up `$revealed = !in_array($this->gameRow($gameId)['lifecycle'], ['lobby', 'draft'], true);` and extend the SQL:

```php
            'SELECT sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at
             FROM game_events WHERE game_id = :game AND sequence > :after
               AND (visible_side IS NULL OR visible_side = :side OR :revealed = 1)
             ORDER BY sequence LIMIT 200'
```

with `'side' => $side ?? '', 'revealed' => $revealed ? 1 : 0` added to the execute array. If `requireMembership` doesn't select `role`, add it to that query.

Projection redaction in `buildProjection`. Replace the Task-2 `$viewerIsVoidLord` / `$shown` lines with:

```php
        $side = self::viewerSide($memberships, $viewer);
        $concealed = $game['mode'] === 'coop' && ($lobbySetup !== null || ($state['phase'] ?? null) === 'prep');
        $shown = match (true) {
            $lobbySetup !== null => $side === 'void' ? $lobbySetup : null,
            $concealed && $side !== 'void' => self::withoutSide($state, 'void'),
            default => $state,
        };
```

and set these fields:

```php
            'setup' => match (true) {
                $lobbySetup !== null => $side === 'void' ? $this->setupProjection($lobbySetup) : null,
                $state !== null && ($state['mode'] ?? 'vanillaPvp') === 'coop' => $concealed && $side !== 'void' ? self::redactedSetup($state) : $this->setupProjection($state),
                default => null,
            },
            'armies' => $this->armiesProjection($state, !$concealed || $side === 'heroes'),
            'draft' => $state !== null && in_array($state['phase'], ['draft', 'prep'], true)
                ? (($state['mode'] ?? 'vanillaPvp') === 'coop'
                    ? ($concealed && $side !== 'heroes' ? $this->redactedCoopDraft($state) : $this->coopDraftProjection($state))
                    : $this->draftProjection($state))
                : null,
```

(`board` and `units` already use `$shown`.)

New helpers:

```php
    /** 'void' for the Void Lord's seat, 'heroes' for any other player seat, null for spectators. */
    private static function viewerSide(array $memberships, array $viewer): ?string
    {
        if ($viewer['role'] !== 'player' || $viewer['seatId'] === null) {
            return null;
        }
        foreach ($memberships as $seat) {
            if ((string)$seat['id'] === (string)$viewer['seatId']) {
                return $seat['role'] === 'voidLord' ? 'void' : 'heroes';
            }
        }
        return null;
    }

    private static function withoutSide(array $state, string $side): array
    {
        $state['units'] = array_filter($state['units'], static fn(array $unit): bool => ($unit['side'] ?? null) !== $side);
        return $state;
    }

    /** What the other side may know of the Void Lord's setup: only whether it is finished. */
    private static function redactedSetup(array $state): array
    {
        return ['budget' => 0, 'spent' => 0, 'confirmed' => (bool)$state['setup']['confirmed'], 'spawnZoneHexes' => [], 'roster' => [], 'redacted' => true];
    }

    /** The draft as the Void Lord and spectators see it during preparation: progress, but no picks. */
    private function redactedCoopDraft(array $state): array
    {
        $projection = $this->coopDraftProjection($state);
        $projection['heroesBySeat'] = array_map(static fn(): array => [], $projection['heroesBySeat']);
        $projection['armyBySeat'] = array_map(static fn(): ?string => null, $projection['armyBySeat']);
        $projection['availableHeroes'] = array_map(static fn(array $hero): array => ['takenBySeatId' => null] + $hero, $projection['availableHeroes']);
        $projection['availableArmies'] = array_map(static fn(array $army): array => ['takenBySeatId' => null] + $army, $projection['availableArmies']);
        $projection['redacted'] = true;
        return $projection;
    }
```

Then:
- In `setupProjection`, add `'redacted' => false`.
- In `coopDraftProjection`, add the following, computing `$picksMade = array_sum(array_map('count', $draft['heroesBySeat'])) + count(array_filter($draft['armyBySeat'], static fn(?string $army): bool => $army !== null));` first:
  - `'picksMade' => $picksMade`
  - `'picksTotal' => 3 * count($state['initiative']['order'])`
  - `'redacted' => false`
- Change `armiesProjection(?array $state, bool $heroArmies = true)` so it skips the `armyBySeat` loop when `$heroArmies` is false.

Snapshots: at the top of `writeSnapshot`:

```php
        if ($projection['mode'] === 'coop' && in_array($projection['lifecycle'], ['lobby', 'draft'], true)
            && $projection['viewer']['role'] !== 'spectator') {
            // A stored player view would carry one side's hidden preparations.
            $projection = $this->spectatorProjection($this->gameRow($gameId));
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`
Expected: no FAIL lines anywhere in the suite.

- [ ] **Step 5: Commit**

```bash
git add api/migrations api/src/Game/GameService.php api/tests
git commit -m "feat: hide each side's Co-op preparations until the battle"
```

---

### Task 4: Client — contracts and the Void Lord's setup board

**Files:**
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs` (`SetupProjection`), `src/BloodAndBanners.Contracts/MatchProjection.cs` (`DraftProjection`)
- Modify: `src/BloodAndBanners.UI/Components/VoidLordSetup.razor` (+ `.razor.css`), `src/BloodAndBanners.UI/Components/GameTable.razor`
- Test: `tests/BloodAndBanners.UI.Tests/VoidLordSetupTests.cs`

**Interfaces:**
- Consumes: projection fields from Task 3.
- Produces:
  - `SetupProjection.Redacted`
  - `DraftProjection.PicksMade` / `PicksTotal` / `Redacted`
  - `VoidLordSetup` parameters `StatusLine` (string?), `ShowConfirm` (bool, default true), `ShowBackToWarRoom` (bool), `OnBackToWarRoom` (EventCallback)
  - `GameTable` parameter `OnBackToWarRoom` (EventCallback)

- [ ] **Step 1: Write the failing tests** (append to `VoidLordSetupTests`):

```csharp
    [Fact]
    public void Over_budget_shows_a_warning_and_the_lobby_hides_confirm()
    {
        var back = 0;
        var cut = Render<VoidLordSetup>(p => p
            .Add(c => c.Setup, Setup(spent: 14))
            .Add(c => c.IsVoidLord, true)
            .Add(c => c.ShowConfirm, false)
            .Add(c => c.ShowBackToWarRoom, true)
            .Add(c => c.StatusLine, "War room · 1 hero, 0 ready")
            .Add(c => c.OnBackToWarRoom, () => back++));

        Assert.Contains("Over budget by 2", cut.Find("[data-testid=setup-over-budget]").TextContent);
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
        Assert.Contains("War room · 1 hero, 0 ready", cut.Find("[data-testid=setup-status]").TextContent);
        cut.Find("[data-action=back-to-war-room]").Click();
        Assert.Equal(1, back);
    }

    [Fact]
    public void A_confirmed_horde_waits_without_the_roster()
    {
        var cut = Render<VoidLordSetup>(p => p
            .Add(c => c.Setup, Setup(spent: 3) with { Confirmed = true })
            .Add(c => c.IsVoidLord, true)
            .Add(c => c.StatusLine, "Waiting for the heroes to finish drafting · 2/3 picks"));

        Assert.NotNull(cut.Find("[data-testid=setup-ready]"));
        Assert.Empty(cut.FindAll("[data-monster]"));
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
    }

    [Fact]
    public void During_the_draft_the_void_lord_keeps_the_setup_board_with_hero_progress()
    {
        var projection = SetupTable("seat-void", new LegalAction("confirmSetup", null, [])) with { Lifecycle = GameLifecycle.Draft };
        projection = projection with
        {
            Draft = new DraftProjection(new Dictionary<string, IReadOnlyList<int>>(), "seat-hero", null,
                new Dictionary<string, int?>(), new Dictionary<string, string?>(), new Dictionary<string, string?>(), [], [])
            { PicksMade = 2, PicksTotal = 3, Redacted = true },
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));

        Assert.Contains("Heroes drafting · 2/3 picks", cut.Find("[data-testid=setup-status]").TextContent);
        Assert.NotNull(cut.Find("[data-action=confirmSetup]"));
    }

    [Fact]
    public void In_the_war_room_the_setup_board_offers_the_way_back_and_no_confirm()
    {
        var projection = SetupTable("seat-void", new LegalAction("placeMonster", null, ["5,0"])) with { Lifecycle = GameLifecycle.Lobby };
        var back = 0;
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection).Add(c => c.OnBackToWarRoom, () => back++));

        Assert.Contains("War room · 1 hero, 1 ready", cut.Find("[data-testid=setup-status]").TextContent);
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
        cut.Find("[data-action=back-to-war-room]").Click();
        Assert.Equal(1, back);
    }

    [Fact]
    public void A_redacted_setup_never_opens_the_setup_board()
    {
        var projection = SetupTable("seat-hero") with { Lifecycle = GameLifecycle.Draft, Setup = Setup() with { Redacted = true } };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));

        Assert.Empty(cut.FindAll("[data-testid=setup-budget]"));
        Assert.DoesNotContain("The Void Lord is placing monsters", cut.Markup);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~VoidLordSetupTests"`
Expected: build errors (`ShowConfirm`, `Redacted`, `PicksMade` don't exist).

- [ ] **Step 3: Implement**

`GameProjection.cs`: give `SetupProjection` a body:

```csharp
public sealed record SetupProjection(
    int Budget,
    int Spent,
    bool Confirmed,
    IReadOnlyList<string> SpawnZoneHexes,
    IReadOnlyList<MonsterCard> Roster)
{
    /// <summary>True for a viewer on the other side: only <see cref="Confirmed"/> is real.</summary>
    public bool Redacted { get; init; }
}
```

`MatchProjection.cs`, in the `DraftProjection` body:

```csharp
    public int PicksMade { get; init; }

    public int PicksTotal { get; init; }

    /// <summary>True for the Void Lord and spectators during preparation: claims are blanked, only progress is real.</summary>
    public bool Redacted { get; init; }
```

`VoidLordSetup.razor`: replace the `else { ... }` Void Lord branch with:

```razor
else
{
    @if (ShowBackToWarRoom)
    {
        <button type="button" class="void-setup__back" data-action="back-to-war-room" @onclick="() => OnBackToWarRoom.InvokeAsync()">Back to war room</button>
    }
    @if (!string.IsNullOrEmpty(StatusLine))
    {
        <p class="void-setup__status" data-testid="setup-status" role="status">@StatusLine</p>
    }
    @if (Setup.Confirmed)
    {
        <p class="void-setup__ready" data-testid="setup-ready" role="status">Your horde stands ready.</p>
    }
    else
    {
        @* existing budget paragraph, meter, hint, selected-abilities block and roster loop, unchanged *@
        @if (Setup.Spent > Setup.Budget)
        {
            <p class="void-setup__over" data-testid="setup-over-budget" role="alert">Over budget by @(Setup.Spent - Setup.Budget) — remove monsters before you confirm.</p>
        }
        @if (ShowConfirm)
        {
            <button type="button" class="action-button" data-action="confirmSetup" disabled="@(!CanConfirm || IsBusy)" @onclick="() => OnConfirm.InvokeAsync()">
                Unleash the horde
            </button>
        }
    }
}
```

Put the over-budget paragraph right after the `<meter>`, not after the roster. Add these parameters:

```csharp
    [Parameter] public string? StatusLine { get; set; }
    [Parameter] public bool ShowConfirm { get; set; } = true;
    [Parameter] public bool ShowBackToWarRoom { get; set; }
    [Parameter] public EventCallback OnBackToWarRoom { get; set; }
```

`VoidLordSetup.razor.css`: add `.void-setup__over { color: var(--danger, #e06c5a); font-weight: 600; }`. Style `.void-setup__back` like the other secondary buttons in this file; if there are none, copy the `.action-button` background and border rules.

`GameTable.razor`:
- Add `[Parameter] public EventCallback OnBackToWarRoom { get; set; }`.
- Replace `InSetup`:

```csharp
    private bool InSetup => Projection.Setup is { Redacted: false }
        && (Projection.Lifecycle == GameLifecycle.Setup
            || (Projection.Mode == GameMode.Coop
                && Projection.Lifecycle is GameLifecycle.Lobby or GameLifecycle.Draft
                && SeatNames.IsVoidLord(Projection, Projection.Viewer.SeatId)));

    private string? SetupStatus
    {
        get
        {
            if (Projection.Lifecycle == GameLifecycle.Lobby)
            {
                var heroes = Projection.Memberships.Where(member => member.Role != "voidLord").ToList();
                return $"War room · {heroes.Count} {(heroes.Count == 1 ? "hero" : "heroes")}, {heroes.Count(member => member.Ready)} ready · you confirm once the host begins";
            }
            if (Projection.Lifecycle == GameLifecycle.Draft && Projection.Draft is { } draft)
            {
                var picks = $"{draft.PicksMade}/{draft.PicksTotal} picks";
                return Projection.Setup!.Confirmed ? $"Waiting for the heroes to finish drafting · {picks}" : $"Heroes drafting · {picks}";
            }
            return null;
        }
    }
```

- Pass the new parameters on the `<VoidLordSetup ...>` tag:

```razor
                           StatusLine="@SetupStatus"
                           ShowConfirm="@(Projection.Lifecycle != GameLifecycle.Lobby)"
                           ShowBackToWarRoom="@(Projection.Lifecycle == GameLifecycle.Lobby)"
                           OnBackToWarRoom="OnBackToWarRoom"
```

- Change `TurnPopup.razor`'s `Projection.Lifecycle == GameLifecycle.Setup` check to `Projection.Lifecycle is GameLifecycle.Setup or GameLifecycle.Lobby or GameLifecycle.Draft` (no turn exists before the battle; this only makes that explicit).

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts src/BloodAndBanners.UI/Components tests/BloodAndBanners.UI.Tests
git commit -m "feat(ui): Void Lord setup board for the war room and the draft"
```

---

### Task 5: Client — routing, war room, draft status, reveal

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/GameExperience.razor`, `WarRoomLobby.razor`, `DraftTable.razor`
- Test: `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`, `WarRoomLobbyTests.cs`, `DraftTableTests.cs`

**Interfaces:**
- Consumes: `GameTable.OnBackToWarRoom`, `SetupProjection.Redacted`, `DraftProjection.Redacted` (Task 4).
- Produces: the `WarRoomLobby.OnPlaceMonsters` parameter (EventCallback).

- [ ] **Step 1: Write the failing tests**

`WarRoomLobbyTests.cs`:

```csharp
    [Fact]
    public void Only_the_void_lord_can_open_the_monster_board_and_heroes_see_them_preparing()
    {
        var opened = 0;
        var lord = Render<WarRoomLobby>(p => p
            .Add(c => c.Projection, CoopLobby("seat-2", false, "seat-2", "setReady"))
            .Add(c => c.OnPlaceMonsters, () => opened++));
        lord.Find("[data-action=place-monsters]").Click();
        Assert.Equal(1, opened);

        var hero = Render<WarRoomLobby>(p => p.Add(c => c.Projection, CoopLobby("seat-1", true, "seat-2", "setReady")));
        Assert.Empty(hero.FindAll("[data-action=place-monsters]"));
        Assert.Contains("The Void Lord is preparing the horde", hero.Find("[data-testid=void-preparing]").TextContent);
    }
```

`DraftTableTests.cs`:

```csharp
    [Fact]
    public void Coop_heroes_see_the_void_lord_status_and_wait_for_the_confirm()
    {
        var baseline = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var projection = baseline with
        {
            Mode = GameMode.Coop,
            Setup = new SetupProjection(0, 0, false, [], []) { Redacted = true },
            Draft = baseline.Draft! with { PickingSeatId = null },
        };
        var cut = Render<DraftTable>(p => p.Add(c => c.Projection, projection));

        Assert.Contains("The Void Lord is placing monsters", cut.Find("[data-testid=void-status]").TextContent);
        Assert.Contains("Waiting for the Void Lord", cut.Find("[data-testid=draft-status]").TextContent);
    }
```

(If `DraftTable` needs `JSInterop.Mode = JSRuntimeMode.Loose`, follow the existing tests in that file.)

`GameExperienceTests.cs`: add `public List<long> EventRequests { get; } = [];` to `FakeSessionCoordinator`, and change `GetEventsAsync` to record `EventRequests.Add(afterSequence);` before returning. Then add:

```csharp
    private static GameProjection CoopVoidLord(GameLifecycle lifecycle, long version = 9, params LegalAction[] actions) => new(
        "game-coop", version, lifecycle, GameMode.Coop,
        new ViewerPermissions("player", "seat-void", true, true),
        new BoardProjection(7, 1, Enumerable.Range(0, 7).Select(q => new HexProjection(q, 0, "ice", "snow", 0, false, null)).ToList()),
        new TurnProjection(lifecycle == GameLifecycle.Active ? 1 : 0, lifecycle == GameLifecycle.Active ? "seat-hero" : null, 0),
        null, actions, new Dictionary<string, int>(), null)
    {
        ScenarioName = "Void Reaches",
        Memberships = [new MembershipProjection("seat-void", 1, true, true) { Role = "voidLord" }, new MembershipProjection("seat-hero", 2, true, false)],
        Setup = lifecycle == GameLifecycle.Active ? null : new SetupProjection(12, 1, false, ["5,0"], [new MonsterCard("Ice Wolf", "Ice", "common", 1, "", 4, 1, 2, 2, 2)]),
        Draft = lifecycle == GameLifecycle.Draft
            ? new DraftProjection(new Dictionary<string, IReadOnlyList<int>>(), "seat-hero", null, new Dictionary<string, int?>(),
                new Dictionary<string, string?>(), new Dictionary<string, string?>(), [], []) { PicksMade = 0, PicksTotal = 3, Redacted = true }
            : null,
    };

    [Fact]
    public void The_void_lord_moves_between_the_war_room_and_the_monster_board()
    {
        var api = new FakeSessionCoordinator { Reconnected = CoopVoidLord(GameLifecycle.Lobby, actions: new LegalAction("setReady", null, [])) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.WaitForElement("[data-action=place-monsters]").Click();
        cut.WaitForElement("[data-testid=setup-budget]");
        cut.Find("[data-action=back-to-war-room]").Click();
        cut.WaitForElement("[data-action=place-monsters]");
    }

    [Fact]
    public void In_the_draft_the_void_lord_gets_the_setup_board_not_the_draft_table()
    {
        var api = new FakeSessionCoordinator { Reconnected = CoopVoidLord(GameLifecycle.Draft, actions: new LegalAction("confirmSetup", null, [])) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.WaitForElement("[data-action=confirmSetup]");
        Assert.Empty(cut.FindAll("[data-testid=draft-log]"));
    }

    [Fact]
    public void The_reveal_refetches_the_whole_log()
    {
        var api = new FakeSessionCoordinator
        {
            Reconnected = CoopVoidLord(GameLifecycle.Draft, actions: new LegalAction("confirmSetup", null, [])),
            Commanded = CoopVoidLord(GameLifecycle.Active, version: 10),
            Events = new EventPage([], 5),
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.WaitForElement("[data-action=confirmSetup]").Click();

        // The first fetch (from 0) moves the cursor to 5; without the reveal the post-command fetch would ask from 5.
        cut.WaitForAssertion(() => Assert.True(api.EventRequests.Count >= 2));
        Assert.Equal(0, api.EventRequests[1]);
    }
```

(`ApplyEvents` sets `_lastSequence = Math.Max(_lastSequence, page.LastSequence)`, so an empty page with `LastSequence` 5 moves the cursor.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~GameExperienceTests|FullyQualifiedName~WarRoomLobbyTests|FullyQualifiedName~DraftTableTests"`
Expected: build error (`OnPlaceMonsters` doesn't exist), then failures.

- [ ] **Step 3: Implement**

`WarRoomLobby.razor`: add the parameter `[Parameter] public EventCallback OnPlaceMonsters { get; set; }`. In `<footer class="war-room__orders">`, after the release button, add:

```razor
        @if (IsCoop && SeatNames.IsVoidLord(Projection, Projection.Viewer.SeatId))
        {
            <button type="button" class="ready-order" data-action="place-monsters" disabled="@IsBusy" @onclick="() => OnPlaceMonsters.InvokeAsync()">Place monsters</button>
        }
        else if (IsCoop && VoidLordCount == 1)
        {
            <p class="host-note" data-testid="void-preparing">The Void Lord is preparing the horde.</p>
        }
```

`DraftTable.razor`, under the `draft-status` paragraph in the header:

```razor
        @if (IsCoop && Projection.Setup is { } voidSetup)
        {
            <p data-testid="void-status" class="draft-status">@(voidSetup.Confirmed ? "The Void Lord is ready" : "The Void Lord is placing monsters")@(Draft.Redacted ? " · Picks stay hidden until the battle" : null)</p>
        }
```

and change the `PickingSeatId is null` arm of `StatusText` to:

```csharp
        : Draft.PickingSeatId is null
            ? IsCoop && Projection.Setup is { Confirmed: false } ? "Waiting for the Void Lord" : "The armies are taking the field"
```

`GameExperience.razor`:
- Add the field `private bool _placingMonsters;`, and reset it in `ReturnToWarRoom` (`_placingMonsters = false;`).
- Add:

```csharp
    /// <summary>The Void Lord prepares on the board, not at the draft table, while the heroes draft.</summary>
    private bool VoidLordPreparing => _game is { Mode: GameMode.Coop, Setup.Redacted: false } && SeatNames.IsVoidLord(_game, _game.Viewer.SeatId);

    /// <summary>Each side's preparations were hidden from the other; the battle brings the whole history.</summary>
    private bool Reveals(GameProjection next) =>
        _game is { Mode: GameMode.Coop, Lifecycle: GameLifecycle.Draft } && next.Lifecycle == GameLifecycle.Active;
```

- Routing. Between the spectator-lobby branch and the lobby branch, insert:

```razor
else if (_game.Lifecycle == GameLifecycle.Lobby && _placingMonsters && VoidLordPreparing)
{
    <GameTable Projection="_game"
               Events="_events"
               LiveAfterSequence="_liveAfterSequence"
               IsBusy="_isBusy"
               ErrorMessage="@_error"
               ImageBaseUrl="@ImageBaseUrl"
               InviteBaseUri="@InviteBaseUri"
               OnCommand="SubmitGameCommand"
               OnReturnToWarRoom="LeaveWarRoom"
               OnBackToWarRoom="() => _placingMonsters = false" />
}
```

- Add `OnPlaceMonsters="() => _placingMonsters = true"` to `<WarRoomLobby ...>`.
- Change the draft branch condition to `_game.Lifecycle == GameLifecycle.Draft && _game.Draft is not null && !VoidLordPreparing`.

The reveal:
- In `PollAsync`, change the event fetch to `page = await Sessions.GetEventsAsync(Reveals(refreshed) ? 0 : _lastSequence, cancellationToken);`.
- In `ApplyProjection`'s same-game branch:

```csharp
        else if (_game is null || projection.Version >= _game.Version)
        {
            if (Reveals(projection))
            {
                _events.Clear();
                _lastSequence = 0;
                _liveAfterSequence = null;
            }
            _game = projection;
        }
```

(`SubmitGameCommand` already fetches events after `ApplyProjection`, so it picks up the reset `_lastSequence = 0`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj` and `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
Expected: all pass. (`AttackPopup`'s queued test is known to be flaky on main; re-run once if only that one fails.)

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI/Components tests/BloodAndBanners.UI.Tests
git commit -m "feat(ui): Void Lord prepares in the war room and during the draft; log fills in at the reveal"
```

---

### Task 6: Live check by hand

**Files:** none (verification only)

- [ ] **Step 1: Run the full suites once more**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|passed|failed"`, `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`, `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
Expected: no failures.

- [ ] **Step 2: Live run.** Start the local API on 8091 with a scratch SQLite database and the signing key; the recipe is in `tests/local-api-launch.ps1` and needs `BLOOD_BANNERS_SIGNING_KEY` and `BLOOD_BANNERS_DSN`. Then:
  1. Create a Void Reaches game in the browser client and claim the Void Lord.
  2. Open Place monsters and place two monsters while curl joins two hero guests. Check that the budget goes 4 → 12 → 20.
  3. Ready everyone with curl and start.
  4. Draft with curl while the browser Void Lord places more.
  5. Check with curl that a hero's `GET /games/{id}` and events contain no `v-m` ids.
  6. Confirm in the browser before the last pick, and check that the battle starts on that pick.
  7. Repeat once with the draft finishing first.

- [ ] **Step 3: Report** what was checked, and anything that looked off, to the user. Don't push or deploy without being asked.
