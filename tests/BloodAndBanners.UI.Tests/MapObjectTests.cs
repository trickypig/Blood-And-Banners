using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class MapObjectTests : BunitContext
{
    private const string Images = "https://example.test/blood/";

    public MapObjectTests()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        Services.AddSingleton<IMapApiClient>(new MapEditorTests.EditorMapApi());
        JSInterop.Mode = JSRuntimeMode.Loose;
    }

    [Fact]
    public void The_catalog_sorts_every_kind_into_its_category_with_its_own_images()
    {
        Assert.Equal(["obstacle", "landmark", "nexus", "item"], MapObjectArt.Categories);
        Assert.Equal(["tree", "rock"], MapObjectArt.KindsIn("obstacle"));
        Assert.Equal(["shrine", "fire-crystal", "forest-crystal", "ice-crystal"], MapObjectArt.KindsIn("landmark"));
        Assert.Equal(["rune-of-magic", "rune-of-strength", "rune-of-warding"], MapObjectArt.KindsIn("nexus"));
        Assert.Equal(["power-potion", "speed-potion", "health-potion"], MapObjectArt.KindsIn("item"));
        Assert.Equal((true, true), (MapObjectArt.BlocksSight("rock"), MapObjectArt.Impassable("rock")));
        Assert.Equal((false, true), (MapObjectArt.BlocksSight("shrine"), MapObjectArt.Impassable("shrine")));
        Assert.Equal((false, false), (MapObjectArt.BlocksSight("health-potion"), MapObjectArt.Impassable("rune-of-magic")));
        Assert.Equal("Fire Crystal", MapObjectArt.Display("fire-crystal"));
        Assert.Equal("Nexus Point", MapObjectArt.CategoryDisplay("nexus"));

        var picks = Enumerable.Range(0, 10).SelectMany(q => Enumerable.Range(0, 10).Select(r => MapObjectArt.Variant("tree", q, r))).ToList();
        Assert.Equal([1, 2, 3, 4], picks.Distinct().Order());
        Assert.Equal(MapObjectArt.Variant("rock", 3, 5), MapObjectArt.Variant("rock", 3, 5));
        Assert.Equal("images/landmarks/shrine-1.png", MapObjectArt.ImagePath("shrine", 3, 5));
        Assert.Equal("images/obstacles/rock-2.png", MapObjectArt.ImagePath("rock", 2));
        Assert.Equal("images/nexus/rune-of-warding-1.png", MapObjectArt.ImagePath("rune-of-warding", 4, 1));
        Assert.Equal("images/items/speed-potion-1.png", MapObjectArt.ImagePath("speed-potion"));
        Assert.Null(MapObjectArt.ImagePath("statue", 1));
        Assert.Null(MapObjectArt.CategoryOf("statue"));
    }

    [Theory]
    [InlineData("rock", "obstacle", "blocked by rock")]
    [InlineData("ice-crystal", "landmark", "landmark: ice crystal")]
    [InlineData("rune-of-magic", "nexus", "nexus point: rune of magic")]
    [InlineData("health-potion", "item", "item: health potion")]
    public void The_battlefield_draws_each_category_on_its_hex(string kind, string category, string label)
    {
        var fixture = BattleFixtures.Projection();
        var hexes = fixture.Board.Hexes.Select(hex => (hex.Q, hex.R) == (4, 4) ? hex with { Object = kind } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var drawn = Assert.Single(cut.FindAll("[data-map-object]"));
        Assert.Equal((kind, category), (drawn.GetAttribute("data-map-object"), drawn.GetAttribute("data-category")));
        Assert.Equal($"{Images}{MapObjectArt.ImagePath(kind, 4, 4)}", drawn.QuerySelector("image")!.GetAttribute("href"));
        Assert.Contains(label, cut.Find("[data-hex='4,4']").GetAttribute("aria-label"));
    }

    [Fact]
    public void Nexus_points_and_items_are_drawn_before_a_unit_standing_on_them()
    {
        var fixture = BattleFixtures.Projection();
        var occupied = fixture.Board.Hexes.First(hex => hex.OccupantId is not null);
        var hexes = fixture.Board.Hexes.Select(hex => hex == occupied ? hex with { Object = "power-potion" } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var markup = cut.Markup;
        var potion = markup.IndexOf("data-map-object=\"power-potion\"", StringComparison.Ordinal);
        var token = markup.IndexOf("unit-token", Math.Max(potion, 0), StringComparison.Ordinal);
        Assert.True(potion >= 0 && token > potion, "the potion is painted before (beneath) the unit token");
    }
}
