using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public static class PollingCadence
{
    /// <summary>True when the viewer holds a seat and it is that seat's turn or pick; spectators never do.</summary>
    public static bool IsViewersMoment(ViewerPermissions viewer, string? activeSeatId, string? pickingSeatId) =>
        viewer.SeatId is { } seat && (activeSeatId == seat || pickingSeatId == seat);

    public static TimeSpan Select(
        bool activeLocalTurn,
        bool pendingChoice,
        bool backgrounded,
        int consecutiveFailures)
    {
        if (consecutiveFailures > 0)
        {
            var exponent = Math.Min(consecutiveFailures, 4);
            return TimeSpan.FromSeconds(Math.Min(30, Math.Pow(2, exponent + 1)));
        }
        if (backgrounded)
        {
            return TimeSpan.FromSeconds(5);
        }
        return activeLocalTurn || pendingChoice
            ? TimeSpan.FromMilliseconds(750)
            : TimeSpan.FromSeconds(2);
    }
}
