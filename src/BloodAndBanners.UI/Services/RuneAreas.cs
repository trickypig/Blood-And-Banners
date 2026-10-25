using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>Which rune kinds reach each hex: every hex within radius − 1 steps of a rune, elevation ignored. Matches Runes.php.</summary>
public static class RuneAreas
{
    public static IReadOnlyDictionary<string, IReadOnlyList<string>> Of(IEnumerable<HexProjection> hexes)
    {
        var all = hexes.ToList();
        var runes = all.Where(hex => MapObjectArt.CategoryOf(hex.Object) == "nexus").ToList();
        var areas = new Dictionary<string, IReadOnlyList<string>>();
        if (runes.Count == 0) return areas;
        foreach (var hex in all)
        {
            var key = $"{hex.Q},{hex.R}";
            var kinds = runes
                .Where(rune => HexMath.Distance($"{rune.Q},{rune.R}", key) <= Math.Clamp(rune.Radius ?? 1, 1, MapObjectArt.MaxRuneRadius) - 1)
                .Select(rune => rune.Object!)
                .Distinct()
                .Order(StringComparer.Ordinal)
                .ToList();
            if (kinds.Count > 0) areas[key] = kinds;
        }
        return areas;
    }
}
