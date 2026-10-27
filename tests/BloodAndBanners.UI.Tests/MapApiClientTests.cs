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
        // The map travels as one string: the host's firewall rejects bodies with about a thousand JSON fields.
        Assert.False(body.RootElement.TryGetProperty("definition", out _));
        using var sent = JsonDocument.Parse(body.RootElement.GetProperty("definitionJson").GetString()!);
        Assert.Equal("Crossing", sent.RootElement.GetProperty("name").GetString());
        Assert.Equal("keep", sent.RootElement.GetProperty("map").GetProperty("hexes")[0].GetProperty("customTile").GetString());
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

    [Fact]
    public async Task Built_in_scenarios_are_listed_and_copied_without_a_bearer()
    {
        var handler = new Handler { Response = """{"scenarios":[{"name":"Serpent's Coil","modeCompatibility":["coop"]}]}""" };
        var client = new MapApiClient(new HttpClient(handler) { BaseAddress = new("https://example.test/play/") });
        var list = await client.ListBuiltInAsync();
        Assert.Equal("https://example.test/play/api/v1/scenarios", handler.Uri);
        Assert.Null(handler.Authorization);
        Assert.Equal("Serpent's Coil", list.Scenarios[0].Name);
        Assert.Equal([GameMode.Coop], list.Scenarios[0].ModeCompatibility);

        handler.Response = JsonSerializer.Serialize(MapFileCodec.Import(MapFileCodecTests.Legacy), ContractJson.Options);
        var definition = await client.LoadBuiltInAsync("Serpent's Coil");
        Assert.Equal("https://example.test/play/api/v1/scenarios/Serpent%27s%20Coil", handler.Uri);
        Assert.Null(handler.Authorization);
        Assert.Equal("Crossing", definition.Name);
    }

    [Fact]
    public async Task The_players_ready_maps_and_their_previews_need_the_bearer()
    {
        var handler = new Handler { Response = """{"maps":[{"scenarioId":"m","name":"Ember Isle","revisionId":"r3","revision":3,"isLatest":false,"players":4,"updatedAt":"2026-10-27T00:00:00Z"}]}""" };
        var client = new MapApiClient(new HttpClient(handler) { BaseAddress = new("https://example.test/play/") });
        var session = new AuthSession("owner", "user", "secret", "refresh");
        var maps = await client.ListPlayableAsync(session, GameMode.VanillaPvp);
        Assert.Equal("https://example.test/play/api/v1/account/maps/playable/vanillaPvp", handler.Uri);
        Assert.Equal("Bearer secret", handler.Authorization);
        Assert.Equal(new PlayableMap("m", "Ember Isle", "r3", 3, false, 4, "2026-10-27T00:00:00Z"), maps.Maps[0]);

        handler.Response = """{"columns":1,"rows":1,"hexes":[]}""";
        await client.LoadRevisionBoardAsync(session, "r/3");
        Assert.Equal("https://example.test/play/api/v1/account/maps/revisions/r%2F3/board", handler.Uri);
        Assert.Equal("Bearer secret", handler.Authorization);
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
