namespace BloodAndBanners.Contracts.Tests;

using System.Text.Json;

public sealed class AreaAttackContractTests
{
    [Fact]
    public void Multi_target_selection_and_cooldown_round_trip_without_breaking_old_actions()
    {
        var action = JsonSerializer.Deserialize<LegalAction>("""{"kind":"specialAttack","unitId":"pyro","ability":"Fireball","targetHexes":["2,0"],"targetSelection":{"mode":"adjacentEnemies","maxTargets":3,"secondaryTargets":{"2,0":["2,1"]}}}""", ContractJson.Options)!;
        var json = JsonSerializer.SerializeToElement(action, ContractJson.Options);
        Assert.True(json.TryGetProperty("targetSelection", out var selection));
        Assert.Equal("adjacentEnemies", selection.GetProperty("mode").GetString());
        Assert.Equal("2,1", selection.GetProperty("secondaryTargets").GetProperty("2,0")[0].GetString());
        var ability = JsonSerializer.Deserialize<AbilityProjection>("""{"name":"Frost Storm","type":"Attack","description":"","playable":true,"coolingDown":true}""", ContractJson.Options)!;
        Assert.True(JsonSerializer.SerializeToElement(ability, ContractJson.Options).GetProperty("coolingDown").GetBoolean());
        Assert.NotNull(JsonSerializer.Deserialize<LegalAction>("""{"kind":"specialAttack","unitId":"old","targetHexes":[]}""", ContractJson.Options));
    }
}
