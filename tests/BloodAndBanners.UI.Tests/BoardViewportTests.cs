namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.UI.Services;

public sealed class BoardViewportTests
{
    [Fact]
    public void Fit_contains_the_board_with_screen_padding()
    {
        var viewport = new BoardViewport();

        viewport.ResetForGame("game-a", new WorldRect(0, 0, 1000, 500), 500, 400);
        viewport.Fit(20);

        Assert.Equal(.46, viewport.Scale, 3);
        Assert.True(viewport.VisibleWorld.Left <= 0);
        Assert.True(viewport.VisibleWorld.Right >= 1000);
        Assert.True(viewport.VisibleWorld.Top <= 0);
        Assert.True(viewport.VisibleWorld.Bottom >= 500);
    }

    [Fact]
    public void Fit_can_zoom_below_the_interaction_floor_to_contain_Home_Map_on_mobile()
    {
        var viewport = new BoardViewport();

        viewport.ResetForGame("home-map", new WorldRect(0, 0, 2042, 3622), 375, 667);

        Assert.True(viewport.Scale < BoardViewport.MinScale);
        Assert.True(viewport.VisibleWorld.Contains(new ViewportPoint(0, 0)));
        Assert.True(viewport.VisibleWorld.Contains(new ViewportPoint(2042, 3622)));
        Assert.Equal(viewport.Scale, viewport.MinimumScale, 6);

        viewport.ZoomAt(new ViewportPoint(187.5, 333.5), .01);
        Assert.Equal(viewport.MinimumScale, viewport.Scale, 6);
    }

    [Fact]
    public void Zoom_preserves_the_world_point_under_the_cursor_and_clamps()
    {
        var viewport = ReadyViewport();
        var cursor = new ViewportPoint(120, 90);
        var before = viewport.ScreenToWorld(cursor);

        viewport.ZoomAt(cursor, 1.75);

        AssertPoint(before, viewport.ScreenToWorld(cursor));
        viewport.ZoomAt(cursor, 100);
        Assert.Equal(BoardViewport.MaxScale, viewport.Scale);
        viewport.ZoomAt(cursor, .0001);
        Assert.Equal(BoardViewport.MinScale, viewport.Scale);
    }

    [Fact]
    public void Pan_converts_screen_delta_by_scale()
    {
        var viewport = ReadyViewport();
        var startX = viewport.Center.X;
        var scale = viewport.Scale;

        viewport.PanBy(42, -21);

        Assert.Equal(startX - 42 / scale, viewport.Center.X, 6);
    }

    [Fact]
    public void Pinch_keeps_the_initial_world_point_under_the_moving_midpoint()
    {
        var viewport = ReadyViewport();
        viewport.BeginPointer(1, new ViewportPoint(100, 100), isTouch: true, canPan: true);
        viewport.BeginPointer(2, new ViewportPoint(200, 100), isTouch: true, canPan: true);
        var worldAtMidpoint = viewport.ScreenToWorld(new ViewportPoint(150, 100));

        viewport.MovePointer(2, new ViewportPoint(260, 100));

        Assert.True(viewport.SuppressActivation);
        AssertPoint(worldAtMidpoint, viewport.ScreenToWorld(new ViewportPoint(180, 100)));
    }

    [Fact]
    public void Drag_threshold_and_suppression_latch_distinguish_click_from_pan()
    {
        var viewport = ReadyViewport();
        viewport.BeginPointer(1, new ViewportPoint(10, 10), isTouch: false, canPan: true);
        viewport.MovePointer(1, new ViewportPoint(15.9, 10));
        viewport.EndPointer(1);
        Assert.False(viewport.ConsumeSuppressedActivation());

        viewport.BeginPointer(2, new ViewportPoint(10, 10), isTouch: false, canPan: true);
        viewport.MovePointer(2, new ViewportPoint(16, 10));
        viewport.EndPointer(2);
        Assert.True(viewport.ConsumeSuppressedActivation());
        Assert.False(viewport.ConsumeSuppressedActivation());
    }

    [Fact]
    public void Ensure_visible_moves_only_when_every_focus_point_is_outside()
    {
        var viewport = ReadyViewport();
        var initial = viewport.Center;

        viewport.EnsureVisible([initial, new ViewportPoint(5000, 5000)]);
        Assert.Equal(initial, viewport.Center);

        viewport.EnsureVisible([new ViewportPoint(2000, 1800), new ViewportPoint(2200, 2000)]);
        Assert.Equal(new ViewportPoint(2100, 1900), viewport.Center);
    }

    [Fact]
    public void Only_a_new_game_resets_a_manually_changed_view_to_fit()
    {
        var viewport = ReadyViewport();
        viewport.PanBy(80, 20);
        var changed = viewport.Center;

        viewport.ResetForGame("game-a", new WorldRect(0, 0, 800, 600), 600, 400);
        Assert.Equal(changed, viewport.Center);

        viewport.ResetForGame("game-b", new WorldRect(100, 200, 800, 600), 600, 400);
        Assert.Equal(new ViewportPoint(500, 500), viewport.Center);
    }

    private static BoardViewport ReadyViewport()
    {
        var viewport = new BoardViewport();
        viewport.ResetForGame("game-a", new WorldRect(0, 0, 800, 600), 600, 400);
        return viewport;
    }

    private static void AssertPoint(ViewportPoint expected, ViewportPoint actual)
    {
        Assert.Equal(expected.X, actual.X, 6);
        Assert.Equal(expected.Y, actual.Y, 6);
    }
}
