namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;

public sealed class DraftTableTests : BunitContext
{
    [Fact]
    public void The_picking_commander_can_claim_open_heroes_and_sees_taken_ones_locked()
    {
        GameCommandRequest? sent = null;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red"))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent = request));

        Assert.Equal("Your pick", cut.Find("[data-testid=draft-status]").TextContent.Trim());
        Assert.True(cut.Find("button[data-hero='Malachar the Voidcaller']").HasAttribute("disabled"));
        Assert.Contains("Rival", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);

        cut.Find("button[data-hero='Thorgrim Shattershield']").Click();

        Assert.Equal("pickHero", sent?.Kind);
        Assert.Equal("Thorgrim Shattershield", JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("hero").GetString());
    }

    [Fact]
    public void A_waiting_commander_cannot_pick_anything()
    {
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-blue", picking: "seat-red") with { LegalActions = [] }));

        Assert.Equal("Waiting for the opposing commander", cut.Find("[data-testid=draft-status]").TextContent.Trim());
        Assert.All(cut.FindAll("button[data-action]:not(.leave-match button)"), button => Assert.True(button.HasAttribute("disabled")));
    }

    [Fact]
    public void First_player_and_start_choices_send_their_values()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red"))
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("button[data-action=chooseFirstPlayer][data-seat=seat-blue]").Click();
        cut.Find("button[data-action=chooseStartPosition][data-start='2']").Click();

        Assert.Equal("seat-blue", JsonSerializer.SerializeToElement(sent[0].Payload).GetProperty("seatId").GetString());
        Assert.Equal(2, JsonSerializer.SerializeToElement(sent[1].Payload).GetProperty("start").GetInt32());
        Assert.Contains("won initiative", cut.Find("[data-testid=initiative]").TextContent);
    }

    [Fact]
    public void The_start_choice_shows_a_map_preview_and_highlights_the_viewers_start_once_chosen()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with { Board = MapPreviewFixtures.Board() };
        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Equal(2, cut.FindAll("[aria-labelledby=start-title] g.map-start").Count);
        Assert.Empty(cut.FindAll("g.map-start.mine"));

        var chosen = projection with
        {
            Draft = projection.Draft! with { StartBySeat = new Dictionary<string, int?> { ["seat-red"] = 2, ["seat-blue"] = 1 } },
        };
        cut.Render(parameters => parameters.Add(component => component.Projection, chosen));

        Assert.Equal("2", cut.Find("g.map-start.mine").GetAttribute("data-start"));
    }

    [Fact]
    public void Army_cards_render_each_units_numeric_stats()
    {
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red")));

        var armyCard = cut.Find("button[data-army='Blackmaw']").TextContent;

        Assert.Contains("Grunt", armyCard);
        var stats = cut.FindAll("button[data-army='Blackmaw'] .stat-line > span");
        Assert.Equal(["2", "1", "4", "3", "5"], stats.Take(5).Select(stat => stat.TextContent.Trim()));
        Assert.Equal(["Move", "Range", "Power", "Defense", "HP"], stats.Take(5).Select(stat => stat.QuerySelector("img")!.GetAttribute("alt")));
        Assert.DoesNotContain("@unit.", armyCard);
    }

    [Fact]
    public void Army_cards_show_the_army_emblem()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        projection = projection with
        {
            Draft = projection.Draft! with { AvailableArmies = [projection.Draft.AvailableArmies[0] with { Emblem = "/images/armies/blackmaw.svg" }] },
        };
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.ImageBaseUrl, "https://example.test/blood/"));

        Assert.Equal(
            "https://example.test/blood/images/armies/blackmaw.svg",
            cut.Find("button[data-army='Blackmaw'] img.army-emblem").GetAttribute("src"));
    }

    [Fact]
    public void Players_leave_the_draft_from_the_match_menu_after_confirming()
    {
        var left = false;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        Assert.Empty(cut.FindAll("button[data-action=leaveMatch]"));
        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();
        Assert.False(left);
        cut.Find("[data-menu=confirmLeave]").Click();
        Assert.True(left);
    }

    [Fact]
    public void Spectators_get_the_match_menu_and_stop_watching_at_once()
    {
        var left = false;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") with
            {
                Viewer = new ViewerPermissions("spectator", null, false, false),
                LegalActions = []
            })
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        cut.Find("[data-menu=toggle]").Click();
        cut.Find("[data-menu=leave]").Click();

        Assert.True(left);
    }

    [Fact]
    public void Catalog_images_resolve_against_a_configured_base_and_escape_spaces()
    {
        Assert.Equal(
            "https://site.test/blood/public/images/heroes/Thorgrim%20Shattershield_card.png",
            CatalogImages.Resolve("https://site.test/blood/public/", "/images/heroes/Thorgrim Shattershield_card.png"));
        Assert.Null(CatalogImages.Resolve(null, "/images/heroes/x.png"));
    }

    [Fact]
    public void Draft_cards_list_hero_and_unit_abilities()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var draft = projection.Draft! with
        {
            AvailableHeroes = [projection.Draft!.AvailableHeroes[0] with { Abilities = [new AbilityProjection("Shield Wall", "Ability", "+1 Defense") { Playable = true }] }],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection with { Draft = draft }));

        Assert.Contains("Shield Wall", cut.Find("button[data-hero='Thorgrim Shattershield'] li[data-ability='Shield Wall']").TextContent);
    }

    [Fact]
    public void Chosen_names_appear_in_claims_and_initiative()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "Lord Ashfang" },
            ],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("Claimed by Lord Ashfang", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);
        Assert.Contains("Lord Ashfang rolled 4", cut.Find("[data-testid=initiative]").TextContent);
    }

    [Fact]
    public void Names_with_markup_render_as_plain_text()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true),
                new MembershipProjection("seat-blue", 2, true, false) { Name = "<b>Ash</b>" },
            ],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Contains("Claimed by <b>Ash</b>", cut.Find("button[data-hero='Malachar the Voidcaller']").TextContent);
        Assert.Empty(cut.FindAll(".taken-by b"));
    }

    [Fact]
    public void The_draft_ribbon_offers_the_name_field_when_renaming_is_legal()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red") with
        {
            LegalActions = [new LegalAction("pickHero", null, []), new LegalAction("setCommanderName", null, [])],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.NotNull(cut.Find(".draft-ribbon [data-testid=commander-name]"));
    }

    [Fact]
    public void The_draft_log_lists_picks_newest_first_and_previews_the_latest()
    {
        var events = new[]
        {
            DraftEvent(1, "initiativeRolled", """{"rolls":{"seat-red":[18],"seat-blue":[4]},"order":["seat-red","seat-blue"]}"""),
            DraftEvent(2, "draftPicked", """{"seatId":"seat-red","decision":"chooseFirstPlayer","value":"seat-red"}"""),
            DraftEvent(3, "draftPicked", """{"seatId":"seat-blue","decision":"pickHero","value":"Malachar the Voidcaller"}"""),
        };

        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red"))
            .Add(component => component.Events, events));

        var log = cut.Find("[data-testid=draft-log]");
        Assert.Equal("Draft log", log.QuerySelector(".combat-log h2")!.TextContent);
        Assert.Equal(
            ["Rival chose Malachar the Voidcaller.", "You chose to move first.", "Initiative: You rolled 18, Rival rolled 4. You won."],
            log.QuerySelectorAll(".combat-log li").Select(item => item.TextContent));
        Assert.Equal("Rival chose Malachar the Voidcaller.", log.QuerySelector(".draft-log-latest")!.TextContent);
    }

    [Fact]
    public void The_draft_log_toggle_opens_and_closes_it_on_small_screens()
    {
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red")));

        var toggle = cut.Find(".draft-log-toggle");
        Assert.Equal("false", toggle.GetAttribute("aria-expanded"));
        Assert.Contains("No picks yet.", cut.Find("[data-testid=draft-log] .combat-log").TextContent);

        toggle.Click();

        Assert.Equal("true", cut.Find(".draft-log-toggle").GetAttribute("aria-expanded"));
        Assert.Contains("open", cut.Find("[data-testid=draft-log]").ClassList);
    }

    private static GameEvent DraftEvent(long sequence, string kind, string payload) =>
        new(sequence, 9, kind, null, [], JsonSerializer.Deserialize<JsonElement>(payload), DateTimeOffset.UnixEpoch);

    [Fact]
    public void Coop_heroes_see_the_void_lord_status_and_wait_for_the_confirm()
    {
        var baseline = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var projection = baseline with
        {
            Mode = GameMode.Coop,
            Setup = new SetupProjection(0, 0, false, [], []) { Redacted = true },
            Draft = baseline.Draft! with { PickingSeatId = null },
        };
        var cut = Render<DraftTable>(p => p.Add(c => c.Projection, projection));

        Assert.Contains("The Void Lord is placing monsters", cut.Find("[data-testid=void-status]").TextContent);
        Assert.Contains("Waiting for the Void Lord", cut.Find("[data-testid=draft-status]").TextContent);
    }
}

