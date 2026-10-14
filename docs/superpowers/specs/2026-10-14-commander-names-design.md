# Commander names — design

Date: 2026-10-14
Status: approved in conversation; awaiting written-spec review

## Goal

Let each player name their commander from the War Room lobby and the draft screen, so the other players, spectators and the combat log see that name instead of generic labels like "Rival" or "Commander 2". This also covers the `docs/todo.md` item "Ability to name yourself before joining".

## Decisions

- **One name per seat.** No separate team or army name.
- **Per match, prefilled.** The name is stored on the seat for this match. The input is prefilled with the last name this browser saved, and nothing is sent until the player saves.
- **Edit window.** The name can be set in the `lobby`, `setup` and `draft` lifecycles. It locks once the game is `active`.

## Out of scope

- Profile-level or account-level names, including the existing `users.display_name`.
- Team or army names.
- Renaming during battle or after the match.
- Rewriting past log lines; there are none before the name locks.

## API

### Schema

Migration `003_commander_names` comes in two versions, `.mysql.sql` and `.sqlite.sql`. It adds `commander_name` to `game_memberships`, nullable and up to 24 characters (`VARCHAR(24)` in MySQL, `TEXT` in SQLite). When it is null, the UI falls back to the default label.

### Command `setCommanderName`

Payload: `{ "name": string }`.

The command is added to the commands `GameService` handles outside the rules engine (`LOBBY_COMMANDS` / `applyLobbyCommand`). That code checks the seat credential, idempotency through command receipts, and `expectedVersion` the same way as for the other commands.

Rules, applied in this order:

1. The payload `name` must be a string. Otherwise the error is `unsupportedCommand`.
2. The game lifecycle must be `lobby`, `setup` or `draft`. Otherwise the error is `illegalLifecycle` ("Commander names are locked once battle begins.").
3. Clean the name:
   - Remove control characters.
   - Collapse runs of whitespace into a single space.
   - Trim both ends.
4. If the cleaned name is empty, store `null`. This clears the name.
5. If the cleaned name is longer than 24 characters, the error is `nameTooLong`. Length is counted in characters (`mb_strlen`), not bytes.
6. If the name matches another seat's name in the same game, ignoring case (`mb_strtolower`), the error is `nameTaken`.
7. Update only the caller's own membership row (`id = membership.id`), then bump the game version.

No game event is written: a name change is not a combat-log entry.

### Legal actions

While the lifecycle is `lobby`, `setup` or `draft`, the viewer has a seat and the viewer can command, `legalActions` includes `{ kind: "setCommanderName" }`. In the lobby it is added in `lobbyActions`. In setup and draft it is added after the engine's legal actions.

Spectators never get the action.

### Projection

Each membership entry gets `name: string|null`. Spectator projections include it too.

## Contracts

`MembershipProjection` gets `public string? Name { get; init; }`. It is an init property like `Role`, so the existing constructor calls and fixtures keep working.

## UI

### Single naming function

Add `SeatNames.Display(GameProjection projection, string? seatId)` and use it in place of the four duplicated helpers:

- `SeatName` in `DraftTable`
- `SeatName` in `GameTable`
- the seat naming in `CombatLogFormatter`
- `SeatNames.Coop`, which `Display` replaces

Resolution order:

1. A null seat gives "A commander".
2. The viewer's own seat gives "You".
3. A seat with a name: a Void Lord seat gives "{name} (Void Lord)"; any other seat gives "{name}".
4. A seat without a name gives the existing fallback:
   - Co-op: "Void Lord" for the Void Lord seat, otherwise "Commander {SeatIndex}".
   - Vanilla, when the viewer is a player: "Rival".
   - Vanilla, when the viewer is a spectator: "Commander {SeatIndex}".

Text built around the seat name, such as "{name}'s army", "Void Lord's horde", "Rival army", "Defeat" and "{x} wins", keeps its current wording. It only takes the seat name from `Display`. Existing text that the current tests pin stays the same when no names are set.

### `CommanderNameField` component

A small form:

- A labelled text input ("Commander name", `maxlength=24`) and a Save button.
- Enter submits the form.

Behaviour:

- It renders only when `setCommanderName` is in `LegalActions` and `Viewer.CanCommand` is true.
- The initial value is the seat's current name if it has one. Otherwise it is the value stored under `bb.commanderName` in localStorage. Every localStorage read and write is wrapped in try/catch, through the existing JS interop pattern if there is one.
- Save sends `setCommanderName` through the parent's `OnCommand` callback. After the next projection shows the saved name, the field writes it to `bb.commanderName`.
- Save is disabled while the parent is busy or when the input matches the current name.
- Server errors (`nameTaken`, `nameTooLong`) show through the screen's existing `ErrorMessage` and need friendly text in the existing error-message mapping:
  - `nameTaken`: "Another commander already uses that name."
  - `nameTooLong`: "Names can be at most 24 characters."

It is placed in two screens:

- **DraftTable:** in the draft ribbon, next to the status.
- **WarRoomLobby:** next to the viewer's own seat row.

Named seats in the lobby list show their name.

## Testing

PHP (`api/tests`):

- Setting a name in the lobby. The name appears in the projection for players and spectators, and the version bumps.
- Setting a name during draft in both Vanilla and Co-op, and during Co-op setup.
- Cleaning: whitespace is trimmed and collapsed, and control characters are removed.
- Sending an empty name clears it to `null`.
- 25 characters is rejected with `nameTooLong`; 24 multibyte characters are accepted.
- A name differing only in case is rejected with `nameTaken`. Re-saving your own current name is accepted.
- In the `active` lifecycle the error is `illegalLifecycle`.
- The command changes only the caller's seat.
- `setCommanderName` appears in legal actions in the lobby, setup and draft, and not when the game is active or for spectators.
- A replayed `commandId` returns the cached response.

UI (bUnit, `tests/BloodAndBanners.UI.Tests`):

- `SeatNames.Display`: "You", a chosen name, a named Void Lord, and every fallback label.
- `DraftTable`: claim text and the initiative line use the chosen names, and the field renders when the action is legal.
- `GameTable`: the turn banner uses the chosen name.
- `CombatLogFormatter`: log lines use the chosen name.
- `CommanderNameField`: prefill order, the Save payload, and the disabled states.
- The existing tests still pass unchanged when no names are set.
