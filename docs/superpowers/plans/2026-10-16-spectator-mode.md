# Spectator Mode and Match Menu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anyone with a game ID can watch a match read-only. The draft and battle screens get a top-right ☰ menu with Copy Link, Copy Game ID and Leave Match, which replaces the inline Leave button.

**Architecture:**
- **Server:** two new public PHP routes, `/api/v1/watch/{gameId}` and `/watch/{gameId}/events`, built on the existing spectator projection builder.
- **Client:**
  - `GameSessionCoordinator` gets an in-memory watch mode. Reconnect and events go to the watch routes, and nothing is persisted.
  - `GameExperience` reuses its polling and screens unchanged, and adds `?watch=`, a Watch button and a "watch instead" offer.
  - A new `MatchMenu` component sits fixed in the top-right corner of `DraftTable` and `GameTable`.

**Tech Stack:** PHP 8 API with its own test runner (`php api/tests/run.php`); Blazor (.NET 10) shared UI library; xUnit + bUnit v2 tests.

**Spec:** `docs/superpowers/specs/2026-10-16-spectator-mode-design.md`

## Global Constraints

- Watching needs **only the game ID**. There is no auth and no seat token, and a seat token is never returned.
- Spectator responses must have `legalActions: []`, `pendingChoice: null` and `viewer = {role: spectator, seatId: null, isHost: false, canCommand: false}`.
- An unknown game ID returns HTTP **404** with code **`gameNotFound`**.
- The token spectator-link endpoints stay untouched.
- The menu appears on **draft and match screens only**. The lobby keeps `LeaveMatchControl` as its Cancel button.
- **Menu labels:**
  - **Copy Link**, **Copy Game ID**, **Leave Match**.
  - Spectators see **Stop watching** instead of Leave Match.
  - A finished match shows **Leave**.
  - Confirm-step labels: **Confirm leave** / **Stay**.
- **Copy Link** always copies the watch URL `{base}/?watch={id}`, for players and spectators alike.
- **Leave confirmation:** two presses for a player in a live match; one press for a spectator or a finished match.
- **Watch-instead text:** a failed join with `gameFull` or `gameAlreadyStarted` shows "That match is full or already under way." and a **Watch this match instead** button.
- **Waiting panel text:** spectators of a lobby see "Waiting for the commanders to muster…".
- Watching never writes to or clears the stored seat session.
- **Menu button attributes:** menu buttons use `data-menu="…"` and **never** `data-action`. Existing tests treat `[data-action]` as "command controls", and spectators must have none.
- **Deviation from spec:** the spec lists `IsWatching` on the coordinator. It is not added, because `GameExperience` reads `Viewer.Role == "spectator"` from the projection, which is the same fact without a second source of truth.

## Review Focus

1. **Spectator poll cadence.** A spectator has `Viewer.SeatId == null` and the lobby or draft has `ActiveSeatId == null`. Today's `null == null` check would poll every 750 ms. Spectators should poll at the 2 s cadence. This is pinned in Task 5.
2. **Watching does not touch the saved seat.** A player who watches another match and then stops watching should still reconnect to their own seat next time. This is pinned in Task 2 (the coordinator keeps the store) and Task 5 (stop watching clears nothing).
3. **Game ID whitespace.** A pasted ID with surrounding spaces (`" game-1 "`) should still watch. This is pinned in Task 2 (the coordinator trims).
4. **Blocked clipboard.** With the clipboard blocked (non-HTTPS, or permission denied), Copy should show the text for manual copy rather than fail silently. This is pinned in Task 3.
5. **Stop watching from the URL.** After stopping watching a `?watch=` page, a reload should not resume watching. This is pinned in Task 5 (the `OnStoppedWatching` callback fires) and the Home wiring that replaces the URL.

## File Structure

| File | Responsibility |
|---|---|
| `api/src/Game/GameService.php` (modify) | `getWatchedGame`, `getWatchedEvents`; shared `spectatorProjection` and `eventPage` helpers |
| `api/src/Http/Application.php` (modify) | Public `GET /api/v1/watch/{id}` and `/events` routes |
| `api/tests/ApiTest.php` (modify) | HTTP tests for the watch routes |
| `api/tests/VanillaMatchServiceTest.php` (modify) | Service test: watched events equal player events in a live match |
| `src/BloodAndBanners.UI/Services/InviteLinks.cs` (modify) | `CreateWatch`, `WatchGameIdFromUri` |
| `src/BloodAndBanners.UI/Services/IGameApiClient.cs`, `GameApiClient.cs` (modify) | `WatchGameAsync`, `GetWatchedEventsAsync` |
| `src/BloodAndBanners.UI/Services/IGameSessionCoordinator.cs`, `GameSessionCoordinator.cs` (modify) | `WatchAsync` and watch-mode routing |
| `src/BloodAndBanners.UI/Components/MatchMenu.razor` + `.css` (create) | ☰ menu: copy link, copy ID, leave with confirm |
| `src/BloodAndBanners.UI/Components/DraftTable.razor` + `.css`, `GameTable.razor` + `.css` (modify) | Host the menu; drop the inline leave control |
| `src/BloodAndBanners.UI/Components/SpectatorWaiting.razor` + `.css` (create) | Read-only lobby panel for spectators |
| `src/BloodAndBanners.UI/Components/QuickStart.razor` + `.css` (modify) | Watch button; watch-instead offer |
| `src/BloodAndBanners.UI/Components/GameExperience.razor` (modify) | Watch entry points, spectator lobby, poll cadence fix, stop-watching callback |
| `src/BloodAndBanners.Web/Pages/Home.razor` (modify) | Pass `?watch=`; clear the query when watching stops |
| `tests/BloodAndBanners.UI.Tests/*` (modify/create) | Tests per task |

Test commands:
- PHP: `php api/tests/run.php` (prints each test and fails loudly).
- UI: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~<Class>"`.

---

### Task 1: Server watch endpoints

**Files:**
- Modify: `api/src/Game/GameService.php` (`getEvents` ~line 481; `getSpectatorGame` ~line 572)
- Modify: `api/src/Http/Application.php` (public routes ~line 53)
- Test: `api/tests/ApiTest.php`, `api/tests/VanillaMatchServiceTest.php`

**Interfaces:**
- Produces HTTP:
  - `GET /api/v1/watch/{gameId}` returns 200 with a GameProjection JSON (spectator viewer), or 404 `gameNotFound`.
  - `GET /api/v1/watch/{gameId}/events?after=N` returns 200 `{events: [...], lastSequence: int}`, or 404.
- Produces PHP:
  - `GameService::getWatchedGame(string $gameId): array`
  - `GameService::getWatchedEvents(string $gameId, int $afterSequence): array`

- [ ] **Step 1: Write the failing API tests**

In `api/tests/ApiTest.php`, add these entries to the array returned by `api_tests()`, directly after the `'spectator endpoint is public and mutation endpoint is read only'` entry:

