using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public static class CombatLogFormatter
{
    public static string? Describe(GameEvent gameEvent, GameProjection projection)
    {
        var payload = gameEvent.Payload;
        return gameEvent.Kind switch
        {
            "initiativeRolled" => "Initiative is rolled.",
            "draftPicked" => $"{Seat(projection, Text(payload, "seatId"))} chose {DraftChoice(projection, payload)}.",
            "armiesPlaced" => "Both armies take the field.",
            "unitMoved" => Moved(projection, payload),
            "attackResolved" =>
                $"{Unit(projection, Text(payload, "unitId"))} → {Unit(projection, Text(payload, "targetUnitId"))}: " +
                $"{Faces(payload.GetProperty("attackFaces"))} vs {Faces(payload.GetProperty("defenseFaces"))} — " +
                Wounds(payload.GetProperty("wounds").GetInt32()),
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
            line += $" {Unit(projection, Text(hit, "byUnitId"))} strikes as it disengages: {Faces(hit.GetProperty("faces"))} — {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
        return line;
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
