using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

internal sealed class FakeAccountApiClient : IAccountApiClient
{
    public AuthSession Verified { get; set; } = new("u1", "user", "access-1", "refresh-1") { DisplayName = "" };
    public Exception? VerifyFailure { get; set; }
    public Exception? StartFailure { get; set; }
    public AccountGames Games { get; set; } = new([], []);
    public FriendsList Friends { get; set; } = new([], [], []);
    public GameSeatSession? Resumed { get; set; }
    public Exception? ResumeFailure { get; set; }
    public Exception? FriendFailure { get; set; }
    public List<string> Calls { get; } = [];
    public AuthSession? VerifyGuest { get; private set; }
    public List<AuthSession> SessionsUsed { get; } = [];
    /// <summary>Access tokens the fake rejects with 401, to exercise refresh.</summary>
    public HashSet<string> ExpiredTokens { get; } = [];

    public Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        Calls.Add($"start:{email}");
        return StartFailure is null ? Task.CompletedTask : Task.FromException(StartFailure);
    }

    public Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default)
    {
        Calls.Add($"code:{email}:{code}");
        VerifyGuest = guest;
        return VerifyFailure is null ? Task.FromResult(Verified) : Task.FromException<AuthSession>(VerifyFailure);
    }

    public Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default)
    {
        Calls.Add($"link:{token}");
        VerifyGuest = guest;
        return VerifyFailure is null ? Task.FromResult(Verified) : Task.FromException<AuthSession>(VerifyFailure);
    }

    public Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        Calls.Add($"logout:{refreshToken}");
        return Task.CompletedTask;
    }

    public Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default) =>
        Use(session, $"name:{displayName}", () => new AccountProfile(session.IdentityId, "me@example.com", displayName.Trim()));

    public Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        Use(session, "games", () => Games);

    public Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) =>
        ResumeFailure is not null
            ? Task.FromException<GameSeatSession>(ResumeFailure)
            : Use(session, $"resume:{gameId}", () => Resumed ?? throw new InvalidOperationException("Resume not configured."));

    public Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default) => Friend(session, "friends");
    public Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default) => Friend(session, $"request:{email}");
    public Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default) => Friend(session, $"seat:{gameId}:{seatId}");
    public Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) => Friend(session, $"accept:{requestId}");
    public Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) => Friend(session, $"decline:{requestId}");
    public Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default) => Friend(session, $"remove:{userId}");

    private Task<FriendsList> Friend(AuthSession session, string call) =>
        FriendFailure is not null && call != "friends" ? Task.FromException<FriendsList>(FriendFailure) : Use(session, call, () => Friends);

    private Task<T> Use<T>(AuthSession session, string call, Func<T> result)
    {
        SessionsUsed.Add(session);
        if (ExpiredTokens.Contains(session.AccessToken))
        {
            return Task.FromException<T>(new GameApiException(HttpStatusCode.Unauthorized, "expiredAccessToken", "expired"));
        }
        Calls.Add(call);
        return Task.FromResult(result());
    }
}

/// <summary>An in-memory signed-in (or signed-out) account for component tests.</summary>
internal sealed class FakeAccountSession(StoredAccount? current = null) : IAccountSession
{
    public StoredAccount? Current { get; private set; } = current;
    public FakeAccountApiClient FakeApi { get; } = new();
    public IAccountApiClient Api => FakeApi;
    public event Action? Changed;
    public bool SignedOut { get; private set; }
    public Exception? StartFailure { get; set; }
    public Exception? VerifyFailure { get; set; }
    public string NameAfterVerify { get; set; } = "";
    public List<string> Calls { get; } = [];

    public static StoredAccount Account(string name = "Ash") =>
        new("web-1", new AuthSession("u1", "user", "access-1", "refresh-1") { DisplayName = name }, name);

    public Task<StoredAccount?> LoadAsync() => Task.FromResult(Current);

    public Task StartSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        Calls.Add($"start:{email}");
        return StartFailure is null ? Task.CompletedTask : Task.FromException(StartFailure);
    }

    public Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default) =>
        Verify($"code:{email}:{code}");

    public Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default) =>
        Verify($"link:{token}");

    public Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default)
    {
        Calls.Add($"name:{displayName}");
        Current = Account(displayName.Trim());
        Changed?.Invoke();
        return Task.FromResult(Current);
    }

    public Task SignOutAsync()
    {
        SignedOut = true;
        Current = null;
        Changed?.Invoke();
        return Task.CompletedTask;
    }

    public Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default) =>
        Task.FromResult(Current!.Identity);

    public Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default) =>
        call(Current?.Identity ?? throw new InvalidOperationException("Not signed in."));

    public void Raise() => Changed?.Invoke();

    private Task<StoredAccount> Verify(string call)
    {
        Calls.Add(call);
        if (VerifyFailure is not null)
        {
            return Task.FromException<StoredAccount>(VerifyFailure);
        }
        Current = Account(NameAfterVerify);
        Changed?.Invoke();
        return Task.FromResult(Current);
    }
}
