namespace BloodAndBanners.Contracts;

public sealed record AbilityProjection(string Name, string Type, string Description)
{
    public bool Playable { get; init; }
    public bool UsedThisTurn { get; init; }
}

public sealed record EffectProjection(string Name, string Stat, int Amount, string Expires);

public sealed record UnitCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp)
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
}

public sealed record HeroCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp,
    string? TakenBySeatId)
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
}

public sealed record ArmyCard(
    string Name,
    string Color,
    IReadOnlyList<UnitCard> Units,
    string? TakenBySeatId);

public sealed record DraftProjection(
    IReadOnlyDictionary<string, IReadOnlyList<int>> InitiativeRolls,
    string? PickingSeatId,
    string? FirstSeatId,
    IReadOnlyDictionary<string, int?> StartBySeat,
    IReadOnlyDictionary<string, string?> HeroBySeat,
    IReadOnlyDictionary<string, string?> ArmyBySeat,
    IReadOnlyList<HeroCard> AvailableHeroes,
    IReadOnlyList<ArmyCard> AvailableArmies);

public sealed record UnitProjection(
    string UnitId,
    string SeatId,
    bool IsHero,
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp,
    int Wounds,
    string? Hex,
    bool ActivatedThisTurn)
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
    public IReadOnlyList<EffectProjection> Effects { get; init; } = [];
    public int? EffectiveMove { get; init; }
    public int? EffectiveRange { get; init; }
    public int? EffectivePower { get; init; }
    public int? EffectiveDefense { get; init; }
}
