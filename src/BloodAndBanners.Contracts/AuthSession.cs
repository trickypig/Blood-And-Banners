namespace BloodAndBanners.Contracts;

public sealed record AuthSession(
    string IdentityId,
    string Kind,
    string AccessToken,
    string RefreshToken);
