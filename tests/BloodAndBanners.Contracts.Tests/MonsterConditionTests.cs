namespace BloodAndBanners.Contracts.Tests;

using System.Text.Json;

public sealed class MonsterConditionTests
{
    [Fact]
    public void Kick_stun_penalty_survives_the_condition_wire_contract()
    {
        var condition = JsonSerializer.Deserialize<ConditionProjection>(
            """{"name":"Stunned","expires":"endOfNextTurn","data":{"powerPenalty":1}}""", ContractJson.Options)!;
        using var serialized = JsonDocument.Parse(JsonSerializer.Serialize(condition, ContractJson.Options));

        Assert.True(serialized.RootElement.GetProperty("data").TryGetProperty("powerPenalty", out var penalty));
        Assert.Equal(1, penalty.GetInt32());
    }
}
