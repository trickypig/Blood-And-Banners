namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class ColumnAttackTests : BunitContext
{
    public ColumnAttackTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection Projection()
    {
        var p = BattleFixtures.Projection();
        var treant = p.Units[0] with
        {
            Name = "Treant", Hex = "0,0",
            Abilities = [
                new("Column Swing", "Attack", "Power 5, Range 2") { Playable = true },
                new("Column Toss", "Attack", "Power 7, Range 4. Once per match; unavailable within 2 hexes") { Playable = true },
            ],
        };
        var near = p.Units[1] with { UnitId = "near", Hex = "2,0" };
        var far = p.Units[1] with { UnitId = "far", Hex = "0,3" };
        var hexes = Enumerable.Range(0, 5).SelectMany(q => Enumerable.Range(0, 5).Select(r =>
            new HexProjection(q, r, "plains", "grass", 0, false,
                q == 0 && r == 0 ? treant.UnitId : q == 2 && r == 0 ? near.UnitId : q == 0 && r == 3 ? far.UnitId : null))).ToList();
        return p with
        {
            Board = new BoardProjection(5, 5, hexes),
            Units = [treant, near, far],
            LegalActions = [
                new("specialAttack", treant.UnitId, ["2,0"]) { Ability = "Column Swing" },
                new("specialAttack", treant.UnitId, ["0,3"]) { Ability = "Column Toss" },
            ],
        };
    }

    [Theory]
    [InlineData("Column Swing", "2,0", "0,3", "near", "within 2 hexes")]
    [InlineData("Column Toss", "0,3", "2,0", "far", "3–4 hexes")]
    public void Column_attacks_guide_targeting_and_send_only_a_projected_target(string ability, string target, string invalidTarget, string targetId, string guidance)
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find($"[data-use-ability='{ability}']").Click();
        Assert.Contains(guidance, cut.Find("[data-testid=targeting-banner]").TextContent);
        if (ability == "Column Toss")
        {
            Assert.Contains("permanently", cut.Find("[data-testid=targeting-banner]").TextContent);
        }
        Assert.Contains("ability-target", cut.Find($"[data-hex='{target}']").ClassList);
        Assert.DoesNotContain("ability-target", cut.Find($"[data-hex='{invalidTarget}']").ClassList);
        cut.Find($"[data-hex='{invalidTarget}']").Click();
        Assert.Empty(sent);
        cut.Find($"[data-hex='{target}']").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal(ability, payload.GetProperty("ability").GetString());
        Assert.Equal(targetId, payload.GetProperty("targetUnitIds")[0].GetString());
    }

    [Fact]
    public void Reconnecting_after_toss_removes_both_buttons_and_keeps_the_consumed_column_explanation()
    {
        var projection = Projection();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Column Toss']").Click();
        var abilities = projection.Units[0].Abilities.Select(a => a with
            { Description = a.Description + " The column has been tossed and is unavailable for the rest of the match." }).ToList();
        projection = projection with
        {
            Version = projection.Version + 1,
            Units = [projection.Units[0] with { Abilities = abilities }, .. projection.Units.Skip(1)],
            LegalActions = [new("attackUnit", projection.Units[0].UnitId, ["2,0"])],
        };
        cut.Render(p => p.Add(c => c.Projection, projection));
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
        Assert.Empty(cut.FindAll("[data-use-ability='Column Swing'], [data-use-ability='Column Toss']"));
        foreach (var ability in new[] { "Column Swing", "Column Toss" })
        {
            Assert.Contains("column has been tossed", cut.Find($"[data-ability='{ability}']").TextContent);
        }
    }
}
