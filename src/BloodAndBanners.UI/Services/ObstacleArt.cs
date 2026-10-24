namespace BloodAndBanners.UI.Services;

/// <summary>
/// Map obstacles: a hex's "tree" or "rock" object fills it, blocking movement (flying included) and sight.
/// The kinds play the same; each just has its own images at images/obstacles/&lt;kind&gt;-1.png to -4.png,
/// picked per hex by position like the terrain variants.
/// </summary>
public static class ObstacleArt
{
    /// <summary>Images per obstacle kind.</summary>
    public const int VariantCount = 4;

    /// <summary>The obstacle kinds, in the order the editor's add button cycles through them. Must match Board::OBSTACLES.</summary>
    public static IReadOnlyList<string> Kinds { get; } = ["tree", "rock"];

    public static bool IsObstacle(string? value) => value is not null && Kinds.Contains(value);

    /// <summary>
    /// Which of the kind's images a hex shows, 1-4. Purely cosmetic, so never stored; salted so it does not
    /// simply follow the hex's terrain variant.
    /// </summary>
    public static int Variant(int q, int r) => TerrainArt.Variant(q * 31 + 17, r * 37 + 11) % VariantCount + 1;

    public static string? ImagePath(string? kind, int variant = 1) =>
        IsObstacle(kind) ? $"images/obstacles/{kind}-{variant}.png" : null;

    public static string? ImagePath(string? kind, int q, int r) => ImagePath(kind, Variant(q, r));

    public static string Display(string kind) => kind == "tree" ? "Trees" : "Rocks";
}
