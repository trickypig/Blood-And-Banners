using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>Whether a map can host one game mode, for how many players, and if not, why not in plain sentences.</summary>
public sealed record ModeCheck(GameMode Mode, int Players, IReadOnlyList<string> Issues)
{
    public bool IsValid => Issues.Count == 0;
}

/// <summary>
/// Checks a map against each game mode. Room around a start is measured by running the game's own deployment
/// (Placement.php): the first four units stand next to the start, later ones next to units already placed, never on
/// obstacles, landmarks, lava, poison pits, spawn zones, the Void Portal or another start.
/// </summary>
public static class MapValidation
{
    public const int DuelUnits = 7, HeroUnits = 8, RoyaleUnits = 8, MaxHeroPlayers = 4, MinSpawnHexes = 24, CoopReach = 3, Clearance = 5;
    private const int AnchoredUnits = 4;

    public static IReadOnlyList<ModeCheck> Check(ScenarioDefinition definition)
    {
        var map = new Map(definition.Map.Hexes);
        return [Duel(map), Coop(map), Royale(map)];
    }

    /// <summary>
    /// The map with its valid modes and their player counts recorded, ready to save. With no valid mode the counts are
    /// left out, since PHP would store an empty object as a JSON list.
    /// </summary>
    public static ScenarioDefinition Stamp(ScenarioDefinition definition, IReadOnlyList<ModeCheck> checks)
    {
        var valid = checks.Where(c => c.IsValid).ToList();
        return definition with
        {
            ModeCompatibility = valid.Select(c => c.Mode).ToList(),
            PlayerCounts = valid.Count > 0 ? valid.ToDictionary(c => c.Mode, c => c.Players) : null,
        };
    }

    private static ModeCheck Duel(Map map)
    {
        var issues = MissingStarts(map, [1, 2], GameMode.VanillaPvp);
        if (issues.Count == 0)
        {
            var occupied = map.Blocked();
            foreach (var (player, opponent) in new[] { (1, 2), (2, 1) })
            {
                var placed = map.Place(map.Starts[player], map.Starts[opponent], DuelUnits, occupied);
                if (placed.Count < DuelUnits) issues.Add($"P{player}'s start has room for {Units(placed.Count)}; a duel needs {DuelUnits}.");
                occupied.UnionWith(placed);
            }
            issues.AddRange(Crowded(map, [1, 2]));
        }
        return new(GameMode.VanillaPvp, issues.Count == 0 ? 2 : 0, issues);
    }

    private static ModeCheck Coop(Map map)
    {
        var issues = MissingStarts(map, [1], GameMode.Coop);
        if (map.Portal is null) issues.Add("Place the Void Portal for the Void Lord.");
        issues.AddRange(FewSpawnHexes(map, GameMode.Coop));
        var players = 0;
        if (map.Starts.TryGetValue(1, out var start))
        {
            var facing = map.Spawn.OrderBy(key => HexMath.Distance(start, key)).ThenBy(key => key, StringComparer.Ordinal).FirstOrDefault() ?? start;
            var room = map.Place(start, facing, HeroUnits * MaxHeroPlayers, map.Blocked(), key => HexMath.Distance(start, key) <= CoopReach).Count;
            players = room / HeroUnits;
            if (players == 0) issues.Add($"P1's start has room for {Units(room)} within {CoopReach} hexes; each hero player needs {HeroUnits}.");
            issues.AddRange(Crowded(map, [1]));
        }
        return new(GameMode.Coop, issues.Count == 0 ? players : 0, issues);
    }

    private static ModeCheck Royale(Map map)
    {
        var count = map.Starts.Keys.DefaultIfEmpty(0).Max();
        var issues = new List<string>();
        var numbered = Enumerable.Range(1, count).ToList();
        var gaps = numbered.Where(p => !map.Starts.ContainsKey(p)).ToList();
        if (count < 2) issues.Add("Battle royale needs at least P1 and P2 starts.");
        else if (gaps.Count > 0) issues.Add($"Battle royale starts must be numbered in order; add {string.Join(" and ", gaps.Select(p => $"P{p}"))} or renumber the starts.");
        issues.AddRange(FewSpawnHexes(map, GameMode.BattleRoyale));
        if (count >= 2 && gaps.Count == 0)
        {
            var occupied = map.Blocked();
            foreach (var player in numbered)
            {
                var home = map.Starts[player];
                var rival = numbered.Where(p => p != player).Select(p => map.Starts[p])
                    .OrderBy(key => HexMath.Distance(home, key)).ThenBy(key => key, StringComparer.Ordinal).First();
                var placed = map.Place(home, rival, RoyaleUnits, occupied);
                if (placed.Count < RoyaleUnits) issues.Add($"P{player}'s start has room for {Units(placed.Count)}; battle royale needs {RoyaleUnits}.");
                occupied.UnionWith(placed);
            }
            issues.AddRange(Crowded(map, numbered));
        }
        return new(GameMode.BattleRoyale, issues.Count == 0 ? count : 0, issues);
    }

