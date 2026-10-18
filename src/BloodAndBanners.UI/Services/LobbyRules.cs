namespace BloodAndBanners.UI.Services;

using BloodAndBanners.Contracts;

/// <summary>When the host may begin, shared by the war room and the Void Lord's setup board.</summary>
public static class LobbyRules
{
    public static int VoidLordCount(GameProjection projection) =>
        projection.Memberships.Count(seat => seat.Role == "voidLord");

    public static bool CanStart(GameProjection projection) => StartBlocker(projection) is null;

    /// <summary>Why the match cannot begin yet, or null when it can.</summary>
    public static string? StartBlocker(GameProjection projection)
    {
        if (projection.Mode == GameMode.Coop)
        {
            if (VoidLordCount(projection) != 1)
            {
                return "Needs a Void Lord";
            }
            if (projection.Memberships.Count < 2)
            {
                return "Needs a hero player";
            }
        }
        else if (projection.Memberships.Count < 2)
        {
            return "Needs a second commander";
        }
        var waiting = projection.Memberships.Count(seat => !seat.Ready);
        return waiting == 0 ? null : $"Waiting for {waiting} {(waiting == 1 ? "commander" : "commanders")} to ready up";
    }
}
