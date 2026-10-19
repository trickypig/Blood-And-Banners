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

    /// <summary>Whether two touches are pinch-zooming the board.</summary>
    public bool IsPinching => _pinch is not null;
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

    /// <summary>
    /// Pans, without zooming, so the <paramref name="focus"/> points are in view, along with the
    /// <paramref name="companions"/> when they all fit. The view stays still while those are already in view.
    /// Otherwise it picks the spot that also shows the most <paramref name="nearby"/> points, keeps the board's
    /// edge at the screen's edge rather than showing empty space past it, and leans towards the focus.
    /// </summary>
    public bool Frame(
        IReadOnlyList<ViewportPoint> focus,
        IReadOnlyList<ViewportPoint>? companions = null,
        IReadOnlyList<ViewportPoint>? nearby = null,
        double margin = 0)
    {
        if (focus.Count == 0)
        {
            return false;
        }
        var halfWidth = ContainerWidth / Scale / 2;
        var halfHeight = ContainerHeight / Scale / 2;
        var inset = Math.Min(Math.Max(0, margin), Math.Min(halfWidth, halfHeight));
        var reachX = halfWidth - inset;
        var reachY = halfHeight - inset;

        // Keep the companions only when they fit on screen together with the focus.
        List<ViewportPoint> kept = [.. focus, .. companions ?? []];
        if (!Fits(kept, reachX, reachY))
        {
            kept = [.. focus];
        }
        if (kept.All(point => Shows(Center, point, reachX, reachY)))
        {
            return false;
        }

        var centroid = new ViewportPoint(focus.Average(point => point.X), focus.Average(point => point.Y));
        if (!Fits(kept, reachX, reachY))
        {
            var (left, right) = BoardRange(BoardBounds.Left, BoardBounds.Right, halfWidth);
            var (top, bottom) = BoardRange(BoardBounds.Top, BoardBounds.Bottom, halfHeight);
            Center = new ViewportPoint(Math.Clamp(centroid.X, left, right), Math.Clamp(centroid.Y, top, bottom));
            return true;
        }

        // Centres that keep every kept point in view, narrowed to centres that keep the board filling the screen.
        var (minX, maxX) = Narrow(kept.Max(point => point.X) - reachX, kept.Min(point => point.X) + reachX, BoardBounds.Left, BoardBounds.Right, halfWidth);
        var (minY, maxY) = Narrow(kept.Max(point => point.Y) - reachY, kept.Min(point => point.Y) + reachY, BoardBounds.Top, BoardBounds.Bottom, halfHeight);
        var ideal = new ViewportPoint(Math.Clamp(centroid.X, minX, maxX), Math.Clamp(centroid.Y, minY, maxY));

        var others = (nearby ?? []).Where(point => !kept.Contains(point)).ToList();
        var xs = Candidates(ideal.X, minX, maxX, others.Select(point => point.X), reachX);
        var ys = Candidates(ideal.Y, minY, maxY, others.Select(point => point.Y), reachY);
        var best = ideal;
        var bestCount = others.Count(point => Shows(ideal, point, reachX, reachY));
        var bestDistance = 0.0;
        foreach (var x in xs)
        {
            foreach (var y in ys)
            {
                var candidate = new ViewportPoint(x, y);
                var count = others.Count(point => Shows(candidate, point, reachX, reachY));
                var distance = Distance(candidate, ideal);
                if (count > bestCount || (count == bestCount && distance < bestDistance))
                {
                    (best, bestCount, bestDistance) = (candidate, count, distance);
                }
            }
        }
        Center = best;
        return true;
    }

    private static bool Fits(IReadOnlyList<ViewportPoint> points, double reachX, double reachY) =>
        points.Max(point => point.X) - points.Min(point => point.X) <= 2 * reachX
        && points.Max(point => point.Y) - points.Min(point => point.Y) <= 2 * reachY;

    private static bool Shows(ViewportPoint center, ViewportPoint point, double reachX, double reachY) =>
        Math.Abs(point.X - center.X) <= reachX + 1e-6 && Math.Abs(point.Y - center.Y) <= reachY + 1e-6;

    // Centres along one axis that keep the board's edges at or past the screen's edges; a board narrower
    // than the screen is centred instead.
    private static (double Min, double Max) BoardRange(double boardMin, double boardMax, double half) =>
        boardMax - boardMin >= 2 * half
            ? (boardMin + half, boardMax - half)
            : ((boardMin + boardMax) / 2, (boardMin + boardMax) / 2);

    // The visibility range narrowed to the board range, or left as it is when the two do not overlap.
    private static (double Min, double Max) Narrow(double min, double max, double boardMin, double boardMax, double half)
    {
        var (lower, upper) = BoardRange(boardMin, boardMax, half);
        var narrowedMin = Math.Max(min, lower);
        var narrowedMax = Math.Min(max, upper);
        return narrowedMin <= narrowedMax ? (narrowedMin, narrowedMax) : (min, max);
    }

    // The ideal spot, the range ends, and every centre where a nearby point enters or leaves the view.
    private static List<double> Candidates(double ideal, double min, double max, IEnumerable<double> others, double reach)
    {
        List<double> values = [ideal, min, max];
        foreach (var value in others)
        {
            values.Add(value - reach);
            values.Add(value + reach);
        }
        return values.Select(value => Math.Clamp(value, min, max)).Distinct().ToList();
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
