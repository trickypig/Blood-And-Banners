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
        Assert.Equal(["rune-of-focus", "rune-of-strength", "rune-of-warding"], MapObjectArt.KindsIn("nexus"));
        Assert.Equal(["power-potion", "speed-potion", "health-potion"], MapObjectArt.KindsIn("item"));
        Assert.Equal((true, true), (MapObjectArt.BlocksSight("rock"), MapObjectArt.Impassable("rock")));
        Assert.Equal((false, true), (MapObjectArt.BlocksSight("shrine"), MapObjectArt.Impassable("shrine")));
        Assert.Equal((false, false), (MapObjectArt.BlocksSight("health-potion"), MapObjectArt.Impassable("rune-of-focus")));
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
    [InlineData("rune-of-focus", "nexus", "nexus point: rune of focus")]
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

    [Fact]
    public void A_hex_holds_one_thing_and_each_placement_replaces_the_last()
    {
        var doc = new MapEditorDocument();
        doc.SetPlayerStart(0, 0, 1);
        doc.SetObject(0, 0, "tree");
        Assert.Equal(("tree", true, (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker, doc.SelectedHex.PlayerStart));

        doc.SetObject(0, 0, "fire-crystal");
        Assert.Equal(("fire-crystal", false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));
        doc.SetObject(0, 0, "rune-of-strength");
        Assert.Equal(("rune-of-strength", false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));
        doc.SetObject(0, 0, "speed-potion");
        Assert.Equal("speed-potion", doc.SelectedHex!.Object);

        doc.SetPlayerStart(0, 0, 2);
        Assert.Equal((null, false, (int?)2), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker, doc.SelectedHex.PlayerStart));
        doc.SetObject(0, 0, "health-potion");
        Assert.Equal(("health-potion", (int?)null), (doc.SelectedHex!.Object, doc.SelectedHex.PlayerStart));

        Assert.Throws<ArgumentOutOfRangeException>(() => doc.SetObject(0, 0, "statue"));
        doc.SetObject(0, 0, null);
        Assert.Equal((null, false), (doc.SelectedHex!.Object, doc.SelectedHex.SightBlocker));

        doc.SetObject(1, 0, "rock");
        doc.SetObject(2, 0, "rune-of-focus");
        doc.Erase(1, 0);
        doc.Erase(2, 0);
        Assert.All(doc.Definition.Map.Hexes, h => Assert.Equal((null, false, (int?)null), (h.Object, h.SightBlocker, h.PlayerStart)));
    }

    [Theory]
    [InlineData("obstacle", new[] { "tree", "rock" }, "Obstacle: Tree")]
    [InlineData("landmark", new[] { "shrine", "fire-crystal", "forest-crystal", "ice-crystal" }, "Landmark: Shrine")]
    [InlineData("nexus", new[] { "rune-of-focus", "rune-of-strength", "rune-of-warding" }, "Nexus Point: Rune of Focus")]
    [InlineData("item", new[] { "power-potion", "speed-potion", "health-potion" }, "Item: Power Potion")]
    public void Each_category_button_cycles_its_kinds_and_places_them(string category, string[] kinds, string info)
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        var button = () => cut.Find($".object-button[data-category='{category}']");
        Assert.Equal("false", button().GetAttribute("aria-pressed"));

        button().Click();
        Assert.Equal(("true", kinds[0]), (button().GetAttribute("aria-pressed"), button().GetAttribute("data-object")));
        Assert.Equal($"{Images}{MapObjectArt.ImagePath(kinds[0])}", button().QuerySelector("img")!.GetAttribute("src"));
        cut.Find("[data-hex='0,0']").Click();
        var drawn = cut.Find("[data-hex='0,0'] [data-map-object]");
        Assert.Equal((kinds[0], category), (drawn.GetAttribute("data-map-object"), drawn.GetAttribute("data-category")));
        Assert.Contains(info, cut.Find(".hex-info").TextContent);

        foreach (var kind in kinds.Skip(1).Append(kinds[0]))
        {
            button().Click();
            Assert.Equal(kind, button().GetAttribute("data-object"));
        }
        Assert.Equal(4, cut.FindAll(".object-button").Count);
    }

    [Fact]
    public void Erase_removes_starts_and_every_kind_of_object()
    {
        var cut = Render<MapEditor>(parameters => parameters.Add(component => component.ImageBaseUrl, Images));
        void Place(string selector, string hex) { cut.Find(selector).Click(); cut.Find($"[data-hex='{hex}']").Click(); }
        Place(".object-button[data-category='obstacle']", "0,0");
        Place(".object-button[data-category='landmark']", "1,0");
        Place(".object-button[data-category='nexus']", "2,0");
        Place(".object-button[data-category='item']", "3,0");
        Place(".player-button", "0,1");
        Place(".object-button[data-category='item']", "0,1");
        Assert.Empty(cut.FindAll(".start-label"));
        Place(".player-button", "1,1");
        Assert.Single(cut.FindAll(".start-label"));

        cut.Find("[data-action='erase']").Click();
        foreach (var hex in new[] { "0,0", "1,0", "2,0", "3,0", "0,1", "1,1" }) cut.Find($"[data-hex='{hex}']").Click();
        Assert.Empty(cut.FindAll("[data-map-object]"));
        Assert.Empty(cut.FindAll(".start-label"));
    }

    [Fact]
    public void A_claimed_crystal_shows_its_owner()
    {
        var fixture = BattleFixtures.Projection();
        var hexes = fixture.Board.Hexes.Select(hex => (hex.Q, hex.R) == (4, 4) ? hex with { Object = "fire-crystal", Impassable = true, ControlledBy = "seat-blue" } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var ring = cut.Find("[data-hex='4,4'] .crystal-ring");
        Assert.Equal("seat-blue", ring.GetAttribute("data-controlled-by"));
        Assert.Contains("seat-2", ring.ClassList);
        Assert.Contains("controlled by Rival", cut.Find("[data-hex='4,4']").GetAttribute("aria-label"));
    }

    [Fact]
    public void Saved_maps_with_the_old_rune_of_magic_open_as_rune_of_focus()
    {
        Assert.Equal("rune-of-focus", MapObjectArt.Renamed("rune-of-magic"));
        Assert.Equal("tree", MapObjectArt.Renamed("tree"));
        Assert.Null(MapObjectArt.Renamed(null));

        var doc = new MapEditorDocument();
        var hexes = doc.Definition.Map.Hexes.Select((h, i) => i == 0 ? h with { Object = "rune-of-magic" } : h).ToList();
        var json = MapFileCodec.ExportCanonical(doc.Definition with { Map = doc.Definition.Map with { Hexes = hexes } });
        doc.Import(json);
        Assert.Equal("rune-of-focus", doc.Definition.Map.Hexes[0].Object);
    }
}
