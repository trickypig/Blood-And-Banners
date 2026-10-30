using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public static class PollingCadence
{
    /// <summary>True when the viewer holds a seat and it is that seat's turn or pick; spectators never do.</summary>
    public static bool IsViewersMoment(ViewerPermissions viewer, string? activeSeatId, string? pickingSeatId) =>
        viewer.SeatId is { } seat && (activeSeatId == seat || pickingSeatId == seat);

    /// <summary>How soon to poll while the AI Void Lord is acting: its pace, so each pause is picked up promptly.</summary>
    public static TimeSpan AiInterval(string? pace) => pace switch
    {
        "slow" => TimeSpan.FromSeconds(2),
        "fast" => TimeSpan.FromMilliseconds(400),
        _ => TimeSpan.FromSeconds(1),
    };

    public static TimeSpan Select(
        bool activeLocalTurn,
        bool pendingChoice,
        bool backgrounded,
        int consecutiveFailures,
        TimeSpan? aiActing = null)
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
        if (aiActing is { } ai)
        {
            return ai < TimeSpan.FromSeconds(2) ? ai : TimeSpan.FromSeconds(2);
        }
        return activeLocalTurn || pendingChoice
            ? TimeSpan.FromMilliseconds(750)
            : TimeSpan.FromSeconds(2);
    }
}
