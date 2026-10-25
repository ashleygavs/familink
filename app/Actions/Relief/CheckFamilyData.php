<?php

namespace App\Actions\Relief;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Looks through the family links of the residents a user may see for records that cannot be right, such as a mother
 * born after her child or a person recorded as their own spouse. Bad links quietly break the family tree, the
 * relationship labels and the household review, so each one is listed with what is wrong and who to fix.
 *
 * It works on plain rows rather than full resident objects, because it reads every resident the viewer may see, which
 * is the whole province for a stakeholder.
 *
 * @phpstan-type Person array{id: int, name: string, birth: int, gender: string, father_id: int|null, mother_id: int|null, spouse_id: int|null, household_id: int|null, civil_status: string|null, deceased: bool, listed: bool, system_id: string, name_key: string}
 * @phpstan-type Found array<string, list<array{subject: Person, text: string}>>
 * @phpstan-type Problem array{resident_id: int, resident_name: string, household_id: int|null, household_code: string|null, barangay_name: string|null, can_open: bool, text: string}
 * @phpstan-type ProblemGroup array{type: string, title: string, explanation: string, total: int, problems: list<Problem>}
 */
class CheckFamilyData
{
    /** A parent should be at least this many years older than their child. */
    private const MINIMUM_PARENT_AGE_GAP = 12;

    /** Each group lists this many problems at most, the rest are counted. */
    private const LISTED_PER_GROUP = 50;

    private const TITLES = [
        'own_link' => ['Linked to themselves', 'A person is recorded as their own father, mother or spouse.'],
        'same_parent' => ['Same person as father and mother', 'The father and the mother of a child are the same person.'],
        'parent_gender' => ['Father or mother of the wrong gender', 'A father recorded as female, or a mother recorded as male. One of the two is a mistake.'],
        'parent_age' => ['Parent too close in age to the child', 'A parent who is born after their child, or less than 12 years before.'],
        'parent_loop' => ['Parents and children in a circle', 'Two people are each recorded as the other\'s parent.'],
        'spouse_one_way' => ['Marriage recorded on one side only', 'One person is recorded as married to another, but the other does not show the marriage back.'],
        'spouse_relative' => ['Married to a close relative', 'A person is recorded as married to their own parent, child, brother or sister.'],
        'future_birth' => ['Born in the future', 'A birthdate that has not happened yet.'],
        'duplicate' => ['Possibly registered twice', 'Two records with the same name and birthdate. They may be the same person.'],
        'civil_status' => ['Civil status does not match the family record', 'A person recorded as having a husband or wife, or a spouse who has died, whose civil status says something else.'],
    ];

