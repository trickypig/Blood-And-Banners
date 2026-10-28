# Carried Items Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Player units pick up potions by choice, carry up to 2, use one per turn on their activation, and may drop one on death. Nothing drinks automatically any more.

**Architecture:** A new `Items` class in the PHP rules engine owns the inventory (`units[id]['items']`), the pickup prompt (a `pending` choice of kind `itemPickup`), the `pickUpItem` / `useItem` commands' rules and the drop on defeat. `BattleRules` routes commands, choices and legal actions to it; `Potions` keeps only the kinds and the effect. `GameService` projects items; the Blazor `GameTable` shows the prompt, an Items row and Pick up / Use buttons.

**Tech Stack:** PHP 8 rules engine (`api/`, plain test runner `php api/tests/run.php`), C# contracts + Blazor UI (`src/`), bUnit/xUnit (`dotnet test tests/BloodAndBanners.UI.Tests`).

**Spec:** `docs/superpowers/specs/2026-10-27-carried-items-design.md`

## Global Constraints

- Max **2** items per unit; two of the same kind allowed.
- One item use per unit per turn; drinking from the pickup prompt counts as that use.
- Pickup is offered only at the end of the unit's own `moveUnit` (including a resolved Ice-collision choice of its own move). Never after forced movement, `moveAbility`, or a Slipping slide at activation.
- Void Lord units never pick up, carry or drop.
- Stunned, Petrified, Entrapped or Charmed (`Charm::CONDITION`) units can't pick up or use items.
- Use: unit is active or could be activated now (using starts the activation); not after attacking unless `canMove` still holds after the attack (Sting).
- Pick up while standing: costs the move (unit hasn't moved, attacked or spent move this activation); goes straight to inventory.
- Death: one random item lands on the death hex only if `board[hex]['object'] === null`; all others are lost.
- Potion effects unchanged: Power/Speed +2 for 3 of the drinker's turns counting the current; Health removes all wounds.
- UI copy: prompt buttons "Drink now", "Keep", "Leave it"; Items row heading "Items"; row hidden when empty; no empty slots.

## Review Focus

- A unit with 2 items ends its move on a potion: no prompt, no choice event, potion stays, and the turn still auto-passes if the seat has nothing left (no stuck `pending`). Test in Task 1.
- The pickup prompt is open when the seat would otherwise auto-pass: the turn must not pass until it's resolved, and must pass right after if nothing is left. Test in Task 1.
- A unit uses its last-turn item after the turn changes: `itemsUsed` resets on a fresh turn. Test in Task 2.
- A carrier killed by a parting strike mid-move, or by terrain, still drops (drop lives in `Wounds::apply`, the single defeat point). Test in Task 3.
- An older saved state with units lacking `items` and turns lacking `itemsUsed` loads and plays (`Turn::normalize`, `?? []`). Test in Task 2.

---

### Task 1: Inventory core and the pickup prompt at move end

**Files:**
- Create: `api/src/Rules/Vanilla/Items.php`
- Modify: `api/src/Rules/Vanilla/Potions.php` (remove `drinkAll`; make the effect public)
- Modify: `api/src/Rules/Vanilla/Turn.php` (`USAGE` gains `'itemsUsed' => []`)
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (remove the 4 `Potions::drinkAll` calls; offer pickup where `Crystals::claim` runs for an own move: end of `move()` and the non-`activateAfter` Ice-collision branch of `resolveChoice`; new `itemPickup` branch in `resolveChoice`)
- Test: rewrite `api/tests/PotionTest.php` → keep `potion_tests()` name; replace the auto-drink tests (push, teleport, move-end drink, passing-over, drink-before-pass, re-drink reset, VL never drinks) with the tests below. Keep the `Effects` turn-count test and the two `projectedObject` tests.

**Interfaces:**
- Produces (`Items`, all `public static`):
  - `const MAX = 2`
  - `carried(array $state, string $unitId): array` — list of potion kinds (`$unit['items'] ?? []`)
  - `blocked(array $state, string $unitId): bool` — Stunned/Petrified/Entrapped/Charmed, or a Void Lord unit (`$state['sides'][Sides::ofUnit(...)]['kind'] === 'void'`, as `Potions::drinkAll` checked)
  - `potionUnder(array $state, string $unitId): ?string` — potion kind on the unit's hex, or null
  - `usedThisTurn(array $state, string $unitId): bool` — `in_array($unitId, $state['turn']['itemsUsed'] ?? [], true)`
  - `offerPickup(array $state, string $unitId, array &$events): array` — opens the choice if allowed, else returns state unchanged
  - `resolvePickup(array $state, string $option, array &$events): array`
- Produces (`Potions`): `public static function applyEffect(array $state, string $unitId, string $hex, string $kind, array &$events): array` — today's private `drink` minus the board clearing; still emits `potionDrunk {unitId, seatId, hex, kind}`.
- Pending choice shape: `['choice' => ['choiceId' => 'choice-'.N, 'seatId', 'kind' => 'itemPickup', 'unitId', 'item' => <kind>, 'options' => [('drink' unless usedThisTurn), 'keep', 'leave'], 'targetHexes' => [<hex>], 'openedAt' => time()], 'context' => []]`; increments `choiceCounter`; emits `choiceOpened` like `IceMovement` does.
- Resolution: `drink` → clear board object, `applyEffect`, append unit to `turn['itemsUsed']`; `keep` → clear board object, append kind to `units[id]['items']`, emit `itemPickedUp {unitId, seatId, hex, kind}`; `leave` → nothing. All emit `choiceResolved {choiceId, option}` and set `pending` to null.

- [ ] **Step 1: Write the failing tests** (in `potion_tests()`; reuse the existing fixtures `vanilla_battle`, `vanilla_board(vanilla_line(n))`, `vanilla_unit`, `coop_battle`, `ability_unit`)

```php
'ending a move on a potion offers drink, keep or leave' => // a1 moves 0,0→2,0 onto power-potion (a2 at 0,1 so seat-a can still act)
    expect_same('itemPickup', $s['pending']['choice']['kind'], ...);
    expect_same(['drink', 'keep', 'leave'], $s['pending']['choice']['options'], ...);
    expect_same('power-potion', $s['pending']['choice']['item'], ...);
    expect_same('power-potion', $s['board']['2,0']['object'], 'still on the map until decided');
'keeping puts the potion in the unit\'s inventory'      // resolveChoice keep → items ['power-potion'], board null, no effects, itemPickedUp event
'drinking now applies the effect and uses the turn\'s item' // → board null, Stats::effective power 4, turn itemsUsed ['a1'], potionDrunk event
'leaving it keeps the potion on the map'                // → board 'power-potion', items [], itemsUsed []
'passing over a potion mid-move offers nothing'          // 1,0 potion, move to 2,0 → pending null
'a unit carrying two items is not offered a third'      // units a1 items ['speed-potion','health-potion'] → pending null, potion stays
'a pushed unit is not offered the potion it lands on'   // Knockback setup from the old push test → pending null, potion stays, b1 effects []
'a teleported unit is not offered the potion it lands on' // Teleport setup from the old test → pending null, wounds still 2
'the void lord\'s monsters are not offered potions'     // coop_battle setup from the old test → pending null, potion stays
'drink now is not offered once the unit has used an item this turn' // set $state['turn']['itemsUsed'] = ['a1'] before the move → options ['keep','leave']
'the turn waits for the pickup decision and passes after it' // single seat-a unit spends full move onto a potion with no enemy in reach:
    expect_same('seat-a', $moved['state']['turn']['activeSeatId'], 'not passed while deciding');
    // resolve 'leave' → expect_same('seat-b', ...['turn']['activeSeatId'], 'passes once decided');
'a full-inventory unit ending its move with nothing left still passes the turn' // same, with 2 items → activeSeatId 'seat-b'
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: the new potion tests FAIL (e.g. pending is null / potion drunk).

- [ ] **Step 3: Implement `Items`, `Potions::applyEffect`, `Turn::USAGE['itemsUsed']`, and the `BattleRules` wiring**

`offerPickup` runs only where `Crystals::claim` runs for the unit's own move (after it, same `pending === null` guard). In `resolveChoice`, add an `itemPickup` branch before the generic StepRunner path: only `$choice['seatId']` may answer (`notYourChoice`); after resolving, run the same `canAct` → `passTurn` check the other branches use. Update the `Potions` class docblock (no automatic drinking).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Items.php api/src/Rules/Vanilla/Potions.php api/src/Rules/Vanilla/Turn.php api/src/Rules/Vanilla/BattleRules.php api/tests/PotionTest.php
git commit -m "Offer drink, keep or leave when a unit ends its own move on a potion"
```

---

### Task 2: Pick up while standing, using carried items, conditions

**Files:**
- Modify: `api/src/Rules/Vanilla/Items.php`
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (`KINDS` gains `pickUpItem`, `useItem`; dispatch; legal actions; messages)
- Test: `api/tests/PotionTest.php`

**Interfaces:**
- Consumes: Task 1's `Items` functions and `Potions::applyEffect`.
- Produces commands:
  - `pickUpItem {unitId}` → legal action `['kind' => 'pickUpItem', 'unitId' => $id, 'targetHexes' => [$hex]]`
  - `useItem {unitId, item}` → one legal action per distinct carried kind: `['kind' => 'useItem', 'unitId' => $id, 'item' => $kind, 'targetHexes' => [$hex]]`
- Produces (`Items`): `canPickUp(array $state, string $unitId, bool $active): bool`, `canUse(array $state, string $unitId, bool $active, bool $canMove): bool`, `pickUp(array $state, string $unitId, array &$events): array`, `use(array $state, string $unitId, string $kind, array &$events): array`.
- New `BattleRules::MESSAGES`: `'nothingToPickUp' => 'There is no potion here to pick up.'`, `'inventoryFull' => 'That unit already carries two items.'`, `'itemUnavailable' => 'That unit cannot use that item now.'`, `'unitCannotUseItems' => 'A stunned, petrified, entrapped or charmed unit cannot pick up or use items.'`, `'slideFirst' => 'That unit must slide on the ice before doing anything else.'`

Rules (from the spec):
- Both offered only for `($active || $canStart)` units in the existing per-unit loop, and never for a not-yet-active Slipping unit (that loop `continue`s already; the commands fail `slideFirst`).
- `canPickUp`: not `blocked`, `potionUnder` non-null, `count(carried) < MAX`, and if active: `!moved && attacks === 0 && moveSpent === 0`.
- `canUse`: not `blocked`, carries ≥1, not `usedThisTurn`, and if active: `attacks === 0 || $canMove` (pass `self::canMove($state, $unitId)`).
- Commands: `activate()` first if not active (as `useAbility` does); re-check the rule after activation and fail with the matching message.
- `pickUp`: clears the board object, appends to items, sets `turn['moved'] = true`, emits `itemPickedUp`. Doesn't touch attacks.
- `use`: removes the first matching kind from items, appends to `itemsUsed`, calls `Potions::applyEffect` with the unit's hex.
- Neither calls `closeIfSpent`. The usual end-of-command `canAct` → `passTurn` still runs.

- [ ] **Step 1: Write the failing tests**

```php
'a unit standing on a potion may pick it up instead of moving' // a1 at 0,0 on speed-potion, unactivated:
    // legal actions include pickUpItem for a1; command → items ['speed-potion'], board null, activeUnitId 'a1', turn moved true,
    // no moveUnit legal action for a1 afterwards, attackUnit still legal when b1 adjacent, useItem 'speed-potion' legal
'a unit that has moved cannot pick up what it stands on'      // a1 moves 0,0→1,0, then set board 1,0 object to a potion → no pickUpItem for a1; command → expect_game_error('alreadyMoved', ...)
'a full inventory blocks picking up'                           // items 2 → no action; command → expect_game_error('inventoryFull', ...)
'using an item applies its effect, starts the activation and spends nothing else' // unactivated a1 items ['power-potion']:
    // useItem → power 4, items [], itemsUsed ['a1'], activeUnitId 'a1', activated ['a1'], moveUnit still legal for a1
'only one item per unit per turn'                              // items ['power-potion','speed-potion'], use one → no useItem for a1; command → 'itemUnavailable'
'item use resets on the next turn'                             // use, endTurn ×2 → useItem legal again for a1
'a normal unit cannot use an item after attacking'             // a1 adjacent to b1, items 1, attack (rolls) → no useItem for a1; command → 'itemUnavailable'
'a stinging unit can use an item after attacking while it may still move' // ability_unit with 'Silent Sting' (its movesAfterAttack() is true) → useItem legal after attack
'stunned, petrified, entrapped and charmed units cannot pick up or use items' // loop the four: apply via UnitConditions::apply (as existing condition tests do) → no pickUpItem/useItem; useItem → 'unitCannotUseItems'
'slowed or unsteady units still use items'                     // Slowed → useItem legal
'an old saved turn without itemsUsed still allows an item'    // unset($state['turn']['itemsUsed']), unset items key on another unit → useItem legal, legalActions for the unit without items has no useItem and no error
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: new tests FAIL with `unsupportedCommand` / missing actions.

- [ ] **Step 3: Implement** the `Items` functions, `KINDS`, dispatch branches, legal-action entries and messages above. Add `itemsUsed` to `Turn::normalize` via `USAGE` (already merged by `+ self::USAGE`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Items.php api/src/Rules/Vanilla/BattleRules.php api/tests/PotionTest.php
git commit -m "Let units pick up a potion they stand on and use carried items on their activation"
```

---

### Task 3: Drop one item when a carrier dies

**Files:**
- Modify: `api/src/Rules/Vanilla/Items.php`
- Modify: `api/src/Rules/Vanilla/Wounds.php` (call right after `unitDefeated` is emitted and `hex` set to null, using the saved `$unit['hex']`)
- Test: `api/tests/PotionTest.php`

**Interfaces:**
- Produces: `Items::dropOnDefeat(array $state, string $unitId, string $hex, ?RandomSource $random, array &$events): array`
  - No items → unchanged. One item → that one. Two or more → index `$random === null ? 0 : $random->roll(count) - 1` (roll only when count > 1, so existing roll sequences are untouched).
  - Chosen item placed if `board[$hex]['object'] === null` → emit `itemDropped {unitId, hex, kind}`; otherwise it's lost too.
  - Every item not placed emits `itemLost {unitId, kind}`. Then `units[id]['items'] = []`.

- [ ] **Step 1: Write the failing tests**

```php
'a slain carrier drops one random item on its hex and loses the rest' // b1 items ['power-potion','health-potion'], hp 1, a1 attacks with rolls [..., 2]:
    expect_same('health-potion', $s['board'][<b1 hex>]['object'], 'the second item, by the roll');
    expect_same([], $s['units']['b1']['items'], ...);
    expect_same(['itemDropped', 'itemLost'], <kinds of item events>, ...);
'a single carried item is dropped without a roll'          // items ['speed-potion'] → board object 'speed-potion'
'nothing drops onto a hex that already holds a map object' // b1 hex object 'rune-of-strength' → stays rune; itemLost ×2
'a carrier killed by a parting strike mid-move still drops' // b1 carrying, moves away from a1 with disengagement rolls killing it → object on b1's start hex
```

- [ ] **Step 2: Run to verify they fail** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → new tests FAIL.
- [ ] **Step 3: Implement** `dropOnDefeat` and the `Wounds::apply` call.
- [ ] **Step 4: Run to verify all pass** — expected `N tests, 0 failures`.
- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla/Items.php api/src/Rules/Vanilla/Wounds.php api/tests/PotionTest.php
git commit -m "Drop one random carried item where a carrier falls"
```

---

### Task 4: Project items to the client

**Files:**
- Modify: `api/src/Game/GameService.php` (unit projection `'items' => array_values($unit['items'] ?? [])`; `pendingChoiceProjection` adds `'item' => $choice['item'] ?? null`)
- Modify: `src/BloodAndBanners.Contracts/MatchProjection.cs` (`UnitProjection`: `public IReadOnlyList<string> Items { get; init; } = [];`)
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs` (`PendingChoiceProjection`: `public string? Item { get; init; }`; `LegalAction`: `public string? Item { get; init; }`)
- Test: a PHP projection test in `api/tests/PotionTest.php` using whatever `GameService`/match-service helper `VanillaMatchServiceTest` uses to fetch a projection; a C# deserialization test in `tests/BloodAndBanners.UI.Tests/GameClientTests.cs` following its existing JSON-fixture style.

Legal actions already pass extra keys through (`$action + ['ability' => null]`), so `item` reaches the client without a GameService change.

- [ ] **Step 1: Write the failing tests**
  - PHP `'the projection shows carried items and the potion on offer'`: unit `items` equals `['power-potion']`; with an `itemPickup` pending, the owner's `pendingChoice['item']` is `'speed-potion'`; a `useItem` legal action carries `item`.
  - C# `Projection_reads_items_and_item_choice`: JSON with `"items":["health-potion"]`, `"pendingChoice":{..., "kind":"itemPickup","item":"health-potion"}`, legal action `"item":"health-potion"` → the three properties equal `"health-potion"`.
- [ ] **Step 2: Run to verify they fail** — `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` and `dotnet test tests/BloodAndBanners.UI.Tests --filter Projection_reads_items_and_item_choice` → FAIL.
- [ ] **Step 3: Implement** the projection keys and contract properties.
- [ ] **Step 4: Run to verify they pass** (same commands) → PASS.
- [ ] **Step 5: Commit**

```bash
git add api/src/Game/GameService.php src/BloodAndBanners.Contracts api/tests tests/BloodAndBanners.UI.Tests/GameClientTests.cs
git commit -m "Project carried items, the offered potion and item actions"
```

---

### Task 5: Pickup prompt, Items row, Pick up button, log lines

**Files:**
- Create: `src/BloodAndBanners.UI/Services/ItemText.cs`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor` (+ `.razor.css` for `.unit-items`)
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`, `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`

**Interfaces:**
- Consumes: Task 4 contract properties; `MapObjectArt.Display(kind)`.
- Produces: `public static class ItemText { public static string Effect(string kind); }` returning exactly `"+2 Power for 3 turns"`, `"+2 Speed for 3 turns"`, `"Heals all wounds"`.

UI decisions:
- **Prompt:** new branch before the generic `PendingChoice` fallback: `Projection.PendingChoice is { Kind: "itemPickup" } pickup && pickup.SeatId == Projection.Viewer.SeatId` → `<section class="choice-prompt" data-testid="item-pickup">`, `<h2>` = `MapObjectArt.Display(pickup.Item)`, `<p>` = `"{unit name} has found a {Display}: {ItemText.Effect}."`, then three buttons `data-choice="drink|keep|leave"` labelled "Drink now", "Keep", "Leave it"; Drink now always rendered, `disabled` when `"drink"` isn't in `Options`. Each calls `Resolve(pickup.ChoiceId, option)`.
- `ControlChoiceTitle` gains `"itemPickup" => "Potion found"` (for the waiting opponent).
- **Items row** on the unit card, between the conditions and `AbilityList`, only when `selected.Items.Count > 0`: `<div class="unit-items" data-testid="unit-items"><h4>Items</h4>` then per item `<span class="unit-item" data-item="@kind">@Display` plus, when `Projection.Viewer.CanCommand` and a `useItem` legal action exists with `UnitId == selected.UnitId && Item == kind`, `<button data-action="useItem">Use</button>` sending `useItem` with `new { unitId, item = kind }`. Duplicate kinds render twice; each Use sends the same payload.
- **Pick up** button on the unit card (same spot, shown even when the Items row is hidden) when a `pickUpItem` legal action exists for `selected.UnitId`: `<button data-action="pickUpItem">Pick up @Display(potion on the unit's hex)</button>` sending `pickUpItem` with `new { unitId }`. Find the potion via `Projection.Board.Hexes` and `HexKey(hex) == selected.Hex`.
- **Log** (`CombatLogFormatter.Describe`): `itemPickedUp` → `"{Unit} picks up a {Display}."`; `itemDropped` → `"{Unit} drops a {Display}."`; `itemLost` → `"{Unit}'s {Display} is lost."`. `potionDrunk` unchanged.

- [ ] **Step 1: Write the failing tests** (select a unit the way existing unit-card tests in `GameTableTests` do)

```csharp
The_pickup_prompt_offers_drink_keep_or_leave        // PendingChoice itemPickup ["drink","keep","leave"], Item "speed-potion", UnitId "s1-u3":
    // prompt text contains "Speed Potion" and "+2 Speed for 3 turns"; clicking [data-choice=keep] sends resolveChoice {choiceId, option:"keep"}
Drink_now_is_disabled_when_the_unit_has_used_an_item // Options ["keep","leave"] → button[data-choice=drink] has disabled
A_unit_without_items_shows_no_items_row             // selected unit Items [] → no [data-testid=unit-items]
Carried_items_are_listed_with_use_buttons           // Items ["power-potion","health-potion"], LegalActions useItem for both → two [data-item], click Use on health → useItem {unitId:"s1-u3", item:"health-potion"}
An_enemy_units_items_show_without_use_buttons      // enemy unit Items ["power-potion"], no legal action → row present, no [data-action=useItem]
Pick_up_offers_the_potion_under_the_unit            // board hex at the unit's hex Object "health-potion", legal pickUpItem → button text "Pick up Health Potion", click sends pickUpItem {unitId}
```
And in `CombatLogFormatterTests`: `Picking_up_dropping_and_losing_items_read_naturally` asserting the three endings `"picks up a Power Potion."`, `"drops a Health Potion."`, `"'s Speed Potion is lost."`.

- [ ] **Step 2: Run to verify they fail** — `dotnet test tests/BloodAndBanners.UI.Tests --filter "FullyQualifiedName~GameTableTests|FullyQualifiedName~CombatLogFormatterTests"` → new tests FAIL.
- [ ] **Step 3: Implement** `ItemText`, the GameTable markup/handlers, the CSS (match the existing `.unit-condition` / `.unit-card` look), and the three log lines.
- [ ] **Step 4: Run to verify they pass** (same command) → all PASS.
- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Show the potion pickup prompt, carried items and Pick up / Use buttons"
```

---

### Task 6: Rule book and full verification

**Files:**
- Modify: `rulebook.html:1505` (Items bullet)

- [ ] **Step 1: Rewrite the Items bullet** to say: potions block nothing; a player unit that ends its own move on one may drink it now, keep it or leave it, and one already standing on a potion may pick it up instead of moving; being pushed, teleported or sliding there doesn't pick it up; units carry up to two items and use one per turn on their activation, before or after moving but not after attacking (Sting excepted); using one starts the unit's activation if needed; stunned, petrified, entrapped or charmed units can't pick up or use items; a slain carrier drops one random item on its hex if the hex is empty, the rest are lost; effects as before; the Void Lord's monsters never take potions.
- [ ] **Step 2: Run the full suites**

Run: `php api/tests/run.php 2>&1 | tail -1` → `N tests, 0 failures`
Run: `dotnet test tests/BloodAndBanners.UI.Tests` → `Failed: 0` (the AttackPopup queued test is known-flaky on main; rerun once if it alone fails).

- [ ] **Step 3: Commit**

```bash
git add rulebook.html
git commit -m "Rule book: carried items"
```
