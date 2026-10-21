using System.Net.Http.Headers;
using System.Net.Http.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class AccountApiClient(HttpClient httpClient) : IAccountApiClient
{
    public async Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default)
    {
        using var response = await SendAsync(HttpMethod.Post, "api/v1/auth/email/start", null, new { email }, cancellationToken);
        await EnsureAsync(response, cancellationToken);
    }

    public Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default) =>
        ReadAsync<AuthSession>(HttpMethod.Post, "api/v1/auth/email/verify", guest, new { email, code, deviceId }, cancellationToken);

    public Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default) =>
        ReadAsync<AuthSession>(HttpMethod.Post, "api/v1/auth/email/verify", guest, new { token, deviceId }, cancellationToken);

    public async Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        using var response = await SendAsync(HttpMethod.Post, "api/v1/auth/logout", null, new { refreshToken }, cancellationToken);
        await EnsureAsync(response, cancellationToken);
    }

    public Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default) =>
        ReadAsync<AccountProfile>(HttpMethod.Put, "api/v1/account", session, new { displayName }, cancellationToken);

    public Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        ReadAsync<AccountGames>(HttpMethod.Get, "api/v1/account/games", session, null, cancellationToken);

    public Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default) =>
        ReadAsync<GameSeatSession>(HttpMethod.Post, $"api/v1/games/{Uri.EscapeDataString(gameId)}/resume", session, null, cancellationToken);

    public Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Get, "api/v1/friends", session, null, cancellationToken);

    public Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, "api/v1/friends/requests", session, new { email }, cancellationToken);

    public Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, "api/v1/friends/requests", session, new { gameId, seatId }, cancellationToken);

    public Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, $"api/v1/friends/requests/{Uri.EscapeDataString(requestId)}/accept", session, null, cancellationToken);

    public Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Post, $"api/v1/friends/requests/{Uri.EscapeDataString(requestId)}/decline", session, null, cancellationToken);

    public Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default) =>
        ReadAsync<FriendsList>(HttpMethod.Delete, $"api/v1/friends/{Uri.EscapeDataString(userId)}", session, null, cancellationToken);

    private async Task<T> ReadAsync<T>(HttpMethod method, string uri, AuthSession? session, object? body, CancellationToken cancellationToken)
    {
        using var response = await SendAsync(method, uri, session, body, cancellationToken);
        return await GameApiClient.ReadAsync<T>(response, cancellationToken);
    }

    private Task<HttpResponseMessage> SendAsync(HttpMethod method, string uri, AuthSession? session, object? body, CancellationToken cancellationToken)
    {
        var request = new HttpRequestMessage(method, uri);
        if (body is not null)
        {
            request.Content = JsonContent.Create(body, body.GetType(), options: ContractJson.Options);
        }
        if (session is not null)
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        }
        return httpClient.SendAsync(request, cancellationToken);
    }

    private static async Task EnsureAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (!response.IsSuccessStatusCode)
        {
            throw await GameApiClient.ErrorAsync(response, cancellationToken);
        }
    }
}
