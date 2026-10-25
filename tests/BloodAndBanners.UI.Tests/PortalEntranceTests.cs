namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.JSInterop;

public sealed class PortalEntranceTests : BunitContext
{
    public PortalEntranceTests() => JSInterop.Mode = JSRuntimeMode.Loose;

    private static GameProjection Projection() => BattleFixtures.Projection() with
    {
        Board = BattleFixtures.Projection().Board with
        {
            Hexes = BattleFixtures.Projection().Board.Hexes.Select((hex, i) => hex with { PlayerStart = i == 0 ? 4 : null }).ToList(),
        },
    };

    [Fact]
    public void Existing_games_show_inactive_magenta_portals()
    {
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection()).Add(c => c.ImageBaseUrl, "https://example.test/"));
        Assert.Contains("magenta-closed.png", cut.Find("[data-portal='4'] image").GetAttribute("href"));
        Assert.DoesNotContain("portal-entrance", cut.Find("main").ClassList);
    }

    [Fact]
    public async Task Leaving_during_module_loading_disposes_the_late_module_without_starting_it()
    {
        var runtime = new DeferredPortalRuntime();
        Services.AddSingleton<IJSRuntime>(runtime);
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection())
            .Add(c => c.PlayEntrance, true).Add(c => c.ImageBaseUrl, "https://example.test/"));
        await cut.InvokeAsync(async () => await cut.Instance.DisposeAsync());
        var module = new PortalModule();
        runtime.Import.SetResult(module);
        cut.WaitForAssertion(() => Assert.True(module.Disposed));
        Assert.False(module.Started);
    }

    private sealed class DeferredPortalRuntime : IJSRuntime
    {
        public TaskCompletionSource<IJSObjectReference> Import { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public ValueTask<T> InvokeAsync<T>(string identifier, object?[]? args) => InvokeAsync<T>(identifier, default, args);
        public ValueTask<T> InvokeAsync<T>(string identifier, CancellationToken cancellationToken, object?[]? args) => new(Load<T>());
        private async Task<T> Load<T>() => (T)await Import.Task;
    }

    private sealed class PortalModule : IJSObjectReference
    {
        public bool Disposed { get; private set; }
        public bool Started { get; private set; }
        public ValueTask DisposeAsync() { Disposed = true; return ValueTask.CompletedTask; }
        public ValueTask<T> InvokeAsync<T>(string identifier, object?[]? args) => InvokeAsync<T>(identifier, default, args);
        public ValueTask<T> InvokeAsync<T>(string identifier, CancellationToken cancellationToken, object?[]? args)
        {
            Started |= identifier == "start";
            return ValueTask.FromResult(default(T)!);
        }
    }

    [Fact]
    public async Task Entrance_blocks_commands_until_completion_and_does_not_replay_on_poll()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(p => p.Add(c => c.Projection, Projection())
            .Add(c => c.PlayEntrance, true).Add(c => c.ImageBaseUrl, "https://example.test/")
            .Add(c => c.OnCommand, (GameCommandRequest command) => sent.Add(command)));
        Assert.Contains("portal-entrance", cut.Find("main").ClassList);
        Assert.True(cut.Find("[data-action='endTurn']").HasAttribute("disabled"));
        cut.Find("[data-hex='1,4']").Click();
        Assert.DoesNotContain("selected", cut.Find("[data-hex='1,4']").ClassList);
        await cut.InvokeAsync(() => cut.Instance.CompletePortalEntrance(Projection().GameId));
        Assert.DoesNotContain("portal-entrance", cut.Find("main").ClassList);
        cut.Find("[data-action='endTurn']").Click();
        Assert.Single(sent);
        cut.Render(p => p.Add(c => c.Projection, Projection() with { Version = Projection().Version + 1 }));
        Assert.DoesNotContain("portal-entrance", cut.Find("main").ClassList);
        Assert.Contains("magenta-closed.png", cut.Find("[data-portal='4'] image").GetAttribute("href"));
    }
}
