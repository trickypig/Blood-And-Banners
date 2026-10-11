namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

public sealed class GameExperienceTests : BunitContext
{
    [Fact]
    public void Invite_join_creates_a_separate_guest_seat_and_opens_the_lobby()
    {
        var api = new FakeSessionCoordinator
        {
            Joined = LobbyProjection("seat-blue", isHost: false)
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialJoinCode, "game-fixture")
            .Add(component => component.InviteBaseUri, "http://localhost:5016/"));

        cut.WaitForElement("button[data-action=join-game]").Click();

        cut.WaitForAssertion(() =>
        {
            Assert.NotNull(cut.Find("[data-testid=game-code]"));
            Assert.StartsWith("web-", api.JoinRequest?.ClientKind);
            Assert.Equal("game-fixture", api.JoinRequest?.GameId);
            Assert.Contains("Waiting for the host", cut.Markup);
        });
    }

    [Fact]
    public void Lobby_ready_action_uses_the_visible_projection_version_and_updates_the_roster()
    {
        var initial = LobbyProjection("seat-red", isHost: true);
        var ready = initial with
        {
            Version = 4,
            Memberships = [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, false, false),
            ]
        };
        var api = new FakeSessionCoordinator { Reconnected = initial, Readied = ready };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InviteBaseUri, "http://localhost:5016/"));

        cut.WaitForElement("button[data-action=toggle-ready]").Click();

        cut.WaitForAssertion(() =>
        {
            Assert.Equal((3L, true), api.ReadyRequest);
            Assert.Contains("Stand down", cut.Find("button[data-action=toggle-ready]").TextContent);
        });
    }

    private static GameProjection LobbyProjection(string viewerSeat, bool isHost) =>
        new(
            "game-fixture",
            3,
            GameLifecycle.Lobby,
            GameMode.VanillaPvp,
            new ViewerPermissions("player", viewerSeat, true, isHost),
            new BoardProjection(3, 2, []),
            new TurnProjection(0, null, 0),
            null,
            [new LegalAction("setReady", null, [])],
            new Dictionary<string, int>(),
            null)
        {
            ScenarioName = "Training Grounds",
            Memberships = [
                new MembershipProjection("seat-red", 1, false, true),
                new MembershipProjection("seat-blue", 2, false, false),
            ]
        };

    private sealed class FakeSessionCoordinator : IGameSessionCoordinator
    {
        public GameProjection? Reconnected { get; init; }
        public GameProjection? Joined { get; init; }
        public GameProjection? Readied { get; init; }
        public (string ClientKind, string GameId)? JoinRequest { get; private set; }
        public (long Version, bool Ready)? ReadyRequest { get; private set; }

        public Task<GameProjection> CreateGuestGameAsync(string deviceId, GameMode mode, string scenarioName, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GameProjection> JoinGuestGameAsync(string deviceId, string gameId, CancellationToken cancellationToken = default)
        {
            JoinRequest = (deviceId, gameId);
            return Task.FromResult(Joined ?? throw new InvalidOperationException());
        }

        public Task<GameProjection?> ReconnectAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(Reconnected);

        public Task<GameProjection> SetReadyAsync(long expectedVersion, bool ready, CancellationToken cancellationToken = default)
        {
            ReadyRequest = (expectedVersion, ready);
            return Task.FromResult(Readied ?? throw new InvalidOperationException());
        }

        public Task<GameProjection> StartGameAsync(CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }
}
