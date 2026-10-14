namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Services;

public sealed class SeatNamesTests
{
    private static GameProjection Duel(string role, string? viewerSeat, string? blueName = null) =>
        BattleFixtures.Projection() with
        {
            Viewer = new ViewerPermissions(role, viewerSeat, role == "player", false),
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = blueName },
            ],
        };

    private static GameProjection Coop(string? voidLordName = null, string? heroName = null) =>
        BattleFixtures.Projection() with
        {
            Mode = GameMode.Coop,
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Role = "voidLord", Name = voidLordName },
                new MembershipProjection("seat-green", 3, true, false) { Name = heroName },
            ],
        };

    [Fact]
    public void The_viewer_is_always_you_even_when_named()
    {
        var projection = Duel("player", "seat-blue", blueName: "Lord Ashfang");

        Assert.Equal("You", SeatNames.Display(projection, "seat-blue"));
    }

    [Fact]
    public void Chosen_names_replace_the_default_labels()
    {
        Assert.Equal("Lord Ashfang", SeatNames.Display(Duel("player", "seat-red", "Lord Ashfang"), "seat-blue"));
        Assert.Equal("Lord Ashfang", SeatNames.Display(Duel("spectator", null, "Lord Ashfang"), "seat-blue"));
        Assert.Equal("Seris", SeatNames.Display(Coop(heroName: "Seris"), "seat-green"));
        Assert.Equal("Morrow (Void Lord)", SeatNames.Display(Coop(voidLordName: "Morrow"), "seat-blue"));
    }

    [Fact]
    public void Unnamed_seats_keep_todays_labels()
    {
        Assert.Equal("Rival", SeatNames.Display(Duel("player", "seat-red"), "seat-blue"));
        Assert.Equal("Commander 2", SeatNames.Display(Duel("spectator", null), "seat-blue"));
        Assert.Equal("Void Lord", SeatNames.Display(Coop(), "seat-blue"));
        Assert.Equal("Commander 3", SeatNames.Display(Coop(), "seat-green"));
        Assert.Equal("A commander", SeatNames.Display(Coop(), null));
    }
}