```php
        'watch endpoint shows any game read only by its id alone' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'watch-api-host']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'vanillaPvp',
                'scenarioName' => 'Proving Field',
            ]));
            $gameId = $created->body['game']['gameId'];

            $view = $app->handle(new Request('GET', "/api/v1/watch/{$gameId}"));
            $events = $app->handle(new Request('GET', "/api/v1/watch/{$gameId}/events", query: ['after' => '0']));

            expect_same(200, $view->status, 'watching needs no account or seat credential');
            expect_same('lobby', $view->body['lifecycle'], 'a lobby can be watched');
            expect_same(
                ['role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false],
                $view->body['viewer'],
                'watchers are read-only spectators'
            );
            expect_same([], $view->body['legalActions'], 'watchers receive no actions');
            expect_same(null, $view->body['pendingChoice'], 'watchers receive no actionable choice');
            expect_true(!str_contains(json_encode($view->body, JSON_THROW_ON_ERROR), $created->body['seatToken']), 'no seat token leaks to watchers');
            expect_same(200, $events->status, 'the event log can be watched too');
            expect_true(array_key_exists('lastSequence', $events->body), 'watched events use the player page shape');
        },

        'watching an unknown game is a not-found error' => static function (): void {
            $app = test_application();

            $view = $app->handle(new Request('GET', '/api/v1/watch/no-such-game'));
            $events = $app->handle(new Request('GET', '/api/v1/watch/no-such-game/events'));

            expect_same(404, $view->status, 'unknown games are not found');
            expect_same('gameNotFound', $view->body['error']['code'], 'the error code is stable');
            expect_same(404, $events->status, 'unknown game events are not found');
        },
```

In `api/tests/VanillaMatchServiceTest.php`, directly after the `'spectators still see the armies'` assertion (~line 176), add:

```php
            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([], $watched['legalActions'], 'watchers cannot act');
            expect_same('spectator', $watched['viewer']['role'], 'watchers are spectators');
            expect_same(
                $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0),
                $match['games']->getWatchedEvents($match['gameId'], 0),
                'watchers read the same event log as players'
            );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php api/tests/run.php`
Expected: FAIL. The watch route tests fail with `routeNotFound` / status 404 where 200 was expected, and the service test fails with "Call to undefined method …getWatchedGame".

- [ ] **Step 3: Implement the service methods**

In `api/src/Game/GameService.php`, replace the body of `getEvents` so that it delegates to a shared private helper, and add the helper right after it:

```php
    public function getEvents(string $gameId, string $identityId, string $seatToken, int $afterSequence): array
    {
        $this->requireSeatCredential($gameId, $identityId, $seatToken);
        return $this->eventPage($gameId, $afterSequence);
    }

    /** Anyone may read a game's event log by its id; it carries nothing seat-private. */
    public function getWatchedEvents(string $gameId, int $afterSequence): array
    {
        $this->gameRow($gameId);
        return $this->eventPage($gameId, $afterSequence);
    }

    private function eventPage(string $gameId, int $afterSequence): array
    {
        $statement = $this->connection->prepare(
            'SELECT sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at
             FROM game_events WHERE game_id = :game AND sequence > :after ORDER BY sequence LIMIT 200'
        );
        $statement->execute(['game' => $gameId, 'after' => $afterSequence]);
        $events = array_map(static fn(array $event): array => [
            'sequence' => (int)$event['sequence'],
            'version' => (int)$event['version'],
            'kind' => $event['kind'],
            'actingSeat' => $event['acting_identity_id'],
            'focusHexes' => json_decode($event['focus_hexes_json'], true, flags: JSON_THROW_ON_ERROR),
            'payload' => json_decode($event['payload_json'], true, flags: JSON_THROW_ON_ERROR),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime($event['created_at'])),
        ], $statement->fetchAll());
        $lastSequence = $afterSequence;
        if ($events !== []) {
            $lastSequence = $events[array_key_last($events)]['sequence'];
        }
        return ['events' => $events, 'lastSequence' => $lastSequence];
    }
```

Replace the tail of `getSpectatorGame` and add `getWatchedGame` plus the shared builder directly after it:

```php
    public function getSpectatorGame(string $token): array
    {
        $statement = $this->connection->prepare(
            'SELECT game_id FROM spectator_links
             WHERE token_hash = :token AND enabled = 1 AND revoked_at IS NULL'
        );
        $statement->execute(['token' => hash('sha256', $token)]);
        $gameId = $statement->fetchColumn();
        if ($gameId === false) {
            throw new GameServiceException('spectatorLinkInvalid', 'This spectator link is invalid or has been revoked.');
        }
        return $this->spectatorProjection($this->gameRow($gameId));
    }

    /** Anyone holding the game id may watch; spectators never receive actions or seat credentials. */
    public function getWatchedGame(string $gameId): array
    {
        return $this->spectatorProjection($this->gameRow($gameId));
    }

    private function spectatorProjection(array $game): array
    {
        return $this->buildProjection($game, $this->memberships($game['id']), [
            'role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false,
        ]);
    }
```

- [ ] **Step 4: Add the public routes**

In `api/src/Http/Application.php`, directly after the `#^/api/v1/spectate/([^/]+)$#` route block and before the scenarios route, add:

```php
            if (preg_match('#^/api/v1/watch/([^/]+)/events$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getWatchedEvents(
                    $matches[1],
                    max(0, (int)($request->query['after'] ?? 0))
                ));
            }
            if (preg_match('#^/api/v1/watch/([^/]+)$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getWatchedGame($matches[1]));
            }
```

