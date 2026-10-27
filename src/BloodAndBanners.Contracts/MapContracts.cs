namespace BloodAndBanners.Contracts;

public sealed record SavedMapSummary(string ScenarioId, string Name, string RevisionId, int Revision, string UpdatedAt);
public sealed record SavedMap(SavedMapSummary Summary, ScenarioDefinition Definition);
public sealed record SavedMaps(IReadOnlyList<SavedMapSummary> Maps);
public sealed record BuiltInScenarioSummary(string Name, IReadOnlyList<GameMode> ModeCompatibility);
public sealed record BuiltInScenarios(IReadOnlyList<BuiltInScenarioSummary> Scenarios);
/// <summary>One of the player's maps at its newest revision the server found ready for a game mode.</summary>
public sealed record PlayableMap(string ScenarioId, string Name, string RevisionId, int Revision, bool IsLatest, int Players, string UpdatedAt);
public sealed record PlayableMaps(IReadOnlyList<PlayableMap> Maps);
