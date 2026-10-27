using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IMapApiClient
{
    Task<SavedMaps> ListAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<SavedMap> LoadAsync(AuthSession session, string scenarioId, CancellationToken cancellationToken = default);
    Task<SavedMap> CreateAsync(AuthSession session, ScenarioDefinition definition, CancellationToken cancellationToken = default);
    Task<SavedMap> SaveAsync(AuthSession session, string scenarioId, string expectedRevisionId, ScenarioDefinition definition, CancellationToken cancellationToken = default);
    /// <summary>The game's own scenarios, which anyone may copy into the editor.</summary>
    Task<BuiltInScenarios> ListBuiltInAsync(CancellationToken cancellationToken = default);
    Task<ScenarioDefinition> LoadBuiltInAsync(string name, CancellationToken cancellationToken = default);
    /// <summary>The player's maps ready for the mode, each at its newest ready revision.</summary>
    Task<PlayableMaps> ListPlayableAsync(AuthSession session, GameMode mode, CancellationToken cancellationToken = default);
    Task<BoardProjection> LoadRevisionBoardAsync(AuthSession session, string revisionId, CancellationToken cancellationToken = default);
}
