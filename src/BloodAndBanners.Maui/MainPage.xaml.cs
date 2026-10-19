namespace BloodAndBanners.Maui;

public partial class MainPage : ContentPage
{
	public MainPage()
	{
		InitializeComponent();
#if ANDROID
		// Android draws apps edge to edge; keep the page clear of the status bar and camera cutout.
		SafeAreaEdges = new SafeAreaEdges(SafeAreaRegions.Container);
		BackgroundColor = Color.FromArgb("#232529");
#endif
	}
}
