using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class AddFriendButtonTests : BunitContext
{
    private static MembershipProjection Seat(bool account = true, bool friend = false, bool requested = false) =>
        new("seat-2", 2, false, false) { Name = "Bryn", IsAccount = account, IsFriend = friend, FriendRequested = requested };

    private IRenderedComponent<AddFriendButton> RenderFor(MembershipProjection seat, string viewer = "seat-1") =>
        Render<AddFriendButton>(p => p.Add(c => c.GameId, "g1").Add(c => c.Seat, seat).Add(c => c.ViewerSeatId, viewer));

    [Fact]
    public void Hidden_without_a_signed_in_account()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        Assert.Empty(RenderFor(Seat()).FindAll("[data-add-friend]"));
    }

    [Theory]
    [InlineData(false, false, false, "seat-1", null)]
    [InlineData(true, false, false, "seat-2", null)]
    [InlineData(true, true, false, "seat-1", "Friend")]
    [InlineData(true, false, true, "seat-1", "Requested")]
    public void Shows_the_right_state(bool account, bool friend, bool requested, string viewer, string? label)
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession(FakeAccountSession.Account()));
        var cut = RenderFor(Seat(account, friend, requested), viewer);
        if (label is null)
        {
            Assert.Empty(cut.FindAll("[data-add-friend]"));
        }
        else
        {
            Assert.Equal(label, cut.Find("[data-add-friend]").TextContent.Trim());
        }
    }

    [Fact]
    public void Clicking_sends_a_seat_request_and_shows_requested()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        Services.AddSingleton<IAccountSession>(session);
        var cut = RenderFor(Seat());

        cut.Find("button[data-add-friend]").Click();

        cut.WaitForAssertion(() => Assert.Equal("Requested", cut.Find("[data-add-friend]").TextContent.Trim()));
        Assert.Contains("seat:g1:seat-2", session.FakeApi.Calls);
    }

    [Fact]
    public void An_already_requested_conflict_still_shows_requested()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        session.FakeApi.FriendFailure = new GameApiException(HttpStatusCode.Conflict, "alreadyRequested", "x");
        Services.AddSingleton<IAccountSession>(session);
        var cut = RenderFor(Seat());

        cut.Find("button[data-add-friend]").Click();

        cut.WaitForAssertion(() => Assert.Equal("Requested", cut.Find("[data-add-friend]").TextContent.Trim()));
    }
}
