using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class MapFileCodecTests
{
    internal const string Legacy = """{"name":"Crossing","columns":2,"rows":1,"hexes":[{"col":1,"row":0,"biome":"plains","terrain":"grass","height":2,"playerStart":1,"portal":true,"customTile":"keep"}],"customMap":42}""";

    [Fact]
    public void Offset_import_and_axial_export_preserve_height_starts_and_extra_fields()
    {
        var map = MapFileCodec.Import(Legacy);
        var hex = Assert.Single(map.Map.Hexes);
        Assert.Equal((0, 1, 2, 1), (hex.Q, hex.R, hex.Elevation, hex.PlayerStart));
        Assert.True(hex.Portal);
        using var json = JsonDocument.Parse(MapFileCodec.ExportLegacy(map));
        Assert.Equal(3, json.RootElement.GetProperty("version").GetInt32());
        var exported = json.RootElement.GetProperty("hexes")[0];
        Assert.False(exported.TryGetProperty("col", out _));
        Assert.Equal(2, exported.GetProperty("height").GetInt32());
        Assert.Equal("keep", exported.GetProperty("customTile").GetString());
        Assert.Equal(42, json.RootElement.GetProperty("customMap").GetInt32());
    }

    [Fact]
    public void The_void_portal_round_trips_and_a_map_holds_at_most_one()
    {
        var doc = new MapEditorDocument();
        doc.SetVoidPortal(0, 0);
        var json = MapFileCodec.ExportCanonical(doc.Definition);
        Assert.Single(System.Text.RegularExpressions.Regex.Matches(json, "\"voidPortal\""));
        var map = MapFileCodec.Import(json);
        Assert.True(map.Map.Hexes.Single(h => h.Q == 0 && h.R == 0).VoidPortal);
        var two = map with { Map = map.Map with { Hexes = map.Map.Hexes.Select(h => h with { VoidPortal = true }).ToList() } };
        Assert.Throws<FormatException>(() => MapFileCodec.Validate(two));
        Assert.Throws<FormatException>(() => MapFileCodec.Import(json.Replace("\"voidPortal\": true", "\"voidPortal\": \"yes\"")));
    }

    [Fact]
    public void Canonical_metadata_survives_editing_and_export()
    {
        var map = MapFileCodec.Import(Legacy);
        var node = System.Text.Json.Nodes.JsonNode.Parse(MapFileCodec.ExportCanonical(map))!;
        node["placements"] = System.Text.Json.Nodes.JsonNode.Parse("""[{"kind":"future-piece"}]""");
        node["scoring"]!["target"] = 17;
        var document = new MapEditorDocument();
        document.Import(node.ToJsonString());
        document.Paint(0, 1, "lava", "basalt");
        Assert.True(document.HasScenarioMetadata);
        Assert.Throws<InvalidOperationException>(() => MapFileCodec.ExportLegacy(document.Definition));
        var reloaded = MapFileCodec.Import(MapFileCodec.ExportCanonical(document.Definition));
        Assert.Equal(17, reloaded.Scoring.Target);
        Assert.Equal("future-piece", reloaded.Placements[0].GetProperty("kind").GetString());
        Assert.Equal("keep", reloaded.Map.Hexes[0].ExtensionData!["customTile"].GetString());
        Assert.Equal("basalt", reloaded.Map.Hexes[0].Terrain);
    }

    [Theory]
    [InlineData("""{"columns":1,"rows":1,"hexes":[{"q":0}]}""")]
    [InlineData("""{"version":99,"columns":1,"rows":1,"hexes":[]}""")]
    [InlineData("""{"columns":1,"rows":1,"hexes":[{"q":"0","r":0}]}""")]
    public void Malformed_files_are_rejected(string json) => Assert.ThrowsAny<Exception>(() => MapFileCodec.Import(json));

    [Fact]
    public void Failed_import_keeps_document_and_saved_association()
    {
        var doc = new MapEditorDocument();
        doc.Import(Legacy);
        var previous = doc.Definition;
        Assert.ThrowsAny<Exception>(() => doc.Import("invalid"));
        Assert.Same(previous, doc.Definition);
    }

    [Theory]
    [InlineData("q", null)]
    [InlineData("portal", "false")]
    public void Canonical_import_rejects_missing_coordinates_and_coerced_flags(string field, string? value)
    {
        var node = System.Text.Json.Nodes.JsonNode.Parse(MapFileCodec.ExportCanonical(MapFileCodec.Import(Legacy)))!;
        var hex = node["map"]!["hexes"]![0]!.AsObject();
        if (value is null) hex.Remove(field); else hex[field] = value;
        Assert.ThrowsAny<Exception>(() => MapFileCodec.Import(node.ToJsonString()));
    }

    [Fact]
    public void Canonical_fields_that_collide_with_legacy_keys_require_canonical_export()
    {
        var node = System.Text.Json.Nodes.JsonNode.Parse(MapFileCodec.ExportCanonical(MapFileCodec.Import(Legacy)))!;
        node["version"] = 42;
        node["map"]!["hexes"]![0]!["height"] = "custom-height-metadata";
        var document = new MapEditorDocument();
        document.Import(node.ToJsonString());
        Assert.True(document.HasScenarioMetadata);
        Assert.Throws<InvalidOperationException>(() => MapFileCodec.ExportLegacy(document.Definition));
        using var exported = JsonDocument.Parse(MapFileCodec.ExportCanonical(document.Definition));
        Assert.Equal(42, exported.RootElement.GetProperty("version").GetInt32());
        Assert.Equal("custom-height-metadata", exported.RootElement.GetProperty("map").GetProperty("hexes")[0].GetProperty("height").GetString());
    }
}
