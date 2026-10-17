# Spectator Mode and Match Menu — Design

Date: 2026-10-16

## Goal

Let anyone watch a match they are not playing in, using only the game ID, and give the draft and battle screens a top-right menu with **Copy Link**, **Copy Game ID** and **Leave Match**, replacing the inline Leave button.

## Decisions

- **Access is by game ID.** Anyone who has the game ID can watch. No token and no account.
- **The menu appears on the draft and match screens only.** The war room lobby keeps its invite box and Cancel button.
- **A failed join offers watching.** If someone tries to join a game that is full or has already started, the error comes with a "Watch this match instead" button.
- **The existing token spectator links stay as they are.** These are `POST /games/{id}/spectator-links` and `GET /spectate/{token}`. Nothing in the client uses them, and they are not removed.

## Server

There are two new public routes. They need no bearer token and no seat token.

| Route | Returns |
|---|---|
| `GET /api/v1/watch/{gameId}` | The spectator projection. It is built by the same `buildProjection` path the token route uses, with viewer `{role: spectator, seatId: null, isHost: false, canCommand: false}`. |
| `GET /api/v1/watch/{gameId}/events?after=N` | The same event page shape players receive (`events`, `lastSequence`), limit 200. |

- `GameService` gains `getWatchedGame(gameId)` and `getWatchedEvents(gameId, afterSequence)`.
- The existing `getEvents` is refactored so that the seat-credential check is separate from the query, and both callers share the query.
- An unknown game ID throws `gameNotFound`, which the API returns as 404.
- Watching works in every lifecycle: lobby, draft, active and finished.
- **Privacy:** spectator projections already return `legalActions: []` and `pendingChoice: null`, keeping only `pendingChoiceSummary`. Events carry nothing seat-private. Seat tokens never appear in either response.

## Client

### API and session

- `IGameApiClient` gains:
  - `WatchGameAsync(gameId)`
  - `GetWatchedEventsAsync(gameId, afterSequence)`
- `IGameSessionCoordinator` gains:
  - `WatchAsync(gameId)`, which fetches the projection and puts the coordinator in watch mode for that game ID.
  - `IsWatching`.
- In watch mode:
  - `ReconnectAsync` and `GetEventsAsync` call the watch endpoints.
  - `SubmitCommandAsync`, `SetReadyAsync` and `StartGameAsync` throw `InvalidOperationException`.
  - Nothing is written to `IGameSessionStore`. A saved seat session is left untouched, so watching never costs a player their seat.
- `LeaveAsync` while watching only clears watch mode. It does not clear the stored seat session.
- Creating or joining a game clears watch mode.

### Entry points

- **URL:** `?watch=<gameId>` starts watching on load.
  - `InviteLinks` gains `CreateWatch(baseUri, gameId)` and `WatchGameIdFromUri(uri)`, alongside the existing `join` helpers.
  - `Home.razor` passes `InitialWatchCode` into `GameExperience`.
  - When `InitialWatchCode` is set, it takes priority over restoring a saved seat for that page load.
  - A reload keeps watching because the query is still in the URL.
- **Start screen:** `QuickStart` gets a **Watch** button next to "Join war room". It uses the same game-code field and calls a new `OnWatch` callback.
- **Failed join:**
  - When `JoinGuestGameAsync` fails with `gameFull` or `gameAlreadyStarted`, the error shows a "Watch this match instead" button (`data-action="watch-instead"`) that watches that game ID.
  - Any other join failure keeps today's message.
- **MAUI** has no URL query. It gets the start-screen Watch button and nothing else.

### Rendering while watching

