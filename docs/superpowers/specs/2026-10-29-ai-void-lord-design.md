# AI Void Lord — design

Date: 2026-10-29. Status: draft for the user's review.

## Intent

Co-op ("Void incursion") today needs a human Void Lord. This adds an option to seat an **AI Void Lord** instead, so 1–4 human hero players can play against the game itself. The user wants it to be **strategic and challenging**:

- It builds coordinated armies in each biome, choosing units that work together, so each group is a puzzle the heroes must read and answer with a plan of their own.
- It plays smart tactics turn by turn, to put up the greatest fight it can.

The AI comes as **named personalities**, and the host picks one. Each personality has its own difficulty and its own tendencies (more or less aggressive, ambush vs. swarm, and so on). Each is **fairly consistent from game to game** but **feels different from the others**.

## Goals

1. The host can seat an AI personality as the Void Lord in a Co-op war room. With an AI seated, a match needs only 1–4 human hero players.
2. **All four personalities ship in the first version** (the user's call):

   | Personality | Feel | Difficulty |
   |---|---|---|
   | **The Ravager** | All-out aggression. Masses of cheap commons rush the heroes, trade units freely, and chase weak or isolated heroes. | Easy, a first opponent |
   | **The Warden** | Fortress. Elites and Unmovable/Stoneskin pieces ring the portal. It holds ground, waits for the heroes and punishes overextension. | Medium |
   | **The Huntress** | Ambush. It hides forces behind sight blockers, in water and in burrows, lures heroes forward, then springs from the flanks and focuses one hero at a time. | Hard |
   | **The Hollow King** | Adaptive. It opens patient and turns aggressive once ahead. It builds tight biome combos (spore networks, wolf packs, Kraken + tentacles) and looks a step ahead on the turns that matter. | Hardest |

   The names and the one-line pitches were my proposal; the user approved the cast ("sounds good"). Exact flavour text is the builder's to write.
3. The AI does the whole Void Lord job: it builds and places the horde during prep, confirms on its own, plays every Void Lord turn, and answers any pending choice addressed to the Void Lord seat.
4. AI turns **play out in sequence**, one action at a time with a short pause between, so players can watch each monster move and attack the way a human Void Lord's turn looks (the user's call).

## Anti-goals (the user's words: what would make them wince)

