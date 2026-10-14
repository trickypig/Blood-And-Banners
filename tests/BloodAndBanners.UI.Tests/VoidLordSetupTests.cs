namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class VoidLordSetupTests : BunitContext
{
    public VoidLordSetupTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static SetupProjection Setup(int spent = 0) => new(12, spent, false, ["5,0", "6,0"],
    [
        new MonsterCard("Ice Wolf", "Ice", "common", 1, "", 4, 1, 2, 2, 2),
        new MonsterCard("Frost Wyrm", "Ice", "boss", 4, "", 3, 3, 6, 5, 10),
    ]);

    private static GameProjection SetupTable(string viewer, params LegalAction[] actions) => new(
        "game-1", 4, GameLifecycle.Setup, GameMode.Coop,
        new ViewerPermissions("player", viewer, true, viewer == "seat-void"),
        new BoardProjection(7, 1, Enumerable.Range(0, 7).Select(q => new HexProjection(q, 0, "ice", "snow", 0, false, q == 6 ? "v-m1" : null)).ToList()),
        new TurnProjection(0, null, 0), null, actions, new Dictionary<string, int>(), null)
    {
        ScenarioName = "Void Reaches",
        Setup = Setup(1),
        Memberships = [new MembershipProjection("seat-void", 1, true, true) { Role = "voidLord" }, new MembershipProjection("seat-hero", 2, true, false)],
        Sides = new Dictionary<string, SideProjection> { ["heroes"] = new("heroes", ["seat-hero"]), ["void"] = new("void", ["seat-void"]) },
        Units = [new UnitProjection("v-m1", "seat-void", false, "Ice Wolf", "", 4, 1, 2, 2, 2, 0, "6,0", false) { Side = "void", Tier = "common" }],
    };

    [Fact]
    public void The_roster_shows_costs_and_disables_what_the_budget_cannot_afford()
    {
        var cut = Render<VoidLordSetup>(parameters => parameters
            .Add(c => c.Setup, Setup(spent: 9))
            .Add(c => c.IsVoidLord, true));

        Assert.Contains("9 / 12", cut.Find("[data-testid=setup-budget]").TextContent);
        Assert.False(cut.Find("[data-monster='Ice Wolf']").HasAttribute("disabled"));
        Assert.True(cut.Find("[data-monster='Frost Wyrm']").HasAttribute("disabled"));
    }

    [Fact]
    public void Hero_players_see_a_waiting_view()
    {
        var cut = Render<VoidLordSetup>(parameters => parameters
            .Add(c => c.Setup, Setup())
            .Add(c => c.IsVoidLord, false)
            .Add(c => c.MonstersPlaced, 3));

        Assert.Contains("The Void Lord is placing monsters", cut.Markup);
        Assert.Contains("3 monsters placed", cut.Markup);
        Assert.Empty(cut.FindAll("[data-monster]"));
    }

    [Fact]
    public void The_void_lord_places_on_a_spawn_hex_and_removes_by_clicking_a_monster()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(c => c.Projection, SetupTable("seat-void",
                new LegalAction("placeMonster", null, ["5,0"]),
                new LegalAction("removeMonster", "v-m1", ["6,0"]),
                new LegalAction("confirmSetup", null, [])))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Contains("spawn-zone", cut.Find("[data-hex='5,0']").ClassList);
        cut.Find("[data-monster='Ice Wolf']").Click();
        Assert.Contains("placeable", cut.Find("[data-hex='5,0']").ClassList);
        cut.Find("[data-hex='5,0']").Click();
        cut.Find("[data-hex='6,0']").Click();
        cut.Find("[data-action=confirmSetup]").Click();

        Assert.Equal(new[] { "placeMonster", "removeMonster", "confirmSetup" }, sent.Select(request => request.Kind));
        var place = JsonSerializer.SerializeToElement(sent[0].Payload);
        Assert.Equal("Ice Wolf", place.GetProperty("monster").GetString());
        Assert.Equal("5,0", place.GetProperty("hex").GetString());
        Assert.Equal("v-m1", JsonSerializer.SerializeToElement(sent[1].Payload).GetProperty("unitId").GetString());
    }
}
