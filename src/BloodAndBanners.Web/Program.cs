using Microsoft.AspNetCore.Components.Web;
using Microsoft.AspNetCore.Components.WebAssembly.Hosting;
using Microsoft.JSInterop;
using BloodAndBanners.Web;
using BloodAndBanners.UI.Services;

var builder = WebAssemblyHostBuilder.CreateDefault(args);
builder.RootComponents.Add<App>("#app");
builder.RootComponents.Add<HeadOutlet>("head::after");

var apiBaseUrl = builder.Configuration["ApiBaseUrl"] ?? builder.HostEnvironment.BaseAddress;
builder.Services.AddScoped(sp => new HttpClient { BaseAddress = new Uri(apiBaseUrl) });
builder.Services.AddScoped<IGameApiClient, GameApiClient>();
builder.Services.AddScoped<IAccountApiClient, AccountApiClient>();
builder.Services.AddScoped<IMapApiClient, MapApiClient>();
builder.Services.AddScoped<IAccountStore, JsonAccountStore>();
builder.Services.AddScoped<IAccountSession, AccountSession>();
builder.Services.AddScoped<IKeyValueStore>(services =>
{
    var js = services.GetRequiredService<IJSRuntime>();
    return new DelegatingKeyValueStore(
        key => js.InvokeAsync<string?>("localStorage.getItem", key),
        (key, value) => js.InvokeVoidAsync("localStorage.setItem", key, value),
        key => js.InvokeVoidAsync("localStorage.removeItem", key));
});
builder.Services.AddScoped<IGameSessionStore, JsonGameSessionStore>();
builder.Services.AddScoped<IGameSessionCoordinator, GameSessionCoordinator>();

await builder.Build().RunAsync();
