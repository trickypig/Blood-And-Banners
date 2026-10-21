# Accounts and friends — design

Date: 2026-10-21
Status: approved in conversation; awaiting written-spec review

## Goal

Let players sign in with their email address, friend each other, and see their own and their friends' unfinished games on the start page. Signing in is optional: guests keep creating, joining by code or QR, and watching games exactly as they do today.

## Decisions

- **Sign-in is passwordless.** It uses an emailed magic link that also carries a 6-digit code. Anyone can sign in with any address they can receive mail at, and the first sign-in creates the account.
- **Mail is sent over IONOS SMTP with PHPMailer.** The settings are `smtp.ionos.com:465`, implicit TLS, sending from `noreply@trickypig.com`. PHPMailer is vendored, not installed with Composer.
- **The account is the game identity.** A signed-in player's `users.id` is the `identity_id` on `game_memberships`. Guests keep their per-game guest identities.
- **Signing in while seated as a guest moves that seat to the account**, through the existing `GameService::transferIdentity`.
- **Friend requests go to an email address or come from a shared game.** Both sides must agree: the other user accepts, or sends their own request, which auto-accepts.
- **A request to an address with no account stays pending, and no email is sent.** It attaches to the account when that address first signs in.
- **The start page shows "Your games" (Resume) and "Friends' games".** A friend's lobby with an open seat offers Join; any other unfinished friend game offers Watch. Completed games are hidden.
- **Every game is visible to all of the host's friends.** There is no private-lobby toggle.

## Out of scope

- Passwords, password reset, and the existing `auth/login` password route. The route stays as it is; the client never calls it.
- Emailing invites to people who have no account.
- Push or real-time notifications. The start page polls.
- Profile pages, avatars, blocking users, and searching for users by name.
- Showing an email address to anyone except its owner and the person who typed it into a request.

## API

### Schema

Migration `005_accounts` comes in `.mysql.sql` and `.sqlite.sql` versions.

- **Magic-link users get an empty `password_hash`.** `users.password_hash` stays `NOT NULL` so SQLite needs no table rebuild, and they store `''` there; `password_verify` never matches an empty hash. `display_name` may be `''` until the user sets it.
- `login_challenges` table:
  - Columns: `id`, `email`, `code_hash`, `token_hash UNIQUE`, `attempts INTEGER DEFAULT 0`, `client_ip`, `expires_at`, `consumed_at NULL`, `created_at`.
  - Index on `(email, created_at)` and on `(client_ip, created_at)`.
- `friendships` table:
  - Columns: `id`, `requester_user_id`, `addressee_user_id NULL`, `addressee_email`, `status` (`'pending'` or `'accepted'`), `from_game` (0/1, whether the request came from a shared game), `created_at`, `responded_at NULL`.
  - Unique on `(requester_user_id, addressee_email)`, plus indexes on `addressee_user_id` and `addressee_email`.
  - `addressee_email` is always stored normalized (trimmed and lowercased).
  - A pair has at most one row: before inserting, the service checks for a row in either direction.

### Mail

- `BloodAndBanners\Mail\Mailer` is an interface with `send(string $to, string $subject, string $text, string $html): void`.
- `SmtpMailer` wraps PHPMailer. It uses SMTP auth with `SMTPSecure = ENCRYPTION_SMTPS`, port 465, and a 10-second timeout.
- `LogMailer` appends each message to `api/var/mail.log`. It is used whenever `SMTP_HOST` is empty, which covers local development and tests.
- New `AppConfig` keys, all optional:
  - `SMTP_HOST`, `SMTP_PORT` (default 465), `SMTP_USER`, `SMTP_PASS`.
  - `MAIL_FROM` (default `noreply@trickypig.com`).
  - `APP_PUBLIC_URL`, the game's public root used in links (default `https://blood.trickypig.com/play`).
