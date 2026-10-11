namespace BloodAndBanners.UI.Services;

public static class CatalogImages
{
    public static string? Resolve(string? baseUrl, string? path)
    {
        if (string.IsNullOrWhiteSpace(baseUrl) || string.IsNullOrWhiteSpace(path))
        {
            return null;
        }
        var escaped = string.Join('/', path.Split('/').Select(Uri.EscapeDataString));
        return baseUrl.TrimEnd('/') + (escaped.StartsWith('/') ? escaped : "/" + escaped);
    }
}
