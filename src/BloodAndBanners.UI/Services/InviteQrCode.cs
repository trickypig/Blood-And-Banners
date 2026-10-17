using System.Text;
using QRCoder;

namespace BloodAndBanners.UI.Services;

public sealed record InviteQrCode(int Size, string PathData)
{
    // Drawn as one SVG path of unit squares so it renders the same in the browser and the app.
    public static InviteQrCode Create(string text)
    {
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(text, QRCodeGenerator.ECCLevel.M);
        var matrix = data.ModuleMatrix;
        var path = new StringBuilder();
        for (var y = 0; y < matrix.Count; y++)
        {
            for (var x = 0; x < matrix[y].Length; x++)
            {
                if (matrix[y][x])
                {
                    path.Append('M').Append(x).Append(' ').Append(y).Append("h1v1h-1z");
                }
            }
        }

        return new InviteQrCode(matrix.Count, path.ToString());
    }
}