- **All personalities playing the same.** The four must be told apart on the board within a turn or two: different hordes, different movement patterns, different targets.
- **Monsters wandering around or making silly decisions.** Every activation should have a visible purpose: advance on a target, hold a position, set up an ambush, protect the portal, or attack. No aimless shuffles, no walking into obvious death for nothing, no wasting an attack that was available, and no leaving a unit idle beside a hero it could hit (unless the personality's plan says hold, and then holding should look deliberate).

## Non-goals

- AI hero players. The AI plays only the Void Lord.
- Vanilla duel AI or Battle Royale AI.
- Any cheating (see Constraints).
- Machine learning or training. Personalities are hand-tuned.
- New rules, monsters or abilities.

## Constraints

### Fair play (the user's call): the AI knows only what a human Void Lord knows

- **During prep** the Void Lord's view hides the heroes' draft picks (`GameService::redactedCoopDraft`). The AI builds its horde from the map, the budget and the number of hero players only. Its "tailored" armies prepare for whoever might come, not for the heroes actually drafted.
- **In battle** it sees what the Void Lord's projection shows. It gets no peeking at hidden dice or future rolls. The builder must make sure whatever state the AI reads is no richer than the Void Lord seat's projection. The simplest way is to give it only data a Void Lord view would contain, or to check that the raw state holds nothing hidden from the Void Lord once battle starts.
- Same budget, spawn zones, placement rules and turn allowance as a human. It acts **only** by submitting commands that appear in `legalActions` / `legalPreBattle` for its seat, through the same engine path as a human command.

### Platform

- The server is PHP on shared hosting, with no background workers or cron assumed. Clients (web, PWA, Android APK) already poll the server for updates (`GameExperience.razor`).
- The AI must work the same on every client, with no client-side AI.
- The hosting firewall rejects JSON bodies with ~1000+ fields (see map save notes), which matters if anything new is posted from the client.
- Old APKs or PWAs in the wild will meet games with an AI seat. They must not break. The worst acceptable case is that they render the AI seat like a human Void Lord.

## How it works (the decided parts)

### Seating

- In the Co-op war room, the Void Lord seat can be held by a person **or** by one of the four personalities. The host chooses. The host is currently seated as Void Lord by default (b979609). Choosing an AI moves the host to a hero seat.
- With an AI seated, the match can start with 1 human hero (today's "at least 2 memberships, all ready" rule must count the AI seat as present and always ready).
- The seat limit stays 5 (4 heroes + Void Lord).
- **Any Co-op map** (the user's call): the AI must play built-in maps and players' own maps alike. Player maps can lack water, sight blockers or variety, or have very few spawn hexes. Every personality's recipe needs fallbacks that still look deliberate on such maps. Examples: the Huntress with no cover ambushes from range and around corners; a biome with no matching creatures borrows a neighbour (see "not strict" below). It must always produce a legal, non-empty horde within budget on any map that passes the Co-op map check (`MapModeCheck`). No picker warning is planned. The AI plays whatever map passes validation.

### Prep

- The AI builds and places its horde once the roster locks at Start (the budget binds then: `CoopMode::budget`, currently 12 + 30 per hero), then confirms. The heroes must never wait on it.
- **Horde building** is driven by the personality's recipe: which biomes it favours, how it splits points across common/elite/boss, which synergy groups it forms, and where on the map it puts them (forward, around the portal, near sight blockers or water, spread or massed).
- The user's framing: **coordinated armies in each biome**. Spawn hexes carry a `biome`, and the AI should form its groups from creatures of the biome they're placed in. The game doesn't force this today (only Water Bound must go on water). It's the AI's own design rule, and it is what makes each group read as a themed puzzle.
- **Hex biome → monster biome** (approved by the user, who added that it "doesn't have to be strict"). Map hexes use 8 biome names (`MapValidator` TERRAINS); creatures use 9 (`gameData.json`).

  | Hex biome | Monster biome |
  |---|---|
  | ice | Ice |
  | water | River |
  | lava | Lava |
  | jungle | Forest; Swamp on `swamp` terrain |
  | darkness | Void; Swamp on `poison-pits` terrain |
  | sand | Wasteland |
  | stone | Hills or Mountain |
  | plains | Hills or Mountain |

  "Not strict" means this is the AI's strong preference, not a hard rule. A personality may slip in an off-biome creature when it completes a synergy, or when a biome has too few spawn hexes or creatures. Hills and Mountain have little room on today's maps, which is fine. How much off-biome mixing each personality allows is the builder's to tune. My suggestion: the Hollow King mixes most, and the Ravager and Warden stay mostly on theme.
- **Consistent but not identical** (the user: "ideally it could vary from game to game", and "each AI opponent would be fairly consistent"). The same personality on the same map should produce a recognisably similar horde and plan, with some variation (which synergy package fills which biome, exact positions), so the heroes can't memorise it.

### Battle

- Turns alternate hero / Void Lord. On each Void Lord turn the AI activates up to 5 monsters (`CoopMode::allowance`). For each it chooses movement, attacks, special attacks, abilities and items from the legal actions, then ends the activation, and finally ends the turn.
- It must also answer pending choices addressed to the Void Lord seat whenever they come up (`state.pending.choice.seatId`), including during a hero's turn, so the game never stalls waiting on it.

### Pacing (the user wanted turns played out in sequence; the mechanism is my plan)

- The AI takes **one action per step**, with a pause between steps.
- **The host controls the pause** (the user's call). The Void Lord turn comes after *every* hero's turn, so with 4 heroes and about 1 s per action, a round holds roughly 1.5–3 minutes of AI play. That's why the user wanted the pace adjustable. My calls:
  - The host picks a pace in the war room next to the personality: **Slow** (~2 s), **Normal** (~1 s, the default) or **Fast** (~0.3 s, effectively as fast as polling allows). The builder may tune the exact values.
  - The host can also change it mid-match from the ☰ match menu.
  - It's stored per game. Non-host players see the current pace but can't change it.
- Steps run only when a client polls, so polling sets a floor on speed. Clients poll every 2 s while it isn't their turn (`PollingCadence.cs`). For Normal and Fast to mean anything, new clients should poll at the chosen pace while the AI is acting. Old APKs and PWAs keep their 2 s cadence, so they just see a slower AI. If even one up-to-date client is connected, its polls drive the pace for everyone.
- The mechanism: when any client polls a game where the AI owes an action (its turn, its pending choice, or unconfirmed prep) and the pause since the AI's last action has passed, the server runs **one** AI step inside that request before answering. Each step is an ordinary engine command, so it produces the normal events, versions and log, and clients animate it exactly as they would a human Void Lord's action.
- If nobody is connected, the AI simply waits. Concurrent polls must not double-step: rely on the existing version-conditional update (`applyMatchCommand`'s `WHERE version = :expected`) or an equivalent guard.
- Each step must finish well within a normal request time on shared hosting. Thinking time per step needs a hard budget (my call: a soft target of ~1 s, a hard cap of a few seconds). When it runs out, the AI falls back to the best option found so far.

### What the user said about strategy

"We'll need to figure out the exact strategy still." The personality table above is the agreed direction. The tuning is the builder's to work out and then playtest with the user.

## Left to the builder

These are my calls or open items. The builder decides, and anything that changes the user-visible feel should be flagged for playtest:

- **The AI's architecture.** My suggestion: a personality is data, made of evaluation weights (damage dealt, damage taken, kills, portal threat and safety, grouping/cohesion, holding terrain and cover, reaching ambush positions), a horde recipe, and a sharpness setting (how many candidates it weighs per decision, whether it looks ahead within its own turn, how often it picks a less-than-best move). The engine is a pure state-in/state-out function with `legalActions`, so the AI can try candidate commands on a copy of the state and score the result.
- **Randomness.** Combat rolls dice through `RandomSource`. When scoring candidates, the AI should reason on expected outcomes (or a deterministic stand-in source), not on the real roll. The real roll happens only when the chosen command is actually submitted.
- **Activation order and planning.** Which 5 units to activate and in what order. Higher personalities should plan the turn as a whole (focus fire, set up flanks before striking) rather than unit by unit.
- **Using abilities well.** The catalog has ~58 creatures across 9 biomes with many abilities (spores, poison, charm, push, teleport, burrow, submerge, breath weapons…). The AI should use them sensibly. A generic "score the result" approach covers most of them; some may need hints in the personality or horde recipes so it uses them on purpose.
- **Storing the AI seat.** For example, a membership with a reserved synthetic identity, a role/flag marking it as AI, and the personality id. The AI also needs per-game memory for things like its current plan, and that must survive between requests.
- **How the seat looks in the lobby and match UI.** Personality name, difficulty and pitch; how the host picks it; how the Void Lord seat shows "AI" in the turn ribbon, rail and log.
- **Ending turns.** When the AI has nothing useful left, it ends activations and the turn promptly. It must never stall the match.
- **Switching the seat.** If a human had placed monsters in the war room and the host then seats an AI, the AI discards that horde and builds its own. Switching back to a human starts from an empty horde. (My call.)
- **Tests.** Engine-level tests that each personality produces a legal, within-budget horde on every built-in Co-op map and on awkward custom-style maps (no water, no cover, one biome, few spawn hexes); that a full AI-vs-scripted-heroes battle runs to completion without stalling; that the AI never submits an illegal command; and checks against the anti-goals (for example, a monster adjacent to a hero with an attack available attacks unless its plan is to hold).
- **Telling personalities apart.** Some measurable check (horde composition, average distance from the portal, how fast it engages) that the four actually differ.