(`gameNotFound` is already mapped to 404 in the `GameServiceException` catch.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php api/tests/run.php`
Expected: every test passes, including the existing `spectator links expose read-only public projections and can be revoked`.

- [ ] **Step 6: Commit**

```bash
git add api/src/Game/GameService.php api/src/Http/Application.php api/tests/ApiTest.php api/tests/VanillaMatchServiceTest.php
git commit -m "feat(api): let anyone watch a game by its id"
```

---

### Task 2: Client watch API, coordinator watch mode, watch links

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/InviteLinks.cs`
- Modify: `src/BloodAndBanners.UI/Services/IGameApiClient.cs`, `GameApiClient.cs`
- Modify: `src/BloodAndBanners.UI/Services/IGameSessionCoordinator.cs`, `GameSessionCoordinator.cs`
- Test: `tests/BloodAndBanners.UI.Tests/GameClientTests.cs`, `GameSessionCoordinatorTests.cs`
- Also modify: the `FakeSessionCoordinator` in `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`, so that it compiles.

**Interfaces:**
- Consumes: the Task 1 HTTP routes.
- Produces:
  - `InviteLinks.CreateWatch(string baseUri, string gameId): string` returns `"{base}/?watch={escaped}"`.
  - `InviteLinks.WatchGameIdFromUri(string uri): string?`
  - `IGameApiClient.WatchGameAsync(string gameId, CancellationToken ct = default): Task<GameProjection>`
  - `IGameApiClient.GetWatchedEventsAsync(string gameId, long afterSequence, CancellationToken ct = default): Task<EventPage>`
  - `IGameSessionCoordinator.WatchAsync(string gameId, CancellationToken ct = default): Task<GameProjection>`

- [ ] **Step 1: Write the failing link and client tests**

In `GameClientTests.cs`, add after `Invite_links_preserve_the_base_path_and_escape_the_game_id`:

```csharp
    [Theory]
    [InlineData("http://localhost:5016/?watch=game-123", "game-123")]
    [InlineData("https://game.test/play/?watch=%20game%201%20", "game 1")]
    [InlineData("https://game.test/?join=game-123", null)]
    public void Watch_links_extract_the_watched_game(string uri, string? expected)
    {
        Assert.Equal(expected, InviteLinks.WatchGameIdFromUri(uri));
    }

    [Fact]
    public void Watch_links_preserve_the_base_path_and_escape_the_game_id()
    {
        Assert.Equal(
            "https://game.test/play/?watch=game%201",
            InviteLinks.CreateWatch("https://game.test/play/", "game 1"));
    }

    [Fact]
    public async Task Watching_reads_the_public_watch_routes_without_credentials()
    {
        const string projection = """
            {
              "gameId":"game 1","version":4,"lifecycle":"draft","mode":"vanillaPvp",
              "viewer":{"role":"spectator","seatId":null,"canCommand":false,"isHost":false},
              "board":{"columns":0,"rows":0,"hexes":[]},
              "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
              "pendingChoice":null,"legalActions":[],"scores":{},"result":null
            }
            """;
        var handler = new QueueHandler(projection, """{"events":[],"lastSequence":7}""");
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var game = await client.WatchGameAsync("game 1");
        var page = await client.GetWatchedEventsAsync("game 1", 3);

        Assert.Equal("spectator", game.Viewer.Role);
        Assert.Equal(7, page.LastSequence);
        Assert.Equal("https://game.test/api/v1/watch/game%201", handler.Requests[0].RequestUri?.AbsoluteUri);
        Assert.Equal("https://game.test/api/v1/watch/game%201/events?after=3", handler.Requests[1].RequestUri?.AbsoluteUri);
        Assert.All(handler.Requests, request =>
        {
            Assert.Null(request.Headers.Authorization);
            Assert.False(request.Headers.Contains("X-Seat-Token"));
        });
    }
```

- [ ] **Step 2: Write the failing coordinator tests**

In `GameSessionCoordinatorTests.cs`, add before `private static GameProjection Projection(`:

```csharp
    [Fact]
    public async Task Watching_routes_reconnect_and_events_to_the_watch_api_and_never_touches_the_saved_seat()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var saved = new StoredGameSession("device-1", identity, "my-game", "seat-secret");
        var store = new MemoryGameSessionStore { Current = saved };
        var watched = Projection("other-game") with { Viewer = new ViewerPermissions("spectator", null, false, false) };
        var api = new FakeGameApiClient { Watched = watched, WatchedEvents = new EventPage([], 5) };
        var coordinator = new GameSessionCoordinator(api, store);

        var first = await coordinator.WatchAsync(" other-game ");
        var polled = await coordinator.ReconnectAsync();
        var page = await coordinator.GetEventsAsync(2);
        await coordinator.LeaveAsync();

        Assert.Same(watched, first);
        Assert.Same(watched, polled);
        Assert.Equal(["other-game", "other-game"], api.WatchRequests);
        Assert.Equal(("other-game", 2L), api.WatchedEventsRequest);
        Assert.Equal(5, page.LastSequence);
        Assert.Null(api.ReconnectRequest);
        Assert.Equal(saved, store.Current);
    }

    [Fact]
    public async Task Watching_refuses_commands_and_stopping_restores_the_saved_seat()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("device-1", identity, "my-game", "seat-secret") };
        var mine = Projection("my-game");
        var api = new FakeGameApiClient { Watched = Projection("other-game"), Reconnected = mine };
        var coordinator = new GameSessionCoordinator(api, store);

        await coordinator.WatchAsync("other-game");

        await Assert.ThrowsAsync<InvalidOperationException>(() => coordinator.SubmitCommandAsync(1, "endTurn", new { }));
        await Assert.ThrowsAsync<InvalidOperationException>(() => coordinator.StartGameAsync());
        Assert.Null(api.CommandRequest);

        await coordinator.LeaveAsync();
        Assert.Same(mine, await coordinator.ReconnectAsync());
    }

    [Fact]
    public async Task Watching_needs_a_game_code()
    {
        var coordinator = new GameSessionCoordinator(new FakeGameApiClient(), new MemoryGameSessionStore());

        await Assert.ThrowsAsync<ArgumentException>(() => coordinator.WatchAsync("  "));
    }
```

In the same file's `FakeGameApiClient`, add these members:

```csharp
        public GameProjection? Watched { get; init; }
        public EventPage? WatchedEvents { get; init; }
        public List<string> WatchRequests { get; } = [];
        public (string GameId, long After)? WatchedEventsRequest { get; private set; }

        public Task<GameProjection> WatchGameAsync(string gameId, CancellationToken cancellationToken = default)
        {
            WatchRequests.Add(gameId);
            return Task.FromResult(Watched ?? throw new InvalidOperationException("Watch response was not configured."));
        }

        public Task<EventPage> GetWatchedEventsAsync(string gameId, long afterSequence, CancellationToken cancellationToken = default)
        {
            WatchedEventsRequest = (gameId, afterSequence);
            return Task.FromResult(WatchedEvents ?? throw new InvalidOperationException("Watched events were not configured."));
        }
```

In `GameExperienceTests.cs`'s `FakeSessionCoordinator`, add (Task 5 extends this further):

```csharp
        public GameProjection? Watched { get; init; }
        public string? WatchRequest { get; private set; }

        public Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default)
        {
            WatchRequest = gameId;
            return Task.FromResult(Watched ?? throw new InvalidOperationException());
        }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~GameClientTests|FullyQualifiedName~GameSessionCoordinatorTests"`
Expected: FAIL to compile. `InviteLinks` has no `WatchGameIdFromUri`/`CreateWatch`, `GameApiClient` has no `WatchGameAsync`, and the coordinator has no `WatchAsync`.

- [ ] **Step 4: Implement the links**

Replace `src/BloodAndBanners.UI/Services/InviteLinks.cs` with:

```csharp
namespace BloodAndBanners.UI.Services;

public static class InviteLinks
{
    public static string Create(string baseUri, string gameId) =>
        $"{baseUri.TrimEnd('/')}/?join={Uri.EscapeDataString(gameId)}";

    public static string CreateWatch(string baseUri, string gameId) =>
        $"{baseUri.TrimEnd('/')}/?watch={Uri.EscapeDataString(gameId)}";

    public static string? GameIdFromUri(string uri) => QueryValue(uri, "join");

    public static string? WatchGameIdFromUri(string uri) => QueryValue(uri, "watch");

    private static string? QueryValue(string uri, string key)
    {
        var query = new Uri(uri, UriKind.Absolute).Query.TrimStart('?');
        foreach (var pair in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            if (Uri.UnescapeDataString(parts[0]).Equals(key, StringComparison.OrdinalIgnoreCase))
            {
                var value = parts.Length == 2
                    ? Uri.UnescapeDataString(parts[1].Replace('+', ' ')).Trim()
                    : string.Empty;
                return value.Length == 0 ? null : value;
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Implement the API client methods**

In `IGameApiClient.cs`, add before the closing brace:

```csharp
    Task<GameProjection> WatchGameAsync(string gameId, CancellationToken cancellationToken = default);

    Task<EventPage> GetWatchedEventsAsync(
        string gameId,
        long afterSequence,
        CancellationToken cancellationToken = default);
```

In `GameApiClient.cs`, add after `GetEventsAsync`:

```csharp
    public async Task<GameProjection> WatchGameAsync(string gameId, CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.GetAsync($"api/v1/watch/{Uri.EscapeDataString(gameId)}", cancellationToken);
        return await ReadAsync<GameProjection>(response, cancellationToken);
    }

    public async Task<EventPage> GetWatchedEventsAsync(
        string gameId,
        long afterSequence,
        CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.GetAsync(
            $"api/v1/watch/{Uri.EscapeDataString(gameId)}/events?after={afterSequence}",
            cancellationToken);
        return await ReadAsync<EventPage>(response, cancellationToken);
    }
```

- [ ] **Step 6: Implement coordinator watch mode**

In `IGameSessionCoordinator.cs`, add after `JoinGuestGameAsync`:

```csharp
    Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default);
```

In `GameSessionCoordinator.cs`, make these changes:

1. Add a field as the first line of the class body:

```csharp
    /// <summary>The game being watched; while set, reads use the public watch routes and the saved seat is left alone.</summary>
    private string? _watchedGameId;
