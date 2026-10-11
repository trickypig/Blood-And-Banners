using System.Text.Json;

namespace BloodAndBanners.Contracts;

public sealed record GameEvent(
    long Sequence,
    long Version,
    string Kind,
    string? ActingSeat,
    IReadOnlyList<string> FocusHexes,
    JsonElement Payload,
    DateTimeOffset Timestamp);

public sealed record EventPage(
    IReadOnlyList<GameEvent> Events,
    long LastSequence);
