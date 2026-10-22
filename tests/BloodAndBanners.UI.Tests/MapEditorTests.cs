using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

namespace BloodAndBanners.UI.Tests;

public sealed class MapEditorTests : BunitContext
{
    private readonly FakeAccountSession _account = new();
    private readonly EditorMapApi _maps = new();

    public MapEditorTests()
    {
        Services.AddSingleton<IAccountSession>(_account);
        Services.AddSingleton<IMapApiClient>(_maps);
        JSInterop.Mode = JSRuntimeMode.Loose;
    }

    [Fact]
    public void Anonymous_users_edit_and_export_without_database_calls()
    {
        var cut = Render<MapEditor>();
        Assert.Empty(cut.FindAll("[data-action='save']"));
        cut.Find("[data-tool='height']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        cut.Find("[data-action='export']").Click();
        Assert.Empty(_maps.Calls);
        Assert.Contains(JSInterop.Invocations, i => i.Identifier == "download");
    }

    [Fact]
    public void Dirty_new_map_can_be_cancelled_then_discarded()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='new']").Click();
        cut.Find("[data-action='keep-editing']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        cut.Find("[data-action='new']").Click(); cut.Find("[data-action='discard']").Click();
        Assert.DoesNotContain("+1", cut.Find("[data-hex='0,0']").TextContent);
    }

