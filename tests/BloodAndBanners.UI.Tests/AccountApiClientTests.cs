using System.Net;
using System.Text;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class AccountApiClientTests
{
    private static readonly AuthSession User = new("u1", "user", "access-1", "refresh-1");

    [Fact]
    public async Task Verify_by_code_posts_email_code_device_and_guest_bearer()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"identityId":"u1","kind":"user","accessToken":"a","refreshToken":"r","displayName":""}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        var session = await client.VerifyEmailCodeAsync("me@example.com", "123456", "web-1", new AuthSession("g1", "guest", "guest-access", "gr"));

        Assert.Equal("user", session.Kind);
        Assert.Equal("", session.DisplayName);
        Assert.Equal("https://api.test/api/v1/auth/email/verify", handler.Uri);
        Assert.Equal("Bearer guest-access", handler.Authorization);
        Assert.Contains("\"code\":\"123456\"", handler.Body);
        Assert.Contains("\"deviceId\":\"web-1\"", handler.Body);
    }

    [Fact]
    public async Task Verify_by_link_without_guest_sends_no_bearer()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"identityId":"u1","kind":"user","accessToken":"a","refreshToken":"r"}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        await client.VerifyEmailLinkAsync("tok", "web-1", guest: null);

        Assert.Null(handler.Authorization);
        Assert.Contains("\"token\":\"tok\"", handler.Body);
    }

    [Fact]
    public async Task Errors_surface_the_server_code()
    {
        var handler = new RecordingHandler(HttpStatusCode.TooManyRequests, """{"error":{"code":"tooManyRequests","message":"Slow down"}}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        var error = await Assert.ThrowsAsync<GameApiException>(() => client.StartEmailSignInAsync("me@example.com"));

        Assert.Equal("tooManyRequests", error.Code);
    }

    [Theory]
    [InlineData("GET", "api/v1/friends")]
    [InlineData("POST", "api/v1/friends/requests/r1/accept")]
    [InlineData("POST", "api/v1/friends/requests/r1/decline")]
    [InlineData("DELETE", "api/v1/friends/u2")]
    public async Task Friend_calls_use_the_documented_routes(string method, string path)
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"friends":[],"incoming":[],"outgoing":[]}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        _ = path switch
        {
            "api/v1/friends" => await client.GetFriendsAsync(User),
            "api/v1/friends/requests/r1/accept" => await client.AcceptFriendAsync(User, "r1"),
            "api/v1/friends/requests/r1/decline" => await client.DeclineFriendAsync(User, "r1"),
            _ => await client.RemoveFriendAsync(User, "u2"),
        };

        Assert.Equal(method, handler.Method);
        Assert.Equal($"https://api.test/{path}", handler.Uri);
        Assert.Equal("Bearer access-1", handler.Authorization);
    }

    [Fact]
    public async Task Seat_friend_request_sends_game_and_seat()
    {
        var handler = new RecordingHandler(HttpStatusCode.OK, """{"friends":[],"incoming":[],"outgoing":[]}""");
        var client = new AccountApiClient(new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") });

        await client.RequestFriendFromSeatAsync(User, "g1", "seat-2");

        Assert.Contains("\"gameId\":\"g1\"", handler.Body);
        Assert.Contains("\"seatId\":\"seat-2\"", handler.Body);
    }

    private sealed class RecordingHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        public string? Method { get; private set; }
        public string? Uri { get; private set; }
        public string? Authorization { get; private set; }
        public string Body { get; private set; } = string.Empty;

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Method = request.Method.Method;
            Uri = request.RequestUri?.ToString();
            Authorization = request.Headers.Authorization?.ToString();
            Body = request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken);
            return new HttpResponseMessage(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") };
        }
    }
}
