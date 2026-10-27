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
    public async Task Spawn_zone_tool_toggles_visible_flags_and_saves_them_without_replacing_terrain()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-height='raise']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='spawn-zone']").Click();
        Assert.Equal("true", cut.Find("[data-action='spawn-zone']").GetAttribute("aria-pressed"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.NotNull(cut.Find("[data-hex='0,0'] [data-spawn-zone='void']"));
        Assert.Contains("Spawn zone: Void", cut.Find(".hex-info").TextContent);
        Assert.Contains("Height: 1", cut.Find(".hex-info").TextContent);
        cut.Find("[data-action='save']").Click();
        cut.WaitForAssertion(() => Assert.Single(_maps.Definitions));
        cut.Find("[data-action='close-dialog']").Click();
        var saved = MapFileCodec.Import(MapFileCodec.ExportCanonical(_maps.Definitions[0]));
        Assert.Equal("void", saved.Map.Hexes.Single(h => h.Q == 0 && h.R == 0).SpawnZone);
        cut.Find("[data-hex='0,0']").Click();
        Assert.Empty(cut.FindAll("[data-spawn-zone]"));
        cut.Find("[data-action='erase-tile']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='spawn-zone']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("empty", cut.Find("[data-hex='0,0']").ClassList);
        Assert.Empty(cut.FindAll("[data-spawn-zone]"));
    }

    [Fact]
    public async Task Erased_tiles_save_as_holes_and_can_be_repainted_without_old_contents()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-height='raise']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find(".player-button").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='erase-tile']").Click();
        cut.Find("[data-hex='0,0']").Click();
        var empty = cut.Find("[data-hex='0,0']");
        Assert.Contains("empty", empty.ClassList);
        Assert.Single(empty.Children);
        Assert.Contains("Empty cell", empty.GetAttribute("aria-label"));
        cut.Find("[data-action='save']").Click();
        cut.WaitForAssertion(() => Assert.Single(_maps.Definitions));
        cut.Find("[data-action='close-dialog']").Click();
        Assert.DoesNotContain(_maps.Definitions[0].Map.Hexes, h => h.Q == 0 && h.R == 0);
        var reloaded = MapFileCodec.Import(MapFileCodec.ExportCanonical(_maps.Definitions[0]));
        Assert.Equal(149, reloaded.Map.Hexes.Count);
        cut.Find("[data-biome='water']").Click();
        cut.Find("[data-hex='0,0']").KeyDown("Enter");
        Assert.DoesNotContain("empty", cut.Find("[data-hex='0,0']").ClassList);
        Assert.Contains("Water / Open Water", cut.Find(".hex-info").TextContent);
        Assert.Contains("Height: 0", cut.Find(".hex-info").TextContent);
        Assert.Empty(cut.FindAll("[data-hex='0,0'] .start-label"));
    }

    [Theory]
    [InlineData(1, 1, 1)]
    [InlineData(2, 7, 9)]
    [InlineData(3, 19, 25)]
    public void Hex_map_dialog_creates_equal_sides_and_editable_empty_corners(int side, int tiles, int cells)
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-action='new']").Click();
        cut.Find("#new-map-shape").Change("hex");
        Assert.Empty(cut.FindAll("#new-map-columns"));
        cut.Find("#new-map-side").Change(side.ToString());
        cut.Find("form").Submit();
        Assert.Equal(cells, cut.FindAll("[data-hex]").Count);
        Assert.Equal(tiles, cut.FindAll(".editor-hex:not(.empty)").Count);
    }

    [Fact]
    public void New_map_can_copy_a_built_in_scenario_under_a_new_name_without_signing_in()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-action='new']").Click();
        cut.Find("#new-map-shape").Change("copy");
        cut.WaitForAssertion(() => Assert.Equal(3, cut.FindAll("#new-map-source option").Count));
        Assert.Empty(cut.FindAll("#new-map-columns"));
        Assert.Contains("Void Reaches (Void incursion)", cut.Find("#new-map-source").TextContent);
        cut.Find("form").Submit();
        Assert.Equal("Choose a scenario to copy.", cut.Find("[role=alert]").TextContent);

        cut.Find("#new-map-source").Change("Void Reaches");
        Assert.Equal("Void Reaches copy", cut.Find("#new-map-name").GetAttribute("value"));
        cut.Find("form").Submit();

        cut.WaitForAssertion(() => Assert.Empty(cut.FindAll("[role=dialog]")));
        Assert.Contains("load-built-in:Void Reaches", _maps.Calls);
        Assert.Equal(_maps.BuiltIn.Map.Hexes.Count, cut.FindAll(".editor-hex:not(.empty)").Count);
        Assert.Contains("Void Reaches copy", cut.Markup);
        Assert.Contains("Copied Void Reaches.", cut.Markup);
    }

    [Fact]
    public void A_scenario_that_fails_to_load_keeps_the_dialog_open_with_the_reason()
    {
        _maps.BuiltInFailure = new HttpRequestException("offline");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='new']").Click();
        cut.Find("#new-map-shape").Change("copy");
        cut.WaitForAssertion(() => Assert.Equal(3, cut.FindAll("#new-map-source option").Count));
        cut.Find("#new-map-name").Input("My own name");
        cut.Find("#new-map-source").Change("Four Corners");
        Assert.Equal("My own name", cut.Find("#new-map-name").GetAttribute("value"));
        cut.Find("form").Submit();
        cut.WaitForAssertion(() => Assert.Contains("Four Corners could not be loaded", cut.Find("[role=alert]").TextContent));
        Assert.NotNull(cut.Find("[role=dialog]"));
    }

    [Fact]
    public void Player_start_icons_use_open_portals_and_the_fourth_player_is_magenta()
    {
        var cut = Render<MapEditor>(p => p.Add(c => c.ImageBaseUrl, "https://example.test/"));
        cut.Find("[data-player='1']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("red-open.png", cut.Find("[data-portal='1'] image").GetAttribute("href"));
        for (var player = 1; player < 4; player++) cut.Find($"[data-player='{player}']").Click();
        Assert.Contains("magenta-open.png", cut.Find("[data-player='4'] img").GetAttribute("src"));
        cut.Find("[data-hex='1,0']").Click();
        Assert.Contains("magenta-open.png", cut.Find("[data-portal='4'] image").GetAttribute("href"));
    }

    [Fact]
    public void After_the_four_starts_the_tool_places_the_one_void_portal()
    {
        var cut = Render<MapEditor>(p => p.Add(c => c.ImageBaseUrl, "https://example.test/"));
        for (var click = 0; click < 5; click++) cut.Find(".player-button").Click();
        var button = cut.Find(".player-button");
        Assert.Equal("void", button.GetAttribute("data-player"));
        Assert.EndsWith("images/void-portal/icon.png", button.QuerySelector("img")!.GetAttribute("src"));
        Assert.Contains("Void Portal", button.GetAttribute("aria-label"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Equal("icon", cut.Find("[data-hex='0,0'] [data-void-portal]").GetAttribute("data-void-portal"));
        Assert.EndsWith("images/void-portal/icon.png", cut.Find("[data-hex='0,0'] [data-void-portal] image").GetAttribute("href"));
        Assert.Contains("Void Portal", cut.Find(".hex-info").TextContent);
        cut.Find("[data-hex='1,0']").Click();
        Assert.Single(cut.FindAll("[data-void-portal]"));
        Assert.NotNull(cut.Find("[data-hex='1,0'] [data-void-portal]"));
        cut.Find("[data-action='erase']").Click();
        cut.Find("[data-hex='1,0']").Click();
        Assert.Empty(cut.FindAll("[data-void-portal]"));
    }

    [Fact]
    public void Toolbar_exposes_all_actions_without_mode_selection()
    {
        var cut = Render<MapEditor>();
        Assert.Empty(cut.FindAll("[data-tool]"));
        Assert.NotNull(cut.Find("[data-height='raise']"));
        Assert.NotNull(cut.Find("[data-player='1']"));
        Assert.NotNull(cut.Find("[data-biome='water']"));
        cut.Find("[data-height='raise']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-biome='water']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("Water / Open Water", cut.Find(".hex-info").TextContent);
        Assert.Contains("Height: 1", cut.Find(".hex-info").TextContent);
        Assert.Empty(cut.FindAll("[data-current-action]"));
        Assert.Equal("false", cut.Find("[data-height='raise']").GetAttribute("aria-pressed"));
    }

    [Fact]
    public void New_map_dialog_sets_name_and_dimensions_and_tapping_name_renames()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-action='new']").Click();
        cut.Find("#new-map-name").Input("River pass");
        cut.Find("#new-map-columns").Change("6");
        cut.Find("#new-map-rows").Change("5");
        cut.Find("form").Submit();
        Assert.Equal(30, cut.FindAll("[data-hex]").Count);
        Assert.Contains("River pass", cut.Find(".map-name").TextContent);
        Assert.Equal("River pass", cut.Find("[data-action='rename'] strong").TextContent);
        Assert.Empty(cut.FindAll("[data-action='rename'] svg"));
        cut.Find("[data-action='rename']").Click();
        cut.Find("#map-name").Input("Mountain pass");
        cut.Find("form").Submit();
        Assert.Contains("Mountain pass", cut.Find(".map-name").TextContent);
    }

    [Fact]
    public void Biome_preview_cycles_and_matches_the_terrain_painted()
    {
        var cut = Render<MapEditor>(p => p.Add(c => c.ImageBaseUrl, "https://images.test/"));
        Assert.EndsWith("/images/terrain/plains-grass.svg", cut.Find("[data-biome='plains'] img").GetAttribute("src"));
        cut.Find("[data-biome='plains']").Click();
        Assert.EndsWith("/images/terrain/plains-roads.svg", cut.Find("[data-biome='plains'] img").GetAttribute("src"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("Plains / Roads", cut.Find(".hex-info").TextContent);
        cut.Find("[data-biome='water']").Click();
        Assert.EndsWith("/images/terrain/water-open-water.svg", cut.Find("[data-biome='water'] img").GetAttribute("src"));
        cut.Find("[data-biome='water']").Click();
        Assert.EndsWith("/images/terrain/water-shallow-water.svg", cut.Find("[data-biome='water'] img").GetAttribute("src"));
        cut.Find("[data-biome='plains']").Click();
        Assert.Equal("roads", cut.Find("[data-biome='plains']").GetAttribute("data-terrain"));
        cut.Find("[data-biome='plains']").Click();
        Assert.EndsWith("/images/terrain/plains-grass.svg", cut.Find("[data-biome='plains'] img").GetAttribute("src"));
    }

    [Fact]
    public async Task Editor_navigation_and_account_controls_are_in_the_shared_menu()
    {
        _account.NameAfterVerify = "Ash";
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        var menu = cut.Find("details.main-menu");
        Assert.Equal("./", menu.QuerySelector("[data-menu='game']")!.GetAttribute("href"));
        Assert.NotNull(menu.QuerySelector("[data-menu='rulebook']"));
        Assert.NotNull(menu.QuerySelector("[data-account='chip']"));
        Assert.Equal("Log Out", menu.QuerySelector("[data-account='sign-out']")!.TextContent.Trim());
        Assert.Empty(cut.FindAll("header [data-account]"));
        Assert.Single(cut.FindAll("[data-account='sign-out']"));
        Assert.Empty(cut.FindAll("[data-menu='editor']"));
    }

    [Fact]
    public void Opening_sign_in_in_the_editor_menu_keeps_the_current_map()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-height='raise']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("details [data-account='sign-in']").Click();
        Assert.NotNull(cut.Find("details [data-signin='email-form']"));
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
    }

    [Fact]
    public void Anonymous_users_edit_and_export_without_database_calls()
    {
        var cut = Render<MapEditor>();
        Assert.True(cut.Find("[data-action='save']").HasAttribute("disabled"));
        cut.Find("[data-height='raise']").Click();
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
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='new']").Click();
        cut.Find("form").Submit();
        cut.Find("[data-action='keep-editing']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        cut.Find("[data-action='new']").Click();
        cut.Find("form").Submit(); cut.Find("[data-action='discard']").Click();
        Assert.DoesNotContain("+1", cut.Find("[data-hex='0,0']").TextContent);
    }

    [Fact]
    public void Player_tool_renders_the_selected_player_number()
    {
        var cut = Render<MapEditor>();
        cut.Find(".player-button").Click();
        Assert.Equal("1", cut.Find(".player-button").GetAttribute("data-player"));
        cut.Find(".player-button").Click();
        Assert.Equal("2", cut.Find(".player-button").GetAttribute("data-player"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Equal("P2", cut.Find("[data-hex='0,0'] .start-label").TextContent);
    }

    [Fact]
    public void Elevation_and_player_actions_apply_without_mode_buttons()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-height='raise']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-height='lower']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("Height: 1", cut.Find(".hex-info").TextContent);
        cut.Find("[data-height='reset']").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Contains("Height: 0", cut.Find(".hex-info").TextContent);
        for (var click = 0; click < 4; click++) cut.Find(".player-button").Click();
        cut.Find("[data-hex='0,0']").Click();
        Assert.Equal("P4", cut.Find("[data-hex='0,0'] .start-label").TextContent);
        cut.Find(".player-button").Click();
        Assert.Equal("void", cut.Find(".player-button").GetAttribute("data-player"));
        cut.Find(".player-button").Click();
        Assert.Equal("1", cut.Find(".player-button").GetAttribute("data-player"));
        cut.Find("[data-action='erase']").Click();
        Assert.Equal("true", cut.Find("[data-action='erase']").GetAttribute("aria-pressed"));
        cut.Find("[data-hex='0,0']").Click();
        Assert.Empty(cut.FindAll(".start-label"));
    }

    [Fact]
    public void New_map_rejects_invalid_values_and_can_be_closed_without_changes()
    {
        var cut = Render<MapEditor>();
        cut.Find("[data-action='new']").Click();
        cut.Find("#new-map-name").Input(" ");
        cut.Find("form").Submit();
        Assert.NotNull(cut.Find("[role='dialog'] [role='alert']"));
        cut.Find("#new-map-name").Input("Invalid dimensions");
        cut.Find("#new-map-columns").Change("31");
        cut.Find("form").Submit();
        Assert.Contains("between 5 and 30", cut.Find("[role='dialog'] [role='alert']").TextContent);
        cut.Find("[role='dialog']").KeyDown("Escape");
        Assert.Empty(cut.FindAll("[role='dialog']"));
        Assert.Equal(150, cut.FindAll("[data-hex]").Count);
        Assert.Contains("Ready", cut.Find("[role='status']").TextContent);
    }

    [Fact]
    public async Task Signed_in_save_creates_then_updates_and_save_as_creates_a_copy()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        cut.WaitForAssertion(() => Assert.Contains("Saved", cut.Find("[role='status']").TextContent));
        cut.Find("[data-action='close-dialog']").Click();
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
        cut.Find("[data-action='save']").Click();
        cut.Find("[data-action='save-as']").Click();
        Assert.Equal(new[] { "list", "create", "save:r1", "create" }, _maps.Calls);
        Assert.Equal(1, _maps.Definitions.Last().Map.Hexes.First().Elevation);
    }

    [Fact]
    public async Task Saving_reports_each_mode_and_records_the_ready_ones_even_for_an_unfinished_map()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        cut.WaitForAssertion(() => Assert.Single(_maps.Definitions));
        Assert.Empty(_maps.Definitions[0].ModeCompatibility);
        Assert.Contains("not ready for any game mode", cut.Find("[role='dialog']").TextContent);
        Assert.Contains("Place a P2 start; vanilla duel needs one.", cut.Find("[data-mode='VanillaPvp']").TextContent);

        cut.Find("[data-action='close-dialog']").Click();
        cut.Find(".player-button").Click();
        cut.Find("[data-hex='0,1']").Click();
        cut.Find(".player-button").Click();
        cut.Find("[data-hex='0,13']").Click();
        cut.Find("[data-action='save']").Click();

        cut.WaitForAssertion(() => Assert.Equal(2, _maps.Definitions.Count));
        Assert.Equal([GameMode.VanillaPvp], _maps.Definitions[1].ModeCompatibility);
        Assert.Equal(2, _maps.Definitions[1].PlayerCounts![GameMode.VanillaPvp]);
        Assert.Contains("Vanilla duel: ready for 2 players", cut.Find("[data-mode='VanillaPvp']").TextContent.Trim());
        Assert.Contains("not ready", cut.Find("[data-mode='Coop']").TextContent);
    }

    [Fact]
    public async Task Conflict_preserves_edits_and_offers_reload_or_save_as()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        cut.Find("[data-action='close-dialog']").Click();
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
        _maps.SaveFailure = new GameApiException(System.Net.HttpStatusCode.Conflict, "mapConflict", "Saved elsewhere");
        cut.Find("[data-action='save']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        Assert.NotNull(cut.Find("[data-action='reload']"));
        Assert.NotNull(cut.Find("[data-action='save-as']"));
        Assert.NotEmpty(cut.Find("[role='alert']").TextContent);
    }

    [Fact]
    public async Task Server_rejection_shows_the_server_reason()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var cut = Render<MapEditor>();
        cut.Find("[data-action='save']").Click();
        _maps.SaveFailure = new GameApiException(System.Net.HttpStatusCode.BadRequest, "invalidMap", "Invalid portal flag.");
        cut.Find("[data-action='save']").Click();
        Assert.Contains("Invalid portal flag.", cut.Find("[role='alert']").TextContent);
    }

    [Fact]
    public async Task Signing_out_during_save_ignores_the_response_and_keeps_local_edits()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        var completion = new TaskCompletionSource<SavedMap>(TaskCreationOptions.RunContinuationsAsynchronously);
        _maps.PendingCreate = completion.Task;
        var cut = Render<MapEditor>();
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
        var saving = cut.Find("[data-action='save']").ClickAsync(new());
        await cut.InvokeAsync(() => _account.SignOutAsync());
        completion.SetResult(EditorMapApi.Saved(_maps.Definitions.Last()));
        await saving;
        Assert.True(cut.Find("[data-action='save']").HasAttribute("disabled"));
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
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
        Assert.Empty(cut.FindAll("[data-load-map]"));
        cut.Find("[data-action='load']").Click();
        cut.Find("[data-load-map='map1']").Click();
        Assert.DoesNotContain("load", _maps.Calls);
        cut.Find("[data-action='keep-editing']").Click();
        Assert.Contains("+1", cut.Find("[data-hex='0,0']").TextContent);
        cut.Find("[data-load-map='map1']").Click(); cut.Find("[data-action='discard']").Click();
        Assert.Single(cut.FindAll(".editor-hex:not(.empty)"));
        Assert.Single(cut.FindAll(".editor-hex.empty"));
        Assert.Contains("Crossing", cut.Find(".map-name").TextContent);
        Assert.Contains("Loaded Crossing.", cut.Find("[role='status']").TextContent);
        Assert.DoesNotContain("%", cut.Find("[role='status']").TextContent);
        cut.Find("[data-height='raise']").Click();
        Assert.DoesNotContain("Loaded", cut.Find("[role='status']").TextContent);
        cut.Find(".editor-hex:not(.empty)").Click();
        Assert.Contains("Unsaved changes", cut.Find("[role='status']").TextContent);
    }

    [Fact]
    public async Task Late_load_after_signout_cannot_replace_local_edits()
    {
        await _account.VerifyCodeAsync("me@example.test", "123456", "device");
        _maps.List = [new("map1", "Crossing", "r1", 1, "2026-10-21T00:00:00Z")];
        var pending = new TaskCompletionSource<SavedMap>(TaskCreationOptions.RunContinuationsAsynchronously);
        _maps.PendingLoad = pending.Task;
        var cut = Render<MapEditor>();
        cut.Find("[data-action='load']").Click();
        var loading = cut.Find("[data-load-map='map1']").ClickAsync(new());
        await cut.InvokeAsync(() => _account.SignOutAsync());
        cut.Find("[data-height='raise']").Click(); cut.Find("[data-hex='0,0']").Click();
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
        cut.WaitForAssertion(() => Assert.False(cut.Find("[data-action='load']").HasAttribute("disabled")));
        cut.Find("[data-action='load']").Click();
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
        public Exception? BuiltInFailure;
        public ScenarioDefinition BuiltIn = MapFileCodec.Import(MapFileCodecTests.Legacy) with { Name = "Void Reaches" };
        public Task<BuiltInScenarios> ListBuiltInAsync(CancellationToken c = default)
        {
            Calls.Add("list-built-in");
            return Task.FromResult(new BuiltInScenarios([new("Four Corners", [GameMode.VanillaPvp]), new("Void Reaches", [GameMode.Coop])]));
        }
        public Task<ScenarioDefinition> LoadBuiltInAsync(string name, CancellationToken c = default)
        {
            Calls.Add($"load-built-in:{name}");
            return BuiltInFailure is null ? Task.FromResult(BuiltIn) : Task.FromException<ScenarioDefinition>(BuiltInFailure);
        }
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
