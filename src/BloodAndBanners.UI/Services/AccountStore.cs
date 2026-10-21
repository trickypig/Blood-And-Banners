using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IAccountStore
{
    ValueTask<StoredAccount?> LoadAsync();
    ValueTask SaveAsync(StoredAccount account);
    ValueTask ClearAsync();
}

public sealed class JsonAccountStore(IKeyValueStore keyValueStore) : IAccountStore
{
    private const string StorageKey = "bloodAndBanners.account";

    public async ValueTask<StoredAccount?> LoadAsync()
    {
        var json = await keyValueStore.GetAsync(StorageKey);
        return string.IsNullOrWhiteSpace(json)
            ? null
            : JsonSerializer.Deserialize<StoredAccount>(json, ContractJson.Options);
    }

    public ValueTask SaveAsync(StoredAccount account) =>
        keyValueStore.SetAsync(StorageKey, JsonSerializer.Serialize(account, ContractJson.Options));

    public ValueTask ClearAsync() => keyValueStore.RemoveAsync(StorageKey);
}
