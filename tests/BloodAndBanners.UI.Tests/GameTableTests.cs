namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed partial class GameTableTests : BunitContext
{
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