    [Fact]
    public void Player_tool_renders_the_selected_player_number()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-tool='player']").Click();
        cut.Find("#player-start").Change("2");
        cut.Find("[data-hex='0,0']").Click();
        Assert.Equal("P2", cut.Find("[data-hex='0,0'] .start-label").TextContent);
    }

    [Fact]
    public async Task Signed_in_save_creates_then_updates_and_save_as_creates_a_copy()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        cut.WaitForAssertion(() => Assert.Contains("Saved", cut.Find("[role='status']").TextContent));
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='save']").Click();
        cut.Find("[data-action='save-as']").Click();
        Assert.Equal(new[] { "list", "create", "save:r1", "create" }, _maps.Calls);
        Assert.Equal(1, _maps.Definitions.Last().Map.Hexes.First().Elevation);
    }

    [Fact]
    public async Task Conflict_preserves_edits_and_offers_reload_or_save_as()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        _maps.SaveFailure = new GameApiException(System.Net.HttpStatusCode.Conflict, "mapConflict", "Saved elsewhere");
        cut.Find("[data-action='save']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        Assert.NotNull(cut.Find("[data-action='reload']"));
        Assert.NotNull(cut.Find("[data-action='save-as']"));
        Assert.NotEmpty(cut.Find("[role='alert']").TextContent);
    }

    [Fact]
    public async Task Signing_out_during_save_ignores_the_response_and_keeps_local_edits()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var completion = new TaskCompletionSource<SavedMap>(TaskCreationOptions.RunContinuationsAsynchronously);
        _maps.PendingCreate = completion.Task;
        var cut = Render<MapEditor>();
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        var saving = cut.Find("[data-action='save']").ClickAsync(new());
        await cut.InvokeAsync(() => _account.SignOutAsync());
        completion.SetResult(EditorMapApi.Saved(_maps.Definitions.Last()));
        await saving;
        Assert.Empty(cut.FindAll("[data-action='save']"));
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        Assert.NotNull(cut.Find("[data-action='export']"));
        await cut.InvokeAsync(() => _account.VerifyCodeAsync("me@example.test", "123456", "device"));
        _maps.PendingCreate = null;
        cut.Find("[data-action='save']").Click();
        Assert.Equal(2, _maps.Calls.Count(c => c == "create"));
    }

    [Fact]
    public async Task Load_requires_discard_confirmation_and_replaces_the_map_only_after_acceptance()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        _maps.List = [new("map1", "Crossing", "r1", 1, "2026-10-21T00:00:00Z")];
        var cut = Render<MapEditor>();
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-load-map='map1']").Click();
        Assert.DoesNotContain("load", _maps.Calls);
        cut.Find("[data-action='keep-editing']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        cut.Find("[data-load-map='map1']").Click(); cut.Find("[data-action='discard']").Click();
        Assert.Single(cut.FindAll("[data-hex]"));
        Assert.Equal("Crossing", cut.Find("#map-name").GetAttribute("value"));
    }

    [Fact]
    public async Task Late_load_after_signout_cannot_replace_local_edits()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        _maps.List = [new("map1", "Crossing", "r1", 1, "2026-10-21T00:00:00Z")];
        var pending = new TaskCompletionSource<SavedMap>(TaskCreationOptions.RunContinuationsAsynchronously);
        _maps.PendingLoad = pending.Task;
        var cut = Render<MapEditor>();
        var loading = cut.Find("[data-load-map='map1']").ClickAsync(new());
        await cut.InvokeAsync(() => _account.SignOutAsync());
        cut.Find("[data-tool='height']").Click(); cut.Find("[data-hex='0,0']").Click();
        pending.SetResult(EditorMapApi.Saved(MapFileCodec.Import(MapFileCodecTests.Legacy)));
        await loading;
        Assert.Equal(150, cut.FindAll("[data-hex]").Count);
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
    }

    [Fact]
    public async Task Token_refresh_for_same_account_keeps_saved_map_association()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        await cut.InvokeAsync(() => _account.Raise());
        cut.Find("[data-action='save']").Click();
        Assert.Equal("save:r1", _maps.Calls.Last());
    }

    [Fact]
    public async Task Signing_in_refreshes_map_list_after_an_asynchronous_response()
    {
        var pending = new TaskCompletionSource<SavedMaps>(TaskCreationOptions.RunContinuationsAsynchronously);
        _maps.PendingList = pending.Task;
        var cut = Render<MapEditor>();
        await cut.InvokeAsync(() => _account.VerifyCodeAsync("me@example.test", "123456", "device"));
        pending.SetResult(new([new("loaded-id", "My asynchronously loaded map", "r1", 1, "2026-10-21T00:00:00Z")]));
        cut.WaitForAssertion(() =>
        {
            Assert.NotNull(cut.Find("[data-load-map='loaded-id']"));
            Assert.False(cut.Find("[data-action='save']").HasAttribute("disabled"));
        });
    }

    internal sealed class EditorMapApi : IMapApiClient
    {
        public List<string> Calls { get; } = [];
        public List<ScenarioDefinition> Definitions { get; } = [];
        public Exception? SaveFailure;
        public Task<SavedMap>? PendingCreate;
        public Task<SavedMap>? PendingLoad;
        public IReadOnlyList<SavedMapSummary> List = [];
        public Task<SavedMaps>? PendingList;
        public static SavedMap Saved(ScenarioDefinition definition, string revision = "r1", int number = 1) => new(new("map1", definition.Name, revision, number, "2026-10-21T00:00:00Z"), definition);
        public Task<SavedMaps> ListAsync(AuthSession s, CancellationToken c = default) { Calls.Add("list"); return PendingList ?? Task.FromResult(new SavedMaps(List)); }
        public Task<SavedMap> LoadAsync(AuthSession s, string id, CancellationToken c = default) { Calls.Add("load"); return PendingLoad ?? Task.FromResult(Saved(MapFileCodec.Import(MapFileCodecTests.Legacy))); }
        public Task<SavedMap> CreateAsync(AuthSession s, ScenarioDefinition d, CancellationToken c = default) { Calls.Add("create"); Definitions.Add(d); return PendingCreate ?? Task.FromResult(Saved(d)); }
        public Task<SavedMap> SaveAsync(AuthSession s, string id, string revision, ScenarioDefinition d, CancellationToken c = default)
        {
            Calls.Add($"save:{revision}"); Definitions.Add(d);
            return SaveFailure is null ? Task.FromResult(Saved(d, "r2", 2)) : Task.FromException<SavedMap>(SaveFailure);
        }
    }
}
