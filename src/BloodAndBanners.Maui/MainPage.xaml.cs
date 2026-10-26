namespace BloodAndBanners.Maui;

public partial class MainPage : ContentPage
{
	public MainPage()
	{
		InitializeComponent();
#if ANDROID || IOS
		// Phones draw apps edge to edge; keep the page clear of the status bar, notch and home indicator.
		SafeAreaEdges = new SafeAreaEdges(SafeAreaRegions.Container);
		BackgroundColor = Color.FromArgb("#232529");
#endif
	}
}
