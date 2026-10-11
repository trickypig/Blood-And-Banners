<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules;

use InvalidArgumentException;

final class InitiativeResolver
{
    public function resolve(array $participants, RandomSource $random): array
    {
        if ($participants === [] || count($participants) !== count(array_unique($participants))) {
            throw new InvalidArgumentException('Initiative participants must be a non-empty unique list.');
        }
        $current = [];
        $rolls = [];
        foreach ($participants as $participant) {
            $current[$participant] = $random->roll(20);
            $rolls[$participant] = [$current[$participant]];
        }

        while (count($current) !== count(array_unique(array_values($current)))) {
            $groups = [];
            foreach ($current as $participant => $value) {
                $groups[$value][] = $participant;
            }
            foreach ($groups as $tied) {
                if (count($tied) < 2) {
                    continue;
                }
                foreach ($tied as $participant) {
                    $current[$participant] = $random->roll(20);
                    $rolls[$participant][] = $current[$participant];
                }
            }
        }

        arsort($current, SORT_NUMERIC);
        return ['order' => array_keys($current), 'rolls' => $rolls, 'final' => $current];
    }
}
