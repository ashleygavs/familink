<?php

namespace App\Http\Requests\Concerns;

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
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function familyLinkRules(?int $barangayId): array
    {
        $notArchived = ResidentStatus::Archived->value;

        return [
            'father_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('gender', Gender::Male->value)->whereNot('status', $notArchived)],
            'mother_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('gender', Gender::Female->value)->whereNot('status', $notArchived)],
            'spouse_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->whereNull('spouse_id')->whereNot('status', $notArchived)],
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
            'spouse_id.exists' => 'That resident is not available as a spouse (already linked to a spouse or archived).',
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
            } elseif (! $parent->birthdate->lt($birthdate)) {
                $validator->errors()->add($field, "The {$label} must be older than the resident.");
            }
        }

        if ($subject !== null && $this->filled('spouse_id') && $this->integer('spouse_id') === $subject->id) {
            $validator->errors()->add('spouse_id', 'A person cannot be their own spouse.');
        }

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
            } elseif (! $child->birthdate->gt($birthdate)) {
                $validator->errors()->add('dependent_ids', "{$child->full_name} must be younger than the resident.");
            }
        }
    }
}
