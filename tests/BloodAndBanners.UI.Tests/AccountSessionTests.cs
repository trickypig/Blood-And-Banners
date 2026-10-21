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
        public int Refreshes { get; private set; }
        public bool Revoked { get; set; }
        public TaskCompletionSource? Gate { get; set; }

        public async Task<AuthSession> RefreshAsync(string refreshToken, string deviceId, CancellationToken cancellationToken = default)
        {
            Refreshes++;
            if (Gate is not null) await Gate.Task;
            if (Revoked) throw new GameApiException(HttpStatusCode.Unauthorized, "invalidRefreshToken", "revoked");
            return new AuthSession("u1", "user", $"access-{Refreshes + 1}", $"refresh-{Refreshes + 1}");
        }

        public Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GameSeatSession> CreateGameAsync(AuthSession session, GameMode mode, string scenarioName, CancellationToken cancellationToken = default) => throw new NotSupportedException();
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
        var session = new AccountSession(api, new RefreshingGameApi(), store, games);

        var account = await session.VerifyCodeAsync("me@example.com", "123456", "web-1");

        Assert.Same(Guest, api.VerifyGuest);
        Assert.Equal("web-1", account.DeviceId);
        Assert.Equal(account, store.Current);
        Assert.Equal("u1", games.Current!.Identity.IdentityId);
        Assert.Equal("seat-secret", games.Current.SeatToken);
    }

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
    public async Task Sign_out_revokes_and_forgets_but_keeps_the_game_seat()
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
        Assert.NotNull(games.Current);
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
