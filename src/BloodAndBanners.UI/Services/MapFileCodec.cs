using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

/// <summary>Local, account-independent map conversion. Unknown scenario and tile fields survive round trips.</summary>
public static class MapFileCodec
{
    public const int MaxBytes = 2 * 1024 * 1024;
    public static ScenarioScoring DefaultScoring => new(10, 3, 2, 1, 2);
    private static readonly JsonSerializerOptions Pretty = new(ContractJson.Options) { WriteIndented = true };

    public static ScenarioDefinition Import(string json)
    {
        if (Encoding.UTF8.GetByteCount(json) > MaxBytes) throw new FormatException("Maps must be no larger than 2 MiB.");
        var root = JsonNode.Parse(json) as JsonObject ?? throw new FormatException("Expected a map object.");
        ScenarioDefinition definition;
        if (root.ContainsKey("schemaVersion"))
        {
            definition = ReadCanonical(root);
        }
        else
        {
            if (root["version"] is { } version && version.GetValue<int>() is not (1 or 2 or 3))
                throw new FormatException("Unsupported map version.");
            var hexes = root["hexes"] as JsonArray ?? throw new FormatException("Map hexes are required.");
            var converted = new JsonArray();
            foreach (var item in hexes)
            {
                var hex = item?.DeepClone() as JsonObject ?? throw new FormatException("Invalid hex.");
                if (hex.ContainsKey("q") || hex.ContainsKey("r"))
                {
                    _ = hex["q"]!.GetValue<int>(); _ = hex["r"]!.GetValue<int>();
                }
                else
                {
                    var col = hex["col"]!.GetValue<int>();
                    var row = hex["row"]!.GetValue<int>();
                    hex["q"] = row - (int)Math.Floor(col / 2.0); hex["r"] = col;
                }
                hex.Remove("col"); hex.Remove("row");
                hex["elevation"] ??= hex["height"]?.DeepClone() ?? JsonValue.Create(0);
                hex.Remove("height");
                var terrain = hex["terrain"]?.GetValue<string>() ?? "grass";
                var biome = hex["biome"]?.GetValue<string>() ?? LegacyBiome(terrain);
                // The old editor resolves its single-level terrain aliases to the biome's default.
                if (!TerrainArt.IsValid(biome, terrain) && IsLegacyAlias(terrain) && TerrainArt.BiomeNames.Contains(biome))
                    terrain = TerrainArt.Terrains(biome)[0];
                hex["biome"] = biome; hex["terrain"] = terrain;
                hex["portal"] ??= false; hex["fountain"] ??= hex["object"]?.GetValue<string>() == "fountain";
                hex["sightBlocker"] ??= false;
                converted.Add(hex);
            }
            var canonical = (JsonObject)root.DeepClone();
            foreach (var key in new[] { "version", "columns", "rows", "hexes" }) canonical.Remove(key);
            canonical["schemaVersion"] = 1;
            canonical["name"] ??= "Imported Map";
            canonical["modeCompatibility"] ??= new JsonArray("vanillaPvp", "coop", "battleRoyale");
            canonical["map"] = new JsonObject { ["columns"] = root["columns"]?.DeepClone(), ["rows"] = root["rows"]?.DeepClone(), ["hexes"] = converted };
            canonical["placements"] ??= new JsonArray(); canonical["objectives"] ??= new JsonArray();
            canonical["scoring"] ??= JsonSerializer.SerializeToNode(DefaultScoring, ContractJson.Options);
            definition = ReadCanonical(canonical);
        }
        Validate(definition);
        return definition with { Name = definition.Name.Trim() };
    }

    public static void Validate(ScenarioDefinition definition)
    {
        if (definition.SchemaVersion != 1 || string.IsNullOrWhiteSpace(definition.Name) || definition.Name.EnumerateRunes().Count() > 120)
            throw new FormatException("Use schema version 1 and a map name of 1–120 characters.");
        if (definition.ModeCompatibility is not { Count: > 0 } || definition.ModeCompatibility.Any(m => !Enum.IsDefined(m)))
            throw new FormatException("Invalid game modes.");
        var map = definition.Map;
        if (map is null || map.Columns is < 1 or > 100 || map.Rows is < 1 or > 100 || map.Hexes is not { Count: > 0 and <= 10000 })
            throw new FormatException("Maps need dimensions from 1–100 and 1–10,000 hexes.");
        var seen = new HashSet<(int, int)>();
        foreach (var hex in map.Hexes)
        {
            if (hex is null || hex.Q is < -10000 or > 10000 || hex.R is < -10000 or > 10000 || !seen.Add((hex.Q, hex.R)))
                throw new FormatException("Invalid or duplicate axial coordinates.");
            if (hex.Biome is null || hex.Terrain is null || !TerrainArt.IsValid(hex.Biome, hex.Terrain)) throw new FormatException("Invalid biome or terrain.");
            if (hex.Elevation is < -1000 or > 1000 || hex.PlayerStart is < 1 or > 4) throw new FormatException("Invalid height or player start.");
            if (new[] { hex.SpawnZone, hex.Piece, hex.Object }.Any(v => v?.Length > 120)) throw new FormatException("Invalid tile metadata.");
        }
        if (definition.Placements is null || definition.Objectives is null || definition.Scoring is null)
            throw new FormatException("Scenario metadata is missing.");
        var s = definition.Scoring;
        if (new[] { s.Target, s.EnemyHero, s.Boss, s.TerrainObject, s.CompleteObjectSetBonus }.Any(v => v is < 0 or > 1000000))
            throw new FormatException("Invalid scoring values.");
    }

