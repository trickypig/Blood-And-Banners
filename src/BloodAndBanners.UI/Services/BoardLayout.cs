using System.Globalization;
using System.Text;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>
/// Flat-top hex geometry matching hex_map_editor.html: raised tiles lift by a fixed step per elevation
/// level and show their sides, and tiles are listed back to front so nearer ones paint over farther ones.
/// Axial r runs across the board and q runs down it, so every axial neighbour is a touching flat-top hex.
/// </summary>
public sealed record BoardLayout(double Width, double Height, IReadOnlyList<HexTile> Tiles)
{
    /// <summary>Hex circumradius in board units.</summary>
    public const double Size = 44;

    /// <summary>Board units each elevation level raises a tile (the editor's 5px at its 40px hexes).</summary>
    public const double LiftPerLevel = Size / 8;

    private const double Padding = 20;
    private static readonly double Root3 = Math.Sqrt(3);

    public static BoardLayout For(IReadOnlyList<HexProjection> hexes)
    {
        if (hexes.Count == 0)
        {
            return new BoardLayout(2 * Padding, 2 * Padding, []);
        }

        var ground = hexes.Select(hex => (Hex: hex, X: 1.5 * Size * hex.R, Y: Root3 * Size * (hex.Q + hex.R / 2.0))).ToList();
        var minX = ground.Min(tile => tile.X);
        var maxX = ground.Max(tile => tile.X);
        var minY = ground.Min(tile => tile.Y);
        var maxY = ground.Max(tile => tile.Y);
        var maxLift = Math.Max(0, hexes.Max(hex => hex.Elevation)) * LiftPerLevel;
        var offsetX = Padding + Size - minX;
        var offsetY = Padding + maxLift + Root3 / 2 * Size - minY;

        var tiles = ground
            .OrderBy(tile => tile.Y)
            .ThenBy(tile => tile.X)
            .Select(tile =>
            {
                var lift = Math.Max(0, tile.Hex.Elevation) * LiftPerLevel;
                return HexTile.Create(tile.Hex, tile.X + offsetX, tile.Y + offsetY - lift, lift);
            })
            .ToList();

        return new BoardLayout(
            maxX - minX + 2 * (Size + Padding),
            maxY - minY + Root3 * Size + 2 * Padding + maxLift,
            tiles);
    }
}

/// <summary>A positioned hex: <see cref="X"/>/<see cref="Y"/> is the centre of its raised top face.</summary>
public sealed record HexTile(HexProjection Hex, double X, double Y, double Lift, string TopPoints, string SidePath, string SideEdges)
{
    internal static HexTile Create(HexProjection hex, double x, double y, double lift)
    {
        var top = string.Join(' ', Enumerable.Range(0, 6).Select(i => Point(Corner(x, y, i))));

        // Upper half of the raised top (corners 3,4,5,0), then the lower half at ground level (0,1,2,3)
        var side = new StringBuilder();
        if (lift > 0)
        {
            side.Append('M').Append(Point(Corner(x, y, 3)));
            foreach (var i in new[] { 4, 5, 0 })
            {
                side.Append(" L").Append(Point(Corner(x, y, i)));
            }
            foreach (var i in new[] { 0, 1, 2, 3 })
            {
                side.Append(" L").Append(Point(Corner(x, y + lift, i)));
            }
            side.Append(" Z");
        }

        // Vertical edges at the visible corners and the bottom outline at ground level
        var edges = new StringBuilder();
        if (lift > 0)
        {
            foreach (var i in new[] { 0, 1, 2, 3 })
            {
                edges.Append(" M").Append(Point(Corner(x, y, i))).Append(" L").Append(Point(Corner(x, y + lift, i)));
            }
            edges.Append(" M").Append(Point(Corner(x, y + lift, 0)));
            foreach (var i in new[] { 1, 2, 3 })
            {
                edges.Append(" L").Append(Point(Corner(x, y + lift, i)));
            }
        }

        return new HexTile(hex, x, y, lift, top, side.ToString(), edges.ToString().TrimStart());
    }

    private static (double X, double Y) Corner(double x, double y, int index)
    {
        var angle = Math.PI / 3 * index;
        return (x + BoardLayout.Size * Math.Cos(angle), y + BoardLayout.Size * Math.Sin(angle));
    }

    private static string Point((double X, double Y) point) =>
        string.Create(CultureInfo.InvariantCulture, $"{point.X:0.##},{point.Y:0.##}");
}
