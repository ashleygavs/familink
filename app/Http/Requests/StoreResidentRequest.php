<?php

namespace App\Http\Requests;

use App\Actions\Residents\FindFamilyMatches;
use App\Enums\CivilStatus;
use App\Enums\Gender;
use App\Enums\HouseholdRole;
use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Http\Requests\Concerns\ValidatesFamilyLinks;
use App\Http\Requests\Concerns\ValidatesSuggestionFeedback;
use App\Models\Household;
use App\Models\Resident;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreResidentRequest extends FormRequest
{
    use ValidatesFamilyLinks;
    use ValidatesSuggestionFeedback;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalise names, apply defaults and force encoders into their own barangay.
     */
    protected function prepareForValidation(): void
    {
        $claimed = filled($this->input('claim_resident_id'));
        $path = $this->input('path') === 'new' ? 'new' : 'existing';

        $input = [
            ...$this->titleCase(['first_name', 'middle_name', 'last_name', 'maiden_name']),
            'path' => $path,
            'is_head' => $this->boolean('is_head'),
            'consent' => $this->boolean('consent'),
            'barangay_id' => $this->user()->barangay_id ?? $this->input('barangay_id'),
        ];

        if ($path === 'new' && ! $claimed) {
            $input += ['father_id' => null, 'mother_id' => null, 'spouse_id' => null, 'dependent_ids' => [], 'via_relatives' => null];
            $input['relatives'] = $this->normalisedRelatives();
        } else {
            $input['relatives'] = null;
            $input['via_relatives'] = $this->namedRelatives();
        }

        $this->merge($input);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $barangayId = $this->barangayId();

        return [
            'path' => ['required', Rule::in(['new', 'existing'])],
            'claim_resident_id' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('status', ResidentStatus::Listed->value)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'maiden_name' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required', 'date', 'before_or_equal:today', 'after:1899-12-31'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'civil_status' => ['required', Rule::enum(CivilStatus::class)],
            'barangay_id' => ['required', 'integer', Rule::exists('barangays', 'id')],
            'household_mode' => ['required', Rule::in(['existing', 'new'])],
            'household_id' => [
                'required_if:household_mode,existing',
                'nullable',
                Rule::exists('households', 'id')->where('barangay_id', $barangayId),
            ],
            'street_address' => ['required_if:household_mode,new', 'nullable', 'string', 'max:255'],
            'is_head' => ['boolean'],
            'household_role' => [
                Rule::requiredIf(! $this->boolean('is_head')),
                'nullable',
                Rule::enum(HouseholdRole::class)->except(HouseholdRole::Head),
            ],
            'consent' => ['boolean'],
            ...$this->familyLinkRules($barangayId),
            ...$this->suggestionFeedbackRules(),
            'via_relatives' => ['nullable', 'array'],
            'via_relatives.*' => ['array', 'max:10'],
            'via_relatives.*.*' => ['integer', Rule::exists('residents', 'id')->whereNot('status', ResidentStatus::Archived->value)],
            'relatives' => ['nullable', 'array'],
            'relatives.parents_partners' => ['boolean'],
            'relatives.children' => ['nullable', 'array', 'max:15'],
            ...$this->relativeRules('relatives.father', required: false, needsGender: false),
            ...$this->relativeRules('relatives.mother', required: false, needsGender: false),
            ...$this->relativeRules('relatives.spouse', required: false, needsGender: true),
            ...$this->relativeRules('relatives.children.*', required: true, needsGender: true, isChild: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->familyLinkMessages(),
            'household_id.exists' => 'Please choose a household from the same barangay.',
            'claim_resident_id.exists' => 'That listed record is no longer available.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->guardClaim($validator);
                $this->guardDuplicate($validator);
                $this->guardFamilyLinks($validator, $this->date('birthdate'), (string) $this->input('gender'));
                $this->guardHead($validator);
                $this->guardNamedRelatives($validator);
                $this->guardRelatives($validator);
            },
        ];
    }

    /**
     * The relatives the person named (cousins, uncles...), as relative kind => resident ids. Only known
     * kinds are kept, and a single id is accepted as a list of one.
     *
     * @return array<string, list<mixed>>
     */
    private function namedRelatives(): array
    {
        $named = $this->input('via_relatives');

        if (! is_array($named)) {
            return [];
        }

        $kept = [];

        foreach (array_intersect_key($named, array_flip(RelativeKind::values())) as $kind => $ids) {
            $ids = array_values(array_filter(is_array($ids) ? $ids : [$ids], fn (mixed $id) => filled($id)));

            if ($ids !== []) {
                $kept[$kind] = $ids;
            }
        }

        return $kept;
    }

    private function barangayId(): ?int
    {
        $barangayId = $this->user()->barangay_id ?? $this->input('barangay_id');

        return $barangayId === null ? null : (int) $barangayId;
    }

    /**
     * @return array<string, mixed>
     */
    private function relativeRules(string $prefix, bool $required, bool $needsGender, bool $isChild = false): array
    {
        $present = $required ? 'required' : Rule::requiredIf(fn () => is_array($this->input($prefix)));

        $rules = [
            "{$prefix}.first_name" => [$present, 'string', 'max:100'],
            "{$prefix}.middle_name" => ['nullable', 'string', 'max:100'],
            "{$prefix}.last_name" => [$present, 'string', 'max:100'],
            "{$prefix}.birthdate" => ['nullable', 'date', 'before_or_equal:today', 'after:1899-12-31'],
            "{$prefix}.approximate_age" => ['nullable', 'integer', 'between:0,120'],
            "{$prefix}.is_deceased" => ['boolean'],
            "{$prefix}.lives_with_me" => ['boolean'],
        ];

        if ($needsGender) {
            $rules["{$prefix}.gender"] = [$present, Rule::enum(Gender::class)];
        }

        if ($isChild) {
            $rules["{$prefix}.other_parent_is_spouse"] = ['boolean'];
        }

        return $rules;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    private function titleCase(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            $value = $this->input($field);
            $names[$field] = is_string($value) && trim($value) !== '' ? $this->properCase($value) : null;
        }

        return $names;
    }

    /**
     * Title-case relatives' names and fix the gender of the father and mother.
     *
     * @return array<string, mixed>|null
     */
    private function normalisedRelatives(): ?array
    {
        $relatives = $this->input('relatives');

        if (! is_array($relatives)) {
            return null;
        }

        $normalise = function (mixed $relative, ?string $gender = null): mixed {
            if (! is_array($relative)) {
                return null;
            }

            $names = [];

            foreach (['first_name', 'middle_name', 'last_name'] as $field) {
                $value = $relative[$field] ?? null;
                $names[$field] = is_string($value) && trim($value) !== '' ? $this->properCase($value) : null;
            }

            return [...$relative, ...$names, ...($gender ? ['gender' => $gender] : [])];
        };

        return [
            'father' => $normalise($relatives['father'] ?? null, Gender::Male->value),
            'mother' => $normalise($relatives['mother'] ?? null, Gender::Female->value),
            'spouse' => $normalise($relatives['spouse'] ?? null),
            'children' => array_map(fn ($child) => $normalise($child), array_values((array) ($relatives['children'] ?? []))),
            'parents_partners' => (bool) ($relatives['parents_partners'] ?? true),
        ];
    }

    private function guardClaim(Validator $validator): void
    {
        if (! $this->filled('claim_resident_id')) {
            return;
        }

        $matches = app(FindFamilyMatches::class)->handle($this->personForMatching(), $this->user()->barangay_id)['claims'];

        if (! collect($matches)->contains(fn (array $match) => $match['resident']->id === $this->integer('claim_resident_id'))) {
            $validator->errors()->add('claim_resident_id', 'That listed record does not match the name and birthdate you entered.');
        }
    }

    private function guardDuplicate(Validator $validator): void
    {
        $existing = Resident::query()
            ->where('first_name', $this->input('first_name'))
            ->where('last_name', $this->input('last_name'))
            ->whereDate('birthdate', $this->input('birthdate'))
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when($this->filled('claim_resident_id'), fn ($query) => $query->whereKeyNot($this->integer('claim_resident_id')))
            ->first();

        if ($existing === null) {
            return;
        }

        $validator->errors()->add('first_name', $existing->status === ResidentStatus::Listed
            ? "{$existing->full_name} was already listed by a family member. Choose \"Yes, this is me\" to use that record."
            : "{$existing->full_name} born on {$existing->birthdate->format('M d, Y')} is already registered (ID {$existing->system_id_no}).");
    }

    private function guardNamedRelatives(Validator $validator): void
    {
        $claimedId = $this->filled('claim_resident_id') ? $this->integer('claim_resident_id') : null;

        foreach ((array) $this->input('via_relatives', []) as $kind => $ids) {
            if ($claimedId !== null && in_array($claimedId, array_map('intval', (array) $ids), true)) {
                $validator->errors()->add("via_relatives.{$kind}", 'A person cannot be their own relative.');
            }
        }
    }

    private function guardHead(Validator $validator): void
    {
        if (! $this->boolean('is_head') || $this->input('household_mode') !== 'existing') {
            return;
        }

        $head = Household::query()->find($this->integer('household_id'))?->head;

        if ($head !== null && $head->id !== $this->integer('claim_resident_id')) {
            $validator->errors()->add('is_head', "This household already has a head ({$head->full_name}).");
        }
    }

    /**
     * Check the relatives a first registrant declares: complete, plausible and not already on record.
     */
    private function guardRelatives(Validator $validator): void
    {
        $entries = $this->relativeEntries();

        if ($entries === []) {
            return;
        }

        if (! $this->boolean('consent')) {
            $validator->errors()->add('consent', 'Please confirm that you have permission to share your relatives\' details.');
        }

        $registrantBirth = $this->date('birthdate');
        $seen = [$this->identityKey($this->input('first_name'), $this->input('last_name'), $registrantBirth->toDateString())];

        foreach ($entries as $key => $relative) {
            $birth = $this->relativeBirthdate($relative);

            if ($birth === null) {
                $validator->errors()->add("{$key}.birthdate", 'Enter the birthdate, or the approximate age if you are not sure.');

                continue;
            }

            $this->guardRelativeAge($validator, $key, $birth, $registrantBirth);
            $identity = $this->identityKey($relative['first_name'], $relative['last_name'], $birth->toDateString());

            if (in_array($identity, $seen, true)) {
                $validator->errors()->add("{$key}.first_name", 'This person appears twice in the family list.');

                continue;
            }

            $seen[] = $identity;
            $existing = $this->existingResident($relative, $birth);

            if ($existing !== null) {
                $validator->errors()->add("{$key}.first_name", "{$existing->full_name} is already on record (ID {$existing->system_id_no}). Go back and choose \"Join an existing family record\" to link them instead.");
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function relativeEntries(): array
    {
        $relatives = (array) $this->input('relatives', []);
        $entries = [];

        foreach (['father', 'mother', 'spouse'] as $slot) {
            if (is_array($relatives[$slot] ?? null)) {
                $entries["relatives.{$slot}"] = $relatives[$slot];
            }
        }

        foreach (array_values((array) ($relatives['children'] ?? [])) as $index => $child) {
            $entries["relatives.children.{$index}"] = (array) $child;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function relativeBirthdate(array $relative): ?CarbonInterface
    {
        if (! empty($relative['birthdate'])) {
            return Carbon::parse($relative['birthdate'])->startOfDay();
        }

        $age = $relative['approximate_age'] ?? null;

        return $age === null || $age === '' ? null : now()->subYears((int) $age)->startOfYear();
    }

    private function guardRelativeAge(Validator $validator, string $key, CarbonInterface $birth, CarbonInterface $registrantBirth): void
    {
        $olderBy = (int) $birth->diffInYears($registrantBirth, false);

        if (str_ends_with($key, 'father') || str_ends_with($key, 'mother')) {
            if ($olderBy < 15) {
                $validator->errors()->add("{$key}.birthdate", 'A parent must be at least 15 years older than you.');
            }
        } elseif (str_contains($key, 'children') && $olderBy > -15) {
            $validator->errors()->add("{$key}.birthdate", 'A child must be at least 15 years younger than you.');
        }
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function existingResident(array $relative, CarbonInterface $birth): ?Resident
    {
        $estimated = empty($relative['birthdate']);

        return Resident::query()
            ->where('first_name_key', Resident::normalizeName($relative['first_name']))
            ->where('last_name_key', Resident::normalizeName($relative['last_name']))
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when(
                $estimated,
                fn ($query) => $query->whereBetween('birthdate', [$birth->copy()->subYears(2)->startOfYear(), $birth->copy()->addYears(2)->endOfYear()]),
                fn ($query) => $query->whereDate('birthdate', $birth->toDateString()),
            )
            ->first();
    }

    private function identityKey(?string $firstName, ?string $lastName, string $birthdate): string
    {
        return Resident::normalizeName($firstName).'|'.Resident::normalizeName($lastName).'|'.$birthdate;
    }

    /**
     * @return array{first_name: string, middle_name: string|null, maiden_name: string|null, last_name: string, birthdate: string, gender: string, household_id: int|null, barangay_id: int|null}
     */
    private function personForMatching(): array
    {
        return [
            'first_name' => (string) $this->input('first_name'),
            'middle_name' => $this->input('middle_name'),
            'maiden_name' => $this->input('maiden_name'),
            'last_name' => (string) $this->input('last_name'),
            'birthdate' => (string) $this->input('birthdate'),
            'gender' => (string) $this->input('gender'),
            'civil_status' => $this->input('civil_status'),
            'household_id' => null,
            'barangay_id' => $this->barangayId(),
        ];
    }

    /**
     * Capitalise a name typed in all lower or all upper case ("dela cruz", "CARIÑO"), but keep names
     * that were typed with their own capitals ("McKenzie", "De La Cruz") exactly as written.
     */
    private function properCase(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value === mb_strtolower($value) || $value === mb_strtoupper($value)
            ? Str::title($value)
            : $value;
    }
}
