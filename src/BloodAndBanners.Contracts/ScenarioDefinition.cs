using System.Text.Json;
using System.Text.Json.Serialization;

namespace BloodAndBanners.Contracts;

public sealed record ScenarioDefinition(
    int SchemaVersion,
    string Name,
    IReadOnlyList<GameMode> ModeCompatibility,
    ScenarioMap Map,
    IReadOnlyList<JsonElement> Placements,
    IReadOnlyList<JsonElement> Objectives,
    ScenarioScoring Scoring)
{
    [JsonExtensionData] public Dictionary<string, JsonElement>? ExtensionData { get; init; }
}

public sealed record ScenarioMap(
    int Columns,
    int Rows,
    IReadOnlyList<ScenarioHex> Hexes)
{
    [JsonExtensionData] public Dictionary<string, JsonElement>? ExtensionData { get; init; }
}

public sealed record ScenarioHex(
    int Q,
    int R,
    string Biome,
    string Terrain,
    int Elevation,
    int? PlayerStart,
    bool Portal,
    string? SpawnZone,
    string? Piece,
    string? Object,
    bool Fountain,
    bool SightBlocker)
{
    [JsonExtensionData] public Dictionary<string, JsonElement>? ExtensionData { get; init; }
    /// <summary>A rune's reach, 1–4: 1 is its own hex, each step adds a ring. Null means 1; other objects have none.</summary>
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)] public int? Radius { get; init; }
    /// <summary>Where the Void Lord's gate stands in Co-op; at most one hex per map.</summary>
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingDefault)] public bool VoidPortal { get; init; }
}

public sealed record ScenarioScoring(
    int Target,
    int EnemyHero,
    int Boss,
    int TerrainObject,
    int CompleteObjectSetBonus)
{
    [JsonExtensionData] public Dictionary<string, JsonElement>? ExtensionData { get; init; }
}
