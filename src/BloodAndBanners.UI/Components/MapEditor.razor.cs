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
    [Parameter] public string? ImageBaseUrl { get; set; }
    [Inject] private IAccountSession Account { get; set; } = default!;
    [Inject] private IMapApiClient MapApi { get; set; } = default!;
    [Inject] private IJSRuntime JS { get; set; } = default!;
    private readonly MapEditorDocument _document = new();
    private static readonly (string, string)[] Tools = [("paint", "Paint terrain"), ("height", "Adjust height"), ("player", "Player starts")];
    private readonly Dictionary<string, string> _terrains = TerrainArt.BiomeNames.ToDictionary(b => b, b => TerrainArt.Terrains(b)[0]);
    private string _tool = "paint", _biome = "plains", _heightAction = "raise";
    private int _player, _columns = 15, _rows = 10;
    private double _zoom = 1;
    private bool _busy, _conflict, _disposed;
    private string? _error, _accountId;
    private string _status = "Edit a map, import a file, or sign in to load your saved maps.";
    private int _epoch;
    private IReadOnlyList<SavedMapSummary> _maps = [];
    private Func<Task>? _pending;
    private ScenarioDefinition? _layoutDefinition;
    private IReadOnlyList<HexProjection> _hexes = [];
    private BoardLayout _layout = BoardLayout.For([]);
    private IJSObjectReference? _files;

    private BoardLayout Layout
    {
        get
        {
            if (!ReferenceEquals(_layoutDefinition, _document.Definition))
            {
                _layoutDefinition = _document.Definition;
                _hexes = _document.Definition.Map.Hexes.Select(h => new HexProjection(h.Q, h.R, h.Biome, h.Terrain, h.Elevation, h.SightBlocker, null) { PlayerStart = h.PlayerStart }).ToList();
                _layout = BoardLayout.For(_hexes);
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
        _accountId = id; _epoch++; _busy = false; _maps = []; _pending = null;
        _document.DetachSavedMap(); _conflict = false; _error = null;
        _status = id is null ? "Signed out. Your map is still available to edit and export." : "Loading your saved maps…";
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
            if (Current(epoch, owner)) { _maps = list.Maps; _status = "Your saved maps are ready."; _error = null; }
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
            _document.Load(saved, owner); SyncDimensions(); _error = null; _conflict = false;
            _status = $"Loaded {saved.Summary.Name}.";
        }
        catch (Exception e) { if (Current(epoch, owner)) ShowError(e); }
        finally { if (Current(epoch, owner)) _busy = false; }
    }
    private void ShowError(Exception error)
    {
        _conflict = error is GameApiException { Code: "mapConflict" };
        _error = _conflict ? "This map was saved elsewhere. Reload the saved map or save your edits as a new map." :
            error is HttpRequestException or InvalidOperationException ? "Unable to access saved maps. Check your connection and sign-in, then try again. Your edits are still here." : error.Message;
    }
    private void Rename(ChangeEventArgs e) { if (!_busy) _document.Rename(e.Value?.ToString() ?? ""); }
    private void SelectBiome(string biome)
    {
        if (_biome == biome)
        {
            var terrains = TerrainArt.Terrains(biome); var index = terrains.ToList().IndexOf(_terrains[biome]);
            _terrains[biome] = terrains[(index + 1) % terrains.Count];
        }
        _biome = biome;
    }
    private void Edit(int q, int r)
    {
        if (_busy || _pending is not null) return;
        _document.Select(q, r);
        if (_tool == "paint") _document.Paint(q, r, _biome, _terrains[_biome]);
        else if (_tool == "player") _document.SetPlayerStart(q, r, _player == 0 ? null : _player);
        else if (_heightAction == "reset") _document.ResetHeight(q, r);
        else _document.AdjustHeight(q, r, _heightAction == "raise" ? 1 : -1);
    }
    private void HexKey(KeyboardEventArgs e, int q, int r) { if (e.Key is "Enter" or " ") Edit(q, r); }
    private Task RequestNew() => Confirm(() =>
    {
        try { _document.New(_document.Definition.Name, _columns, _rows); _error = null; _conflict = false; _zoom = 1; }
        catch (Exception e) { ShowError(e); }
        return Task.CompletedTask;
    });
    private Task RequestClear() => Confirm(() => { _document.Clear(); return Task.CompletedTask; });
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
    private static string StartColor(int start) => start switch { 1 => "#c41e3a", 2 => "#0051ba", 3 => "#00a86b", _ => "#ffaa00" };
    public async ValueTask DisposeAsync()
    {
        _disposed = true; _epoch++; Account.Changed -= AccountChanged;
        if (_files is not null) { try { await _files.DisposeAsync(); } catch (JSDisconnectedException) { } }
    }
}
