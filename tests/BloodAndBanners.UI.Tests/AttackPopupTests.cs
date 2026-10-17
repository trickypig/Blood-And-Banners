namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class AttackPopupTests : BunitContext
{
    private const string Hit = """{"unitId":"s1-u3","targetUnitId":"s2-u1","ability":null,"attackFaces":["sword","sword","blank"],"defenseFaces":["shield","blank"],"wounds":1,"attackBase":2,"attackModifiers":[{"source":"Rally","amount":1}],"defenseBase":3,"defenseModifiers":[{"source":"Standing in water","amount":-1}]}""";

    [Fact]
    public void Summaries_count_swords_shields_wounds_and_defeat() =>
        Assert.Equal("2 swords vs 1 shield → 1 wound → Grunt is defeated", AttackReport.Summarize(2, 0, 1, 0, 1, "Grunt"));

    [Fact]
    public void Summaries_mention_penalties_prevention_and_whiffs()
    {
        Assert.Equal("1 sword vs 2 shields → no wounds", AttackReport.Summarize(1, 0, 2, 0, 0, null));
        Assert.Equal("3 swords (−1) vs 0 shields → 1 wound (1 prevented)", AttackReport.Summarize(3, 1, 0, 1, 1, null));
    }

    [Fact]
    public void Reports_collect_the_same_commands_follow_up_lines_until_the_next_attack()
    {
        var events = new[]
        {
            Event(1, 4, "attackResolved", Hit),
            Event(2, 5, "attackResolved", Hit),
            Event(3, 5, "unitDefeated", """{"unitId":"s2-u1"}"""),
            Event(4, 5, "splashDamage", """{"ability":"Fireball","hits":[{"unitId":"s1-u3","faces":["sword"],"wounds":1}]}"""),
            Event(5, 5, "attackResolved", Hit),
            Event(6, 5, "turnEnded", """{"seatId":"seat-red","nextSeatId":"seat-blue","round":3}"""),
            Event(7, 6, "unitMoved", """{"unitId":"s1-u3","from":"1,4","to":"2,4","path":["2,4"]}"""),
        };

        var reports = AttackReport.FromEvents(events, BattleFixtures.Projection(), afterSequence: 1);

        Assert.Equal([2L, 5L], reports.Select(report => report.Sequence));
        Assert.True(reports[0].Defeated);
        Assert.Equal(["Fireball scorches Ranger for 1."], reports[0].Consequences);
        Assert.False(reports[1].Defeated);
        Assert.Empty(reports[1].Consequences);
    }

    [Fact]
    public void Reports_name_the_modifiers_behind_each_pool_and_reflections()
    {
        var reflected = """{"unitId":"s1-u3","targetUnitId":"s1-u3","reflectedByUnitId":"s2-u1","attackFaces":["sword"],"defenseFaces":[],"wounds":1,"defenseBase":1,"defenseModifiers":[{"source":"Petrified","amount":null},{"source":"Aura","amount":2,"unitId":"s2-u1"}]}""";
        var report = AttackReport.FromEvents([Event(1, 1, "attackResolved", reflected)], BattleFixtures.Projection(), 0).Single();

        Assert.Equal("s2-u1", report.DefenderId);
        Assert.Equal("s1-u3", report.DamagedUnitId);
        Assert.Equal(["Petrified: no dice", "Aura (Grunt) +2"], report.Defense.Modifiers);
        Assert.Contains(report.Notes, note => note.Contains("reflects the blow back at Ranger"));
    }

    [Fact]
    public void Nothing_pops_up_until_history_has_loaded_and_history_never_does()
    {
        var events = new[] { Event(1, 1, "attackResolved", Hit) };
        var cut = Render<AttackPopup>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.Events, events));
        Assert.Empty(cut.FindAll("[data-testid=attack-popup]"));

        cut.Render(parameters => parameters.Add(component => component.LiveAfterSequence, 1L));
        Assert.Empty(cut.FindAll("[data-testid=attack-popup]"));
    }

    [Fact]
    public void A_live_attack_shows_hidden_dice_then_rolls_then_settles_on_the_real_faces()
    {
        var stages = new StageClock();
        var cut = Render<AttackPopup>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.LiveAfterSequence, 0L)
            .Add(component => component.Delay, stages.Delay)
            .Add(component => component.Events, new[] { Event(1, 1, "attackResolved", Hit) }));

        Assert.Equal("Ranger attacks Grunt", cut.Find(".attack-popup h2").TextContent);
        Assert.Equal(5, cut.FindAll(".die-unknown").Count);
        Assert.Contains("Rally +1", cut.Find("[data-pool=power]").TextContent);
        Assert.Contains("Standing in water −1", cut.Find("[data-pool=defense]").TextContent);
        Assert.Empty(cut.FindAll("[data-testid=attack-popup-summary]"));

        stages.Next();
        cut.WaitForAssertion(() => Assert.Equal(5, cut.FindAll(".die-rolling").Count));

        stages.Next();
        cut.WaitForAssertion(() => Assert.Equal("2 swords vs 1 shield → 1 wound", cut.Find("[data-testid=attack-popup-summary]").TextContent));
        Assert.Equal(["sword", "sword", "blank"], cut.FindAll("[data-pool=power] .die").Select(die => die.GetAttribute("data-face")));
        Assert.Empty(cut.FindAll(".attack-popup-card.fallen"));

        stages.Next();
        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[data-testid=attack-popup]")));
    }

    [Fact]
    public void A_defeated_defenders_card_falls_after_the_dice_settle()
    {
        var stages = new StageClock();
        var cut = Render<AttackPopup>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.LiveAfterSequence, 0L)
            .Add(component => component.Delay, stages.Delay)
            .Add(component => component.Events, new[]
            {
                Event(1, 1, "attackResolved", Hit),
                Event(2, 1, "unitDefeated", """{"unitId":"s2-u1"}"""),
            }));

        stages.Next();
        stages.Next();
        cut.WaitForAssertion(() => Assert.Contains("Grunt is defeated", cut.Find("[data-testid=attack-popup-summary]").TextContent));
        Assert.Empty(cut.FindAll(".attack-popup-card.fallen"));

        stages.Next();
        cut.WaitForAssertion(() => Assert.Equal("s2-u1", cut.Find(".attack-popup-card.fallen").GetAttribute("data-unit-id")));
    }

    [Fact]
    public void Queued_attacks_follow_one_another_and_the_close_button_skips_ahead()
    {
        var stages = new StageClock();
        var cut = Render<AttackPopup>(parameters => parameters
            .Add(component => component.Projection, BattleFixtures.Projection())
            .Add(component => component.LiveAfterSequence, 0L)
            .Add(component => component.Delay, stages.Delay)
            .Add(component => component.Events, new[] { Event(1, 1, "attackResolved", Hit), Event(2, 1, "attackResolved", Hit) }));

        Assert.Equal("1", cut.Find("[data-testid=attack-popup]").GetAttribute("data-sequence"));
        Assert.Contains("1 more attack", cut.Find(".attack-popup-queue").TextContent);

        cut.Find("[data-testid=attack-popup-close]").Click();
        Assert.Equal("2", cut.Find("[data-testid=attack-popup]").GetAttribute("data-sequence"));

        cut.Find("[data-testid=attack-popup-close]").Click();
        Assert.Empty(cut.FindAll("[data-testid=attack-popup]"));
    }

    private static GameEvent Event(long sequence, long version, string kind, string payload) =>
        new(sequence, version, kind, null, [], JsonSerializer.Deserialize<JsonElement>(payload), DateTimeOffset.UnixEpoch);

    /// <summary>Holds each popup stage open until the test advances it.</summary>
    private sealed class StageClock
    {
        private readonly Queue<TaskCompletionSource> _waiting = new();

        public Task Delay(TimeSpan time, CancellationToken token)
        {
            var stage = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
            token.Register(() => stage.TrySetCanceled(token));
            lock (_waiting)
            {
                _waiting.Enqueue(stage);
            }
            return stage.Task;
        }

        public void Next()
        {
            TaskCompletionSource? stage = null;
            SpinWait.SpinUntil(() =>
            {
                lock (_waiting)
                {
                    return _waiting.TryDequeue(out stage);
                }
            }, TimeSpan.FromSeconds(2));
            Assert.NotNull(stage);
            stage.SetResult();
        }
    }
}