- The password exists only in the server's config file.
- PHPMailer's `src/PHPMailer.php`, `SMTP.php` and `Exception.php`, plus its LICENSE, are vendored under `api/src/Vendor/PHPMailer/`, so the release build copies them with the rest of `src`. `autoload.php` maps the `PHPMailer\PHPMailer\` namespace to that folder.
- A send failure is logged server-side. The client still gets the normal `202`, but `auth/email/start` is not counted as successful for rate-limit purposes.

### Sign-in routes (no auth required)

`POST /api/v1/auth/email/start {email}`
- The address is validated with `FILTER_VALIDATE_EMAIL` after normalizing.
- **Rate limits:** 3 challenges per email per 15 minutes and 20 per IP per hour. Over the limit, the route returns `429 tooManyRequests`.
- Otherwise it creates a challenge with a random 6-digit code and a random 32-byte token. Both are stored as SHA-256 hashes, and the challenge expires after 15 minutes.
- It mails "Your Blood & Banners sign-in code: 123456", with the link `{APP_PUBLIC_URL}/?signin=…` (same query-parameter style as `?join=` and `?watch=`).
- It always responds `202 {}` for a valid address, whether or not an account exists.
- `Request` gains `clientIp`, taken from `REMOTE_ADDR`.

`POST /api/v1/auth/email/verify {email, code, deviceId}` or `{token, deviceId}`
- **Code path:** looks up the newest unconsumed, unexpired challenge for the email.
  - A wrong code increments `attempts`.
  - At 5 attempts the challenge is consumed (dead).
  - Either failure returns `400 invalidCode`.
- **Token path:** looks up by `token_hash` and returns `400 invalidCode` when it is missing, expired or consumed.
- **On success:**
  - It consumes the challenge and finds or creates the `users` row for the email.
  - It claims pending friendships: rows with that `addressee_email` and a null `addressee_user_id` get `addressee_user_id` set.
  - It issues the existing access/refresh pair with `kind:'user'`.
- **Guest carry-over:** if the request also carries a valid guest bearer token, it runs `transferIdentity(guestId, userId)` and marks the guest `converted_user_id`, all in the same transaction.
- **Response:** `{identityId, kind, accessToken, refreshToken, displayName}`.

`POST /api/v1/auth/logout {refreshToken}` revokes that refresh token and returns `204`.

### Account routes (require `kind:'user'`; otherwise `403 accountRequired`)

- `GET /api/v1/account` returns `{userId, email, displayName}`.
- `PUT /api/v1/account {displayName}` trims the name, which must be 1–24 characters (matching the commander-name rules), and returns the account.
- `GET /api/v1/account/games` returns `{yours: [...], friends: [...]}`. Each row has this shape:

  ```json
  { "gameId": "…", "mode": "vanilla", "scenarioName": "…", "lifecycle": "lobby",
    "seats": [{ "seatIndex": 0, "name": "…", "isFriend": true }],
    "canJoin": true, "updatedAt": "…" }
  ```

  - `yours` lists games where you hold a seat and the lifecycle isn't `completed`, newest `updated_at` first.
  - `friends` lists games that aren't completed, where an accepted friend holds a seat and you don't. `canJoin` is true when the lifecycle is `lobby` and a seat is open. Results are capped at 20, newest first.
- `POST /api/v1/games/{id}/resume`:
  - It requires a `user` principal with a membership in the game.
  - It writes a new random seat token to `seat_token_hash`, which invalidates the old one on other devices, and returns the same seat-session shape as `join`.
  - Not being a member returns `404 gameNotFound`.

### Friends routes (require `kind:'user'`)

- `GET /api/v1/friends` returns:

  ```json
  { "friends":  [{ "userId": "…", "displayName": "…" }],
    "incoming": [{ "requestId": "…", "displayName": "…" }],
    "outgoing": [{ "requestId": "…", "label": "name or email" }] }
  ```

  The `label` on an outgoing request is the other person's display name if they have an account and their user ID was already known to the requester (the request came from a game). Otherwise it is the email the requester typed.
