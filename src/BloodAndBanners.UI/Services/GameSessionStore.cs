using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public sealed record StoredGameSession(
    string DeviceId,
    AuthSession Identity,
    string GameId,
    string SeatToken);

public interface IGameSessionStore
{
    ValueTask<StoredGameSession?> LoadAsync();
    ValueTask SaveAsync(StoredGameSession session);
    ValueTask ClearAsync();
}

public interface IKeyValueStore
{
    ValueTask<string?> GetAsync(string key);
    ValueTask SetAsync(string key, string value);
    ValueTask RemoveAsync(string key);
}

public sealed class DelegatingKeyValueStore(
    Func<string, ValueTask<string?>> getAsync,
    Func<string, string, ValueTask> setAsync,
    Func<string, ValueTask> removeAsync) : IKeyValueStore
{
    public ValueTask<string?> GetAsync(string key) => getAsync(key);

    public ValueTask SetAsync(string key, string value) => setAsync(key, value);

    public ValueTask RemoveAsync(string key) => removeAsync(key);
}

public sealed class JsonGameSessionStore(IKeyValueStore keyValueStore) : IGameSessionStore
{
    private const string StorageKey = "bloodAndBanners.gameSession";

    public async ValueTask<StoredGameSession?> LoadAsync()
    {
        var json = await keyValueStore.GetAsync(StorageKey);
        return string.IsNullOrWhiteSpace(json)
            ? null
            : JsonSerializer.Deserialize<StoredGameSession>(json, ContractJson.Options);
    }

    public ValueTask SaveAsync(StoredGameSession session) =>
        keyValueStore.SetAsync(StorageKey, JsonSerializer.Serialize(session, ContractJson.Options));

    public ValueTask ClearAsync() => keyValueStore.RemoveAsync(StorageKey);
}
