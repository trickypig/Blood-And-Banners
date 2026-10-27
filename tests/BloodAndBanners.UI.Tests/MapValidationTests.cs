using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class MapValidationTests
{
    // A side-15 hex map centred on (7, 14): plenty of room, with the Void's ground far to the east.
    private static readonly (int Q, int R) Center = (7, 14);

    private static MapEditorDocument Open()
    {
        var doc = new MapEditorDocument();
        doc.NewHex("Check", 15);
        return doc;
    }

    private static (int Q, int R) At(int dq, int dr) => (Center.Q + dq, Center.R + dr);

    private static void Spawn(MapEditorDocument doc, int count)
    {
        // Every hex 11–14 steps east of the centre is far from any start used below.
        foreach (var hex in doc.Definition.Map.Hexes.Where(h => h.Q - Center.Q >= 11).Take(count).ToList()) doc.ToggleSpawnZone(hex.Q, hex.R);
    }

    /// <summary>
    /// Players each built-in map seats per mode (duel, Void incursion, battle royale). The server's copy of this check
    /// (MapModeCheck.php) asserts the same table in api/tests/MapModeCheckTest.php, so the two cannot drift apart.
    /// </summary>
    [Theory]
    [InlineData("four-corners", 2, 0, 0)]
    [InlineData("home-map", 2, 0, 0)]
    [InlineData("proving-field", 2, 0, 0)]
    [InlineData("training-grounds", 0, 0, 0)]
    [InlineData("last-banner", 0, 0, 0)]
    [InlineData("void-reaches", 0, 4, 0)]
    [InlineData("incursion-twin-roads", 0, 4, 0)]
    [InlineData("incursion-serpents-coil", 0, 4, 0)]
    [InlineData("incursion-the-gorge", 0, 4, 0)]
    [InlineData("incursion-lakeheart", 0, 4, 0)]
    [InlineData("incursion-blighted-march", 0, 4, 0)]
    public void Built_in_maps_match_the_server_check(string file, int duel, int coop, int royale)
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (!File.Exists(Path.Combine(root.FullName, "BloodAndBanners.slnx"))) root = root.Parent!;
        var definition = MapFileCodec.Import(File.ReadAllText(Path.Combine(root.FullName, "api", "scenarios", $"{file}.json")));
        var players = MapValidation.Check(definition).ToDictionary(c => c.Mode, c => c.Players);
        Assert.Equal((duel, coop, royale), (players[GameMode.VanillaPvp], players[GameMode.Coop], players[GameMode.BattleRoyale]));
    }

    private static ModeCheck Check(MapEditorDocument doc, GameMode mode) => MapValidation.Check(doc.Definition).Single(c => c.Mode == mode);

    private static void Ring(MapEditorDocument doc, (int Q, int R) around, int radius, Action<int, int> act)
    {
        foreach (var hex in doc.Definition.Map.Hexes.ToList())
            if (HexMath.Distance($"{hex.Q},{hex.R}", $"{around.Q},{around.R}") == radius) act(hex.Q, hex.R);
    }

    [Fact]
    public void A_blank_map_explains_what_each_mode_is_missing()
    {
        var checks = MapValidation.Check(Open().Definition);

        Assert.All(checks, c => Assert.Equal(0, c.Players));
        Assert.Contains("Place a P1 start; vanilla duel needs one.", checks[0].Issues);
        Assert.Contains("Place a P2 start; vanilla duel needs one.", checks[0].Issues);
        Assert.Contains("Place the Void Portal for the Void Lord.", checks[1].Issues);
        Assert.Contains("Void incursion needs at least 24 Void spawn hexes; this map has 0.", checks[1].Issues);
        Assert.Contains("Battle royale needs at least P1 and P2 starts.", checks[2].Issues);
    }

    [Fact]
    public void A_duel_needs_P1_and_P2_with_room_for_seven_units_each()
    {
        var doc = Open();
        doc.SetPlayerStart(At(-6, 0).Q, At(-6, 0).R, 1);
        doc.SetPlayerStart(At(6, 0).Q, At(6, 0).R, 2);

        var duel = Check(doc, GameMode.VanillaPvp);

        Assert.True(duel.IsValid, string.Join(" ", duel.Issues));
        Assert.Equal(2, duel.Players);
    }

    [Fact]
    public void A_start_with_fewer_than_four_open_neighbours_fails_like_the_game_deployment()
    {
        var doc = Open();
        var p1 = At(-6, 0);
        doc.SetPlayerStart(p1.Q, p1.R, 1);
        doc.SetPlayerStart(At(6, 0).Q, At(6, 0).R, 2);
        // Three neighbours are rock, one lava, one poison pits: one open hex beside the start, though open land lies beyond.
        var neighbours = HexMath.Neighbours($"{p1.Q},{p1.R}").Select(HexMath.Parse).ToList();
        foreach (var n in neighbours.Take(3)) doc.SetObject(n.Q, n.R, "rock");
        doc.Paint(neighbours[3].Q, neighbours[3].R, "lava", "lava");
        doc.Paint(neighbours[4].Q, neighbours[4].R, "darkness", "poison-pits");

        var duel = Check(doc, GameMode.VanillaPvp);

        Assert.Equal(["P1's start has room for 1 unit; a duel needs 7."], duel.Issues);
    }

    [Fact]
    public void Extra_starts_are_ignored_by_a_duel_but_runes_are_fine_and_objectives_must_keep_their_distance()
    {
        var doc = Open();
        doc.SetPlayerStart(At(-6, 0).Q, At(-6, 0).R, 1);
        doc.SetPlayerStart(At(6, 0).Q, At(6, 0).R, 2);
        doc.SetPlayerStart(At(0, 6).Q, At(0, 6).R, 4);
        doc.SetObject(At(-5, 0).Q, At(-5, 0).R, "rune-of-focus");
        doc.SetObject(At(-1, 0).Q, At(-1, 0).R, "shrine");      // 5 from P1: too close
        doc.SetObject(At(0, 0).Q, At(0, 0).R, "health-potion"); // 6 from both: fine

        var duel = Check(doc, GameMode.VanillaPvp);

        Assert.Equal([$"The Shrine at {At(-1, 0).Q}, {At(-1, 0).R} is 5 hexes from P1's start; keep objectives and potions more than 5 hexes away."], duel.Issues);
    }

    [Fact]
    public void Void_incursion_seats_one_hero_player_per_eight_open_hexes_within_three_of_P1()
    {
        var doc = Open();
        doc.SetPlayerStart(Center.Q, Center.R, 1);
        doc.SetVoidPortal(At(13, 0).Q, At(13, 0).R);
        Spawn(doc, 24);

        Assert.Equal(4, Check(doc, GameMode.Coop).Players); // 36 open hexes, capped at four players

        Ring(doc, Center, 3, (q, r) => doc.SetObject(q, r, "tree")); // 18 left within two hexes
        var coop = Check(doc, GameMode.Coop);
        Assert.True(coop.IsValid, string.Join(" ", coop.Issues));
        Assert.Equal(2, coop.Players);

        Ring(doc, Center, 2, (q, r) => doc.SetObject(q, r, "tree")); // 6 left
        Assert.Equal(["P1's start has room for 6 units within 3 hexes; each hero player needs 8."], Check(doc, GameMode.Coop).Issues);
    }

    [Fact]
    public void Void_incursion_needs_twenty_four_spawn_hexes()
    {
        var doc = Open();
        doc.SetPlayerStart(Center.Q, Center.R, 1);
        doc.SetVoidPortal(At(13, 0).Q, At(13, 0).R);
        Spawn(doc, 23);

        Assert.Equal(["Void incursion needs at least 24 Void spawn hexes; this map has 23."], Check(doc, GameMode.Coop).Issues);
    }

    [Fact]
    public void Battle_royale_needs_starts_numbered_in_order_each_with_room_for_eight()
    {
        var doc = Open();
        Spawn(doc, 24);
        doc.SetPlayerStart(At(-6, 0).Q, At(-6, 0).R, 1);
        doc.SetPlayerStart(At(0, 6).Q, At(0, 6).R, 3);

        Assert.Contains("Battle royale starts must be numbered in order; add P2 or renumber the starts.", Check(doc, GameMode.BattleRoyale).Issues);

        doc.SetPlayerStart(At(6, -6).Q, At(6, -6).R, 2);
        var royale = Check(doc, GameMode.BattleRoyale);
        Assert.True(royale.IsValid, string.Join(" ", royale.Issues));
        Assert.Equal(3, royale.Players);
    }

    [Fact]
    public void Stamping_records_only_the_valid_modes_and_their_player_counts_through_a_save_round_trip()
    {
        var doc = Open();
        doc.SetPlayerStart(At(-6, 0).Q, At(-6, 0).R, 1);
        doc.SetPlayerStart(At(6, 0).Q, At(6, 0).R, 2);
        doc.SetPlayerStart(At(0, 6).Q, At(0, 6).R, 3);
        Spawn(doc, 24);

        var stamped = MapValidation.Stamp(doc.Definition, MapValidation.Check(doc.Definition));
        var restored = MapFileCodec.Import(MapFileCodec.ExportCanonical(stamped));

        Assert.Equal([GameMode.VanillaPvp, GameMode.BattleRoyale], restored.ModeCompatibility);
        Assert.Equal(new Dictionary<GameMode, int> { [GameMode.VanillaPvp] = 2, [GameMode.BattleRoyale] = 3 }, restored.PlayerCounts);
        Assert.Contains("\"playerCounts\":{\"vanillaPvp\":2,\"battleRoyale\":3}", JsonSerializer.Serialize(stamped, ContractJson.Options));
    }

    [Fact]
    public void A_map_valid_for_no_mode_still_round_trips()
    {
        var blank = Open().Definition;
        var stamped = MapValidation.Stamp(blank, MapValidation.Check(blank));

        var restored = MapFileCodec.Import(MapFileCodec.ExportCanonical(stamped));

        Assert.Empty(restored.ModeCompatibility);
        Assert.Null(restored.PlayerCounts);
    }
}
