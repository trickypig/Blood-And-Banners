using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class MapEditorDocument
{
    public ScenarioDefinition Definition { get; private set; } = Empty("New Map", 15, 10);
    public bool IsDirty { get; private set; }
    public SavedMapSummary? SavedSummary { get; private set; }
    public string? SavedOwnerId { get; private set; }
    public (int Q, int R)? Selection { get; private set; }
    public ScenarioHex? SelectedHex => Selection is { } s ? Definition.Map.Hexes.FirstOrDefault(h => h.Q == s.Q && h.R == s.R) : null;
    public bool HasScenarioMetadata => MapFileCodec.HasScenarioMetadata(Definition);

    public void New(string name, int columns, int rows)
    {
        if (columns is < 5 or > 30 || rows is < 5 or > 30) throw new FormatException("New map dimensions must be 5–30.");
        Definition = Empty(name, columns, rows); IsDirty = true; DetachSavedMap(); Selection = null;
    }
    public void Import(string json)
    {
        var definition = MapFileCodec.Import(json);
        Definition = definition; IsDirty = true; DetachSavedMap(); Selection = null;
    }
    public void Load(SavedMap map, string ownerId)
    {
        MapFileCodec.Validate(map.Definition);
        Definition = map.Definition; Selection = null; MarkSaved(map, ownerId);
    }
    public void Select(int q, int r) => Selection = (q, r);
    public void Rename(string name)
    {
        if (name != Definition.Name) { Definition = Definition with { Name = name }; IsDirty = true; }
    }
    public void Paint(int q, int r, string biome, string terrain)
    {
        if (!TerrainArt.IsValid(biome, terrain)) throw new FormatException("Invalid terrain.");
        Edit(q, r, h => h with { Biome = biome, Terrain = terrain });
    }
    public void AdjustHeight(int q, int r, int delta) => Edit(q, r, h => h with { Elevation = Math.Clamp(h.Elevation + delta, 0, 10) });
    public void ResetHeight(int q, int r) => Edit(q, r, h => h with { Elevation = 0 });
    public void SetPlayerStart(int q, int r, int? player)
    {
        if (player is < 1 or > 4) throw new ArgumentOutOfRangeException(nameof(player));
        if (!Definition.Map.Hexes.Any(h => h.Q == q && h.R == r)) return;
        Select(q, r);
        // A hex holds one thing, so a start clears any object from its hex.
        var hexes = Definition.Map.Hexes.Select(h => h.Q == q && h.R == r ? (player is null ? h : WithoutObject(h)) with { PlayerStart = player }
            : player is not null && h.PlayerStart == player ? h with { PlayerStart = null } : h).ToList();
        if (!hexes.SequenceEqual(Definition.Map.Hexes))
        {
            Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
        }
    }
    /// <summary>
    /// Places an obstacle, landmark, nexus point or item, replacing whatever was on the hex (a start or another object);
    /// null clears it. Only obstacles carry the sight-blocker flag.
    /// </summary>
    public void SetObject(int q, int r, string? kind)
    {
        if (kind is not null && !MapObjectArt.IsKnown(kind)) throw new ArgumentOutOfRangeException(nameof(kind));
        Edit(q, r, h => kind is null ? WithoutObject(h) : h with { Object = kind, SightBlocker = MapObjectArt.BlocksSight(kind), PlayerStart = null });
    }
    /// <summary>Removes everything on the hex (start position and object), leaving its terrain and height.</summary>
    public void Erase(int q, int r) => Edit(q, r, h => WithoutObject(h) with { PlayerStart = null });
    private static ScenarioHex WithoutObject(ScenarioHex hex) =>
        hex.SightBlocker || hex.Object is not null ? hex with { Object = null, SightBlocker = false } : hex;
    public void Clear()
    {
        var hexes = Definition.Map.Hexes.Select(h => h with { Biome = "plains", Terrain = "grass", Elevation = 0, PlayerStart = null }).ToList();
        Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
    }
    public void DetachSavedMap() { SavedSummary = null; SavedOwnerId = null; }
    public void MarkSaved(SavedMap map, string ownerId)
    {
        SavedSummary = map.Summary; SavedOwnerId = ownerId; IsDirty = false;
        Definition = map.Definition;
    }
    private void Edit(int q, int r, Func<ScenarioHex, ScenarioHex> edit)
    {
        Select(q, r);
        var hexes = Definition.Map.Hexes.Select(h => h.Q == q && h.R == r ? edit(h) : h).ToList();
        if (!hexes.SequenceEqual(Definition.Map.Hexes))
        {
            Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
        }
    }
    private static ScenarioDefinition Empty(string name, int columns, int rows) => new(1, name,
        [GameMode.VanillaPvp, GameMode.Coop, GameMode.BattleRoyale], new(columns, rows,
            Enumerable.Range(0, columns).SelectMany(col => Enumerable.Range(0, rows).Select(row =>
                new ScenarioHex(row - col / 2, col, "plains", "grass", 0, null, false, null, null, null, false, false))).ToList()),
        [], [], MapFileCodec.DefaultScoring);
}
