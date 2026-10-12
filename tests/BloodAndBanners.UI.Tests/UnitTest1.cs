namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed partial class GameTableTests : BunitContext
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
    public void Quick_start_prefills_and_submits_an_invite_code()
    {
        string? joined = null;
        var cut = Render<QuickStart>(parameters => parameters
            .Add(component => component.InitialJoinCode, " game-from-link ")
            .Add(component => component.OnJoin, code => joined = code));

        Assert.Equal("game-from-link", cut.Find("input[data-join-code]").GetAttribute("value"));
        cut.Find("button[data-action=join-game]").Click();

        Assert.Equal("game-from-link", joined);
    }

    [Fact]
    public void Quick_start_enables_join_as_an_invite_code_is_pasted()
    {
        string? joined = null;
        var cut = Render<QuickStart>(parameters => parameters
            .Add(component => component.OnJoin, code => joined = code));
        var join = cut.Find("button[data-action=join-game]");
        Assert.True(join.HasAttribute("disabled"));

        cut.Find("input[data-join-code]").Input(" pasted-game ");

        join = cut.Find("button[data-action=join-game]");
        Assert.False(join.HasAttribute("disabled"));
        join.Click();
        Assert.Equal("pasted-game", joined);
    }

    [Fact]
    public void Player_projection_renders_board_status_and_legal_actions()
    {
        GameCommandRequest? sent = null;
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, Projection(canCommand: true))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        Assert.Equal("Battlefield, 2 columns by 3 rows", cut.Find("svg.hex-board").GetAttribute("aria-label"));
        Assert.Contains("Round 2", cut.Find("[data-testid=turn-status]").TextContent);
        Assert.Equal("Plains grass, elevation 0, occupied by Thorgrim", cut.Find("[data-hex='0,0']").GetAttribute("aria-label"));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-hex='1,0']").Click();

        Assert.Equal("moveUnit", sent?.Kind);
        Assert.Equal("1,0", System.Text.Json.JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("to").GetString());
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

    [Fact]
    public void Lobby_shows_invite_seats_and_requires_everyone_ready_before_starting()
    {
        var projection = LobbyProjection([
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, false, false),
        ]);

        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.InviteUrl, "http://localhost:5016/?join=game-fixture"));

        Assert.Equal("game-fixture", cut.Find("[data-testid=game-code]").TextContent.Trim());
        Assert.Equal("http://localhost:5016/?join=game-fixture", cut.Find("[data-testid=invite-link]").GetAttribute("value"));
        Assert.Equal(2, cut.FindAll("[data-seat]").Count);
        Assert.Contains("You", cut.Find("[data-seat='1']").TextContent);
        Assert.Contains("Not ready", cut.Find("[data-seat='2']").TextContent);
        Assert.True(cut.Find("button[data-action=start-game]").HasAttribute("disabled"));
    }

    [Fact]
    public void Lobby_emits_ready_copy_and_host_start_actions()
    {
        bool? ready = null;
        var copied = false;
        var started = false;
        var projection = LobbyProjection([
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, true, false),
        ]);

        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.InviteUrl, "https://game.test/?join=game-fixture")
            .Add(component => component.OnReadyChanged, value => ready = value)
            .Add(component => component.OnCopyInvite, () => copied = true)
            .Add(component => component.OnStart, () => started = true));

        cut.Find("button[data-action=toggle-ready]").Click();
        cut.Find("button[data-action=copy-invite]").Click();
        cut.Find("button[data-action=start-game]").Click();

        Assert.False(ready);
        Assert.True(copied);
        Assert.True(started);
    }

    [Fact]
    public void Non_host_lobby_does_not_show_the_start_control()
    {
        var projection = LobbyProjection([
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, false, false),
        ]) with
        {
            Viewer = new ViewerPermissions("player", "seat-blue", true, false)
        };

        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(component => component.Projection, projection));

        Assert.Empty(cut.FindAll("button[data-action=start-game]"));
        Assert.Contains("Waiting for the host", cut.Markup);
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

    private static GameProjection LobbyProjection(IReadOnlyList<MembershipProjection> memberships) =>
        new(
            "game-fixture",
            3,
            GameLifecycle.Lobby,
            GameMode.VanillaPvp,
            new ViewerPermissions("player", "seat-red", true, true),
            new BoardProjection(3, 2, []),
            new TurnProjection(0, null, 0),
            null,
            [new LegalAction("setReady", null, [])],
            memberships.ToDictionary(seat => seat.SeatId, _ => 0),
            null)
        {
            ScenarioName = "Training Grounds",
            Memberships = memberships
        };
}
