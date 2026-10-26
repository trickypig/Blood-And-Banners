using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class MapApiClient(HttpClient http) : IMapApiClient
{
    private const string Root = "api/v1/account/maps";
    public Task<SavedMaps> ListAsync(AuthSession session, CancellationToken cancellationToken = default) =>
        SendAsync<SavedMaps>(HttpMethod.Get, Root, session, null, cancellationToken);
    public Task<SavedMap> LoadAsync(AuthSession session, string scenarioId, CancellationToken cancellationToken = default) =>
        SendAsync<SavedMap>(HttpMethod.Get, $"{Root}/{Uri.EscapeDataString(scenarioId)}", session, null, cancellationToken);
    public Task<SavedMap> CreateAsync(AuthSession session, ScenarioDefinition definition, CancellationToken cancellationToken = default) =>
        SendAsync<SavedMap>(HttpMethod.Post, Root, session, new { definitionJson = Encode(definition) }, cancellationToken);
    public Task<SavedMap> SaveAsync(AuthSession session, string scenarioId, string expectedRevisionId, ScenarioDefinition definition, CancellationToken cancellationToken = default) =>
        SendAsync<SavedMap>(HttpMethod.Put, $"{Root}/{Uri.EscapeDataString(scenarioId)}", session, new { definitionJson = Encode(definition), expectedRevisionId }, cancellationToken);

    // One string field, not a JSON tree: the host's firewall rejects request bodies with about a thousand
    // JSON fields, and each hex carries a dozen.
    private static string Encode(ScenarioDefinition definition) => JsonSerializer.Serialize(definition, ContractJson.Options);

    private async Task<T> SendAsync<T>(HttpMethod method, string path, AuthSession session, object? body, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(method, path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.AccessToken);
        if (body is not null) request.Content = JsonContent.Create(body, body.GetType(), options: ContractJson.Options);
        using var response = await http.SendAsync(request, cancellationToken);
        return await GameApiClient.ReadAsync<T>(response, cancellationToken);
    }
}
