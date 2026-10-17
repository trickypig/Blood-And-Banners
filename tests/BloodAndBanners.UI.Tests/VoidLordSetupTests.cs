namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class VoidLordSetupTests : BunitContext
{
    public VoidLordSetupTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Fact]
    public void Selected_monster_shows_ability_descriptions_and_marks_only_unsupported_abilities_deferred()
    {
        var setup = Setup() with
        {
            Roster =
            [
                new MonsterCard("Marsh Hydra", "Poison", "boss", 4, "", 3, 1, 4, 3, 8)
                {
                    Abilities =
                    [
                        new AbilityProjection("Outrage", "Essence", "+2 Power at 1 HP") { Playable = true },
                        new AbilityProjection("Multiple Heads", "Essence", "One attack per HP remaining") { Playable = false },
                    ],
                },
            ],
        };
        var cut = Render<VoidLordSetup>(p => p
            .Add(c => c.Setup, setup)
            .Add(c => c.IsVoidLord, true)
            .Add(c => c.SelectedMonster, "Marsh Hydra"));

        Assert.Contains("+2 Power at 1 HP", cut.Find("[data-ability='Outrage']").TextContent);
        Assert.DoesNotContain("deferred", cut.Find("[data-ability='Outrage']").ClassList);
        Assert.Contains("Not yet in the digital game", cut.Find("[data-ability='Multiple Heads']").TextContent);
    }

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

    [Fact]
    public void Placement_highlights_only_the_selected_monster_habitat()
    {
        var sent = new List<GameCommandRequest>();
        var riverAction = JsonSerializer.Deserialize<LegalAction>("""{"kind":"placeMonster","unitId":null,"monster":"Ice Wolf","targetHexes":["4,0"]}""", ContractJson.Options)!;
        var landAction = JsonSerializer.Deserialize<LegalAction>("""{"kind":"placeMonster","unitId":null,"monster":"Frost Wyrm","targetHexes":["5,0"]}""", ContractJson.Options)!;
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, SetupTable("seat-void", riverAction, landAction))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-monster='Ice Wolf']").Click();
        Assert.Contains("placeable", cut.Find("[data-hex='4,0']").ClassList);
        Assert.DoesNotContain("placeable", cut.Find("[data-hex='5,0']").ClassList);
        cut.Find("[data-hex='5,0']").Click();
        Assert.Empty(sent);
        cut.Find("[data-hex='4,0']").Click();
        Assert.Single(sent);
    }

    [Fact]
    public void Over_budget_shows_a_warning_and_the_lobby_hides_confirm()
    {
        var back = 0;
        var cut = Render<VoidLordSetup>(p => p
            .Add(c => c.Setup, Setup(spent: 14))
            .Add(c => c.IsVoidLord, true)
            .Add(c => c.ShowConfirm, false)
            .Add(c => c.ShowBackToWarRoom, true)
            .Add(c => c.StatusLine, "War room · 1 hero, 0 ready")
            .Add(c => c.OnBackToWarRoom, () => back++));

        Assert.Contains("Over budget by 2", cut.Find("[data-testid=setup-over-budget]").TextContent);
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
        Assert.Contains("War room · 1 hero, 0 ready", cut.Find("[data-testid=setup-status]").TextContent);
        cut.Find("[data-action=back-to-war-room]").Click();
        Assert.Equal(1, back);
    }

    [Fact]
    public void A_confirmed_horde_waits_without_the_roster()
    {
        var cut = Render<VoidLordSetup>(p => p
            .Add(c => c.Setup, Setup(spent: 3) with { Confirmed = true })
            .Add(c => c.IsVoidLord, true)
            .Add(c => c.StatusLine, "Waiting for the heroes to finish drafting · 2/3 picks"));

        Assert.NotNull(cut.Find("[data-testid=setup-ready]"));
        Assert.Empty(cut.FindAll("[data-monster]"));
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
    }

    [Fact]
    public void During_the_draft_the_void_lord_keeps_the_setup_board_with_hero_progress()
    {
        var projection = SetupTable("seat-void", new LegalAction("confirmSetup", null, [])) with { Lifecycle = GameLifecycle.Draft };
        projection = projection with
        {
            Draft = new DraftProjection(new Dictionary<string, IReadOnlyList<int>>(), "seat-hero", null,
                new Dictionary<string, int?>(), new Dictionary<string, string?>(), new Dictionary<string, string?>(), [], [])
            { PicksMade = 2, PicksTotal = 3, Redacted = true },
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));

        Assert.Contains("Heroes drafting · 2/3 picks", cut.Find("[data-testid=setup-status]").TextContent);
        Assert.NotNull(cut.Find("[data-action=confirmSetup]"));
    }

    [Fact]
    public void In_the_war_room_the_setup_board_offers_the_way_back_and_no_confirm()
    {
        var projection = SetupTable("seat-void", new LegalAction("placeMonster", null, ["5,0"])) with { Lifecycle = GameLifecycle.Lobby };
        var back = 0;
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection).Add(c => c.OnBackToWarRoom, () => back++));

        Assert.Contains("War room · 1 hero, 1 ready", cut.Find("[data-testid=setup-status]").TextContent);
        Assert.Empty(cut.FindAll("[data-action=confirmSetup]"));
        cut.Find("[data-action=back-to-war-room]").Click();
        Assert.Equal(1, back);
    }

    [Fact]
    public void A_redacted_setup_never_opens_the_setup_board()
    {
        var projection = SetupTable("seat-hero") with { Lifecycle = GameLifecycle.Draft, Setup = Setup() with { Redacted = true } };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));

        Assert.Empty(cut.FindAll("[data-testid=setup-budget]"));
        Assert.DoesNotContain("The Void Lord is placing monsters", cut.Markup);
    }
}
