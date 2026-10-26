using System.Globalization;
using System.Text;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;
using Microsoft.AspNetCore.Components;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.AspNetCore.Components.Web;
using Microsoft.JSInterop;

namespace BloodAndBanners.UI.Components;

public partial class MapEditor : IAsyncDisposable
{
    [Parameter] public string ClientKind { get; set; } = "web";
    [Parameter] public string? ImageBaseUrl { get; set; }
    [Inject] private IAccountSession Account { get; set; } = default!;
    [Inject] private IMapApiClient MapApi { get; set; } = default!;
    [Inject] private IJSRuntime JS { get; set; } = default!;
    private readonly MapEditorDocument _document = new();
    private static readonly (string, string)[] FileActions = [("new", "New map"), ("load", "Load map"), ("save", "Save"), ("save-as", "Save as new map"), ("import", "Import JSON"), ("export", "Export JSON")];
    private string? _dialog, _dialogError;
    private string _draftName = "New Map";
    private ElementReference _dialogElement;
    private bool _focusDialog;
    private static bool RequiresAccount(string action) => action is "load" or "save" or "save-as";
    private string FileLabel(string action, string label) => RequiresAccount(action) && Account.Current is null ? $"{label} (sign in required)" : label;
    private Task FileAction(string action)
    {
        switch (action)
        {
            case "new": _columns = _document.Definition.Map.Columns; _rows = _document.Definition.Map.Rows; _draftName = "New Map"; OpenDialog("new"); break;
            case "load": OpenDialog("load"); break;
            case "save": return Save(false);
            case "save-as": return Save(true);
            case "export": return Export();
        }
        return Task.CompletedTask;
    }
    private void OpenDialog(string dialog) { _dialog = dialog; _dialogError = null; _focusDialog = true; }
    private void CloseDialog() { _dialog = null; _dialogError = null; }
    private void OpenRename() { _draftName = _document.Definition.Name; OpenDialog("rename"); }
    private void DialogKey(KeyboardEventArgs e) { if (e.Key == "Escape") CloseDialog(); }
    protected override async Task OnAfterRenderAsync(bool firstRender)
    {
        if (_focusDialog) { _focusDialog = false; await _dialogElement.FocusAsync(); }
    }
    private async Task SubmitDialog()
    {
        var name = _draftName.Trim();
        if (name.Length is < 1 or > 120) { _dialogError = "Enter a map name between 1 and 120 characters."; return; }
        if (_dialog == "rename") { _document.Rename(name); _status = ""; CloseDialog(); return; }
        if (_columns is < 5 or > 30 || _rows is < 5 or > 30) { _dialogError = "Columns and rows must be between 5 and 30."; return; }
        var columns = _columns; var rows = _rows;
        CloseDialog();
        await Confirm(() =>
        {
            _document.New(name, columns, rows); _error = null; _conflict = false; _zoom = 1; _status = "New map created.";
            return Task.CompletedTask;
        });
    }
    private void SelectHeight(string action) { _tool = "height"; _heightAction = action; _status = ""; }
    /// <summary>The start button cycles P1–P4, then the Void Portal.</summary>
    private const int VoidPortalChoice = 5;
    private int NextPlayer => _player % VoidPortalChoice + 1;
    private static string StartChoiceName(int choice) => choice == VoidPortalChoice ? "the Void Portal" : $"player {choice} start";
    private void SelectPlayer()
    {
        if (_tool == "player") _player = NextPlayer;
        _tool = "player"; _status = "";
    }
    private readonly Dictionary<string, string> _objectKinds = MapObjectArt.Categories.ToDictionary(category => category, category => MapObjectArt.KindsIn(category)[0]);
    private string NextKind(string category)
    {
        var kinds = MapObjectArt.KindsIn(category);
        return kinds[(kinds.ToList().IndexOf(_objectKinds[category]) + 1) % kinds.Count];
    }
    private void SelectObject(string category)
    {
        if (_tool == category) _objectKinds[category] = NextKind(category);
        _tool = category; _status = "";
    }
    private void SelectErase() { _tool = "erase"; _status = ""; }
    private void ChangeZoom(string action) { _status = ""; if (action == "zoom-reset") _zoom = 1; else Zoom(action == "zoom-in" ? .1 : -.1); }
    private static string IconPath(string action) => action switch
    {
        "new" => "M14 2H4v20h16V8Zm0 0v6h6M8 14h8m-4-4v8",
        "load" => "M3 7V4h6l3 3h9v13H3Zm0 4h18",
        "save" => "M3 3h15l3 3v15H3ZM7 3v6h10V3M7 21v-8h10v8",
        "save-as" => "M3 3h15l3 3v7M3 3v18h9M7 3v6h10V3m-2 15h8m-4-4v8",
        "import" => "M4 15v6h16v-6M12 3v13m-5-5 5 5 5-5",
        "export" => "M4 15v6h16v-6M12 16V3m-5 5 5-5 5 5",
        "raise" => "M4 19h16M12 15V3m-5 5 5-5 5 5",
        "lower" => "M4 21h16M12 3v12m-5-5 5 5 5-5",
        "reset" => "M4 19h16M5 9a7 7 0 1 1 2 6M5 3v6h6",
        "zoom-in" => "M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14m5 12 6 6M6 10h8m-4-4v8",
        "zoom-out" => "M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14m5 12 6 6M6 10h8",
        "erase" => "M9 20H4l-1-4L14 5l6 6-9 9m-6-8 6 6m5 2h6",
        _ => "M4 9V4h5m6 0h5v5M4 15v5h5m6 0h5v-5M9 12h6"
    };
    private readonly Dictionary<string, string> _terrains = TerrainArt.BiomeNames.ToDictionary(b => b, b => TerrainArt.Terrains(b)[0]);
    private string _tool = "paint", _biome = "plains", _heightAction = "raise";
    private int _player = 1, _columns = 15, _rows = 10;
    private double _zoom = 1;
    private bool _busy, _conflict, _disposed;
    private string? _error, _accountId;
    private string _status = "Loaded New Map.";
    private int _epoch;
    private IReadOnlyList<SavedMapSummary> _maps = [];
    private Func<Task>? _pending;
    private ScenarioDefinition? _layoutDefinition;
    private IReadOnlyList<HexProjection> _hexes = [];
    private IReadOnlyDictionary<string, IReadOnlyList<string>> _runeAreas = new Dictionary<string, IReadOnlyList<string>>();
    private BoardLayout _layout = BoardLayout.For([]);
    private IJSObjectReference? _files;

