namespace BloodAndBanners.UI.Services;

/// <summary>Player-facing wording for unit conditions; mirrors the server's condition handlers.</summary>
public static class ConditionText
{
    public static string Summary(string name, int? powerPenalty = null) => name switch
    {
        "Stunned" => $"can't move, no parting strikes, −{powerPenalty ?? 2} Power",
        "Petrified" => "can't move or attack, no parting strikes, no Defense dice",
        "Entrapped" => "can't move or attack, no parting strikes",
        "Pan Pipes" => "protects allied Satyrs within 3 hexes; failed save 15+ lets their owner redirect the attack",
        "Slowed" => "−1 Move",
        "Unsteady" => "−1 Power and no disengagement strikes",
        "Slipping" => "must continue in its locked direction before acting",
        "Poisoned" => "rolls one poison die for a wound at the end of its owner's turn",
        _ => string.Empty,
    };

    public static int StatDelta(string name, string stat, int? powerPenalty = null) => (name, stat) switch
    {
        ("Stunned", "power") => -(powerPenalty ?? 2),
        ("Slowed", "move") => -1,
        ("Unsteady", "power") => -1,
        _ => 0,
    };

    public static string Expiry(string expires) => expires switch
    {
        "endOfThisTurn" => "until the end of this turn",
        "endOfNextTurn" => "until the end of its next turn",
        "turnStart" => "until its caster's next turn",
        "turnEnd" => "until the end of the turn",
        "whileOnTerrain" => "while it stays on this terrain",
        "untilCleared" => "until cleared",
        _ => "until cleared",
    };
}
