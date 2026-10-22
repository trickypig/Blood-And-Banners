using System.Net;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class SignInPanelTests : BunitContext
{
    private FakeAccountSession Register(StoredAccount? current = null)
    {
        var session = new FakeAccountSession(current);
        Services.AddSingleton<IAccountSession>(session);
        return session;
    }

    [Fact]
    public void Email_then_code_then_name_signs_in()
    {
        var session = Register();
        var done = false;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1").Add(c => c.OnDone, () => done = true));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("input[data-signin=code]")));
        Assert.Contains("me@example.com", cut.Markup);

        cut.Find("input[data-signin=code]").Input("123456");
        cut.Find("form[data-signin=code-form]").Submit();
        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("input[data-signin=name]")));

        cut.Find("input[data-signin=name]").Input("Ash");
        cut.Find("form[data-signin=name-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(done));
        Assert.Equal(["start:me@example.com", "code:me@example.com:123456", "name:Ash"], session.Calls);
    }

    [Fact]
    public void A_named_account_skips_the_name_step()
    {
        var session = Register();
        session.NameAfterVerify = "Ash";
        var done = false;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1").Add(c => c.OnDone, () => done = true));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("123456"));
        cut.Find("form[data-signin=code-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(done));
    }

    [Theory]
    [InlineData("invalidCode", "That code is wrong or has expired")]
    [InlineData("tooManyRequests", "Too many sign-in emails")]
    public void Server_errors_show_inline(string code, string text)
    {
        var session = Register();
        var error = new GameApiException(HttpStatusCode.BadRequest, code, "server text");
        if (code == "tooManyRequests") session.StartFailure = error; else session.VerifyFailure = error;
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1"));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        if (code == "invalidCode")
        {
            cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("000000"));
            cut.Find("form[data-signin=code-form]").Submit();
        }

        cut.WaitForAssertion(() => Assert.Contains(text, cut.Find("[role=alert]").TextContent));
    }

    [Fact]
    public void A_timeout_shows_the_generic_error_inline()
    {
        var session = Register();
        session.VerifyFailure = new TaskCanceledException("timed out");
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1"));

        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("123456"));
        cut.Find("form[data-signin=code-form]").Submit();

        cut.WaitForAssertion(() => Assert.Contains("Sign-in failed. Check your connection and try again.", cut.Find("[role=alert]").TextContent));
        Assert.False(cut.Find("input[data-signin=code]").HasAttribute("disabled"));
    }

    [Fact]
    public void Account_chip_shows_sign_in_after_a_new_account_names_itself_and_signs_out()
    {
        Register();
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));

        cut.Find("button[data-account=sign-in]").Click();
        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("input[data-signin=code]").Input("123456"));
        cut.Find("form[data-signin=code-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("input[data-signin=name]").Input("Ash"));
        cut.Find("form[data-signin=name-form]").Submit();
        cut.WaitForAssertion(() => cut.Find("button[data-account=sign-out]").Click());

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-account=sign-in]")));
        Assert.Empty(cut.FindAll("[data-signin=panel]"));
    }

    [Fact]
    public void Resend_is_disabled_right_after_sending()
    {
        Register();
        var cut = Render<SignInPanel>(p => p.Add(c => c.DeviceId, "web-1"));
        cut.Find("input[data-signin=email]").Input("me@example.com");
        cut.Find("form[data-signin=email-form]").Submit();

        cut.WaitForAssertion(() => Assert.True(cut.Find("button[data-signin=resend]").HasAttribute("disabled")));
    }

    [Fact]
    public void Account_chip_renders_nothing_without_an_account_service()
    {
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));
        Assert.Empty(cut.FindAll("[data-account]"));
    }

    [Fact]
    public void Account_chip_shows_the_name_and_signs_out()
    {
        var session = Register(FakeAccountSession.Account("Ash"));
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));

        Assert.Contains("Ash", cut.Find("[data-account=chip]").TextContent);
        cut.Find("button[data-account=sign-out]").Click();

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-account=sign-in]")));
        Assert.True(session.SignedOut);
    }

    [Fact]
    public void Account_chip_asks_an_unnamed_account_for_a_name()
    {
        Register(FakeAccountSession.Account(""));
        var cut = Render<AccountChip>(p => p.Add(c => c.ClientKind, "web"));

        Assert.NotNull(cut.Find("input[data-signin=name]"));
    }
}
