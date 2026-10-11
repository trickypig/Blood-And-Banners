using System.Net;

namespace BloodAndBanners.UI.Services;

public sealed class GameApiException(HttpStatusCode statusCode, string code, string message)
    : HttpRequestException(message, null, statusCode)
{
    public string Code { get; } = code;
}
