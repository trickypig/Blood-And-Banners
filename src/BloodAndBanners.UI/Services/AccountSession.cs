using System.Net;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class AccountSession(
    IAccountApiClient accountApi,
    IGameApiClient gameApi,
    IAccountStore store,
    IGameSessionStore games) : IAccountSession
{
    private readonly SemaphoreSlim _refreshGate = new(1, 1);
    private bool _loaded;

    public StoredAccount? Current { get; private set; }
    public IAccountApiClient Api => accountApi;
    public event Action? Changed;

    public async Task<StoredAccount?> LoadAsync()
    {
        if (!_loaded)
        {
            Current = await store.LoadAsync();
            _loaded = true;
        }
        return Current;
    }

    public Task StartSignInAsync(string email, CancellationToken cancellationToken = default) =>
        accountApi.StartEmailSignInAsync(email, cancellationToken);

    public Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default) =>
        CompleteAsync(deviceId, guest => accountApi.VerifyEmailCodeAsync(email, code, deviceId, guest, cancellationToken), cancellationToken);

    public Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default) =>
        CompleteAsync(deviceId, guest => accountApi.VerifyEmailLinkAsync(token, deviceId, guest, cancellationToken), cancellationToken);

    public async Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default)
    {
        var profile = await CallAsync(identity => accountApi.UpdateDisplayNameAsync(identity, displayName, cancellationToken), cancellationToken);
        var current = Current ?? throw new InvalidOperationException("Not signed in.");
        var updated = current with { DisplayName = profile.DisplayName, Identity = current.Identity with { DisplayName = profile.DisplayName } };
        await SaveAsync(updated);
        return updated;
    }

    public async Task SignOutAsync()
    {
        if (Current is { } current)
        {
            try
            {
                await accountApi.LogoutAsync(current.Identity.RefreshToken);
            }
            catch (Exception)
            {
                // Signing out locally still works when the server cannot be told (including timeouts).
            }
        }
        await ForgetAsync();
    }

    public async Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default)
    {
        await _refreshGate.WaitAsync(cancellationToken);
        try
        {
            var current = Current ?? throw new InvalidOperationException("Not signed in.");
            if (current.Identity.AccessToken != staleAccessToken)
            {
                return current.Identity;
            }
            AuthSession refreshed;
            try
            {
                refreshed = await gameApi.RefreshAsync(current.Identity.RefreshToken, current.DeviceId, cancellationToken);
            }
            catch (HttpRequestException error) when (error.StatusCode == HttpStatusCode.Unauthorized)
            {
                await ForgetAsync();
                throw;
            }
            var updated = current with { Identity = refreshed with { DisplayName = current.DisplayName } };
            await SaveAsync(updated);
            // A seat held by this account carries the same tokens; keep it in step so neither copy goes stale.
            if (await games.LoadAsync() is { } game && game.Identity.IdentityId == refreshed.IdentityId)
            {
                await games.SaveAsync(game with { DeviceId = updated.DeviceId, Identity = updated.Identity });
            }
            return updated.Identity;
        }
        finally
        {
            _refreshGate.Release();
        }
    }

    public async Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default)
    {
        var identity = (await LoadAsync() ?? throw new InvalidOperationException("Not signed in.")).Identity;
        try
        {
            return await call(identity);
        }
        catch (HttpRequestException error) when (error.StatusCode == HttpStatusCode.Unauthorized)
        {
            return await call(await RefreshAsync(identity.AccessToken, cancellationToken));
        }
    }

    private async Task<StoredAccount> CompleteAsync(string deviceId, Func<AuthSession?, Task<AuthSession>> verify, CancellationToken cancellationToken)
    {
        var game = await games.LoadAsync();
        var guest = game?.Identity.Kind == "guest" ? game.Identity : null;
        var identity = await verify(guest);
        var account = new StoredAccount(deviceId, identity, identity.DisplayName ?? string.Empty);
        await SaveAsync(account);
        if (guest is not null && game is not null)
        {
            // The server moves the guest's seat only when the account holds none in that game, so ask for the
            // account's own seat; if that fails the guest identity and its seat token keep working.
            try
            {
                var resumed = await accountApi.ResumeGameAsync(identity, game.GameId, cancellationToken);
                await games.SaveAsync(new StoredGameSession(deviceId, identity, resumed.Game.GameId, resumed.SeatToken));
            }
            catch (HttpRequestException)
            {
            }
        }
        return account;
    }

    private async Task SaveAsync(StoredAccount account)
    {
        await store.SaveAsync(account);
        Current = account;
        _loaded = true;
        Changed?.Invoke();
    }

    private async Task ForgetAsync()
    {
        await store.ClearAsync();
        Current = null;
        _loaded = true;
        Changed?.Invoke();
    }
}
