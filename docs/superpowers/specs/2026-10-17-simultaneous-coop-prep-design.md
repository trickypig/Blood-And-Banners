# Simultaneous Co-op preparation — design

Date: 2026-10-17
Status: approved in brainstorming, awaiting spec review

## Goal

In Void Incursion (Co-op), nobody should sit idle before the battle:

- The Void Lord can start placing monsters while hero players are still joining the war room.
- After the host presses Start, the hero players draft at the same time as the Void Lord finishes placing.
- The battle begins automatically once both sides are done, in either order.

Today the flow runs one step at a time: lobby → `setup` (the Void Lord places, everyone else waits) → `draft` (heroes snake-draft, the Void Lord waits) → `active`.

## User decisions

- **Hidden until battle (option A).** Hero players don't see monsters during preparation, and the Void Lord doesn't see hero picks. Both are revealed when the battle starts.
- **Live budget.** The budget is 4 + 8 × (hero seats right now). It changes as players join or leave. Being over budget is allowed in the lobby.
- **Draft waits for the roster.** The hero draft starts only after Start. The snake order still comes from the initiative roll among the locked hero seats.
- **Lobby state approach.** The Co-op match state is created as soon as the lobby has a Void Lord. It does not use a separate lobby placement store.
- **Confirm only after Start.** The Void Lord cannot confirm in the lobby because the budget can still change. Confirming is final, as it is today.
- **Spectators see neither side's choices** until the battle starts, so watching by game ID cannot be used to cheat.

## Flow

### Lobby

- When a seat becomes Void Lord, the server creates a setup-only Co-op state through the existing `saveState` if none exists, using `claimVoidLord` or `assignVoidLord`. The state holds `mode: 'coop'`, `phase: 'setup'`, the scenario `board`, `voidLordSeatId`, `units: []`, and `setup` = `{budget, spent: 0, nextMonster: 1, confirmed: false}`, plus the empty fields the engine normalises.
- `placeMonster` and `removeMonster` are legal for the Void Lord in the lobby. They are routed to `CoopSetup` the same way as now, except for the over-budget rule below.
- **Budget:** `setup.budget` is recalculated from the current non-Void-Lord membership count:
  - whenever the state is projected or a setup command is applied in the lobby;
  - after any join, leave or remove, and after any Void Lord change.
- In the lobby, `placeMonster` still rejects a monster that would push `spent` over the current budget. If a leave shrinks the budget below `spent`, the placed monsters stay where they are.
- **Void Lord changes hands:** the existing monster units move to the new seat: `seatId` is rewritten and `voidLordSeatId` updated. Releasing the role with nobody taking it deletes the lobby state.
- **Room deleted:** when the last player leaves, the state row is removed along with the game.
- `confirmSetup` is rejected in the lobby with a new error, `rosterNotLocked`.

### Start

- The host's Start checks are unchanged: everyone ready and exactly one Void Lord.
- `CoopMode::start` gets a new option, `setupState`, which is the lobby state if one exists. It keeps that state's `units`, `setup.spent` and `setup.nextMonster`. It sets the final budget from the locked hero count, rolls initiative, and builds `sides` and `draft` as it does now.
- New phase name: `prep`. The `games.lifecycle` column is set to `draft` for new games, so the client keeps routing hero players to the draft. For the engine, `prep` means setup commands and draft commands are both legal.
- `draft.pickingSeatId` is set to the first hero in initiative order right away. Setup confirmation no longer gates the draft.
- If no lobby state exists (the Void Lord never opened the board), start creates the same state with no monsters.

### Preparation (lifecycle `draft`, engine phase `prep`)

- **Void Lord:** `placeMonster`, `removeMonster` and `confirmSetup` keep working until they confirm. Confirm needs at least 1 monster and `spent ≤ budget`; otherwise it fails with the existing `setupEmpty` or a new `budgetExceeded`. After confirming, the Void Lord has no setup actions.
- **Heroes:** the snake draft is unchanged (`pickHero`, `pickArmy`).
- **Finishing:** when the last draft pick lands and `setup.confirmed` is true, run the existing `deploy`, which places the heroes, sets `phase = 'active'` and starts turn 1. The same happens when `confirmSetup` lands after the draft is already complete.
- **Routing:** `CoopMode::applyPreBattle` / `legalPreBattle` in phase `prep` route by command kind. Setup kinds go to `CoopSetup` and draft kinds to `CoopDraft`. `CoopDraft` no longer throws `setupConfirmed` for setup kinds in `prep`. When the draft finishes but setup isn't confirmed, it leaves `pickingSeatId = null` and stays in `prep`. Both sides then use a shared "finish if both done" helper.

### Legacy games

A game already saved in engine phase `setup` (old flow) keeps today's behaviour: confirm → `draft` → `active`. The routing keys on `phase`, so `setup` and `draft` behave exactly as now, and only `prep` uses the new path.

## Hidden information

### Projection (`GameService::buildProjection`)

Hidden information applies while the lifecycle is `lobby`, or `draft` with engine phase `prep`. The viewer's side is: the Void Lord seat → `void`; any other player seat → `heroes`; spectators → neither.

- `units`: void-side units are visible only to the Void Lord. Hero units don't exist until deploy.
- `setup`: the full setup projection goes only to the Void Lord. Hero players and spectators get the same object reduced to `confirmed` (bool) and `redacted: true`, with an empty roster, `budget`/`spent` = 0 and no spawn hexes. The monster count is hidden too.
- `draft`: hero players get the full Co-op draft projection. The Void Lord and spectators get only progress: `picksMade`, `picksTotal`, `complete`. Hero names and army claims are blanked.
- `armies`: during `prep`, hero armies are hidden from the Void Lord and spectators.
- `board` occupants: void units are not shown to heroes or spectators.
- Once the engine phase is `active` (or later), everyone sees everything, as today.

