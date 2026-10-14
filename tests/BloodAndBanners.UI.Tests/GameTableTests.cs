namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.AspNetCore.Components.Web;

public sealed partial class GameTableTests : BunitContext
{
    public GameTableTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    [Fact]
    public void Selecting_a_unit_highlights_its_legal_moves_and_attacks_and_sends_them()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();

        Assert.Contains("selected", cut.Find("[data-hex='1,4']").ClassList);
        Assert.Contains("reachable", cut.Find("[data-hex='2,4']").ClassList);
        Assert.Contains("attackable", cut.Find("[data-hex='3,4']").ClassList);
        Assert.Equal("Ranger", cut.Find("[data-testid=selected-unit] h3").TextContent);

        cut.Find("[data-hex='3,4']").Click();

        Assert.Equal("attackUnit", sent.Single().Kind);
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("s1-u3", payload.GetProperty("unitId").GetString());
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitId").GetString());
    }

    [Fact]
    public void End_turn_is_offered_as_an_order_and_rosters_strike_out_the_fallen()
    {
        GameCommandRequest? sent = null;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        cut.Find("button[data-action=endTurn]").Click();

        Assert.Equal("endTurn", sent?.Kind);
        Assert.Contains("defeated", cut.Find("[data-roster-unit=s2-u2]").ClassList);
        Assert.Contains("Your turn · 3 activations", cut.Find("[data-testid=turn-status]").TextContent);
    }

    [Fact]
    public void The_combat_log_lists_newest_events_first()
    {
        var events = new[]
        {
            new GameEvent(1, 5, "turnEnded", null, [], JsonSerializer.Deserialize<JsonElement>("""{"seatId":"seat-blue","nextSeatId":"seat-red","round":2}"""), DateTimeOffset.UnixEpoch),
            new GameEvent(2, 6, "unitDefeated", null, ["4,4"], JsonSerializer.Deserialize<JsonElement>("""{"unitId":"s2-u2"}"""), DateTimeOffset.UnixEpoch),
        };

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.Events, events));

        var lines = cut.FindAll(".combat-log li");
        Assert.Equal("Brute is defeated.", lines[0].TextContent);
        Assert.Equal("Round 2: Your turn.", lines[1].TextContent);
        Assert.Contains("focus", cut.Find("[data-hex='4,4']").ClassList);
    }

    [Fact]
    public void A_decided_match_shows_the_result_and_a_way_back_to_the_war_room()
    {
        var returned = false;
        var finished = BattleFixtures.Projection() with
        {
            Lifecycle = GameLifecycle.Completed,
            LegalActions = [],
            Result = new GameResult("annihilation", "seat-red", new Dictionary<string, int>())
        };

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, finished)
            .Add(component => component.OnReturnToWarRoom, () => returned = true));

        Assert.Equal("Victory", cut.Find("#match-end-title").TextContent);
        cut.Find("button[data-action=returnToWarRoom]").Click();
        Assert.True(returned);
    }

    [Fact]
    public void The_board_paints_editor_terrain_raises_high_ground_and_zooms()
    {
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.ImageBaseUrl, "https://example.test/blood/"));

        // Hex 4,4 lands on the stone path's seventh variant image
        var stone = cut.Find("[data-hex='4,4'] .hex-top").GetAttribute("fill");
        Assert.Equal("url(#bb-terrain-stone-stone-path-v7)", stone);
        Assert.Equal(
            "https://example.test/blood/images/terrain/stone-stone-path-7.svg",
            cut.Find("#bb-terrain-stone-stone-path-v7 image").GetAttribute("href"));
        Assert.Equal("+1", cut.Find("[data-hex='4,4'] .hex-elevation").TextContent);
        Assert.Equal("#3b3b3b", cut.Find("[data-hex='4,4'] .hex-elevation").GetAttribute("fill"));
        Assert.Single(cut.FindAll("[data-hex='4,4'] .hex-side"));
        Assert.Empty(cut.FindAll("[data-hex='2,4'] .hex-side"));

        var viewBox = cut.Find("svg.hex-board").GetAttribute("viewBox");
        cut.Find("[aria-label='Zoom in']").Click();
        var zoomed = cut.Find("svg.hex-board").GetAttribute("viewBox");
        Assert.NotEqual(viewBox, zoomed);
    }

    [Fact]
    public void Selecting_or_hovering_a_unit_on_the_board_highlights_it_in_the_army_list()
    {
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection() with { Turn = new TurnProjection(2, null, 3) }));

        cut.Find("[data-hex='1,4']").Click();
        Assert.Contains("selected", cut.Find("[data-roster-unit=s1-u3]").ClassList);
        Assert.Equal("true", cut.Find("[data-roster-unit=s1-u3]").GetAttribute("aria-current"));

        cut.Find("[data-hex='3,4']").MouseEnter();
        Assert.Contains("highlighted", cut.Find("[data-roster-unit=s2-u1]").ClassList);
        Assert.DoesNotContain("highlighted", cut.Find("[data-roster-unit=s1-u3]").ClassList);

        cut.Find("[data-hex='3,4']").MouseLeave();
        Assert.DoesNotContain("highlighted", cut.Find("[data-roster-unit=s2-u1]").ClassList);
        Assert.Contains("selected", cut.Find("[data-roster-unit=s1-u3]").ClassList);
    }

    [Fact]
    public void Units_that_have_acted_this_turn_are_marked_on_the_board_and_in_the_army_list()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Turn = new TurnProjection(2, "seat-blue", 1) { ActiveUnitId = "s2-u1" },
            Units = [
                projection.Units[0] with { ActivatedThisTurn = true },
                projection.Units[1] with { ActivatedThisTurn = true },
                projection.Units[2],
            ],
        };

        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("spent", cut.Find("[data-hex='1,4'] .unit-token").ClassList);
        Assert.Single(cut.FindAll("[data-hex='1,4'] .token-spent-badge"));
        Assert.EndsWith("already activated this turn", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
        Assert.Equal("Activated", cut.Find("[data-roster-unit=s1-u3] .activation-tag").TextContent);

        Assert.Contains("activating", cut.Find("[data-hex='3,4'] .unit-token").ClassList);
        Assert.Empty(cut.FindAll("[data-hex='3,4'] .token-spent-badge"));
        Assert.Equal("Acting", cut.Find("[data-roster-unit=s2-u1] .activation-tag").TextContent);

        Assert.Empty(cut.FindAll("[data-roster-unit=s2-u2] .activation-tag"));
    }

    [Fact]
    public void Leaving_a_match_takes_two_clicks_and_is_only_offered_to_players()
    {
        var left = false;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        cut.Find("button[data-action=leaveMatch]").Click();
        Assert.False(left);
        cut.Find("button[data-action=stayInMatch]").Click();
        Assert.Empty(cut.FindAll("button[data-action=confirmLeave]"));
        cut.Find("button[data-action=leaveMatch]").Click();
        cut.Find("button[data-action=confirmLeave]").Click();
        Assert.True(left);

        var spectating = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection() with
            {
                Viewer = new ViewerPermissions("spectator", null, false, false),
                LegalActions = []
            }));
        Assert.Empty(spectating.FindAll("[data-action]"));
    }

    [Fact]
    public void The_loser_is_told_their_army_has_fallen()
    {
        var finished = BattleFixtures.Projection() with
        {
            LegalActions = [],
            Result = new GameResult("annihilation", "seat-blue", new Dictionary<string, int>())
        };

        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, finished));

        Assert.Equal("Defeat", cut.Find("#match-end-title").TextContent);
        Assert.Contains("Your army has fallen.", cut.Find("[data-testid=match-end]").TextContent);
        Assert.Empty(cut.FindAll("button[data-action=leaveMatch]"));
    }

    [Fact]
    public void The_focus_pulse_survives_an_automatic_end_of_turn_and_activations_are_pluralised()
    {
        var events = new[]
        {
            new GameEvent(1, 5, "attackResolved", null, ["3,4"], JsonSerializer.Deserialize<JsonElement>("{}"), DateTimeOffset.UnixEpoch),
            new GameEvent(2, 5, "turnEnded", null, [], JsonSerializer.Deserialize<JsonElement>("{}"), DateTimeOffset.UnixEpoch),
        };
        var projection = BattleFixtures.Projection();

        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection with { Turn = new TurnProjection(2, "seat-red", 1) })
            .Add(component => component.Events, events));

        Assert.Contains("focus", cut.Find("[data-hex='3,4']").ClassList);
        Assert.Contains("1 activation", cut.Find("[data-testid=turn-status]").TextContent);
        Assert.DoesNotContain("1 activations", cut.Find("[data-testid=turn-status]").TextContent);
    }

    [Fact]
    public void The_unit_card_shows_effective_stats_and_abilities()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with
                {
                    EffectiveDefense = 4,
                    Effects = [new EffectProjection("Shield Wall", "defense", 1, "nextTurn")],
                    Abilities = [new AbilityProjection("Evasion", "Essence", "Roll d20") { Playable = true }],
                },
                projection.Units[1],
                projection.Units[2],
            ],
        };

        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        var defense = cut.Find("[data-testid=selected-unit] [data-stat='defense']");
        Assert.Equal("Defense 2 → 4", defense.TextContent.Trim());
        Assert.Contains("Shield Wall +1 until your next turn", defense.GetAttribute("title"));
        Assert.NotNull(cut.Find("[data-testid=selected-unit] li[data-ability='Evasion']"));
    }

    [Fact]
    public void Choosing_a_special_attack_targets_only_its_hexes_and_sends_it()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Fireball", "Attack", "Power 4") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("specialAttack", "s1-u3", ["3,4"]) { Ability = "Fireball" }],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Fireball']").Click();

        Assert.Contains("Fireball", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='3,4']").ClassList);
        Assert.DoesNotContain("reachable", cut.Find("[data-hex='2,4']").ClassList);

        cut.Find("[data-hex='3,4']").MouseEnter();
        Assert.Contains("splash", cut.Find("[data-hex='2,4']").ClassList);
        Assert.Contains("splash", cut.Find("[data-hex='4,4']").ClassList);

        cut.Find("[data-hex='3,4']").Click();

        var command = sent.Single();
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Fireball", payload.GetProperty("ability").GetString());
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitIds")[0].GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Fact]
    public void Targeting_can_be_cancelled_and_abilities_send_a_single_target()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Rune of Distraction", "Ability", "-2 Defense") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("useAbility", "s1-u3", ["3,4"]) { Ability = "Rune of Distraction" }],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Rune of Distraction']").Click();
        cut.Find("button[data-action=cancelTargeting]").Click();
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
        Assert.Contains("attackable", cut.Find("[data-hex='3,4']").ClassList);

        cut.Find("button[data-use-ability='Rune of Distraction']").Click();
        cut.Find("[data-hex='3,4']").Click();
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("useAbility", sent.Single().Kind);
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitId").GetString());
    }

    [Fact]
    public void Tokens_mark_buffs_and_debuffs()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with { Effects = [new EffectProjection("Shield Wall", "defense", 1, "nextTurn")] },
                projection.Units[1] with { Effects = [new EffectProjection("Rune of Distraction", "defense", -2, "nextTurn")] },
                projection.Units[2],
            ],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Single(cut.FindAll("[data-hex='1,4'] .effect-marker.buff"));
        Assert.Single(cut.FindAll("[data-hex='3,4'] .effect-marker.debuff"));
        Assert.Contains("Shield Wall", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
    }

    [Fact]
    public void Chain_lightning_picks_targets_in_order_within_the_remaining_reach()
    {
        var sent = new List<GameCommandRequest>();
        var hexes = Enumerable.Range(0, 8).Select(q => new HexProjection(q, 0, "plains", "grass", 0, false,
            q switch { 0 => "s1-u5", 2 => "s2-u1", 4 => "s2-u2", 5 => "s2-u3", 7 => "s2-u4", _ => null })).ToList();
        UnitProjection Enemy(string id, string hex) => new(id, "seat-blue", false, id, "", 2, 1, 3, 2, 5, 0, hex, false);
        var projection = BattleFixtures.Projection() with
        {
            Board = new BoardProjection(8, 1, hexes),
            Units = [
                new UnitProjection("s1-u5", "seat-red", false, "Sorcerer", "", 3, 5, 3, 2, 3, 0, "0,0", false)
                {
                    Abilities = [new AbilityProjection("Chain Lightning", "Attack", "Power 3, up to 3 units") { Playable = true }],
                },
                Enemy("s2-u1", "2,0"), Enemy("s2-u2", "4,0"), Enemy("s2-u3", "5,0"), Enemy("s2-u4", "7,0"),
            ],
            LegalActions = [new LegalAction("specialAttack", "s1-u5", ["2,0", "4,0", "5,0"]) { Ability = "Chain Lightning" }, new LegalAction("endTurn", null, [])],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("button[data-use-ability='Chain Lightning']").Click();
        cut.Find("[data-hex='2,0']").Click();

        Assert.Equal("1", cut.Find("[data-hex='2,0'] .chain-mark").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='4,0']").ClassList);
        Assert.Contains("ability-target", cut.Find("[data-hex='5,0']").ClassList);
        Assert.DoesNotContain("ability-target", cut.Find("[data-hex='7,0']").ClassList);

        cut.Find("[data-hex='4,0']").Click();
        Assert.Contains("ability-target", cut.Find("[data-hex='5,0']").ClassList);
        Assert.Empty(sent);

        cut.Find("button[data-action=strikeChain]").Click();
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal(["s2-u1", "s2-u2"], payload.GetProperty("targetUnitIds").EnumerateArray().Select(id => id.GetString()!).ToArray());
    }

    [Fact]
    public void The_glare_owner_picks_a_die_to_reroll_or_keeps()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection() with
        {
            PendingChoice = new PendingChoiceProjection("choice-1", "seat-red", "moonlightGlare", ["reroll:1", "keep"])
            {
                UnitId = "s1-u3", Side = "defense", Faces = ["shield", "blank"],
            },
            PendingChoiceSummary = new PendingChoiceSummary("choice-1", "seat-red", "s1-u3", DateTimeOffset.UtcNow.AddSeconds(45)),
            LegalActions = [new LegalAction("resolveChoice", "s1-u3", [])],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        var prompt = cut.Find("[data-testid=glare-prompt]");
        Assert.Contains("Moonlight Glare", prompt.TextContent);
        Assert.Contains("defense", prompt.TextContent);
        Assert.True(cut.Find("button[data-choice='reroll:0']").HasAttribute("disabled"));

        cut.Find("button[data-choice='reroll:1']").Click();

        Assert.Equal("resolveChoice", sent.Single().Kind);
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("choice-1", payload.GetProperty("choiceId").GetString());
        Assert.Equal("reroll:1", payload.GetProperty("option").GetString());
    }

    [Fact]
    public void The_other_player_waits_and_may_keep_for_them_once_allowed()
    {
        var sent = new List<GameCommandRequest>();
        var waiting = BattleFixtures.Projection() with
        {
            PendingChoiceSummary = new PendingChoiceSummary("choice-1", "seat-blue", "s2-u1", DateTimeOffset.UtcNow.AddSeconds(30)),
            LegalActions = [],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, waiting)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Contains("Waiting for your opponent's decision", cut.Find("[data-testid=choice-waiting]").TextContent);
        Assert.Empty(cut.FindAll("button[data-action=keepForThem]"));
        Assert.Empty(cut.FindAll("button[data-action=endTurn]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, waiting with { LegalActions = [new LegalAction("resolveChoice", "s2-u1", [])] }));
        cut.Find("button[data-action=keepForThem]").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("keep", payload.GetProperty("option").GetString());
        Assert.Equal("choice-1", payload.GetProperty("choiceId").GetString());
    }

    [Fact]
    public void Projected_movement_paths_preview_from_pointer_and_keyboard_with_hazards_and_forced_steps()
    {
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, TerrainMovementProjection()));
        cut.Find("[data-hex='0,0']").Click();

        var destination = cut.Find("[data-hex='3,0']");
        destination.MouseEnter();

        Assert.Equal(["1", "2", "3"], cut.FindAll("[data-path-step]").Select(marker => marker.GetAttribute("data-path-step")!).ToArray());
        Assert.Equal(2, cut.FindAll("[data-path-connector]").Count);
        Assert.Equal("lava", cut.Find("[data-hex='2,0'] [data-hazard]").GetAttribute("data-hazard"));
        Assert.Contains("forced-slide", cut.Find("[data-hex='3,0']").ClassList);
        Assert.Contains("5 damage dice", cut.Find("[data-testid=movement-preview]").TextContent);

        destination.MouseLeave();
        Assert.Empty(cut.FindAll("[data-path-step]"));
        destination.TriggerEvent("onfocus", new FocusEventArgs());
        Assert.Equal(3, cut.FindAll("[data-path-step]").Count);
    }

    [Fact]
    public void Hovering_a_destination_keeps_every_hex_handler_bound()
    {
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, TerrainMovementProjection()));
        cut.Find("[data-hex='0,0']").Click();
        string[] Handlers() => cut.FindAll("[data-hex]")
            .SelectMany(hex => hex.Attributes.Where(attribute => attribute.Name.StartsWith("blazor:on", StringComparison.Ordinal)))
            .Select(attribute => $"{attribute.Name}={attribute.Value}")
            .ToArray();
        var before = Handlers();

        cut.Find("[data-hex='3,0']").MouseEnter();

        Assert.NotEmpty(before);
        Assert.Equal(before, Handlers());
    }

    [Fact]
    public void Safe_movement_sends_the_projected_path_immediately()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TerrainMovementProjection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-hex='1,0']").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("moveUnit", sent.Single().Kind);
        Assert.Equal("s1-u3", payload.GetProperty("unitId").GetString());
        Assert.Equal(["1,0"], payload.GetProperty("path").EnumerateArray().Select(step => step.GetString()!).ToArray());
    }

    [Fact]
    public void First_tap_on_hazardous_movement_requires_confirmation_and_cancel_is_safe()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TerrainMovementProjection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();

        cut.Find("[data-hex='3,0']").Click();

        var dialog = cut.Find("[data-testid=movement-confirmation]");
        Assert.Equal("dialog", dialog.GetAttribute("role"));
        Assert.Contains("5 damage dice", dialog.TextContent);
        Assert.Empty(sent);
        cut.Find("button[data-action=cancelMovement]").Click();
        Assert.Empty(cut.FindAll("[data-testid=movement-confirmation]"));
        Assert.Empty(sent);

        cut.Find("[data-hex='3,0']").Click();
        cut.Find("button[data-action=confirmMovement]").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal(["1,0", "2,0", "3,0"], payload.GetProperty("path").EnumerateArray().Select(step => step.GetString()!).ToArray());
    }

    [Fact]
    public void Ice_collision_choices_resolve_direct_options_or_target_only_projected_swing_exits()
    {
        var sent = new List<GameCommandRequest>();
        var projection = TerrainMovementProjection() with
        {
            PendingChoice = new PendingChoiceProjection("ice-1", "seat-red", "iceCollision", ["continue", "stop", "swing:1,1"])
            {
                UnitId = "s1-u3",
                TargetHexes = ["1,1"],
            },
            LegalActions = [new LegalAction("resolveChoice", "s1-u3", [])],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        var prompt = cut.Find("[data-testid=ice-choice]");
        Assert.Equal("dialog", prompt.GetAttribute("role"));
        Assert.Empty(cut.FindAll("button[data-action=endTurn]"));
        cut.Find("button[data-choice=continue]").Click();
        Assert.Equal("continue", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("option").GetString());

        sent.Clear();
        cut.Find("button[data-choice=stop]").Click();
        Assert.Equal("stop", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("option").GetString());

        sent.Clear();
        cut.Find("button[data-action=chooseSwing]").Click();
        Assert.Contains("swing-target", cut.Find("[data-hex='1,1']").ClassList);
        Assert.DoesNotContain("swing-target", cut.Find("[data-hex='2,0']").ClassList);
        cut.Find("[data-hex='1,1']").Click();
        Assert.Equal("swing:1,1", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("option").GetString());

        var reconnected = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        Assert.Equal("Ice collision", reconnected.Find("[data-testid=ice-choice] h2").TextContent);
        Assert.Empty(reconnected.FindAll("button[data-action=endTurn]"));
    }

    [Fact]
    public void Terrain_hazards_and_slipping_direction_are_exposed_in_accessible_labels_and_distinct_markers()
    {
        var projection = TerrainMovementProjection();
        projection = projection with
        {
            Units = [projection.Units[0] with
            {
                Conditions = [
                    new ConditionProjection("Unsteady", "whileOnTerrain"),
                    new ConditionProjection("Slipping", "untilCleared") { Data = new ConditionDataProjection { Direction = [1, 0] } },
                    new ConditionProjection("Poisoned", "untilCleared"),
                ],
            }],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("Lava lava flow", cut.Find("[data-hex='2,0']").GetAttribute("aria-label"));
        Assert.Contains("5 damage dice", cut.Find("[data-hex='2,0']").GetAttribute("aria-label"));
        Assert.Contains("Slipping east", cut.Find("[data-hex='0,0']").GetAttribute("aria-label"));
        Assert.Equal(3, cut.FindAll("[data-hex='0,0'] [data-condition-marker]").Count);
        Assert.Equal("−1 Power and no disengagement strikes", ConditionText.Summary("Unsteady"));
        Assert.Equal(-1, ConditionText.StatDelta("Unsteady", "power"));
        Assert.Contains("wound", ConditionText.Summary("Poisoned"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("must continue", cut.Find("[data-condition='Slipping']").TextContent);
        Assert.Contains("until cleared", cut.Find("[data-condition='Poisoned']").TextContent);
    }

    [Fact]
    public void Board_view_controls_offer_fit_and_report_zoom_accessibly()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, TerrainMovementProjection()));

        Assert.Equal("Fit", cut.Find("button[aria-label='Fit whole battlefield']").TextContent);
        Assert.Equal("status", cut.Find("[data-testid=viewport-status]").GetAttribute("role"));
        Assert.Contains("Zoom", cut.Find("[data-testid=viewport-status]").TextContent);
        Assert.NotNull(cut.Find(".board-interaction-surface"));
    }

    [Fact]
    public void Pointer_drag_suppresses_the_following_hex_click_but_small_motion_and_keyboard_still_activate()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var sent = new List<GameCommandRequest>();
        var pausedFollow = false;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TerrainMovementProjection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request))
            .Add(component => component.OnFollowEvents, () => pausedFollow = true));
        cut.Find("[data-hex='0,0']").Click();
        var board = cut.Find(".board-interaction-surface");
        var destination = cut.Find("[data-hex='1,0']");

        board.PointerDown(new PointerEventArgs { PointerId = 1, ClientX = 10, ClientY = 10, PointerType = "mouse" });
        board.PointerMove(new PointerEventArgs { PointerId = 1, ClientX = 16, ClientY = 10, PointerType = "mouse" });
        board.PointerUp(new PointerEventArgs { PointerId = 1, ClientX = 16, ClientY = 10, PointerType = "mouse" });
        destination.Click();
        Assert.Empty(sent);
        Assert.True(pausedFollow);

        board.PointerDown(new PointerEventArgs { PointerId = 2, ClientX = 10, ClientY = 10, PointerType = "mouse" });
        board.PointerMove(new PointerEventArgs { PointerId = 2, ClientX = 15, ClientY = 10, PointerType = "mouse" });
        board.PointerUp(new PointerEventArgs { PointerId = 2, ClientX = 15, ClientY = 10, PointerType = "mouse" });
        destination.Click();
        Assert.Single(sent);

        sent.Clear();
        destination.KeyDown(new KeyboardEventArgs { Key = "Enter" });
        Assert.Single(sent);
    }

    [Fact]
    public void Ordinary_hex_pointers_are_not_captured_by_the_parent_svg()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, TerrainMovementProjection()));
        var board = cut.Find(".board-interaction-surface");
        var hex = cut.Find("[data-hex='1,0']");

        hex.PointerDown(new PointerEventArgs { PointerId = 11, ClientX = 100, ClientY = 100, PointerType = "mouse" });
        hex.PointerUp(new PointerEventArgs { PointerId = 11, ClientX = 100, ClientY = 100, PointerType = "mouse" });
        Assert.DoesNotContain(JSInterop.Invocations, invocation => invocation.Identifier is "capturePointer" or "releasePointer");

        board.PointerDown(new PointerEventArgs { PointerId = 12, ClientX = 10, ClientY = 10, PointerType = "mouse" });
        board.PointerUp(new PointerEventArgs { PointerId = 12, ClientX = 10, ClientY = 10, PointerType = "mouse" });
        Assert.Contains(JSInterop.Invocations, invocation => invocation.Identifier == "capturePointer");
        Assert.Contains(JSInterop.Invocations, invocation => invocation.Identifier == "releasePointer");
    }

    [Fact]
    public void Pinch_always_suppresses_hex_activation()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TerrainMovementProjection())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));
        cut.Find("[data-hex='0,0']").Click();
        var board = cut.Find(".board-interaction-surface");
        var destination = cut.Find("[data-hex='1,0']");

        destination.PointerDown(new PointerEventArgs { PointerId = 1, ClientX = 100, ClientY = 100, PointerType = "touch" });
        board.PointerDown(new PointerEventArgs { PointerId = 2, ClientX = 200, ClientY = 100, PointerType = "touch" });
        board.PointerMove(new PointerEventArgs { PointerId = 2, ClientX = 250, ClientY = 100, PointerType = "touch" });
        board.PointerUp(new PointerEventArgs { PointerId = 2, ClientX = 250, ClientY = 100, PointerType = "touch" });
        board.PointerUp(new PointerEventArgs { PointerId = 1, ClientX = 100, ClientY = 100, PointerType = "touch" });
        destination.Click();

        Assert.Empty(sent);
    }

    [Fact]
    public void A_stunned_unit_shows_its_condition_on_the_card_token_and_label()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with { EffectivePower = 1, Conditions = [new ConditionProjection("Stunned", "endOfNextTurn")] },
                projection.Units[1],
                projection.Units[2],
            ],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        Assert.Equal(
            "Stunned — can't move, no parting strikes, −2 Power, until the end of its next turn",
            cut.Find("[data-testid=selected-unit] [data-condition='Stunned']").TextContent.Trim());
        Assert.Contains("Stunned −2", cut.Find("[data-testid=selected-unit] [data-stat='power']").GetAttribute("title"));
        Assert.Single(cut.FindAll("[data-hex='1,4'] .condition-marker"));
        Assert.Contains("Stunned", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
    }

    private static GameProjection TeleportBoard()
    {
        var hexes = new List<HexProjection>
        {
            new(0, 0, "plains", "grass", 0, false, "s1-hero"),
            new(1, 0, "plains", "grass", 0, false, "s1-u1"),
            new(2, 0, "plains", "grass", 0, false, null),
            new(3, 0, "stone", "crags", 2, false, null),
            new(4, 0, "plains", "grass", 0, false, null),
            new(5, 0, "plains", "grass", 0, false, null),
            new(6, 0, "plains", "grass", 0, false, "s2-u1"),
            new(0, 1, "stone", "crags", 0, true, null),
        };
        UnitProjection Unit(string id, string seat, string hex, bool hero = false) => new(id, seat, hero, id, "", 3, 1, 3, 2, 5, 0, hex, false);
        return BattleFixtures.Projection() with
        {
            Board = new BoardProjection(7, 2, hexes),
            Units = [
                Unit("s1-hero", "seat-red", "0,0", hero: true) with { Abilities = [new AbilityProjection("Teleport", "Attack", "Move a friendly unit") { Playable = true }] },
                Unit("s1-u1", "seat-red", "1,0"),
                Unit("s2-u1", "seat-blue", "6,0"),
            ],
            LegalActions = [new LegalAction("specialAttack", "s1-hero", ["0,0", "1,0"]) { Ability = "Teleport" }, new LegalAction("endTurn", null, [])],
        };
    }

    private static GameProjection TerrainMovementProjection()
    {
        var projection = BattleFixtures.Projection();
        return projection with
        {
            Board = new BoardProjection(4, 2, [
                new HexProjection(0, 0, "plains", "grass", 0, false, "s1-u3"),
                new HexProjection(1, 0, "water", "shallow water", 0, false, null),
                new HexProjection(2, 0, "lava", "lava flow", 0, false, null),
                new HexProjection(3, 0, "ice", "ice sheet", 0, false, null),
                new HexProjection(1, 1, "plains", "grass", 0, false, null),
            ]),
            Units = [projection.Units[0] with { Hex = "0,0" }],
            LegalActions = [
                new LegalAction("moveUnit", "s1-u3", ["1,0", "3,0"])
                {
                    MovementOptions = [
                        new MovementOptionProjection("1,0", ["1,0"], 1, []),
                        new MovementOptionProjection("3,0", ["1,0", "2,0", "3,0"], 3, [
                            new MovementHazardProjection("damage", "lava", "2,0", 5, false, false),
                            new MovementHazardProjection("slide", "ice", "3,0", 0, true, false),
                        ]),
                    ],
                },
                new LegalAction("endTurn", null, []),
            ],
        };
    }

    [Fact]
    public void Teleport_picks_a_unit_then_a_destination_and_sends_both()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TeleportBoard())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("button[data-use-ability='Teleport']").Click();
        Assert.Contains("Teleport: choose a unit", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ally-target", cut.Find("[data-hex='1,0']").ClassList);

        cut.Find("[data-hex='1,0']").Click();
        Assert.Contains("Teleport: choose where", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("destination", cut.Find("[data-hex='3,0']").ClassList);
        Assert.Contains("destination", cut.Find("[data-hex='4,0']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='5,0']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='0,1']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='6,0']").ClassList);

        cut.Find("button[data-action=teleportBack]").Click();
        Assert.Contains("Teleport: choose a unit", cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='1,0']").Click();
        cut.Find("[data-hex='3,0']").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("specialAttack", sent.Single().Kind);
        Assert.Equal("Teleport", payload.GetProperty("ability").GetString());
        Assert.Equal("s1-u1", payload.GetProperty("targetUnitIds")[0].GetString());
        Assert.Equal("3,0", payload.GetProperty("toHex").GetString());
    }

    [Fact]
    public void Hovering_a_gust_target_previews_its_push_path()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Gust of Wind", "Ability", "Push back") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("useAbility", "s1-u3", ["2,4"]) { Ability = "Gust of Wind" }],
            Board = projection.Board with
            {
                Hexes = [
                    new HexProjection(1, 4, "plains", "roads", 0, false, "s1-u3"),
                    new HexProjection(2, 4, "plains", "roads", 0, false, "s2-u1"),
                    new HexProjection(3, 4, "plains", "roads", 0, false, null),
                    new HexProjection(4, 4, "stone", "stone-path", 1, false, null),
                ],
            },
        };
        projection = projection with { Units = [projection.Units[0], projection.Units[1] with { Hex = "2,4" }, projection.Units[2]] };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Gust of Wind']").Click();
        cut.Find("[data-hex='2,4']").MouseEnter();

        Assert.Contains("push-path", cut.Find("[data-hex='3,4']").ClassList);
        Assert.Contains("push-path", cut.Find("[data-hex='4,4']").ClassList);
    }
}

