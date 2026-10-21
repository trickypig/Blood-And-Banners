namespace BloodAndBanners.Contracts;

public sealed record AccountProfile(string UserId, string Email, string DisplayName);

public sealed record FriendSummary(string UserId, string DisplayName);

public sealed record IncomingFriendRequest(string RequestId, string DisplayName);

/// <summary>A request this account sent; the label is the typed email, or the player's name when sent from a game.</summary>
public sealed record OutgoingFriendRequest(string RequestId, string Label);

public sealed record FriendsList(
    IReadOnlyList<FriendSummary> Friends,
    IReadOnlyList<IncomingFriendRequest> Incoming,
    IReadOnlyList<OutgoingFriendRequest> Outgoing);

public sealed record AccountGameSeat(int SeatIndex, string? Name, bool IsFriend);

public sealed record AccountGameRow(
    string GameId,
    GameMode Mode,
    string ScenarioName,
    GameLifecycle Lifecycle,
    IReadOnlyList<AccountGameSeat> Seats,
    bool CanJoin,
    string UpdatedAt);

public sealed record AccountGames(IReadOnlyList<AccountGameRow> Yours, IReadOnlyList<AccountGameRow> Friends);
