# Vanilla abilities — design

Date: 2026-10-11
Status: approved in conversation; awaiting written-spec review

## Goal

Heroes and units in a Vanilla duel use their printed abilities instead of fighting on stats alone. Every ability that works on today's board (no forced movement, no terrain types) is playable; the rest are visible on cards and marked as not yet in the digital game.

Builds on the playable duel slice (`2026-10-11-vanilla-playable-match-design.md`).

## Scope

In scope — 22 abilities in five groups:

| Group | Abilities |
|---|---|
| A. Passive modifiers | Trembling Fear, Reckless Fury, Unbreakable Line (Left Guard, Right Guard), Evasion, One Last Horn |
| B. Extra / flexible actions | Dual Strike, Twin Strikes, Dual Strikes, Silent Sting |
| C. Special attacks | Bolt of Annihilation, Flame Strike, Chaos Strike, Fireball, Chain Lightning, Mystic Shield |
| D. Timed buffs / debuffs | Shield Wall, Warlord's Command, Rune of Warding, Rune of Distraction, Fragile Focus |
| E. Player choice mid-roll | Moonlight Glare |

Deferred (shown on cards with a "Not yet in the digital game" tag, no effect in play):

- Forced movement and status: Teleport, Gust of Wind, Knockback, Stunning Blow.
- Terrain-dependent: Flying, Lava Walker, and fire/water damage types (Flame Strike's fire tag has no effect yet).

## Rules as implemented

The rulebook framework (rulebook.html, "Unit Activation"):

- An activated unit may move, then attack.
- A unit with special **Attacks** may use one of them instead of its normal attack.
- A unit's **Abilities** may each be used once per turn, at any time during its owner's turn, only by units activated this turn. Never during the other player's turn.
- An **Essence** always applies, whoever's turn it is and whether or not the unit activated.

Where the card text is silent or ambiguous, the choice below is the decision for this slice.

### Turn flow

- Attacking ends a unit's movement, except Silent Sting. The activation ends once its attacks are used up (one, or two with a Dual Strike-type essence) or the player ends it. Separately, a unit activated this turn can still use its unused Abilities for the rest of its owner's turn, even after its activation has ended.
- Using an Ability with a unit that has not activated this turn activates it, spending one of the seat's activations under the existing activation rules (including the three-non-hero limit while the hero lives). Using an ability does not consume the unit's move or attack.
- "Until your next turn" means until the start of the caster's seat's next turn. "Until end of turn" means until the current seat's turn ends.
- Effects on different units stack. Reapplying the same named effect to the same unit refreshes its expiry instead of stacking.
- Effective Power, Defense, Range and Move never go below 0.
- Order of Power modifiers: base, then additive modifiers (effects, essences), then floor at 0, then multipliers (Chaos Strike).

### A. Passive modifiers

- **Trembling Fear** (Urgrok): a non-hero unit attacking Urgrok gets −1 Power. Applies to normal and special attacks. Does not apply to Fireball splash or disengagement hits (those are not Power rolls).
- **Reckless Fury** (Berserker): +1 Power per Wound the Berserker currently has, on his attacks.
- **Unbreakable Line** (Left Guard, Right Guard): +1 Defense while the other Guard or the Center is adjacent. The Center has no ability of its own.
- **Evasion** (Thief): whenever the Thief is the target of an attack roll — normal attack, special attack, a Chain Lightning link, or a disengagement hit — roll a d20 first. On 11 or higher the attack misses and no attack or defense dice are rolled. Fireball splash does not target, so it is not evaded.
- **One Last Horn** (Azgaroth): the first time Azgaroth's Wounds would reach his HP, his Wounds are set to HP − 1 instead and the essence is spent for the rest of the match.

### B. Extra / flexible actions

- **Dual Strike** (Ravager), **Twin Strikes** (Blade Dancer), **Dual Strikes** (Briarfang): the unit may make two attacks in its activation, at the same or different targets. All movement happens before the first attack.
- **Silent Sting** (Thornshaft): the Thornshaft may split its Move before and after its attack, total no more than its Move. Disengagement applies to each move as normal.

### C. Special attacks (replace the normal attack)

All use normal line-of-sight rules for their target unless stated.

- **Bolt of Annihilation** (Malachar): Power 6, Range 5.
- **Flame Strike** (Ignar): Power 3, Range 5.
- **Chaos Strike** (Champion of Chaos): uses the Champion's normal Range. Roll a d20: 10–20 the attack rolls double his effective Power in dice; 1–9 no attack is made. Either way the Champion gets −2 Defense until his next turn.
- **Fireball** (Wizard): Power 4, Range 4 against the target. Then every other unit adjacent to the target — friend or foe, including the Wizard — takes 1 attack die of damage (each sword is a Wound) with no defense roll.
- **Chain Lightning** (Sorcerer): Power 3. Choose 1–3 different enemy units in order. The first must be within 5 hexes of the Sorcerer and in line of sight. Each later target is measured from the previous target and ignores line of sight. The sum of the hop distances (Sorcerer → 1st → 2nd → 3rd) must be 5 or less. Each target defends separately, in order.
- **Mystic Shield** (Malachar): one friendly unit within 4 hexes, Malachar included, gets +3 Defense until Malachar's next turn. No line of sight needed. It counts as Malachar's attack for the activation.
- **Teleport** (Malachar): deferred.

### D. Timed buffs / debuffs

- **Shield Wall** (Thorgrim, Ability): one adjacent friendly unit other than Thorgrim gets +1 Defense until Thorgrim's next turn.
- **Warlord's Command** (Durgin, Ability): one adjacent friendly unit other than Durgin gets +1 Power until end of turn.
- **Rune of Warding** (Rune Smith, Ability): one friendly unit within the Rune Smith's Range (3), Rune Smith included, gets +2 Defense until his next turn. No line of sight needed.
- **Rune of Distraction** (Rune Smith, Ability): one unit within range 3 gets −2 Defense until the Rune Smith's next turn. No line of sight needed.
- **Fragile Focus** (Malachar, Essence): if Malachar takes any Wound, he cannot use his special Attacks during his seat's next turn. His normal attack is unaffected. A wound during his own turn locks the following turn, not the current one.

### E. Moonlight Glare (Elvish Duelist, Essence)

- Once during each seat's turn (once on its owner's turn, once on the opponent's), the Duelist's owner may re-roll one of the Duelist's own dice: one of its attack dice when it attacks, or one of its defense dice when it defends.
- The choice opens after both the attack and defense dice are rolled and before Wounds are applied, and only when at least one of the Duelist's dice is not already its best face (sword when attacking, shield when defending).
- Options: one per re-rollable die ("Re-roll attack die 2 (blank)") plus "Keep". The re-roll replaces that die; the result stands.
- While the choice is pending, every other command is rejected. When the choice is more than 45 seconds old (server clock), the other seat may resolve it as "Keep".

## Server design

### State

Copied into match state at pick time with the stats, so print-data edits never change an in-progress match:

- `units[id].abilities`: `[{name, type, description}]`.

New per-unit fields:

- `units[id].usage`: per-turn record — `abilitiesUsed[]`, `attacksMade`, `movedBeforeAttack`, `movedAfterAttack`, `glareUsed`. Reset for every unit by `Turn::fresh()`.
- `units[id].flags`: persistent — `lastHornSpent`, `focusLockedForTurn` (round/seat the lock applies to).

New top-level fields:

- `effects`: `[{effectId, name, sourceUnitId, targetUnitId, stat, amount, expires: {seatId, at: "turnStart" | "turnEnd"}}]`. Expired entries are removed (with an `effectExpired` event) when the matching turn boundary passes.
- `pending`: `null` or `{steps[], context, choice: {choiceId, seatId, kind, options[], openedAt}}`.

Missing fields on matches saved before this slice are read as empty: no abilities, no effects, no usage, no pending choice. Those matches keep playing on stats alone.

### Abilities module — `api/src/Rules/Vanilla/Abilities/`

- `AbilityRegistry`: maps ability name → handler instance; `isPlayable(name)`; lists a unit's handlers.
- One class per implemented ability, implementing only the hooks it needs:
  - `modifyStat(state, unitId, stat, context): int` — additive delta (Trembling Fear, Reckless Fury, Unbreakable Line).
  - `beforeDefend(state, attackContext, random): ?outcome` — Evasion.
  - `onDefeat(state, unitId): ?state` — One Last Horn.
  - `attackAllowance(unit): int` — Dual Strike family.
  - `canMoveAfterAttack(unit): bool` — Silent Sting.
  - `specialAttack(): {targeting, steps(state, unitId, targets)}` — group C.
  - `abilityAction(): {targets(state, unitId), apply(state, unitId, targetId)}` — group D Abilities.
  - `afterRolls(state, attackContext): ?choice` — Moonlight Glare.
- `Stats::effective(state, unitId, stat, context)`: the single place that reads Power, Defense, Range and Move. It sums the base stat, `effects` entries and essence `modifyStat` deltas, then floors at 0. `Combat`, `Movement` and the projection all go through it.

### Attack resolution as steps

- `Combat` turns every attack into a list of steps: `evadeCheck`, `rollAttack`, `rollDefense`, `glareCheck`, `applyWounds`, `splash`, `chaosRoll`. A normal attack, Fireball (target then splash) and each Chain Lightning link are step lists.
- `StepRunner` executes steps in order. A step that opens a choice stores the remaining steps and context in `pending` and stops; the command returns with a `choiceOpened` event.
- `resolveChoice` from the owning seat (or from the other seat with `keep` after 45 s) applies the option and resumes the runner from the saved steps.
- Wounds go through one `wound()` path that runs `onDefeat` hooks before removing a unit, then checks annihilation as today.

### Commands

| Command | Payload | Notes |
|---|---|---|
| `specialAttack` | `{unitId, ability, targetUnitIds[]}` | One target, or 1–3 ordered targets for Chain Lightning. Activates the unit if needed; counts as an attack. |
| `useAbility` | `{unitId, ability, targetUnitId}` | Activates the unit if needed; once per ability per turn. |
| `resolveChoice` | `{choiceId, option}` | Only command accepted while `pending` is set. |

`moveUnit` allows a second move for Silent Sting (remaining budget), and `attackUnit` allows a second attack for the Dual Strike family.

New error codes and messages: `unknownAbility`, `abilityNotPlayable`, `abilityAlreadyUsed`, `abilityLocked`, `invalidAbilityTarget`, `chainTooLong`, `choicePending`, `notYourChoice`, `unknownChoice`, `choiceNotExpired`.

### Legal actions and projection

- `LegalAction` gains optional `ability`. The server lists one `specialAttack` action per usable special attack (target hexes = valid targets; for Chain Lightning, valid first-hop targets) and one `useAbility` action per usable ability (target hexes = valid targets).
- While `pending` is set, the owning seat's only legal action is `resolveChoice`; the other seat gets `resolveChoice` (option `keep`) once 45 s have passed, and nothing else.
- `pendingChoice` (null today) is sent only to the seat that owns the choice. Every viewer gets `choicePending: true|false`.
- Units project `abilities` (name, type, description, playable, usedThisTurn), `effects` (name, stat, amount, expiry) and effective stats.

### Events

New: `abilityUsed`, `effectApplied`, `effectExpired`, `evaded`, `survivedDefeat`, `chaosRolled`, `splashDamage`, `choiceOpened`, `choiceResolved`. `attackResolved` gains `ability` (null for a normal attack) and `rerolled` (`{side, index, from, to}` or null).

## Client design

### Contracts

- `UnitProjection`: `Abilities` (`AbilityProjection(Name, Type, Description, Playable, UsedThisTurn)`), `Effects` (`EffectProjection(Name, Stat, Amount, Expires)`), and effective `Power`, `Defense`, `Range`, `Move` alongside the base values.
- `LegalAction`: optional `Ability`.
- `GameProjection`: `ChoicePending`.
- `PendingChoiceProjection`: `ExpiresAt` (when the other seat may keep).

### Unit card (command rail)

- Stats show base and effective values ("Defense 3 → 4"); the reason is in a tooltip / focus text ("Shield Wall +1 until your next turn").
- Abilities listed with a type badge (Attack, Ability, Essence) and card text. Usable special attacks and abilities are buttons; essences are labels; used abilities are disabled with "Used"; deferred ones carry "Not yet in the digital game".
- Draft hero and army cards list the same abilities.

### Targeting mode

- Clicking an enemy still makes a normal attack.
- Choosing a special attack or ability button enters targeting mode: the button stays pressed, only that action's targets are highlighted (red for enemies, gold for allies), and a banner names the action with Esc / Cancel to leave. Clicking a highlighted hex sends the command. Any error leaves targeting mode.
- Fireball: hovering or focusing a target also marks the splash hexes.
- Chain Lightning: targets are chosen in order and marked 1–3; next-hop candidates are highlighted from the remaining distance budget (hex distance only, since later hops ignore line of sight); a Strike button sends the chain with 1–3 targets.

### Board

- Tokens show a small ▲ (buff) or ▼ (debuff) marker while effects apply; the hex label describes them for screen readers.

### Moonlight Glare prompt

- The existing choice prompt shows the Duelist's dice as faces; clicking a die re-rolls it, or choose Keep.
- The other seat sees "Waiting for your opponent's decision…", and a "Keep for them" button once `ExpiresAt` passes.
- Polling uses the fast cadence while `ChoicePending` is true.

### Combat log

Each new event gets a line, e.g. "Thief evades (rolled 14).", "Fireball scorches Grunt and Tank for 1.", "Azgaroth refuses to fall: One Last Horn.", "Chaos Strike falters (rolled 6).", "Elvish Duelist re-rolls a blank: sword."

## Testing

- PHP: one test per ability handler using `vanilla_battle` helpers and scripted dice; `StepRunner` suspend / resume / 45 s keep / command rejection while pending; effect expiry at turn start and end; a full duel with Sol Invictus vs Amethyst Thornguard using abilities; old-state compatibility (a match state without the new fields still plays).
- .NET (bUnit): unit card ability states; targeting mode enter / send / cancel; Fireball splash preview; Chain Lightning ordered picks and Strike; Moonlight Glare prompt and waiting / keep-for-them view; combat log lines; draft cards list abilities.
- Manual: extend the README two-browser walkthrough with an ability-heavy duel.
