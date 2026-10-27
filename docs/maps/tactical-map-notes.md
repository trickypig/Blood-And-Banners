# Compact tactical maps

Added 2026-10-27. Three Void Incursion maps and two Vanilla Duel maps, all rectangles with dimensions between 15 and 20 hexes. These are initial playtest candidates, not a claim of demonstrated competitive balance. Existing maps and gameplay rules are preserved.

## Research and adaptation

Sources consulted:

- [Heroscape community: What makes a great map?](https://www.reddit.com/r/heroscape/comments/1qbe33r/in_your_opinion_what_makes_a_great_heroscape_map/) includes designer LeftOn4ya reposting his map-design principles: offer multiple opening routes, distribute useful elevation, give both players comparable access to rewards, and provide sight blockers against elevated shooters. Other contributors favor exposed low-ground rewards and features that make the whole map useful.
- [Heroscape community: Custom map build advice](https://www.reddit.com/r/heroscape/comments/1q0dlxd/heroscape_custom_map_builds_advice/) stresses giving melee units a chance, avoiding dominant hills near one start, and providing usable landing areas around elevation changes.
- [Heroscape community: Advice for making your own maps](https://www.reddit.com/r/heroscape/comments/1hysrna) discusses likely engagement points and measuring movement to rewards and high ground. Search excerpts were available; the complete thread could not be retrieved.
- The original [LeftOn4ya Heroscapers thread](https://www.heroscapers.com/threads/lefton4yas-maps-philosophy-of-heroscape-cartography.32237/) returned HTTP 403. The designer's own repost in the first source supplies the readable guidance; this research does not claim to have read the blocked original.

Application to Blood & Banners is a design inference. Heroscape movement timings and glyph rules are not copied: this game has different activation, nexus, terrain, and deployment rules. Trees and rocks actually block sight and movement here; landmarks block movement; nexus points and potions remain traversable. Roads are visual route markers without an assumed movement bonus. Basalt, snow, darkness, and shallow water provide biome variety without forcing terrain damage.

Every map has multiple safe walking approaches. Rewards sit away from deployment; high ground and nexus control are generally separate commitments. Small radius-one nexus auras avoid turning the whole middle into one stacked bonus zone. Water, ice, poison, and lava occupy bounded areas with ordinary ground alternatives. The duel maps use exact 180-degree symmetry of terrain, height, objects, and starts, including the staggered hex coordinates.

## Void Incursion

The three maps have P1 starts, explicit Void Portals, three nexus points, potions, terrain landmarks, and Void deployment in several biomes. All seat four hero players with 32 deployment spaces available within three hexes of P1. Spawn markers designate legal placement areas, not restrictions that bind a monster to its pictured biome: the existing engine determines placement legality. Water deployment specifically allows River monsters; ordinary monsters have land alternatives.

### Threefold Ford — 18 × 20

[Layout preview](tactical-previews/incursion-threefold-ford.svg) · [Scenario](../../api/scenarios/incursion-threefold-ford.json)

A two-row river splits the battlefield. Two-hex-wide western and eastern fords and a central stone bridge are independently viable; no safe fourth crossing exists. The central bridge is the shortest direct approach and offers Strength, but little nearby cover. The western ford offers Warding near jungle cover and a healing pickup on the far bank. The eastern ford offers Focus with an exposed dune hill beyond it. Capturing a crossing does not prevent the party changing to another one along the banks.

The Void Lord can pressure the near-bank approaches or hold the far-bank snow and basalt regions. Seven water deployment hexes threaten the bridge and adjacent river, but land deployment remains available. The portal court has a low ramp and screening trees rather than a single fortified entrance. The crystals give reasons to leave the direct portal route.

Decision to test: does the party concentrate on the fast central bridge, split across two fords, or secure a flank's resources before crossing? Does defending the near bank leave the portal too exposed?

### Cinder Switch — 20 × 18

[Layout preview](tactical-previews/incursion-cinder-switch.svg) · [Scenario](../../api/scenarios/incursion-cinder-switch.json)

A broad basalt ridge offers Focus and a high observation point. Lava interrupts part of its crest; the power pickup lies farther forward and the fire crystal occupies a separate nearby hex. Taking the ridge gives position but does not collect every reward or control both flanks.

Ground-level western jungle and eastern snow routes skirt the ridge. The west offers Warding and healing near cover and a small poison pocket; the east offers Strength and speed near ice. Both paths rejoin before the portal. A northern lake supplies seven River monster placement hexes, while jungle, snow, basalt, and darkness deployment let the Void Lord vary the defense.

Decision to test: spend activations climbing for firing position, secure a flank's nexus, or bypass the ridge while the Void Lord commits defenders there? Lava also creates a local forced-movement threat without making lava traversal mandatory.

### Thornwatch — 18 × 18

[Layout preview](tactical-previews/incursion-thornwatch.svg) · [Scenario](../../api/scenarios/incursion-thornwatch.json)

Broken tree and rock screens interrupt the center. Two parallel paths through the woodland can reinforce each other, while an eastern dune hill provides a longer outside approach. Warding occupies the western woodland lane, Focus the eastern lane, and Strength the dune approach. The hill and the Strength nexus are separate positions.

A western pool and its healing pickup tempt a detour; a northern power pickup tempts a direct advance through the screens. The Void Lord can deploy in the woodland between the paths, along the eastern flank, in northern darkness and snow, or in the water. Ice and poison occur in small northern patches with safe ground around them.

Decision to test: keep the heroes together for mutual support, send a detachment around the overlook, or use the short woodland connections to switch the point of attack? Can the Void Lord ambush the central party without conceding the outside route?

## Vanilla Duel

Both maps have P1/P2 starts, seven-unit deployment capacity, symmetric rewards, and no Void Portal or monster spawn markers. Rewards are more than five hexes from either start, matching the editor's clearance rule. Symmetry guarantees matching geometry; actual faction and first-player balance still need playtesting.

### Splitstone — 18 × 16

[Layout preview](tactical-previews/duel-splitstone.svg) · [Scenario](../../api/scenarios/duel-splitstone.json)

Two stepped stone ridges flank a low shallow-water center. The small peaks reward climbing, while paired Strength and Warding nexus points encourage fighting below and around them. Rocks interrupt the direct advance; jungle and dune flanks provide approaches around the ridges. Symmetric shrines and health/speed pickups draw units toward the upper and lower edges.

Decision to test: hold a ridge, contest a low nexus, or send a mobile group around the opposing ridge? A defender on a peak cannot simultaneously occupy the low rewards.

### Rimefire Crossing — 20 × 18

[Layout preview](tactical-previews/duel-rimefire-crossing.svg) · [Scenario](../../api/scenarios/duel-rimefire-crossing.json)

A vertical river has upper, central, and lower crossing pairs. Two offset stepping hills reward ranged positioning but cannot close all crossings. Paired Focus nexus points mark the outer crossings; Warding marks the middle. Snow, basalt, small ice strips, and lava pockets produce local positioning and knockback choices. Paired crystals and potions lie along the approaches to the outer crossings.

Decision to test: contest the middle behind cover, take an outer crossing's Focus, or draw the opposing army to one bridge and cross elsewhere? Every hazard has an ordinary ground bypass.

## Reproduction and verification

Run `python scripts/build-tactical-maps.py --preview docs/maps/tactical-previews` to rebuild only these five scenarios and their SVG previews. Run `python scripts/build-tactical-maps.py --check` to verify checked-in data and tactical invariants without rewriting maps. No third-party Python packages are needed.

The generator checks safe ordinary-walker connectivity with climbs of at most one level; safe routes exclude lava, poison, open water, and ice entirely. It checks reward access, start clearance, four-player incursion deployment area, multiple deployment biomes, absence of a single critical middle hex, independently viable Threefold Ford crossings, and complete duel geometry symmetry. Safe route lengths measure hex steps, not combat rounds. The server's `MapValidator` and `MapModeCheck` provide authoritative schema and deployment checks. The battlefield picker exposes all five scenarios in their respective modes.

For playtesting, rotate duel starting sides and try melee-heavy, ranged-heavy, and flying parties. Track which routes and rewards see use, whether one nexus can be held without exposure, whether the portal's defense creates a stall, and whether River monsters remain a useful choice. Automated structural checks cannot establish those combat outcomes.