```

2. Add the method after `JoinGuestGameAsync`:

```csharp
    public async Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default)
    {
        var normalizedGameId = gameId.Trim();
        if (normalizedGameId.Length == 0)
        {
            throw new ArgumentException("A game code is required.", nameof(gameId));
        }

        var projection = await api.WatchGameAsync(normalizedGameId, cancellationToken);
        _watchedGameId = normalizedGameId;
        return projection;
    }
```

3. In `CreateGuestGameAsync` and `JoinGuestGameAsync`, add `_watchedGameId = null;` as the first statement of each method body. In `JoinGuestGameAsync`, it goes before the `normalizedGameId` line.

4. Make `GetEventsAsync` start with:

```csharp
        if (_watchedGameId is { } watched)
        {
            return await api.GetWatchedEventsAsync(watched, afterSequence, cancellationToken);
        }
```

5. Replace `LeaveAsync` with:

```csharp
    public async Task LeaveAsync()
    {
        if (_watchedGameId is not null)
        {
            _watchedGameId = null;
            return;
        }
        await store.ClearAsync();
    }
```

6. Make `ReconnectAsync` start with:

```csharp
        if (_watchedGameId is { } watched)
        {
            return await api.WatchGameAsync(watched, cancellationToken);
        }
```

7. Replace `RequiredSessionAsync` with:

```csharp
    private async ValueTask<StoredGameSession> RequiredSessionAsync()
    {
        if (_watchedGameId is not null)
        {
            throw new InvalidOperationException("Spectators cannot give orders.");
        }
        return await store.LoadAsync()
            ?? throw new InvalidOperationException("No game session is saved on this device.");
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: PASS for the whole project, including the new tests and the existing coordinator and client tests.

- [ ] **Step 8: Commit**

```bash
git add src/BloodAndBanners.UI/Services tests/BloodAndBanners.UI.Tests/GameClientTests.cs tests/BloodAndBanners.UI.Tests/GameSessionCoordinatorTests.cs tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs
git commit -m "feat(ui): add a watch mode to the game session coordinator"
```

---

### Task 3: MatchMenu component

**Files:**
- Create: `src/BloodAndBanners.UI/Components/MatchMenu.razor`
- Create: `src/BloodAndBanners.UI/Components/MatchMenu.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/MatchMenuTests.cs` (create)

**Interfaces:**
- Consumes: `InviteLinks.CreateWatch` (Task 2).
- Produces: a `<MatchMenu GameId InviteBaseUri IsSpectator IsFinished OnLeave />` Razor component.
  - **DOM hooks for tests:** `[data-menu=toggle]`, `[data-menu=copyLink]`, `[data-menu=copyGameId]`, `[data-menu=leave]`, `[data-menu=confirmLeave]`, `[data-menu=stay]`, `[data-menu=backdrop]`, `[data-menu=notice]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/BloodAndBanners.UI.Tests/MatchMenuTests.cs`:

```csharp
using BloodAndBanners.UI.Components;
using Bunit;
using Microsoft.AspNetCore.Components.Web;
using Microsoft.JSInterop;

namespace BloodAndBanners.UI.Tests;

public sealed class MatchMenuTests : BunitContext
{
    private IRenderedComponent<MatchMenu> RenderMenu(bool spectator = false, bool finished = false, Action? onLeave = null) =>
        Render<MatchMenu>(parameters => parameters
            .Add(menu => menu.GameId, "game 7")
            .Add(menu => menu.InviteBaseUri, "https://blood.test/play/")
            .Add(menu => menu.IsSpectator, spectator)
            .Add(menu => menu.IsFinished, finished)
            .Add(menu => menu.OnLeave, () => onLeave?.Invoke()));

    [Fact]
    public void The_menu_is_closed_until_toggled_and_offers_the_three_items()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = RenderMenu();

        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));
        Assert.Equal("false", cut.Find("[data-menu=toggle]").GetAttribute("aria-expanded"));

        cut.Find("[data-menu=toggle]").Click();

        Assert.Equal("true", cut.Find("[data-menu=toggle]").GetAttribute("aria-expanded"));
        Assert.Equal("Copy Link", cut.Find("[data-menu=copyLink]").TextContent.Trim());
        Assert.Equal("Copy Game ID", cut.Find("[data-menu=copyGameId]").TextContent.Trim());
        Assert.Equal("Leave Match", cut.Find("[data-menu=leave]").TextContent.Trim());
        Assert.Empty(cut.FindAll("[data-action]"));
    }

    [Fact]
    public void Copy_link_copies_the_watch_url_and_copy_id_copies_the_bare_id()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyLink]").Click();
        Assert.Contains("Link copied", cut.Find("[data-menu=notice]").TextContent);
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyGameId]").Click();
        Assert.Contains("Game ID copied", cut.Find("[data-menu=notice]").TextContent);

        var copied = JSInterop.Invocations["navigator.clipboard.writeText"].Select(call => call.Arguments[0]).ToList();
        Assert.Equal(["https://blood.test/play/?watch=game%207", "game 7"], copied);
    }

    [Fact]
    public void A_blocked_clipboard_shows_the_text_to_copy_by_hand()
    {
        JSInterop.SetupVoid("navigator.clipboard.writeText", _ => true).SetException(new JSException("denied"));
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyGameId]").Click();

        var notice = cut.Find("[data-menu=notice]");
        Assert.Contains("Copy it manually", notice.TextContent);
        Assert.Equal("game 7", notice.QuerySelector("input")?.GetAttribute("value"));
    }

    [Fact]
    public void A_player_in_a_live_match_confirms_before_leaving_and_can_stay()
    {
        var left = false;
        var cut = RenderMenu(onLeave: () => left = true);

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        Assert.False(left);
        cut.Find("[data-menu=stay]").Click();
        Assert.Empty(cut.FindAll("[data-menu=confirmLeave]"));

        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();
        Assert.True(left);
        Assert.Empty(cut.FindAll("[data-menu=leave]"));
    }

    [Theory]
    [InlineData(true, false, "Stop watching")]
    [InlineData(false, true, "Leave")]
    public void Spectators_and_finished_matches_leave_in_one_press(bool spectator, bool finished, string label)
    {
        var left = false;
        var cut = RenderMenu(spectator, finished, () => left = true);

        cut.Find("[data-menu=toggle]").Click();
        Assert.Equal(label, cut.Find("[data-menu=leave]").TextContent.Trim());
        cut.Find("[data-menu=leave]").Click();

        Assert.True(left);
    }

    [Fact]
    public void Escape_and_clicking_outside_close_the_menu()
    {
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find(".match-menu").KeyDown(new KeyboardEventArgs { Key = "Escape" });
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=backdrop]").Click();
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~MatchMenuTests"`
Expected: FAIL to compile, because `MatchMenu` does not exist.

- [ ] **Step 3: Create the component**

Create `src/BloodAndBanners.UI/Components/MatchMenu.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.UI.Services
@using Microsoft.JSInterop
@implements IDisposable
@inject IJSRuntime JS

<div class="match-menu" @onkeydown="OnKey">
    <button type="button"
            class="match-menu-toggle"
            data-menu="toggle"
            aria-label="Match menu"
            aria-haspopup="menu"
            aria-expanded="@(_open ? "true" : "false")"
            @onclick="Toggle">☰</button>
    @if (_open)
    {
        <div class="match-menu-backdrop" data-menu="backdrop" @onclick="Close"></div>
        <div class="match-menu-panel" role="menu">
            <button type="button" role="menuitem" data-menu="copyLink" @onclick="CopyLink">Copy Link</button>
            <button type="button" role="menuitem" data-menu="copyGameId" @onclick="CopyGameId">Copy Game ID</button>
            @if (_confirming)
            {
                <button type="button" role="menuitem" class="leave-confirm" data-menu="confirmLeave" @onclick="Leave">Confirm leave</button>
                <button type="button" role="menuitem" data-menu="stay" @onclick="() => _confirming = false">Stay</button>
            }
            else
            {
                <button type="button" role="menuitem" data-menu="leave" @onclick="RequestLeave">@LeaveLabel</button>
            }
        </div>
    }
    else if (_notice is not null)
    {
        <div class="match-menu-notice" role="status" data-menu="notice">
            <span>@_notice</span>
            @if (_manualCopy is not null)
            {
                <input readonly value="@_manualCopy" aria-label="Text to copy" />
            }
        </div>
    }
</div>

@code {
    [Parameter, EditorRequired]
    public string GameId { get; set; } = string.Empty;

    [Parameter]
    public string InviteBaseUri { get; set; } = string.Empty;

    [Parameter]
    public bool IsSpectator { get; set; }

    [Parameter]
    public bool IsFinished { get; set; }

    [Parameter]
    public EventCallback OnLeave { get; set; }

    private bool _open;
    private bool _confirming;
    private string? _notice;
    private string? _manualCopy;
    private CancellationTokenSource? _noticeTimer;

    private string LeaveLabel => IsSpectator ? "Stop watching" : IsFinished ? "Leave" : "Leave Match";

    private bool NeedsConfirmation => !IsSpectator && !IsFinished;

    private void Toggle()
    {
        _open = !_open;
        _confirming = false;
        ClearNotice();
    }

    private void Close()
    {
        _open = false;
        _confirming = false;
    }

    private void OnKey(KeyboardEventArgs args)
    {
        if (args.Key == "Escape")
        {
            Close();
        }
    }

    private Task CopyLink() => Copy(InviteLinks.CreateWatch(InviteBaseUri, GameId), "Link copied");

    private Task CopyGameId() => Copy(GameId, "Game ID copied");

    private async Task Copy(string text, string confirmation)
    {
        Close();
        try
        {
            await JS.InvokeVoidAsync("navigator.clipboard.writeText", text);
            ShowNotice(confirmation, manualCopy: null);
        }
        catch (JSException)
        {
            ShowNotice("Copy it manually:", manualCopy: text);
        }
    }

    private void ShowNotice(string text, string? manualCopy)
    {
        ClearNotice();
        _notice = text;
        _manualCopy = manualCopy;
        if (manualCopy is null)
        {
            var timer = _noticeTimer = new CancellationTokenSource();
            _ = HideNoticeLater(timer.Token);
        }
    }

    private async Task HideNoticeLater(CancellationToken cancellationToken)
    {
        try
        {
            await Task.Delay(TimeSpan.FromSeconds(2.5), cancellationToken);
        }
        catch (TaskCanceledException)
        {
            return;
        }
        await InvokeAsync(() =>
        {
            _notice = null;
            StateHasChanged();
        });
    }

    private void ClearNotice()
    {
        _noticeTimer?.Cancel();
        _noticeTimer?.Dispose();
        _noticeTimer = null;
        _notice = null;
        _manualCopy = null;
    }

    private async Task RequestLeave()
    {
        if (NeedsConfirmation)
        {
            _confirming = true;
            return;
        }
        await Leave();
    }

    private async Task Leave()
    {
        Close();
        await OnLeave.InvokeAsync();
    }

    public void Dispose() => ClearNotice();
}
```

If `KeyboardEventArgs` does not resolve, add `@using Microsoft.AspNetCore.Components.Web` under the other `@using` lines. Check `src/BloodAndBanners.UI/_Imports.razor` first, because it may already import it.

Create `src/BloodAndBanners.UI/Components/MatchMenu.razor.css`:

```css
.match-menu { position: fixed; z-index: 40; top: .4rem; right: .6rem; font-family: "Segoe UI", system-ui, sans-serif; }
.match-menu-toggle { width: 2.5rem; height: 2.5rem; padding: 0; color: #f0ead9; background: #24241f; border: 1px solid #b08b42; font-size: 1.25rem; line-height: 1; cursor: pointer; }
.match-menu-toggle:hover,
.match-menu-toggle[aria-expanded="true"] { background: #3a3a33; }
.match-menu-backdrop { position: fixed; z-index: -1; inset: 0; }
.match-menu-panel,
.match-menu-notice { position: absolute; top: calc(100% + .35rem); right: 0; display: grid; min-width: 12rem; max-width: min(22rem, calc(100vw - 1.2rem)); background: #1d1e1b; border: 1px solid #b08b42; box-shadow: 0 .5rem 1.2rem rgba(0, 0, 0, .45); }
.match-menu-panel { padding: .3rem; }
.match-menu-panel button { min-height: 2.5rem; padding: .45rem .8rem; color: #e4dcc7; background: transparent; border: 0; text-align: left; font: inherit; cursor: pointer; }
.match-menu-panel button:hover { background: #3a3a33; }
.match-menu-panel .leave-confirm { color: #f8f0dc; background: #681e24; }
.match-menu-notice { gap: .4rem; padding: .55rem .75rem; color: #f0ead9; }
.match-menu-notice input { min-width: 0; padding: .4rem; color: #f2ead8; background: #191a17; border: 1px solid #5b5548; font: .85rem ui-monospace, monospace; }
button:focus-visible { outline: .22rem solid #fff3aa; outline-offset: .16rem; }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~MatchMenuTests"`
Expected: PASS (7 tests).

If `JSInterop.Invocations["…"]` is not indexable by identifier in the installed bUnit version, use `JSInterop.Invocations.Where(call => call.Identifier == "navigator.clipboard.writeText")`.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI/Components/MatchMenu.razor src/BloodAndBanners.UI/Components/MatchMenu.razor.css tests/BloodAndBanners.UI.Tests/MatchMenuTests.cs
git commit -m "feat(ui): add the top-right match menu component"
```

---

### Task 4: Put the menu on the draft and battle screens; remove the inline Leave

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/DraftTable.razor` (~lines 5 and 157-160 plus `@code` params), `DraftTable.razor.css` (line 46)
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor` (~line 8, ~lines 411-414 plus params ~line 503), `GameTable.razor.css` (line 19)
- Modify: `src/BloodAndBanners.UI/Components/GameExperience.razor` (pass `InviteBaseUri` through)
- Test: `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs` (~line 104), `GameTableTests.cs` (~line 284), `GameExperienceTests.cs` (~line 225)

**Interfaces:**
- Consumes: `MatchMenu` (Task 3).
- Produces: `DraftTable.InviteBaseUri` and `GameTable.InviteBaseUri` (`string`, default `string.Empty`) parameters.

- [ ] **Step 1: Rewrite the existing leave tests against the menu (failing)**

In `DraftTableTests.cs`, replace `Players_can_leave_the_draft_after_confirming` with:

```csharp
    [Fact]
    public void Players_leave_the_draft_from_the_match_menu_after_confirming()
    {
        var left = false;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        Assert.Empty(cut.FindAll("button[data-action=leaveMatch]"));
        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        Assert.False(left);
        cut.Find("[data-menu=confirmLeave]").Click();
        Assert.True(left);
    }

    [Fact]
    public void Spectators_get_the_match_menu_and_stop_watching_at_once()
    {
        var left = false;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") with
            {
                Viewer = new ViewerPermissions("spectator", null, false, false),
                LegalActions = []
            })
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();

        Assert.True(left);
    }
