using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class PollingCadenceTests
{
    [Theory]
    [InlineData("fast", 400)]
    [InlineData("normal", 1000)]
    [InlineData("slow", 2000)]
    [InlineData(null, 1000)]
    public void Ai_interval_follows_the_pace(string? pace, int expected) =>
        Assert.Equal(expected, PollingCadence.AiInterval(pace).TotalMilliseconds);

    [Theory]
    [InlineData("fast", 400)]
    [InlineData("slow", 2000)]
    public void Ai_turn_polls_at_pace(string pace, int expected) =>
        Assert.Equal(expected, PollingCadence.Select(false, false, false, 0, PollingCadence.AiInterval(pace)).TotalMilliseconds);

    [Fact]
    public void Failures_still_back_off_and_background_wins_over_ai()
    {
        Assert.Equal(16000, PollingCadence.Select(false, false, false, 3, PollingCadence.AiInterval("fast")).TotalMilliseconds);
        Assert.Equal(5000, PollingCadence.Select(false, false, true, 0, PollingCadence.AiInterval("fast")).TotalMilliseconds);
    }
}
