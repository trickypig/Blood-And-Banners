namespace BloodAndBanners.UI.Services;

public static class InviteLinks
{
    public static string Create(string baseUri, string gameId) =>
        $"{baseUri.TrimEnd('/')}/?join={Uri.EscapeDataString(gameId)}";

    public static string CreateWatch(string baseUri, string gameId) =>
        $"{baseUri.TrimEnd('/')}/?watch={Uri.EscapeDataString(gameId)}";

    public static string? GameIdFromUri(string uri) => QueryValue(uri, "join");

    public static string? WatchGameIdFromUri(string uri) => QueryValue(uri, "watch");

    private static string? QueryValue(string uri, string key)
    {
        var query = new Uri(uri, UriKind.Absolute).Query.TrimStart('?');
        foreach (var pair in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            if (Uri.UnescapeDataString(parts[0]).Equals(key, StringComparison.OrdinalIgnoreCase))
            {
                var value = parts.Length == 2
                    ? Uri.UnescapeDataString(parts[1].Replace('+', ' ')).Trim()
                    : string.Empty;
                return value.Length == 0 ? null : value;
            }
        }

        return null;
    }
}
