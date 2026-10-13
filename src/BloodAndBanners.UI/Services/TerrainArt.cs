namespace BloodAndBanners.UI.Services;

/// <summary>
/// Terrain look shared with hex_map_editor.html: each biome's flat fallback color and the terrains
/// that have a pattern at images/terrain/&lt;biome&gt;-&lt;terrain&gt;.svg. The first terrain is the biome's default.
/// Each terrain also has cosmetic variants &lt;biome&gt;-&lt;terrain&gt;-1.svg to -7.svg, picked per hex by position.
/// </summary>
public static class TerrainArt
{
    private const string UnknownColor = "#6b6b6b";

    /// <summary>Images per terrain: the primary plus seven variants.</summary>
    public const int VariantCount = 8;

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

    /// <summary>
    /// Which of the terrain's images a hex shows: 0 is the primary, 1-7 the variants. Purely cosmetic, so it
    /// is never stored; a hash of the position scatters the images evenly but always picks the same one for a
    /// hex. Must stay identical to terrainVariant() in hex_map_editor.html.
    /// </summary>
    public static int Variant(int q, int r)
    {
        unchecked
        {
            var h = (uint)q * 374761393u + (uint)r * 668265263u;
            h = (h ^ (h >> 13)) * 1274126177u;
            h ^= h >> 16;
            return (int)(h % VariantCount);
        }
    }

    /// <summary>The pattern image path, falling back to the biome's default terrain like the editor does.</summary>
    public static string? ImagePath(string biome, string terrain, int variant = 0)
    {
        var biomeKey = Normalize(biome);
        if (!Biomes.TryGetValue(biomeKey, out var art))
        {
            return null;
        }
        var terrainKey = Normalize(terrain);
        var resolved = art.Terrains.Contains(terrainKey) ? terrainKey : art.Terrains[0];
        return variant == 0 ? $"images/terrain/{biomeKey}-{resolved}.svg" : $"images/terrain/{biomeKey}-{resolved}-{variant}.svg";
    }

    private static string Normalize(string value) => value.Trim().ToLowerInvariant().Replace(' ', '-');
}
