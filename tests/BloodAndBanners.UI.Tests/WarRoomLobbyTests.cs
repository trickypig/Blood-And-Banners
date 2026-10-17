namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class WarRoomLobbyTests : BunitContext
{
    private static GameProjection CoopLobby(string viewer, bool isHost, string? voidLord, params string[] actions) => new(
        "game-1", 3, GameLifecycle.Lobby, GameMode.Coop,
        new ViewerPermissions("player", viewer, true, isHost),
        new BoardProjection(0, 0, []),
        new TurnProjection(0, null, 0), null,
        actions.Select(kind => new LegalAction(kind, null, [])).ToList(),
        new Dictionary<string, int>(), null)
    {
        ScenarioName = "Void Reaches",
        Memberships =
        [
            new MembershipProjection("seat-1", 1, true, true) { Role = voidLord == "seat-1" ? "voidLord" : "player" },
            new MembershipProjection("seat-2", 2, true, false) { Role = voidLord == "seat-2" ? "voidLord" : "player" },
        ],
    };

    [Fact]
    public void A_member_can_claim_the_void_lord_and_start_waits_for_one()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-1", true, null, "setReady", "claimVoidLord", "assignVoidLord"))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Contains("Needs a Void Lord", cut.Markup);
        Assert.True(cut.Find("[data-action=start-game]").HasAttribute("disabled"));
        cut.Find("[data-action=claimVoidLord]").Click();
        Assert.Equal("claimVoidLord", sent.Single().Kind);
    }

    [Fact]
    public void Coop_lobby_uses_ally_wording()
    {
        var host = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, CoopLobby("seat-1", true, null)));
        Assert.Contains("Gather your allies", host.Markup);
        Assert.DoesNotContain("Call your rival", host.Markup);

        var guest = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, CoopLobby("seat-2", false, null)));
        Assert.Contains("Waiting for the host to begin the incursion.", guest.Markup);
    }

    [Fact]
    public void The_void_lord_is_badged_and_the_host_can_reassign_the_role()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-1", true, "seat-2", "setReady", "assignVoidLord"))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Contains("Void Lord", cut.Find("[data-seat='2']").TextContent);
        Assert.False(cut.Find("[data-action=start-game]").HasAttribute("disabled"));
        cut.Find("[data-assign-seat='seat-1']").Click();
        Assert.Equal("assignVoidLord", sent.Single().Kind);
        Assert.Equal("seat-1", JsonSerializer.SerializeToElement(sent.Single().Payload).GetProperty("seatId").GetString());
    }

    [Fact]
    public void Seat_names_call_the_void_lord_by_role()
    {
        var projection = CoopLobby("seat-1", true, "seat-2");
        Assert.Equal("You", SeatNames.Display(projection, "seat-1"));
        Assert.Equal("Void Lord", SeatNames.Display(projection, "seat-2"));
        Assert.True(SeatNames.IsVoidLord(projection, "seat-2"));
    }

    [Fact]
    public void Named_seats_show_their_name_in_the_muster()
    {
        var projection = CoopLobby("seat-1", true, null) with
        {
            Memberships =
            [
                new MembershipProjection("seat-1", 1, true, true) { Name = "Seris" },
                new MembershipProjection("seat-2", 2, true, false),
            ],
        };

        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, projection));

        Assert.Contains("Seris (You)", cut.Find("li[data-seat='1'] strong").TextContent);
        Assert.Contains("Commander 2", cut.Find("li[data-seat='2'] strong").TextContent);
    }

    [Fact]
    public void The_muster_offers_the_name_field()
    {
        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, CoopLobby("seat-1", true, null, "setReady", "setCommanderName")));

        Assert.NotNull(cut.Find(".war-room__muster [data-testid=commander-name]"));
        Assert.Contains("data-testid=\"commander-name\"", cut.Find(".war-room__muster").InnerHtml.Split("seat-list")[0]);
    }

    [Fact]
    public void The_host_removes_another_commander_after_confirming()
    {
        var removed = new List<string>();
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-1", true, null, "setReady"))
            .Add(c => c.OnRemoveSeat, (string seatId) => removed.Add(seatId)));

        Assert.Empty(cut.FindAll("li[data-seat='1'] [data-remove-seat]"));
        cut.Find("li[data-seat='2'] [data-remove-seat]").Click();
        Assert.Empty(removed);
        cut.Find("li[data-seat='2'] [data-confirm-remove]").Click();

        Assert.Equal(["seat-2"], removed);
    }

    [Fact]
    public void Removing_can_be_called_off()
    {
        var removed = new List<string>();
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-1", true, null, "setReady"))
            .Add(c => c.OnRemoveSeat, (string seatId) => removed.Add(seatId)));

        cut.Find("[data-remove-seat='seat-2']").Click();
        cut.Find("li[data-seat='2'] [data-keep-seat]").Click();

        Assert.Empty(removed);
        Assert.NotNull(cut.Find("[data-remove-seat='seat-2']"));
    }

    [Fact]
    public void Only_the_host_can_remove_commanders()
    {
        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, CoopLobby("seat-2", false, null, "setReady")));

        Assert.Empty(cut.FindAll("[data-remove-seat]"));
    }

    [Fact]
    public void A_player_can_switch_to_watching()
    {
        var watched = false;
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, CoopLobby("seat-2", false, null, "setReady"))
            .Add(c => c.OnWatchInstead, () => watched = true));

        cut.Find("[data-action=watch-instead]").Click();

        Assert.True(watched);
    }
}
