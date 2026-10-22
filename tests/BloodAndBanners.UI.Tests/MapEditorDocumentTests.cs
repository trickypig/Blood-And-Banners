using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class MapEditorDocumentTests
{
    [Fact]
    public void Assigning_a_player_start_moves_that_players_existing_marker()
    {
        var doc = new MapEditorDocument();
        doc.SetPlayerStart(0, 0, 1);
        doc.SetPlayerStart(1, 0, 1);
        Assert.Single(doc.Definition.Map.Hexes, h => h.PlayerStart == 1);
        Assert.Equal(1, doc.Definition.Map.Hexes.Single(h => h.Q == 1 && h.R == 0).PlayerStart);
        Assert.Null(doc.Definition.Map.Hexes.Single(h => h.Q == 0 && h.R == 0).PlayerStart);
    }
    [Fact]
    public void Tools_edit_the_document_and_noops_do_not_dirty_it()
    {
        var doc = new MapEditorDocument();
        doc.New("Small", 5, 5);
        var saved = new SavedMap(new("id", "Small", "r1", 1, "2026-10-21T00:00:00Z"), doc.Definition);
        doc.MarkSaved(saved, "owner");
        Assert.False(doc.IsDirty);
        doc.Paint(0, 0, "plains", "grass");
        Assert.False(doc.IsDirty);
        doc.AdjustHeight(0, 0, 1);
        doc.SetPlayerStart(0, 0, 2);
        var hex = doc.Definition.Map.Hexes.First();
        Assert.Equal(1, hex.Elevation);
        Assert.Equal(2, hex.PlayerStart);
        Assert.True(doc.IsDirty);
        doc.ResetHeight(0, 0);
        doc.Clear();
        Assert.Equal(25, doc.Definition.Map.Hexes.Count);
        Assert.All(doc.Definition.Map.Hexes, h => { Assert.Equal(0, h.Elevation); Assert.Null(h.PlayerStart); });
        doc.Rename("Renamed");
        Assert.Equal("Renamed", doc.Definition.Name);
        doc.DetachSavedMap();
        Assert.Null(doc.SavedSummary);
        Assert.Equal(25, doc.Definition.Map.Hexes.Count);
    }

    [Fact]
    public void Import_detaches_database_identity_and_preserves_arbitrary_axial_positions()
    {
        var doc = new MapEditorDocument();
        doc.Import("""{"version":3,"name":"Axial","columns":1,"rows":1,"hexes":[{"q":-3,"r":7,"biome":"plains","terrain":"grass","height":-2}]}""");
        Assert.Equal((-3, 7, -2), (doc.Definition.Map.Hexes[0].Q, doc.Definition.Map.Hexes[0].R, doc.Definition.Map.Hexes[0].Elevation));
        Assert.Null(doc.SavedSummary);
        Assert.True(doc.IsDirty);
    }
}
