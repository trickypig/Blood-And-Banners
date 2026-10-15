namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class PushAttackTests : BunitContext
{
    public PushAttackTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection Projection(string ability)
    {
        var p = BattleFixtures.Projection();
        return p with
        {
            Board = new BoardProjection(6, 1, Enumerable.Range(0, 6).Select(q => new HexProjection(q, 0, "plains", "grass", 0, false,
                q == 0 ? "s1-u3" : q == 1 ? "s2-u1" : null)).ToList()),
            Units = [p.Units[0] with { Hex = "0,0", Abilities = [new AbilityProjection(ability, "Attack", "Push attack") { Playable = true }] },
                p.Units[1] with { Hex = "1,0" }],
            LegalActions = [new LegalAction("specialAttack", "s1-u3", ["1,0"]) { Ability = ability }],
        };
    }

    [Theory]
    [InlineData("Tail Slash", 1)]
    [InlineData("Stone Swat", 2)]
    public void Special_attacks_preview_the_correct_push_distance_and_send_the_named_attack(string ability, int distance)
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection(ability))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find($"[data-use-ability='{ability}']").Click();
        cut.Find("[data-hex='1,0']").MouseEnter();
        Assert.Contains("push-path", cut.Find("[data-hex='2,0']").ClassList);
        Assert.Equal(distance == 2, cut.Find("[data-hex='3,0']").ClassList.Contains("push-path"));
        cut.Find("[data-hex='1,0']").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal(ability, payload.GetProperty("ability").GetString());
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitIds")[0].GetString());
    }

    [Fact]
    public void A_blocked_push_preview_stops_at_the_wall()
    {
        var projection = Projection("Stone Swat");
        projection = projection with { Board = projection.Board with { Hexes = projection.Board.Hexes.Select(h => h.Q == 2 ? h with { SightBlocker = true } : h).ToList() } };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Stone Swat']").Click();
        cut.Find("[data-hex='1,0']").MouseEnter();
        Assert.Empty(cut.FindAll(".push-path"));
    }

    [Fact]
    public void After_tail_slash_the_used_marker_remains_and_the_bite_is_selectable()
    {
        var sent = new List<GameCommandRequest>();
        var projection = Projection("Tail Slash");
        projection = projection with
        {
            Turn = projection.Turn with { ActiveUnitId = "s1-u3" },
            Units = [projection.Units[0] with { Abilities = [projection.Units[0].Abilities[0] with { UsedThisTurn = true }] }, projection.Units[1]],
            LegalActions = [new LegalAction("attackUnit", "s1-u3", ["1,0"])],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        Assert.Contains("Used", cut.Find("[data-ability='Tail Slash']").TextContent);
        Assert.Empty(cut.FindAll("[data-use-ability='Tail Slash']"));
        cut.Find("[data-hex='1,0']").Click();
        Assert.Equal("attackUnit", Assert.Single(sent).Kind);
    }
}