```

In `GameTableTests.cs`, replace `Leaving_a_match_takes_two_clicks_and_is_only_offered_to_players` with:

```csharp
    [Fact]
    public void Leaving_a_match_goes_through_the_menu_and_spectators_have_no_command_controls()
    {
        var left = false;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        Assert.Empty(cut.FindAll("button[data-action=leaveMatch]"));
        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        Assert.False(left);
        cut.Find("[data-menu=stay]").Click();
        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();
        Assert.True(left);

        var spectating = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection() with
            {
                Viewer = new ViewerPermissions("spectator", null, false, false),
                LegalActions = []
            }));
        Assert.Empty(spectating.FindAll("[data-action]"));
        spectating.Find("[data-menu=toggle]").Click();
        Assert.Equal("Stop watching", spectating.Find("[data-menu=leave]").TextContent.Trim());
    }
```

In `GameExperienceTests.cs`, in `A_poll_that_was_in_flight_cannot_resurrect_a_game_the_player_left`, replace the two lines

```csharp
        cut.Find("button[data-action=leaveMatch]").Click();
        cut.Find("button[data-action=confirmLeave]").Click();
```

with

```csharp
        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();
```

Leave `Cancelling_from_the_war_room_returns_to_the_starting_selection` unchanged, because the lobby still uses `LeaveMatchControl`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~DraftTableTests|FullyQualifiedName~GameTableTests|FullyQualifiedName~GameExperienceTests"`
Expected: FAIL. `[data-menu=toggle]` is not found, and the inline `leaveMatch` button still exists.

