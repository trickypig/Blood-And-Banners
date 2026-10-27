namespace BloodAndBanners.UI.Services;

using BloodAndBanners.Contracts;

/// <summary>A mode and battlefield for a new war room: a built-in scenario by name, or a revision of the player's own map.</summary>
public sealed record GameSetupSelection(GameMode Mode, string ScenarioName, string? ScenarioRevisionId = null);
