using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IGameSessionCoordinator
{
    Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default);
    Task<GameProjection> CreateGuestGameAsync(string deviceId, GameMode mode, string scenarioName, string? scenarioRevisionId = null, CancellationToken cancellationToken = default);

    /// <summary>Takes up the signed-in account's seat in a game on this device.</summary>
    Task<GameProjection> ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default);
    Task<GameProjection> JoinGuestGameAsync(string deviceId, string gameId, CancellationToken cancellationToken = default);
    Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default);
    Task<GameProjection?> ReconnectAsync(CancellationToken cancellationToken = default);
    Task<GameProjection> SetReadyAsync(long expectedVersion, bool ready, CancellationToken cancellationToken = default);
    Task<GameProjection> StartGameAsync(CancellationToken cancellationToken = default);
    Task<GameProjection> SubmitCommandAsync(long expectedVersion, string kind, object payload, CancellationToken cancellationToken = default);
    Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default);
    Task LeaveAsync();
    /// <summary>Gives up the saved lobby seat on the server and forgets it; false when the server could not be told.</summary>
    Task<bool> LeaveSeatAsync(CancellationToken cancellationToken = default);
    Task<GameProjection> RemoveSeatAsync(string seatId, CancellationToken cancellationToken = default);
}
