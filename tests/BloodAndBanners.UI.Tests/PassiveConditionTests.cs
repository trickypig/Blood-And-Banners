namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class PassiveConditionTests : BunitContext
{
    public PassiveConditionTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Theory]
    [InlineData("Dragon Slayer", "{\"level\":2}", "+2 Power and +2 Defense", "power", "Dragon Slayer +2")]
    [InlineData("Hibernating", "{}", "+2 Defense", "defense", "Hibernating +2")]
    [InlineData("Enraged", "{\"level\":5}", "+5 Power and +5 Defense", "power", "Enraged +5")]
    public void Passive_conditions_explain_their_current_bonus_on_card_token_and_stat_tooltip(
        string name, string data, string summary, string stat, string tooltip)
    {
        var condition = JsonSerializer.Deserialize<ConditionProjection>(
            $$"""{"name":"{{name}}","expires":"untilCleared","data":{{data}}}""", ContractJson.Options)!;
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Conditions = [condition] }, .. projection.Units.Skip(1)],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        Assert.Contains(summary, cut.Find($"[data-condition='{name}']").TextContent);
        Assert.Contains(tooltip, cut.Find($"[data-testid=selected-unit] [data-stat='{stat}']").GetAttribute("title"));
        if (name == "Dragon Slayer")
            Assert.Contains("rest of the game", cut.Find($"[data-condition='{name}']").TextContent);
        Assert.Single(cut.FindAll($"[data-hex='1,4'] [data-condition-marker='{name}']"));
        Assert.Contains(name == "Enraged" ? "Enraged +5" : name == "Dragon Slayer" ? "Dragon Slayer +2" : "Hibernating", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
    }
}
