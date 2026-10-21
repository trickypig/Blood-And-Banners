namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.UI.Components;
using Bunit;

public sealed class HealthDisplayTests : BunitContext
{
    public HealthDisplayTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Theory]
    [InlineData(0, 3)]
    [InlineData(1, 2)]
    [InlineData(3, 0)]
    [InlineData(5, 0)]
    public void Unit_card_and_sidebar_show_remaining_hp_clamped_at_zero(int wounds, int full)
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Hp = 3, Wounds = wounds }, .. projection.Units.Skip(1)],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        var card = cut.Find("[data-testid=selected-unit]");
        Assert.Equal($"{full} HP", card.QuerySelector("[data-stat=health]")!.GetAttribute("aria-label"));
        var rosterHealth = cut.Find("[data-roster-unit=s1-u3] .roster-health");
        Assert.Equal(full.ToString(), rosterHealth.TextContent.Trim());
        Assert.Equal($"{full} HP", rosterHealth.GetAttribute("aria-label"));
        Assert.EndsWith("/Stat_Health.png", rosterHealth.QuerySelector("img")!.GetAttribute("src"));
        Assert.DoesNotContain("Wounds", card.TextContent);
    }
}
