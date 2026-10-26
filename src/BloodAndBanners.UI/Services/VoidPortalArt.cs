namespace BloodAndBanners.UI.Services;

/// <summary>The Void Lord's gate: one 14 × 8 sprite sheet of 192 px frames, plus a still icon (the first Active Idle frame).</summary>
public static class VoidPortalArt
{
    /// <summary>The engine's id for the fixed Void Portal unit.</summary>
    public const string UnitId = "v-portal";
    public const string SheetPath = "images/void-portal/void-gate.png";
    public const string IconPath = "images/void-portal/icon.png";
    public const int Frame = 192;
    public const int Columns = 14;
    public const int Rows = 8;

    /// <summary>
    /// How the gate is drawn: the editor's still icon, the dormant pool before battle, the opening run (Appear,
    /// Rise, Activate) into Active Idle, the Active Idle loop, or the closing run (Inactivate, Decline, Disappear).
    /// </summary>
    public enum Phase { Icon, Dormant, Opening, Active, Closing }
}
