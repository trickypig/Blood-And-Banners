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
        Assert.Equal("You", SeatNames.Coop(projection, "seat-1"));
        Assert.Equal("Void Lord", SeatNames.Coop(projection, "seat-2"));
        Assert.True(SeatNames.IsVoidLord(projection, "seat-2"));
    }
}
