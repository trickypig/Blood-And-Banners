using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountHomeTests : BunitContext
{
    private static AccountGameRow Row(string id, GameLifecycle lifecycle, bool canJoin, params string?[] names) =>
        new(id, GameMode.VanillaPvp, "Four Corners", lifecycle,
            names.Select((name, i) => new AccountGameSeat(i + 1, name, false)).ToList(), canJoin, "2026-10-21 10:00:00");

    private FakeAccountSession SignedIn()
    {
        var session = new FakeAccountSession(FakeAccountSession.Account());
        Services.AddSingleton<IAccountSession>(session);
        return session;
    }

    [Fact]
    public void Nothing_renders_without_account_services()
    {
        var cut = Render<AccountHome>();
        Assert.Empty(cut.FindAll("[data-account-home]"));
    }

    [Fact]
    public void Nothing_renders_when_signed_out()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession());
        var cut = Render<AccountHome>();
        Assert.Empty(cut.FindAll("[data-account-home]"));
    }

    [Fact]
    public void Your_games_offer_resume_and_friends_games_offer_join_or_watch()
    {
        var session = SignedIn();
        session.FakeApi.Games = new AccountGames(
            [Row("mine", GameLifecycle.Active, false, "Ash", null)],
            [Row("open", GameLifecycle.Lobby, true, "Fen"), Row("busy", GameLifecycle.Active, false, "Fen", "Bryn")]);
        string? resumed = null, joined = null, watched = null;

        var cut = Render<AccountHome>(p => p
            .Add(c => c.OnResume, id => resumed = id)
            .Add(c => c.OnJoin, id => joined = id)
            .Add(c => c.OnWatch, id => watched = id));

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("[data-game=mine] button[data-action=resume-game]")));
        Assert.Contains("Commander 2", cut.Find("[data-game=mine]").TextContent);
        cut.Find("[data-game=mine] button[data-action=resume-game]").Click();
        cut.Find("[data-game=open] button[data-action=join-friend-game]").Click();
        cut.Find("[data-game=busy] button[data-action=watch-friend-game]").Click();

        Assert.Equal(("mine", "open", "busy"), (resumed, joined, watched));
    }

    [Fact]
    public void Empty_lists_explain_themselves()
    {
        SignedIn();
        var cut = Render<AccountHome>();
        cut.WaitForAssertion(() => Assert.Contains("No unfinished games", cut.Markup));
        Assert.Contains("Add a friend to see their games here", cut.Markup);
    }

    [Fact]
    public void Incoming_requests_show_a_badge_and_can_be_accepted()
    {
        var session = SignedIn();
        session.FakeApi.Friends = new FriendsList([], [new IncomingFriendRequest("r1", "Bryn")], []);
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => Assert.Equal("1", cut.Find("[data-friends=badge]").TextContent.Trim()));
        session.FakeApi.Friends = new FriendsList([new FriendSummary("u2", "Bryn")], [], []);
        cut.Find("button[data-friend-accept=r1]").Click();

        cut.WaitForAssertion(() => Assert.Contains("accept:r1", session.FakeApi.Calls));
        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[data-friends=badge]")));
        Assert.Contains("Bryn", cut.Find("[data-friends=list]").TextContent);
    }

    [Fact]
    public void Adding_a_friend_by_email_reports_conflicts_kindly()
    {
        var session = SignedIn();
        session.FakeApi.FriendFailure = new GameApiException(HttpStatusCode.Conflict, "alreadyRequested", "x");
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => cut.Find("input[data-friends=email]").Input("b@example.com"));
        cut.Find("form[data-friends=add]").Submit();

        cut.WaitForAssertion(() => Assert.Contains("already sent", cut.Find("[data-friends=error]").TextContent));
    }

    [Fact]
    public void Removing_a_friend_needs_a_second_click()
    {
        var session = SignedIn();
        session.FakeApi.Friends = new FriendsList([new FriendSummary("u2", "Fen")], [], []);
        var cut = Render<AccountHome>();

        cut.WaitForAssertion(() => cut.Find("button[data-friend-remove=u2]").Click());
        Assert.DoesNotContain("remove:u2", session.FakeApi.Calls);
        cut.Find("button[data-friend-confirm-remove=u2]").Click();

        cut.WaitForAssertion(() => Assert.Contains("remove:u2", session.FakeApi.Calls));
    }

    [Fact]
    public void Lists_refresh_on_the_poll_interval()
    {
        var session = SignedIn();
        var cut = Render<AccountHome>(p => p.Add(c => c.PollInterval, TimeSpan.FromMilliseconds(30)));

        cut.WaitForAssertion(() => Assert.True(session.FakeApi.Calls.Count(c => c == "games") >= 2), TimeSpan.FromSeconds(2));
    }
}