- `POST /api/v1/friends/requests` takes `{email}` or `{gameId, seatId}`.
  - **The game form** requires both users to hold seats in that game and the target seat to belong to a `user` identity. Otherwise it returns `400 cannotFriendSeat`. The requester is never shown the target's email address.
  - **Errors:** your own address or ID returns `400 cannotFriendSelf`. Already being friends returns `409 alreadyFriends`. An existing outgoing request returns `409 alreadyRequested`.
  - **Mutual request:** if the other user already has a pending request to you, that row becomes `accepted` and no new row is inserted.
  - **Success** returns the updated `GET /friends` body, which the mutual case also returns.
- `POST /api/v1/friends/requests/{id}/accept` works only for the addressee and returns the updated friends body.
- `POST /api/v1/friends/requests/{id}/decline` deletes the row. It works for the addressee, or for the requester as a cancel.
- `DELETE /api/v1/friends/{userId}` deletes the accepted row.

### Game projection

Each seat in the game projection gains `isAccount: bool`. When the viewer is a signed-in user, it also gains `isFriend: bool` and `friendRequested: bool`, which drive the Add friend button. Commander-name rules are unchanged, except that joining or creating as a user with a display name pre-sets the seat's `commander_name` to it.

## Client

### Contracts

New DTOs in `BloodAndBanners.Contracts`, each with golden JSON fixtures in `tests/fixtures/`:
- `AccountProfile`, `EmailSignInStart`, `EmailSignInVerify`.
- `FriendsList` (with `Friend`, `IncomingFriendRequest`, `OutgoingFriendRequest`).
- `FriendRequestCreate`.
- `AccountGames` (with `AccountGameRow`, `AccountGameSeat`).

`AuthSession` gains an optional `DisplayName`, and seat projections gain `IsAccount`, `IsFriend` and `FriendRequested`.

### Session model

- **`IAccountStore` / `JsonAccountStore`** is stored under key `bloodAndBanners.account` in `IKeyValueStore`. It holds the user's `AuthSession`, separately from `StoredGameSession`.
- **Coordinator identity choice.** `GameSessionCoordinator` create and join use the account identity when one is stored. It refreshes the account's access token when needed, through the existing `auth/refresh`, and saves rotated tokens back to the store. Without an account it falls back to `CreateGuestAsync`, as today.
- **Resuming** a game from "Your games" calls `resume`, then saves the returned seat token as the current `StoredGameSession`.
- **Signing out** calls `logout` and clears the account store. The current game session is left alone; it still works if the seat token is valid, because a seat belongs to the identity, not to a device.
- **A failed refresh** (`invalidRefreshToken`) clears the account store and drops back to the signed-out start page.

### Sign-in UI

- An **account chip** at the top of the start page shows **Sign in** when signed out. When signed in, it shows the display name and a menu with Sign out.
- The **SignIn** component runs in steps:
  1. Email field → "Send code".
  2. A 6-digit code field, with "Use a different email" and "Resend" (disabled for 30 s).
  3. If the account has no display name yet, a name field.
  4. Done.
- Server errors map to inline text: `invalidCode`, `tooManyRequests`, and a generic fallback.
- **The `?signin=…` link** is read by the web and MAUI home pages, like `?join=`. The token is verified on load, a short result is shown, and the parameter is dropped from the address. Android App Links already claim `blood.trickypig.com/play`, so the link opens the app; the code path covers any case where it doesn't.
- **Guest carry-over:** when a guest seat session exists at sign-in, the verify call sends the guest bearer token so the seat moves to the account.
- **Commander-name prefill:** the server pre-sets a signed-in seat's name from the display name, so the existing localStorage prefill doesn't apply to it; no client change is needed.

### Start page (signed in)

