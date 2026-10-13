namespace BloodAndBanners.UI.Services;

/// <summary>Player-facing wording for unit conditions; mirrors the server's condition handlers.</summary>
public static class ConditionText
{
    public static string Summary(string name) => name switch
    {
        "Stunned" => "can't move, no parting strikes, −2 Power",
        "Unsteady" => "−1 Power and no disengagement strikes",
        "Slipping" => "must continue in its locked direction before acting",
        "Poisoned" => "rolls one poison die for a wound at the end of its owner's turn",
        _ => string.Empty,
    };

    public static int StatDelta(string name, string stat) => (name, stat) switch
    {
        ("Stunned", "power") => -2,
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