    private BoardLayout Layout
    {
        get
        {
            if (!ReferenceEquals(_layoutDefinition, _document.Definition))
            {
                _layoutDefinition = _document.Definition;
                _hexes = _document.Definition.Map.Hexes.Select(h => new HexProjection(h.Q, h.R, h.Biome, h.Terrain, h.Elevation, h.SightBlocker, null) { PlayerStart = h.PlayerStart, Object = h.Object, Radius = h.Radius, VoidPortal = h.VoidPortal }).ToList();
                _layout = BoardLayout.For(_hexes);
                _runeAreas = RuneAreas.Of(_hexes);
            }
            return _layout;
        }
    }

    protected override async Task OnInitializedAsync()
    {
        Account.Changed += AccountChanged;
        await Account.LoadAsync();
        await AdoptAccount();
    }
    private void AccountChanged() => _ = InvokeAsync(AdoptAccount);
    private async Task AdoptAccount()
    {
        if (_disposed) return;
        var id = Account.Current?.Identity.IdentityId;
        if (id == _accountId) return; // Refreshing tokens does not change map ownership.
        _accountId = id; _epoch++; _busy = false; _maps = []; _pending = null; CloseDialog();
        _document.DetachSavedMap(); _conflict = false; _error = null;
        _status = id is null ? "Signed out. Your map is still available to edit and export." : $"Loaded {_document.Definition.Name}.";
        StateHasChanged();
        if (id is not null) await RefreshMaps();
    }
    private bool Current(int epoch, string owner) => !_disposed && _epoch == epoch && Account.Current?.Identity.IdentityId == owner;
    private Task<T> AccountCall<T>(string owner, Func<AuthSession, Task<T>> call) => Account.CallAsync(session =>
        session.IdentityId == owner ? call(session) : Task.FromException<T>(new InvalidOperationException("Your account changed. Please try again.")));