internal static class DraftFixtures
{
    public static GameProjection Projection(string viewerSeat, string picking) => new(
        "game-fixture",
        9,
        GameLifecycle.Draft,
        GameMode.VanillaPvp,
        new ViewerPermissions("player", viewerSeat, true, viewerSeat == "seat-red"),
        new BoardProjection(11, 9, []),
        new TurnProjection(0, null, 0),
        null,
        [
            new LegalAction("chooseFirstPlayer", null, []),
            new LegalAction("chooseStartPosition", null, []),
            new LegalAction("pickHero", null, []),
            new LegalAction("pickArmy", null, []),
        ],
        new Dictionary<string, int> { ["seat-red"] = 0, ["seat-blue"] = 0 },
        null)
    {
        ScenarioName = "Proving Field",
        Memberships = [
            new MembershipProjection("seat-red", 1, true, true),
            new MembershipProjection("seat-blue", 2, true, false),
        ],
        Draft = new DraftProjection(
            new Dictionary<string, IReadOnlyList<int>> { ["seat-red"] = [18], ["seat-blue"] = [4] },
            picking,
            null,
            new Dictionary<string, int?> { ["seat-red"] = null, ["seat-blue"] = null },
            new Dictionary<string, string?> { ["seat-red"] = null, ["seat-blue"] = "Malachar the Voidcaller" },
            new Dictionary<string, string?> { ["seat-red"] = null, ["seat-blue"] = null },
            [
                new HeroCard("Thorgrim Shattershield", "/images/heroes/Thorgrim Shattershield_card.png", 3, 1, 5, 5, 7, null),
                new HeroCard("Malachar the Voidcaller", "/images/heroes/Malachar the Voidcaller_card.png", 4, 5, 4, 3, 6, "seat-blue"),
            ],
            [
                new ArmyCard("Blackmaw", "Black", [new UnitCard("Grunt", "/images/units/black_grunt_card.png", 2, 1, 4, 3, 5)], null),
            ])
    };
}
