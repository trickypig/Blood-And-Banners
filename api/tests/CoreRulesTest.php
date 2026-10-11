<?php
declare(strict_types=1);

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\HexLineOfSight;
use BloodAndBanners\Rules\InitiativeResolver;
use BloodAndBanners\Rules\RandomSource;

final class SequenceRandom implements RandomSource
{
    public function __construct(private array $values)
    {
    }

    public function roll(int $sides): int
    {
        if ($this->values === []) {
            throw new RuntimeException('test random sequence exhausted');
        }
        $value = array_shift($this->values);
        if ($value < 1 || $value > $sides) {
            throw new RuntimeException("test roll {$value} is outside d{$sides}");
        }
        return $value;
    }
}

function core_rules_tests(): array
{
    return [
        'custom d6 records three swords two shields and one blank' => static function (): void {
            $result = (new CustomDice())->roll(6, new SequenceRandom([1, 2, 3, 4, 5, 6]));

            expect_same(['sword', 'sword', 'sword', 'shield', 'shield', 'blank'], $result['faces'], 'face mapping should match the physical die');
            expect_same(3, $result['swords'], 'attack successes should count swords');
            expect_same(2, $result['shields'], 'defense successes should count shields');
        },

        'initiative rerolls only tied participants until order is unique' => static function (): void {
            $result = (new InitiativeResolver())->resolve(
                ['red', 'blue', 'green'],
                new SequenceRandom([10, 10, 5, 7, 12])
            );

            expect_same(['blue', 'red', 'green'], $result['order'], 'higher unique initiative should act first');
            expect_same([10, 7], $result['rolls']['red'], 'red should record its initial roll and reroll');
            expect_same([10, 12], $result['rolls']['blue'], 'blue should record its initial roll and reroll');
            expect_same([5], $result['rolls']['green'], 'untied green should not reroll');
        },

        'line of sight succeeds when at least one shortest ray is clear' => static function (): void {
            $board = [
                '0,0' => test_hex(0, 0),
                '1,0' => test_hex(1, 0, occupant: 'blocker'),
                '0,1' => test_hex(0, 1),
                '1,1' => test_hex(1, 1),
            ];

            expect_true(
                (new HexLineOfSight())->isVisible('0,0', '1,1', $board),
                'the clear alternate shortest ray should preserve visibility'
            );
        },

        'line of sight fails when every shortest ray is blocked' => static function (): void {
            $board = [
                '0,0' => test_hex(0, 0),
                '1,0' => test_hex(1, 0, sightBlocker: true),
                '0,1' => test_hex(0, 1, elevation: 3),
                '1,1' => test_hex(1, 1, elevation: 1),
            ];

            expect_same(false, (new HexLineOfSight())->isVisible('0,0', '1,1', $board), 'obstacle and +2 elevation should block both rays');
        },
    ];
}

function test_hex(
    int $q,
    int $r,
    int $elevation = 0,
    bool $sightBlocker = false,
    ?string $occupant = null,
): array {
    return [
        'q' => $q,
        'r' => $r,
        'elevation' => $elevation,
        'sightBlocker' => $sightBlocker,
        'occupantId' => $occupant,
    ];
}
