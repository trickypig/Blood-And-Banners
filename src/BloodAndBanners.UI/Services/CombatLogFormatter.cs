using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public static class CombatLogFormatter
{
    public static string? Describe(GameEvent gameEvent, GameProjection projection)
    {
        try
        {
            return DescribeCore(gameEvent, projection);
        }
        catch (Exception error) when (error is KeyNotFoundException or InvalidOperationException or FormatException or JsonException)
        {
            return null;
        }
    }

    private static string? DescribeCore(GameEvent gameEvent, GameProjection projection)
    {
        var payload = gameEvent.Payload;
        return gameEvent.Kind switch
        {
            "initiativeRolled" => "Initiative is rolled.",
            "draftPicked" => $"{Seat(projection, Text(payload, "seatId"))} chose {DraftChoice(projection, payload)}.",
            "armiesPlaced" => "Both armies take the field.",
            "unitMoved" => Moved(projection, payload),
            "attackResolved" => Attack(projection, payload),
            "evaded" => payload.GetProperty("evaded").GetBoolean()
                ? $"{Unit(projection, Text(payload, "unitId"))} evades (rolled {payload.GetProperty("roll").GetInt32()})."
                : $"{Unit(projection, Text(payload, "unitId"))} fails to evade (rolled {payload.GetProperty("roll").GetInt32()}).",
            "survivedDefeat" => $"{Unit(projection, Text(payload, "unitId"))} refuses to fall: {Text(payload, "ability")}.",
            "chaosRolled" => payload.GetProperty("struck").GetBoolean()
                ? $"Chaos Strike surges (rolled {payload.GetProperty("roll").GetInt32()})!"
                : $"Chaos Strike falters (rolled {payload.GetProperty("roll").GetInt32()}).",
            "splashDamage" => $"{Text(payload, "ability")} scorches {JoinAnd(payload.GetProperty("hits").EnumerateArray().Select(hit => $"{Unit(projection, Text(hit, "unitId"))} for {hit.GetProperty("wounds").GetInt32()}"))}.",
            "abilityUsed" => $"{Unit(projection, Text(payload, "unitId"))} uses {Text(payload, "ability")} on {Unit(projection, Text(payload, "targetUnitId"))}.",
            "effectApplied" => $"{Unit(projection, Text(payload, "targetUnitId"))} gets {Signed(payload.GetProperty("amount").GetInt32())} {Title(Text(payload, "stat"))} ({Text(payload, "name")}).",
            "effectExpired" => $"{Text(payload, "name")} fades from {Unit(projection, Text(payload, "targetUnitId"))}.",
            "choiceOpened" => $"{Unit(projection, Text(payload, "unitId"))} may re-roll a die (Moonlight Glare).",
            "choiceResolved" => Text(payload, "option") == "keep" ? "The roll stands." : null,
            "unitDefeated" => $"{Unit(projection, Text(payload, "unitId"))} is defeated.",
            "turnEnded" => $"Round {payload.GetProperty("round").GetInt32()}: {Possessive(Seat(projection, Text(payload, "nextSeatId")))} turn.",
            "matchCompleted" => $"{Seat(projection, Text(payload, "winningSeatId"))} won by annihilation.",
            _ => null,
        };
    }

    private static string Moved(GameProjection projection, JsonElement payload)
    {
        var line = $"{Unit(projection, Text(payload, "unitId"))} moves to {Text(payload, "to")}.";
        foreach (var hit in payload.GetProperty("disengagement").EnumerateArray())
        {
            if (hit.TryGetProperty("evaded", out var evaded) && evaded.GetBoolean())
            {
                line += $" It evades {Unit(projection, Text(hit, "byUnitId"))}'s parting strike (rolled {hit.GetProperty("evasionRoll").GetInt32()}).";
                continue;
            }
            line += $" {Unit(projection, Text(hit, "byUnitId"))} strikes as it disengages: {Faces(hit.GetProperty("faces"))} — {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
        return line;
    }

    private static string Attack(GameProjection projection, JsonElement payload)
    {
        var ability = payload.TryGetProperty("ability", out var name) && name.ValueKind == JsonValueKind.String ? $" ({name.GetString()})" : string.Empty;
        var line = $"{Unit(projection, Text(payload, "unitId"))} → {Unit(projection, Text(payload, "targetUnitId"))}{ability}: " +
            $"{Faces(payload.GetProperty("attackFaces"))} vs {Faces(payload.GetProperty("defenseFaces"))} — " +
            Wounds(payload.GetProperty("wounds").GetInt32());
        if (payload.TryGetProperty("rerolled", out var reroll) && reroll.ValueKind == JsonValueKind.Object)
        {
            line += $". Re-rolled a {Text(reroll, "from")}: {Text(reroll, "to")}.";
        }
        return line;
    }

    private static string Signed(int amount) => amount > 0 ? $"+{amount}" : amount.ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static string Title(string value) => string.IsNullOrEmpty(value) ? value : char.ToUpperInvariant(value[0]) + value[1..];

    private static string JoinAnd(IEnumerable<string> parts)
    {
        var list = parts.ToList();
        return list.Count <= 1 ? string.Concat(list) : string.Join(", ", list[..^1]) + " and " + list[^1];
    }

    private static string DraftChoice(GameProjection projection, JsonElement payload)
    {
        var value = payload.GetProperty("value").ToString();
        return Text(payload, "decision") switch
        {
            "chooseFirstPlayer" => $"{Seat(projection, value)} to move first",
            "chooseStartPosition" => $"start {value}",
            _ => value,
        };
    }

    private static string Seat(GameProjection projection, string seatId)
    {
        if (seatId == projection.Viewer.SeatId)
        {
            return "You";
        }
        if (projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        var seat = projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? "A commander" : $"Commander {seat.SeatIndex}";
    }

    private static string Possessive(string name) => name == "You" ? "Your" : $"{name}'s";

    private static string Unit(GameProjection projection, string unitId) =>
        projection.Units.FirstOrDefault(unit => unit.UnitId == unitId)?.Name ?? unitId;

    private static string Faces(JsonElement faces)
    {
        var symbols = string.Concat(faces.EnumerateArray().Select(face => face.GetString() switch
        {
            "sword" => "⚔",
            "shield" => "🛡",
            _ => "⬚",
        }));
        return symbols.Length == 0 ? "—" : symbols;
    }

    private static string Wounds(int wounds) => wounds == 1 ? "1 wound" : $"{wounds} wounds";

    private static string Text(JsonElement element, string property) =>
        element.GetProperty(property).GetString() ?? string.Empty;
}
