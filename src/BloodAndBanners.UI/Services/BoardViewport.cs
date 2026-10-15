namespace BloodAndBanners.UI.Services;

public readonly record struct ViewportPoint(double X, double Y);

public readonly record struct WorldRect(double X, double Y, double Width, double Height)
{
    public double Left => X;
    public double Top => Y;
    public double Right => X + Width;
    public double Bottom => Y + Height;

    public bool Contains(ViewportPoint point) =>
        point.X >= Left && point.X <= Right && point.Y >= Top && point.Y <= Bottom;
}

/// <summary>Pure board-view and pointer-gesture state. DOM measurement and pointer capture stay outside this type.</summary>
public sealed class BoardViewport
{
    public const double MinScale = .2;
    public const double MaxScale = 3;
    public const double DragThreshold = 6;

    private sealed record PointerState(
        ViewportPoint Start,
        ViewportPoint Previous,
        ViewportPoint Current,
        bool IsTouch,
        bool CanPan);

    private sealed record PinchState(
        long FirstId,
        long SecondId,
        double InitialDistance,
        double InitialScale,
        ViewportPoint WorldAtMidpoint);

    private readonly Dictionary<long, PointerState> _pointers = [];
    private PinchState? _pinch;
    private string? _gameId;

    public ViewportPoint Center { get; private set; }
    public double Scale { get; private set; } = 1;
    public double ContainerWidth { get; private set; } = 800;
    public double ContainerHeight { get; private set; } = 600;
    public WorldRect BoardBounds { get; private set; } = new(0, 0, 800, 600);
    public bool SuppressActivation { get; private set; }
    public double MinimumScale => Math.Min(MinScale, FitScale());

    public WorldRect VisibleWorld => new(
        Center.X - ContainerWidth / Scale / 2,
        Center.Y - ContainerHeight / Scale / 2,
        ContainerWidth / Scale,
        ContainerHeight / Scale);

    public void ResetForGame(string gameId, WorldRect boardBounds, double containerWidth, double containerHeight)
    {
        BoardBounds = boardBounds;
        ContainerWidth = Math.Max(1, containerWidth);
        ContainerHeight = Math.Max(1, containerHeight);
        if (_gameId == gameId)
        {
            return;
        }

        _gameId = gameId;
        _pointers.Clear();
        _pinch = null;
        SuppressActivation = false;
        Fit();
    }

    public void Resize(double containerWidth, double containerHeight)
    {
        ContainerWidth = Math.Max(1, containerWidth);
        ContainerHeight = Math.Max(1, containerHeight);
    }

    public void Fit(double padding = 24)
    {
        Scale = Math.Min(FitScale(padding), MaxScale);
        Center = new ViewportPoint(
            BoardBounds.Left + BoardBounds.Width / 2,
            BoardBounds.Top + BoardBounds.Height / 2);
    }

    public void ZoomAt(ViewportPoint screenPoint, double factor)
    {
        if (!double.IsFinite(factor) || factor <= 0)
        {
            return;
        }

        var anchoredWorld = ScreenToWorld(screenPoint);
        Scale = Math.Clamp(Scale * factor, MinimumScale, MaxScale);
        Center = CenterForAnchor(anchoredWorld, screenPoint, Scale);
    }

    public void PanBy(double screenDeltaX, double screenDeltaY)
    {
        Center = new ViewportPoint(
            Center.X - screenDeltaX / Scale,
            Center.Y - screenDeltaY / Scale);
    }

    public void BeginPointer(long pointerId, ViewportPoint point, bool isTouch, bool canPan)
    {
        if (_pointers.Count == 0)
        {
            SuppressActivation = false;
        }
        _pointers[pointerId] = new PointerState(point, point, point, isTouch, canPan);

        var touches = _pointers.Where(entry => entry.Value.IsTouch).Take(2).ToArray();
        if (touches.Length == 2)
        {
            var midpoint = Midpoint(touches[0].Value.Current, touches[1].Value.Current);
            _pinch = new PinchState(
                touches[0].Key,
                touches[1].Key,
                Distance(touches[0].Value.Current, touches[1].Value.Current),
                Scale,
                ScreenToWorld(midpoint));
            SuppressActivation = true;
        }
    }

