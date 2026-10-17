namespace BloodAndBanners.UI.Tests;

using System.Net;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

public sealed class GameExperienceTests : BunitContext
{
    [Fact]
    public void Vanilla_mode_opens_the_battlefield_picker_without_creating_a_game()
    {
        var api = new FakeSessionCoordinator { Created = LobbyProjection("seat-red", isHost: true) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));

        cut.Find("button[data-mode=vanillaPvp]").Click();

        Assert.Null(api.CreateRequest);
        Assert.NotNull(cut.Find("[data-testid=battlefield-picker]"));
        Assert.Equal("true", cut.Find("button[data-scenario='Four Corners']").GetAttribute("aria-checked"));
        Assert.Equal("false", cut.Find("button[data-scenario='Proving Field']").GetAttribute("aria-checked"));
    }

    [Theory]
    [InlineData("Four Corners")]
    [InlineData("Proving Field")]
    [InlineData("Home Map")]
    public void Vanilla_battlefield_cards_submit_the_selected_scenario(string scenario)
    {
        var api = new FakeSessionCoordinator { Created = LobbyProjection("seat-red", isHost: true) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));

        cut.Find("button[data-mode=vanillaPvp]").Click();
        cut.Find($"button[data-scenario='{scenario}']").Click();
        cut.Find("button[data-action=create-game]").Click();

        cut.WaitForAssertion(() =>
        {
            Assert.NotNull(api.CreateRequest);
            Assert.StartsWith("web-", api.CreateRequest?.DeviceId);
            Assert.Equal(GameMode.VanillaPvp, api.CreateRequest?.Mode);
            Assert.Equal(scenario, api.CreateRequest?.ScenarioName);
        });
    }

    [Fact]
    public void Battlefield_back_returns_to_modes_without_creating_a_game()
    {
        var api = new FakeSessionCoordinator { Created = LobbyProjection("seat-red", isHost: true) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.Find("button[data-mode=vanillaPvp]").Click();
        cut.Find("button[data-action=back-modes]").Click();

        Assert.Null(api.CreateRequest);
        Assert.Empty(cut.FindAll("[data-testid=battlefield-picker]"));
        Assert.NotNull(cut.Find("button[data-mode=vanillaPvp]"));
    }

    [Theory]
    [InlineData(GameMode.Coop, "coop", "Void Reaches")]
    [InlineData(GameMode.BattleRoyale, "battleRoyale", "Last Banner")]
    public void Non_vanilla_modes_create_directly_with_their_existing_scenarios(GameMode mode, string selector, string scenario)
    {
        var api = new FakeSessionCoordinator { Created = LobbyProjection("seat-red", isHost: true) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;
        var cut = Render<GameExperience>();

        cut.Find($"button[data-mode={selector}]").Click();

        cut.WaitForAssertion(() => Assert.Equal((mode, scenario), (api.CreateRequest?.Mode, api.CreateRequest?.ScenarioName)));
        Assert.Empty(cut.FindAll("[data-testid=battlefield-picker]"));
    }

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

    [Fact]
    public void Draft_projection_opens_the_draft_table_and_sends_picks_at_the_visible_version()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator { Reconnected = draft, Commanded = draft with { Version = 10 } };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));

        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() =>
        {
            Assert.Equal(9, api.CommandRequest?.Version);
            Assert.Equal("pickHero", api.CommandRequest?.Kind);
        });
    }

    [Fact]
    public void A_version_conflict_tells_the_commander_to_try_again()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator
        {
            Reconnected = draft,
            CommandFailure = new GameApiException(System.Net.HttpStatusCode.Conflict, "versionConflict", "The game changed before this command arrived.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() => Assert.Contains("The battlefield changed — try again.", cut.Markup));
    }

    [Fact]
    public void A_rejected_order_shows_the_server_reason()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator
        {
            Reconnected = draft,
            CommandFailure = new GameApiException(System.Net.HttpStatusCode.UnprocessableEntity, "heroTaken", "Another commander has already claimed that hero.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() => Assert.Contains("Another commander has already claimed that hero.", cut.Markup));
    }

    [Fact]
    public async Task A_poll_that_was_in_flight_cannot_resurrect_a_game_the_player_left()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator { Reconnected = draft, PollGate = new TaskCompletionSource() };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        Assert.True(SpinWait.SpinUntil(() => api.PollBlocked.Task.IsCompleted, TimeSpan.FromSeconds(5)));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();
        cut.WaitForAssertion(() => Assert.DoesNotContain("Muster your host", cut.Markup));

        api.PollGate.SetResult();
        await Task.Delay(300);

        Assert.True(api.Left);
        Assert.DoesNotContain("Muster your host", cut.Markup);
    }

    [Fact]
    public void Cancelling_from_the_war_room_returns_to_the_starting_selection()
    {
        var api = new FakeSessionCoordinator { Reconnected = LobbyProjection("seat-red", isHost: true) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-action=leaveMatch]").Click();
        cut.Find("button[data-action=confirmLeave]").Click();

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-mode=vanillaPvp]")));
        Assert.True(api.Left);
        Assert.Empty(cut.FindAll("[data-testid=game-code]"));
    }

    [Fact]
    public void A_failed_event_fetch_does_not_hide_a_successful_order()
    {
        var draft = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var api = new FakeSessionCoordinator
        {
            Reconnected = draft,
            Commanded = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") with { Version = 10 },
            EventsFailure = new HttpRequestException("events down")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("button[data-hero='Thorgrim Shattershield']").Click();

        cut.WaitForAssertion(() => Assert.Contains("Waiting for the opposing commander", cut.Markup));
        Assert.DoesNotContain("could not be delivered", cut.Markup);
    }

    private static GameProjection Spectating(GameProjection projection) =>
        projection with { Viewer = new ViewerPermissions("spectator", null, false, false), LegalActions = [] };

    [Fact]
    public void A_watch_link_opens_the_match_read_only()
    {
        var api = new FakeSessionCoordinator
        {
            Watched = Spectating(DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialWatchCode, "game-fixture"));

        cut.WaitForAssertion(() => Assert.Contains("Muster your host", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
        cut.Find("[data-menu=toggle]").Click();
        Assert.Equal("Stop watching", cut.Find("[data-menu=leave]").TextContent.Trim());
    }

    [Fact]
    public void The_start_screen_can_watch_a_game_by_its_code()
    {
        var api = new FakeSessionCoordinator { Watched = Spectating(LobbyProjection("seat-red", isHost: true)) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters.Add(component => component.ClientKind, "web"));
        cut.WaitForElement("[data-join-code]").Input(" game-fixture ");
        cut.Find("button[data-action=watch-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("Waiting for the commanders to muster", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
        Assert.Empty(cut.FindAll("[data-testid=game-code]"));
    }

    [Theory]
    [InlineData("gameFull")]
    [InlineData("gameAlreadyStarted")]
    public void A_join_refused_because_the_match_is_full_or_started_offers_to_watch(string code)
    {
        var api = new FakeSessionCoordinator
        {
            JoinFailure = new GameApiException(HttpStatusCode.UnprocessableEntity, code, "No room."),
            Watched = Spectating(DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialJoinCode, "game-fixture"));
        cut.WaitForElement("button[data-action=join-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("That match is full or already under way.", cut.Markup));
        cut.Find("button[data-action=watch-instead]").Click();

        cut.WaitForAssertion(() => Assert.Contains("Muster your host", cut.Markup));
        Assert.Equal("game-fixture", api.WatchRequest);
    }

    [Fact]
    public void Other_join_failures_do_not_offer_to_watch()
    {
        var api = new FakeSessionCoordinator
        {
            JoinFailure = new GameApiException(HttpStatusCode.NotFound, "gameNotFound", "Missing.")
        };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialJoinCode, "game-fixture"));
        cut.WaitForElement("button[data-action=join-game]").Click();

        cut.WaitForAssertion(() => Assert.Contains("could not be joined", cut.Markup));
        Assert.Empty(cut.FindAll("button[data-action=watch-instead]"));
    }

    [Fact]
    public void Stopping_watching_returns_to_the_start_and_tells_the_host_page()
    {
        var stopped = false;
        var api = new FakeSessionCoordinator { Watched = Spectating(LobbyProjection("seat-red", isHost: true)) };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.InitialWatchCode, "game-fixture")
            .Add(component => component.OnStoppedWatching, () => stopped = true));
        cut.WaitForElement("[data-testid=spectator-seats]");
        cut.Find("[data-menu=leave]").Click();

        cut.WaitForAssertion(() => Assert.NotNull(cut.Find("button[data-mode=vanillaPvp]")));
        Assert.True(api.Left);
        Assert.True(stopped);
    }

    [Fact]
    public void A_player_leaving_their_own_match_does_not_report_stopped_watching()
    {
        var stopped = false;
        var api = new FakeSessionCoordinator { Reconnected = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") };
        Services.AddSingleton<IGameSessionCoordinator>(api);
        JSInterop.Mode = JSRuntimeMode.Loose;

        var cut = Render<GameExperience>(parameters => parameters
            .Add(component => component.ClientKind, "web")
            .Add(component => component.OnStoppedWatching, () => stopped = true));
        cut.WaitForElement("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        cut.Find("[data-menu=confirmLeave]").Click();

        cut.WaitForAssertion(() => Assert.True(api.Left));
        Assert.False(stopped);
    }

    private sealed class FakeSessionCoordinator : IGameSessionCoordinator
    {
        public GameProjection? Reconnected { get; init; }
        public GameProjection? Created { get; init; }
        public GameProjection? Joined { get; init; }
        public GameProjection? Readied { get; init; }
        public (string ClientKind, string GameId)? JoinRequest { get; private set; }
        public (string DeviceId, GameMode Mode, string ScenarioName)? CreateRequest { get; private set; }
        public (long Version, bool Ready)? ReadyRequest { get; private set; }

        public GameProjection? Commanded { get; init; }
        public Exception? CommandFailure { get; init; }
        public (long Version, string Kind, object Payload)? CommandRequest { get; private set; }
        public EventPage Events { get; set; } = new([], 0);
        public bool Left { get; private set; }
        public Exception? EventsFailure { get; init; }
        public Exception? JoinFailure { get; init; }
        public TaskCompletionSource? PollGate { get; init; }
        public TaskCompletionSource PollBlocked { get; } = new();
        private int _reconnects;

        public Task<GameProjection> SubmitCommandAsync(long expectedVersion, string kind, object payload, CancellationToken cancellationToken = default)
        {
            CommandRequest = (expectedVersion, kind, payload);
            if (CommandFailure is not null)
            {
                return Task.FromException<GameProjection>(CommandFailure);
            }
            return Task.FromResult(Commanded ?? throw new InvalidOperationException());
        }

        public Task<EventPage> GetEventsAsync(long afterSequence, CancellationToken cancellationToken = default) =>
            EventsFailure is not null ? Task.FromException<EventPage>(EventsFailure) : Task.FromResult(Events);

        public Task LeaveAsync()
        {
            Left = true;
            return Task.CompletedTask;
        }

        public Task<GameProjection> CreateGuestGameAsync(string deviceId, GameMode mode, string scenarioName, CancellationToken cancellationToken = default)
        {
            CreateRequest = (deviceId, mode, scenarioName);
            return Task.FromResult(Created ?? throw new InvalidOperationException());
        }

        public Task<GameProjection> JoinGuestGameAsync(string deviceId, string gameId, CancellationToken cancellationToken = default)
        {
            JoinRequest = (deviceId, gameId);
            if (JoinFailure is not null)
            {
                return Task.FromException<GameProjection>(JoinFailure);
            }
            return Task.FromResult(Joined ?? throw new InvalidOperationException());
        }

        public async Task<GameProjection?> ReconnectAsync(CancellationToken cancellationToken = default)
        {
            if (PollGate is not null && Interlocked.Increment(ref _reconnects) > 1)
            {
                PollBlocked.TrySetResult();
                await PollGate.Task;
            }
            return WatchRequest is not null && !Left ? Watched : Reconnected;
        }

        public GameProjection? Watched { get; init; }
        public string? WatchRequest { get; private set; }

        public Task<GameProjection> WatchAsync(string gameId, CancellationToken cancellationToken = default)
        {
            WatchRequest = gameId;
            return Task.FromResult(Watched ?? throw new InvalidOperationException());
        }

        public Task<GameProjection> SetReadyAsync(long expectedVersion, bool ready, CancellationToken cancellationToken = default)
        {
            ReadyRequest = (expectedVersion, ready);
            return Task.FromResult(Readied ?? throw new InvalidOperationException());
        }

        public Task<GameProjection> StartGameAsync(CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<BoardProjection> GetScenarioBoardAsync(string scenarioName, CancellationToken cancellationToken = default) =>
            Task.FromResult(new BoardProjection(0, 0, []));
    }
}
