using System.Net;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class GameSessionCoordinator(
    IGameApiClient api,
    IGameSessionStore store) : IGameSessionCoordinator
{
    public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) =>
        api.GetScenarioBoardAsync(scenarioName, cancellationToken);

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

    public async Task<GameProjection> JoinGuestGameAsync(
        string deviceId,
        string gameId,
        CancellationToken cancellationToken = default)
    {
        var normalizedGameId = gameId.Trim();
        if (normalizedGameId.Length == 0)
        {
            throw new ArgumentException("A game code is required.", nameof(gameId));
        }

        var identity = await api.CreateGuestAsync(deviceId, cancellationToken);
        var gameSession = await api.JoinGameAsync(identity, normalizedGameId, cancellationToken);
        await store.SaveAsync(new StoredGameSession(
            deviceId,
            identity,
            gameSession.Game.GameId,
            gameSession.SeatToken));
        return gameSession.Game;
    }

    public Task<GameProjection> SetReadyAsync(
        long expectedVersion,
        bool ready,
        CancellationToken cancellationToken = default) =>
        SubmitCommandAsync(expectedVersion, "setReady", new { ready }, cancellationToken);

    public async Task<GameProjection> SubmitCommandAsync(
        long expectedVersion,
        string kind,
        object payload,
        CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        var command = new CommandEnvelope(
            Guid.NewGuid().ToString("N"),
            expectedVersion,
            kind,
            System.Text.Json.JsonSerializer.SerializeToElement(payload, payload.GetType(), ContractJson.Options));
        return await api.SubmitCommandAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            command,
            cancellationToken);
    }

    public async Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        return await api.GetEventsAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            afterSequence,
            cancellationToken);
    }

    public async Task LeaveAsync() => await store.ClearAsync();

    public async Task<GameProjection> StartGameAsync(CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        return await api.StartGameAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            cancellationToken);
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

    private async ValueTask<StoredGameSession> RequiredSessionAsync() =>
        await store.LoadAsync()
        ?? throw new InvalidOperationException("No game session is saved on this device.");
}
