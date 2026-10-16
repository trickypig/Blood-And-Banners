namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class DragonBreathTests : BunitContext
{
    public DragonBreathTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Theory]
    [InlineData(3, false)]
    [InlineData(3, true)]
    [InlineData(1, false)]
    public void Breath_previews_all_adjacent_hexes_and_sends_only_the_primary_target(int targetQ, bool keyboard)
    {
        var p = BattleFixtures.Projection();
        var dragon = p.Units[0] with
        {
            Name = "Void Dragon", Hex = "0,0",
            Abilities = [new("Dragon Breath", "Attack", "Power 6, Range 4. All adjacent units defend against one fewer sword.") { Playable = true }],
        };
        var primary = p.Units[1] with { Hex = $"{targetQ},0" };
        var ally = dragon with { UnitId = "ally", Hex = $"{targetQ + 1},0", Abilities = [] };
        var hexes = Enumerable.Range(-1, 8).SelectMany(q => Enumerable.Range(-2, 7).Select(r =>
            new HexProjection(q, r, "plains", "grass", 0, false,
                q == 0 && r == 0 ? dragon.UnitId : q == targetQ && r == 0 ? primary.UnitId : q == targetQ + 1 && r == 0 ? ally.UnitId : null))).ToList();
        p = p with
        {
            Board = new BoardProjection(8, 7, hexes), Units = [dragon, primary, ally],
            LegalActions = [new("specialAttack", dragon.UnitId, [primary.Hex!]) { Ability = "Dragon Breath" }],
        };
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(a => a.Add(c => c.Projection, p)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Dragon Breath']").Click();
        var banner = cut.Find("[data-testid=targeting-banner]").TextContent;
        Assert.Contains("Range 4", banner);
        Assert.Contains("allies", banner);
        Assert.Contains("one fewer sword", banner);
        var target = cut.Find($"[data-hex='{targetQ},0']");
        if (keyboard) target.Focus(); else target.MouseEnter();
        Assert.Equal(6, cut.FindAll(".splash").Count);
        Assert.Contains("splash", cut.Find($"[data-hex='{targetQ + 1},0']").ClassList);
        Assert.Equal(targetQ == 1, cut.Find("[data-hex='0,0']").ClassList.Contains("splash"));
        target.Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Dragon Breath", payload.GetProperty("ability").GetString());
        Assert.Equal(new[] { primary.UnitId }, payload.GetProperty("targetUnitIds").EnumerateArray().Select(x => x.GetString()));
    }
}
