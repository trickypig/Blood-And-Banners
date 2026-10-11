namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class GameTableTests : BunitContext
{
    [Fact]
    public void Quick_start_sends_the_selected_mode_and_disables_all_choices_while_connecting()
    {
        GameMode? selected = null;
        var cut = Render<QuickStart>(parameters => parameters
            .Add(component => component.OnCreate, mode => selected = mode));

        cut.Find("button[data-mode=battleRoyale]").Click();
        Assert.Equal(GameMode.BattleRoyale, selected);

        var busy = Render<QuickStart>(parameters => parameters.Add(component => component.IsBusy, true));
        Assert.All(busy.FindAll("button[data-mode]"), button => Assert.True(button.HasAttribute("disabled")));
    }

    [Fact]
    public void Player_projection_renders_board_status_and_legal_actions()
    {
        var cut = Render<GameTable>(parameters => parameters.Add(
            component => component.Projection,
            Projection(canCommand: true)));

        Assert.Equal("Battlefield, 3 columns by 2 rows", cut.Find("[role=grid]").GetAttribute("aria-label"));
        Assert.Contains("Round 2", cut.Find("[data-testid=turn-status]").TextContent);
        Assert.Equal("Move", cut.Find("button[data-action=moveUnit]").TextContent.Trim());
        Assert.Equal("Plains grass, elevation 0, occupied by Thorgrim", cut.Find("[data-hex='0,0']").GetAttribute("aria-label"));
    }

    [Fact]
    public void Pending_choice_replaces_normal_actions_with_server_owned_options()
    {
        var projection = Projection(canCommand: true) with
        {
            PendingChoice = new PendingChoiceProjection(
                "choice-1",
                "seat-red",
                "chooseCollisionHex",
                ["1,0", "1,1"])
        };

        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Empty(cut.FindAll("button[data-action=moveUnit]"));
        Assert.Contains("Choose collision hex", cut.Find("[role=dialog]").TextContent);
        Assert.Equal(2, cut.FindAll("button[data-choice]").Count);
    }

    [Fact]
    public void Spectator_projection_has_no_mutation_controls()
    {
        var cut = Render<GameTable>(parameters => parameters.Add(
            component => component.Projection,
            Projection(canCommand: false)));

        Assert.Contains("Spectating", cut.Find("[data-testid=viewer-role]").TextContent);
        Assert.Empty(cut.FindAll("[data-action]"));
        Assert.NotNull(cut.Find("button[data-follow-events]"));
    }

    private static GameProjection Projection(bool canCommand) => new(
        "game-fixture",
        7,
        GameLifecycle.Active,
        GameMode.VanillaPvp,
        new ViewerPermissions(canCommand ? "player" : "spectator", canCommand ? "seat-red" : null, canCommand, canCommand),
        new BoardProjection(3, 2, [
            new HexProjection(0, 0, "plains", "grass", 0, false, "Thorgrim"),
            new HexProjection(1, 0, "stone", "crags", 2, true, null),
        ]),
        new TurnProjection(2, "seat-red", 3),
        null,
        [new LegalAction("moveUnit", "Thorgrim", ["1,0"])],
        new Dictionary<string, int> { ["seat-red"] = 3, ["seat-blue"] = 1 },
        null);
}
