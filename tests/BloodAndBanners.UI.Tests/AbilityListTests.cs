namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class AbilityListTests : BunitContext
{
    private static readonly AbilityProjection Fireball = new("Fireball", "Attack", "Power 4, Range 4, splash") { Playable = true };
    private static readonly AbilityProjection Evasion = new("Evasion", "Essence", "Roll d20") { Playable = true };
    private static readonly AbilityProjection Teleport = new("Teleport", "Attack", "Move a friendly unit") { Playable = false };
    private static readonly AbilityProjection Runes = new("Rune of Warding", "Ability", "+2 Defense") { Playable = true, UsedThisTurn = true };

    [Fact]
    public void Lists_each_ability_with_its_type_and_state()
    {
        var cut = Render<AbilityList>(parameters => parameters.Add(component => component.Abilities, [Fireball, Evasion, Teleport, Runes]));

        Assert.Equal("Attack", cut.Find("li[data-ability='Fireball'] .ability-type").TextContent);
        Assert.Contains("Power 4, Range 4, splash", cut.Find("li[data-ability='Fireball']").TextContent);
        Assert.Contains("Not yet in the digital game", cut.Find("li[data-ability='Teleport'] .ability-deferred").TextContent);
        Assert.Contains("Used", cut.Find("li[data-ability='Rune of Warding'] .ability-used").TextContent);
        Assert.Empty(cut.FindAll("button"));
    }

    [Fact]
    public void Usable_actions_become_buttons_that_report_the_chosen_action()
    {
        LegalAction? chosen = null;
        var action = new LegalAction("specialAttack", "u1", ["2,0"]) { Ability = "Fireball" };
        var cut = Render<AbilityList>(parameters => parameters
            .Add(component => component.Abilities, [Fireball, Evasion])
            .Add(component => component.Actions, [action])
            .Add(component => component.ActiveAbility, "Fireball")
            .Add(component => component.OnChoose, (LegalAction picked) => chosen = picked));

        var button = cut.Find("button[data-use-ability='Fireball']");
        Assert.Equal("true", button.GetAttribute("aria-pressed"));
        Assert.Empty(cut.FindAll("button[data-use-ability='Evasion']"));
        button.Click();
        Assert.Same(action, chosen);
    }
}
