namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class MushroomAbilityTests : BunitContext
{
    public MushroomAbilityTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection NetworkProjection(params MovementHazardProjection[] hazards)
    {
        var projection = BattleFixtures.Projection();
        return projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Mycelium Network", "Essence", "Teleport instead of moving.") { Playable = true }] }, .. projection.Units.Skip(1)],
            LegalActions = [new LegalAction("moveAbility", "s1-u3", ["2,4"])
            {
                Ability = "Mycelium Network",
                MovementOptions = [new MovementOptionProjection("2,4", ["2,4"], 3, hazards)],
            }],
        };
    }

    [Fact]
    public void Network_targets_empty_hexes_and_sends_a_movement_ability_command()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, NetworkProjection())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Mycelium Network']").Click();
        Assert.Contains("choose a destination", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='2,4']").ClassList);
        cut.Find("[data-hex='3,4']").Click();
        Assert.Empty(sent);
        cut.Find("[data-hex='2,4']").Click();
        Assert.Equal("moveAbility", sent.Single().Kind);
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("Mycelium Network", payload.GetProperty("ability").GetString());
        Assert.Equal("2,4", payload.GetProperty("toHex").GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Theory]
    [InlineData("damage", "lava", 5, "5 damage dice")]
    [InlineData("condition", "poison", 0, "Poisoned")]
    [InlineData("condition", "ice", 0, "Unsteady")]
    public void Network_terrain_arrival_requires_confirmation(string kind, string terrain, int dice, string risk)
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, NetworkProjection(new MovementHazardProjection(kind, terrain, "2,4", dice, false, false)))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Mycelium Network']").Click();
        cut.Find("[data-hex='2,4']").Click();
        Assert.Empty(sent);
        Assert.Contains(risk, cut.Find("[data-testid=movement-confirmation]").TextContent);
        cut.Find("button[data-action=cancelMovement]").Click();
        Assert.Empty(sent);
        cut.Find("[data-hex='2,4']").Click();
        cut.Find("button[data-action=confirmMovement]").Click();
        Assert.Equal("moveAbility", sent.Single().Kind);
        Assert.Equal("2,4", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("toHex").GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Theory]
    [InlineData("Spore Bomb", "defeat itself")]
    [InlineData("Spore Cloud", "within 3 hexes")]
    public void Spore_attacks_confirm_the_area_on_the_caster(string ability, string prompt)
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection(ability, "Attack", "Release spores.") { Playable = true }] }, .. projection.Units.Skip(1)],
            LegalActions = [new LegalAction("specialAttack", "s1-u3", ["1,4"]) { Ability = ability }],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='1,4']").Click();
        cut.Find($"button[data-use-ability='{ability}']").Click();
        Assert.Empty(sent);
        Assert.Contains(prompt, cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='1,4']").Click();
        Assert.Equal("specialAttack", sent.Single().Kind);
        Assert.Equal("s1-u3", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("targetUnitIds")[0].GetString());
    }
}
