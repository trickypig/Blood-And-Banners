namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class InviteQrCodeTests : BunitContext
{
    private static GameProjection Lobby() => new(
        "game-1", 1, GameLifecycle.Lobby, GameMode.VanillaPvp,
        new ViewerPermissions("player", "seat-1", true, true),
        new BoardProjection(0, 0, []),
        new TurnProjection(0, null, 0), null,
        [],
        new Dictionary<string, int>(), null)
    {
        Memberships = [new MembershipProjection("seat-1", 1, true, true)],
    };

    [Fact]
    public void Encodes_a_link_as_square_modules_with_a_quiet_zone()
    {
        var code = InviteQrCode.Create("https://blood.trickypig.com/?join=game-1");

        Assert.True(code.Size >= 21 + 8);
        Assert.StartsWith("M", code.PathData);
        Assert.DoesNotContain("M0 0", code.PathData);
        Assert.Equal(code, InviteQrCode.Create("https://blood.trickypig.com/?join=game-1"));
    }

    [Fact]
    public void The_war_room_shows_a_scannable_code_for_the_invite_link()
    {
        var url = "https://blood.trickypig.com/?join=game-1";
        var cut = Render<WarRoomLobby>(parameters => parameters
            .Add(c => c.Projection, Lobby())
            .Add(c => c.InviteUrl, url));

        var svg = cut.Find("[data-testid=invite-qr] svg");
        Assert.Equal($"0 0 {InviteQrCode.Create(url).Size} {InviteQrCode.Create(url).Size}", svg.GetAttribute("viewBox"));
        Assert.Equal(InviteQrCode.Create(url).PathData, svg.QuerySelector("path")!.GetAttribute("d"));
    }

    [Fact]
    public void No_code_is_drawn_before_the_link_is_known()
    {
        var cut = Render<WarRoomLobby>(parameters => parameters.Add(c => c.Projection, Lobby()));

        Assert.Empty(cut.FindAll("[data-testid=invite-qr]"));
    }
}
