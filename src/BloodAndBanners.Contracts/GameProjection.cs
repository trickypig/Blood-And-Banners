namespace BloodAndBanners.Contracts;

public enum GameLifecycle
{
    Lobby,
    Draft,
    Setup,
    Active,
    Completed,
    Abandoned
}

public enum GameMode
{
    VanillaPvp,
    Coop,
    BattleRoyale
}

public sealed record ViewerPermissions(
    string Role,
    string? SeatId,
    bool CanCommand,
    bool IsHost);

public sealed record BoardProjection(
    int Columns,
    int Rows,
    IReadOnlyList<HexProjection> Hexes);

public sealed record HexProjection(
    int Q,
    int R,
    string Biome,
    string Terrain,
    int Elevation,
    bool SightBlocker,
    string? OccupantId);

public sealed record TurnProjection(
    int Round,
    string? ActiveSeatId,
    int ActivationsRemaining);

public sealed record PendingChoiceProjection(
    string ChoiceId,
    string SeatId,
    string Kind,
    IReadOnlyList<string> Options);

public sealed record LegalAction(
    string Kind,
    string? UnitId,
    IReadOnlyList<string> TargetHexes);

public sealed record GameResult(
    string Reason,
    string? WinningSeatId,
    IReadOnlyDictionary<string, int> FinalScores);

public sealed record GameProjection(
    string GameId,
    long Version,
    GameLifecycle Lifecycle,
    GameMode Mode,
    ViewerPermissions Viewer,
    BoardProjection Board,
    TurnProjection Turn,
    PendingChoiceProjection? PendingChoice,
    IReadOnlyList<LegalAction> LegalActions,
    IReadOnlyDictionary<string, int> Scores,
    GameResult? Result);
