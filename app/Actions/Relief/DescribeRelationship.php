<?php

namespace App\Actions\Relief;

/**
 * Says what one person is to another in a family tree, such as "Second cousin", "Aunt" or "Wife of uncle". It works
 * out the closest shared ancestor and counts the generations on each side. People who are not related by blood, but by
 * marriage, are described through their spouse. Each description also comes with a group ("Second cousins") and a
 * distance, so a list of relatives can be ordered from the closest to the furthest.
 *
 * @phpstan-type Relationship array{label: string, group: string, rank: int}
 */
class DescribeRelationship
{
    /** Where the people related only through marriage are listed. */
    public const MARRIAGE_GROUP = 'Related by marriage';

    /**
     * @var array<int, array<int, int>>
     */
    private array $ancestorCache = [];

    /**
     * @param  array<int, list<int>>  $parents  Each person's parents, by the person's id.
     * @param  array<int, string>  $genders  Male or Female, by id.
     * @param  array<int, int>  $spouses  Each married person's spouse, by id.
     */
    public function __construct(private array $parents, private array $genders, private array $spouses) {}

    /**
     * What $person is to $reference, for example "Aunt" when $person is the aunt of $reference. Null when the two are
     * the same person or no link is known.
     */
    public function between(int $reference, int $person): ?string
    {
        return $this->classify($reference, $person)['label'] ?? null;
    }

    /**
     * The same, with the group the person belongs to in a list of relatives and how far away they are.
     *
     * @return Relationship|null
     */
    public function classify(int $reference, int $person): ?array
    {
        if ($reference === $person) {
            return null;
        }

        $blood = $this->blood($reference, $person);

        if ($blood !== null) {
            return $blood;
        }

        // Either one may be the one still pointing to the other: a late spouse keeps pointing to a widow who married again.
        if (($this->spouses[$reference] ?? null) === $person || ($this->spouses[$person] ?? null) === $reference) {
            return ['label' => $this->term($person, 'Husband', 'Wife', 'Spouse'), 'group' => 'Husband or wife', 'rank' => 1];
        }

        $personsSpouse = $this->spouses[$person] ?? null;
        $viaTheirSpouse = $personsSpouse === null ? null : $this->blood($reference, $personsSpouse);

        if ($viaTheirSpouse !== null) {
            return [
                'label' => $this->term($person, 'Husband', 'Wife', 'Spouse').' of '.lcfirst($viaTheirSpouse['label']),
                'group' => self::MARRIAGE_GROUP,
                'rank' => $viaTheirSpouse['rank'] + 1,
            ];
        }

        $referencesSpouse = $this->spouses[$reference] ?? null;
        $viaMySpouse = $referencesSpouse === null ? null : $this->blood($referencesSpouse, $person);

        return $viaMySpouse === null ? null : [
            'label' => "Spouse's ".lcfirst($viaMySpouse['label']),
            'group' => self::MARRIAGE_GROUP,
            'rank' => $viaMySpouse['rank'] + 1,
        ];
    }

    /**
     * @return Relationship|null
     */
    private function blood(int $reference, int $person): ?array
    {
        $mine = $this->ancestors($reference);
        $theirs = $this->ancestors($person);
        $best = null;

        foreach ($mine as $ancestor => $up) {
            if (isset($theirs[$ancestor]) && ($best === null || $up + $theirs[$ancestor] < $best[0] + $best[1])) {
                $best = [$up, $theirs[$ancestor]];
            }
        }

        if ($best === null) {
            return null;
        }

        [$up, $down] = $best;

        return [
            'label' => $this->label($reference, $person, $up, $down),
            'group' => $this->group($up, $down),
            'rank' => $up + $down,
        ];
    }

    /**
     * What the person is called, given how many generations up each of them is from their closest shared ancestor.
     */
    private function label(int $reference, int $person, int $up, int $down): string
    {
        return match (true) {
            $down === 0 => $this->ancestorTerm($up, $person),
            $up === 0 => $this->descendantTerm($down, $person),
            $up === 1 && $down === 1 => $this->siblingTerm($reference, $person),
            $up === 1 => $this->nieceTerm($down, $person),
            $down === 1 => $this->auntTerm($up, $person),
            default => $this->cousinTerm($up, $down),
        };
    }

