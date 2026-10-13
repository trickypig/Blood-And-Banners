namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class CombatLogFormatterTests
{
    [Fact]
    public void Attacks_show_both_dice_rolls_and_wounds()
    {
        var line = CombatLogFormatter.Describe(
            Event("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","attackFaces":["sword","sword","sword","shield"],"defenseFaces":["shield","blank"],"wounds":2}"""),
            BattleFixtures.Projection());

        Assert.Equal("Ranger → Grunt: ⚔⚔⚔🛡 vs 🛡⬚ — 2 wounds", line);
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
}
