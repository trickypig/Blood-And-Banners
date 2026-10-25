namespace BloodAndBanners.UI.Services;

/// <summary>
/// Map objects, one per hex in the hex's "object": obstacles (block movement, flying included, and sight), landmarks
/// (block movement only), nexus points and items (block nothing; no rules effect yet). Each kind has images at
/// images/&lt;folder&gt;/&lt;kind&gt;-1.png onwards, picked per hex by position like the terrain variants.
/// Must match the lists in Board.php.
/// </summary>
public static class MapObjectArt
{
    private sealed record Kind(string Id, string Display, string Category, int Variants);

    private static readonly (string Id, string Display, string Folder)[] CategoryInfo =
    [
        ("obstacle", "Obstacle", "obstacles"),
        ("landmark", "Landmark", "landmarks"),
        ("nexus", "Nexus Point", "nexus"),
        ("item", "Item", "items"),
    ];

    /// <summary>In the order the editor's button for each category cycles through them.</summary>
    private static readonly Kind[] Art =
    [
        new("tree", "Tree", "obstacle", 4),
        new("rock", "Rock", "obstacle", 4),
        new("shrine", "Shrine", "landmark", 1),
        new("fire-crystal", "Fire Crystal", "landmark", 1),
        new("forest-crystal", "Forest Crystal", "landmark", 1),
        new("ice-crystal", "Ice Crystal", "landmark", 1),
        new("rune-of-magic", "Rune of Magic", "nexus", 1),
        new("rune-of-strength", "Rune of Strength", "nexus", 1),
        new("rune-of-warding", "Rune of Warding", "nexus", 1),
        new("power-potion", "Power Potion", "item", 1),
        new("speed-potion", "Speed Potion", "item", 1),
        new("health-potion", "Health Potion", "item", 1),
    ];

    public static IReadOnlyList<string> Categories { get; } = CategoryInfo.Select(info => info.Id).ToList();

    public static IReadOnlyList<string> KindsIn(string category) => Art.Where(kind => kind.Category == category).Select(kind => kind.Id).ToList();

    private static Kind? Find(string? kind) => kind is null ? null : Art.FirstOrDefault(art => art.Id == kind);

    public static bool IsKnown(string? kind) => Find(kind) is not null;

    public static string? CategoryOf(string? kind) => Find(kind)?.Category;

    public static bool BlocksSight(string? kind) => CategoryOf(kind) == "obstacle";

    public static bool Impassable(string? kind) => CategoryOf(kind) is "obstacle" or "landmark";

    public static string Display(string kind) => Find(kind)!.Display;

    public static string CategoryDisplay(string category) => CategoryInfo.Single(info => info.Id == category).Display;

    /// <summary>Images available for a kind.</summary>
    public static int VariantCount(string kind) => Find(kind)!.Variants;

    /// <summary>
    /// Which of the kind's images a hex shows, from 1. Purely cosmetic, so never stored; salted so it does not
    /// simply follow the hex's terrain variant.
    /// </summary>
    public static int Variant(string kind, int q, int r) => TerrainArt.Variant(q * 31 + 17, r * 37 + 11) % VariantCount(kind) + 1;

    public static string? ImagePath(string? kind, int variant = 1) => Find(kind) is { } art
        ? $"images/{CategoryInfo.Single(info => info.Id == art.Category).Folder}/{art.Id}-{variant}.png"
        : null;

    public static string? ImagePath(string? kind, int q, int r) => IsKnown(kind) ? ImagePath(kind, Variant(kind!, q, r)) : null;
}
