using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IGameSessionCoordinator
{
    Task<GameProjection> CreateGuestGameAsync(string deviceId, GameMode mode, string scenarioName, CancellationToken cancellationToken = default);
    Task<GameProjection> JoinGuestGameAsync(string deviceId, string gameId, CancellationToken cancellationToken = default);
    Task<GameProjection?> ReconnectAsync(CancellationToken cancellationToken = default);
    Task<GameProjection> SetReadyAsync(long expectedVersion, bool ready, CancellationToken cancellationToken = default);
    Task<GameProjection> StartGameAsync(CancellationToken cancellationToken = default);
}
