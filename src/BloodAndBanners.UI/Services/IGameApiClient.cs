using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IGameApiClient
{
    Task<AuthSession> CreateGuestAsync(string deviceId, CancellationToken cancellationToken = default);

    Task<AuthSession> RefreshAsync(
        string refreshToken,
        string deviceId,
        CancellationToken cancellationToken = default);

    Task<GameSeatSession> CreateGameAsync(
        AuthSession session,
        GameMode mode,
        string scenarioName,
        CancellationToken cancellationToken = default);

    Task<GameSeatSession> JoinGameAsync(
        AuthSession session,
        string gameId,
        CancellationToken cancellationToken = default);

    Task<GameProjection> ReconnectAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CancellationToken cancellationToken = default);

    Task<GameProjection> SubmitCommandAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CommandEnvelope command,
        CancellationToken cancellationToken = default);

    Task<GameProjection> StartGameAsync(
        AuthSession session,
        string gameId,
        string seatToken,
        CancellationToken cancellationToken = default);

    Task<SeatReassignmentResult> ReassignSeatAsync(
        AuthSession session,
        string gameId,
        string hostSeatToken,
        string seatId,
        string replacementIdentityId,
        CancellationToken cancellationToken = default);
}
