using System.Net;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed class GameSessionCoordinator(
    IGameApiClient api,
    IGameSessionStore store,
    IAccountSession? account = null) : IGameSessionCoordinator
{
    /// <summary>The game being watched; while set, reads use the public watch routes and the saved seat is left alone.</summary>
    private string? _watchedGameId;

    public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) =>
        api.GetScenarioBoardAsync(scenarioName, cancellationToken);

    public Task<GameProjection> CreateGuestGameAsync(
        string deviceId,
        GameMode mode,
        string scenarioName,
        CancellationToken cancellationToken = default) =>
        OpenSeatAsync(deviceId, identity => api.CreateGameAsync(identity, mode, scenarioName, cancellationToken), cancellationToken);

    public Task<GameProjection> JoinGuestGameAsync(
        string deviceId,
        string gameId,
        CancellationToken cancellationToken = default)
    {
        var normalizedGameId = gameId.Trim();
        if (normalizedGameId.Length == 0)
        {
            throw new ArgumentException("A game code is required.", nameof(gameId));
        }
        return OpenSeatAsync(deviceId, identity => api.JoinGameAsync(identity, normalizedGameId, cancellationToken), cancellationToken);
    }

    public async Task<GameProjection> ResumeAccountGameAsync(string gameId, CancellationToken cancellationToken = default)
    {
        var signedIn = account is null ? null : await account.LoadAsync();
        if (signedIn is null)
        {
            throw new InvalidOperationException("Sign in to resume a game.");
        }
        return await OpenSeatAsync(signedIn.DeviceId, identity => account!.Api.ResumeGameAsync(identity, gameId.Trim(), cancellationToken), cancellationToken);
    }

    /// <summary>Opens a seat as the signed-in account when there is one, otherwise as a fresh guest, and remembers it.</summary>
    private async Task<GameProjection> OpenSeatAsync(
        string deviceId,
        Func<AuthSession, Task<GameSeatSession>> open,
        CancellationToken cancellationToken)
    {
        _watchedGameId = null;
        var signedIn = account is null ? null : await account.LoadAsync();
        AuthSession identity;
        GameSeatSession seat;
        if (signedIn is null)
        {
            identity = await api.CreateGuestAsync(deviceId, cancellationToken);
            seat = await open(identity);
        }
        else
        {
            AuthSession? used = null;
            seat = await account!.CallAsync(async current =>
            {
                used = current;
                return await open(current);
            }, cancellationToken);
            identity = used!;
            deviceId = signedIn.DeviceId;
        }
        await store.SaveAsync(new StoredGameSession(deviceId, identity, seat.Game.GameId, seat.SeatToken));
        return seat.Game;
    }

    /// <summary>A seat held by the signed-in account always uses the account's newest tokens.</summary>
    private async ValueTask<StoredGameSession?> LoadSessionAsync()
    {
        var session = await store.LoadAsync();
        if (session is null || account is null || session.Identity.Kind != "user")
        {
            return session;
        }
        var signedIn = await account.LoadAsync();
        return signedIn is not null && signedIn.Identity.IdentityId == session.Identity.IdentityId
            ? session with { Identity = signedIn.Identity }
            : session;
    }

    private async Task<AuthSession> RefreshSessionAsync(StoredGameSession session, CancellationToken cancellationToken)
    {
        if (account?.Current is { } signedIn && signedIn.Identity.IdentityId == session.Identity.IdentityId)
        {
            // The account refresh also rewrites the stored seat.
            return await account.RefreshAsync(session.Identity.AccessToken, cancellationToken);
        }
        var refreshed = await api.RefreshAsync(session.Identity.RefreshToken, session.DeviceId, cancellationToken);
        await store.SaveAsync(session with { Identity = refreshed });
        return refreshed;
    }

    public async Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default)
    {
        var normalizedGameId = gameId.Trim();
        if (normalizedGameId.Length == 0)
        {
            throw new ArgumentException("A game code is required.", nameof(gameId));
        }

        var projection = await api.WatchGameAsync(normalizedGameId, cancellationToken);
        _watchedGameId = normalizedGameId;
        return projection;
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
        if (_watchedGameId is { } watched)
        {
            return await api.GetWatchedEventsAsync(watched, afterSequence, cancellationToken);
        }
        var session = await RequiredSessionAsync();
        return await api.GetEventsAsync(
            session.Identity,
            session.GameId,
            session.SeatToken,
            afterSequence,
            cancellationToken);
    }

    public async Task LeaveAsync()
    {
        if (_watchedGameId is not null)
        {
            _watchedGameId = null;
            return;
        }
        await store.ClearAsync();
    }

    public async Task<bool> LeaveSeatAsync(CancellationToken cancellationToken = default)
    {
        var session = await LoadSessionAsync();
        if (session is null)
        {
            return true;
        }
        try
        {
            try
            {
                await api.LeaveGameAsync(session.Identity, session.GameId, session.SeatToken, cancellationToken);
            }
            catch (HttpRequestException error) when (error.StatusCode is HttpStatusCode.Unauthorized)
            {
                var refreshed = await RefreshSessionAsync(session, cancellationToken);
                await api.LeaveGameAsync(refreshed, session.GameId, session.SeatToken, cancellationToken);
            }
            return true;
        }
        catch (GameApiException error) when ((int)error.StatusCode is >= 403 and < 500)
        {
            // The server answered: the seat is already gone or can no longer be given up.
            return true;
        }
        catch (HttpRequestException)
        {
            return false;
        }
        finally
        {
            await store.ClearAsync();
        }
    }

    public async Task<GameProjection> RemoveSeatAsync(string seatId, CancellationToken cancellationToken = default)
    {
        var session = await RequiredSessionAsync();
        return await api.RemoveSeatAsync(session.Identity, session.GameId, session.SeatToken, seatId, cancellationToken);
    }

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
        if (_watchedGameId is { } watched)
        {
            return await api.WatchGameAsync(watched, cancellationToken);
        }

        var session = await LoadSessionAsync();
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
            var refreshedIdentity = await RefreshSessionAsync(session, cancellationToken);
            return await api.ReconnectAsync(
                refreshedIdentity,
                session.GameId,
                session.SeatToken,
                cancellationToken);
        }
    }

    private async ValueTask<StoredGameSession> RequiredSessionAsync()
    {
        if (_watchedGameId is not null)
        {
            throw new InvalidOperationException("Spectators cannot give orders.");
        }
        return await LoadSessionAsync()
            ?? throw new InvalidOperationException("No game session is saved on this device.");
    }
}
