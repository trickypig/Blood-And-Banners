using System.Text.Json;

namespace BloodAndBanners.Contracts;

public sealed record CommandEnvelope(
    string CommandId,
    long ExpectedVersion,
    string Kind,
    JsonElement Payload);
