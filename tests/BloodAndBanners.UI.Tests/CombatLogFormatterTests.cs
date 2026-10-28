namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class CombatLogFormatterTests
{
    [Theory]
    [InlineData(2)]
    [InlineData(5)]
    [InlineData(3)]
    public void Rage_history_shows_the_level_at_the_time_of_each_change(int level)
    {
        var payload = JsonSerializer.Serialize(new { unitId = "s1-u3", name = "Enraged", data = new { level } });
        var line = CombatLogFormatter.Describe(Event("conditionApplied", payload), BattleFixtures.Projection());
        Assert.Contains($"Enraged +{level}", line);
    }

    [Theory]
    [InlineData("seat-red", null, "ice-crystal", "You claim the Ice Crystal.")]
    [InlineData("seat-blue", "seat-red", "fire-crystal", "Rival claims the Fire Crystal from you.")]
    public void Crystal_claims_name_the_player_and_the_crystal(string seatId, string? previous, string kind, string expected)
    {
        var payload = JsonSerializer.Serialize(new { seatId, unitId = "s1-u3", hex = "4,4", kind, previousSeatId = previous });
        Assert.Equal(expected, CombatLogFormatter.Describe(Event("crystalClaimed", payload), BattleFixtures.Projection()));
    }

    [Fact]
    public void Picking_up_dropping_and_losing_items_read_naturally()
    {
        var projection = BattleFixtures.Projection();
        Assert.EndsWith("picks up a Power Potion.", CombatLogFormatter.Describe(Event("itemPickedUp", """{"unitId":"s1-u3","seatId":"seat-red","hex":"1,4","kind":"power-potion"}"""), projection));
        Assert.EndsWith("drops a Health Potion.", CombatLogFormatter.Describe(Event("itemDropped", """{"unitId":"s1-u3","hex":"1,4","kind":"health-potion"}"""), projection));
        Assert.EndsWith("'s Speed Potion is lost.", CombatLogFormatter.Describe(Event("itemLost", """{"unitId":"s1-u3","kind":"speed-potion"}"""), projection));
    }

    [Fact]
    public void Dragon_slayer_history_reports_the_permanent_award_level()
    {
        var line = CombatLogFormatter.Describe(
            Event("conditionApplied", """{"unitId":"s1-u3","name":"Dragon Slayer","data":{"level":2}}"""), BattleFixtures.Projection());
        Assert.Contains("Dragon Slayer +2", line);
        Assert.Contains("rest of the game", line);
    }

    [Theory]
    [InlineData(true, "Grunt reflects incoming damage with Reflective Surface (rolled 16).")]
    [InlineData(false, "Grunt fails to reflect incoming damage with Reflective Surface (rolled 16).")]
    public void Reflection_saves_explain_whether_damage_is_returned(bool saved, string expected)
    {
        var payload = JsonSerializer.Serialize(new { unitId = "s2-u1", sourceUnitId = "s1-u3", ability = "Reflective Surface", roll = 16, saved });
        Assert.Equal(expected, CombatLogFormatter.Describe(Event("saveRolled", payload), BattleFixtures.Projection()));
    }

    [Fact]
    public void Reflected_attacks_identify_the_reflector_and_damage_recipient()
    {
        var line = CombatLogFormatter.Describe(
            Event("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s1-u3","reflectedByUnitId":"s2-u1","attackFaces":["sword","sword"],"defenseFaces":["shield"],"wounds":1}"""),
            BattleFixtures.Projection());
        Assert.Contains("reflected by Grunt", line);
        Assert.Contains("Ranger → Ranger", line);
        Assert.Contains("1 wound", line);
    }

    [Fact]
    public void Attacks_show_both_dice_rolls_and_wounds()
    {
        var line = CombatLogFormatter.Describe(
            Event("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","attackFaces":["sword","sword","sword","shield"],"defenseFaces":["shield","blank"],"wounds":2}"""),
            BattleFixtures.Projection());

        Assert.Equal("Ranger → Grunt: ⚔⚔⚔🛡 vs 🛡⬚ — 2 wounds", line);
    }

    [Fact]
    public void Dragon_breath_log_identifies_the_shared_roll_and_splash_sword_penalty()
    {
        var line = CombatLogFormatter.Describe(
            Event("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","ability":"Dragon Breath","attackFaces":["sword","sword","sword","shield","blank","blank"],"defenseFaces":["shield"],"sharedAttackRoll":true,"attackPenalty":1,"damageType":"fire","wounds":1}"""),
            BattleFixtures.Projection());
        Assert.Contains("shared roll", line);
        Assert.Contains("− 1 sword", line);
        Assert.Contains("1 wound", line);
    }

    [Fact]
    public void Moves_report_disengagement_strikes_and_turns_name_the_next_commander()
    {
        var projection = BattleFixtures.Projection();

        Assert.Equal(
            "Ranger moves to 2,4. Grunt strikes as it disengages: ⚔ — 1 wound.",
            CombatLogFormatter.Describe(Event("unitMoved", """{"unitId":"s1-u3","from":"1,4","to":"2,4","disengagement":[{"byUnitId":"s2-u1","faces":["sword"],"wounds":1}]}"""), projection));
        Assert.Equal(
            "Round 3: Rival's turn.",
            CombatLogFormatter.Describe(Event("turnEnded", """{"seatId":"seat-red","nextSeatId":"seat-blue","round":3}"""), projection));
        Assert.Equal(
            "You won by annihilation.",
            CombatLogFormatter.Describe(Event("matchCompleted", """{"winningSeatId":"seat-red","reason":"annihilation"}"""), projection));
        Assert.Null(CombatLogFormatter.Describe(Event("readinessChanged", """{"ready":true}"""), projection));
    }

    [Fact]
    public void Malformed_payloads_yield_no_line_instead_of_throwing()
    {
        var projection = BattleFixtures.Projection();

        Assert.Null(CombatLogFormatter.Describe(Event("attackResolved", "{\"unitId\":\"s1-u3\"}"), projection));
        Assert.Null(CombatLogFormatter.Describe(Event("unitMoved", "{}"), projection));
        Assert.Null(CombatLogFormatter.Describe(Event("turnEnded", "{\"round\":\"x\",\"nextSeatId\":\"seat-red\"}"), projection));
        Assert.Null(CombatLogFormatter.Describe(new GameEvent(1, 1, "unitDefeated", null, [], default, DateTimeOffset.UnixEpoch), projection));
    }


    [Theory]
    [InlineData("evaded", """{"unitId":"s2-u1","attackerId":"s1-u3","roll":14,"evaded":true}""", "Grunt evades (rolled 14).")]
    [InlineData("evaded", """{"unitId":"s2-u1","attackerId":"s1-u3","roll":4,"evaded":false}""", "Grunt fails to evade (rolled 4).")]
    [InlineData("survivedDefeat", """{"unitId":"s2-u1","ability":"One Last Horn"}""", "Grunt refuses to fall: One Last Horn.")]
    [InlineData("chaosRolled", """{"unitId":"s1-u3","roll":6,"struck":false}""", "Chaos Strike falters (rolled 6).")]
    [InlineData("chaosRolled", """{"unitId":"s1-u3","roll":15,"struck":true}""", "Chaos Strike surges (rolled 15)!")]
    [InlineData("splashDamage", """{"ability":"Fireball","hits":[{"unitId":"s2-u1","faces":["sword"],"wounds":1},{"unitId":"s2-u2","faces":["blank"],"wounds":0}]}""", "Fireball scorches Grunt for 1 and Brute for 0.")]
    [InlineData("abilityUsed", """{"unitId":"s1-u3","ability":"Shield Wall","targetUnitId":"s2-u1"}""", "Ranger uses Shield Wall on Grunt.")]
    [InlineData("sporesReleased", """{"unitId":"s1-u3","ability":"Spore Cloud","targetUnitIds":["s2-u1"]}""", "Ranger releases spores (Spore Cloud).")]
    [InlineData("effectApplied", """{"name":"Rune of Distraction","sourceUnitId":"s1-u3","targetUnitId":"s2-u1","stat":"defense","amount":-2,"expires":{"seatId":"seat-red","at":"turnStart"}}""", "Grunt gets -2 Defense (Rune of Distraction).")]
    [InlineData("effectExpired", """{"name":"Shield Wall","targetUnitId":"s2-u1"}""", "Shield Wall fades from Grunt.")]
    [InlineData("choiceOpened", """{"choiceId":"choice-1","seatId":"seat-blue","kind":"moonlightGlare","unitId":"s2-u1"}""", "Grunt may re-roll a die (Moonlight Glare).")]
    [InlineData("choiceOpened", """{"choiceId":"choice-2","seatId":"seat-red","kind":"iceCollision","unitId":"s1-u3"}""", "Ranger must choose how to pass an ally on the ice.")]
    [InlineData("choiceResolved", """{"choiceId":"choice-1","option":"keep"}""", "The roll stands.")]
    [InlineData("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","ability":"Fireball","attackFaces":["sword"],"defenseFaces":["shield"],"rerolled":{"side":"defense","index":0,"from":"blank","to":"shield"},"wounds":0}""", "Ranger → Grunt (Fireball): ⚔ vs 🛡 — 0 wounds. Re-rolled a blank: shield.")]
    [InlineData("unitMoved", """{"unitId":"s1-u3","from":"1,4","to":"2,4","disengagement":[{"byUnitId":"s2-u1","faces":[],"wounds":0,"evasionRoll":15,"evaded":true}]}""", "Ranger moves to 2,4. It evades Grunt's parting strike (rolled 15).")]
    public void New_ability_events_have_readable_lines(string kind, string payload, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }

    [Fact]
    public void A_glare_reroll_is_reported_on_the_attack_not_the_choice()
    {
        Assert.Null(CombatLogFormatter.Describe(Event("choiceResolved", """{"choiceId":"choice-1","option":"reroll:1"}"""), BattleFixtures.Projection()));
    }

    [Theory]
    [InlineData("terrainEntered", """{"unitId":"s1-u3","terrain":"lava-flow","hex":"2,4","movementKind":"voluntary"}""", "Ranger enters lava flow at 2,4.")]
    [InlineData("movementStopped", """{"unitId":"s1-u3","hex":"2,4","reason":"water"}""", "Ranger's movement stops in water at 2,4.")]
    [InlineData("movementStopped", """{"unitId":"s1-u3","hex":"2,4","reason":"poison"}""", "Ranger's movement stops in poison at 2,4.")]
    [InlineData("terrainIgnored", """{"unitId":"s1-u3","terrain":"lava-flow","ability":"Lava Walker","hex":"2,4"}""", "Ranger ignores lava flow with Lava Walker.")]
    [InlineData("terrainIgnored", """{"unitId":"s1-u3","terrain":"open-water","ability":"Flying","hex":"2,4"}""", "Ranger flies over open water.")]
    [InlineData("terrainDamage", """{"unitId":"s1-u3","terrain":"lava-flow","cause":"entry","dice":5,"faces":["blank","blank","shield","shield","blank"],"wounds":0,"hex":"2,4"}""", "Ranger enters lava flow: ⬚⬚🛡🛡⬚ — 0 wounds (no Defense).")]
    [InlineData("terrainDamage", """{"unitId":"s1-u3","terrain":"lava","cause":"turnEnd","dice":5,"faces":["sword","blank","blank","shield","blank"],"wounds":1,"hex":"2,4"}""", "Lava burns Ranger at turn end: ⚔⬚⬚🛡⬚ — 1 wound (no Defense).")]
    [InlineData("terrainDamage", """{"unitId":"s1-u3","terrain":"fall","cause":"slipFall","dice":2,"faces":["sword","sword"],"wounds":2,"hex":"2,4"}""", "Ranger falls while sliding: ⚔⚔ — 2 wounds (no Defense).")]
    [InlineData("terrainDamage", """{"unitId":"s2-u1","terrain":"ice","cause":"collision","dice":1,"faces":["blank"],"wounds":0,"hex":"3,4"}""", "Grunt hits another unit on the ice: ⬚ — 0 wounds (no Defense).")]
    [InlineData("poisonRolled", """{"unitId":"s1-u3","hex":"2,4","faces":["sword"],"wounds":1,"cleared":false}""", "Poison wracks Ranger: ⚔ — 1 wound; it remains poisoned.")]
    [InlineData("poisonRolled", """{"unitId":"s1-u3","hex":"2,4","faces":["shield"],"wounds":0,"cleared":true}""", "Ranger fights off the poison: 🛡 — 0 wounds.")]
    [InlineData("poisonRolled", """{"unitId":"s1-u3","hex":"2,4","faces":["shield"],"wounds":0,"cleared":false}""", "Ranger resists the poison: 🛡 — 0 wounds; it remains poisoned.")]
    public void Terrain_events_have_concise_authoritative_lines(string kind, string payload, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }

    [Theory]
    [InlineData("exit", "Ranger slides 2 hexes to 3,4 and leaves the ice.")]
    [InlineData("blocked", "Ranger slides 2 hexes to 3,4 and stops at an obstacle.")]
    [InlineData("moveSpent", "Ranger slides 2 hexes to 3,4 and runs out of movement.")]
    [InlineData("enemyCollision", "Ranger slides 2 hexes to 3,4 and collides with an enemy.")]
    [InlineData("friendlyStop", "Ranger slides 2 hexes to 3,4 and stops before an ally.")]
    [InlineData("defeated", "Ranger slides 2 hexes to 3,4 and falls.")]
    public void Ice_slide_stop_reasons_are_explained(string stopped, string expected)
    {
        var payload = $$"""{"unitId":"s1-u3","from":"1,4","to":"3,4","path":["2,4","3,4"],"direction":[1,0],"stopped":"{{stopped}}"}""";
        Assert.Equal(expected, CombatLogFormatter.Describe(Event("unitSlid", payload), BattleFixtures.Projection()));
    }

    [Theory]
    [InlineData("terrainEntered", """{"unitId":"s1-u3"}""")]
    [InlineData("movementStopped", """{"unitId":"s1-u3","hex":"2,4"}""")]
    [InlineData("terrainIgnored", """{"unitId":"s1-u3","ability":"Flying"}""")]
    [InlineData("terrainDamage", """{"unitId":"s1-u3","cause":"entry","wounds":1}""")]
    [InlineData("poisonRolled", """{"unitId":"s1-u3","wounds":0}""")]
    [InlineData("unitSlid", """{"unitId":"s1-u3","path":[]}""")]
    public void Partial_terrain_payloads_are_silent(string kind, string payload)
    {
        Assert.Null(CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }

    private static GameEvent Event(string kind, string payload) =>
        new(1, 1, kind, null, [], JsonSerializer.Deserialize<JsonElement>(payload), DateTimeOffset.UnixEpoch);

    [Theory]
    [InlineData("saveRolled", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","ability":"Knockback","roll":14,"saved":true}""", "Grunt resists Knockback (rolled 14).")]
    [InlineData("saveRolled", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","ability":"Stunning Blow","roll":3,"saved":false}""", "Grunt fails to resist Stunning Blow (rolled 3).")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"4,4","path":["2,4","3,4","4,4"],"collisions":[],"falls":[],"blocked":null}""", "Grunt is driven back to 4,4.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"2,4","path":["2,4"],"collisions":[{"unitId":"s2-u1","faces":["sword"],"wounds":1},{"unitId":"s2-u2","faces":["shield"],"wounds":0}],"falls":[],"blocked":"unit"}""", "Grunt is driven back but slams into Brute. Grunt: 1 wound. Brute: 0 wounds.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"2,4","path":["2,4"],"collisions":[{"unitId":"s2-u1","faces":["blank"],"wounds":0}],"falls":[],"blocked":"edge"}""", "Grunt is driven back but hits the edge of the battlefield. Grunt: 0 wounds.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"3,4","path":["2,4","3,4"],"collisions":[],"falls":[{"levels":2,"faces":["sword","blank"],"wounds":1}],"blocked":null}""", "Grunt is driven back to 3,4. It falls 2 levels: 1 wound.")]
    [InlineData("conditionApplied", """{"name":"Stunned","unitId":"s2-u1","sourceUnitId":"s1-u3","expires":{"kind":"afterTurn","turnNumber":2}}""", "Grunt is stunned.")]
    [InlineData("conditionExpired", """{"name":"Stunned","unitId":"s2-u1"}""", "Grunt is no longer stunned.")]
    [InlineData("unitTeleported", """{"unitId":"s2-u1","casterId":"s1-u3","from":"2,4","to":"5,4"}""", "Ranger teleports Grunt to 5,4.")]
    [InlineData("unitTeleported", """{"unitId":"s1-u3","casterId":"s1-u3","from":"1,4","to":"3,4"}""", "Ranger teleports to 3,4.")]
    public void Forced_movement_and_condition_events_have_readable_lines(string kind, string payload, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }

    [Fact]
    public void Co_op_events_name_the_void_lord_and_the_winning_side()
    {
        var projection = new GameProjection("g", 1, GameLifecycle.Active, GameMode.Coop,
            new ViewerPermissions("player", "seat-a", true, false), new BoardProjection(0, 0, []), new TurnProjection(1, "seat-a", 5),
            null, [], new Dictionary<string, int>(), null)
        {
            Memberships = [new MembershipProjection("seat-void", 1, true, true) { Role = "voidLord" }, new MembershipProjection("seat-a", 2, true, false)],
        };

        Assert.Equal("The Void Lord placed Ice Wolf.", CombatLogFormatter.Describe(Event("monsterPlaced", """{"unitId":"v-m1","monster":"Ice Wolf","hex":"5,0","cost":1}"""), projection));
        Assert.Equal("The Void Lord withdrew Ice Wolf.", CombatLogFormatter.Describe(Event("monsterRemoved", """{"unitId":"v-m1","monster":"Ice Wolf","hex":"5,0","cost":1}"""), projection));
        Assert.Equal("The Void Lord unleashed 4 monsters. The heroes draft.", CombatLogFormatter.Describe(Event("setupConfirmed", """{"monsters":4}"""), projection));
        Assert.Equal("The Void Lord's horde stands ready.", CombatLogFormatter.Describe(Event("setupConfirmed", "{}"), projection));
        Assert.Equal("The Void Lord's horde stands ready.", CombatLogFormatter.Describe(Event("setupConfirmed", "[]"), projection));
        Assert.Equal("Round 2: Void Lord's turn.", CombatLogFormatter.Describe(Event("turnEnded", """{"seatId":"seat-a","nextSeatId":"seat-void","round":2}"""), projection));
        Assert.Equal("The heroes have destroyed every monster.", CombatLogFormatter.Describe(Event("matchCompleted", """{"reason":"monstersDefeated","winningSeatId":null,"winningSide":"heroes"}"""), projection));
        Assert.Equal("The Void Lord has slain every hero.", CombatLogFormatter.Describe(Event("matchCompleted", """{"reason":"heroesDefeated","winningSeatId":"seat-void","winningSide":"void"}"""), projection));
    }

    [Fact]
    public void Initiative_lines_show_each_roll_and_the_winner()
    {
        var projection = BattleFixtures.Projection();

        Assert.Equal(
            "Initiative: You rolled 4, Rival rolled 2. You won.",
            CombatLogFormatter.Describe(Event("initiativeRolled", """{"rolls":{"seat-red":[4],"seat-blue":[2]},"order":["seat-red","seat-blue"]}"""), projection));
        Assert.Equal(
            "Initiative: You rolled 7 → 3, Rival rolled 7 → 15. Rival won.",
            CombatLogFormatter.Describe(Event("initiativeRolled", """{"rolls":{"seat-red":[7,3],"seat-blue":[7,15]},"order":["seat-blue","seat-red"]}"""), projection));
    }

    [Theory]
    [InlineData("seat-red", "seat-red", "You chose to move first.")]
    [InlineData("seat-red", "seat-blue", "You chose Rival to move first.")]
    [InlineData("seat-blue", "seat-red", "Rival chose you to move first.")]
    [InlineData("seat-blue", "seat-blue", "Rival chose to move first.")]
    public void First_player_lines_read_naturally(string picker, string first, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(
            Event("draftPicked", $$"""{"seatId":"{{picker}}","decision":"chooseFirstPlayer","value":"{{first}}"}"""),
            BattleFixtures.Projection()));
    }

    [Fact]
    public void Recent_lists_the_newest_readable_lines_first()
    {
        var projection = BattleFixtures.Projection();
        var events = new[]
        {
            Event("draftPicked", """{"seatId":"seat-red","decision":"pickHero","value":"Warden"}"""),
            Event("readinessChanged", """{"ready":true}"""),
            Event("draftPicked", """{"seatId":"seat-blue","decision":"pickArmy","value":"Blackmaw"}"""),
        };

        Assert.Equal(
            ["Rival chose Blackmaw.", "You chose Warden."],
            CombatLogFormatter.Recent(events, projection, 40).Select(line => line.Text));
        Assert.Equal(["Rival chose Blackmaw."], CombatLogFormatter.Recent(events, projection, 1).Select(line => line.Text));
    }

    [Fact]
    public void Turn_lines_use_the_chosen_name()
    {
        var projection = BattleFixtures.Projection() with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "Lord Ashfang" },
            ],
        };

        Assert.Equal(
            "Round 3: Lord Ashfang's turn.",
            CombatLogFormatter.Describe(Event("turnEnded", """{"seatId":"seat-red","nextSeatId":"seat-blue","round":3}"""), projection));
    }

    [Fact]
    public void Drinking_a_potion_names_the_unit_and_the_potion()
    {
        var payload = JsonSerializer.Serialize(new { unitId = "s1-u3", seatId = "seat-red", hex = "4,4", kind = "speed-potion" });
        var line = CombatLogFormatter.Describe(Event("potionDrunk", payload), BattleFixtures.Projection());
        Assert.EndsWith("drinks a Speed Potion.", line);
    }
}
