using Android.App;
using Android.Content;
using Android.Content.PM;
using Android.OS;
using AndroidX.Core.View;

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
		HideNavigationBar();
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

	public override void OnWindowFocusChanged(bool hasFocus)
	{
		base.OnWindowFocusChanged(hasFocus);
		// Dialogs, the keyboard and app switching can bring the bar back; hide it again on return.
		if (hasFocus)
		{
			HideNavigationBar();
		}
	}

	/// <summary>
	/// Game-style immersive mode: the back/home/recents bar stays hidden until the player swipes up
	/// from the bottom edge, then slides away again. The status bar stays, with light icons on the dark page.
	/// </summary>
	private void HideNavigationBar()
	{
		if (Window is not { DecorView: { } decor } window || WindowCompat.GetInsetsController(window, decor) is not { } controller)
		{
			return;
		}
		controller.SystemBarsBehavior = WindowInsetsControllerCompat.BehaviorShowTransientBarsBySwipe;
		controller.Hide(WindowInsetsCompat.Type.NavigationBars());
		controller.AppearanceLightStatusBars = false;
	}

	private static void ReceiveLink(Intent? intent)
	{
		if (intent?.Action == Intent.ActionView && intent.DataString is { } uri)
		{
			IncomingLinks.Receive(uri);
		}
	}
}
