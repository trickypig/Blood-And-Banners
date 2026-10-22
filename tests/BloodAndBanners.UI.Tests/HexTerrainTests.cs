using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

namespace BloodAndBanners.UI.Tests;

public sealed class HexTerrainTests : BunitContext
{
    [Theory]
    [InlineData(-10, .6)] [InlineData(-1, .08)] [InlineData(0, 0)]
    [InlineData(1, .035)] [InlineData(4, .14)] [InlineData(8, .28)] [InlineData(10, .28)]
    public void Shared_shading_keeps_raised_tiles_readable(int elevation, double expected) =>
        Assert.Equal(expected, TerrainArt.ElevationShade(elevation), 8);

    [Fact]
    public void Raised_hex_has_sides_texture_and_white_shading()
    {
        var tile = BoardLayout.For([new HexProjection(0, 0, "plains", "grass", 8, false, null)]).Tiles[0];
        var cut = Render<HexTerrain>(p => p.Add(c => c.Tile, tile).Add(c => c.PatternId, "test-pattern"));
        Assert.Single(cut.FindAll("path.hex-side"));
        Assert.Equal("url(#test-pattern)", cut.Find("polygon.hex-top").GetAttribute("fill"));
        Assert.Equal("0.28", cut.Find("polygon.hex-shade").GetAttribute("fill-opacity"));
        Assert.Equal("#fff", cut.Find("polygon.hex-shade").GetAttribute("fill"));
    }

    [Fact]
    public void Flat_hex_has_no_sides_or_shading()
    {
        var tile = BoardLayout.For([new HexProjection(0, 0, "plains", "grass", 0, false, null)]).Tiles[0];
        var cut = Render<HexTerrain>(p => p.Add(c => c.Tile, tile).Add(c => c.PatternId, "test"));
        Assert.Empty(cut.FindAll("path")); Assert.Empty(cut.FindAll(".hex-shade"));
    }
}
