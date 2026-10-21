using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>The player-facing names of the game modes.</summary>
public static class GameModeLabels
{
    public static string For(GameMode mode) => mode switch
    {
        GameMode.VanillaPvp => "Vanilla duel",
        GameMode.Coop => "Void incursion",
        GameMode.BattleRoyale => "Battle royale",
        _ => mode.ToString(),
    };
}