### Event feed

- New migration `004_event_visibility` adds a nullable `visible_side` column to `game_events` (sqlite and mysql).
- `monsterPlaced` and `monsterRemoved` events appended during lobby or `prep` are tagged `void`. `draftPicked` events during `prep` are tagged `heroes`. The `setupConfirmed` event stays public; it reveals only that the Void Lord is done.
- `eventPage` takes the viewer's side. While the game is before battle (lifecycle `lobby` or `draft`), it leaves out events whose `visible_side` is set and isn't the viewer's side. Spectators see only untagged events. From `active` onward, every event is returned, so the draft log and placement history fill in at the reveal.
- No new battle-start event. The existing public `armiesPlaced` event marks the battle start. When a Co-op game goes from Draft to Active, the client clears its log and re-fetches the event history from sequence 0, which is how the hidden history fills in.

### Snapshots

`writeSnapshot` currently stores the acting player's projection, and nothing reads it. Before battle, write the spectator projection instead, so a future reader can't leak hidden data.

## Client

### War room (`WarRoomLobby`)

- **Void Lord only:** a "Place monsters" button opens the board in setup mode in `GameExperience`. This is a local view switch; the lifecycle is still `lobby`.
- The setup board reuses `GameTable` + `VoidLordSetup`:
  - a "Back to war room" link;
  - a slim war-room status line: hero count, ready count and "you confirm once the host begins". Start stays in the war room;
  - a live budget readout that can show over-budget in a warning colour;
  - no Confirm button in the lobby.
- **Hero players:** the war room is unchanged apart from a status line, "The Void Lord is preparing the horde", shown when a Void Lord exists.
- Lobby polling keeps working while the Void Lord is on the board, so roster changes and Start still arrive. When the lifecycle changes to `draft`, the Void Lord stays on (or switches to) the setup board.

### Draft lifecycle

- **Hero players:** `DraftTable` as today, plus a status line: "The Void Lord is placing monsters" or "The Void Lord is ready". When the draft is complete and the Void Lord hasn't confirmed, show "Waiting for the Void Lord".
- **Void Lord:** routed to `GameTable` in setup mode, not `DraftTable`. `VoidLordSetup` shows a Confirm button and "Heroes drafting · N/M picks". After confirming: "Waiting for the heroes to finish drafting".
- **Routing:** in `GameExperience`, when the lifecycle is `Draft` and the viewer is the Void Lord, render `GameTable`. `GameTable`'s `InSetup` becomes true for lifecycle `Setup`, or for `Draft` when the viewer is the Void Lord and `Setup` is not null.
- **Contracts:** `SetupProjection` gains `Redacted`, which the hero/spectator copy of `VoidLordSetup` uses to show the status line without a count. `DraftProjection` gains `PicksMade` / `PicksTotal` / `Complete`, and `Heroes`/claims may be empty for a redacted viewer.

### Battle start

Both sides switch to the normal `GameTable`. The existing turn popup announces the first hero's turn, and the log fills in from the re-fetch.

## Errors

- `rosterNotLocked`: "The Void Lord confirms once the host starts the match." This is `confirmSetup` in the lobby.
- `budgetExceeded` on confirm: reuses the existing message.
- Setup commands in the lobby from a non-Void-Lord seat: the existing `notVoidLord`.
- Setup commands in the lobby when the mode isn't Co-op: the existing `unsupportedCommand`.

## Testing

### PHP (`api/tests`)

- Lobby setup:
  - The state is created when a Void Lord is claimed or assigned.
  - Placement works and respects the live budget.
  - Joins grow the budget.
  - A leave shrinks it below `spent`; placements stay.
  - Reassigning the Void Lord moves the monsters; releasing the role clears them.
  - Confirming in the lobby gives `rosterNotLocked`.
  - The lobby state is deleted with the room.
- Start: lobby monsters carry over and the final budget is right. Start without a lobby state works. The draft is open right away.
- Prep: draft-first then confirm, and confirm-first then draft, both reach `active` with heroes deployed. An over-budget confirm is rejected. Commands after confirm are rejected.
- Hidden information: projections for a hero, the Void Lord and a spectator during lobby and `prep` hide the right fields. `eventPage` filtering works per side. Everything is visible once `active`.
- Legacy: a state saved in phase `setup` still goes setup → draft → active.
- The existing Co-op suites stay green after adjusting the expectations that assumed setup comes first.

### bUnit (`tests/BloodAndBanners.UI.Tests`)

- `WarRoomLobby`: the Place-monsters button shows only for the Void Lord, and heroes see the preparing status line.
- `GameExperience`: in the lobby, the Void Lord moves between the war room and the setup board. In the draft lifecycle, the Void Lord is routed to the setup board.
- `VoidLordSetup`: no Confirm in the lobby, Confirm during prep, the draft-progress line, the waiting state after confirm, and the over-budget warning.
- `DraftTable`: the Void Lord status line and the waiting-for-Void-Lord state.

### By hand

One local run on the API (port 8091, scratch SQLite database, signing key), with curl playing two hero seats:

1. The Void Lord places monsters while the heroes are still joining.
2. Start.
3. The heroes draft while the Void Lord edits.
4. Both orders of finishing.
5. Check that the heroes' view and the event log stay clean of monsters until the battle starts.

## Out of scope

- An AI Void Lord.
- Un-confirming setup.
- Changing the snake-draft rules or the budget formula.
- Vanilla PvP, which is unaffected.
