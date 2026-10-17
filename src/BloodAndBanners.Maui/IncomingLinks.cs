namespace BloodAndBanners.Maui;

/// <summary>
/// Invite and watch links that opened the app. The platform hands them over before or after the
/// page is ready, so the latest one is kept for a page that has not subscribed yet.
/// </summary>
public static class IncomingLinks
{
	public static string? Latest { get; private set; }

	public static event Action<string>? Received;

	public static void Receive(string uri)
	{
		Latest = uri;
		Received?.Invoke(uri);
	}
}
