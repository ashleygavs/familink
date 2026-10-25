<?php

namespace App\Http\Requests\Concerns;

use App\Actions\Residents\MarriageRules;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for linking a resident to a father, mother, spouse and children.
 *
 * @mixin FormRequest
 */
trait ValidatesFamilyLinks
{
    /** A parent must be at least this many years older than the child, the same limit the data check uses. */
    private const MINIMUM_PARENT_AGE_GAP = 12;

    /** The youngest age at which someone can be recorded as married. */
    private const MINIMUM_MARRIED_AGE = 16;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function familyLinkRules(?int $barangayId): array
    {
        $notArchived = ResidentStatus::Archived->value;

        return [
            'father_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('gender', Gender::Male->value)->whereNot('status', $notArchived)],
            'mother_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('gender', Gender::Female->value)->whereNot('status', $notArchived)],
            'spouse_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->whereNot('status', $notArchived)],
            'dependent_ids' => ['nullable', 'array', 'max:20'],
            'dependent_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('residents', 'id')->where('barangay_id', $barangayId)->whereNot('status', $notArchived),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function familyLinkMessages(): array
    {
        return [
            'father_id.exists' => 'Please choose a male, active resident as the father.',
            'mother_id.exists' => 'Please choose a female, active resident as the mother.',
            'spouse_id.exists' => 'Please choose a registered resident as the husband or wife.',
            'dependent_ids.*.exists' => 'One of the chosen children is not available in this barangay.',
        ];
    }

    /**
     * Check ages and slots of the chosen relatives. Run after the basic rules passed.
     *
     * @param  Resident|null  $subject  The resident being linked, when they already exist.
     */
    protected function guardFamilyLinks(Validator $validator, CarbonInterface $birthdate, string $gender, ?Resident $subject = null): void
    {
        foreach (['father_id' => 'father', 'mother_id' => 'mother'] as $field => $label) {
            $parent = $this->filled($field) ? Resident::query()->find($this->integer($field)) : null;

            if ($parent === null) {
                continue;
            }

            if ($subject !== null && $parent->id === $subject->id) {
                $validator->errors()->add($field, "A person cannot be their own {$label}.");
            } elseif (! $this->isOlderBy($parent->birthdate, $birthdate)) {
                $validator->errors()->add($field, "The {$label} must be at least ".self::MINIMUM_PARENT_AGE_GAP.' years older than the resident.');
            }
        }

        $this->guardSpouse($validator, $birthdate, $subject);

        $this->guardDependents($validator, $birthdate, $gender, $subject);
    }

    private function guardDependents(Validator $validator, CarbonInterface $birthdate, string $gender, ?Resident $subject): void
    {
        $dependentIds = (array) $this->input('dependent_ids', []);

        if ($dependentIds === []) {
            return;
        }

        $parentColumn = $gender === Gender::Male->value ? 'father_id' : 'mother_id';
        $linked = array_map('intval', array_filter([$this->input('father_id'), $this->input('mother_id'), $this->input('spouse_id')]));

        foreach (Resident::query()->whereIn('id', $dependentIds)->get() as $child) {
            $currentParent = $child->{$parentColumn};

            if ($subject !== null && $child->id === $subject->id) {
                $validator->errors()->add('dependent_ids', 'A person cannot be their own child.');
            } elseif (in_array($child->id, $linked, true)) {
                $validator->errors()->add('dependent_ids', "{$child->full_name} is already chosen as a parent or spouse.");
            } elseif ($currentParent !== null && ($subject === null || $currentParent !== $subject->id)) {
                $validator->errors()->add('dependent_ids', "{$child->full_name} already has a ".($parentColumn === 'father_id' ? 'father' : 'mother').' on record.');
            } elseif (! $this->isOlderBy($birthdate, $child->birthdate)) {
                $validator->errors()->add('dependent_ids', "{$child->full_name} must be at least ".self::MINIMUM_PARENT_AGE_GAP.' years younger than the resident.');
            }
        }
    }

    /**
     * Whether the first person was born at least the minimum parent age gap before the second.
     */
    private function isOlderBy(CarbonInterface $older, CarbonInterface $younger): bool
    {
        return round(($younger->getTimestamp() - $older->getTimestamp()) / (365.25 * 86400), 1) >= self::MINIMUM_PARENT_AGE_GAP;
    }

    /**
     * A spouse must be an adult who is not the person's own parent, child, brother or sister, judging by the links being
     * saved together with the ones already on record.
     */
    private function guardSpouse(Validator $validator, CarbonInterface $birthdate, ?Resident $subject): void
    {
        if (! $this->filled('spouse_id')) {
            return;
        }

        $spouse = Resident::query()->find($this->integer('spouse_id'));

        if ($subject !== null && $this->integer('spouse_id') === $subject->id) {
            $validator->errors()->add('spouse_id', 'A person cannot be their own spouse.');

            return;
        }

        if ($spouse === null) {
            return;
        }

        // Linking a spouse also writes to the other person's record, so they must be someone this user may work with:
        // the same barangays as "Record a marriage".
        if (! Resident::query()->visibleTo($this->user())->whereKey($spouse->id)->exists()) {
            $validator->errors()->add('spouse_id', 'You can only link a husband or wife who lives in a barangay you handle.');

            return;
        }

        $problem = app(MarriageRules::class)->linkProblem($spouse);

        if ($problem !== null) {
            $validator->errors()->add('spouse_id', $problem);

            return;
        }

        $fatherId = $this->filled('father_id') ? $this->integer('father_id') : $subject?->father_id;
        $motherId = $this->filled('mother_id') ? $this->integer('mother_id') : $subject?->mother_id;
        $childIds = array_map('intval', (array) $this->input('dependent_ids', []));

        $relation = match (true) {
            in_array($spouse->id, [$fatherId, $motherId], true) || ($subject !== null && in_array($spouse->id, [$subject->father_id, $subject->mother_id], true)) => 'parent',
            in_array($spouse->id, $childIds, true) || ($subject !== null && in_array($subject->id, [$spouse->father_id, $spouse->mother_id], true)) => 'child',
            ($fatherId !== null && $fatherId === $spouse->father_id) || ($motherId !== null && $motherId === $spouse->mother_id) => 'brother or sister',
            default => null,
        };

        if ($relation !== null) {
            $validator->errors()->add('spouse_id', "A person cannot be married to their own {$relation}.");
        }

        if ($birthdate->age < self::MINIMUM_MARRIED_AGE || $spouse->birthdate->age < self::MINIMUM_MARRIED_AGE) {
            $validator->errors()->add('spouse_id', 'Both people must be at least '.self::MINIMUM_MARRIED_AGE.' years old to be recorded as married.');
        }
    }
}
