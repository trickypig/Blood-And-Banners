namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class CoopTableTests : BunitContext
{
    public CoopTableTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static IReadOnlyList<MembershipProjection> Seats() =>
    [
        new MembershipProjection("seat-void", 1, true, true) { Role = "voidLord" },
        new MembershipProjection("seat-a", 2, true, false),
        new MembershipProjection("seat-b", 3, true, false),
    ];

    private static Dictionary<string, SideProjection> Sides() => new()
    {
        ["heroes"] = new("heroes", ["seat-a", "seat-b"]),
        ["void"] = new("void", ["seat-void"]),
    };

    private static GameProjection Battle(string viewer, string active, string? responding = null, GameResult? result = null) => new(
        "game-1", 10, result is null ? GameLifecycle.Active : GameLifecycle.Completed, GameMode.Coop,
        new ViewerPermissions("player", viewer, true, false),
        new BoardProjection(3, 1, [new HexProjection(0, 0, "plains", "grass", 0, false, "s2-hero1"), new HexProjection(1, 0, "plains", "grass", 0, false, "s3-hero1"), new HexProjection(2, 0, "plains", "grass", 0, false, "v-m1")]),
        new TurnProjection(2, active, 5) { RespondingToSeatId = responding }, null, [], new Dictionary<string, int>(), result)
    {
        ScenarioName = "Void Reaches",
        Memberships = Seats(),
        Sides = Sides(),
        Units =
        [
            new UnitProjection("s2-hero1", "seat-a", true, "Thorgrim", "", 2, 1, 4, 4, 8, 0, "0,0", false) { Side = "heroes" },
            new UnitProjection("s3-hero1", "seat-b", true, "Ignar", "", 3, 3, 4, 2, 6, 0, "1,0", false) { Side = "heroes" },
            new UnitProjection("v-m1", "seat-void", false, "Ice Wolf", "", 4, 1, 2, 2, 2, 0, "2,0", false) { Side = "void", Tier = "common" },
        ],
    };

    [Fact]
    public void The_turn_banner_names_who_the_void_lord_answers()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Battle("seat-a", "seat-void", responding: "seat-b")));
        Assert.Contains("Void Lord's turn — answering Commander 3", cut.Find("[data-testid=turn-status]").TextContent);
    }

    [Fact]
    public void Rosters_and_tokens_use_a_colour_per_seat_and_a_void_palette()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Battle("seat-a", "seat-a")));
        Assert.Contains("seat-void", cut.Find("[data-hex='2,0'] .unit-token").ClassList);
        Assert.Contains("seat-3", cut.Find("[data-hex='1,0'] .unit-token").ClassList);
        Assert.Contains("ally", cut.Find("[data-hex='1,0'] .unit-token").ClassList);
        Assert.Contains("Void Lord's horde", cut.Markup);
        Assert.Contains("Commander 3's army", cut.Markup);
    }

    [Fact]
    public void Co_op_results_are_shared_by_side()
    {
        var heroesWon = new GameResult("monstersDefeated", null, new Dictionary<string, int>()) { WinningSide = "heroes" };
        var asHero = Render<GameTable>(p => p.Add(c => c.Projection, Battle("seat-b", "seat-a", result: heroesWon)));
        Assert.Equal("Victory", asHero.Find("#match-end-title").TextContent);
        Assert.Contains("Every monster has fallen.", asHero.Find("[data-testid=match-end]").TextContent);

        var asVoid = Render<GameTable>(p => p.Add(c => c.Projection, Battle("seat-void", "seat-a", result: heroesWon)));
        Assert.Equal("Defeat", asVoid.Find("#match-end-title").TextContent);
    }

    [Fact]
    public void The_co_op_draft_offers_two_hero_slots_and_hides_start_choices()
    {
        var draft = new DraftProjection(
            new Dictionary<string, IReadOnlyList<int>> { ["seat-a"] = [15], ["seat-b"] = [5] },
            "seat-a", null,
            new Dictionary<string, int?>(), new Dictionary<string, string?>(),
            new Dictionary<string, string?> { ["seat-a"] = null, ["seat-b"] = null },
            [new HeroCard("Thorgrim", "", 2, 1, 4, 4, 8, "seat-a"), new HeroCard("Ignar", "", 3, 3, 4, 2, 6, null)],
            [new ArmyCard("Sol Invictus", "Gold", [], null)])
        { HeroesBySeat = new Dictionary<string, IReadOnlyList<string>> { ["seat-a"] = ["Thorgrim"], ["seat-b"] = [] } };
        var projection = Battle("seat-a", "seat-a") with
        {
            Lifecycle = GameLifecycle.Draft,
            Draft = draft,
            LegalActions = [new LegalAction("pickHero", null, []), new LegalAction("pickArmy", null, [])],
        };

        var cut = Render<DraftTable>(p => p.Add(c => c.Projection, projection));

        Assert.Empty(cut.FindAll("[data-action=chooseFirstPlayer]"));
        Assert.Empty(cut.FindAll("[data-action=chooseStartPosition]"));
        Assert.Contains("Your heroes: Thorgrim (1 of 2)", cut.Markup);
        Assert.False(cut.Find("[data-hero='Ignar']").HasAttribute("disabled"));
    }
}
