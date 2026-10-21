namespace BloodAndBanners.Contracts;

public sealed record AuthSession(
    string IdentityId,
    string Kind,
    string AccessToken,
    string RefreshToken)
{
    /// <summary>Set for signed-in accounts; empty until the player picks a name.</summary>
    public string? DisplayName { get; init; }
}
