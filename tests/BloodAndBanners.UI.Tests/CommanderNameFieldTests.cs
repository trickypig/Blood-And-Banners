namespace BloodAndBanners.UI.Tests;

using System.Text.Json;
using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using BloodAndBanners.UI.Services;
using Bunit;
using Microsoft.Extensions.DependencyInjection;

public sealed class CommanderNameFieldTests : BunitContext
{
    private sealed class MemoryStore : IKeyValueStore
    {
        public Dictionary<string, string> Values { get; } = [];

        public ValueTask<string?> GetAsync(string key) => ValueTask.FromResult(Values.GetValueOrDefault(key));

        public ValueTask SetAsync(string key, string value)
        {
            Values[key] = value;
            return ValueTask.CompletedTask;
        }

        public ValueTask RemoveAsync(string key)
        {
            Values.Remove(key);
            return ValueTask.CompletedTask;
        }
    }

    private sealed class BrokenStore : IKeyValueStore
    {
        public ValueTask<string?> GetAsync(string key) => throw new InvalidOperationException("storage blocked");

        public ValueTask SetAsync(string key, string value) => throw new InvalidOperationException("storage blocked");

        public ValueTask RemoveAsync(string key) => throw new InvalidOperationException("storage blocked");
    }

    private static GameProjection Draft(string? myName = null, bool canRename = true) =>
        DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-blue") with
        {
            LegalActions = canRename ? [new LegalAction("setCommanderName", null, [])] : [],
            Memberships =
            [
                new MembershipProjection("seat-red", 1, true, true) { Name = myName },
                new MembershipProjection("seat-blue", 2, true, false),
            ],
        };

    [Fact]
    public void The_field_is_hidden_when_renaming_is_not_legal()
    {
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft(canRename: false)));

        Assert.Empty(cut.FindAll("[data-testid=commander-name]"));
    }

    [Fact]
    public void The_current_name_wins_over_the_remembered_one()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Remembered";
        Services.AddSingleton<IKeyValueStore>(store);

        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft(myName: "Current")));

        cut.WaitForAssertion(() => Assert.Equal("Current", cut.Find("input").GetAttribute("value")));
        Assert.True(cut.Find("button[data-action=setCommanderName]").HasAttribute("disabled"));
    }

    [Fact]
    public void An_unnamed_seat_takes_the_remembered_name_without_pressing_save()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Lord Ashfang";
        Services.AddSingleton<IKeyValueStore>(store);
        var sent = new List<GameCommandRequest>();

        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.WaitForAssertion(() => Assert.Single(sent));
        Assert.Equal("Lord Ashfang", cut.Find("input").GetAttribute("value"));
        Assert.Equal("setCommanderName", sent[0].Kind);
        Assert.Equal("Lord Ashfang", JsonSerializer.SerializeToElement(sent[0].Payload).GetProperty("name").GetString());

        cut.Render(parameters => parameters.Add(c => c.IsBusy, true));
        cut.Render(parameters => parameters.Add(c => c.IsBusy, false));
        Assert.Single(sent);
    }

    [Fact]
    public void A_named_seat_is_not_renamed_from_memory()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Remembered";
        Services.AddSingleton<IKeyValueStore>(store);
        var sent = new List<GameCommandRequest>();

        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft(myName: "Current"))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.WaitForAssertion(() => Assert.Equal("Current", cut.Find("input").GetAttribute("value")));
        Assert.Empty(sent);
    }

    [Fact]
    public void The_remembered_name_is_not_sent_when_renaming_is_not_legal()
    {
        var store = new MemoryStore();
        store.Values[CommanderNameField.StorageKey] = "Lord Ashfang";
        Services.AddSingleton<IKeyValueStore>(store);
        var sent = new List<GameCommandRequest>();

        Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft(canRename: false))
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Empty(sent);
    }

    [Fact]
    public void The_field_is_labelled_as_your_commander_name()
    {
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft()));

        Assert.Equal("Your commander name", cut.Find("label").TextContent.Trim());
    }

    [Fact]
    public void The_server_cleaned_name_replaces_the_input_and_is_remembered()
    {
        var store = new MemoryStore();
        Services.AddSingleton<IKeyValueStore>(store);
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft()));
        cut.Find("input").Input("  Lord   Ash ");

        cut.Render(parameters => parameters.Add(c => c.Projection, Draft(myName: "Lord Ash")));

        cut.WaitForAssertion(() => Assert.Equal("Lord Ash", cut.Find("input").GetAttribute("value")));
        Assert.Equal("Lord Ash", store.Values[CommanderNameField.StorageKey]);
    }

    [Fact]
    public void Broken_or_missing_storage_still_allows_naming()
    {
        Services.AddSingleton<IKeyValueStore>(new BrokenStore());
        GameCommandRequest? sent = null;
        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.OnCommand, (GameCommandRequest request) => sent = request));

        cut.WaitForAssertion(() => Assert.Equal(string.Empty, cut.Find("input").GetAttribute("value") ?? string.Empty));
        cut.Find("input").Input("Seris");
        cut.Find("form").Submit();
        cut.Render(parameters => parameters.Add(c => c.Projection, Draft(myName: "Seris")));

        Assert.Equal("Seris", JsonSerializer.SerializeToElement(sent!.Payload).GetProperty("name").GetString());
    }

    [Fact]
    public void Respacing_the_current_name_is_not_a_change()
    {
        var cut = Render<CommanderNameField>(parameters => parameters.Add(c => c.Projection, Draft(myName: "Lord Ash")));

        cut.Find("input").Input("  Lord   Ash ");

        Assert.True(cut.Find("button[data-action=setCommanderName]").HasAttribute("disabled"));
    }

    [Fact]
    public void Saving_is_disabled_while_busy()
    {
        var cut = Render<CommanderNameField>(parameters => parameters
            .Add(c => c.Projection, Draft())
            .Add(c => c.IsBusy, true));
        cut.Find("input").Input("Seris");

        Assert.True(cut.Find("button[data-action=setCommanderName]").HasAttribute("disabled"));
    }
}
