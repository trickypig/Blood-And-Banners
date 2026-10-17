using System.Globalization;
using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>One side's dice pool: who rolled it, what it was built from and what came up.</summary>
public sealed record DicePool(
    string UnitId,
    string UnitName,
    string Stat,
    int? Base,
    IReadOnlyList<string> Modifiers,
    IReadOnlyList<string> Faces);

/// <summary>Everything the attack popup shows for a single <c>attackResolved</c> event.</summary>
public sealed record AttackReport(
    long Sequence,
    string AttackerId,
    string DefenderId,
    string DamagedUnitId,
    string? Ability,
    DicePool Attack,
    DicePool Defense,
    IReadOnlyList<string> Notes,
    int Wounds,
    bool Defeated,
    string Summary,
    IReadOnlyList<string> Consequences)
{
    /// <summary>
    /// Reports for every attack after <paramref name="afterSequence"/>, oldest first. An attack's consequences are the
    /// readable log lines from the same command that follow it, up to the next attack.
    /// </summary>
    public static IReadOnlyList<AttackReport> FromEvents(IReadOnlyList<GameEvent> events, GameProjection projection, long afterSequence)
    {
        var reports = new List<AttackReport>();
        for (var index = 0; index < events.Count; index++)
        {
            var attack = events[index];
            if (attack.Kind != "attackResolved" || attack.Sequence <= afterSequence)
            {
                continue;
            }
            var following = events
                .Skip(index + 1)
                .TakeWhile(next => next.Version == attack.Version && next.Kind != "attackResolved")
                .ToList();
            try
            {
                reports.Add(Build(attack, following, projection));
            }
            catch (Exception error) when (error is KeyNotFoundException or InvalidOperationException or FormatException or JsonException)
            {
                // A malformed payload only loses its popup; the combat log still has the line.
            }
        }
        return reports;
    }

    private static AttackReport Build(GameEvent attack, IReadOnlyList<GameEvent> following, GameProjection projection)
    {
        var payload = attack.Payload;
        var attackerId = Text(payload, "unitId");
        var damagedId = Text(payload, "targetUnitId");
        var reflectedBy = OptionalText(payload, "reflectedByUnitId");
        var defenderId = reflectedBy ?? damagedId;
        var ability = OptionalText(payload, "ability");
        var attackFaces = Faces(payload, "attackFaces");
        var defenseFaces = Faces(payload, "defenseFaces");
        var wounds = payload.GetProperty("wounds").GetInt32();
        var penalty = payload.TryGetProperty("attackPenalty", out var penaltyValue) && penaltyValue.ValueKind == JsonValueKind.Number ? penaltyValue.GetInt32() : 0;
        var prevented = payload.TryGetProperty("woundsPrevented", out var preventedValue) && preventedValue.ValueKind == JsonValueKind.Number ? preventedValue.GetInt32() : 0;
        var shared = payload.TryGetProperty("sharedAttackRoll", out var sharedValue) && sharedValue.ValueKind == JsonValueKind.True;

        var attackModifiers = Modifiers(payload, "attackModifiers", projection).ToList();
        if (shared)
        {
            attackModifiers.Add("Shared roll");
        }
        if (penalty > 0)
        {
            attackModifiers.Add($"−{penalty} sword{Plural(penalty)} for this target");
        }

        var notes = new List<string>();
        if (reflectedBy is not null)
        {
            notes.Add($"{Name(projection, reflectedBy)} reflects the blow back at {Name(projection, attackerId)}!");
        }
        if (payload.TryGetProperty("rerolled", out var rerolled) && rerolled.ValueKind == JsonValueKind.Object)
        {
            var side = rerolled.GetProperty("side").GetString() == "attack" ? "attack" : "defense";
            notes.Add($"Moonlight Glare rerolled a {side} die: {FaceName(rerolled.GetProperty("from").GetString())} → {FaceName(rerolled.GetProperty("to").GetString())}.");
        }

        var defeated = following.Any(next => next.Kind == "unitDefeated" && OptionalText(next.Payload, "unitId") == damagedId);
        var consequences = following
            .Where(next => next.Kind is not ("turnEnded" or "choiceResolved"))
            .Where(next => !(next.Kind == "unitDefeated" && OptionalText(next.Payload, "unitId") == damagedId))
            .Select(next => CombatLogFormatter.Describe(next, projection))
            .OfType<string>()
            .ToList();

        var swords = attackFaces.Count(face => face == "sword");
        var shields = defenseFaces.Count(face => face == "shield");
        return new AttackReport(
            attack.Sequence,
            attackerId,
            defenderId,
            damagedId,
            ability,
            new DicePool(attackerId, Name(projection, attackerId), "Power", OptionalInt(payload, "attackBase"), attackModifiers, attackFaces),
            new DicePool(damagedId, Name(projection, damagedId), "Defense", OptionalInt(payload, "defenseBase"), Modifiers(payload, "defenseModifiers", projection).ToList(), defenseFaces),
            notes,
            wounds,
            defeated,
            Summarize(swords, penalty, shields, prevented, wounds, defeated ? Name(projection, damagedId) : null),
            consequences);
    }

    /// <summary>"2 swords vs 1 shield → 1 wound → Goblin is defeated".</summary>
    public static string Summarize(int swords, int penalty, int shields, int prevented, int wounds, string? defeatedName)
    {
        var parts = new List<string>
        {
            $"{Count(swords, "sword")}{(penalty > 0 ? $" (−{penalty})" : "")} vs {Count(shields, "shield")}",
            wounds == 0 ? "no wounds" : Count(wounds, "wound"),
        };
        if (prevented > 0)
        {
            parts[^1] += $" ({prevented} prevented)";
        }
        if (defeatedName is not null)
        {
            parts.Add($"{defeatedName} is defeated");
        }
        return string.Join(" → ", parts);
    }

    private static IEnumerable<string> Modifiers(JsonElement payload, string property, GameProjection projection)
    {
        if (!payload.TryGetProperty(property, out var modifiers) || modifiers.ValueKind != JsonValueKind.Array)
        {
            yield break;
        }
        foreach (var modifier in modifiers.EnumerateArray())
        {
            var source = Text(modifier, "source");
            var owner = OptionalText(modifier, "unitId");
            var from = owner is null ? "" : $" ({Name(projection, owner)})";
            yield return modifier.TryGetProperty("amount", out var amount) && amount.ValueKind == JsonValueKind.Number
                ? $"{source}{from} {amount.GetInt32().ToString("+0;−0", CultureInfo.InvariantCulture)}"
                : $"{source}{from}: no dice";
        }
    }

    private static IReadOnlyList<string> Faces(JsonElement payload, string property) =>
        payload.GetProperty(property).EnumerateArray().Select(face => face.GetString() ?? "blank").ToList();

    private static string FaceName(string? face) => face switch
    {
        "sword" => "sword",
        "shield" => "shield",
        _ => "blank",
    };

    private static string Count(int count, string noun) => $"{count} {noun}{Plural(count)}";

    private static string Plural(int count) => count == 1 ? "" : "s";

    private static string Name(GameProjection projection, string unitId) =>
        projection.Units.FirstOrDefault(unit => unit.UnitId == unitId)?.Name ?? unitId;

    private static string Text(JsonElement element, string property) =>
        element.GetProperty(property).GetString() ?? throw new InvalidOperationException($"{property} is null");

    private static string? OptionalText(JsonElement element, string property) =>
        element.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    private static int? OptionalInt(JsonElement element, string property) =>
        element.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.Number ? value.GetInt32() : null;
}
