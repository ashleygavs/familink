<?php

namespace App\Actions\Residents;

use App\Enums\Gender;
use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ListRelatives
{
    public function __construct(private GenerateIdentifiers $identifiers) {}

    /**
     * Create the relatives a first registrant declared (father, mother, spouse, children) as Listed
     * residents and link them to the registrant. Listed relatives cannot claim relief until they register.
     *
     * @param  array<string, mixed>  $relatives
     */
    public function handle(Resident $registrant, Barangay $barangay, Household $household, array $relatives): void
    {
        $father = $this->list($registrant, $barangay, $household, $relatives['father'] ?? null, Gender::Male->value, HouseholdRole::Parent);
        $mother = $this->list($registrant, $barangay, $household, $relatives['mother'] ?? null, Gender::Female->value, HouseholdRole::Parent);
        $spouseData = $relatives['spouse'] ?? null;
        $spouse = $this->list($registrant, $barangay, $household, $spouseData, (string) ($spouseData['gender'] ?? ''), HouseholdRole::Spouse, ['civil_status' => 'Married']);

        $links = array_filter([
            'father_id' => $father?->id,
            'mother_id' => $mother?->id,
            'spouse_id' => $spouse?->id,
        ]);

        if ($links !== []) {
            $registrant->update($links);
        }

        $spouse?->update(['spouse_id' => $registrant->id]);

        if ($father !== null && $mother !== null && ($relatives['parents_partners'] ?? true)) {
            $father->update(['spouse_id' => $mother->id]);
            $mother->update(['spouse_id' => $father->id]);
        }

        foreach ((array) ($relatives['children'] ?? []) as $childData) {
            $this->listChild($registrant, $barangay, $household, $spouse, $childData);
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $extra
     */
    private function list(Resident $registrant, Barangay $barangay, Household $household, ?array $data, string $gender, HouseholdRole $role, array $extra = []): ?Resident
    {
        if ($data === null) {
            return null;
        }

        [$birthdate, $estimated] = $this->birthdate($data);
        $livesWithMe = (bool) ($data['lives_with_me'] ?? false) && ! ($data['is_deceased'] ?? false);

        return Resident::query()->create([
            'system_id_no' => $this->identifiers->systemId($barangay),
            'qr_code_hash' => $this->identifiers->qrHash(),
            'household_id' => $livesWithMe ? $household->id : null,
            'barangay_id' => $livesWithMe ? $barangay->id : null,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'birthdate' => $birthdate,
            'birthdate_estimated' => $estimated,
            'gender' => $gender,
            'household_role' => $livesWithMe ? $role->value : null,
            'status' => ResidentStatus::Listed,
            'is_deceased' => (bool) ($data['is_deceased'] ?? false),
            'listed_by_resident_id' => $registrant->id,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listChild(Resident $registrant, Barangay $barangay, Household $household, ?Resident $spouse, array $data): void
    {
        $child = $this->list($registrant, $barangay, $household, $data, (string) $data['gender'], HouseholdRole::Child);

        if ($child === null) {
            return;
        }

        $registrantIsFather = $registrant->gender === Gender::Male->value;
        $links = [$registrantIsFather ? 'father_id' : 'mother_id' => $registrant->id];

        if ($spouse !== null && ($data['other_parent_is_spouse'] ?? true) && $spouse->gender !== $registrant->gender) {
            $links[$registrantIsFather ? 'mother_id' : 'father_id'] = $spouse->id;
        }

        $child->update($links);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonInterface, 1: bool}
     */
    private function birthdate(array $data): array
    {
        if (! empty($data['birthdate'])) {
            return [Carbon::parse($data['birthdate']), false];
        }

        return [now()->subYears((int) $data['approximate_age'])->startOfYear(), true];
    }
}
