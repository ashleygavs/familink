<?php

use App\Actions\Relief\DescribeRelationship;

/**
 * A small family. Great-grandparent 1 has two sons, 3 and 4. Under 3: the father 5 (with brother 17 and sister 7), and
 * under 5: the person 8 (the reference), brother 12 and half-brother 13. Under 4: 6, whose child 10 is a second cousin.
 */
function family(): DescribeRelationship
{
    $parents = [
        3 => [1], 4 => [1], 5 => [3], 7 => [3], 17 => [3], 6 => [4], 8 => [5, 25], 12 => [5, 25], 13 => [5, 23], 9 => [7], 26 => [9],
        10 => [6], 11 => [10], 15 => [20], 22 => [20], 24 => [8],
    ];
    $genders = [
        1 => 'Male', 3 => 'Male', 4 => 'Male', 5 => 'Male', 7 => 'Female', 17 => 'Male', 18 => 'Female', 6 => 'Female',
        8 => 'Male', 12 => 'Male', 13 => 'Male', 9 => 'Male', 10 => 'Female', 11 => 'Male', 15 => 'Female', 20 => 'Male',
        22 => 'Female', 24 => 'Female', 23 => 'Female', 25 => 'Female', 26 => 'Male',
    ];
    $spouses = [8 => 15, 15 => 8, 17 => 18, 18 => 17];

    return new DescribeRelationship($parents, $genders, $spouses);
}

it('names ancestors and descendants by generation', function (int $reference, int $person, string $expected) {
    expect(family()->between($reference, $person))->toBe($expected);
})->with([
    'father' => [8, 5, 'Father'],
    'grandfather' => [8, 3, 'Grandfather'],
    'great-grandfather' => [8, 1, 'Great-grandfather'],
    'son' => [5, 8, 'Son'],
    'grandson' => [3, 8, 'Grandson'],
    'great-grandson' => [1, 8, 'Great-grandson'],
    'daughter' => [8, 24, 'Daughter'],
]);

it('names brothers, sisters, aunts, uncles, nieces and nephews', function (int $reference, int $person, string $expected) {
    expect(family()->between($reference, $person))->toBe($expected);
})->with([
    'brother' => [8, 12, 'Brother'],
    'half-brother' => [8, 13, 'Half-brother'],
    'aunt' => [8, 7, 'Aunt'],
    'uncle' => [8, 17, 'Uncle'],
    'nephew' => [7, 8, 'Nephew'],
    'great-granddaughter' => [3, 24, 'Great-granddaughter'],
]);

it('names cousins by degree and by generations removed', function (int $reference, int $person, string $expected) {
    expect(family()->between($reference, $person))->toBe($expected);
})->with([
    'first cousin' => [8, 9, 'First cousin'],
    'second cousin' => [8, 10, 'Second cousin'],
    'second cousin once removed' => [8, 11, 'Second cousin once removed'],
    'first cousin once removed' => [8, 26, 'First cousin once removed'],
]);

it('describes people who are related through marriage', function (int $reference, int $person, string $expected) {
    expect(family()->between($reference, $person))->toBe($expected);
})->with([
    'wife' => [8, 15, 'Wife'],
    'wife of an uncle' => [8, 18, 'Wife of uncle'],
    'spouse\'s sister' => [8, 22, "Spouse's sister"],
]);

it('gives no label to the same person or to people with no known link', function () {
    expect(family()->between(8, 8))->toBeNull()
        ->and(family()->between(8, 99))->toBeNull()
        ->and(family()->between(8, 23))->toBeNull();
});

it('calls only people with two known parents, sharing one, half-siblings', function () {
    $family = new DescribeRelationship([2 => [1], 3 => [1]], [], []);

    expect($family->between(2, 1))->toBe('Parent')->and($family->between(1, 2))->toBe('Child')->and($family->between(2, 3))->toBe('Sibling');
});
