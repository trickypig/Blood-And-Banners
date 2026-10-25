using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class ObstacleTests : BunitContext
{
    private const string Images = "https://example.test/blood/";

    public ObstacleTests()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        Services.AddSingleton<IMapApiClient>(new MapEditorTests.EditorMapApi());
        JSInterop.Mode = JSRuntimeMode.Loose;
    }

    [Fact]
    public void Each_hex_picks_a_stable_image_and_every_image_gets_used()
    {
        var picks = Enumerable.Range(0, 10).SelectMany(q => Enumerable.Range(0, 10).Select(r => ObstacleArt.Variant("tree", q, r))).ToList();
        Assert.Equal([1, 2, 3, 4], picks.Distinct().Order());
        Assert.Equal(ObstacleArt.Variant("rock", 3, 5), ObstacleArt.Variant("rock", 3, 5));
        Assert.Equal("images/obstacles/shrine-1.png", ObstacleArt.ImagePath("shrine", 3, 5));
        Assert.Equal("images/obstacles/rock-2.png", ObstacleArt.ImagePath("rock", 2));
        Assert.Null(ObstacleArt.ImagePath("statue", 1));
    }

    [Fact]
    public void Obstacles_block_their_hex_and_clearing_or_a_start_removes_them()
    {
        var doc = new MapEditorDocument();
        doc.SetPlayerStart(0, 0, 1);
        doc.SetObstacle(0, 0, "tree");
        var hex = doc.SelectedHex!;
        Assert.Equal(("tree", true, (int?)null), (hex.Object, hex.SightBlocker, hex.PlayerStart));

        doc.SetObstacle(0, 0, "rock");
        Assert.Equal("rock", doc.SelectedHex!.Object);
        doc.SetObstacle(0, 0, null);
        Assert.Equal((null, false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));

        doc.SetObstacle(1, 0, "tree");
        doc.SetPlayerStart(1, 0, 2);
        Assert.Equal((null, false, (int?)2), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker, doc.SelectedHex.PlayerStart));
        Assert.Throws<ArgumentOutOfRangeException>(() => doc.SetObstacle(0, 0, "statue"));

        doc.SetObstacle(2, 0, "rock");
        doc.Erase(1, 0);
        doc.Erase(2, 0);
        Assert.All(doc.Definition.Map.Hexes, h => Assert.Equal((null, false, (int?)null), (h.Object, h.SightBlocker, h.PlayerStart)));
    }

    [Fact]
    public void Editor_add_button_toggles_trees_and_rocks_and_erase_removes_any_object()
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        var add = () => cut.Find(".obstacle-button");
        Assert.Equal("false", add().GetAttribute("aria-pressed"));

        add().Click();
        Assert.Equal("tree", add().GetAttribute("data-obstacle"));
        cut.Find("[data-hex='0,0']").Click();
        var tree = cut.Find("[data-hex='0,0'] [data-obstacle]");
        Assert.Equal("tree", tree.GetAttribute("data-obstacle"));
        Assert.Equal($"{Images}{ObstacleArt.ImagePath("tree", 0, 0)}", tree.QuerySelector("image")!.GetAttribute("href"));
        Assert.Contains("Obstacle: Trees", cut.Find(".hex-info").TextContent);

        add().Click();
        Assert.Equal("rock", add().GetAttribute("data-obstacle"));
        Assert.StartsWith($"{Images}images/obstacles/rock-", add().QuerySelector("img")!.GetAttribute("src"));
        cut.Find("[data-hex='1,0']").Click();
        Assert.Equal("rock", cut.Find("[data-hex='1,0'] [data-obstacle]").GetAttribute("data-obstacle"));

        add().Click();
        Assert.Equal("shrine", add().GetAttribute("data-obstacle"));
        cut.Find("[data-hex='3,0']").Click();
        Assert.Contains("Obstacle: Shrines", cut.Find(".hex-info").TextContent);
        add().Click();
        Assert.Equal("tree", add().GetAttribute("data-obstacle"));
        cut.Find("[data-action='erase']").Click();
        cut.Find("[data-hex='3,0']").Click();

        cut.Find(".player-button").Click();
        cut.Find("[data-hex='2,0']").Click();
        cut.Find("[data-action='erase']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-hex='2,0']").Click();
        Assert.Empty(cut.FindAll("[data-hex='0,0'] [data-obstacle]"));
        Assert.Empty(cut.FindAll(".start-label"));
        Assert.Single(cut.FindAll("[data-obstacle='rock'] image"));
    }

    [Fact]
    public void The_battlefield_stands_obstacles_on_their_hexes()
    {
        var fixture = BattleFixtures.Projection();
        var hexes = fixture.Board.Hexes.Select(hex => (hex.Q, hex.R) == (4, 4) ? hex with { SightBlocker = true, Impassable = true, Object = "rock" } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var obstacle = Assert.Single(cut.FindAll("[data-obstacle]"));
        Assert.Equal("rock", obstacle.GetAttribute("data-obstacle"));
        Assert.Contains("blocked by rocks", cut.Find("[data-hex='4,4']").GetAttribute("aria-label"));
    }
}