internal static class BattleFixtures
{
    public static GameProjection Projection() => new(
        "game-fixture",
        21,
        GameLifecycle.Active,
        GameMode.VanillaPvp,
        new ViewerPermissions("player", "seat-red", true, true),
        new BoardProjection(11, 9, [
            new HexProjection(1, 4, "plains", "roads", 0, false, "s1-u3"),
            new HexProjection(2, 4, "plains", "roads", 0, false, null),
            new HexProjection(3, 4, "stone", "stone-path", 1, false, "s2-u1"),
            new HexProjection(4, 4, "stone", "stone-path", 1, false, null),
        ]),
        new TurnProjection(2, "seat-red", 3),
        null,
        [
            new LegalAction("moveUnit", "s1-u3", ["2,4"]),
            new LegalAction("attackUnit", "s1-u3", ["3,4"]),
            new LegalAction("endTurn", null, []),
        ],
        new Dictionary<string, int> { ["seat-red"] = 0, ["seat-blue"] = 0 },
        null)
    {
        ScenarioName = "Proving Field",
        Memberships = [
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, true, false),
        ],
        Units = [
            new UnitProjection("s1-u3", "seat-red", false, "Ranger", "/images/units/gold_ranger_card.png", 3, 6, 3, 2, 4, 1, "1,4", false),
            new UnitProjection("s2-u1", "seat-blue", false, "Grunt", "/images/units/black_grunt_card.png", 2, 1, 4, 3, 5, 0, "3,4", false),
            new UnitProjection("s2-u2", "seat-blue", false, "Brute", "/images/units/black_tank_card.png", 2, 1, 4, 4, 6, 6, null, false),
        ]
    };
}