    public void MovePointer(long pointerId, ViewportPoint point)
    {
        if (!_pointers.TryGetValue(pointerId, out var pointer))
        {
            return;
        }
        _pointers[pointerId] = pointer with { Previous = pointer.Current, Current = point };

        if (_pinch is { } pinch &&
            _pointers.TryGetValue(pinch.FirstId, out var first) &&
            _pointers.TryGetValue(pinch.SecondId, out var second))
        {
            var midpoint = Midpoint(first.Current, second.Current);
            var distance = Distance(first.Current, second.Current);
            var factor = pinch.InitialDistance <= 0 ? 1 : distance / pinch.InitialDistance;
            Scale = Math.Clamp(pinch.InitialScale * factor, MinimumScale, MaxScale);
            Center = CenterForAnchor(pinch.WorldAtMidpoint, midpoint, Scale);
            SuppressActivation = true;
            return;
        }

        var totalDistance = Distance(pointer.Start, point);
        if (totalDistance < DragThreshold && !SuppressActivation)
        {
            return;
        }

        var delta = SuppressActivation
            ? new ViewportPoint(point.X - pointer.Current.X, point.Y - pointer.Current.Y)
            : new ViewportPoint(point.X - pointer.Start.X, point.Y - pointer.Start.Y);
        SuppressActivation = true;
        if (pointer.CanPan)
        {
            PanBy(delta.X, delta.Y);
        }
    }

    public void EndPointer(long pointerId)
    {
        _pointers.Remove(pointerId);
        if (_pinch is not null)
        {
            if (_pointers.Count < 2)
            {
                _pinch = null;
            }
            if (_pointers.Count == 1)
            {
                var remaining = _pointers.Single();
                _pointers[remaining.Key] = remaining.Value with
                {
                    Start = remaining.Value.Current,
                    Previous = remaining.Value.Current,
                    CanPan = false,
                };
            }
        }
    }

    public bool ConsumeSuppressedActivation()
    {
        if (_pointers.Count > 0 || !SuppressActivation)
        {
            return false;
        }
        SuppressActivation = false;
        return true;
    }

    /// <summary>Centres on the focus points unless one already sits at least <paramref name="margin"/> world units inside the view.</summary>
    public bool EnsureVisible(IReadOnlyList<ViewportPoint> focusPoints, double margin = 0)
    {
        var visible = VisibleWorld;
        var inset = Math.Min(Math.Max(0, margin), Math.Min(visible.Width, visible.Height) / 2);
        var safe = new WorldRect(visible.X + inset, visible.Y + inset, visible.Width - 2 * inset, visible.Height - 2 * inset);
        if (focusPoints.Count == 0 || focusPoints.Any(safe.Contains))
        {
            return false;
        }
        Center = new ViewportPoint(focusPoints.Average(point => point.X), focusPoints.Average(point => point.Y));
        return true;
    }

    public ViewportPoint ScreenToWorld(ViewportPoint screenPoint)
    {
        var visible = VisibleWorld;
        return new ViewportPoint(
            visible.Left + screenPoint.X / Scale,
            visible.Top + screenPoint.Y / Scale);
    }

    private double FitScale(double padding = 24)
    {
        var usableWidth = Math.Max(1, ContainerWidth - 2 * Math.Max(0, padding));
        var usableHeight = Math.Max(1, ContainerHeight - 2 * Math.Max(0, padding));
        var widthScale = BoardBounds.Width <= 0 ? MaxScale : usableWidth / BoardBounds.Width;
        var heightScale = BoardBounds.Height <= 0 ? MaxScale : usableHeight / BoardBounds.Height;
        return Math.Min(widthScale, heightScale);
    }

    private ViewportPoint CenterForAnchor(ViewportPoint worldPoint, ViewportPoint screenPoint, double scale) => new(
        worldPoint.X - (screenPoint.X - ContainerWidth / 2) / scale,
        worldPoint.Y - (screenPoint.Y - ContainerHeight / 2) / scale);

    private static ViewportPoint Midpoint(ViewportPoint first, ViewportPoint second) =>
        new((first.X + second.X) / 2, (first.Y + second.Y) / 2);

    private static double Distance(ViewportPoint first, ViewportPoint second) =>
        Math.Sqrt(Math.Pow(second.X - first.X, 2) + Math.Pow(second.Y - first.Y, 2));
}