- [ ] **Step 3: Wire the menu into DraftTable**

In `DraftTable.razor`, insert as the first child of `<main class="draft-table" …>`, before `<header class="draft-ribbon">`:

```razor
    <MatchMenu GameId="@Projection.GameId"
               InviteBaseUri="@InviteBaseUri"
               IsSpectator="@(!Projection.Viewer.CanCommand)"
               OnLeave="OnReturnToWarRoom" />
```

Delete this block near the end of the markup:

```razor
    @if (Projection.Viewer.CanCommand)
    {
        <LeaveMatchControl OnLeave="OnReturnToWarRoom" />
    }
```

Add this parameter after `OnReturnToWarRoom` in `@code`:

```csharp
    [Parameter]
    public string InviteBaseUri { get; set; } = string.Empty;
```

In `DraftTable.razor.css` line 46, add `padding-right: 3.25rem;` to the `.draft-ribbon` rule, so that it reads:

```css
.draft-ribbon { display: grid; gap: .5rem; padding-right: 3.25rem; padding-bottom: 1rem; border-bottom: .25rem solid var(--brass); }
```

- [ ] **Step 4: Wire the menu into GameTable**

In `GameTable.razor`, insert as the first child of `<main class="game-table">`, before `<header class="turn-ribbon">`:

```razor
    <MatchMenu GameId="@Projection.GameId"
               InviteBaseUri="@InviteBaseUri"
               IsSpectator="@(!Projection.Viewer.CanCommand)"
               IsFinished="@(Projection.Result is not null)"
               OnLeave="OnReturnToWarRoom" />
```

Delete:

```razor
        @if (Projection.Viewer.CanCommand && Projection.Result is null)
        {
            <LeaveMatchControl OnLeave="OnReturnToWarRoom" />
        }
```

Add this parameter after `OnReturnToWarRoom` in `@code`:

```csharp
    [Parameter]
    public string InviteBaseUri { get; set; } = string.Empty;
```

`MatchMenu` is `position: fixed`, so it doesn't take a grid cell in `.game-table`. In `GameTable.razor.css`, change the `.turn-ribbon` padding from `padding: .35rem 1.15rem;` to:

```css
    padding: .35rem 3.75rem .35rem 1.15rem;
```

- [ ] **Step 5: Pass InviteBaseUri from GameExperience**

In `GameExperience.razor`, add `InviteBaseUri="@InviteBaseUri"` to both the `<DraftTable …>` and the `<GameTable …>` elements, for example right after `ImageBaseUrl="@ImageBaseUrl"`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: PASS for the whole project.

`DraftTableTests` line 36 selects `button[data-action]:not(.leave-match button)`. It still passes, because menu buttons use `data-menu`.

- [ ] **Step 7: Commit**

```bash
git add src/BloodAndBanners.UI/Components tests/BloodAndBanners.UI.Tests
git commit -m "feat(ui): move leaving into the match menu on draft and battle screens"
```

---

### Task 5: Watch entry points, spectator lobby and stop-watching

**Files:**
- Create: `src/BloodAndBanners.UI/Components/SpectatorWaiting.razor`, `SpectatorWaiting.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/QuickStart.razor` (join form ~line 31; error ~line 97; params), `QuickStart.razor.css` (line 56 and new rules)
- Modify: `src/BloodAndBanners.UI/Components/GameExperience.razor`
- Modify: `src/BloodAndBanners.Web/Pages/Home.razor`
- Test: `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`

**Interfaces:**
- Consumes:
  - `IGameSessionCoordinator.WatchAsync` (Task 2).
  - `GameApiException.Code` (existing; `src/BloodAndBanners.UI/Services/GameApiException.cs`).
  - `MatchMenu` via the tables (Task 4).
- Produces:
  - `GameExperience.InitialWatchCode` (`string?`) and `GameExperience.OnStoppedWatching` (`EventCallback`).
  - `QuickStart.OnWatch` (`EventCallback<string>`) and `QuickStart.WatchOfferGameId` (`string?`).
  - `SpectatorWaiting(Projection, OnLeave)`.

- [ ] **Step 1: Extend the fake coordinator**

In `GameExperienceTests.cs`'s `FakeSessionCoordinator`:

1. Add the property `public Exception? JoinFailure { get; init; }`.
2. Change `JoinGuestGameAsync` to:

```csharp
        public Task<GameProjection> JoinGuestGameAsync(string deviceId, string gameId, CancellationToken cancellationToken = default)
        {
            JoinRequest = (deviceId, gameId);
            if (JoinFailure is not null)
            {
                return Task.FromException<GameProjection>(JoinFailure);
            }
            return Task.FromResult(Joined ?? throw new InvalidOperationException());
        }
```

3. Make `ReconnectAsync` return `Watched` when `WatchRequest` is set. Replace its final `return Reconnected;` with:

```csharp
            return WatchRequest is not null && !Left ? Watched : Reconnected;
```

Also add `using System.Net;` at the top of the file if it is not there.

- [ ] **Step 2: Write the failing tests**

Add to `GameExperienceTests.cs`, before `private sealed class FakeSessionCoordinator`:

