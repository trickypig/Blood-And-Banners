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
    public void Army_cards_render_each_units_numeric_stats()
    {
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red")));

        var armyCard = cut.Find("button[data-army='Blackmaw']").TextContent;

        Assert.Contains("Grunt M2 R1 P4 D3 HP5", armyCard);
        Assert.DoesNotContain("@unit.", armyCard);
    }

    [Fact]
    public void Players_can_leave_the_draft_after_confirming()
    {
        var left = false;
        var cut = Render<DraftTable>(parameters => parameters
            .Add(component => component.Projection, DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue"))
            .Add(component => component.OnReturnToWarRoom, () => left = true));

        cut.Find("button[data-action=leaveMatch]").Click();
        Assert.False(left);
        cut.Find("button[data-action=confirmLeave]").Click();
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
