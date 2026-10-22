using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IMapApiClient
{
    Task<SavedMaps> ListAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<SavedMap> LoadAsync(AuthSession session, string scenarioId, CancellationToken cancellationToken = default);
    Task<SavedMap> CreateAsync(AuthSession session, ScenarioDefinition definition, CancellationToken cancellationToken = default);
    Task<SavedMap> SaveAsync(AuthSession session, string scenarioId, string expectedRevisionId, ScenarioDefinition definition, CancellationToken cancellationToken = default);
}