Below the existing quick-start controls, which stay unchanged:
- **Your games:** rows show the map, mode, seat names and a lifecycle badge, with a **Resume** button.
- **Friends' games:** rows show the same details, with **Join** when `canJoin` and **Watch** otherwise. Join goes through the normal join path under the account identity; Watch uses the existing watch-by-ID flow.
- **Friends panel:**
  - An "Add friend by email" field.
  - An incoming-requests list with Accept and Decline.
  - The friends list, with a remove button behind a confirm step that isn't a browser dialog.
  - The outgoing-pending list, with Cancel.
- **Empty states:** "No unfinished games", and "Add a friend to see their games here".
- **Polling:** `account/games` and `friends` are polled every 10 s while the start page is visible, through the existing `PollingCadence` helper. A badge on the Friends panel heading counts incoming requests.

The signed-out start page is identical to today's.

### Add friend in games

When the viewer is a signed-in user, the War Room lobby seat list and the ☰ match menu's player list show an **Add friend** button on each other seat where `isAccount && !isFriend && !friendRequested`. A sent request shows "Requested", and an accepted one shows "Friend".

## Error handling

- **Mail failure:** an SMTP send failure is logged with the PHPMailer error, and the start step still responds `202`, so it never reveals whether an account exists. The user can press Resend.
- **Expired access token:** account routes return `401` on an expired access token. The client refreshes once and retries.
- **Membership changes:** "Your games" may include a game the player has since been removed from (lobby kick). `resume` then returns `404`, and the row disappears on the next poll with a short notice.
- **Concurrent mutual requests:** two concurrent mutual requests resolve inside a transaction that re-checks the reverse row. At worst one becomes `alreadyRequested`, which the client treats as a refresh.

## Testing

**PHP API tests** (`api/tests`, using `LogMailer` and a fixed clock where needed):
- **Challenges:**
  - Creation and the mail content.
  - Rate limits, per email and per IP.
  - Expiry.
  - Wrong-code attempts up to the lock.
  - The token path.
  - A consumed challenge can't be reused.
- **Verify:**
  - Creates an account on first sign-in and reuses it on the next.
  - Claims pending email friendships.
  - Transfers a guest's seat into the account.
- **Account:** display-name validation; logout revokes the token.
- **Friends:**
  - A request to an email with an account, and to one without.
  - Self and duplicate requests are rejected.
  - A mutual request auto-accepts.
  - Accept and decline permissions.
  - Requester cancel and unfriend.
  - Game-seat requests: allowed between users in a shared game, rejected for guest seats and for non-members.
- **`account/games`:** includes your unfinished games, includes friends' unfinished games, excludes completed games and pending friends, and computes `canJoin` correctly.
- **Resume:** rotates the seat token so the old token is rejected, and returns `404` for non-members.
- **Projection:** the `isAccount`/`isFriend`/`friendRequested` flags.

**UI tests** (bUnit, `BloodAndBanners.UI.Tests`):
- **SignIn steps:** sending the email, entering the code, the error states, the display-name step, and Resend cooldown.
- **Start page:**
  - Signed out, it is unchanged.
  - Signed in, it renders both lists with the Resume, Join and Watch actions.
  - The friends panel actions and the incoming badge.
- **Add friend button:** visible only for eligible seats, in both the lobby and the match menu.
- **Coordinator:** uses the account identity when one is stored and a guest otherwise. It saves rotated refresh tokens and clears the account on a failed refresh.
- **Contract fixtures** cover the new DTOs.

## Deployment notes

- **Server config:** add `SMTP_HOST=smtp.ionos.com`, `SMTP_PORT=465`, `SMTP_USER=noreply@trickypig.com`, `SMTP_PASS=…` (supplied by the user at deploy time), `MAIL_FROM=noreply@trickypig.com` and `APP_PUBLIC_URL=https://blood.trickypig.com`.
- **Migration:** migration 005 runs through the existing migrator.
- **APK:** rebuild it so `?signin=` links are handled in-app.
