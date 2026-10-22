using System.Net;
using System.Text;
using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class MapApiClientTests
{
    [Fact]
    public async Task Saves_send_expected_revision_and_bearer_and_read_definition()
    {
        var handler = new Handler();
        var client = new MapApiClient(new HttpClient(handler) { BaseAddress = new("https://example.test/play/") });
        var definition = MapFileCodec.Import(MapFileCodecTests.Legacy);
        handler.Response = JsonSerializer.Serialize(new SavedMap(new("map", "Crossing", "r2", 2, "2026-10-21T00:00:00Z"), definition), ContractJson.Options);
        var result = await client.SaveAsync(new("owner", "user", "secret", "refresh"), "a/b", "r1", definition);
        Assert.Equal("https://example.test/play/api/v1/account/maps/a%2Fb", handler.Uri);
        Assert.Equal("Bearer secret", handler.Authorization);
        Assert.Equal(HttpMethod.Put, handler.Method);
        using var body = JsonDocument.Parse(handler.Body!);
        Assert.Equal("r1", body.RootElement.GetProperty("expectedRevisionId").GetString());
        Assert.Equal("Crossing", body.RootElement.GetProperty("definition").GetProperty("name").GetString());
        Assert.Equal(2, result.Summary.Revision);
        Assert.Equal("keep", result.Definition.Map.Hexes[0].ExtensionData!["customTile"].GetString());
    }

    [Fact]
    public async Task Conflict_codes_are_available_to_the_editor()
    {
        var handler = new Handler { Status = HttpStatusCode.Conflict, Response = """{"error":{"code":"mapConflict","message":"Saved elsewhere"}}""" };
        var client = new MapApiClient(new HttpClient(handler) { BaseAddress = new("https://example.test/") });
        var error = await Assert.ThrowsAsync<GameApiException>(() => client.LoadAsync(new("u", "user", "a", "r"), "id"));
        Assert.Equal("mapConflict", error.Code);
    }

    private sealed class Handler : HttpMessageHandler
    {
        public string Response = "{}";
        public HttpStatusCode Status = HttpStatusCode.OK;
        public string? Uri, Authorization, Body;
        public HttpMethod? Method;
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Uri = request.RequestUri!.AbsoluteUri; Method = request.Method;
            Authorization = request.Headers.Authorization?.ToString();
            Body = request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken);
            return new(Status) { Content = new StringContent(Response, Encoding.UTF8, "application/json") };
        }
    }
}
