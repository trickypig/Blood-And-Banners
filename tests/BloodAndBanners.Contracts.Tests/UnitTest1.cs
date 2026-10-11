namespace BloodAndBanners.Contracts.Tests;

public sealed class ContractFixtureTests
{
    [Fact]
    public void GameProjection_fixture_round_trips_with_camel_case_contract()
    {
        var json = File.ReadAllText(FixturePath("game-projection.json"));

        var projection = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameProjection>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(projection);
        Assert.Equal("game-fixture", projection.GameId);
        Assert.Equal(global::BloodAndBanners.Contracts.GameLifecycle.Active, projection.Lifecycle);
        Assert.Equal(global::BloodAndBanners.Contracts.GameMode.VanillaPvp, projection.Mode);
        Assert.True(projection.Viewer.CanCommand);
        Assert.Equal("unit-thorgrim", projection.Board.Hexes.Single().OccupantId);
        Assert.Equal("moveUnit", projection.LegalActions.Single().Kind);

        using var serialized = System.Text.Json.JsonDocument.Parse(
            System.Text.Json.JsonSerializer.Serialize(projection, global::BloodAndBanners.Contracts.ContractJson.Options));
        Assert.True(serialized.RootElement.TryGetProperty("gameId", out _));
        Assert.False(serialized.RootElement.TryGetProperty("GameId", out _));
        Assert.Equal("active", serialized.RootElement.GetProperty("lifecycle").GetString());
    }

    [Fact]
    public void CommandEnvelope_preserves_arbitrary_payload_for_the_authoritative_server()
    {
        var json = File.ReadAllText(FixturePath("command-envelope.json"));

        var command = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.CommandEnvelope>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(command);
        Assert.Equal(7, command.ExpectedVersion);
        Assert.Equal("moveUnit", command.Kind);
        Assert.Equal("unit-thorgrim", command.Payload.GetProperty("unitId").GetString());
        Assert.Equal(2, command.Payload.GetProperty("path").GetArrayLength());
    }

    private static string FixturePath(string name) =>
        Path.Combine(AppContext.BaseDirectory, "fixtures", name);
}
