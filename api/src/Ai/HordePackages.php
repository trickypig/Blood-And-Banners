<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

/** Themed groups of same-biome monsters the horde builder draws from. The first creature is the core. */
final class HordePackages
{
    /** @return list<array{name:string,biome:string,tags:list<string>,creatures:list<string>}> */
    public static function all(): array
    {
        $p = static fn (string $name, string $biome, array $tags, array $creatures): array
            => ['name' => $name, 'biome' => $biome, 'tags' => $tags, 'creatures' => $creatures];
        return [
            $p('Wolf pack', 'Ice', ['swarm', 'ambush'], ['Dire Wolf', 'Ice Wolf', 'Ice Wolf', 'Ice Wolf']),
            $p('Storm front', 'Ice', ['fortress'], ['Storm Giant', 'Polar Bear', 'Lesser Ice Elemental', 'Lesser Ice Elemental']),
            $p('The deep', 'River', ['ambush'], ['Kraken', 'Kraken Tentacle (Greater)', 'Kraken Tentacle (Lesser)', 'Kraken Tentacle (Lesser)']),
            $p('Tide court', 'River', ['combo'], ['Ewashia, Mistress of Tides', 'Mermaid', 'Merman', 'Merman']),
            $p('Mycelium', 'Swamp', ['combo', 'fortress'], ['Myconid', 'Shroom Kicker', 'Shroom Kicker', 'Spore Sprout', 'Fungal Archer']),
            $p('Hydra mire', 'Swamp', ['fortress'], ['Marsh Hydra', 'Myconid', 'Shroom Bomb', 'Shroom Bomb']),
            $p('Firestorm', 'Lava', ['combo'], ['Pyromancer', 'Greater Fire Elemental', 'Lesser Fire Elemental', 'Lesser Fire Elemental']),
            $p('Inferno', 'Lava', ['swarm'], ['Greater Fire Elemental', 'Lesser Fire Elemental', 'Lesser Fire Elemental', 'Lesser Fire Elemental']),
            $p('Wild hunt', 'Forest', ['ambush', 'swarm'], ['Satyr Warrior', 'Satyr Archer', 'Satyr Archer', 'Satyr Archer']),
            $p('Old growth', 'Forest', ['fortress'], ['Treant', 'Krampus', 'Satyr Archer', 'Satyr Archer']),
            $p('Dune lords', 'Wasteland', ['combo'], ['Dune Wyvern', 'Basilisk', 'Sand Scorpion', 'Sand Scorpion']),
            $p('Hollow host', 'Wasteland', ['swarm'], ['Stray', 'Stray', 'Husk', 'Husk', 'Slatescale Worm']),
            $p('Stone wall', 'Hills', ['fortress'], ['Stoneking', 'Stonekin Boulderfist', 'Stonekin Brute', 'Stonekin Brute']),
            $p("Giant's step", 'Hills', ['fortress', 'combo'], ['Stonekin Boulderfist', 'Stonekin Mauler', 'Stonekin Mauler', 'Stonekin Mauler']),
            $p("Dragon's roost", 'Mountain', ['combo'], ['Crystal Golem', 'Ogre Chief', 'Ogre']),
            $p('Ogre band', 'Mountain', ['swarm'], ['Ogre Chief', 'Ogre', 'Ogre', 'Ogre', 'Crystal Golem']),
            $p('Shadow host', 'Void', ['ambush', 'swarm'], ['Shadow Wraith', 'Shadow Wraith', 'Shadow Wraith', 'Shadow Wraith']),
            $p('Void wyrm', 'Void', ['combo'], ['Void Dragon', 'Void Hydra']),
        ];
    }
}
