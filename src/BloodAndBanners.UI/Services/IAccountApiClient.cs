using BloodAndBanners.Contracts;

namespace BloodAndBanners.UI.Services;

public interface IAccountApiClient
{
    Task StartEmailSignInAsync(string email, CancellationToken cancellationToken = default);
    /// <summary>Trades an emailed code for a session; a guest identity brings its current seat along.</summary>
    Task<AuthSession> VerifyEmailCodeAsync(string email, string code, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default);
    Task<AuthSession> VerifyEmailLinkAsync(string token, string deviceId, AuthSession? guest, CancellationToken cancellationToken = default);
    Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default);
    Task<AccountProfile> UpdateDisplayNameAsync(AuthSession session, string displayName, CancellationToken cancellationToken = default);
    Task<AccountGames> GetGamesAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<GameSeatSession> ResumeGameAsync(AuthSession session, string gameId, CancellationToken cancellationToken = default);
    Task<FriendsList> GetFriendsAsync(AuthSession session, CancellationToken cancellationToken = default);
    Task<FriendsList> RequestFriendAsync(AuthSession session, string email, CancellationToken cancellationToken = default);
    Task<FriendsList> RequestFriendFromSeatAsync(AuthSession session, string gameId, string seatId, CancellationToken cancellationToken = default);
    Task<FriendsList> AcceptFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default);
    Task<FriendsList> DeclineFriendAsync(AuthSession session, string requestId, CancellationToken cancellationToken = default);
    Task<FriendsList> RemoveFriendAsync(AuthSession session, string userId, CancellationToken cancellationToken = default);
}
