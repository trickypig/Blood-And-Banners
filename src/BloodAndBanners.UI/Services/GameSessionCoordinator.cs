using System.Net;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class GameSessionCoordinator(
    IGameApiClient api,
    IGameSessionStore store)
{
    public async Task<GameProjection> CreateGuestGameAsync(
        string deviceId,
        GameMode mode,
        string scenarioName,
        CancellationToken cancellationToken = default)
    {
        var identity = await api.CreateGuestAsync(deviceId, cancellationToken);
        var gameSession = await api.CreateGameAsync(identity, mode, scenarioName, cancellationToken);
        await store.SaveAsync(new StoredGameSession(
            deviceId,
            identity,
            gameSession.Game.GameId,
            gameSession.SeatToken));
        return gameSession.Game;
    }

    public async Task<GameProjection?> ReconnectAsync(CancellationToken cancellationToken = default)
    {
        var session = await store.LoadAsync();
        if (session is null)
        {
            return null;
        }

        try
        {
            return await api.ReconnectAsync(
                session.Identity,
                session.GameId,
                session.SeatToken,
                cancellationToken);
        }
        catch (HttpRequestException error) when (error.StatusCode is HttpStatusCode.Unauthorized)
        {
            var refreshedIdentity = await api.RefreshAsync(
                session.Identity.RefreshToken,
                session.DeviceId,
                cancellationToken);
            var refreshedSession = session with { Identity = refreshedIdentity };
            await store.SaveAsync(refreshedSession);
            return await api.ReconnectAsync(
                refreshedIdentity,
                refreshedSession.GameId,
                refreshedSession.SeatToken,
                cancellationToken);
        }
    }
}
