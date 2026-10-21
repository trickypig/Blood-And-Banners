using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed record StoredAccount(string DeviceId, AuthSession Identity, string DisplayName);

/// <summary>The signed-in account on this device; owns token refresh for the account and any seat it holds.</summary>
public interface IAccountSession
{
    StoredAccount? Current { get; }
    IAccountApiClient Api { get; }
    event Action? Changed;
    Task<StoredAccount?> LoadAsync();
    Task StartSignInAsync(string email, CancellationToken cancellationToken = default);
    Task<StoredAccount> VerifyCodeAsync(string email, string code, string deviceId, CancellationToken cancellationToken = default);
    Task<StoredAccount> VerifyLinkAsync(string token, string deviceId, CancellationToken cancellationToken = default);
    Task<StoredAccount> SetDisplayNameAsync(string displayName, CancellationToken cancellationToken = default);
    Task SignOutAsync();
    /// <summary>Refreshes once even when several callers saw the same expired token.</summary>
    Task<AuthSession> RefreshAsync(string staleAccessToken, CancellationToken cancellationToken = default);
    /// <summary>Runs an account call, refreshing and retrying once on 401.</summary>
    Task<T> CallAsync<T>(Func<AuthSession, Task<T>> call, CancellationToken cancellationToken = default);
}