```csharp
    private static GameProjection Spectating(GameProjection projection) =>
        projection with { Viewer = new ViewerPermissions("spectator", null, false, false), LegalActions = [] };

    [Fact]
    public void A_watch_link_opens_the_match_read_only()
    {
        var api = new FakeSessionCoordinator
        {
            Watched = Spectating(DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialWatchCode, "game-fixture"));

        cut.WaitForAssertion(() => Assert.Contains("Muster your host", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
        cut.Find("[data-menu=toggle]").Click();
        Assert.Equal("Stop watching", cut.Find("[data-menu=leave]").TextContent.Trim());
    }

    [Fact]
    public void The_start_screen_can_watch_a_game_by_its_code()
    {
        var api = new FakeSessionCoordinator { Watched = Spectating(LobbyProjection("seat-red", isHost: true)) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("[data-join-code]").Input(" game-fixture ");
        cut.Find("button[data-action=watch-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("Waiting for the commanders to muster", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
        Assert.Empty(cut.FindAll("[data-testid=game-code]"));
    }

    [Theory]
    [InlineData("gameFull")]
    [InlineData("gameAlreadyStarted")]
    public void A_join_refused_because_the_match_is_full_or_started_offers_to_watch(string code)
    {
        var api = new FakeSessionCoordinator
        {
            JoinFailure = new GameApiException(HttpStatusCode.UnprocessableEntity, code, "No room."),
            Watched = Spectating(DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialJoinCode, "game-fixture"));
        cut.WaitForElement("button[data-action=join-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("That match is full or already under way.", cut.Markup));
        cut.Find("button[data-action=watch-instead]").Click();

        cut.WaitForAssertion(() => Assert.Contains("Muster your host", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
    }

    [Fact]
    public void Other_join_failures_do_not_offer_to_watch()
    {
        var api = new FakeSessionCoordinator
        {
            JoinFailure = new GameApiException(HttpStatusCode.NotFound, "gameNotFound", "Missing.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialJoinCode, "game-fixture"));
        cut.WaitForElement("button[data-action=join-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("could not be joined", cut.Markup));
        Assert.Empty(cut.FindAll("button[data-action=watch-instead]"));
    }

    [Fact]
    public void Stopping_watching_returns_to_the_start_and_tells_the_host_page()
    {
        var stopped = false;
        var api = new FakeSessionCoordinator { Watched = Spectating(LobbyProjection("seat-red", isHost: true)) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialWatchCode, "game-fixture")
            .Add(component => component.OnStoppedWatching, () => stopped = true));
        cut.WaitForElement("[data-testid=spectator-seats]");
        cut.Find("[data-menu=leave]").Click();

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-mode=vanillaPvp]")));
        Assert.True(api.Left);
        Assert.True(stopped);
    }

    [Fact]
    public void A_player_leaving_their_own_match_does_not_report_stopped_watching()
    {
        var stopped = false;
        var api = new FakeSessionCoordinator { Reconnected = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.OnStoppedWatching, () => stopped = true));
        cut.WaitForElement("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();

        cut.WaitForAssertion(() => Assert.True(api.Left));
        Assert.False(stopped);
    }
```

