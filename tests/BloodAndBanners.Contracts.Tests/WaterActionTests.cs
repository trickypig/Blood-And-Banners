namespace BloodAndBanners.Contracts.Tests;

using System.Text.Json;

public sealed class WaterActionTests
{
    [Fact]
    public void Species_specific_placement_round_trips_and_old_actions_still_load()
    {
        var action = JsonSerializer.Deserialize<LegalAction>("""{"kind":"placeMonster","unitId":null,"monster":"Kraken","targetHexes":["1,0"]}""", ContractJson.Options)!;
        var json = JsonSerializer.SerializeToElement(action, ContractJson.Options);
        Assert.True(json.TryGetProperty("monster", out var monster));
        Assert.Equal("Kraken", monster.GetString());
        var old = JsonSerializer.Deserialize<LegalAction>("""{"kind":"placeMonster","unitId":null,"targetHexes":["1,0"]}""", ContractJson.Options)!;
        Assert.Equal("placeMonster", old.Kind);
    }
}
