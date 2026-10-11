using System.Net;
using System.Text;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class GameClientTests
{
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
        var gameSession = await client.CreateGameAsync(session, GameMode.Coop, "Portal Watch");

        Assert.Equal("guest-1", session.IdentityId);
        Assert.Equal("game-1", gameSession.Game.GameId);
        Assert.Equal(GameMode.Coop, gameSession.Game.Mode);
        Assert.Equal("seat-secret", gameSession.SeatToken);
        Assert.Equal("Bearer", handler.Requests[1].Headers.Authorization?.Scheme);
        Assert.Equal("access-1", handler.Requests[1].Headers.Authorization?.Parameter);
        Assert.Contains("\"scenarioName\":\"Portal Watch\"", handler.Bodies[1]);
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
