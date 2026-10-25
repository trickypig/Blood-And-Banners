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
    string? OccupantId)
{
    public int? PlayerStart { get; init; }
    /// <summary>The map object on the hex: an obstacle, landmark, nexus point or item kind such as "tree" or "fire-crystal".</summary>
    public string? Object { get; init; }
    /// <summary>Nothing may enter the hex, flying included: obstacles and landmarks.</summary>
    public bool Impassable { get; init; }
}

public sealed record TurnProjection(
    int Round,
    string? ActiveSeatId,
    int ActivationsRemaining)
{
    public string? ActiveUnitId { get; init; }
    public int ActivationAllowance { get; init; }
    public string? RespondingToSeatId { get; init; }
}

public sealed record PendingChoiceProjection(
    string ChoiceId,
    string SeatId,
    string Kind,
    IReadOnlyList<string> Options)
{
    public string? UnitId { get; init; }
    public string? Side { get; init; }
    public IReadOnlyList<string> Faces { get; init; } = [];
    public IReadOnlyList<string> TargetHexes { get; init; } = [];
    public DateTimeOffset? ExpiresAt { get; init; }
}

public sealed record PendingChoiceSummary(string ChoiceId, string SeatId, string UnitId, DateTimeOffset? KeepableAt)
{
    public string Kind { get; init; } = string.Empty;
}

public sealed record MovementHazardProjection(
    string Kind,
    string Terrain,
    string Hex,
    int Dice,
    bool Forced,
    bool Stops);

public sealed record MovementOptionProjection(
    string Destination,
    IReadOnlyList<string> Path,
    int Cost,
    IReadOnlyList<MovementHazardProjection> Hazards);

public sealed record LegalAction(
    string Kind,
    string? UnitId,
    IReadOnlyList<string> TargetHexes)
{
    public string? Ability { get; init; }
    public string? Monster { get; init; }
    public TargetSelectionProjection? TargetSelection { get; init; }
    public IReadOnlyList<MovementOptionProjection> MovementOptions { get; init; } = [];
}

public sealed record TargetSelectionProjection(string Mode, int MaxTargets)
{
    public IReadOnlyDictionary<string, IReadOnlyList<string>> SecondaryTargets { get; init; } = new Dictionary<string, IReadOnlyList<string>>();
}

public sealed record GameResult(
    string Reason,
    string? WinningSeatId,
    IReadOnlyDictionary<string, int> FinalScores)
{
    public string? WinningSide { get; init; }
}

public sealed record MembershipProjection(
    string SeatId,
    int SeatIndex,
    bool Ready,
    bool IsHost)
{
    public string Role { get; init; } = "player";

    public string? Name { get; init; }

    public bool IsAccount { get; init; }

    /// <summary>True when the viewing account is already friends with this seat's account.</summary>
    public bool IsFriend { get; init; }

    /// <summary>True when the viewing account has a pending friend request out to this seat's account.</summary>
    public bool FriendRequested { get; init; }
}

public sealed record SideProjection(string Kind, IReadOnlyList<string> SeatIds);

public sealed record MonsterCard(
    string Name,
    string Biome,
    string Tier,
    int Cost,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp)
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
}

public sealed record SetupProjection(
    int Budget,
    int Spent,
    bool Confirmed,
    IReadOnlyList<string> SpawnZoneHexes,
    IReadOnlyList<MonsterCard> Roster)
{
    /// <summary>True for a viewer on the other side: only <see cref="Confirmed"/> is real.</summary>
    public bool Redacted { get; init; }

    /// <summary>In the war room: the most the Void Lord may place before the roster is locked (a full table). Null once the budget is final.</summary>
    public int? MaxBudget { get; init; }
}

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
    GameResult? Result)
{
    public string ScenarioName { get; init; } = string.Empty;
    public IReadOnlyList<MembershipProjection> Memberships { get; init; } = [];
    public DraftProjection? Draft { get; init; }
    public IReadOnlyDictionary<string, SeatArmyProjection>? Armies { get; init; }
    public IReadOnlyList<UnitProjection> Units { get; init; } = [];
    public PendingChoiceSummary? PendingChoiceSummary { get; init; }
    public IReadOnlyDictionary<string, SideProjection>? Sides { get; init; }
    public SetupProjection? Setup { get; init; }
}
