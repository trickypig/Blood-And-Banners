using System.Text.Json;
using BloodAndBanners.Contracts;

namespace BloodAndBanners.Contracts.Tests;

public sealed class AccountContractTests
{
    [Fact]
    public void Auth_session_carries_the_optional_display_name()
    {
        var session = Read<AuthSession>("auth-session.json");
        Assert.Equal("user", session.Kind);
        Assert.Equal("Ash", session.DisplayName);
        Assert.Null(JsonSerializer.Deserialize<AuthSession>(
            """{"identityId":"g","kind":"guest","accessToken":"a","refreshToken":"r"}""", ContractJson.Options)!.DisplayName);
    }

    [Fact]
    public void Account_games_fixture_reads_modes_lifecycles_and_seats()
    {
        var games = Read<AccountGames>("account-games.json");
        var mine = Assert.Single(games.Yours);
        Assert.Equal(GameMode.VanillaPvp, mine.Mode);
        Assert.Equal(GameLifecycle.Active, mine.Lifecycle);
        Assert.Null(mine.Seats[1].Name);
        Assert.True(mine.Seats[1].IsFriend);
        var theirs = Assert.Single(games.Friends);
        Assert.Equal(GameMode.Coop, theirs.Mode);
        Assert.True(theirs.CanJoin);
    }

    [Fact]
    public void Friends_list_fixture_reads_all_three_lists()
    {
        var list = Read<FriendsList>("friends-list.json");
        Assert.Equal("Fen", Assert.Single(list.Friends).DisplayName);
        Assert.Equal("r1", Assert.Single(list.Incoming).RequestId);
        Assert.Equal("later@example.com", Assert.Single(list.Outgoing).Label);
    }

    [Fact]
    public void Membership_friend_flags_default_to_false()
    {
        var seat = JsonSerializer.Deserialize<MembershipProjection>(
            """{"seatId":"s","seatIndex":1,"ready":false,"isHost":true,"isAccount":true,"friendRequested":true}""", ContractJson.Options)!;
        Assert.True(seat.IsAccount);
        Assert.False(seat.IsFriend);
        Assert.True(seat.FriendRequested);
    }

    private static T Read<T>(string name) =>
        JsonSerializer.Deserialize<T>(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "fixtures", name)), ContractJson.Options)!;
}
