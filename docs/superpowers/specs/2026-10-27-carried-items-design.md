# Carried items: picking up potions to use later (design)

Date: 2026-10-27. Builds on `2026-10-25-runes-and-potions-design.md` and replaces its potion pickup rule.

## Why

Today a player unit drinks a potion the moment it comes to rest on one, even when it's pushed there. Players
want pickups to be a real decision: grab a potion and save it for the turn it matters, or leave it for a unit
that needs it more. Items belong to the unit, not the player, so which unit carries what matters.

## Goals

- When a unit chooses to pick up a potion, its owner decides: drink it now, keep it, or leave it on the map.
- Units carry up to 2 items and use them on a later activation.
- Killing a carrier can put one of its items back on the map.

## Non-goals

- Item types other than the three potions. The inventory is shaped as "items" so other kinds can come later,
  but only potions exist now.
- Trading or handing items between units, or dropping them on purpose.
- Void Lord monsters picking up or carrying items. The Void Lord places potions as rewards his monsters guard
  (as now).
- Changes to what the potions do.

## Anti-goals

- Picking an item up by accident. Pickup happens only when the player chooses to move onto the item, or
  chooses to pick it up while standing on it. Being shoved onto a potion must never grab or drink it.
- Clutter on the unit card for units carrying nothing.

## Rules (decided by the user)

### Picking up

- A pickup is offered only when a player unit **ends its own normal move** on a hex holding a potion.
  Forced or ability movement onto a potion (push, pull, teleport, Sky Toss, ice slide caused by someone
  else, etc.) never triggers a pickup or a drink. The potion just stays under the unit.
- The owner then picks one of three options:
  - **Drink now:** applies the potion's effect immediately and removes it from the map. This counts as the
    unit's one item use for the turn, so it's unavailable if the unit has already used an item this turn.
  - **Keep:** moves the potion into the unit's inventory and removes it from the map. Uses no item use.
  - **Leave it:** the potion stays on the map. Uses nothing.
- If the unit already carries 2 items, there's no choice and no prompt. The potion stays on the map.
- **Standing on a potion at activation:** a unit that begins its activation on a potion's hex may pick it up
  **instead of moving**. Picking it up uses the unit's move for the activation. The unit can still attack,
  and can still use an item (including the one it just picked up) before or after attacking. As shown in the
  mockup, the three-way choice is not offered here. Picking up puts the potion straight into the inventory, and the
  player can then drink it at once with Use. If the inventory is full, no pickup is offered.

### Carrying and using

- A unit holds at most 2 items. Two of the same kind is allowed.
- A unit may use **one** carried item per turn. It must be the active unit, or a unit that could be activated
  right now. Using an item on an unactivated unit **starts its activation** (spends one of the turn's
  activations, like `moveUnit` / `useAbility` do).
- Before moving or after moving, but **not after attacking**. Exception: a unit with an ability that lets it
  split its movement around an attack (Sting today, i.e. the existing `canMove` after-attack path) may still
  use an item after attacking while its activation is open.
- Using an item is free: it doesn't spend the move or an attack. Using an item does not itself keep an
  activation open. When the activation closes (e.g. after the last attack), the chance is gone.

### Conditions

- A unit that is **Stunned, Petrified, Entrapped or Charmed** can't pick up items (neither the move-end
  prompt nor Pick up) and can't use carried items.
- All other conditions (Slipping, Slowed, Unsteady, Hibernating, Enraged, Poisoned, etc.) don't affect items.
- Using an item applies exactly the same effect as drinking the potion from the map does today (Power/Speed
  +2 for 3 of the drinker's player's turns, counting the current turn; Health removes all wounds) and removes
  it from the inventory.

### When a carrier dies

- If a unit carrying items is defeated, one of its items is chosen at random. That item is placed on the hex
  where the unit died **only if that hex has no map object** on it. Otherwise it is lost.
- Every other item it carried is lost.

## Client (decided by the user from mockups)

- **Pickup prompt:** shown in the same place and style as the existing decision prompts (Moonlight Glare,
  Ice collision). Title is the potion's name, a line says what it does, and there are three buttons: Drink now,
  Keep, Leave it. Drink now is disabled when the unit has already used an item this turn.
- **Pick up while standing on a potion:** a "Pick up <potion name>" button in the orders panel for the active
  unit, available only when the rules allow it (hasn't moved yet, has room).
- **Inventory:** an **Items** row on the unit card listing each carried item with a **Use** button. The row is
  **not shown at all** when the unit carries nothing, and there are no empty-slot placeholders.

## Builder's calls (not discussed with the user)

- **Ice:** if a unit's own `moveUnit` ends in an ice slide (including after an Ice collision choice) that comes
  to rest on a potion, the pickup prompt is offered once the slide settles. A slide at activation caused by
  Slipping (from an earlier push) counts as forced movement: no prompt. The unit has then moved, so Pick up
  isn't available that activation either.
- **Ability movement** (`moveAbility`, e.g. Burrow) does not offer a pickup. Only a normal move does.
- **Hazardous death hex:** a dropped item lands on the death hex whatever its terrain (water, lava, chasm),
  as long as the hex has no map object.

- Inventory lives on the unit in match state (e.g. `units[id]['items']`, a list of potion kinds) and is
  exposed on the unit projection. Older saved states without the field read as empty.
- Item use is tracked per turn in `Turn::USAGE` (e.g. `itemUsed`), reset each fresh turn.
- The pickup prompt uses the existing `pending` choice mechanism, owned by the moving unit's seat. Rules
  engine shape (new action kinds such as `pickUpItem` / `useItem`, option names, event names) is the
  builder's call, following existing `BattleRules` patterns.
- The existing `Potions::drinkAll` calls (after commands, after choices, at turn start) are removed or
  replaced. Nothing drinks automatically any more.
- Use buttons and Pick up appear only for the viewer's own active unit while legal (driven by legal actions,
  like abilities). Other players and spectators see a unit's Items row without buttons.
- Battle log lines for: picking up, leaving (optional), using an item, an item dropped on death, items lost
  on death. The existing `potionDrunk` line stays for drinking.
- Rule book (`rulebook.html`) Items bullet is rewritten to match these rules.

## Constraints

- PHP rules engine under `api/src/Rules/Vanilla`; Blazor UI under `src/BloodAndBanners.UI`; contracts in
  `src/BloodAndBanners.Contracts/MatchProjection.cs`.
- Tests: PHP engine tests alongside `api/tests/PotionTest.php`; bUnit tests for the prompt, Items row
  visibility and Use button.

## Out of scope

Deploy, push and APK rebuild (the user decides separately).
