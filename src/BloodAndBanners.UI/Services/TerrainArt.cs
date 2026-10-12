namespace BloodAndBanners.UI.Services;

/// <summary>
/// Terrain look shared with hex_map_editor.html: each biome's flat fallback color and the terrains
/// that have a pattern at images/terrain/&lt;biome&gt;-&lt;terrain&gt;.svg. The first terrain is the biome's default.
/// </summary>
public static class TerrainArt
{
    private const string UnknownColor = "#6b6b6b";

    private static readonly Dictionary<string, (string Color, string[] Terrains)> Biomes = new()
    {
        ["plains"] = ("#4a7c4e", ["grass", "roads"]),
        ["water"] = ("#4a7c9e", ["open-water", "shallow-water"]),
        ["stone"] = ("#6b6b6b", ["stone-path", "crags"]),
        ["sand"] = ("#d4a76a", ["desert", "dunes"]),
        ["jungle"] = ("#2d5016", ["jungle", "swamp"]),
        ["darkness"] = ("#1a1a1a", ["darkness", "poison-pits"]),
        ["lava"] = ("#c4381c", ["basalt", "lava"]),
        ["ice"] = ("#a8d8e8", ["snow", "ice"]),
    };

    public static string Color(string biome) =>
        Biomes.TryGetValue(Normalize(biome), out var art) ? art.Color : UnknownColor;

    /// <summary>A muted shade of the biome color for height labels: darker for light biomes, lighter for dark ones.</summary>
    public static string LabelColor(string biome)
    {
        var color = Color(biome);
        var channels = new[] { 1, 3, 5 }.Select(i => Convert.ToInt32(color.Substring(i, 2), 16)).ToArray();
        var lightness = (0.299 * channels[0] + 0.587 * channels[1] + 0.114 * channels[2]) / 255;
        var (target, amount) = lightness < 0.4 ? (255, 0.55) : (0, 0.45);
        return "#" + string.Concat(channels.Select(c =>
            ((int)Math.Round(c + (target - c) * amount, MidpointRounding.AwayFromZero)).ToString("x2")));
    }

    /// <summary>The pattern image path, falling back to the biome's default terrain like the editor does.</summary>
    public static string? ImagePath(string biome, string terrain)
    {
        var biomeKey = Normalize(biome);
        if (!Biomes.TryGetValue(biomeKey, out var art))
        {
            return null;
        }
        var terrainKey = Normalize(terrain);
        var resolved = art.Terrains.Contains(terrainKey) ? terrainKey : art.Terrains[0];
        return $"images/terrain/{biomeKey}-{resolved}.svg";
    }

    private static string Normalize(string value) => value.Trim().ToLowerInvariant().Replace(' ', '-');
}
