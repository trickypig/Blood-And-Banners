namespace BloodAndBanners.UI.Services;

public static class PollingCadence
{
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
