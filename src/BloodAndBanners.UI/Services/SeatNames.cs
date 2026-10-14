namespace BloodAndBanners.UI.Services;

using BloodAndBanners.Contracts;

/// <summary>How Co-op screens name seats: the viewer is "You", the Void Lord by role, hero players by banner number.</summary>
public static class SeatNames
{
    public static bool IsVoidLord(GameProjection projection, string? seatId) =>
        seatId is not null && projection.Memberships.Any(member => member.SeatId == seatId && member.Role == "voidLord");

    public static string Coop(GameProjection projection, string? seatId)
    {
        if (seatId is null)
        {
            return "A commander";
        }
        if (seatId == projection.Viewer.SeatId)
        {
            return "You";
        }
        if (IsVoidLord(projection, seatId))
        {
            return "Void Lord";
        }
        var seat = projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        return seat is null ? "A commander" : $"Commander {seat.SeatIndex}";
    }

    public static string? SideOf(GameProjection projection, string? seatId) =>
        seatId is null
            ? null
            : projection.Sides?.FirstOrDefault(side => side.Value.SeatIds.Contains(seatId)).Key ?? seatId;
}
