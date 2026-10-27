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
    public void NewHex(string name, int side)
    {
        if (side is < 1 or > 15) throw new FormatException("Hex side length must be 1–15.");
        var radius = side - 1;
        var centerQ = radius - radius / 2;
        var definition = Empty(name, 2 * side - 1, 2 * side - 1);
        Definition = definition with { Map = definition.Map with { Hexes = definition.Map.Hexes.Where(h =>
            Math.Max(Math.Max(Math.Abs(h.Q - centerQ), Math.Abs(h.R - radius)), Math.Abs(h.Q - centerQ + h.R - radius)) <= radius).ToList() } };
        IsDirty = true; DetachSavedMap(); Selection = null;
    }
    /// <summary>Missing coordinate entries are empty cells; dimensions retain the editable canvas.</summary>
    public IEnumerable<(int Q, int R)> Cells => Enumerable.Range(0, Definition.Map.Columns)
        .SelectMany(col => Enumerable.Range(0, Definition.Map.Rows).Select(row => (Q: row - col / 2, R: col)))
        .Union(Definition.Map.Hexes.Select(h => (h.Q, h.R)))
        .Union(Definition.Map.EditorEmptyCells?.Select(c => (c.Q, c.R)) ?? []);
    public void Import(string json)
    {
        var definition = MapFileCodec.Import(json);
        Definition = Renamed(definition); IsDirty = true; DetachSavedMap(); Selection = null;
    }
    public void Load(SavedMap map, string ownerId)
    {
        MapFileCodec.Validate(map.Definition);
        Selection = null; MarkSaved(map, ownerId); Definition = Renamed(Definition);
    }
    public void Select(int q, int r) => Selection = (q, r);
    public void Rename(string name)
    {
        if (name != Definition.Name) { Definition = Definition with { Name = name }; IsDirty = true; }
    }
    public void Paint(int q, int r, string biome, string terrain)
    {
        if (!TerrainArt.IsValid(biome, terrain)) throw new FormatException("Invalid terrain.");
        if (!Definition.Map.Hexes.Any(h => h.Q == q && h.R == r))
        {
            if (!Cells.Contains((q, r))) return;
            var hex = new ScenarioHex(q, r, biome, terrain, 0, null, false, null, null, null, false, false);
            var empty = Definition.Map.EditorEmptyCells?.Where(c => c.Q != q || c.R != r).ToList();
            Definition = Definition with { Map = Definition.Map with { Hexes = [.. Definition.Map.Hexes, hex], EditorEmptyCells = empty is { Count: > 0 } ? empty : null } };
            Select(q, r); IsDirty = true;
            return;
        }
        Edit(q, r, h => h with { Biome = biome, Terrain = terrain });
    }
    public void EraseTile(int q, int r)
    {
        Select(q, r);
        var hexes = Definition.Map.Hexes.Where(h => h.Q != q || h.R != r).ToList();
        if (hexes.Count == Definition.Map.Hexes.Count) return;
        var empty = Definition.Map.EditorEmptyCells;
        if (r < 0 || r >= Definition.Map.Columns || q + r / 2 < 0 || q + r / 2 >= Definition.Map.Rows)
            empty = (empty ?? []).Append(new ScenarioCell(q, r)).Distinct().ToList();
        Definition = Definition with { Map = Definition.Map with { Hexes = hexes, EditorEmptyCells = empty } }; IsDirty = true;
    }
    public void AdjustHeight(int q, int r, int delta) => Edit(q, r, h => h with { Elevation = Math.Clamp(h.Elevation + delta, 0, 10) });
    public void ResetHeight(int q, int r) => Edit(q, r, h => h with { Elevation = 0 });
    public void ToggleSpawnZone(int q, int r) => Edit(q, r, h => h with { SpawnZone = h.SpawnZone == "void" ? null : "void" });
    public void SetPlayerStart(int q, int r, int? player)
    {
        if (player is < 1 or > 4) throw new ArgumentOutOfRangeException(nameof(player));
        if (!Definition.Map.Hexes.Any(h => h.Q == q && h.R == r)) return;
        Select(q, r);
        // A hex holds one thing, so a start clears any object from its hex.
        var hexes = Definition.Map.Hexes.Select(h => h.Q == q && h.R == r ? (player is null ? h : WithoutObject(h) with { VoidPortal = false }) with { PlayerStart = player }
            : player is not null && h.PlayerStart == player ? h with { PlayerStart = null } : h).ToList();
        if (!hexes.SequenceEqual(Definition.Map.Hexes))
        {
            Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
        }
    }
    /// <summary>Moves the map's one Void Portal to the hex, clearing any start or object there.</summary>
    public void SetVoidPortal(int q, int r)
    {
        if (!Definition.Map.Hexes.Any(h => h.Q == q && h.R == r)) return;
        Select(q, r);
        var hexes = Definition.Map.Hexes.Select(h => h.Q == q && h.R == r ? WithoutObject(h) with { PlayerStart = null, VoidPortal = true }
            : h.VoidPortal ? h with { VoidPortal = false } : h).ToList();
        if (!hexes.SequenceEqual(Definition.Map.Hexes))
        {
            Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
        }
    }
    /// <summary>
    /// Places an obstacle, landmark, nexus point or item, replacing whatever was on the hex (a start or another object);
    /// null clears it. Only obstacles carry the sight-blocker flag. Placing a rune on the same rune instead widens its
    /// radius, wrapping from 4 back to 1.
    /// </summary>
    public void SetObject(int q, int r, string? kind)
    {
        if (kind is not null && !MapObjectArt.IsKnown(kind)) throw new ArgumentOutOfRangeException(nameof(kind));
        Edit(q, r, h => kind is null ? WithoutObject(h)
            : h.Object == kind && MapObjectArt.CategoryOf(kind) == "nexus" ? h with { Radius = NextRadius(h.Radius) }
            : h with { Object = kind, SightBlocker = MapObjectArt.BlocksSight(kind), PlayerStart = null, VoidPortal = false, Radius = null });
    }
    private static int? NextRadius(int? radius) => (radius ?? 1) % MapObjectArt.MaxRuneRadius + 1 is var next && next > 1 ? next : null;
    /// <summary>Removes everything on the hex (start position, Void Portal and object), leaving its terrain and height.</summary>
    public void Erase(int q, int r) => Edit(q, r, h => WithoutObject(h) with { PlayerStart = null, VoidPortal = false });
    private static ScenarioHex WithoutObject(ScenarioHex hex) =>
        hex.SightBlocker || hex.Object is not null || hex.Radius is not null ? hex with { Object = null, SightBlocker = false, Radius = null } : hex;
    public void Clear()
    {
        var hexes = Definition.Map.Hexes.Select(h => h with { Biome = "plains", Terrain = "grass", Elevation = 0, PlayerStart = null, VoidPortal = false }).ToList();
        Definition = Definition with { Map = Definition.Map with { Hexes = hexes } }; IsDirty = true;
    }
    public void DetachSavedMap() { SavedSummary = null; SavedOwnerId = null; }
    private static ScenarioDefinition Renamed(ScenarioDefinition definition) =>
        definition.Map.Hexes.All(h => MapObjectArt.Renamed(h.Object) == h.Object) ? definition
            : definition with { Map = definition.Map with { Hexes = definition.Map.Hexes.Select(h => h with { Object = MapObjectArt.Renamed(h.Object) }).ToList() } };
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