    /**
     * @return array{scanned: int, total: int, groups: list<ProblemGroup>}
     */
    public function handle(User $user): array
    {
        $people = $this->people(Resident::query()->visibleTo($user)->whereNot('status', ResidentStatus::Archived->value));
        $scanned = $people->count();

        // Relatives may be in a barangay the user cannot see, but their records are still needed to judge the link.
        $missing = $people->flatMap(fn (array $person): array => [$person['father_id'], $person['mother_id'], $person['spouse_id']])
            ->filter(fn (?int $id): bool => $id !== null && ! $people->has($id))
            ->unique()
            ->values();

        $all = $missing->isEmpty() ? $people : $people->union($this->people(Resident::query()->whereIn('id', $missing->all())));

        /** @var Found $found */
        $found = array_fill_keys(array_keys(self::TITLES), []);

        foreach ($people as $person) {
            $this->checkPerson($person, $all, $found);
        }

        $this->checkDuplicates($people, $found);

        $households = Household::query()
            ->with('barangay')
            ->whereIn('id', $people->pluck('household_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $scope = $user->accessibleBarangayIds();

        $groups = [];
        $total = 0;

        foreach (self::TITLES as $type => [$title, $explanation]) {
            if ($found[$type] === []) {
                continue;
            }

            $total += count($found[$type]);

            $groups[] = [
                'type' => $type,
                'title' => $title,
                'explanation' => $explanation,
                'total' => count($found[$type]),
                'problems' => array_map(function (array $entry) use ($households, $scope): array {
                    $subject = $entry['subject'];
                    $household = $subject['household_id'] === null ? null : $households->get($subject['household_id']);

                    return [
                        'resident_id' => $subject['id'],
                        'resident_name' => $subject['name'],
                        'household_id' => $household?->id,
                        'household_code' => $household?->household_code,
                        'barangay_name' => $household?->barangay->name,
                        'can_open' => $household !== null && ($scope === null || in_array($household->barangay_id, $scope, true)),
                        'text' => $entry['text'],
                    ];
                }, array_slice($found[$type], 0, self::LISTED_PER_GROUP)),
            ];
        }

        return ['scanned' => $scanned, 'total' => $total, 'groups' => $groups];
    }

    /**
     * The residents of a query as small arrays keyed by id.
     *
     * @param  Builder<Resident>  $query
     * @return Collection<int, Person>
     */
    private function people($query): Collection
    {
        return $query->toBase()
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'gender', 'father_id', 'mother_id', 'spouse_id', 'household_id', 'civil_status', 'is_deceased', 'status', 'system_id_no', 'first_name_key', 'last_name_key'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => [
                'id' => (int) $row->id,
                'name' => implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name, $row->suffix])),
                'birth' => (int) strtotime((string) $row->birthdate),
                'gender' => (string) $row->gender,
                'father_id' => $row->father_id === null ? null : (int) $row->father_id,
                'mother_id' => $row->mother_id === null ? null : (int) $row->mother_id,
                'spouse_id' => $row->spouse_id === null ? null : (int) $row->spouse_id,
                'household_id' => $row->household_id === null ? null : (int) $row->household_id,
                'civil_status' => $row->civil_status === null ? null : (string) $row->civil_status,
                'deceased' => (bool) $row->is_deceased,
                'listed' => $row->status === ResidentStatus::Listed->value,
                'system_id' => (string) $row->system_id_no,
                'name_key' => $row->first_name_key.'|'.$row->last_name_key.'|'.substr((string) $row->birthdate, 0, 10),
            ]]);
    }

    /**
     * @param  Person  $person
     * @param  Collection<int, Person>  $all
     * @param  Found  $found
     */
    private function checkPerson(array $person, Collection $all, array &$found): void
    {
        $name = $person['name'];
        $father = $person['father_id'] === null ? null : $all->get($person['father_id']);
        $mother = $person['mother_id'] === null ? null : $all->get($person['mother_id']);
        $spouse = $person['spouse_id'] === null ? null : $all->get($person['spouse_id']);

        foreach (['father_id' => 'father', 'mother_id' => 'mother', 'spouse_id' => 'spouse'] as $column => $role) {
            if ($person[$column] === $person['id']) {
                $found['own_link'][] = ['subject' => $person, 'text' => "{$name} is recorded as their own {$role}."];
            }
        }

        if ($person['birth'] > time()) {
            $found['future_birth'][] = ['subject' => $person, 'text' => "{$name} has a birthdate of ".date('M j, Y', $person['birth']).', which has not happened yet.'];
        }

        if ($person['father_id'] !== null && $person['father_id'] === $person['mother_id']) {
            $found['same_parent'][] = ['subject' => $person, 'text' => "{$name} has the same person, ".($father['name'] ?? 'someone').', as both father and mother.'];
        }

        if ($father !== null && $father['id'] !== $person['id'] && $father['gender'] === 'Female') {
            $found['parent_gender'][] = ['subject' => $person, 'text' => "{$name}'s father, {$father['name']}, is recorded as female."];
        }

        if ($mother !== null && $mother['id'] !== $person['id'] && $mother['gender'] === 'Male') {
            $found['parent_gender'][] = ['subject' => $person, 'text' => "{$name}'s mother, {$mother['name']}, is recorded as male."];
        }

        foreach ([['father', $father], ['mother', $mother]] as [$role, $parent]) {
            if ($parent === null || $parent['id'] === $person['id']) {
                continue;
            }

            $years = round(($person['birth'] - $parent['birth']) / (365.25 * 86400), 1);

            if ($years < self::MINIMUM_PARENT_AGE_GAP) {
                $found['parent_age'][] = ['subject' => $person, 'text' => $years < 0
                    ? "{$name}'s {$role}, {$parent['name']}, was born after them."
                    : "{$name}'s {$role}, {$parent['name']}, was born only ".(int) floor($years).' years before them.'];
            }

            if ($person['id'] < $parent['id'] && in_array($person['id'], [$parent['father_id'], $parent['mother_id']], true)) {
                $found['parent_loop'][] = ['subject' => $person, 'text' => "{$name} and {$parent['name']} are each recorded as the other's parent."];
            }
        }

        if ($spouse === null || $spouse['id'] === $person['id']) {
            return;
        }

        $this->checkCivilStatus($person, $spouse, $found);

        if ($spouse['spouse_id'] !== $person['id']) {
            $found['spouse_one_way'][] = ['subject' => $person, 'text' => $spouse['spouse_id'] === null
                ? "{$name} is recorded as married to {$spouse['name']}, but {$spouse['name']} has no spouse recorded."
                : "{$name} is recorded as married to {$spouse['name']}, but {$spouse['name']} is recorded as married to someone else."];
        }

        $kin = match (true) {
            in_array($spouse['id'], [$person['father_id'], $person['mother_id']], true) => 'parent',
            in_array($person['id'], [$spouse['father_id'], $spouse['mother_id']], true) => 'child',
            $this->sharesParent($person, $spouse) => 'brother or sister',
            default => null,
        };

        if ($kin !== null && ($person['id'] < $spouse['id'] || $spouse['spouse_id'] !== $person['id'])) {
            $found['spouse_relative'][] = ['subject' => $person, 'text' => "{$name} is recorded as married to their own {$kin}, {$spouse['name']}."];
        }
    }

    /**
     * Only the contradictions that are certain: a living husband or wife recorded, but the civil status is not Married,
     * or a husband or wife who has died while the status still says Married. "Married" with nobody recorded is left
     * alone, because the husband or wife may simply live elsewhere or not be registered.
     *
     * @param  Person  $person
     * @param  Person  $spouse
     * @param  Found  $found
     */
    private function checkCivilStatus(array $person, array $spouse, array &$found): void
    {
        if ($person['deceased'] || $person['civil_status'] === null) {
            return;
        }

        $name = $person['name'];

        if ($spouse['deceased'] && $person['civil_status'] === 'Married') {
            $found['civil_status'][] = ['subject' => $person, 'text' => "{$name}'s husband or wife, {$spouse['name']}, has died, but {$name} is still recorded as Married. It should say Widowed."];
        }

        if (! $spouse['deceased'] && $person['civil_status'] !== 'Married') {
            $found['civil_status'][] = ['subject' => $person, 'text' => "{$name} is recorded as married to {$spouse['name']}, but the civil status says {$person['civil_status']}."];
        }
    }

    /**
     * Records with the same first name, last name and birthdate, each listed with the other record to compare it with.
     * Only people the viewer can see are compared, so nobody outside their area is revealed.
     *
     * @param  Collection<int, Person>  $people
     * @param  Found  $found
     */
    private function checkDuplicates(Collection $people, array &$found): void
    {
        foreach ($people->groupBy('name_key') as $group) {
            if ($group->count() < 2) {
                continue;
            }

            foreach ($group as $person) {
                $others = $group->where('id', '!=', $person['id'])
                    ->map(fn (array $other): string => $other['system_id'].($other['listed'] ? ' (listed by a family member)' : ''))
                    ->implode(', ');

                $found['duplicate'][] = [
                    'subject' => $person,
                    'text' => "{$person['name']}, born ".date('M j, Y', $person['birth']).', has another record with the same name and birthdate: '.$others.'.',
                ];
            }
        }
    }

    /**
     * @param  Person  $one
     * @param  Person  $other
     */
    private function sharesParent(array $one, array $other): bool
    {
        return ($one['father_id'] !== null && $one['father_id'] === $other['father_id'])
            || ($one['mother_id'] !== null && $one['mother_id'] === $other['mother_id']);
    }
}