- **Lobby:** a small read-only `SpectatorWaiting` panel shows the mode, the scenario and each seat's ready state, with the line "Waiting for the commanders to muster…" and a Stop watching button. Polling moves the view into the draft when it starts.
- **Draft and battle:** the existing `DraftTable` and `GameTable` render unchanged. They already hide controls when `Viewer.CanCommand` is false, and `GameTable` shows the "Battle camera" panel.
- **Events:** polling fetches events from the watch endpoint, so the combat log, attack popup and turn popup all work for spectators.
- **Polling cadence:** spectators never have an active local turn, so they poll at the normal 2-second cadence.
- **Leaving:** the spectator's Leave returns to the start screen. On web it also removes `?watch=` from the address bar with `NavigationManager.NavigateTo(base, replace: true)`, so a reload doesn't start watching again.

### Match menu

The new `MatchMenu` component (`src/BloodAndBanners.UI/Components/MatchMenu.razor` + `.css`):

- **Trigger and placement:** a ☰ button (`aria-label="Match menu"`, `aria-expanded`) is pinned to the top-right corner of `DraftTable` and `GameTable`. It is shown for players and spectators.
- **Items:**
  - **Copy Link** copies `InviteLinks.CreateWatch(InviteBaseUri, gameId)`, which is the watch link for both players and spectators.
  - **Copy Game ID** copies the bare game ID.
  - **Leave Match**, or **Stop watching** for spectators, uses the existing two-step confirm inside the open menu.
- **Leave rules:**
  - Once the match has a result, the item is labelled **Leave** and needs no confirmation.
  - Spectators never need confirmation.
- **Copy feedback:** after a successful copy, the menu briefly shows "Link copied" or "Game ID copied". If the clipboard throws, the menu shows the text with "Copy it manually" so it can be selected.
- **Closing:** the menu closes on Escape, on choosing an item (except the first Leave press), or on a click outside it.
- **Parameters:** `GameId`, `InviteBaseUri`, `IsSpectator`, `IsFinished`, `OnLeave`.
- **Clipboard:** `MatchMenu` injects `IJSRuntime` itself and uses the same `navigator.clipboard.writeText` call as `CopyInvite`.
- **Wiring:**
  - `DraftTable` and `GameTable` gain an `InviteBaseUri` parameter, passed from `GameExperience`.
  - `GameExperience` sends the leave callback to `ReturnToWarRoom`. While watching, that method also clears the URL query.
- **Removed:** the inline `<LeaveMatchControl>` comes out of `DraftTable` and `GameTable`.
  - `LeaveMatchControl` itself stays, because the lobby's Cancel button still uses it.
  - The menu's confirm behaviour reuses `LeaveMatchControl`'s pattern; it does not reuse the component.

## Testing

**PHP (`api/tests`):**
- `GET /watch/{id}` returns 200 without auth and has `viewer.role = spectator`, `canCommand = false`, `legalActions = []`, `pendingChoice = null` and no `seatToken`.
- An unknown ID returns 404 `gameNotFound`.
- `GET /watch/{id}/events?after=0` returns the same events a player sees.
- Watching a lobby game works.

**bUnit (`tests/BloodAndBanners.UI.Tests`):**
- `MatchMenu`:
  - The items render with a player's labels and with a spectator's labels.
  - Copy invokes the clipboard with the watch URL and with the game ID.
  - Leave needs two presses for a player in a live match and one press for a spectator or a finished match.
- `DraftTable` and `GameTable` no longer render the inline leave control, and they do render the menu.
- `QuickStart`: Watch calls `OnWatch` with the trimmed code.
- `GameExperience`: a join that fails with `gameFull` or `gameAlreadyStarted` shows the watch-instead button, and clicking it watches. `InitialWatchCode` starts watching.
- `GameSessionCoordinator`:
  - Watch mode routes reconnect and events to the watch API, never touches the store, and rejects commands.
  - Leave while watching keeps the stored seat session.
- `InviteLinks`: the `watch` helpers round-trip.

## Out of scope

- A spectator count or list visible to players.
- Hiding watching or making it private. Anyone with the ID can watch.
- Token link management UI, or removing the token endpoints.
- A menu in the war room lobby.
