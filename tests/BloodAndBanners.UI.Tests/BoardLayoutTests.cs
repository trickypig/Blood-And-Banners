namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class BoardLayoutTests
{
    private static readonly (int Q, int R)[] Directions = [(1, 0), (1, -1), (0, -1), (-1, 0), (-1, 1), (0, 1)];

    private static HexProjection Hex(int q, int r, int elevation = 0) =>
        new(q, r, "plains", "grass", elevation, false, null);

    [Fact]
    public void Every_axial_neighbour_touches_edge_to_edge()
    {
        var hexes = Directions.Select(d => Hex(d.Q, d.R)).Append(Hex(0, 0)).ToList();
        var tiles = BoardLayout.For(hexes).Tiles;
        var centre = tiles.Single(tile => tile.Hex is { Q: 0, R: 0 });

        foreach (var tile in tiles.Where(tile => tile != centre))
        {
            var distance = Math.Sqrt(Math.Pow(tile.X - centre.X, 2) + Math.Pow(tile.Y - centre.Y, 2));
            Assert.Equal(Math.Sqrt(3) * BoardLayout.Size, distance, 6);
        }
    }

    [Fact]
    public void Raised_tiles_lift_by_their_elevation_and_draw_after_the_tiles_behind_them()
    {
        var layout = BoardLayout.For([Hex(1, 0, elevation: 2), Hex(0, 0)]);
        var low = layout.Tiles.Single(tile => tile.Hex.Q == 0);
        var high = layout.Tiles.Single(tile => tile.Hex.Q == 1);

        Assert.Same(low, layout.Tiles[0]);
        Assert.Equal(2 * BoardLayout.LiftPerLevel, high.Lift);
        Assert.Equal(Math.Sqrt(3) * BoardLayout.Size - 2 * BoardLayout.LiftPerLevel, high.Y - low.Y, 6);
        Assert.Empty(low.SidePath);
        Assert.NotEmpty(high.SidePath);
        Assert.True(low.Y - BoardLayout.Size > 0 && high.Y - BoardLayout.Size > 0, "tiles stay inside the board");
    }

    [Fact]
    public void Points_use_invariant_numbers()
    {
        var culture = Thread.CurrentThread.CurrentCulture;
        Thread.CurrentThread.CurrentCulture = new System.Globalization.CultureInfo("de-DE");
        try
        {
            Assert.Matches(@"^[\d.]+,[\d.]+( [\d.]+,[\d.]+){5}$", BoardLayout.For([Hex(0, 0)]).Tiles[0].TopPoints);
        }
        finally
        {
            Thread.CurrentThread.CurrentCulture = culture;
        }
    }

    [Theory]
    [InlineData("stone", "crags", "images/terrain/stone-crags.svg")]
    [InlineData("stone", "stone", "images/terrain/stone-stone-path.svg")]
    [InlineData("Water", "Open Water", "images/terrain/water-open-water.svg")]
    [InlineData("void", "rift", null)]
    public void Terrain_images_match_the_editor_and_fall_back_to_the_biome_default(string biome, string terrain, string? expected) =>
        Assert.Equal(expected, TerrainArt.ImagePath(biome, terrain));

    [Theory]
    [InlineData("stone", "#3b3b3b")]
    [InlineData("jungle", "#a1b096")]
    public void Height_labels_use_a_muted_shade_of_the_biome_color(string biome, string expected) =>
        Assert.Equal(expected, TerrainArt.LabelColor(biome));

    [Fact]
    public void Hex_math_measures_axial_distance_and_lists_neighbours()
    {
        Assert.Equal(3, HexMath.Distance("0,0", "3,0"));
        Assert.Equal(2, HexMath.Distance("0,0", "1,1"));
        Assert.Equal(["1,0", "1,-1", "0,-1", "-1,0", "-1,1", "0,1"], HexMath.Neighbours("0,0"));
    }

    [Fact]
    public void Hex_math_finds_directions_and_steps()
    {
        Assert.Equal((1, 0), HexMath.Direction("0,0", "1,0"));
        Assert.Null(HexMath.Direction("0,0", "2,0"));
        Assert.Equal("2,-1", HexMath.Step("1,0", (1, -1)));
    }
}
