namespace BloodAndBanners.UI.Services;

using BloodAndBanners.Contracts;

/// <summary>How screens name seats: the viewer is "You", then a chosen commander name, then the mode's default label.</summary>
public static class SeatNames
{
    public static bool IsVoidLord(GameProjection projection, string? seatId) =>
        seatId is not null && projection.Memberships.Any(member => member.SeatId == seatId && member.Role == "voidLord");

    public static string Display(GameProjection projection, string? seatId)
    {
        if (seatId is null)
        {
            return "A commander";
        }
        if (seatId == projection.Viewer.SeatId)
        {
            return "You";
        }
        var seat = projection.Memberships.FirstOrDefault(member => member.SeatId == seatId);
        var voidLord = IsVoidLord(projection, seatId);
        if (!string.IsNullOrEmpty(seat?.Name))
        {
            return voidLord ? $"{seat.Name} (Void Lord)" : seat.Name;
        }
        if (projection.Mode == GameMode.Coop && voidLord)
        {
            return "Void Lord";
        }
        if (projection.Mode != GameMode.Coop && projection.Viewer.Role == "player")
        {
            return "Rival";
        }
        return seat is null ? "A commander" : $"Commander {seat.SeatIndex}";
    }

    public static string? SideOf(GameProjection projection, string? seatId) =>
        seatId is null
            ? null
            : projection.Sides?.FirstOrDefault(side => side.Value.SeatIds.Contains(seatId)).Key ?? seatId;
}
