namespace BloodAndBanners.UI.Services;

/// <summary>Plain-language effects of carried items, shown on the pickup prompt.</summary>
public static class ItemText
{
    public static string Effect(string kind) => kind switch
    {
        "power-potion" => "+2 Power for 3 turns",
        "speed-potion" => "+2 Speed for 3 turns",
        "health-potion" => "Heals all wounds",
        _ => string.Empty,
    };
}