    private async Task RefreshMaps()
    {
        if (_busy || _accountId is not { } owner) return;
        var epoch = _epoch; _busy = true;
        try
        {
            var list = await AccountCall(owner, s => MapApi.ListAsync(s));
            if (Current(epoch, owner)) { _maps = list.Maps; if (_dialog == "load") _status = "Map list refreshed."; _error = null; }
        }
        catch (Exception e) { if (Current(epoch, owner)) ShowError(e); }
        finally { if (Current(epoch, owner)) { _busy = false; StateHasChanged(); } }
    }
    private async Task Save(bool asNew)
    {
        if (_busy || _accountId is not { } owner) return;
        var epoch = _epoch; _busy = true; _error = null;
        try
        {
            var snapshot = _document.Definition;
            MapFileCodec.Validate(snapshot);
            var saved = !asNew && _document.SavedOwnerId == owner && _document.SavedSummary is { } summary
                ? await AccountCall(owner, s => MapApi.SaveAsync(s, summary.ScenarioId, summary.RevisionId, snapshot))
                : await AccountCall(owner, s => MapApi.CreateAsync(s, snapshot));
            if (!Current(epoch, owner)) return;
            _document.MarkSaved(saved, owner); _conflict = false;
            _maps = new[] { saved.Summary }.Concat(_maps.Where(m => m.ScenarioId != saved.Summary.ScenarioId)).ToList();
            _status = $"Saved {saved.Summary.Name} (revision {saved.Summary.Revision}).";
        }
        catch (Exception e) { if (Current(epoch, owner)) ShowError(e); }
        finally { if (Current(epoch, owner)) _busy = false; }
    }
    private Task RequestLoad(string id) => Confirm(() => Load(id));
    private async Task Load(string id)
    {
        if (_busy || _accountId is not { } owner) return;
        var epoch = _epoch; _busy = true;
        try
        {
            var saved = await AccountCall(owner, s => MapApi.LoadAsync(s, id));
            if (!Current(epoch, owner)) return;
            _document.Load(saved, owner); CloseDialog(); SyncDimensions(); _error = null; _conflict = false;
            _status = $"Loaded {saved.Summary.Name}.";
        }
        catch (Exception e) { if (Current(epoch, owner)) ShowError(e); }
        finally { if (Current(epoch, owner)) _busy = false; }
    }
    private void ShowError(Exception error)
    {
        _conflict = error is GameApiException { Code: "mapConflict" };
        _error = _conflict ? "This map was saved elsewhere. Reload the saved map or save your edits as a new map." :
            // The server's own reason (a rejected map, an expired sign-in) says more than the generic connection hint.
            error is GameApiException api ? $"{api.Message} Your edits are still here." :
            error is HttpRequestException or InvalidOperationException ? "Unable to access saved maps. Check your connection and sign-in, then try again. Your edits are still here." : error.Message;
    }
    private void SelectBiome(string biome)
    {
        if (_tool == "paint" && _biome == biome)
        {
            var terrains = TerrainArt.Terrains(biome); var index = terrains.ToList().IndexOf(_terrains[biome]);
            _terrains[biome] = terrains[(index + 1) % terrains.Count];
        }
        _biome = biome; _tool = "paint"; _status = "";
    }
    private void Edit(int q, int r)
    {
        if (_busy || _pending is not null || _dialog is not null) return;
        _status = "";
        _document.Select(q, r);
        if (_tool == "paint") _document.Paint(q, r, _biome, _terrains[_biome]);
        else if (_tool == "player" && _player == VoidPortalChoice) _document.SetVoidPortal(q, r);
        else if (_tool == "player") _document.SetPlayerStart(q, r, _player);
        else if (_objectKinds.TryGetValue(_tool, out var kind)) _document.SetObject(q, r, kind);
        else if (_tool == "erase") _document.Erase(q, r);
        else if (_heightAction == "reset") _document.ResetHeight(q, r);
        else _document.AdjustHeight(q, r, _heightAction == "raise" ? 1 : -1);
    }
    private void HexKey(KeyboardEventArgs e, int q, int r) { if (e.Key is "Enter" or " ") Edit(q, r); }
    private Task Confirm(Func<Task> action)
    {
        if (_busy) return Task.CompletedTask;
        if (_document.IsDirty) { _pending = action; return Task.CompletedTask; }
        return action();
    }
    private async Task Discard() { var action = _pending; _pending = null; if (action is not null) await action(); }
    private async Task Import(InputFileChangeEventArgs e)
    {
        if (_busy) return;
        try
        {
            using var reader = new StreamReader(e.File.OpenReadStream(MapFileCodec.MaxBytes), Encoding.UTF8);
            var json = await reader.ReadToEndAsync();
            _ = MapFileCodec.Import(json); // Validate before offering to discard the current map.
            await Confirm(() => { _document.Import(json); SyncDimensions(); _conflict = false; _error = null; _status = "Map imported."; return Task.CompletedTask; });
        }
        catch (Exception ex) { _error = $"Could not import map: {ex.Message}"; }
    }
    private async Task Export()
    {
        try
        {
            var json = _document.HasScenarioMetadata ? MapFileCodec.ExportCanonical(_document.Definition) : MapFileCodec.ExportLegacy(_document.Definition);
            _files ??= await JS.InvokeAsync<IJSObjectReference>("import", "./_content/BloodAndBanners.UI/js/mapEditorFiles.js");
            var name = string.Concat(_document.Definition.Name.Select(c => char.IsLetterOrDigit(c) || c is '-' or '_' ? c : '_'));
            await _files.InvokeVoidAsync("download", $"{(string.IsNullOrEmpty(name) ? "map" : name)}.json", json);
            _status = "Map exported. Your database save status is unchanged.";
        }
        catch (Exception e) { _error = $"Could not export map: {e.Message}"; }
    }
    private void SyncDimensions() { _columns = _document.Definition.Map.Columns; _rows = _document.Definition.Map.Rows; _zoom = 1; }
    private void Zoom(double delta) => _zoom = Math.Clamp(_zoom + delta, .5, 2);
    private static string Num(double value) => value.ToString("0.###", CultureInfo.InvariantCulture);
    private static string Display(string value) => CultureInfo.InvariantCulture.TextInfo.ToTitleCase(value.Replace('-', ' '));
    private static string StartColor(int start) => start switch { 1 => "#c41e3a", 2 => "#0051ba", 3 => "#00a86b", _ => "#dd43c9" };
    public async ValueTask DisposeAsync()
    {
        _disposed = true; _epoch++; Account.Changed -= AccountChanged;
        if (_files is not null) { try { await _files.DisposeAsync(); } catch (JSDisconnectedException) { } }
    }
}
