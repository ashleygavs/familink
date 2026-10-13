<?php

namespace App\Actions\Residents;

use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\RelativeLink;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisterResident
{
    public function __construct(
        private GenerateIdentifiers $identifiers,
        private LinkRelatives $linkRelatives,
        private ListRelatives $listRelatives,
    ) {}

    /**
     * Register a resident as Pending, creating or joining a household.
     *
     * A first registrant ("new" path) declares their relatives, who are created as Listed residents.
     * Otherwise the resident is linked to relatives already on record. A registrant may also claim a
     * Listed record that a family member created for them earlier.
     *
     * @param  array<string, mixed>  $data  Validated input from StoreResidentRequest.
     */
    public function handle(User $actor, array $data): Resident
    {
        return retry(
            3,
            fn () => DB::transaction(fn () => $this->register($actor, $data)),
            0,
            fn (Throwable $exception) => $exception instanceof UniqueConstraintViolationException,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function register(User $actor, array $data): Resident
    {
        $barangay = Barangay::query()->findOrFail((int) ($actor->barangay_id ?? $data['barangay_id']));
        $household = $this->resolveHousehold($barangay, $data);
        $isHead = (bool) ($data['is_head'] ?? false);
        $role = $isHead ? HouseholdRole::Head->value : ($data['household_role'] ?? null);
        $claimed = ($data['claim_resident_id'] ?? null) !== null;

        $resident = $claimed
            ? $this->claim($data, $barangay, $household, $role)
            : $this->create($data, $barangay, $household, $role);

        if ($isHead) {
            $household->update(['head_resident_id' => $resident->id]);
        }

        if (! $claimed && ($data['path'] ?? 'existing') === 'new') {
            $this->listRelatives->handle($resident, $barangay, $household, (array) ($data['relatives'] ?? []));
        } else {
            $this->linkRelatives->handle($resident, $data);
        }

        $this->declareRelatives($resident, (array) ($data['via_relatives'] ?? []));

        return $resident;
    }

    /**
     * Remember the relatives the person named, such as a cousin or an uncle, even when their own parents
     * cannot be worked out from them.
     *
     * @param  array<string, mixed>  $declared  Relative kind => list of resident ids.
     */
    private function declareRelatives(Resident $resident, array $declared): void
    {
        foreach ($declared as $kind => $relativeIds) {
            foreach ((array) $relativeIds as $relativeId) {
                if ((int) $relativeId === $resident->id) {
                    continue;
                }

                RelativeLink::query()->updateOrCreate(
                    ['resident_id' => $resident->id, 'relative_id' => (int) $relativeId],
                    ['relation' => $kind],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, Barangay $barangay, Household $household, ?string $role): Resident
    {
        return Resident::query()->create([
            'system_id_no' => $this->identifiers->systemId($barangay),
            'qr_code_hash' => $this->identifiers->qrHash(),
            'household_id' => $household->id,
            'barangay_id' => $barangay->id,
            'household_role' => $role,
            'status' => ResidentStatus::Pending,
            ...$this->personalFields($data),
        ]);
    }

    /**
     * Turn the Listed record a relative created into the registrant's own, pending record.
     *
     * @param  array<string, mixed>  $data
     */
    private function claim(array $data, Barangay $barangay, Household $household, ?string $role): Resident
    {
        $resident = Resident::query()->lockForUpdate()->findOrFail((int) $data['claim_resident_id']);

        $resident->fill([
            'household_id' => $household->id,
            'barangay_id' => $barangay->id,
            'household_role' => $role,
            'status' => ResidentStatus::Pending,
            'is_deceased' => false,
            'birthdate_estimated' => false,
            ...$this->personalFields($data),
        ])->save();

        return $resident;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personalFields(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'maiden_name' => $data['maiden_name'] ?? null,
            'birthdate' => $data['birthdate'],
            'gender' => $data['gender'],
            'civil_status' => $data['civil_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveHousehold(Barangay $barangay, array $data): Household
    {
        if ($data['household_mode'] === 'existing') {
            return Household::query()->lockForUpdate()->findOrFail((int) $data['household_id']);
        }

        return Household::query()->create([
            'household_code' => $this->identifiers->householdCode($barangay),
            'barangay_id' => $barangay->id,
            'street_address' => $data['street_address'],
        ]);
    }
}
