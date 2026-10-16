<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Effects;

/** Maps printed ability names to handlers. Names without a handler are deferred and have no effect in play. */
final class AbilityRegistry
{
    /** @var array<string, Ability>|null */
    private static ?array $handlers = null;

    public static function get(string $name): ?Ability
    {
        return self::handlers()[$name] ?? null;
    }

    public static function isPlayable(string $name): bool
    {
        return self::get($name) !== null;
    }

    /** @return Ability[] the unit's abilities that have handlers */
    public static function abilitiesOf(array $unit): array
    {
        $found = [];
        foreach ($unit['abilities'] ?? [] as $ability) {
            $handler = self::get((string)$ability['name']);
            if ($handler !== null) {
                $found[] = $handler;
            }
        }
        return $found;
    }

    public static function find(array $unit, string $name): ?Ability
    {
        foreach (self::abilitiesOf($unit) as $ability) {
            if ($ability->name === $name) {
                return $ability;
            }
        }
        return null;
    }

    public static function any(array $unit, callable $test): bool
    {
        foreach (self::abilitiesOf($unit) as $ability) {
            if ($test($ability)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, Ability> */
    private static function handlers(): array
    {
        if (self::$handlers === null) {
            $list = [
                new Knockback('Knockback'),
                new Teleport('Teleport'),
                new GustOfWind('Gust of Wind'),
                new StunningBlow('Stunning Blow'),
                new Flying('Flying'),
                new PackTactics('Pack Tactics'),
                new SharkInstinct('Shark Instinct'),
                new Outrage('Outrage'),
                new FrighteningPresence('Frightening Presence'),
                new FeralCharge('Feral Charge'),
                new OgreRivalry('Ogre Rivalry'),
                new DamageReduction('Molten Shell'),
                new DamageReduction('Darkness Veil', rangedOnly: true),
                new Burrow('Burrow'),
                new Unmovable('Unmovable'),
                new CrushingBlow('Crushing Blow'),
                new AquaticSurge('Aquatic Surge'),
                new RangedDefense('Stoneskin'),
                new RangedDefense('Hollow Bones'),
                new PoisonAttack('Poison Arrows', 15),
                new PoisonAttack('Venom', 8, automaticOnWound: true),
                new PoisonAttack('Poisonous Bite', 11, immediate: true),
                new StunningKick('Stunning Kick'),
                new ArrowsOfSlowness('Arrows of Slowness'),
                new HydraHeads('Multiple Heads'),
                new HydraHeads('Many Heads', 4, 2),
                new BranchAttack('Branch Attack'),
                new SporeRelease('Spore Release'),
                new SporeAttack('Spore Bomb', 2, 17, true),
                new SporeAttack('Spore Cloud', 3, 13),
                new MyceliumNetwork('Mycelium Network'),
                new WaterAffinity('Water Affinity'),
                new WaterBound('Water Bound'),
                new Submerged('Submerged'),
                new WaterTwister('Water Twister'),
                new TentacleRegeneration('Tentacle Regeneration'),
                new LavaWalker('Lava Walker'),
                new TremblingFear('Trembling Fear'),
                new RecklessFury('Reckless Fury'),
                new UnbreakableLine('Unbreakable Line'),
                new Evasion('Evasion'),
                new OneLastHorn('One Last Horn'),
                new FragileFocus('Fragile Focus'),
                new MultiStrike('Dual Strike'),
                new MultiStrike('Twin Strikes'),
                new MultiStrike('Dual Strikes'),
                new SilentSting('Silent Sting'),
                new MoonlightGlare('Moonlight Glare'),
                new FixedStrike('Bolt of Annihilation', 6, 5),
                new FixedStrike('Flame Strike', 3, 5, 'fire'),
                new ChaosStrike('Chaos Strike'),
                new Fireball('Fireball'),
                new FrostStorm('Frost Storm'),
                new TailSlash('Tail Slash'),
                new StoneSwat('Stone Swat'),
                new PetrifyingGaze('Petrifying Gaze'),
                new StoneGrip('Stone Grip'),
                new SkyToss('Sky Toss'),
                new PanPipes('Pan Pipes'),
                new BoulderThrow('Boulder Throw'),
                new ColumnAttack('Column Swing'),
                new ColumnAttack('Column Toss', toss: true),
                new DragonBreath('Dragon Breath'),
                new MysticShield('Mystic Shield'),
                new ChainLightning('Chain Lightning'),
                new StatBlessing('Shield Wall', 'defense', 1, Effects::UNTIL_NEXT_TURN, 1, StatBlessing::ALLIES, false),
                new StatBlessing('Warlord\'s Command', 'power', 1, Effects::UNTIL_END_OF_TURN, 1, StatBlessing::ALLIES, false),
                new StatBlessing('Rune of Warding', 'defense', 2, Effects::UNTIL_NEXT_TURN, null, StatBlessing::ALLIES, true),
                new StatBlessing('Rune of Distraction', 'defense', -2, Effects::UNTIL_NEXT_TURN, null, StatBlessing::ANYONE, false),
            ];
            self::$handlers = [];
            foreach ($list as $handler) {
                self::$handlers[$handler->name] = $handler;
            }
        }
        return self::$handlers;
    }
}
