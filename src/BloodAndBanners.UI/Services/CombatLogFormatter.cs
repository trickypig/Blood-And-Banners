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
            "choiceOpened" => ChoiceOpened(projection, payload),
            "choiceResolved" => Text(payload, "option") == "keep" ? "The roll stands." : null,
            "unitDefeated" => $"{Unit(projection, Text(payload, "unitId"))} is defeated.",
            "turnEnded" => $"Round {payload.GetProperty("round").GetInt32()}: {Possessive(Seat(projection, Text(payload, "nextSeatId")))} turn.",
            "matchCompleted" => $"{Seat(projection, Text(payload, "winningSeatId"))} won by annihilation.",
            "saveRolled" => payload.GetProperty("saved").GetBoolean()
                ? $"{Unit(projection, Text(payload, "unitId"))} resists {Text(payload, "ability")} (rolled {payload.GetProperty("roll").GetInt32()})."
                : $"{Unit(projection, Text(payload, "unitId"))} fails to resist {Text(payload, "ability")} (rolled {payload.GetProperty("roll").GetInt32()}).",
            "unitPushed" => Pushed(projection, payload),
            "conditionApplied" => $"{Unit(projection, Text(payload, "unitId"))} is {Text(payload, "name").ToLowerInvariant()}.",
            "conditionExpired" => $"{Unit(projection, Text(payload, "unitId"))} is no longer {Text(payload, "name").ToLowerInvariant()}.",
            "unitTeleported" => Text(payload, "unitId") == Text(payload, "casterId")
                ? $"{Unit(projection, Text(payload, "casterId"))} teleports to {Text(payload, "to")}."
                : $"{Unit(projection, Text(payload, "casterId"))} teleports {Unit(projection, Text(payload, "unitId"))} to {Text(payload, "to")}.",
            "terrainEntered" => TerrainEntered(projection, payload),
            "terrainIgnored" => TerrainIgnored(projection, payload),
            "terrainDamage" => TerrainDamage(projection, payload),
            "movementStopped" => MovementStopped(projection, payload),
            "unitSlid" => UnitSlid(projection, payload),
            "poisonRolled" => PoisonRolled(projection, payload),
            _ => null,
        };
    }

    private static string? ChoiceOpened(GameProjection projection, JsonElement payload) => Text(payload, "kind") switch
    {
        "moonlightGlare" => $"{Unit(projection, Text(payload, "unitId"))} may re-roll a die (Moonlight Glare).",
        "iceCollision" => $"{Unit(projection, Text(payload, "unitId"))} must choose how to pass an ally on the ice.",
        _ => null,
    };

    private static string TerrainEntered(GameProjection projection, JsonElement payload)
    {
        _ = Text(payload, "movementKind");
        return $"{Unit(projection, Text(payload, "unitId"))} enters {TerrainName(Text(payload, "terrain"))} at {Text(payload, "hex")}.";
    }

    private static string TerrainIgnored(GameProjection projection, JsonElement payload)
    {
        _ = Text(payload, "hex");
        var unit = Unit(projection, Text(payload, "unitId"));
        var terrain = TerrainName(Text(payload, "terrain"));
        var ability = Text(payload, "ability");
        return ability == "Flying"
            ? $"{unit} flies over {terrain}."
            : $"{unit} ignores {terrain} with {ability}.";
    }

    private static string MovementStopped(GameProjection projection, JsonElement payload) =>
        $"{Possessive(Unit(projection, Text(payload, "unitId")))} movement stops in {TerrainName(Text(payload, "reason"))} at {Text(payload, "hex")}.";

    private static string TerrainDamage(GameProjection projection, JsonElement payload)
    {
        var unit = Unit(projection, Text(payload, "unitId"));
        var terrain = TerrainName(Text(payload, "terrain"));
        var cause = Text(payload, "cause");
        _ = payload.GetProperty("dice").GetInt32();
        _ = Text(payload, "hex");
        var roll = Faces(payload.GetProperty("faces"));
        var wounds = Wounds(payload.GetProperty("wounds").GetInt32());
        var lead = cause switch
        {
            "entry" => $"{unit} enters {terrain}",
            "turnEnd" => $"Lava burns {unit} at turn end",
            "slipFall" => $"{unit} falls while sliding",
            "collision" => $"{unit} hits another unit on the ice",
            _ => $"{terrain} harms {unit}",
        };
        return $"{lead}: {roll} — {wounds} (no Defense).";
    }

    private static string PoisonRolled(GameProjection projection, JsonElement payload)
    {
        var unit = Unit(projection, Text(payload, "unitId"));
        _ = Text(payload, "hex");
        var roll = Faces(payload.GetProperty("faces"));
        var wounds = payload.GetProperty("wounds").GetInt32();
        var cleared = payload.GetProperty("cleared").GetBoolean();
        if (cleared)
        {
            return $"{unit} fights off the poison: {roll} — {Wounds(wounds)}.";
        }
        var lead = wounds > 0 ? $"Poison wracks {unit}" : $"{unit} resists the poison";
        return $"{lead}: {roll} — {Wounds(wounds)}; it remains poisoned.";
    }

    private static string UnitSlid(GameProjection projection, JsonElement payload)
    {
        var unit = Unit(projection, Text(payload, "unitId"));
        _ = Text(payload, "from");
        var to = Text(payload, "to");
        var path = payload.GetProperty("path").EnumerateArray().Select(step => step.GetString() ?? string.Empty).ToList();
        var direction = payload.GetProperty("direction").EnumerateArray().Select(value => value.GetInt32()).ToList();
        if (direction.Count != 2)
        {
            throw new FormatException("Ice slide direction must contain two coordinates.");
        }
        var ending = Text(payload, "stopped") switch
        {
            "exit" => "leaves the ice",
            "blocked" => "stops at an obstacle",
            "moveSpent" => "runs out of movement",
            "enemyCollision" => "collides with an enemy",
            "friendlyStop" => "stops before an ally",
            "defeated" => "falls",
            var stopped => $"stops ({TerrainName(stopped)})",
        };
        return $"{unit} slides {path.Count} {(path.Count == 1 ? "hex" : "hexes")} to {to} and {ending}.";
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

    private static string TerrainName(string value) => value.Replace('-', ' ').ToLowerInvariant();

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


    private static string Pushed(GameProjection projection, JsonElement payload)
    {
        var name = Unit(projection, Text(payload, "unitId"));
        var collisions = payload.GetProperty("collisions").EnumerateArray().ToList();
        var blocked = payload.GetProperty("blocked").ValueKind == JsonValueKind.String ? payload.GetProperty("blocked").GetString() : null;
        var line = blocked switch
        {
            "unit" => $"{name} is driven back but slams into {Unit(projection, Text(collisions[1], "unitId"))}.",
            "wall" => $"{name} is driven back but hits a wall.",
            "edge" => $"{name} is driven back but hits the edge of the battlefield.",
            _ => $"{name} is driven back to {Text(payload, "to")}.",
        };
        foreach (var fall in payload.GetProperty("falls").EnumerateArray())
        {
            line += $" It falls {fall.GetProperty("levels").GetInt32()} levels: {Wounds(fall.GetProperty("wounds").GetInt32())}.";
        }
        foreach (var hit in collisions)
        {
            line += $" {Unit(projection, Text(hit, "unitId"))}: {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
        return line;
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
