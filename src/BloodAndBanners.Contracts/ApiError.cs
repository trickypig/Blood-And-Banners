namespace BloodAndBanners.Contracts;

public sealed record ApiErrorResponse(ApiError Error);

public sealed record ApiError(
    string Code,
    string Message,
    long? CurrentVersion,
    string Recovery);
