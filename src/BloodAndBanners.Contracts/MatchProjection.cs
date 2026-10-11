namespace BloodAndBanners.Contracts;

public sealed record UnitCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp);

public sealed record HeroCard(
    string Name,
    string Image,
    int Move,
    int Range,
    int Power,
    int Defense,
    int Hp,
    string? TakenBySeatId);

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
    bool ActivatedThisTurn);
