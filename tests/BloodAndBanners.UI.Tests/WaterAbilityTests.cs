namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class WaterAbilityTests : BunitContext
{
    public WaterAbilityTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Fact]
    public void Regeneration_selects_an_empty_projected_hex_and_sends_the_summon_destination()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Tentacle Regeneration", "Attack", "Summon a tentacle.") { Playable = true }] }, .. projection.Units.Skip(1)],
            LegalActions = [new LegalAction("specialAttack", "s1-u3", ["2,4"]) { Ability = "Tentacle Regeneration" }],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Tentacle Regeneration']").Click();
        Assert.Contains("choose adjacent water", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='2,4']").ClassList);
        cut.Find("[data-hex='3,4']").Click();
        Assert.Empty(sent);
        cut.Find("[data-hex='2,4']").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Tentacle Regeneration", payload.GetProperty("ability").GetString());
        Assert.Equal("s1-u3", payload.GetProperty("targetUnitIds")[0].GetString());
        Assert.Equal("2,4", payload.GetProperty("toHex").GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Fact]
    public void Summoned_units_have_a_readable_combat_log_entry()
    {
        var payload = JsonSerializer.SerializeToElement(new { unitId = "tentacle", sourceUnitId = "s1-u3", name = "Kraken Tentacle (Lesser)", hex = "2,4" });
        var gameEvent = new GameEvent(1, 1, "unitSummoned", null, ["2,4"], payload, DateTimeOffset.UnixEpoch);
        Assert.Equal("Ranger summons Kraken Tentacle (Lesser) at 2,4.", CombatLogFormatter.Describe(gameEvent, BattleFixtures.Projection()));
    }
}
