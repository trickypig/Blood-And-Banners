# Forced movement and stun — design

Date: 2026-10-12
Status: approved in conversation; awaiting written-spec review

## Goal

The last four non-terrain abilities become playable: **Knockback**, **Gust of Wind**, **Teleport** and **Stunning Blow**. Along the way the engine gains a general **conditions** framework (Stunned is its first condition; the terrain slice will add Unsteady, Slipping and Poisoned) and a **forced movement** module for pushes.

Builds on the abilities slice (`2026-10-11-vanilla-abilities-design.md`): ability registry, `Stats::effective`, `Effects`, `StepRunner`, `Wounds`.

## Scope

In scope:

- Knockback (Hammerer, Essence), Stunning Blow (Bruiser, Essence), Gust of Wind (Thalion Windrider, Ability), Teleport (Malachar the Voidcaller, Attack).
- Conditions framework with Stunned.
- Forced movement (pushes) with collisions, walls, board edges and falls.
- Client: two-step Teleport targeting, Gust push preview, condition display, combat-log lines.

Deferred: Flying and Lava Walker (remain "Not yet in the digital game"); terrain conditions Unsteady, Slipping, Poisoned; water, ice, lava, poison terrain rules.

## Rules as implemented

The rulebook's ice section defines collisions (both units take 1 attack die with no defense roll) and falls (1 attack die per level dropped, no defense roll); pushes reuse them. Where the rulebook and cards are silent, the choice below is the decision for this slice.

### Pushes (Knockback, Gust of Wind)

- Direction: straight away from the pusher — the hex direction from the pusher's hex to the pushed unit's hex. Both pushing abilities are melee (the pusher is adjacent), so the direction is always one of the six axial directions.
- The push moves one hex at a time, up to its distance. For each step into the next hex:
  - **Empty, walkable hex at most 1 level higher, level, or lower**: the unit moves in. A drop of 1 level is a normal step.
  - **Empty, walkable hex 2 or more levels lower**: the unit falls in and takes 1 attack die per level dropped (each sword is a Wound, no defense roll). The push continues if distance remains.
  - **Hex holding a unit (friend or foe)**: the push stops before it; the pushed unit and that unit each take 1 attack die with no defense roll.
  - **Wall (2 or more levels higher), sight blocker, or off the board**: the push stops; the pushed unit takes 1 attack die with no defense roll.
- Forced movement (pushes and teleports) never triggers disengagement strikes.
- A unit defeated during a push stops there; remaining push steps are skipped. If the push's damage ends the match, resolution stops.

### Knockback (Hammerer, Essence)

- After each of the Hammerer's attacks that lands on a target which survives, the target rolls a d20 save: **11 or higher** — nothing; **2–10** — pushed 1 hex; **1** — pushed 2 hexes.
- The save happens even when the attack dealt 0 wounds (the attack was made). It does not happen when the attack was evaded (no attack was rolled) or when the target was defeated.

### Stunning Blow (Bruiser, Essence)

- Same trigger as Knockback. On a d20 save below 11, the target becomes **Stunned**.

### Stunned (condition)

- A stunned unit cannot move (including using Gust of Wind), makes no disengagement strikes, and has −2 Power.
- It lasts through the stunned unit's owner's next turn and ends when that turn ends, whether or not the unit activates. Since stuns land during the attacker's turn, that is the victim's following turn; if a unit were stunned during its own seat's turn, it would still last through that seat's following turn.
- A stunned unit can still be pushed and teleported by others.
- Re-stunning refreshes the expiry; it does not stack.

### Gust of Wind (Thalion Windrider, Ability)

- Target: an adjacent enemy whose printed Move is 3 or more.
- Used "instead of moving": only while Thalion has not moved in his current activation (using it activates him if he has not activated this turn), and using it counts as his move. Not usable while Thalion is stunned.
- Pushes the target 2 hexes away from Thalion. Does not use his attack. Once per turn (like all Abilities).

### Teleport (Malachar the Voidcaller, Attack)

- Choose a friendly unit within 3 hexes of Malachar (Malachar included), then a destination hex.
- The destination must be empty and walkable (on the board, not a sight blocker) and satisfy: hex distance from the unit's hex + ⌊|elevation change| ÷ 2⌋ ≤ 3.
- Line of sight, units and walls in between are ignored. No disengagement strikes.
- Counts as Malachar's attack; locked by Fragile Focus like his other spells.

## Server design

### Conditions framework — `api/src/Rules/Vanilla/Conditions/`

State: top-level `conditions: [{name, unitId, sourceUnitId, expires, data}]`. At most one of each condition name per unit; reapplying replaces the entry (refreshing its expiry).

`expires` is one of:

