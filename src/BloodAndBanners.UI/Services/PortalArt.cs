namespace BloodAndBanners.UI.Services;

public static class PortalArt
{
    public static string Color(int player) => player switch { 1 => "red", 2 => "blue", 3 => "green", _ => "magenta" };
    public static string ImagePath(int player, string pose = "open") => $"images/portals/{Color(player)}-{pose}.png";
}
