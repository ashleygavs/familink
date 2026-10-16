<?php

namespace App\Actions\Residents;

use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixes a mistake in a registration: a misspelt name, a wrong birthdate or gender. The resident keeps their ID, QR
 * code, family links and household. What was changed, and from what, is kept in their history.
 */
class CorrectResident
{
    /** A parent must be at least this many years older than their child, the same rule used at registration. */
    private const MINIMUM_PARENT_AGE_GAP = 15;

    /** The youngest age at which someone can be recorded as married. */
    private const MINIMUM_MARRIED_AGE = 16;

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'suffix' => 'Suffix',
        'maiden_name' => 'Maiden name',
        'birthdate' => 'Birthdate',
        'gender' => 'Gender',
    ];

    /**
     * @param  array<string, mixed>  $data  The validated fields: first_name, middle_name, last_name, suffix, maiden_name, birthdate and gender.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident, array $data): void
    {
        $new = $this->normalise($data);
        $old = $this->current($resident);
        $changed = array_keys(array_filter($new, fn (?string $value, string $field): bool => $value !== $old[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            throw ValidationException::withMessages(['first_name' => 'Nothing was changed.']);
        }

        $this->guard($resident, $new);

        DB::transaction(function () use ($actor, $resident, $new, $old, $changed): void {
            $resident->update([
                ...array_intersect_key($new, array_flip(['first_name', 'middle_name', 'last_name', 'suffix', 'maiden_name', 'gender'])),
                'birthdate' => $new['birthdate'],
                // A date an admin corrects to is a real date, not an estimate.
                'birthdate_estimated' => in_array('birthdate', $changed, true) ? false : $resident->birthdate_estimated,
            ]);

            ResidentChange::query()->create([
                'resident_id' => $resident->id,
                'changed_by_user_id' => $actor->id,
                'type' => ResidentChange::CORRECTION,
                'summary' => 'Corrected '.implode('; ', array_map(fn (string $field): string => $this->describe($field, $old[$field], $new[$field]), $changed)).'.',
                'data' => [
                    'before' => array_intersect_key($old, array_flip($changed)),
                    'after' => array_intersect_key($new, array_flip($changed)),
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function normalise(array $data): array
    {
        $text = fn (string $field): ?string => isset($data[$field]) && trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;

        return [
            'first_name' => $text('first_name'),
            'middle_name' => $text('middle_name'),
            'last_name' => $text('last_name'),
            'suffix' => $text('suffix'),
            'maiden_name' => $text('maiden_name'),
            'birthdate' => Carbon::parse((string) $data['birthdate'])->toDateString(),
            'gender' => $text('gender'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function current(Resident $resident): array
    {
        return [
            'first_name' => $resident->first_name,
            'middle_name' => $resident->middle_name,
            'last_name' => $resident->last_name,
            'suffix' => $resident->suffix,
            'maiden_name' => $resident->maiden_name,
            'birthdate' => $resident->birthdate->toDateString(),
            'gender' => $resident->gender,
        ];
    }

    private function describe(string $field, ?string $before, ?string $after): string
    {
        $show = fn (?string $value): string => $value === null ? 'none' : ($field === 'birthdate' ? Carbon::parse($value)->format('M j, Y') : $value);

        return self::LABELS[$field].' '.$show($before).' to '.$show($after);
    }

    /**
     * @param  array<string, string|null>  $new
     *
     * @throws ValidationException
     */
    private function guard(Resident $resident, array $new): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($resident->status === ResidentStatus::Archived) {
            $fail('first_name', 'An archived registration cannot be changed.');
        }

        $duplicate = Resident::query()
            ->whereKeyNot($resident->id)
            ->where('first_name_key', Resident::normalizeName($new['first_name']))
            ->where('last_name_key', Resident::normalizeName($new['last_name']))
            ->whereDate('birthdate', $new['birthdate'])
            ->whereNot('status', ResidentStatus::Archived->value)
            ->first();

        if ($duplicate !== null) {
            $fail('first_name', "{$duplicate->full_name} born on {$duplicate->birthdate->format('M j, Y')} is already registered (ID {$duplicate->system_id_no}).");
        }

        $birth = Carbon::parse((string) $new['birthdate']);

        foreach ([$resident->father, $resident->mother] as $parent) {
            if ($parent !== null && $parent->birthdate->copy()->addYears(self::MINIMUM_PARENT_AGE_GAP)->greaterThan($birth)) {
                $fail('birthdate', "{$parent->full_name} is recorded as a parent, so they must be at least ".self::MINIMUM_PARENT_AGE_GAP.' years older.');
            }
        }

        foreach ($resident->childrenAsFather->merge($resident->childrenAsMother) as $child) {
            if ($birth->copy()->addYears(self::MINIMUM_PARENT_AGE_GAP)->greaterThan($child->birthdate)) {
                $fail('birthdate', "{$child->full_name} is recorded as their child, so they must be at least ".self::MINIMUM_PARENT_AGE_GAP.' years older.');
            }
        }

        if ($resident->spouse_id !== null && $birth->age < self::MINIMUM_MARRIED_AGE) {
            $fail('birthdate', 'This resident is recorded as married, so they must be at least '.self::MINIMUM_MARRIED_AGE.' years old.');
        }

        if ($resident->childrenAsFather->isNotEmpty() && $new['gender'] !== Gender::Male->value) {
            $fail('gender', 'This resident is recorded as a father, so the gender must stay Male.');
        }

        if ($resident->childrenAsMother->isNotEmpty() && $new['gender'] !== Gender::Female->value) {
            $fail('gender', 'This resident is recorded as a mother, so the gender must stay Female.');
        }
    }
}
