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

    private static GameEvent Event(string kind, string payload) =>
        new(1, 1, kind, null, [], JsonSerializer.Deserialize<JsonElement>(payload), DateTimeOffset.UnixEpoch);
}
