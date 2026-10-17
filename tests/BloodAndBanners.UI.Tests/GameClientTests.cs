using System.Net;
using System.Text;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class GameClientTests
{
    [Theory]
    [InlineData("http://localhost:5016/?join=game-123", "game-123")]
    [InlineData("https://game.test/?mode=vanilla&join=game%20code", "game code")]
    [InlineData("https://game.test/", null)]
    public void Invite_links_extract_the_join_code(string uri, string? expected)
    {
        Assert.Equal(expected, InviteLinks.GameIdFromUri(uri));
    }

    [Fact]
    public void Invite_links_preserve_the_base_path_and_escape_the_game_id()
    {
        Assert.Equal(
            "https://game.test/play/?join=game%201",
            InviteLinks.Create("https://game.test/play/", "game 1"));
    }

    [Theory]
    [InlineData("http://localhost:5016/?watch=game-123", "game-123")]
    [InlineData("https://game.test/play/?watch=%20game%201%20", "game 1")]
    [InlineData("https://game.test/?join=game-123", null)]
    public void Watch_links_extract_the_watched_game(string uri, string? expected)
    {
        Assert.Equal(expected, InviteLinks.WatchGameIdFromUri(uri));
    }

    [Fact]
    public void Watch_links_preserve_the_base_path_and_escape_the_game_id()
    {
        Assert.Equal(
            "https://game.test/play/?watch=game%201",
            InviteLinks.CreateWatch("https://game.test/play/", "game 1"));
    }

    [Fact]
    public async Task Watching_reads_the_public_watch_routes_without_credentials()
    {
        const string projection = """
            {
              "gameId":"game 1","version":4,"lifecycle":"draft","mode":"vanillaPvp",
              "viewer":{"role":"spectator","seatId":null,"canCommand":false,"isHost":false},
              "board":{"columns":0,"rows":0,"hexes":[]},
              "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
              "pendingChoice":null,"legalActions":[],"scores":{},"result":null
            }
            """;
        var handler = new QueueHandler(projection, """{"events":[],"lastSequence":7}""");
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var game = await client.WatchGameAsync("game 1");
        var page = await client.GetWatchedEventsAsync("game 1", 3);

        Assert.Equal("spectator", game.Viewer.Role);
        Assert.Equal(7, page.LastSequence);
        Assert.Equal("https://game.test/api/v1/watch/game%201", handler.Requests[0].RequestUri?.AbsoluteUri);
        Assert.Equal("https://game.test/api/v1/watch/game%201/events?after=3", handler.Requests[1].RequestUri?.AbsoluteUri);
        Assert.All(handler.Requests, request =>
        {
            Assert.Null(request.Headers.Authorization);
            Assert.False(request.Headers.Contains("X-Seat-Token"));
        });
    }

    [Theory]
    [InlineData(true, false, false, 0, 750)]
    [InlineData(false, true, false, 0, 750)]
    [InlineData(false, false, false, 0, 2000)]
    [InlineData(true, false, true, 0, 5000)]
    [InlineData(false, false, false, 3, 16000)]
    public void Polling_cadence_adapts_to_turn_visibility_and_failures(
        bool activeLocalTurn,
        bool pendingChoice,
        bool backgrounded,
        int consecutiveFailures,
        int expectedMilliseconds)
    {
        var interval = PollingCadence.Select(activeLocalTurn, pendingChoice, backgrounded, consecutiveFailures);

        Assert.Equal(expectedMilliseconds, interval.TotalMilliseconds);
    }

    [Fact]
    public void Spectators_poll_at_the_relaxed_cadence_even_when_no_seat_is_active()
    {
        var viewer = new ViewerPermissions("spectator", null, false, false);

        Assert.False(PollingCadence.IsViewersMoment(viewer, activeSeatId: null, pickingSeatId: null));
        Assert.False(PollingCadence.IsViewersMoment(viewer, activeSeatId: "seat-red", pickingSeatId: null));
        Assert.True(PollingCadence.IsViewersMoment(new ViewerPermissions("player", "seat-red", true, false), "seat-red", null));
        Assert.True(PollingCadence.IsViewersMoment(new ViewerPermissions("player", "seat-red", true, false), null, "seat-red"));
    }

    [Fact]
    public async Task Guest_session_is_used_to_create_and_deserialize_a_game()
    {
        var handler = new QueueHandler(
            """
            {"identityId":"guest-1","kind":"guest","accessToken":"access-1","refreshToken":"refresh-1"}
            """,
            """
            {
              "game": {
                "gameId":"game-1","version":1,"lifecycle":"lobby","mode":"coop",
                "viewer":{"role":"player","seatId":"seat-1","canCommand":true,"isHost":true},
                "board":{"columns":0,"rows":0,"hexes":[]},
                "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
                "pendingChoice":null,"legalActions":[],"scores":{"seat-1":0},"result":null
              },
              "seatToken":"seat-secret"
            }
            """
        );
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var session = await client.CreateGuestAsync("device-1");
        var gameSession = await client.CreateGameAsync(session, GameMode.Coop, "Void Reaches");

        Assert.Equal("guest-1", session.IdentityId);
        Assert.Equal("game-1", gameSession.Game.GameId);
        Assert.Equal(GameMode.Coop, gameSession.Game.Mode);
        Assert.Equal("seat-secret", gameSession.SeatToken);
        Assert.Equal("Bearer", handler.Requests[1].Headers.Authorization?.Scheme);
        Assert.Equal("access-1", handler.Requests[1].Headers.Authorization?.Parameter);
        Assert.Contains("\"scenarioName\":\"Void Reaches\"", handler.Bodies[1]);
    }

    [Fact]
    public async Task Reconnect_and_commands_send_identity_and_seat_credentials()
    {
        const string projection = """
            {
              "gameId":"game-1","version":1,"lifecycle":"lobby","mode":"vanillaPvp",
              "viewer":{"role":"player","seatId":"seat-1","canCommand":true,"isHost":true},
              "board":{"columns":0,"rows":0,"hexes":[]},
              "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
              "pendingChoice":null,"legalActions":[],"scores":{"seat-1":0},"result":null
            }
            """;
        var handler = new QueueHandler(projection, projection);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });
        var identity = new AuthSession("guest-1", "guest", "access-1", "refresh-1");
        var command = new CommandEnvelope(
            "command-1",
            1,
            "setReady",
            System.Text.Json.JsonSerializer.SerializeToElement(new { ready = true }, ContractJson.Options));

        await client.ReconnectAsync(identity, "game-1", "seat-secret");
        await client.SubmitCommandAsync(identity, "game-1", "seat-secret", command);

        Assert.All(handler.Requests, request =>
        {
            Assert.Equal("Bearer", request.Headers.Authorization?.Scheme);
            Assert.Equal("access-1", request.Headers.Authorization?.Parameter);
            Assert.Equal("seat-secret", request.Headers.GetValues("X-Seat-Token").Single());
        });
        Assert.Equal(HttpMethod.Get, handler.Requests[0].Method);
        Assert.EndsWith("api/v1/games/game-1", handler.Requests[0].RequestUri?.ToString());
        Assert.Contains("\"commandId\":\"command-1\"", handler.Bodies[1]);
    }

    [Fact]
    public async Task Join_and_start_use_the_two_player_lobby_routes()
    {
        const string projection = """
            {
              "gameId":"game-1","version":2,"lifecycle":"lobby","mode":"vanillaPvp",
              "scenarioName":"Training Grounds",
              "viewer":{"role":"player","seatId":"seat-2","canCommand":true,"isHost":false},
              "board":{"columns":0,"rows":0,"hexes":[]},
              "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
              "pendingChoice":null,"memberships":[],"legalActions":[],"scores":{},"result":null
            }
            """;
        var handler = new QueueHandler(
            $$"""{"game":{{projection}},"seatToken":"guest-seat-secret"}""",
            projection);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });
        var identity = new AuthSession("guest-2", "guest", "access-2", "refresh-2");

        var joined = await client.JoinGameAsync(identity, "game-1");
        await client.StartGameAsync(identity, "game-1", "guest-seat-secret");

        Assert.Equal("guest-seat-secret", joined.SeatToken);
        Assert.Equal(HttpMethod.Post, handler.Requests[0].Method);
        Assert.EndsWith("api/v1/games/game-1/join", handler.Requests[0].RequestUri?.ToString());
        Assert.Equal("Bearer access-2", handler.Requests[0].Headers.Authorization?.ToString());
        Assert.EndsWith("api/v1/games/game-1/start", handler.Requests[1].RequestUri?.ToString());
        Assert.Equal("guest-seat-secret", handler.Requests[1].Headers.GetValues("X-Seat-Token").Single());
    }

    [Fact]
    public async Task Host_reassignment_sends_the_host_credential_and_reads_the_rotated_credential()
    {
        var handler = new QueueHandler(
            """
            {
              "seatId":"seat-2","seatToken":"rotated-secret",
              "game": {
                "gameId":"game-1","version":3,"lifecycle":"lobby","mode":"vanillaPvp",
                "viewer":{"role":"player","seatId":"seat-1","canCommand":true,"isHost":true},
                "board":{"columns":0,"rows":0,"hexes":[]},
                "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
                "pendingChoice":null,"legalActions":[],"scores":{"seat-1":0,"seat-2":0},"result":null
              }
            }
            """);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });
        var identity = new AuthSession("host-1", "guest", "access-1", "refresh-1");

        var result = await client.ReassignSeatAsync(
            identity,
            "game-1",
            "host-secret",
            "seat-2",
            "replacement-1");

        Assert.Equal("rotated-secret", result.SeatToken);
        Assert.Equal(3, result.Game.Version);
        Assert.Equal("host-secret", handler.Requests[0].Headers.GetValues("X-Seat-Token").Single());
        Assert.EndsWith("api/v1/games/game-1/seats/seat-2/reassign", handler.Requests[0].RequestUri?.ToString());
        Assert.Contains("\"identityId\":\"replacement-1\"", handler.Bodies[0]);
    }

    [Fact]
    public async Task Refresh_rotates_the_identity_tokens_for_the_same_device()
    {
        var handler = new QueueHandler(
            """
            {"identityId":"guest-1","kind":"guest","accessToken":"access-2","refreshToken":"refresh-2"}
            """);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var refreshed = await client.RefreshAsync("refresh-1", "device-1");

        Assert.Equal("access-2", refreshed.AccessToken);
        Assert.Equal("refresh-2", refreshed.RefreshToken);
        Assert.EndsWith("api/v1/auth/refresh", handler.Requests[0].RequestUri?.ToString());
        Assert.Contains("\"refreshToken\":\"refresh-1\"", handler.Bodies[0]);
        Assert.Contains("\"deviceId\":\"device-1\"", handler.Bodies[0]);
    }

    [Fact]
    public async Task Events_are_polled_after_a_sequence_with_the_seat_credential()
    {
        const string page = """
            {"events":[{"sequence":5,"version":9,"kind":"attackResolved","actingSeat":"guest-1","focusHexes":["1,0","2,0"],
              "payload":{"wounds":2},"timestamp":"2026-10-11T12:00:00Z"}],"lastSequence":5}
            """;
        var handler = new QueueHandler(page);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var events = await client.GetEventsAsync(new AuthSession("guest-1", "guest", "access-1", "refresh-1"), "game-1", "seat-secret", 4);

        Assert.EndsWith("api/v1/games/game-1/events?after=4", handler.Requests[0].RequestUri?.ToString());
        Assert.Equal("seat-secret", handler.Requests[0].Headers.GetValues("X-Seat-Token").Single());
        Assert.Equal(new[] { "1,0", "2,0" }, events.Events.Single().FocusHexes);
        Assert.Equal(5, events.LastSequence);
    }

    [Fact]
    public async Task Scenario_boards_are_read_anonymously_by_escaped_name()
    {
        const string board = """
            {"columns":2,"rows":1,"hexes":[
              {"q":0,"r":0,"biome":"plains","terrain":"grass","elevation":0,"sightBlocker":false,"occupantId":null,"playerStart":1},
              {"q":1,"r":0,"biome":"water","terrain":"open-water","elevation":0,"sightBlocker":false,"occupantId":null,"playerStart":null}]}
            """;
        var handler = new QueueHandler(board);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var result = await client.GetScenarioBoardAsync("Four Corners");

        Assert.EndsWith("api/v1/scenarios/Four%20Corners/board", handler.Requests[0].RequestUri?.AbsoluteUri);
        Assert.Null(handler.Requests[0].Headers.Authorization);
        Assert.Equal(new int?[] { 1, null }, result.Hexes.Select(hex => hex.PlayerStart));
    }

    [Fact]
    public async Task Rejected_commands_surface_the_server_error_code_and_message()
    {
        const string error = """{"error":{"code":"heroTaken","message":"Another commander has already claimed that hero.","currentVersion":8}}""";
        var handler = new StatusHandler(HttpStatusCode.UnprocessableEntity, error);
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });
        var command = new CommandEnvelope("c-1", 8, "pickHero", System.Text.Json.JsonSerializer.SerializeToElement(new { hero = "X" }));

        var failure = await Assert.ThrowsAsync<GameApiException>(() =>
            client.SubmitCommandAsync(new AuthSession("guest-1", "guest", "access-1", "refresh-1"), "game-1", "seat-secret", command));

        Assert.Equal(HttpStatusCode.UnprocessableEntity, failure.StatusCode);
        Assert.Equal("heroTaken", failure.Code);
        Assert.Equal("Another commander has already claimed that hero.", failure.Message);
    }

    private sealed class StatusHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") });
    }

    private sealed class QueueHandler(params string[] responses) : HttpMessageHandler
    {
        private readonly Queue<string> _responses = new(responses);
        public List<HttpRequestMessage> Requests { get; } = [];
        public List<string> Bodies { get; } = [];

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests.Add(request);
            Bodies.Add(request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken));
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(_responses.Dequeue(), Encoding.UTF8, "application/json")
            };
        }
    }
}
