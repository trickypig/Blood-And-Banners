using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class MainMenuTests : BunitContext
{
    [Fact]
    public void Main_page_navigation_and_account_controls_are_inside_the_menu()
    {
        Services.AddSingleton<IAccountSession>(new FakeAccountSession(FakeAccountSession.Account("Ash")));
        var cut = Render<QuickStart>(p => p.Add(c => c.ClientKind, "web"));
        var menu = cut.Find("details.main-menu");
        Assert.False(menu.HasAttribute("open"));
        Assert.Equal("editor", menu.QuerySelector("[data-menu='editor']")!.GetAttribute("href"));
        Assert.Equal("https://trickypig.com/blood/public/rulebook.html", menu.QuerySelector("[data-menu='rulebook']")!.GetAttribute("href"));
        Assert.Equal("Ash", menu.QuerySelector("[data-account='chip']")!.TextContent.Trim());
        Assert.Equal("Log Out", menu.QuerySelector("[data-account='sign-out']")!.TextContent.Trim());
        Assert.Empty(cut.FindAll(".intro-copy [data-account]"));
        Assert.Single(cut.FindAll("[data-account='chip']"));
    }

    [Fact]
    public void Logging_out_from_the_menu_switches_to_sign_in()
    {
        var account = new FakeAccountSession(FakeAccountSession.Account());
        Services.AddSingleton<IAccountSession>(account);
        var cut = Render<MainMenu>();
        cut.Find("[data-account='sign-out']").Click();
        Assert.True(account.SignedOut);
        Assert.Empty(cut.FindAll("[data-account='chip']"));
        cut.Find("[data-account='sign-in']").Click();
        Assert.NotNull(cut.Find("nav [data-signin='email-form']"));
        Assert.Empty(cut.FindAll(".account-chip__hint"));
    }

    [Fact]
    public void Links_work_without_account_services_and_native_editor_uses_web_address()
    {
        var cut = Render<MainMenu>(p => p.Add(c => c.ClientKind, "maui"));
        Assert.Equal("https://blood.trickypig.com/play/editor", cut.Find("[data-menu='editor']").GetAttribute("href"));
        Assert.NotNull(cut.Find("[data-menu='rulebook']"));
    }
}
