using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.Contracts.Tests;

public sealed class MapContractTests
{
    [Fact]
    public void Hex_projection_reads_the_impassable_flag()
    {
        const string json = """{"q":0,"r":0,"biome":"plains","terrain":"grass","elevation":0,"sightBlocker":false,"impassable":true,"occupantId":null,"object":"shrine"}""";
        var shrine = JsonSerializer.Deserialize<HexProjection>(json, ContractJson.Options)!;
        Assert.Equal((false, true), (shrine.SightBlocker, shrine.Impassable));
    }

    [Fact]
    public void Saved_map_roundtrip_preserves_revision_identity_and_unknown_metadata()
    {
        var fixture = File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "fixtures", "saved-map.json"));
        var saved = JsonSerializer.Deserialize<SavedMap>(fixture, ContractJson.Options)!;
        Assert.Equal(2, saved.Summary.Revision);
        Assert.Equal("revision-2", saved.Summary.RevisionId);
        var roundtrip = JsonSerializer.Deserialize<SavedMap>(JsonSerializer.Serialize(saved, ContractJson.Options), ContractJson.Options)!;
        Assert.True(roundtrip.Definition.ExtensionData!["customScenario"].GetProperty("future").GetBoolean());
        Assert.Equal("preserved", roundtrip.Definition.Map.Hexes[0].ExtensionData!["customTile"].GetString());
        Assert.Equal(8, roundtrip.Definition.Map.Hexes[0].Elevation);
    }
}
