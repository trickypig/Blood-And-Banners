using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

namespace BloodAndBanners.UI.Tests;

public sealed class MapEditorDocumentTests
{
    [Fact]
    public void Erased_imported_outlying_cells_remain_editable_after_save_and_reload()
    {
        var doc = new MapEditorDocument();
        doc.Import("""{"version":3,"name":"Outlier","columns":1,"rows":1,"hexes":[{"q":10,"r":10,"biome":"plains","terrain":"grass","height":2}]}""");
        doc.EraseTile(10, 10);
        Assert.Contains((10, 10), doc.Cells);
        doc.Import(MapFileCodec.ExportCanonical(doc.Definition));
        Assert.Contains((10, 10), doc.Cells);
        doc.Paint(10, 10, "plains", "grass");
        var hex = Assert.Single(doc.Definition.Map.Hexes);
        Assert.Equal((10, 10, 0), (hex.Q, hex.R, hex.Elevation));
    }

    [Fact]
    public void Entirely_erased_maps_round_trip_and_only_terrain_can_restore_cells()
    {
        var doc = new MapEditorDocument();
        doc.New("Empty canvas", 5, 5);
        foreach (var cell in doc.Cells.ToList()) doc.EraseTile(cell.Q, cell.R);
        Assert.Empty(doc.Definition.Map.Hexes);
        foreach (var json in new[] { MapFileCodec.ExportCanonical(doc.Definition), MapFileCodec.ExportLegacy(doc.Definition) })
        {
            var restored = new MapEditorDocument();
            restored.Import(json);
            Assert.Empty(restored.Definition.Map.Hexes);
            Assert.Equal(25, restored.Cells.Count());
            restored.AdjustHeight(0, 0, 1);
            restored.SetPlayerStart(0, 0, 1);
            restored.SetObject(0, 0, "tree");
            restored.SetVoidPortal(0, 0);
            Assert.Empty(restored.Definition.Map.Hexes);
            restored.Paint(0, 0, "plains", "grass");
            Assert.Equal(0, Assert.Single(restored.Definition.Map.Hexes).Elevation);
            restored.Paint(999, 999, "plains", "grass");
            Assert.Single(restored.Definition.Map.Hexes);
        }
    }

    [Theory]
    [InlineData(1, 1, 1)]
    [InlineData(2, 7, 3)]
    [InlineData(3, 19, 5)]
    [InlineData(15, 631, 29)]
    public void Hex_maps_have_six_equal_sides_and_preserve_their_shape_on_reload(int side, int count, int extent)
    {
        var doc = new MapEditorDocument();
        doc.NewHex("Hex", side);
        var map = MapFileCodec.Import(MapFileCodec.ExportCanonical(doc.Definition)).Map;
        Assert.Equal(count, map.Hexes.Count);
        Assert.Equal((extent, extent), (map.Columns, map.Rows));
        var qs = map.Hexes.Select(h => h.Q).ToList();
        var rs = map.Hexes.Select(h => h.R).ToList();
        var sums = map.Hexes.Select(h => h.Q + h.R).ToList();
        foreach (var axis in new[] { qs, rs, sums })
        {
            Assert.Equal(extent - 1, axis.Max() - axis.Min());
            Assert.Equal(side, axis.Count(v => v == axis.Min()));
            Assert.Equal(side, axis.Count(v => v == axis.Max()));
        }
    }

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
    public void The_void_portal_moves_and_shares_its_hex_with_nothing()
    {
        var doc = new MapEditorDocument();
        doc.SetObject(0, 0, "tree");
        doc.SetPlayerStart(0, 0, 1);
        doc.SetVoidPortal(0, 0);
        var hex = doc.Definition.Map.Hexes.Single(h => h.Q == 0 && h.R == 0);
        Assert.True(hex.VoidPortal);
        Assert.Null(hex.PlayerStart);
        Assert.Null(hex.Object);
        doc.SetVoidPortal(1, 0);
        Assert.Single(doc.Definition.Map.Hexes, h => h.VoidPortal);
        doc.SetPlayerStart(1, 0, 2);
        Assert.DoesNotContain(doc.Definition.Map.Hexes, h => h.VoidPortal);
        doc.SetVoidPortal(1, 0);
        doc.SetObject(1, 0, "rock");
        Assert.DoesNotContain(doc.Definition.Map.Hexes, h => h.VoidPortal);
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
