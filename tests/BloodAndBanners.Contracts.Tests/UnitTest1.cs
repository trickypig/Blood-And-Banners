namespace BloodAndBanners.Contracts.Tests;

public sealed class ContractFixtureTests
{
    [Fact]
    public void GameProjection_fixture_round_trips_with_camel_case_contract()
    {
        var json = File.ReadAllText(FixturePath("game-projection.json"));

        var projection = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameProjection>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(projection);
        Assert.Equal("game-fixture", projection.GameId);
        Assert.Equal(global::BloodAndBanners.Contracts.GameLifecycle.Active, projection.Lifecycle);
        Assert.Equal(global::BloodAndBanners.Contracts.GameMode.VanillaPvp, projection.Mode);
        Assert.Equal("Training Grounds", projection.ScenarioName);
        Assert.True(projection.Viewer.CanCommand);
        Assert.Equal(2, projection.Memberships.Count);
        Assert.True(projection.Memberships[0].IsHost);
        Assert.True(projection.Memberships[1].Ready);
        Assert.Null(projection.Memberships[0].Name);
        Assert.Equal("Lord Ashfang", projection.Memberships[1].Name);
        Assert.Equal("unit-thorgrim", projection.Board.Hexes.Single().OccupantId);
        Assert.Equal("moveUnit", projection.LegalActions.Single().Kind);
        Assert.Equal("unit-thorgrim", projection.Turn.ActiveUnitId);
        Assert.Null(projection.Draft);
        Assert.True(projection.Units.Single().IsHero);
        Assert.Equal(2, projection.Units.Single().Wounds);
        var unit = projection.Units.Single();
        Assert.Equal("Shield Wall", unit.Abilities.Single().Name);
        Assert.True(unit.Abilities.Single().Playable);
        Assert.Equal(2, unit.Effects.Single().Amount);
        Assert.Equal(7, unit.EffectiveDefense);
        Assert.Equal("Stunned", unit.Conditions[0].Name);
        Assert.Null(unit.Conditions[0].Data.Direction);
        Assert.Equal(new[] { 1, 0 }, unit.Conditions[1].Data.Direction);
        Assert.Null(projection.LegalActions.Single().Ability);
        var movement = projection.LegalActions.Single().MovementOptions;
        Assert.Equal(2, movement.Count);
        Assert.Equal(new[] { "1,0" }, movement[0].Path);
        Assert.Empty(movement[0].Hazards);
        Assert.Equal(5, movement[1].Hazards.Single().Dice);
        Assert.Equal("lava", movement[1].Hazards.Single().Terrain);
        Assert.False(movement[1].Hazards.Single().Forced);
        Assert.Equal("iceCollision", projection.PendingChoice?.Kind);
        Assert.Equal(new[] { "1,1" }, projection.PendingChoice?.TargetHexes);
        Assert.Equal("choice-1", projection.PendingChoiceSummary?.ChoiceId);
        Assert.Equal("iceCollision", projection.PendingChoiceSummary?.Kind);
        Assert.Null(projection.PendingChoiceSummary?.KeepableAt);

        using var serialized = System.Text.Json.JsonDocument.Parse(
            System.Text.Json.JsonSerializer.Serialize(projection, global::BloodAndBanners.Contracts.ContractJson.Options));
        Assert.True(serialized.RootElement.TryGetProperty("gameId", out _));
        Assert.False(serialized.RootElement.TryGetProperty("GameId", out _));
        Assert.Equal("active", serialized.RootElement.GetProperty("lifecycle").GetString());
    }

    [Fact]
    public void Additive_terrain_fields_default_for_older_json()
    {
        var action = System.Text.Json.JsonSerializer.Deserialize<LegalAction>(
            """{"kind":"moveUnit","unitId":"u1","targetHexes":["1,0"]}""",
            ContractJson.Options);
        var condition = System.Text.Json.JsonSerializer.Deserialize<ConditionProjection>(
            """{"name":"Stunned","expires":"endOfNextTurn"}""",
            ContractJson.Options);
        var choice = System.Text.Json.JsonSerializer.Deserialize<PendingChoiceProjection>(
            """{"choiceId":"choice-1","seatId":"seat-a","kind":"moonlightGlare","options":["keep"]}""",
            ContractJson.Options);
        var summary = System.Text.Json.JsonSerializer.Deserialize<PendingChoiceSummary>(
            """{"choiceId":"choice-1","seatId":"seat-a","unitId":"u1","keepableAt":"2026-10-11T12:00:45Z"}""",
            ContractJson.Options);

        Assert.NotNull(action);
        Assert.Empty(action.MovementOptions);
        Assert.NotNull(condition);
        Assert.Null(condition.Data.Direction);
        Assert.NotNull(choice);
        Assert.Empty(choice.TargetHexes);
        Assert.NotNull(summary);
        Assert.Equal(string.Empty, summary.Kind);
        Assert.NotNull(summary.KeepableAt);
    }

    [Fact]
    public void CommandEnvelope_preserves_arbitrary_payload_for_the_authoritative_server()
    {
        var json = File.ReadAllText(FixturePath("command-envelope.json"));

        var command = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.CommandEnvelope>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(command);
        Assert.Equal(7, command.ExpectedVersion);
        Assert.Equal("moveUnit", command.Kind);
        Assert.Equal("unit-thorgrim", command.Payload.GetProperty("unitId").GetString());
        Assert.Equal(2, command.Payload.GetProperty("path").GetArrayLength());
    }

