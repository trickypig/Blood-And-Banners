using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class GameApiClient(HttpClient httpClient)
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

    public async Task<GameProjection> CreateGameAsync(
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
        return await ReadAsync<GameProjection>(response, cancellationToken);
    }

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        response.EnsureSuccessStatusCode();
        await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        return await JsonSerializer.DeserializeAsync<T>(stream, ContractJson.Options, cancellationToken)
            ?? throw new JsonException("The API returned an empty JSON response.");
    }
}
