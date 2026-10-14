namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class MapPreviewTests : BunitContext
{
    [Fact]
    public void Every_hex_is_a_biome_coloured_dot_and_each_start_gets_a_numbered_marker()
    {
        var cut = Render<MapPreview>(parameters => parameters
            .Add(component => component.Board, MapPreviewFixtures.Board())
            .Add(component => component.Name, "Proving Field"));

        Assert.Equal(6, cut.FindAll("circle.map-dot").Count);
        Assert.Equal("#c4381c", cut.Find("circle.map-dot[data-hex='1,1']").GetAttribute("fill"));
        var markers = cut.FindAll("g.map-start");
        Assert.Equal(new[] { "1", "2" }, markers.Select(marker => marker.GetAttribute("data-start")));
        Assert.Equal(new[] { "1", "2" }, markers.Select(marker => marker.TextContent.Trim()));
        Assert.Equal("Proving Field map preview with 2 starting points", cut.Find("svg").GetAttribute("aria-label"));
        Assert.Empty(cut.FindAll("image"));
    }

    [Fact]
    public void The_viewers_start_is_highlighted()
    {
        var cut = Render<MapPreview>(parameters => parameters
            .Add(component => component.Board, MapPreviewFixtures.Board())
            .Add(component => component.Name, "Proving Field")
            .Add(component => component.HighlightStart, 2));

        Assert.DoesNotContain("mine", cut.Find("g.map-start[data-start='1']").ClassList);
        Assert.Contains("mine", cut.Find("g.map-start[data-start='2']").ClassList);
    }

    [Fact]
    public void An_empty_board_renders_nothing()
    {
        var cut = Render<MapPreview>(parameters => parameters
            .Add(component => component.Board, new BoardProjection(0, 0, []))
            .Add(component => component.Name, "Nowhere"));

        Assert.Empty(cut.FindAll("svg"));
    }
}

internal static class MapPreviewFixtures
{
    public static BoardProjection Board() => new(3, 2,
    [
        Hex(0, 0, "plains") with { PlayerStart = 1 },
        Hex(1, 0, "plains"),
        Hex(2, 0, "water"),
        Hex(0, 1, "stone"),
        Hex(1, 1, "lava"),
        Hex(2, 1, "plains") with { PlayerStart = 2 },
    ]);

    private static HexProjection Hex(int q, int r, string biome) => new(q, r, biome, "grass", q, false, null);
}