    [Fact]
    public void GameSeatSession_keeps_the_credential_outside_the_public_projection()
    {
        var json = File.ReadAllText(FixturePath("game-seat-session.json"));

        var session = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameSeatSession>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(session);
        Assert.Equal("game-fixture", session.Game.GameId);
        Assert.Equal("seat-one", session.Game.Viewer.SeatId);
        Assert.Equal("seat-secret", session.SeatToken);
        Assert.DoesNotContain("seatToken", System.Text.Json.JsonSerializer.Serialize(
            session.Game,
            global::BloodAndBanners.Contracts.ContractJson.Options));
    }

    [Fact]
    public void Scenario_fixture_carries_versioned_map_modes_and_scoring()
    {
        var json = File.ReadAllText(FixturePath("scenario-definition.json"));

        var scenario = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.ScenarioDefinition>(
            json,
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(scenario);
        Assert.Equal(1, scenario.SchemaVersion);
        Assert.Contains(GameMode.BattleRoyale, scenario.ModeCompatibility);
        Assert.Equal("plains", scenario.Map.Hexes.Single().Biome);
        Assert.Equal(10, scenario.Scoring.Target);
        Assert.Equal(2, scenario.Scoring.CompleteObjectSetBonus);
    }

    [Fact]
    public void Event_and_error_fixtures_preserve_recovery_and_random_outcomes()
    {
        var gameEvent = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameEvent>(
            File.ReadAllText(FixturePath("game-event.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);
        var error = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.ApiErrorResponse>(
            File.ReadAllText(FixturePath("api-error.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(gameEvent);
        Assert.Equal(9, gameEvent.Sequence);
        Assert.Equal(3, gameEvent.Payload.GetProperty("attackDice").GetArrayLength());
        Assert.NotNull(error);
        Assert.Equal("versionConflict", error.Error.Code);
        Assert.Equal(8, error.Error.CurrentVersion);
        Assert.Contains("Refresh", error.Error.Recovery);
    }

    [Fact]
    public void Draft_fixture_carries_rolls_open_claims_and_catalog_cards()
    {
        var draft = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.DraftProjection>(
            File.ReadAllText(FixturePath("draft-projection.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(draft);
        Assert.Equal(new[] { 12, 18 }, draft.InitiativeRolls["seat-red"]);
        Assert.Equal("seat-blue", draft.PickingSeatId);
        Assert.Null(draft.FirstSeatId);
        Assert.Equal(2, draft.StartBySeat["seat-blue"]);
        Assert.Null(draft.HeroBySeat["seat-blue"]);
        Assert.Equal("seat-red", draft.AvailableHeroes.Single().TakenBySeatId);
        Assert.Equal("Grunt", draft.AvailableArmies.Single().Units.Single().Name);
    }

    [Fact]
    public void Unit_fixture_carries_live_battle_state()
    {
        var unit = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.UnitProjection>(
            File.ReadAllText(FixturePath("unit-projection.json")),
            global::BloodAndBanners.Contracts.ContractJson.Options);

        Assert.NotNull(unit);
        Assert.Equal("s1-u3", unit.UnitId);
        Assert.Equal(6, unit.Range);
        Assert.Equal("2,4", unit.Hex);
        Assert.True(unit.ActivatedThisTurn);
    }

    [Fact]
    public void Coop_projection_fixture_deserializes_sides_setup_roles_and_monsters()
    {
        var json = File.ReadAllText(FixturePath("coop-projection.json"));
        var projection = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameProjection>(
            json, global::BloodAndBanners.Contracts.ContractJson.Options)!;

        Assert.Equal(global::BloodAndBanners.Contracts.GameLifecycle.Setup, projection.Lifecycle);
        Assert.Equal(global::BloodAndBanners.Contracts.GameMode.Coop, projection.Mode);
        Assert.Equal("voidLord", projection.Memberships[0].Role);
        Assert.Equal("warden", projection.Memberships[0].AiPersonality);
        Assert.Null(projection.Memberships[1].AiPersonality);
        Assert.Equal("fast", projection.AiPace);
        Assert.Equal("The Warden", projection.AiPersonalities.Single().Name);
        Assert.Equal(new[] { "seat-void" }, projection.Sides!["void"].SeatIds);
        Assert.Equal(12, projection.Setup!.Budget);
        Assert.Equal(1, projection.Setup.Roster.Single().Cost);
        Assert.Equal("seat-hero", projection.Turn.RespondingToSeatId);
        var wolf = projection.Units.Single();
        Assert.Equal("void", wolf.Side);
        Assert.Equal("common", wolf.Tier);
        Assert.False(wolf.InactiveAbilities.Single().Playable);
        Assert.Equal("heroes", projection.Result!.WinningSide);
    }

    [Fact]
    public void Duel_fixture_defaults_the_new_coop_fields()
    {
        var projection = System.Text.Json.JsonSerializer.Deserialize<global::BloodAndBanners.Contracts.GameProjection>(
            File.ReadAllText(FixturePath("game-projection.json")), global::BloodAndBanners.Contracts.ContractJson.Options)!;

        Assert.Null(projection.Sides);
        Assert.Null(projection.Setup);
        Assert.Equal("player", projection.Memberships[0].Role);
        Assert.Empty(projection.Units.Single().InactiveAbilities);
        Assert.Null(projection.Turn.RespondingToSeatId);
    }

    private static string FixturePath(string name) =>
        Path.Combine(AppContext.BaseDirectory, "fixtures", name);
}
