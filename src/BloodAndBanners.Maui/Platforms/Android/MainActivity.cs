using Android.App;
using Android.Content;
using Android.Content.PM;
using Android.OS;

namespace BloodAndBanners.Maui;

// SingleTask keeps one game window: a link tapped elsewhere reaches this one through OnNewIntent.
[Activity(Theme = "@style/Maui.SplashTheme", MainLauncher = true, LaunchMode = LaunchMode.SingleTask, ConfigurationChanges = ConfigChanges.ScreenSize | ConfigChanges.Orientation | ConfigChanges.UiMode | ConfigChanges.ScreenLayout | ConfigChanges.SmallestScreenSize | ConfigChanges.Density)]
// Verified against https://blood.trickypig.com/.well-known/assetlinks.json, so invite links open here instead of the browser.
[IntentFilter(
	[Intent.ActionView],
	Categories = [Intent.CategoryDefault, Intent.CategoryBrowsable],
	DataScheme = "https",
	DataHost = "blood.trickypig.com",
	DataPathPrefix = "/play",
	AutoVerify = true)]
public class MainActivity : MauiAppCompatActivity
{
	protected override void OnCreate(Bundle? savedInstanceState)
	{
		base.OnCreate(savedInstanceState);
		// A restored activity replays its original intent; only a fresh launch carries a new link.
		if (savedInstanceState is null)
		{
			ReceiveLink(Intent);
		}
	}

	protected override void OnNewIntent(Intent? intent)
	{
		base.OnNewIntent(intent);
		ReceiveLink(intent);
	}

	private static void ReceiveLink(Intent? intent)
	{
		if (intent?.Action == Intent.ActionView && intent.DataString is { } uri)
		{
			IncomingLinks.Receive(uri);
		}
	}
}
