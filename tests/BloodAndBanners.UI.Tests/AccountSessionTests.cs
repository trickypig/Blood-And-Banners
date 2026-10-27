using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountSessionTests
{
    private sealed class MemoryAccountStore : IAccountStore
    {
        public StoredAccount? Current { get; set; }
        public ValueTask<StoredAccount?> LoadAsync() => ValueTask.FromResult(Current);
        public ValueTask SaveAsync(StoredAccount account) { Current = account; return ValueTask.CompletedTask; }
        public ValueTask ClearAsync() { Current = null; return ValueTask.CompletedTask; }
    }

    private sealed class MemoryGames : IGameSessionStore
    {
        public StoredGameSession? Current { get; set; }
        public ValueTask<StoredGameSession?> LoadAsync() => ValueTask.FromResult(Current);
        public ValueTask SaveAsync(StoredGameSession session) { Current = session; return ValueTask.CompletedTask; }
        public ValueTask ClearAsync() { Current = null; return ValueTask.CompletedTask; }
    }

    /// <summary>Only RefreshAsync is used by AccountSession.</summary>
    private sealed class RefreshingGameApi : IGameApiClient
    {
        /// <summary>Account refreshes; guest refreshes are recorded in <see cref="GuestRefreshes"/>.</summary>
        public int Refreshes { get; private set; }
        public bool Revoked { get; set; }
        public TaskCompletionSource? Gate { get; set; }
        /// <summary>Runs while an account refresh is in flight, e.g. to let another tab write the shared store.</summary>
        public Action? DuringRefresh { get; set; }
        public List<(string RefreshToken, string DeviceId)> GuestRefreshes { get; } = [];
        public Exception? GuestFailure { get; set; }

        public async Task<AuthSession> RefreshAsync(string refreshToken, string deviceId, CancellationToken cancellationToken = default)
        {
            if (refreshToken.StartsWith("guest-", StringComparison.Ordinal))
            {
                GuestRefreshes.Add((refreshToken, deviceId));
                if (GuestFailure is not null) throw GuestFailure;
                return new AuthSession("g1", "guest", "guest-access-fresh", "guest-refresh-fresh");
            }
            Refreshes++;
            if (Gate is not null) await Gate.Task;
            DuringRefresh?.Invoke();
            if (Revoked) throw new GameApiException(HttpStatusCode.Unauthorized, "invalidRefreshToken", "revoked");
            return new AuthSession("u1", "user", $"access-{Refreshes + 1}", $"refresh-{Refreshes + 1}");
        }

        public Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameSeatSession> CreateGameAsync(AuthSession session, GameMode mode, string scenarioName, string? scenarioRevisionId = null, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameSeatSession> JoinGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> ReconnectAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> SubmitCommandAsync(AuthSession session, string gameId, string seatToken, CommandEnvelope command, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> StartGameAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task LeaveGameAsync(AuthSession session, string gameId, string seatToken, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> RemoveSeatAsync(AuthSession session, string gameId, string hostSeatToken, string seatId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<SeatReassignmentResult> ReassignSeatAsync(AuthSession session, string gameId, string hostSeatToken, string seatId, string replacementIdentityId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<EventPage> GetEventsAsync(AuthSession session, string gameId, string seatToken, long afterSequence, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameProjection> WatchGameAsync(string gameId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<EventPage> GetWatchedEventsAsync(string gameId, long afterSequence, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private static readonly AuthSession Guest = new("g1", "guest", "guest-access", "guest-refresh");

    [Fact]
    public async Task Verifying_saves_the_account_and_moves_a_guest_seat_to_it()
    {
        var api = new FakeAccountApiClient();
        var store = new MemoryAccountStore();
        var games = new MemoryGames { Current = new StoredGameSession("web-old", Guest, "game-1", "seat-secret") };
        api.Resumed = new GameSeatSession(Projection("game-1"), "account-seat");
        var gameApi = new RefreshingGameApi();
        var session = new AccountSession(api, gameApi, store, games);

        var account = await session.VerifyCodeAsync("me@example.com", "123456", "web-1");

        Assert.Equal([("guest-refresh", "web-old")], gameApi.GuestRefreshes);
        Assert.Equal("guest-access-fresh", api.VerifyGuest!.AccessToken);
        Assert.Equal("web-1", account.DeviceId);
        Assert.Equal(account, store.Current);
        Assert.Equal("u1", games.Current!.Identity.IdentityId);
        Assert.Equal("account-seat", games.Current.SeatToken);
        Assert.Equal("web-1", games.Current.DeviceId);
        Assert.Contains("resume:game-1", api.Calls);
    }

    [Fact]
    public async Task A_stale_guest_is_refreshed_before_verify_so_the_seat_carries_over()
    {
        var api = new FakeAccountApiClient { VerifyFailure = new GameApiException(HttpStatusCode.BadRequest, "invalidCode", "wrong") };
        var games = new MemoryGames { Current = new StoredGameSession("web-old", Guest, "game-1", "seat-secret") };
        var session = new AccountSession(api, new RefreshingGameApi(), new MemoryAccountStore(), games);

        await Assert.ThrowsAsync<GameApiException>(() => session.VerifyCodeAsync("me@example.com", "000000", "web-1"));

        Assert.Equal("guest-access-fresh", api.VerifyGuest!.AccessToken);
        // The rotated guest tokens are kept, since the old refresh token is now revoked.
        Assert.Equal(new StoredGameSession("web-old", new AuthSession("g1", "guest", "guest-access-fresh", "guest-refresh-fresh"), "game-1", "seat-secret"), games.Current);
    }

    [Fact]
    public async Task A_failed_guest_refresh_signs_in_without_carry_over_and_leaves_the_guest_seat()
    {
        var api = new FakeAccountApiClient();
        var games = new MemoryGames { Current = new StoredGameSession("web-old", Guest, "game-1", "seat-secret") };
        var gameApi = new RefreshingGameApi { GuestFailure = new GameApiException(HttpStatusCode.Unauthorized, "invalidRefreshToken", "revoked") };
        var store = new MemoryAccountStore();
        var session = new AccountSession(api, gameApi, store, games);

        await session.VerifyCodeAsync("me@example.com", "123456", "web-1");

        Assert.Single(gameApi.GuestRefreshes);
        Assert.Contains("code:me@example.com:123456", api.Calls);
        Assert.Null(api.VerifyGuest);
        Assert.Equal("u1", store.Current!.Identity.IdentityId);
        Assert.DoesNotContain(api.Calls, call => call.StartsWith("resume:"));
        Assert.Equal(new StoredGameSession("web-old", Guest, "game-1", "seat-secret"), games.Current);
    }

    public static TheoryData<Exception> ResumeFailures => new()
    {
        new GameApiException(HttpStatusCode.NotFound, "notFound", "gone"),
        new TaskCanceledException("timed out"),
    };

    [Theory]
    [MemberData(nameof(ResumeFailures))]
    public async Task A_failed_resume_keeps_the_seat_token_under_the_account(Exception failure)
    {
        var api = new FakeAccountApiClient { ResumeFailure = failure };
        var games = new MemoryGames { Current = new StoredGameSession("web-old", Guest, "game-1", "seat-secret") };
        var store = new MemoryAccountStore();
        var session = new AccountSession(api, new RefreshingGameApi(), store, games);

        await session.VerifyCodeAsync("me@example.com", "123456", "web-1");

        Assert.Equal("u1", store.Current!.Identity.IdentityId);
        Assert.Equal(store.Current.Identity, games.Current!.Identity);
        Assert.Equal("seat-secret", games.Current.SeatToken);
        Assert.Equal("game-1", games.Current.GameId);
        Assert.Equal("web-1", games.Current.DeviceId);
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

    [Fact]
    public async Task A_401_refreshes_once_and_retries_and_updates_both_stores()
    {
        var api = new FakeAccountApiClient();
        api.ExpiredTokens.Add("access-1");
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var games = new MemoryGames { Current = new StoredGameSession("web-1", identity, "game-1", "seat") };
        var gameApi = new RefreshingGameApi();
        var session = new AccountSession(api, gameApi, store, games);
        await session.LoadAsync();

        await session.CallAsync(i => api.GetGamesAsync(i));

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal("access-2", store.Current!.Identity.AccessToken);
        Assert.Equal("Ash", store.Current.DisplayName);
        Assert.Equal("refresh-2", games.Current!.Identity.RefreshToken);
    }

    [Fact]
    public async Task Concurrent_refreshes_for_the_same_stale_token_hit_the_server_once()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var gameApi = new RefreshingGameApi { Gate = new TaskCompletionSource() };
        var session = new AccountSession(new FakeAccountApiClient(), gameApi, store, new MemoryGames());
        await session.LoadAsync();

        var first = session.RefreshAsync("access-1");
        var second = session.RefreshAsync("access-1");
        gameApi.Gate.SetResult();
        var results = await Task.WhenAll(first, second);

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal(results[0], results[1]);
    }

    [Fact]
    public async Task A_revoked_refresh_token_signs_out()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var session = new AccountSession(new FakeAccountApiClient(), new RefreshingGameApi { Revoked = true }, store, new MemoryGames());
        await session.LoadAsync();
        var changed = 0;
        session.Changed += () => changed++;

        await Assert.ThrowsAsync<GameApiException>(() => session.RefreshAsync("access-1"));

        Assert.Null(session.Current);
        Assert.Null(store.Current);
        Assert.Equal(1, changed);
    }

    [Fact]
    public async Task A_second_tab_adopts_tokens_another_tab_already_rotated()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var games = new MemoryGames { Current = new StoredGameSession("web-1", identity, "game-1", "seat") };
        var gameApi = new RefreshingGameApi();
        var tabA = new AccountSession(new FakeAccountApiClient(), gameApi, store, games);
        var tabB = new AccountSession(new FakeAccountApiClient(), gameApi, store, games);
        await tabA.LoadAsync();
        await tabB.LoadAsync();
        var bChanged = 0;
        tabB.Changed += () => bChanged++;

        await tabA.RefreshAsync("access-1");
        var adopted = await tabB.RefreshAsync("access-1");

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal("access-2", adopted.AccessToken);
        Assert.Equal(store.Current, tabB.Current);
        Assert.NotNull(store.Current);
        Assert.Equal("refresh-2", games.Current!.Identity.RefreshToken);
        Assert.Equal(1, bChanged);
    }

    [Fact]
    public async Task A_refresh_rejected_after_another_tab_rotated_adopts_instead_of_signing_out()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var newer = new StoredAccount("web-1", new AuthSession("u1", "user", "access-9", "refresh-9") { DisplayName = "Ash" }, "Ash");
        // The other tab saves its rotated pair while this tab's refresh (with the now revoked token) is in flight.
        var gameApi = new RefreshingGameApi { Revoked = true, DuringRefresh = () => store.Current = newer };
        var session = new AccountSession(new FakeAccountApiClient(), gameApi, store, new MemoryGames());
        await session.LoadAsync();

        var adopted = await session.RefreshAsync("access-1");

        Assert.Equal(1, gameApi.Refreshes);
        Assert.Equal("access-9", adopted.AccessToken);
        Assert.Equal(newer, session.Current);
        Assert.Equal(newer, store.Current);
    }

    [Fact]
    public async Task Sign_out_revokes_and_forgets_and_clears_a_seat_held_by_the_account()
    {
        var api = new FakeAccountApiClient();
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var games = new MemoryGames { Current = new StoredGameSession("web-1", identity, "game-1", "seat") };
        var session = new AccountSession(api, new RefreshingGameApi(), store, games);
        await session.LoadAsync();

        await session.SignOutAsync();

        Assert.Contains("logout:refresh-1", api.Calls);
        Assert.Null(store.Current);
        Assert.Null(games.Current);
    }

    [Fact]
    public async Task Sign_out_keeps_a_guest_game_seat()
    {
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var guestSeat = new StoredGameSession("web-1", Guest, "game-1", "seat");
        var games = new MemoryGames { Current = guestSeat };
        var session = new AccountSession(new FakeAccountApiClient(), new RefreshingGameApi(), store, games);
        await session.LoadAsync();

        await session.SignOutAsync();

        Assert.Null(store.Current);
        Assert.Equal(guestSeat, games.Current);
    }

    [Fact]
    public async Task Sign_out_still_forgets_locally_when_the_logout_call_is_cancelled()
    {
        var api = new FakeAccountApiClient { LogoutFailure = new TaskCanceledException() };
        var identity = new AuthSession("u1", "user", "access-1", "refresh-1");
        var store = new MemoryAccountStore { Current = new StoredAccount("web-1", identity, "Ash") };
        var session = new AccountSession(api, new RefreshingGameApi(), store, new MemoryGames());
        await session.LoadAsync();

        await session.SignOutAsync();

        Assert.Null(store.Current);
        Assert.Null(session.Current);
    }

    [Fact]
    public async Task Json_store_round_trips_under_the_account_key()
    {
        var values = new Dictionary<string, string>();
        var kv = new DelegatingKeyValueStore(
            key => ValueTask.FromResult(values.GetValueOrDefault(key)),
            (key, value) => { values[key] = value; return ValueTask.CompletedTask; },
            key => { values.Remove(key); return ValueTask.CompletedTask; });
        var store = new JsonAccountStore(kv);
        var account = new StoredAccount("web-1", new AuthSession("u1", "user", "a", "r") { DisplayName = "Ash" }, "Ash");

        await store.SaveAsync(account);

        Assert.True(values.ContainsKey("bloodAndBanners.account"));
        Assert.Equal(account, await store.LoadAsync());
    }
}
