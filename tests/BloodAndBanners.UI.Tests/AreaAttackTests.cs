namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class AreaAttackTests : BunitContext
{
    public AreaAttackTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection Projection(string ability, string mode)
    {
        var projection = BattleFixtures.Projection();
        var caster = projection.Units[0] with { Hex = "0,0", Abilities = [new AbilityProjection(ability, "Attack", "Area attack") { Playable = true }] };
        var enemy = projection.Units[1];
        var action = JsonSerializer.Deserialize<LegalAction>(JsonSerializer.Serialize(new
        {
            kind = "specialAttack", unitId = caster.UnitId, ability, targetHexes = new[] { "2,0", "2,1", "3,-1" },
            targetSelection = new { mode, maxTargets = 3, secondaryTargets = new Dictionary<string, string[]> { ["2,0"] = ["2,1", "3,-1"] } },
        }), ContractJson.Options)!;
        return projection with
        {
            Board = new BoardProjection(6, 4,
            [
                new(0, 0, "plains", "grass", 0, false, caster.UnitId),
                new(2, 0, "plains", "grass", 0, false, "a"),
                new(2, 1, "plains", "grass", 0, false, "b"),
                new(3, -1, "plains", "grass", 0, false, "c"),
                new(5, 0, "plains", "grass", 0, false, "d"),
                new(1, 1, "plains", "grass", 0, false, "friend"),
            ]),
            Units = [caster, enemy with { UnitId = "a", Hex = "2,0" }, enemy with { UnitId = "b", Hex = "2,1" },
                enemy with { UnitId = "c", Hex = "3,-1" }, enemy with { UnitId = "d", Hex = "5,0" }, caster with { UnitId = "friend", Hex = "1,1" }],
            LegalActions = [action],
        };
    }

    [Fact]
    public void Frost_selection_previews_power_limits_targets_and_supports_undo()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection("Frost Storm", "independent"))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("button[data-use-ability='Frost Storm']").Click();
        Assert.True(cut.Find("[data-action=confirmTargets]").HasAttribute("disabled"));
        cut.Find("[data-hex='2,0']").Click();
        Assert.Contains("Power 5", cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='2,1']").Click();
        Assert.Contains("Power 4 each", cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='3,-1']").Click();
        Assert.Contains("Power 3 each", cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='5,0']").Click();
        cut.Find("[data-hex='2,0']").Click();
        Assert.Empty(sent);
        Assert.Contains("picked-target", cut.Find("[data-hex='3,-1']").ClassList);
        cut.Find("[data-action=undoTarget]").Click();
        Assert.DoesNotContain("picked-target", cut.Find("[data-hex='3,-1']").ClassList);
        Assert.Contains("picked-target", cut.Find("[data-hex='2,0']").ClassList);
        cut.Find("[data-action=confirmTargets]").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Frost Storm", payload.GetProperty("ability").GetString());
        Assert.Equal(new[] { "a", "b" }, payload.GetProperty("targetUnitIds").EnumerateArray().Select(x => x.GetString()));
    }

    [Theory]
    [InlineData(0)]
    [InlineData(1)]
    [InlineData(2)]
    public void Pyromancer_picks_a_primary_then_only_projected_secondary_enemies(int extras)
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection("Fireball", "adjacentEnemies"))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Fireball']").Click();
        cut.Find("[data-hex='2,0']").Click();
        Assert.Empty(sent);
        Assert.Contains("ability-target", cut.Find("[data-hex='2,1']").ClassList);
        Assert.DoesNotContain("ability-target", cut.Find("[data-hex='1,1']").ClassList);
        Assert.DoesNotContain("ability-target", cut.Find("[data-hex='5,0']").ClassList);
        cut.Find("[data-hex='1,1']").Click();
        cut.Find("[data-hex='5,0']").Click();
        if (extras >= 1) cut.Find("[data-hex='2,1']").Click();
        if (extras >= 2) cut.Find("[data-hex='3,-1']").Click();
        cut.Find("[data-action=confirmTargets]").Click();
        var payload = JsonSerializer.SerializeToElement(Assert.Single(sent).Payload);
        Assert.Equal(new[] { "a", "b", "c" }.Take(extras + 1), payload.GetProperty("targetUnitIds").EnumerateArray().Select(x => x.GetString()));
    }

    [Fact]
    public void Cancelling_or_receiving_a_new_game_version_clears_the_target_selection()
    {
        var sent = new List<GameCommandRequest>();
        var projection = Projection("Frost Storm", "independent");
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Frost Storm']").Click();
        cut.Find("[data-hex='2,0']").Click();
        cut.Find("[data-action=cancelTargeting]").Click();
        Assert.Empty(sent);
        cut.Find("[data-use-ability='Frost Storm']").Click();
        Assert.True(cut.Find("[data-action=confirmTargets]").HasAttribute("disabled"));
        cut.Find("[data-hex='2,0']").Click();
        cut.Render(p => p.Add(c => c.Projection, projection with { Version = projection.Version + 1 }));
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
        Assert.Empty(sent);
    }

    [Fact]
    public void Cooling_down_abilities_show_their_status_on_the_card()
    {
        var ability = JsonSerializer.Deserialize<AbilityProjection>("""{"name":"Frost Storm","type":"Attack","description":"Power 5","playable":true,"coolingDown":true}""", ContractJson.Options)!;
        var cut = Render<AbilityList>(p => p.Add(c => c.Abilities, [ability]));
        Assert.Contains("Cooling down", cut.Find("[data-ability='Frost Storm']").TextContent);
        Assert.Empty(cut.FindAll("button"));
    }
}
