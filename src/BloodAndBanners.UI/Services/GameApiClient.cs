using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class GameApiClient(HttpClient httpClient) : IGameApiClient
{
    public async Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.PostAsJsonAsync(
            "api/v1/auth/guest",
            new { deviceId },
            ContractJson.Options,
            cancellationToken);
        return await ReadAsync<AuthSession>(response, cancellationToken);
    }

    public async Task<AuthSession> RefreshAsync(
        string refreshToken,
        string deviceId,
        CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.PostAsJsonAsync(
            "api/v1/auth/refresh",
            new { refreshToken, deviceId },
            ContractJson.Options,
            cancellationToken);
        return await ReadAsync<AuthSession>(response, cancellationToken);
    }

    public async Task<GameSeatSession> CreateGameAsync(
        AuthSession session,
        GameMode mode,
        string scenarioName,
        CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, "api/v1/games")
        {
            Content = JsonContent.Create(new { mode, scenarioName }, options: ContractJson.Options)
        };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<GameSeatSession>(response, cancellationToken);
    }

    public async Task<GameSeatSession> JoinGameAsync(
        AuthSession session,
        string gameId,
        CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, $"api/v1/games/{Uri.EscapeDataString(gameId)}/join");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<GameSeatSession>(response, cancellationToken);
    }

    public async Task<GameProjection> ReconnectAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(HttpMethod.Get, $"api/v1/games/{gameId}", session, seatToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<GameProjection>(response, cancellationToken);
    }

    public async Task<GameProjection> SubmitCommandAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CommandEnvelope command,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(
            HttpMethod.Post,
            $"api/v1/games/{gameId}/commands",
            session,
            seatToken,
            JsonContent.Create(command, options: ContractJson.Options));
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<GameProjection>(response, cancellationToken);
    }

    public async Task<GameProjection> StartGameAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(
            HttpMethod.Post,
            $"api/v1/games/{Uri.EscapeDataString(gameId)}/start",
            session,
            seatToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<GameProjection>(response, cancellationToken);
    }

    public async Task<EventPage> GetEventsAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        long afterSequence,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(
            HttpMethod.Get,
            $"api/v1/games/{gameId}/events?after={afterSequence}",
            session,
            seatToken);
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<EventPage>(response, cancellationToken);
    }

    public async Task<SeatReassignmentResult> ReassignSeatAsync(
        AuthSession session,
        string gameId,
        string hostSeatToken,
        string seatId,
        string replacementIdentityId,
        CancellationToken cancellationToken = default)
    {
        using var request = CreateSeatRequest(
            HttpMethod.Post,
            $"api/v1/games/{gameId}/seats/{seatId}/reassign",
            session,
            hostSeatToken,
            JsonContent.Create(new { identityId = replacementIdentityId }, options: ContractJson.Options));
        using var response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadAsync<SeatReassignmentResult>(response, cancellationToken);
    }

    private static HttpRequestMessage CreateSeatRequest(
        HttpMethod method,
        string uri,
        AuthSession session,
        string seatToken,
        HttpContent? content = null)
    {
        var request = new HttpRequestMessage(method, uri) { Content = content };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        request.Headers.Add("X-Seat-Token", seatToken);
        return request;
    }

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (!response.IsSuccessStatusCode)
        {
            throw await ErrorAsync(response, cancellationToken);
        }
        await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        return await JsonSerializer.DeserializeAsync<T>(stream, ContractJson.Options, cancellationToken)
            ?? throw new JsonException("The API returned an empty JSON response.");
    }

    private static async Task<GameApiException> ErrorAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        ApiError? error = null;
        try
        {
            error = (await response.Content.ReadFromJsonAsync<ApiErrorResponse>(ContractJson.Options, cancellationToken))?.Error;
        }
        catch (JsonException)
        {
        }
        return new GameApiException(
            response.StatusCode,
            error?.Code ?? "httpError",
            error?.Message ?? $"The server returned HTTP {(int)response.StatusCode}.");
    }
}
