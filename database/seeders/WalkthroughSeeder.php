<?php

namespace Database\Seeders;

use App\Actions\Residents\GenerateIdentifiers;
use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * More municipalities and barangays, an admin and an encoder for every barangay, families and relief events, so
 * every role can be tried and the rules that keep each area separate can be checked. Every admin handles one
 * barangay. Safe to run again: nothing is duplicated.
 *
 * Run with: php artisan db:seed --class=WalkthroughSeeder
 */
class WalkthroughSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, BarangaySeeder::class]);

        $bacolor = Municipality::query()->where('name', 'Bacolor')->firstOrFail();
        $cabalantian = Barangay::query()->where('name', 'Cabalantian')->firstOrFail();

        $lubao = Municipality::query()->firstOrCreate(['name' => 'Lubao', 'province' => 'Pampanga']);
        $santaCruz = Barangay::query()->updateOrCreate(['name' => 'Santa Cruz'], ['code' => 'SC', 'municipality_id' => $lubao->id]);
        $sanNicolas = Barangay::query()->updateOrCreate(['name' => 'San Nicolas'], ['code' => 'SN', 'municipality_id' => $lubao->id]);

        $mexico = Municipality::query()->firstOrCreate(['name' => 'Mexico', 'province' => 'Pampanga']);
        $sanJoseMalino = Barangay::query()->updateOrCreate(['name' => 'San Jose Malino'], ['code' => 'SJM', 'municipality_id' => $mexico->id]);

        $sanLuis = Municipality::query()->firstOrCreate(['name' => 'San Luis', 'province' => 'Pampanga']);
        $staMonica = Barangay::query()->updateOrCreate(['name' => 'Sta. Monica'], ['code' => 'SM', 'municipality_id' => $sanLuis->id]);

        $superAdmin = User::query()->where('email', 'superadmin@familink.test')->first()
            ?? $this->account('Super Admin', 'superadmin@familink.test', RoleName::SuperAdmin);

        // One admin and one encoder for every barangay. The Bacolor admin (admin@) handles San Jose and is made
        // by DatabaseSeeder.
        $cabalantianAdmin = $this->admin('BAC_Cabalantian Admin', 'bac.cabalantian.admin@familink.test', $bacolor, $cabalantian);
        $santaCruzAdmin = $this->admin('LUB_Santa Cruz Admin', 'lub.santacruz.admin@familink.test', $lubao, $santaCruz);
        $sanNicolasAdmin = $this->admin('LUB_San Nicolas Admin', 'lub.sannicolas.admin@familink.test', $lubao, $sanNicolas);
        $this->admin('MEX_San Jose Malino Admin', 'mex.sanjosemalino.admin@familink.test', $mexico, $sanJoseMalino);
        $this->admin('SLU_Sta. Monica Admin', 'slu.stamonica.admin@familink.test', $sanLuis, $staMonica);

        $this->account('BAC_Cabalantian Encoder', 'bac.cabalantian.encoder@familink.test', RoleName::User, $cabalantian);
        $this->account('LUB_Santa Cruz Encoder', 'lub.santacruz.encoder@familink.test', RoleName::User, $santaCruz);
        $this->account('LUB_San Nicolas Encoder', 'lub.sannicolas.encoder@familink.test', RoleName::User, $sanNicolas);
        $this->account('MEX_San Jose Malino Encoder', 'mex.sanjosemalino.encoder@familink.test', RoleName::User, $sanJoseMalino);
        $this->account('SLU_Sta. Monica Encoder', 'slu.stamonica.encoder@familink.test', RoleName::User, $staMonica);

        $this->bacolorFamilies($cabalantian, $cabalantianAdmin);
        $this->lubaoFamilies($santaCruz, $sanNicolas, $santaCruzAdmin, $sanNicolasAdmin);
        $this->events($superAdmin, $bacolor, $lubao);
    }

    /**
     * An admin of one municipality who handles a single barangay.
     */
    private function admin(string $name, string $email, Municipality $municipality, Barangay $barangay): User
    {
        $admin = $this->account($name, $email, RoleName::Admin, municipality: $municipality);
        $admin->managedBarangays()->syncWithoutDetaching([$barangay->id]);

        return $admin;
    }

    private function account(string $name, string $email, RoleName $role, ?Barangay $barangay = null, ?Municipality $municipality = null): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        // An account that already exists is left alone, so a changed name or password is never reset.
        if (! $user->exists) {
            $user->forceFill([
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
                'role_id' => Role::query()->where('name', $role->value)->value('id'),
                'barangay_id' => $barangay?->id,
                'municipality_id' => $municipality?->id,
            ])->save();
        }

        return $user;
    }

    private function bacolorFamilies(Barangay $cabalantian, User $approver): void
    {
        $villanueva = $this->household($cabalantian, '14 Mabini Street');
        $lola = $this->person($villanueva, $approver, 'Carmen', 'Villanueva', 'Female', '1948-03-01', 'Widowed', 'Other');
        $father = $this->person($villanueva, $approver, 'Roberto', 'Villanueva', 'Male', '1975-05-12', 'Married', 'Head', ['father_id' => null, 'mother_id' => $lola->id]);
        $mother = $this->person($villanueva, $approver, 'Elena', 'Villanueva', 'Female', '1978-09-03', 'Married', 'Spouse', ['spouse_id' => $father->id], 'Cruz');
        $father->update(['spouse_id' => $mother->id]);
        $this->person($villanueva, $approver, 'Miguel', 'Villanueva', 'Male', '2005-02-10', 'Single', 'Child', ['father_id' => $father->id, 'mother_id' => $mother->id], 'Cruz');
        $this->person($villanueva, $approver, 'Sofia', 'Villanueva', 'Female', '2010-07-21', 'Single', 'Child', ['father_id' => $father->id, 'mother_id' => $mother->id], 'Cruz');
        $villanueva->update(['head_resident_id' => $father->id]);

        $ramos = $this->household($cabalantian, '3 Rizal Avenue');
        $teresa = $this->person($ramos, $approver, 'Teresa', 'Ramos', 'Female', '1990-11-30', 'Single', 'Head');
        $this->person($ramos, $approver, 'Lito', 'Ramos', 'Male', '2015-04-04', 'Single', 'Child', ['mother_id' => $teresa->id]);
        $this->person($ramos, null, 'Joel', 'Ramos', 'Male', '1993-06-18', 'Single', 'Sibling', [], null, ResidentStatus::Pending);
        $ramos->update(['head_resident_id' => $teresa->id]);
    }

    private function lubaoFamilies(Barangay $santaCruz, Barangay $sanNicolas, User $approver, User $sanNicolasApprover): void
    {
        $manalo = $this->household($santaCruz, '8 Sampaguita Street');
        $antonio = $this->person($manalo, $approver, 'Antonio', 'Manalo', 'Male', '1970-01-25', 'Married', 'Head');
        $gloria = $this->person($manalo, $approver, 'Gloria', 'Manalo', 'Female', '1972-08-14', 'Married', 'Spouse', ['spouse_id' => $antonio->id], 'Reyes');
        $antonio->update(['spouse_id' => $gloria->id]);
        $this->person($manalo, $approver, 'Paolo', 'Manalo', 'Male', '2000-10-02', 'Single', 'Child', ['father_id' => $antonio->id, 'mother_id' => $gloria->id], 'Reyes');
        $this->person($manalo, $approver, 'Marie', 'Manalo', 'Female', '2004-12-19', 'Single', 'Child', ['father_id' => $antonio->id, 'mother_id' => $gloria->id], 'Reyes');
        $this->person($manalo, null, 'Rhea', 'Manalo', 'Female', '2012-03-09', 'Single', 'Child', ['father_id' => $antonio->id, 'mother_id' => $gloria->id], 'Reyes', ResidentStatus::Pending);
        $manalo->update(['head_resident_id' => $antonio->id]);

        $dizon = $this->household($santaCruz, '21 Acacia Lane');
        $cristina = $this->person($dizon, $approver, 'Cristina', 'Dizon', 'Female', '1985-04-11', 'Single', 'Head');
        $this->person($dizon, $approver, 'Ian', 'Dizon', 'Male', '2012-09-23', 'Single', 'Child', ['mother_id' => $cristina->id]);
        $this->person($dizon, $approver, 'Jade', 'Dizon', 'Female', '2016-01-30', 'Single', 'Child', ['mother_id' => $cristina->id]);
        $dizon->update(['head_resident_id' => $cristina->id]);

        $soriano = $this->household($sanNicolas, '5 Narra Road');
        $ramon = $this->person($soriano, $sanNicolasApprover, 'Ramon', 'Soriano', 'Male', '1960-07-07', 'Married', 'Head');
        $linda = $this->person($soriano, $sanNicolasApprover, 'Linda', 'Soriano', 'Female', '1963-02-16', 'Married', 'Spouse', ['spouse_id' => $ramon->id], 'Bautista');
        $ramon->update(['spouse_id' => $linda->id]);
        $this->person($soriano, $sanNicolasApprover, 'Carlo', 'Soriano', 'Male', '1990-05-28', 'Single', 'Child', ['father_id' => $ramon->id, 'mother_id' => $linda->id], 'Bautista');
        $soriano->update(['head_resident_id' => $ramon->id]);
    }

    private function events(User $creator, Municipality $bacolor, Municipality $lubao): void
    {
        $open = ['start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(30)->toDateString()];

        $events = [
            ['Rice Distribution (per household)', $bacolor, AllocationType::PerHousehold, null, EventStatus::Active, $open],
            ['Cash Aid (per resident, max 3 per household)', $bacolor, AllocationType::PerResident, 3, EventStatus::Active, $open],
            ['Typhoon Aid (still a draft)', $bacolor, AllocationType::PerHousehold, null, EventStatus::Draft, $open],
            ['Old Relief (closed)', $bacolor, AllocationType::PerHousehold, null, EventStatus::Closed, ['start_date' => now()->subMonths(3)->toDateString(), 'end_date' => now()->subMonths(2)->toDateString()]],
            ['Lubao Relief Packs', $lubao, AllocationType::PerHousehold, null, EventStatus::Active, $open],
        ];

        foreach ($events as [$title, $municipality, $type, $limit, $status, $dates]) {
            ReliefEvent::query()->firstOrCreate(['title' => $title], [
                'municipality_id' => $municipality->id,
                'allocation_type' => $type,
                'max_claims_per_household' => $limit,
                'status' => $status,
                'created_by_user_id' => $creator->id,
                ...$dates,
            ]);
        }
    }

    private function household(Barangay $barangay, string $street): Household
    {
        return Household::query()->firstOrCreate(
            ['barangay_id' => $barangay->id, 'street_address' => $street],
            ['household_code' => app(GenerateIdentifiers::class)->householdCode($barangay)],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function person(
        Household $household,
        ?User $approver,
        string $first,
        string $last,
        string $gender,
        string $birthdate,
        string $civilStatus,
        string $role,
        array $extra = [],
        ?string $maidenName = null,
        ResidentStatus $status = ResidentStatus::Approved,
    ): Resident {
        $barangay = Barangay::query()->findOrFail($household->barangay_id);
        $identifiers = app(GenerateIdentifiers::class);

        return Resident::query()->firstOrCreate(
            ['household_id' => $household->id, 'first_name' => $first, 'last_name' => $last, 'birthdate' => $birthdate],
            [
                'system_id_no' => $identifiers->systemId($barangay),
                'qr_code_hash' => $identifiers->qrHash(),
                'barangay_id' => $barangay->id,
                'middle_name' => $maidenName,
                'maiden_name' => $gender === 'Female' && $civilStatus === 'Married' ? $maidenName : null,
                'gender' => $gender,
                'civil_status' => $civilStatus,
                'household_role' => $role,
                'status' => $status,
                'approved_by_user_id' => $status === ResidentStatus::Approved ? $approver?->id : null,
                ...$extra,
            ],
        );
    }
}
