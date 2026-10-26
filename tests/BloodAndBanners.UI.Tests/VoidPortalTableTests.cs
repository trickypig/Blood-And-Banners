namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class VoidPortalTableTests : BunitContext
{
    public VoidPortalTableTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static UnitProjection Portal(string? hex) =>
        new(VoidPortalArt.UnitId, "seat-void", false, "Void Portal", "/images/monsters/void_portal.png", 0, 5, 5, 0, 15, hex is null ? 15 : 3, hex, false)
        {
            Side = "void", Tier = "boss", AvatarImage = "/images/monsters/void_portal_nobg.png",
        };

    private static GameProjection Battle(GameLifecycle lifecycle = GameLifecycle.Active, string? portalHex = "2,0", GameMode mode = GameMode.Coop, string viewer = "seat-a") => new(
        "game-1", 10, lifecycle, mode,
        new ViewerPermissions("player", viewer, true, false),
        new BoardProjection(3, 1,
        [
            new HexProjection(0, 0, "plains", "grass", 0, false, "s2-hero1") { PlayerStart = 1 },
            new HexProjection(1, 0, "plains", "grass", 0, false, null),
            new HexProjection(2, 0, "plains", "grass", 0, false, portalHex is null ? null : VoidPortalArt.UnitId) { VoidPortal = true },
        ]),
        new TurnProjection(2, "seat-a", 5), null, [], new Dictionary<string, int>(),
        lifecycle == GameLifecycle.Completed ? new GameResult("portalDestroyed", null, new Dictionary<string, int>()) { WinningSide = "heroes" } : null)
    {
        ScenarioName = "Void Reaches",
        Memberships =
        [
            new MembershipProjection("seat-void", 1, true, true) { Role = "voidLord" },
            new MembershipProjection("seat-a", 2, true, false),
        ],
        Sides = new Dictionary<string, SideProjection> { ["heroes"] = new("heroes", ["seat-a"]), ["void"] = new("void", ["seat-void"]) },
        Units =
        [
            new UnitProjection("s2-hero1", "seat-a", true, "Thorgrim", "", 2, 1, 4, 4, 8, 0, "0,0", false) { Side = "heroes" },
            Portal(portalHex),
        ],
    };

    private static string? Phase(IRenderedComponent<GameTable> cut) =>
        cut.FindAll("[data-hex='2,0'] [data-void-portal]").SingleOrDefault()?.GetAttribute("data-void-portal");

    [Fact]
    public void A_standing_portal_loops_active_idle_and_its_token_keeps_only_the_health_plate()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Battle()).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Equal("active", Phase(cut));
        var sheet = cut.Find("[data-hex='2,0'] .void-sheet");
        Assert.EndsWith("images/void-portal/void-gate.png", sheet.GetAttribute("href"));
        Assert.Equal(("2688", "1536"), (sheet.GetAttribute("width"), sheet.GetAttribute("height")));
        Assert.Contains("active", sheet.ClassList);
        Assert.Empty(cut.FindAll("[data-hex='2,0'] .token-figure"));
        Assert.Empty(cut.FindAll("[data-hex='2,0'] .token-initials"));
        Assert.NotNull(cut.Find("[data-hex='2,0'] .hp-fill"));
    }

    [Fact]
    public void Before_battle_the_portal_lies_dormant_and_the_entrance_opens_it()
    {
        var lobby = Render<GameTable>(p => p.Add(c => c.Projection, Battle(GameLifecycle.Lobby)).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Equal("dormant", Phase(lobby));

        var entering = Render<GameTable>(p => p.Add(c => c.Projection, Battle()).Add(c => c.PlayEntrance, true)
            .Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Equal("opening", Phase(entering));
        entering.InvokeAsync(() => entering.Instance.CompletePortalEntrance("game-1"));
        Assert.Equal("active", Phase(entering));
    }

    [Fact]
    public void A_portal_seen_falling_plays_its_closing_run_but_one_already_gone_is_not_drawn()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Battle()).Add(c => c.ImageBaseUrl, "https://example.test/"));
        cut.Render(p => p.Add(c => c.Projection, Battle(GameLifecycle.Completed, portalHex: null) with { Version = 11 }));
        Assert.Equal("closing", Phase(cut));
        Assert.Contains("The Void Portal has been destroyed.", cut.Find("[data-testid=match-end]").TextContent);

        var later = Render<GameTable>(p => p.Add(c => c.Projection, Battle(GameLifecycle.Completed, portalHex: null)).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Null(Phase(later));
    }

    [Fact]
    public void Only_co_op_boards_draw_the_portal()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Battle(mode: GameMode.VanillaPvp)).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Null(Phase(cut));
    }

    [Fact]
    public void The_war_room_count_leaves_out_the_portal()
    {
        var lobby = Battle(GameLifecycle.Lobby, viewer: "seat-void") with
        {
            Setup = new SetupProjection(20, 0, false, [], []),
            Units = [Portal("2,0")],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, lobby).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Equal(0, cut.FindComponent<VoidLordSetup>().Instance.MonstersPlaced);
    }
}
