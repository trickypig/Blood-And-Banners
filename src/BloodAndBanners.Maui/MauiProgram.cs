using Microsoft.Extensions.Logging;

namespace BloodAndBanners.Maui;

public static class MauiProgram
{
	public static MauiApp CreateMauiApp()
	{
		var builder = MauiApp.CreateBuilder();
		builder
			.UseMauiApp<App>()
			.ConfigureFonts(fonts =>
			{
				fonts.AddFont("OpenSans-Regular.ttf", "OpenSansRegular");
			});

		builder.Services.AddMauiBlazorWebView();
		builder.Services.AddSingleton(new HttpClient
		{
			BaseAddress = new Uri("https://blood.trickypig.com/play/")
		});
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IGameApiClient, BloodAndBanners.UI.Services.GameApiClient>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IAccountApiClient, BloodAndBanners.UI.Services.AccountApiClient>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IMapApiClient, BloodAndBanners.UI.Services.MapApiClient>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IAccountStore, BloodAndBanners.UI.Services.JsonAccountStore>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IAccountSession, BloodAndBanners.UI.Services.AccountSession>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IKeyValueStore>(_ =>
			new BloodAndBanners.UI.Services.DelegatingKeyValueStore(
				key => new ValueTask<string?>(SecureStorage.Default.GetAsync(key)),
				(key, value) => new ValueTask(SecureStorage.Default.SetAsync(key, value)),
				key =>
				{
					SecureStorage.Default.Remove(key);
					return ValueTask.CompletedTask;
				}));
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IGameSessionStore, BloodAndBanners.UI.Services.JsonGameSessionStore>();
		builder.Services.AddSingleton<BloodAndBanners.UI.Services.IGameSessionCoordinator, BloodAndBanners.UI.Services.GameSessionCoordinator>();

#if DEBUG
		builder.Services.AddBlazorWebViewDeveloperTools();
		builder.Logging.AddDebug();
#endif

		return builder.Build();
	}
}