    private static List<string> MissingStarts(Map map, int[] players, GameMode mode) =>
        players.Where(p => !map.Starts.ContainsKey(p)).Select(p => $"Place a P{p} start; {GameModeLabels.For(mode).ToLowerInvariant()} needs one.").ToList();

    private static IEnumerable<string> FewSpawnHexes(Map map, GameMode mode)
    {
        if (map.Spawn.Count < MinSpawnHexes)
            yield return $"{GameModeLabels.For(mode)} needs at least {MinSpawnHexes} Void spawn hexes; this map has {map.Spawn.Count}.";
    }

    /// <summary>Objectives (landmarks) and pickups (items) must stand more than <see cref="Clearance"/> hexes from every start in play.</summary>
    private static IEnumerable<string> Crowded(Map map, IEnumerable<int> players)
    {
        foreach (var player in players)
            foreach (var hex in map.Hexes.Values.Where(h => MapObjectArt.CategoryOf(h.Object) is "landmark" or "item").OrderBy(h => h.R).ThenBy(h => h.Q))
            {
                var distance = HexMath.Distance(map.Starts[player], Map.Key(hex));
                if (distance <= Clearance)
                    yield return $"The {MapObjectArt.Display(hex.Object!)} at {hex.Q}, {hex.R} is {distance} {(distance == 1 ? "hex" : "hexes")} from P{player}'s start; keep objectives and potions more than {Clearance} hexes away.";
            }
    }

    private static string Units(int count) => count == 1 ? "1 unit" : $"{count} units";

    private sealed class Map
    {
        public readonly Dictionary<string, ScenarioHex> Hexes;
        public readonly Dictionary<int, string> Starts = [];
        public readonly List<string> Spawn;
        public readonly string? Portal;

        public Map(IEnumerable<ScenarioHex> hexes)
        {
            Hexes = hexes.ToDictionary(Key);
            foreach (var (key, hex) in Hexes)
                if (hex.PlayerStart is { } player) Starts[player] = key;
            Spawn = Hexes.Where(e => e.Value.SpawnZone == "void").Select(e => e.Key).ToList();
            Portal = Hexes.Where(e => e.Value.VoidPortal).Select(e => e.Key).FirstOrDefault();
        }

        public static string Key(ScenarioHex hex) => $"{hex.Q},{hex.R}";

        /// <summary>Hexes no unit may deploy onto, whoever is placing.</summary>
        public HashSet<string> Blocked() => Hexes.Where(e => !Deployable(e.Value) || e.Value.PlayerStart is not null).Select(e => e.Key).ToHashSet();

        private static bool Deployable(ScenarioHex hex) =>
            !MapObjectArt.Impassable(hex.Object) && !hex.SightBlocker && hex.Terrain is not ("lava" or "poison-pits")
            && hex.SpawnZone != "void" && !hex.VoidPortal;

        /// <summary>Placement.php's deployment of up to <paramref name="units"/> units; returns the hexes filled before room ran out.</summary>
        public List<string> Place(string home, string opponent, int units, ISet<string> occupied, Func<string, bool>? within = null)
        {
            var placed = new List<string>();
            for (var index = 0; index < units; index++)
            {
                var anchors = index < AnchoredUnits ? [home] : placed;
                var best = anchors.SelectMany(HexMath.Neighbours).Distinct()
                    .Where(key => Hexes.ContainsKey(key) && key != home && key != opponent && !occupied.Contains(key) && !placed.Contains(key) && (within?.Invoke(key) ?? true))
                    .OrderBy(key => index < AnchoredUnits ? HexMath.Distance(key, opponent) : HexMath.Distance(key, home))
                    .ThenBy(key => index < AnchoredUnits ? 0 : HexMath.Distance(key, opponent))
                    .ThenBy(key => HexMath.Parse(key).Q).ThenBy(key => HexMath.Parse(key).R)
                    .FirstOrDefault();
                if (best is null) break;
                placed.Add(best);
            }
            return placed;
        }
    }
}
