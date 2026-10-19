namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class TurnPopupTests : BunitContext
{
    [Fact]
    public void The_turn_already_in_play_when_the_game_loads_does_not_pop_up()
    {
        var cut = Render<TurnPopup>(parameters => parameters.Add(component => component.Projection, BattleFixtures.Projection()));

        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));
    }

    [Fact]
    public void A_change_of_turn_names_whose_turn_it_is_then_closes_itself()
    {
        var timer = new ManualTimer();
        var game = BattleFixtures.Projection();
        var cut = Render<TurnPopup>(parameters => parameters
            .Add(component => component.Projection, game)
            .Add(component => component.Delay, timer.Delay));

        cut.Render(parameters => parameters.Add(component => component.Projection, game with { Turn = new TurnProjection(2, "seat-blue", 3) }));
        Assert.Equal("Rival's turn", cut.Find(".turn-popup h2").TextContent);
        Assert.Equal("Round 2", cut.Find(".turn-popup-round").TextContent);

        cut.Render(parameters => parameters.Add(component => component.Projection, game with { Turn = new TurnProjection(3, "seat-red", 3) }));
        Assert.Equal("Your turn", cut.Find(".turn-popup h2").TextContent);
        Assert.Contains("mine", cut.Find(".turn-popup").ClassList);

        timer.Fire();
        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[data-testid=turn-popup]")));
    }

    [Fact]
    public void The_close_button_dismisses_the_notice_and_the_same_turn_does_not_reopen_it()
    {
        var game = BattleFixtures.Projection();
        var cut = Render<TurnPopup>(parameters => parameters
            .Add(component => component.Projection, game)
            .Add(component => component.Delay, new ManualTimer().Delay));
        var blue = game with { Turn = new TurnProjection(2, "seat-blue", 3) };

        cut.Render(parameters => parameters.Add(component => component.Projection, blue));
        cut.Find("[data-testid=turn-popup-close]").Click();
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, blue with { Version = blue.Version + 1 }));
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));
    }

    [Fact]
    public void A_held_notice_waits_until_released_and_an_attack_starting_mid_notice_defers_it()
    {
        var timer = new ManualTimer();
        var game = BattleFixtures.Projection();
        var cut = Render<TurnPopup>(parameters => parameters
            .Add(component => component.Projection, game)
            .Add(component => component.Delay, timer.Delay));
        var blue = game with { Turn = new TurnProjection(2, "seat-blue", 3) };

        cut.Render(parameters => parameters.Add(component => component.Projection, blue).Add(component => component.Held, true));
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, blue).Add(component => component.Held, false));
        Assert.Equal("Rival's turn", cut.Find(".turn-popup h2").TextContent);

        cut.Render(parameters => parameters.Add(component => component.Projection, blue).Add(component => component.Held, true));
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, blue).Add(component => component.Held, false));
        Assert.Equal("Rival's turn", cut.Find(".turn-popup h2").TextContent);
        timer.Fire();
        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[data-testid=turn-popup]")));
    }

    [Fact]
    public void Switching_to_another_game_or_ending_the_battle_shows_no_notice()
    {
        var game = BattleFixtures.Projection();
        var cut = Render<TurnPopup>(parameters => parameters.Add(component => component.Projection, game));

        cut.Render(parameters => parameters.Add(component => component.Projection, game with { GameId = "other", Turn = new TurnProjection(1, "seat-blue", 3) }));
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, game with { GameId = "other", Turn = new TurnProjection(2, "seat-red", 3), Result = new GameResult("elimination", "seat-red", new Dictionary<string, int>()) }));
        Assert.Empty(cut.FindAll("[data-testid=turn-popup]"));
    }

    private sealed class ManualTimer
    {
        private TaskCompletionSource? _pending;

        public Task Delay(TimeSpan time, CancellationToken token)
        {
            var pending = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
            token.Register(() => pending.TrySetCanceled(token));
            _pending = pending;
            return pending.Task;
        }

        public void Fire() => _pending?.TrySetResult();
    }
}
