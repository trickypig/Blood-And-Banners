namespace BloodAndBanners.Contracts;

public sealed record SeatReassignmentResult(
    string SeatId,
    string SeatToken,
    GameProjection Game);