- `{kind: 'afterTurn', turnNumber}` — removed when turn `turnNumber` ends (Stunned uses the stunned seat's next turn number, `Turn::nextTurnNumberOf`).
- `{kind: 'turnStart' | 'turnEnd', seatId}` — same semantics as `Effects`.
- `{kind: 'whileOnTerrain'}`, `{kind: 'untilCleared'}` — reserved for the terrain slice; never expired by turn boundaries.

Code:

- `Condition` (abstract, no-op hooks): `statDelta(state, unitId, stat, context): int`, `blocksMovement(): bool`, `blocksDisengagementStrike(): bool`, `onTurnStart(state, unitId, events): state`, `onTurnEnd(state, unitId, events): state`.
- `ConditionRegistry`: name → handler. This slice registers `Stunned`.
- `Conditions` service: `apply(state, name, unitId, sourceUnitId, expires, events)`, `has(state, unitId, name)`, `forUnit(state, unitId)`, `any(state, unitId, callable)`, `atTurnEnd(state, events)`, `atTurnStart(state, events)` (run hooks, then expire matching entries), `clear(state, unitId, name, events)`.
- Integration: `Stats::effective` adds condition `statDelta`s; movement legality checks `blocksMovement` (no `moveUnit` legal actions; `moveUnit` fails with `unitStunned`); `Movement::disengagedFrom` skips enemies whose conditions block strikes; `BattleRules::passTurn` calls `atTurnEnd` before the turn changes and `atTurnStart` after; defeated units' conditions are removed.
- Events: `conditionApplied {name, unitId, sourceUnitId, expires}`, `conditionExpired {name, unitId}`.

### Forced movement — `api/src/Rules/Vanilla/Forced.php`

- `Forced::push(array $state, string $unitId, array $direction, int $hexes, ?string $sourceUnitId, RandomSource $random, array &$events): array` implements the push rules, applying damage through `Wounds::apply`, and emits `unitPushed {unitId, sourceUnitId, from, to, path, collisions: [{unitId, faces, wounds}], falls: [{levels, faces, wounds}], blocked: 'unit'|'wall'|'edge'|null}`. Focus hexes: the path.
- `Forced::directionFrom(string $fromHex, string $toHex): array` — the axial direction for adjacent hexes.
- `Forced::teleportDestinations(array $state, string $unitId): array` — hexes satisfying the Teleport rule.

### Abilities

- New `Ability` hook `afterHit(array $state, string $ownerId, string $targetId): array` (steps to run after a strike lands on a surviving, non-evading target). `StepRunner::land` queues those steps for the attacker's abilities.
- New step ops: `save {unitId, sourceUnitId, ability, onFail}` (rolls d20, emits `saveRolled {unitId, ability, roll, saved}`, then applies Knockback's push or Stunning Blow's condition), and `push {unitId, sourceUnitId, hexes}`.
- `Knockback`, `StunningBlow` (essences with `afterHit`), `GustOfWind` (TargetedAbility with the stillness rule), `Teleport` (SpecialAttack).
- `SpecialAttack::validate` and `steps` gain an `array $options` argument (Teleport reads `toHex`); the `specialAttack` command passes `{toHex}` from its payload.
- New error codes: `invalidDestination`, `unitStunned`, `gustNeedsStillness`.
- The deferred list shrinks to Flying and Lava Walker.

### Projection

- Units gain `conditions: [{name, expires}]` where `expires` is `endOfThisTurn` (an `afterTurn` condition whose turn is the current one), `endOfNextTurn` (an `afterTurn` condition whose turn is still ahead), `turnStart` / `turnEnd` (seat-bound, as for effects), `whileOnTerrain` or `untilCleared`.
- Teleport's legal action lists the teleportable friendly units' hexes; destinations are computed by the client with the same formula and validated by the server.
- `MatchEngine::normalize` adds `conditions: []` for states saved before this slice. In-progress matches whose units already carry these ability names start using them once deployed.

## Client design

- Contracts: `ConditionProjection(Name, Expires)`; `UnitProjection.Conditions`.
- Teleport targeting: step 1 highlights teleportable friendly units (gold) — banner "Teleport: choose a unit"; step 2 highlights destinations (blue) computed with `HexMath.Distance` and board elevations — banner "Teleport: choose where"; clicking sends `specialAttack {unitId, ability: 'Teleport', targetUnitIds: [friendId], toHex}`. Esc / Cancel leaves; step 2 can go back to step 1.
- Gust of Wind: normal ability targeting; hovering a target previews its push path (arrow markers on up to 2 hexes, stopping where it would be blocked).
- Conditions: unit card line "Stunned — can't move, no parting strikes, −2 Power, until the end of its next turn"; effective Power shows the stun as a reason; token badge 💫; hex `aria-label` lists conditions.
- Combat log: lines for `saveRolled`, `unitPushed` (with collisions, falls and blocks), `conditionApplied`, `conditionExpired`, and a new `unitTeleported {unitId, casterId, from, to}` event (Teleport rolls no dice, so it emits no `attackResolved`).

## Testing

- PHP: push resolution (free, unit collision both damaged, wall, sight blocker, edge, 2+ level fall, 1-level drop no damage, death mid-push, match end from collision); Knockback save ≥ 11 / 2–10 / 1, no save when evaded or killed; Stunning Blow; Stunned lifecycle (−2 Power, no move, no disengagement strike, expiry at the end of the stunned seat's next turn, refresh not stack); Gust (Move ≥ 3 filter, stillness, stunned Thalion); Teleport (destination formula with height, occupied, unwalkable, self, Fragile Focus lock); conditions JSON round trip; old-state compatibility; catalog smoke test updated for the shorter deferred list.
- .NET: Teleport two-step targeting, Gust push preview, Stunned card and badge, combat-log lines, contract fixture.
- Manual: README walkthrough step.
