namespace BloodAndBanners.UI.Services;

/// <summary>Axial hex arithmetic on "q,r" keys, matching the server's Hex class.</summary>
public static class HexMath
{
    private static readonly (int Q, int R)[] Directions = [(1, 0), (1, -1), (0, -1), (-1, 0), (-1, 1), (0, 1)];

    public static int Distance(string from, string to)
    {
        var (q1, r1) = Parse(from);
        var (q2, r2) = Parse(to);
        var dq = q2 - q1;
        var dr = r2 - r1;
        return (Math.Abs(dq) + Math.Abs(dr) + Math.Abs(dq + dr)) / 2;
    }

    public static IReadOnlyList<string> Neighbours(string key)
    {
        var (q, r) = Parse(key);
        return Directions.Select(d => $"{q + d.Q},{r + d.R}").ToList();
    }

    public static (int Q, int R)? Direction(string from, string to)
    {
        var (q1, r1) = Parse(from);
        var (q2, r2) = Parse(to);
        var direction = (q2 - q1, r2 - r1);
        return Directions.Contains(direction) ? direction : null;
    }

    /// <summary>Closest direction away at any range; ties follow the server's direction order.</summary>
    public static (int Q, int R)? DirectionAway(string from, string to)
    {
        var (q1, r1) = Parse(from);
        var (q2, r2) = Parse(to);
        var dq = q2 - q1;
        var dr = r2 - r1;
        if (dq == 0 && dr == 0)
        {
            return null;
        }
        (int Q, int R)? best = null;
        var bestScore = int.MinValue;
        foreach (var direction in Directions)
        {
            // Cube-coordinate dot product, matching Forced.directionAwayFrom on the server.
            var score = dq * direction.Q + dr * direction.R + (dq + dr) * (direction.Q + direction.R);
            if (score > bestScore)
            {
                best = direction;
                bestScore = score;
            }
        }
        return best;
    }

    public static string Step(string key, (int Q, int R) direction)
    {
        var (q, r) = Parse(key);
        return $"{q + direction.Q},{r + direction.R}";
    }

    public static (int Q, int R) Parse(string key)
    {
        var parts = key.Split(',');
        return (int.Parse(parts[0], System.Globalization.CultureInfo.InvariantCulture), int.Parse(parts[1], System.Globalization.CultureInfo.InvariantCulture));
    }
}
