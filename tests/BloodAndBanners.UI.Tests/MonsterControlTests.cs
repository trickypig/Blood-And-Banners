namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class MonsterControlTests : BunitContext
{
    public MonsterControlTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Fact]
    public void Kick_stun_shows_its_one_power_penalty_on_the_card_and_tooltip()
    {
        var condition = JsonSerializer.Deserialize<ConditionProjection>(
            """{"name":"Stunned","expires":"endOfNextTurn","data":{"powerPenalty":1}}""", ContractJson.Options)!;
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { EffectivePower = 2, Conditions = [condition] }, .. projection.Units.Skip(1)],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        Assert.Contains("−1 Power", cut.Find("[data-condition='Stunned']").TextContent);
        Assert.DoesNotContain("−2 Power", cut.Find("[data-condition='Stunned']").TextContent);
        Assert.Contains("Stunned −1", cut.Find("[data-testid=selected-unit] [data-stat='power']").GetAttribute("title"));
    }

    [Fact]
    public void Slowness_explains_reduced_move_and_the_victim_next_turn_expiry()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { EffectiveMove = 2, Conditions = [new ConditionProjection("Slowed", "endOfNextTurn")] }, .. projection.Units.Skip(1)],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        Assert.Contains("−1 Move", cut.Find("[data-condition='Slowed']").TextContent);
        Assert.Contains("until the end of its next turn", cut.Find("[data-condition='Slowed']").TextContent);
        Assert.Contains("Slowed −1", cut.Find("[data-testid=selected-unit] [data-stat='move']").GetAttribute("title"));
        Assert.Single(cut.FindAll("[data-hex='1,4'] [data-condition-marker='Slowed']"));
    }
}