    private static ScenarioDefinition ReadCanonical(JsonObject root)
    {
        // Record deserialization otherwise supplies 0 for absent integer fields, silently moving a hex.
        var map = root["map"] as JsonObject ?? throw new FormatException("Map data is required.");
        foreach (var field in new[] { "columns", "rows" }) RequireInteger(map, field);
        var hexes = map["hexes"] as JsonArray ?? throw new FormatException("Map hexes are required.");
        foreach (var item in hexes)
        {
            var hex = item as JsonObject ?? throw new FormatException("Invalid hex.");
            foreach (var field in new[] { "q", "r", "elevation" }) RequireInteger(hex, field);
            foreach (var field in new[] { "portal", "fountain", "sightBlocker" })
                if (hex[field] is not JsonValue flag || !flag.TryGetValue<bool>(out _)) throw new FormatException($"{field} must be a boolean.");
            if (hex["playerStart"] is not null) RequireInteger(hex, "playerStart");
        }
        var modes = root["modeCompatibility"] as JsonArray ?? throw new FormatException("Game modes are required.");
        foreach (var mode in modes)
            if (mode is not JsonValue value || !value.TryGetValue<string>(out var name) || name is not ("vanillaPvp" or "coop" or "battleRoyale"))
                throw new FormatException("Invalid game mode.");
        var scoring = root["scoring"] as JsonObject ?? throw new FormatException("Scoring data is required.");
        foreach (var field in new[] { "target", "enemyHero", "boss", "terrainObject", "completeObjectSetBonus" }) RequireInteger(scoring, field);
        return root.Deserialize<ScenarioDefinition>(ContractJson.Options) ?? throw new FormatException("Invalid scenario.");
    }

    private static void RequireInteger(JsonObject obj, string field)
    {
        if (obj[field] is not JsonValue number || !number.TryGetValue<int>(out _))
            throw new FormatException($"{field} must be an integer.");
    }

    public static bool HasScenarioMetadata(ScenarioDefinition map) =>
        map.Placements.Count > 0 || map.Objectives.Count > 0 || map.Map.ExtensionData?.Count > 0 ||
        map.ExtensionData?.Keys.Any(k => k is "version" or "columns" or "rows" or "hexes") == true ||
        map.Map.Hexes.Any(h => h.ExtensionData?.Keys.Any(k => k is "height" or "col" or "row") == true) ||
        map.Scoring.ExtensionData?.Count > 0 || map.Scoring != DefaultScoring ||
        !map.ModeCompatibility.SequenceEqual(new[] { GameMode.VanillaPvp, GameMode.Coop, GameMode.BattleRoyale });

    public static string ExportCanonical(ScenarioDefinition map) => JsonSerializer.Serialize(map, Pretty);

    public static string ExportLegacy(ScenarioDefinition map)
    {
        if (HasScenarioMetadata(map)) throw new InvalidOperationException("Use scenario JSON to preserve this map's metadata.");
        var root = JsonSerializer.SerializeToNode(map, ContractJson.Options)!.AsObject();
        var data = root["map"]!.AsObject();
        root["version"] = 3; root["columns"] = data["columns"]!.DeepClone(); root["rows"] = data["rows"]!.DeepClone();
        var hexes = data["hexes"]!.DeepClone().AsArray();
        foreach (var item in hexes)
        {
            var hex = item!.AsObject(); hex["height"] = hex["elevation"]!.DeepClone(); hex.Remove("elevation");
        }
        root["hexes"] = hexes;
        foreach (var key in new[] { "schemaVersion", "map", "modeCompatibility", "placements", "objectives", "scoring" }) root.Remove(key);
        return root.ToJsonString(Pretty);
    }

    private static bool IsLegacyAlias(string terrain) => terrain is "water" or "stone" or "sand" or "forest" or "road";
    private static string LegacyBiome(string terrain) => terrain switch
    {
        "water" or "open-water" or "shallow-water" => "water", "stone" or "stone-path" or "crags" => "stone",
        "sand" or "desert" or "dunes" => "sand", "forest" or "jungle" or "swamp" => "jungle",
        "darkness" or "poison-pits" => "darkness", "lava" or "basalt" => "lava", "ice" or "snow" => "ice", _ => "plains"
    };
}
