namespace BloodAndBanners.Contracts;

public sealed record GameSeatSession(
    GameProjection Game,
    string SeatToken);
