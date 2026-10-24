namespace BloodAndBanners.UI.Services;

/// <summary>
/// Map obstacles: a hex's "tree", "rock" or "shrine" object fills it, blocking movement (flying included) and sight.
/// The kinds play the same; each just has its own images at images/obstacles/&lt;kind&gt;-1.png onwards,
/// picked per hex by position like the terrain variants.
/// </summary>
public static class ObstacleArt
{
    /// <summary>
    /// The obstacle kinds with their image counts, in the order the editor's add button cycles through them.
    /// Must match Board::OBSTACLES.
    /// </summary>
    private static readonly (string Kind, string Display, int Variants)[] Art =
    [
        ("tree", "Trees", 4),
        ("rock", "Rocks", 4),
        ("shrine", "Shrines", 1),
    ];

    public static IReadOnlyList<string> Kinds { get; } = Art.Select(art => art.Kind).ToList();

    public static bool IsObstacle(string? value) => value is not null && Kinds.Contains(value);

    /// <summary>Images available for a kind.</summary>
    public static int VariantCount(string kind) => Art.Single(art => art.Kind == kind).Variants;

    /// <summary>
    /// Which of the kind's images a hex shows, from 1. Purely cosmetic, so never stored; salted so it does not
    /// simply follow the hex's terrain variant.
    /// </summary>
    public static int Variant(string kind, int q, int r) => TerrainArt.Variant(q * 31 + 17, r * 37 + 11) % VariantCount(kind) + 1;

    public static string? ImagePath(string? kind, int variant = 1) =>
        IsObstacle(kind) ? $"images/obstacles/{kind}-{variant}.png" : null;

    public static string? ImagePath(string? kind, int q, int r) => IsObstacle(kind) ? ImagePath(kind, Variant(kind!, q, r)) : null;

    public static string Display(string kind) => Art.Single(art => art.Kind == kind).Display;
}
