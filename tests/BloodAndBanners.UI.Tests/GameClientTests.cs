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
              "gameId":"game-1","version":1,"lifecycle":"lobby","mode":"coop",
              "viewer":{"role":"player","seatId":"seat-1","canCommand":true,"isHost":true},
              "board":{"columns":0,"rows":0,"hexes":[]},
              "turn":{"round":0,"activeSeatId":null,"activationsRemaining":0},
              "pendingChoice":null,"legalActions":[],"scores":{"seat-1":0},"result":null
            }
            """
        );
        var client = new GameApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://game.test/") });

        var session = await client.CreateGuestAsync("device-1");
        var game = await client.CreateGameAsync(session, GameMode.Coop, "Portal Watch");

        Assert.Equal("guest-1", session.IdentityId);
        Assert.Equal("game-1", game.GameId);
        Assert.Equal(GameMode.Coop, game.Mode);
        Assert.Equal("Bearer", handler.Requests[1].Headers.Authorization?.Scheme);
        Assert.Equal("access-1", handler.Requests[1].Headers.Authorization?.Parameter);
        Assert.Contains("\"scenarioName\":\"Portal Watch\"", handler.Bodies[1]);
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
