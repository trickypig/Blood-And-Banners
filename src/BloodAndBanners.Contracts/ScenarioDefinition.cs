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
