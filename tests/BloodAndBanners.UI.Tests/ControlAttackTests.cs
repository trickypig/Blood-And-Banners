namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Microsoft.AspNetCore.Components.Web;
using Bunit;

public sealed class ControlAttackTests : BunitContext
{
    public ControlAttackTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection ChoiceProjection(string kind)
    {
        var p = BattleFixtures.Projection();
        var option = kind == "panPipes" ? "target:friend" : "land:5,0";
        var hex = kind == "panPipes" ? "3,0" : "5,0";
        return p with
        {
            Board = new BoardProjection(6, 1, Enumerable.Range(0, 6).Select(q => new HexProjection(q, 0, "plains", "grass", 0, false,
                q == 0 ? p.Units[0].UnitId : q == 1 ? p.Units[1].UnitId : q == 3 ? "friend" : null)).ToList()),
            Units = [p.Units[0] with { Hex = "0,0" }, p.Units[1] with { Hex = "1,0" }, p.Units[1] with { UnitId = "friend", Name = "Ally", Hex = "3,0" }],
            PendingChoice = new("control-1", p.Viewer.SeatId!, kind, [option]) { UnitId = p.Units[1].UnitId, TargetHexes = [hex] },
            LegalActions = [new("resolveChoice", p.Units[1].UnitId, [hex])],
        };
    }

    [Theory]
    [InlineData("panPipes", false)]
    [InlineData("panPipes", true)]
    [InlineData("skyToss", false)]
    [InlineData("skyToss", true)]
    public void Control_choices_highlight_only_legal_hexes_and_support_mouse_and_keyboard(string kind, bool keyboard)
    {
        var sent = new List<GameCommandRequest>();
        var projection = ChoiceProjection(kind);
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        Assert.Contains(kind == "panPipes" ? "Pan Pipes" : "Sky Toss", cut.Find("[data-testid=control-choice]").TextContent);
        var hex = projection.PendingChoice!.TargetHexes[0];
        Assert.Contains("control-target", cut.Find($"[data-hex='{hex}']").ClassList);
        cut.Find("[data-hex='2,0']").Click();
        Assert.Empty(sent);
        var target = cut.Find($"[data-hex='{hex}']");
        if (keyboard) target.KeyDown(new KeyboardEventArgs { Key = "Enter" }); else target.Click();
        var command = Assert.Single(sent);
        Assert.Equal("resolveChoice", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("control-1", payload.GetProperty("choiceId").GetString());
        Assert.Equal(projection.PendingChoice.Options[0], payload.GetProperty("option").GetString());
    }

    [Theory]
    [InlineData("panPipes")]
    [InlineData("skyToss")]
    public void Control_choice_buttons_are_named_and_busy_state_prevents_submitting(string kind)
    {
        var sent = new List<GameCommandRequest>();
        var projection = ChoiceProjection(kind);
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection).Add(c => c.IsBusy, true)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        var button = cut.Find("[data-testid=control-choice] button");
        Assert.True(button.HasAttribute("disabled"));
        Assert.DoesNotContain("target:", button.TextContent);
        Assert.DoesNotContain("land:", button.TextContent);
        cut.Find($"[data-hex='{projection.PendingChoice!.TargetHexes[0]}']").Click();
        Assert.Empty(sent);
    }

    [Theory]
    [InlineData("panPipes", "Pan Pipes")]
    [InlineData("skyToss", "Sky Toss")]
    public void Opponent_waiting_prompt_names_the_control_choice_and_offers_no_timeout_override(string kind, string title)
    {
        var projection = ChoiceProjection(kind);
        projection = projection with
        {
            PendingChoice = null,
            PendingChoiceSummary = new("control-1", "other-seat", projection.Units[1].UnitId, null) { Kind = kind },
            LegalActions = [],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        Assert.Contains(title, cut.Find("[data-testid=choice-waiting]").TextContent);
        Assert.Empty(cut.FindAll("[data-action=keepForThem]"));
        Assert.Empty(cut.FindAll(".control-target"));
    }

    [Theory]
    [InlineData("Petrified", "no Defense dice")]
    [InlineData("Entrapped", "can't move or attack")]
    [InlineData("Charmed", "must move as close as possible to its Mermaid")]
    [InlineData("Pan Pipes", "Satyrs within 3 hexes")]
    public void Control_conditions_explain_their_effects_on_the_unit_card(string condition, string explanation)
    {
        var projection = ChoiceProjection("panPipes");
        projection = projection with
        {
            PendingChoice = null,
            Units = [projection.Units[0] with { Conditions = [new(condition, "turnStart")] }, .. projection.Units.Skip(1)],
            LegalActions = [new("activateUnit", projection.Units[0].UnitId, [])],
        };
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains(explanation, cut.Find($"[data-condition='{condition}']").TextContent);
    }

    [Theory]
    [InlineData("Petrifying Gaze", "save 13+")]
    [InlineData("Stone Grip", "save 12+")]
    [InlineData("Sky Toss", "save 15+")]
    [InlineData("Siren's Song", "within 5 hexes; save 15+ or be charmed")]
    public void Control_attack_targeting_guides_the_save(string ability, string guidance)
    {
        var projection = ChoiceProjection("panPipes");
        var target = "1,0";
        projection = projection with
        {
            PendingChoice = null,
            Units = [projection.Units[0] with { Abilities = [new(ability, "Attack", "Control") { Playable = true }] }, .. projection.Units.Skip(1)],
            LegalActions = [new("specialAttack", projection.Units[0].UnitId, [target]) { Ability = ability }],
        };
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find($"[data-use-ability=\"{ability}\"]").Click();
        Assert.Contains(guidance, cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find($"[data-hex='{target}']").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        Assert.Equal(ability, JsonSerializer.SerializeToElement(command.Payload).GetProperty("ability").GetString());
    }

    [Fact]
    public void Pan_pipes_plays_at_once_without_asking_for_a_target()
    {
        var projection = ChoiceProjection("panPipes");
        var piper = projection.Units[0];
        projection = projection with
        {
            PendingChoice = null,
            Units = [piper with { Abilities = [new("Pan Pipes", "Attack", "Control") { Playable = true }] }, .. projection.Units.Skip(1)],
            LegalActions = [new("specialAttack", piper.UnitId, ["0,0"]) { Ability = "Pan Pipes" }],
        };
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, projection)
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-use-ability='Pan Pipes']").Click();
        var command = Assert.Single(sent);
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Pan Pipes", payload.GetProperty("ability").GetString());
        Assert.Equal(piper.UnitId, Assert.Single(payload.GetProperty("targetUnitIds").EnumerateArray()).GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Fact]
    public void Control_resolution_events_explain_redirection_and_undefended_fall_damage()
    {
        var p = BattleFixtures.Projection();
        GameEvent E(string kind, string json) => new(1, 1, kind, null, [], JsonDocument.Parse(json).RootElement.Clone(), DateTimeOffset.UnixEpoch);
        Assert.Contains("Pan Pipes", CombatLogFormatter.Describe(E("attackRedirected", """{"unitId":"s1-u3","targetUnitId":"s2-u1","ability":"Pan Pipes"}"""), p));
        Assert.Contains("wasted", CombatLogFormatter.Describe(E("attackRedirected", """{"unitId":"s1-u3","targetUnitId":null,"ability":"Pan Pipes"}"""), p));
        var line = CombatLogFormatter.Describe(E("unitTossed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"1,0","to":"5,0","faces":["sword","blank","sword"],"wounds":2}"""), p);
        Assert.Contains("5,0", line);
        Assert.Contains("2 wounds", line);
        Assert.Contains("no Defense", line);
    }
}
