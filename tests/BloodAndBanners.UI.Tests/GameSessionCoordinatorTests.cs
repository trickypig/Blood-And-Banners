using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class GameSessionCoordinatorTests
{
    [Fact]
    public async Task Creating_a_game_persists_identity_game_and_seat_credential()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var projection = Projection("game-1");
        var api = new FakeGameApiClient
        {
            Guest = identity,
            Created = new GameSeatSession(projection, "seat-secret")
        };
        var store = new MemoryGameSessionStore();
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.CreateGuestGameAsync(
            "device-1",
            GameMode.VanillaPvp,
            "Training Grounds");

        Assert.Same(projection, result);
        Assert.Equal(new StoredGameSession("device-1", identity, "game-1", "seat-secret"), store.Current);
    }

    [Fact]
    public async Task Reconnect_restores_the_saved_identity_and_seat_credential()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var stored = new StoredGameSession("device-1", identity, "game-1", "seat-secret");
        var projection = Projection("game-1");
        var api = new FakeGameApiClient { Reconnected = projection };
        var store = new MemoryGameSessionStore { Current = stored };
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.ReconnectAsync();

        Assert.Same(projection, result);
        Assert.Equal((identity, "game-1", "seat-secret"), api.ReconnectRequest);
    }

    [Fact]
    public async Task Reconnect_without_a_saved_session_does_not_call_the_API()
    {
        var api = new FakeGameApiClient();
        var coordinator = new GameSessionCoordinator(api, new MemoryGameSessionStore());

        var result = await coordinator.ReconnectAsync();

        Assert.Null(result);
        Assert.Null(api.ReconnectRequest);
    }

    [Fact]
    public async Task Joining_a_game_creates_a_guest_and_persists_the_joined_seat()
    {
        var identity = new AuthSession("guest-2", "guest", "access-2", "refresh-2");
        var projection = Projection("game-1") with
        {
            Viewer = new ViewerPermissions("player", "seat-2", true, false)
        };
        var api = new FakeGameApiClient
        {
            Guest = identity,
            Joined = new GameSeatSession(projection, "seat-2-secret")
        };
        var store = new MemoryGameSessionStore();
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.JoinGuestGameAsync("device-2", " game-1 ");

        Assert.Same(projection, result);
        Assert.Equal((identity, "game-1"), api.JoinRequest);
        Assert.Equal(new StoredGameSession("device-2", identity, "game-1", "seat-2-secret"), store.Current);
    }

    [Fact]
    public async Task Ready_and_start_commands_use_the_persisted_seat_and_latest_version()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore
        {
            Current = new StoredGameSession("device-1", identity, "game-1", "seat-secret")
        };
        var readyProjection = Projection("game-1") with { Version = 4 };
        var draftProjection = readyProjection with { Version = 5, Lifecycle = GameLifecycle.Draft };
        var api = new FakeGameApiClient { Commanded = readyProjection, Started = draftProjection };
        var coordinator = new GameSessionCoordinator(api, store);

        var ready = await coordinator.SetReadyAsync(3, true);
        var started = await coordinator.StartGameAsync();

        Assert.Same(readyProjection, ready);
        Assert.Equal((identity, "game-1", "seat-secret"), api.CommandRequest?.Session);
        Assert.Equal(3, api.CommandRequest?.Command.ExpectedVersion);
        Assert.Equal("setReady", api.CommandRequest?.Command.Kind);
        Assert.True(api.CommandRequest?.Command.Payload.GetProperty("ready").GetBoolean());
        Assert.Same(draftProjection, started);
        Assert.Equal((identity, "game-1", "seat-secret"), api.StartRequest);
    }

    [Fact]
    public async Task Reconnect_refreshes_an_expired_identity_and_persists_the_rotated_tokens()
    {
        var expired = new AuthSession("guest-1", "guest", "access-expired", "refresh-1");
        var refreshed = new AuthSession("guest-1", "guest", "access-2", "refresh-2");
        var stored = new StoredGameSession("device-1", expired, "game-1", "seat-secret");
        var projection = Projection("game-1");
        var api = new FakeGameApiClient
        {
            Refreshed = refreshed,
            Reconnected = projection,
            ReconnectFailure = new HttpRequestException("expired", null, HttpStatusCode.Unauthorized)
        };
        var store = new MemoryGameSessionStore { Current = stored };
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.ReconnectAsync();

        Assert.Same(projection, result);
        Assert.Equal(("refresh-1", "device-1"), api.RefreshRequest);
        Assert.Equal(refreshed, store.Current?.Identity);
        Assert.Equal(2, api.ReconnectRequests.Count);
        Assert.Equal(refreshed, api.ReconnectRequests[1].Identity);
        Assert.Equal("seat-secret", api.ReconnectRequests[1].SeatToken);
    }

    [Fact]
    public async Task Json_store_round_trips_and_clears_the_complete_session()
    {
        var keyValues = new MemoryKeyValueStore();
        var store = new JsonGameSessionStore(keyValues);
        var expected = new StoredGameSession(
            "device-1",
            new AuthSession("guest-1", "guest", "access-1", "refresh-1"),
            "game-1",
            "seat-secret");

        await store.SaveAsync(expected);
        var loaded = await store.LoadAsync();

        Assert.Equal(expected, loaded);
        Assert.Contains("\"seatToken\":\"seat-secret\"", keyValues.Value);
        Assert.DoesNotContain("SeatToken", keyValues.Value);

        await store.ClearAsync();
        Assert.Null(await store.LoadAsync());
    }

    [Fact]
    public async Task Delegating_store_forwards_platform_storage_operations()
    {
        string? value = null;
        var store = new DelegatingKeyValueStore(
            key => ValueTask.FromResult(value),
            (key, next) =>
            {
                value = next;
                return ValueTask.CompletedTask;
            },
            key =>
            {
                value = null;
                return ValueTask.CompletedTask;
            });

        await store.SetAsync("session", "encrypted-or-origin-scoped-value");
        Assert.Equal("encrypted-or-origin-scoped-value", await store.GetAsync("session"));

        await store.RemoveAsync("session");
        Assert.Null(await store.GetAsync("session"));
    }

    [Fact]
    public async Task Battle_commands_build_an_envelope_from_the_kind_payload_and_version()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("device-1", identity, "game-1", "seat-secret") };
        var moved = Projection("game-1") with { Version = 13 };
        var api = new FakeGameApiClient { Commanded = moved };
        var coordinator = new GameSessionCoordinator(api, store);

        var result = await coordinator.SubmitCommandAsync(12, "moveUnit", new { unitId = "s1-u2", to = "3,4" });

        Assert.Same(moved, result);
        Assert.Equal(12, api.CommandRequest?.Command.ExpectedVersion);
        Assert.Equal("moveUnit", api.CommandRequest?.Command.Kind);
        Assert.Equal("3,4", api.CommandRequest?.Command.Payload.GetProperty("to").GetString());
        Assert.Equal(32, api.CommandRequest?.Command.CommandId.Length);
    }

    [Fact]
    public async Task Events_use_the_saved_seat_and_leaving_clears_the_session()
    {
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var store = new MemoryGameSessionStore { Current = new StoredGameSession("device-1", identity, "game-1", "seat-secret") };
        var api = new FakeGameApiClient { Events = new EventPage([], 9) };
        var coordinator = new GameSessionCoordinator(api, store);

        var page = await coordinator.GetEventsAsync(4);
        await coordinator.LeaveAsync();

        Assert.Equal(9, page.LastSequence);
        Assert.Equal((identity, "game-1", "seat-secret", 4L), api.EventsRequest);
        Assert.Null(store.Current);
    }

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

    private static GameProjection Projection(string gameId) => new(
        gameId,
        1,
        GameLifecycle.Lobby,
        GameMode.VanillaPvp,
        new ViewerPermissions("player", "seat-1", true, true),
        new BoardProjection(0, 0, []),
        new TurnProjection(0, null, 0),
        null,
        [],
        new Dictionary<string, int> { ["seat-1"] = 0 },
        null);

    private sealed class MemoryGameSessionStore : IGameSessionStore
    {
        public StoredGameSession? Current { get; set; }

        public ValueTask<StoredGameSession?> LoadAsync() => ValueTask.FromResult(Current);

        public ValueTask SaveAsync(StoredGameSession session)
        {
            Current = session;
            return ValueTask.CompletedTask;
        }

        public ValueTask ClearAsync()
        {
            Current = null;
            return ValueTask.CompletedTask;
        }
    }

    private sealed class MemoryKeyValueStore : IKeyValueStore
    {
        public string? Value { get; private set; }

        public ValueTask<string?> GetAsync(string key) => ValueTask.FromResult(Value);

        public ValueTask SetAsync(string key, string value)
        {
            Value = value;
            return ValueTask.CompletedTask;
        }

        public ValueTask RemoveAsync(string key)
        {
            Value = null;
            return ValueTask.CompletedTask;
        }
    }

    private sealed class FakeGameApiClient : IGameApiClient
    {
        public AuthSession? Guest { get; init; }
        public GameSeatSession? Created { get; init; }
        public GameProjection? Reconnected { get; init; }
        public GameSeatSession? Joined { get; init; }
        public GameProjection? Commanded { get; init; }
        public GameProjection? Started { get; init; }
        public AuthSession? Refreshed { get; init; }
        public HttpRequestException? ReconnectFailure { get; set; }
        public List<(AuthSession Identity, string GameId, string SeatToken)> ReconnectRequests { get; } = [];
        public (AuthSession Identity, string GameId, string SeatToken)? ReconnectRequest =>
            ReconnectRequests.Count == 0 ? null : ReconnectRequests[^1];
        public (string RefreshToken, string DeviceId)? RefreshRequest { get; private set; }
        public (AuthSession Identity, string GameId)? JoinRequest { get; private set; }
        public ((AuthSession Identity, string GameId, string SeatToken) Session, CommandEnvelope Command)? CommandRequest { get; private set; }
        public (AuthSession Identity, string GameId, string SeatToken)? StartRequest { get; private set; }

        public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

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

        public EventPage? Events { get; init; }
        public (AuthSession Identity, string GameId, string SeatToken, long After)? EventsRequest { get; private set; }

        public Task<EventPage> GetEventsAsync(
            AuthSession session,
            string gameId,
            string seatToken,
            long afterSequence,
            CancellationToken cancellationToken = default)
        {
            EventsRequest = (session, gameId, seatToken, afterSequence);
            return Task.FromResult(Events ?? throw new InvalidOperationException("Events response was not configured."));
        }

        public Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default) =>
            Task.FromResult(Guest ?? throw new InvalidOperationException("Guest response was not configured."));

        public Task<AuthSession> RefreshAsync(
            string refreshToken,
            string deviceId,
            CancellationToken cancellationToken = default)
        {
            RefreshRequest = (refreshToken, deviceId);
            return Task.FromResult(Refreshed ?? throw new InvalidOperationException("Refresh response was not configured."));
        }

        public Task<GameSeatSession> CreateGameAsync(
            AuthSession session,
            GameMode mode,
            string scenarioName,
            CancellationToken cancellationToken = default) =>
            Task.FromResult(Created ?? throw new InvalidOperationException("Game response was not configured."));

        public Task<GameSeatSession> JoinGameAsync(
            AuthSession session,
            string gameId,
            CancellationToken cancellationToken = default)
        {
            JoinRequest = (session, gameId);
            return Task.FromResult(Joined ?? throw new InvalidOperationException("Join response was not configured."));
        }

        public Task<GameProjection> ReconnectAsync(
            AuthSession session,
            string gameId,
            string seatToken,
            CancellationToken cancellationToken = default)
        {
            ReconnectRequests.Add((session, gameId, seatToken));
            if (ReconnectFailure is not null)
            {
                var failure = ReconnectFailure;
                ReconnectFailure = null;
                throw failure;
            }
            return Task.FromResult(Reconnected ?? throw new InvalidOperationException("Reconnect response was not configured."));
        }

        public Task<GameProjection> SubmitCommandAsync(
            AuthSession session,
            string gameId,
            string seatToken,
            CommandEnvelope command,
            CancellationToken cancellationToken = default)
        {
            CommandRequest = ((session, gameId, seatToken), command);
            return Task.FromResult(Commanded ?? throw new InvalidOperationException("Command response was not configured."));
        }

        public Task<GameProjection> StartGameAsync(
            AuthSession session,
            string gameId,
            string seatToken,
            CancellationToken cancellationToken = default)
        {
            StartRequest = (session, gameId, seatToken);
            return Task.FromResult(Started ?? throw new InvalidOperationException("Start response was not configured."));
        }

        public Task<SeatReassignmentResult> ReassignSeatAsync(
            AuthSession session,
            string gameId,
            string hostSeatToken,
            string seatId,
            string replacementIdentityId,
            CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }
}