Add this to `GameClientTests.cs` to pin the poll cadence for spectators (Review Focus #1). It tests a new pure helper that `GameExperience` will call:

```csharp
    [Fact]
    public void Spectators_poll_at_the_relaxed_cadence_even_when_no_seat_is_active()
    {
        var viewer = new ViewerPermissions("spectator", null, false, false);

        Assert.False(PollingCadence.IsViewersMoment(viewer, activeSeatId: null, pickingSeatId: null));
        Assert.False(PollingCadence.IsViewersMoment(viewer, activeSeatId: "seat-red", pickingSeatId: null));
        Assert.True(PollingCadence.IsViewersMoment(new ViewerPermissions("player", "seat-red", true, false), "seat-red", null));
        Assert.True(PollingCadence.IsViewersMoment(new ViewerPermissions("player", "seat-red", true, false), null, "seat-red"));
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj --filter "FullyQualifiedName~GameExperienceTests|FullyQualifiedName~GameClientTests"`
Expected: FAIL to compile. `InitialWatchCode`, `OnStoppedWatching` and `PollingCadence.IsViewersMoment` do not exist.

- [ ] **Step 4: Add the cadence helper**

In `src/BloodAndBanners.UI/Services/PollingCadence.cs`, add `using BloodAndBanners.Contracts;` at the top and this method inside the class:

```csharp
    /// <summary>True when the viewer holds a seat and it is that seat's turn or pick; spectators never do.</summary>
    public static bool IsViewersMoment(ViewerPermissions viewer, string? activeSeatId, string? pickingSeatId) =>
        viewer.SeatId is { } seat && (activeSeatId == seat || pickingSeatId == seat);
```

- [ ] **Step 5: Create SpectatorWaiting**

Create `src/BloodAndBanners.UI/Components/SpectatorWaiting.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts
@using BloodAndBanners.UI.Services

<main class="spectator-waiting" aria-labelledby="spectator-waiting-title">
    <p class="eyebrow">Spectating · @ModeLabel · @Projection.ScenarioName</p>
    <h1 id="spectator-waiting-title">Waiting for the commanders to muster…</h1>
    <ul class="seats" data-testid="spectator-seats">
        @foreach (var seat in Projection.Memberships)
        {
            <li>
                <span>@SeatNames.Display(Projection, seat.SeatId)</span>
                <span class="@(seat.Ready ? "ready" : "unready")">@(seat.Ready ? "Ready" : "Not ready")</span>
            </li>
        }
    </ul>
    <button type="button" data-menu="leave" @onclick="() => OnLeave.InvokeAsync()">Stop watching</button>
</main>

@code {
    [Parameter, EditorRequired]
    public GameProjection Projection { get; set; } = default!;

    [Parameter]
    public EventCallback OnLeave { get; set; }

    private string ModeLabel => Projection.Mode switch
    {
        GameMode.Coop => "Void incursion",
        GameMode.BattleRoyale => "Battle royale",
        _ => "Vanilla duel",
    };
}
```

Create `src/BloodAndBanners.UI/Components/SpectatorWaiting.razor.css`:

```css
.spectator-waiting { min-height: 100dvh; padding: clamp(1rem, 6vw, 4rem); color: #e4dcc7; background: linear-gradient(110deg, rgba(21, 22, 19, .98), rgba(38, 37, 32, .94)), repeating-linear-gradient(135deg, #24251f 0 3px, #1d1e1b 3px 11px); font-family: "Segoe UI", system-ui, sans-serif; }
.eyebrow { margin: 0 0 .5rem; color: #d0ad66; letter-spacing: .04em; }
h1 { margin: 0 0 1.5rem; font-family: Georgia, "Times New Roman", serif; font-size: clamp(1.6rem, 5vw, 2.6rem); }
.seats { display: grid; gap: .4rem; max-width: 28rem; margin: 0 0 1.5rem; padding: 0; list-style: none; }
.seats li { display: flex; justify-content: space-between; gap: 1rem; padding: .6rem .85rem; background: rgba(0, 0, 0, .25); border: 1px solid #5a5548; }
.ready { color: #b7d69a; }
.unready { color: #a89f8a; }
button { min-height: 2.5rem; padding: .45rem .9rem; color: #e4dcc7; background: transparent; border: 1px solid #6a6252; font: inherit; cursor: pointer; }
button:hover { background: #3a3a33; }
button:focus-visible { outline: .22rem solid #fff3aa; outline-offset: .16rem; }
```

- [ ] **Step 6: Add the Watch button and the watch-instead offer to QuickStart**

In `QuickStart.razor`:

1. Inside the `.join-room` form's inner `<div>`, after the `join-game` submit button, add:

```razor
                    <button type="button"
                            class="watch"
                            data-action="watch-game"
                            disabled="@(IsBusy || string.IsNullOrWhiteSpace(_joinCode))"
                            @onclick="Watch">
                        Watch
                    </button>
```

2. Replace

```razor
    else if (!string.IsNullOrWhiteSpace(ErrorMessage))
    {
        <p class="connection-error" role="alert">@ErrorMessage</p>
    }
```

with

```razor
    else if (!string.IsNullOrWhiteSpace(ErrorMessage))
    {
        <div class="connection-error" role="alert">
            <p>@ErrorMessage</p>
            @if (!string.IsNullOrWhiteSpace(WatchOfferGameId))
            {
                <button type="button" class="watch-instead" data-action="watch-instead" @onclick="() => OnWatch.InvokeAsync(WatchOfferGameId)">
                    Watch this match instead
                </button>
            }
        </div>
    }
```

3. Add these parameters after `OnJoin`:

```csharp
    [Parameter]
    public EventCallback<string> OnWatch { get; set; }

    /// <summary>A game the player tried to join but cannot; when set, the error offers to watch it.</summary>
    [Parameter]
    public string? WatchOfferGameId { get; set; }
```

4. Add this method next to `Join()`:

```csharp
    private Task Watch() => string.IsNullOrWhiteSpace(_joinCode)
        ? Task.CompletedTask
        : OnWatch.InvokeAsync(_joinCode.Trim());
```

Before changing the markup, run `grep -rn "connection-error" tests/` and update any test that selects `p.connection-error` so that it selects `.connection-error` instead.

In `QuickStart.razor.css`:
- Change line 56 to `.join-room div { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; }`.
- Append these rules:

```css
.join-room button.watch { background: transparent; border-left: 0; }
.connection-error p { margin: 0; }
.watch-instead { margin-top: .65rem; padding: .55rem .9rem; color: #f6edda; background: transparent; border: 1px solid #d0ad66; font: inherit; cursor: pointer; }
.watch-instead:hover { background: rgba(0, 0, 0, .2); }
```

The existing `@media` rule (line 69) already collapses `.join-room div` to one column on narrow screens.

- [ ] **Step 7: Wire watching into GameExperience**

In `GameExperience.razor`:

1. Change the `QuickStart` element to add:

```razor
                OnWatch="WatchGame"
                WatchOfferGameId="@_watchOffer"
```

2. Directly after the `QuickStart` branch's closing `}`, add a branch before the existing lobby branch:

```razor
else if (_game.Lifecycle == GameLifecycle.Lobby && IsWatching)
{
    <SpectatorWaiting Projection="_game" OnLeave="ReturnToWarRoom" />
}
```

3. Add these parameters after `ImageBaseUrl`:

```csharp
    /// <summary>A game id from a watch link; when set, the page watches it instead of restoring a saved seat.</summary>
    [Parameter]
    public string? InitialWatchCode { get; set; }

    /// <summary>Raised after a spectator stops watching, so the host page can drop the watch link from its address.</summary>
    [Parameter]
    public EventCallback OnStoppedWatching { get; set; }
```

4. Add these fields and members after `_error`:

```csharp
    private string? _watchOffer;
    private const string WatchFailed = "That match could not be found. Check the game code and try again.";

    private bool IsWatching => _game?.Viewer.Role == "spectator";
```

5. Replace the body of `OnInitializedAsync` with:

```csharp
        _isBusy = true;
        var watching = !string.IsNullOrWhiteSpace(InitialWatchCode);
        try
        {
            _game = watching
                ? await Sessions.WatchAsync(InitialWatchCode!, _pollCancellation.Token)
                : await Sessions.ReconnectAsync(_pollCancellation.Token);
            StartPolling();
        }
        catch (OperationCanceledException) when (_pollCancellation.IsCancellationRequested)
        {
        }
        catch (Exception)
        {
            _error = watching
                ? WatchFailed
                : "Your saved war room could not be restored. Check the server and try again.";
        }
        finally
        {
            _isBusy = false;
        }
```

6. Replace `JoinGame` with:

```csharp
    private Task JoinGame(string gameId) => RunCommand(async () =>
    {
        _generation++;
        _watchOffer = null;
        try
        {
            ApplyProjection(await Sessions.JoinGuestGameAsync(
                DeviceId(),
                gameId,
                _pollCancellation.Token));
        }
        catch (GameApiException error) when (error.Code is "gameFull" or "gameAlreadyStarted")
        {
            _watchOffer = gameId.Trim();
            _error = "That match is full or already under way.";
            return;
        }
        _status = "You joined the war room.";
        StartPolling();
    }, "That war room could not be joined. Check the game code and try again.");

    private Task WatchGame(string gameId) => RunCommand(async () =>
    {
        _generation++;
        _watchOffer = null;
        ApplyProjection(await Sessions.WatchAsync(gameId, _pollCancellation.Token));
        _status = null;
        StartPolling();
    }, WatchFailed);
```

7. In `CreateGame`'s lambda, add `_watchOffer = null;` after `_generation++;`.

8. Replace `ReturnToWarRoom` with:

```csharp
    private async Task ReturnToWarRoom()
    {
        var wasWatching = IsWatching;
        _generation++;
        await Sessions.LeaveAsync();
        _generation++;
        _game = null;
        _events.Clear();
        _lastSequence = 0;
        _liveAfterSequence = null;
        _error = null;
        _status = null;
        _watchOffer = null;
        if (wasWatching)
        {
            await OnStoppedWatching.InvokeAsync();
        }
    }
```

9. In `PollAsync`, replace the `PollingCadence.Select(...)` call's first two arguments so that it reads:

```csharp
            var interval = PollingCadence.Select(
                PollingCadence.IsViewersMoment(current.Viewer, current.Turn.ActiveSeatId, null),
                current.PendingChoiceSummary is not null
                    || PollingCadence.IsViewersMoment(current.Viewer, null, current.Draft?.PickingSeatId),
                backgrounded: false,
                failures);
```

- [ ] **Step 8: Wire the web host page**

Replace the `GameExperience` element in `src/BloodAndBanners.Web/Pages/Home.razor` and add a code block:

```razor
<GameExperience ClientKind="web"
                InitialJoinCode="@InviteLinks.GameIdFromUri(Navigation.Uri)"
                InitialWatchCode="@InviteLinks.WatchGameIdFromUri(Navigation.Uri)"
                InviteBaseUri="@Navigation.BaseUri"
                ImageBaseUrl="@Configuration["ImageBaseUrl"]"
                OnStoppedWatching="ClearWatchLink" />

@code {
    // Drop ?watch= from the address so a reload does not start watching again.
    private void ClearWatchLink() => Navigation.NavigateTo(Navigation.BaseUri, replace: true);
}
```

Leave MAUI's `Home.razor` unchanged. It has no URL, and it gets the Watch button through `QuickStart`.

- [ ] **Step 9: Run all the tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: PASS for the whole project.

Then run: `dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj`
Expected: Build succeeded, 0 errors.

- [ ] **Step 10: Commit**

```bash
git add src/BloodAndBanners.UI src/BloodAndBanners.Web/Pages/Home.razor tests/BloodAndBanners.UI.Tests
git commit -m "feat(ui): watch matches by link, code or after a refused join"
```

---

### Task 6: End-to-end check in the running app

**Files:** none (verification only)

- [ ] **Step 1: Run the full suites**

Run: `php api/tests/run.php`
Run: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
Run: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
Expected: all pass.

- [ ] **Step 2: Drive the app locally**

Use the `run` skill, which reads the README's local-run section; see also memory `map-preview-slice` for the dev-server port gotchas. Start the PHP API and the web client, then check each of these:

1. In window A, open a Vanilla duel war room. In window B, join it with the invite link. Ready up and start, so both windows are in the draft.
2. In window A, open ☰ in the top-right corner. The menu shows Copy Link, Copy Game ID and Leave Match, and no inline Leave button remains on the page. Click Copy Link, and confirm the "Link copied" notice appears and fades.
3. In window C (a private window), paste the copied link (`…/?watch=<id>`). Confirm the draft renders read-only, the draft log fills, and ☰ shows **Stop watching**.
4. In window B, make a pick. Confirm window C updates within about 2 seconds.
5. In window C, click **Stop watching**. Confirm the start screen appears, the address bar has no `?watch=`, and a reload stays on the start screen.
6. In window C, paste the original `?join=` invite link and click Join. Confirm you see "That match is full or already under way." and the **Watch this match instead** button, and that clicking it opens the draft read-only.
7. In window A, open ☰, then Leave Match, then Confirm leave. Confirm it returns to the start screen.

- [ ] **Step 3: Record follow-ups**

Note anything off as follow-ups for the user, such as overlap with the turn ribbon at phone width. Do not deploy or push. Both are the user's call.