    /**
     * The heading the person is listed under with others of the same kind.
     */
    private function group(int $up, int $down): string
    {
        return match (true) {
            $down === 0 => $up === 1 ? 'Parents' : ($up === 2 ? 'Grandparents' : 'Great-'.str_repeat('great-', $up - 3).'grandparents'),
            $up === 0 => $down === 1 ? 'Children' : ($down === 2 ? 'Grandchildren' : 'Great-'.str_repeat('great-', $down - 3).'grandchildren'),
            $up === 1 && $down === 1 => 'Brothers and sisters',
            $up === 1 => $down === 2 ? 'Nieces and nephews' : ($down === 3 ? 'Grandnieces and grandnephews' : 'Great-'.str_repeat('great-', $down - 4).'grandnieces and grandnephews'),
            $down === 1 => $up === 2 ? 'Aunts and uncles' : ($up === 3 ? 'Granduncles and grandaunts' : 'Great-'.str_repeat('great-', $up - 4).'granduncles and grandaunts'),
            default => $this->cousinName($up, $down, plural: true),
        };
    }

    /**
     * The person and everyone above them, with how many generations up each one is.
     *
     * @return array<int, int>
     */
    private function ancestors(int $person): array
    {
        if (isset($this->ancestorCache[$person])) {
            return $this->ancestorCache[$person];
        }

        $found = [$person => 0];
        $queue = [$person];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->parents[$current] ?? [] as $parent) {
                if (! isset($found[$parent])) {
                    $found[$parent] = $found[$current] + 1;
                    $queue[] = $parent;
                }
            }
        }

        return $this->ancestorCache[$person] = $found;
    }

    private function term(int $person, string $male, string $female, string $neutral): string
    {
        return match ($this->genders[$person] ?? null) {
            'Male' => $male,
            'Female' => $female,
            default => $neutral,
        };
    }

    private function ancestorTerm(int $steps, int $person): string
    {
        $base = $this->term($person, 'father', 'mother', 'parent');

        if ($steps === 1) {
            return ucfirst($base);
        }

        $grand = 'grand'.$base;

        return $steps === 2 ? ucfirst($grand) : 'Great-'.str_repeat('great-', $steps - 3).$grand;
    }

    private function descendantTerm(int $steps, int $person): string
    {
        $base = $this->term($person, 'son', 'daughter', 'child');

        if ($steps === 1) {
            return ucfirst($base);
        }

        $grand = 'grand'.$base;

        return $steps === 2 ? ucfirst($grand) : 'Great-'.str_repeat('great-', $steps - 3).$grand;
    }

    private function siblingTerm(int $reference, int $person): string
    {
        $mine = $this->parents[$reference] ?? [];
        $theirs = $this->parents[$person] ?? [];
        $term = $this->term($person, 'brother', 'sister', 'sibling');

        // Only call them half-siblings when both have two parents on record and share just one. With a parent missing
        // from the records, a full brother or sister would look like a half one.
        $halfSiblings = count($mine) >= 2 && count($theirs) >= 2 && count(array_intersect($mine, $theirs)) === 1;

        return $halfSiblings ? 'Half-'.$term : ucfirst($term);
    }

    private function nieceTerm(int $steps, int $person): string
    {
        $base = $this->term($person, 'nephew', 'niece', 'niece or nephew');

        return match (true) {
            $steps === 2 => ucfirst($base),
            $steps === 3 => 'Grand'.$base,
            default => 'Great-'.str_repeat('great-', $steps - 4).'grand'.$base,
        };
    }

    private function auntTerm(int $steps, int $person): string
    {
        $base = $this->term($person, 'uncle', 'aunt', 'aunt or uncle');

        return match (true) {
            $steps === 2 => ucfirst($base),
            $steps === 3 => 'Grand'.$base,
            default => 'Great-'.str_repeat('great-', $steps - 4).'grand'.$base,
        };
    }

    private function cousinTerm(int $up, int $down): string
    {
        return $this->cousinName($up, $down, plural: false);
    }

    /**
     * "Second cousin once removed", or "Second cousins once removed" for a heading.
     */
    private function cousinName(int $up, int $down, bool $plural): string
    {
        $degree = min($up, $down) - 1;
        $removed = abs($up - $down);
        $ordinal = [1 => 'First', 2 => 'Second', 3 => 'Third'][$degree] ?? $degree.'th';
        $term = $ordinal.($plural ? ' cousins' : ' cousin');

        return match (true) {
            $removed === 0 => $term,
            $removed === 1 => $term.' once removed',
            $removed === 2 => $term.' twice removed',
            default => "{$term} {$removed} times removed",
        };
    }
}
