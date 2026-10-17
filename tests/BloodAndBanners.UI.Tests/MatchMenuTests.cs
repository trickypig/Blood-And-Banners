using BloodAndBanners.UI.Components;
using Bunit;
using Microsoft.AspNetCore.Components.Web;
using Microsoft.JSInterop;

namespace BloodAndBanners.UI.Tests;

public sealed class MatchMenuTests : BunitContext
{
    private IRenderedComponent<MatchMenu> RenderMenu(bool spectator = false, bool finished = false, Action? onLeave = null) =>
        Render<MatchMenu>(parameters => parameters
            .Add(menu => menu.GameId, "game 7")
            .Add(menu => menu.InviteBaseUri, "https://blood.test/play/")
            .Add(menu => menu.IsSpectator, spectator)
            .Add(menu => menu.IsFinished, finished)
            .Add(menu => menu.OnLeave, () => onLeave?.Invoke()));

    [Fact]
    public void The_menu_is_closed_until_toggled_and_offers_the_three_items()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = RenderMenu();

        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));
        Assert.Equal("false", cut.Find("[data-menu=toggle]").GetAttribute("aria-expanded"));

        cut.Find("[data-menu=toggle]").Click();

        Assert.Equal("true", cut.Find("[data-menu=toggle]").GetAttribute("aria-expanded"));
        Assert.Equal("Copy Link", cut.Find("[data-menu=copyLink]").TextContent.Trim());
        Assert.Equal("Copy Game ID", cut.Find("[data-menu=copyGameId]").TextContent.Trim());
        Assert.Equal("Leave Match", cut.Find("[data-menu=leave]").TextContent.Trim());
        Assert.Empty(cut.FindAll("[data-action]"));
    }

    [Fact]
    public void Copy_link_copies_the_watch_url_and_copy_id_copies_the_bare_id()
    {
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyLink]").Click();
        cut.WaitForAssertion(() => Assert.Contains("Link copied", cut.Find("[data-menu=notice]").TextContent));
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyGameId]").Click();
        cut.WaitForAssertion(() => Assert.Contains("Game ID copied", cut.Find("[data-menu=notice]").TextContent));

        var copied = JSInterop.Invocations["navigator.clipboard.writeText"].Select(call => call.Arguments[0]).ToList();
        Assert.Equal(["https://blood.test/play/?watch=game%207", "game 7"], copied);
    }

    [Fact]
    public void A_blocked_clipboard_shows_the_text_to_copy_by_hand()
    {
        JSInterop.SetupVoid("navigator.clipboard.writeText", _ => true).SetException(new JSException("denied"));
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=copyGameId]").Click();

        cut.WaitForAssertion(() => Assert.Contains("Copy it manually", cut.Find("[data-menu=notice]").TextContent));
        var notice = cut.Find("[data-menu=notice]");
        Assert.Equal("game 7", notice.QuerySelector("input")?.GetAttribute("value"));
    }

    [Fact]
    public void A_player_in_a_live_match_confirms_before_leaving_and_can_stay()
    {
        var left = false;
        var cut = RenderMenu(onLeave: () => left = true);

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        Assert.False(left);
        cut.Find("[data-menu=stay]").Click();
        Assert.Empty(cut.FindAll("[data-menu=confirmLeave]"));

        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();
        Assert.True(left);
        Assert.Empty(cut.FindAll("[data-menu=leave]"));
    }

    [Theory]
    [InlineData(true, false, "Stop watching")]
    [InlineData(false, true, "Leave")]
    public void Spectators_and_finished_matches_leave_in_one_press(bool spectator, bool finished, string label)
    {
        var left = false;
        var cut = RenderMenu(spectator, finished, () => left = true);

        cut.Find("[data-menu=toggle]").Click();
        Assert.Equal(label, cut.Find("[data-menu=leave]").TextContent.Trim());
        cut.Find("[data-menu=leave]").Click();

        Assert.True(left);
    }

    [Fact]
    public void Escape_and_clicking_outside_close_the_menu()
    {
        var cut = RenderMenu();

        cut.Find("[data-menu=toggle]").Click();
        cut.Find(".match-menu").KeyDown(new KeyboardEventArgs { Key = "Escape" });
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=backdrop]").Click();
        Assert.Empty(cut.FindAll("[data-menu=copyLink]"));
    }
}
