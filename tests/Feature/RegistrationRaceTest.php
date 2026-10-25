<?php

use App\Actions\Residents\FindDuplicateResident;
use App\Actions\Residents\GenerateIdentifiers;
use App\Actions\Residents\RegisterResident;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['name' => 'San Jose', 'code' => 'SJ']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->data = fn (array $extra = []) => [
        'first_name' => 'Twin', 'middle_name' => null, 'last_name' => 'Racer', 'birthdate' => '1986-08-08',
        'gender' => 'Female', 'civil_status' => 'Single', 'household_mode' => 'new', 'street_address' => '8 Twin Road',
        'is_head' => true, 'household_role' => null, ...$extra,
    ];
});

it('finds the same person by name and birthdate', function () {
    $existing = Resident::factory()->create(['first_name' => 'Twin', 'last_name' => 'Racer', 'birthdate' => '1986-08-08']);

    $found = app(FindDuplicateResident::class)->handle('Twin', 'Racer', '1986-08-08');

    expect($found?->id)->toBe($existing->id)
        ->and(app(FindDuplicateResident::class)->handle('Twin', 'Racer', '1986-08-09'))->toBeNull()
        ->and(app(FindDuplicateResident::class)->handle('Other', 'Racer', '1986-08-08'))->toBeNull();
});

it('does not count an archived record, or the record being claimed, as the same person', function () {
    $archived = Resident::factory()->create(['first_name' => 'Twin', 'last_name' => 'Racer', 'birthdate' => '1986-08-08', 'status' => ResidentStatus::Archived]);

    expect(app(FindDuplicateResident::class)->handle('Twin', 'Racer', '1986-08-08'))->toBeNull();

    $archived->update(['status' => ResidentStatus::Listed]);

    expect(app(FindDuplicateResident::class)->handle('Twin', 'Racer', '1986-08-08')?->id)->toBe($archived->id)
        ->and(app(FindDuplicateResident::class)->handle('Twin', 'Racer', '1986-08-08', $archived->id))->toBeNull();
});

it('says in plain words who is already registered, or listed by a relative', function () {
    $registered = Resident::factory()->create(['first_name' => 'Twin', 'last_name' => 'Racer', 'middle_name' => null, 'suffix' => null, 'birthdate' => '1986-08-08']);
    $message = app(FindDuplicateResident::class)->message($registered);

    expect($message)->toBe("Twin Racer born on Aug 08, 1986 is already registered (ID {$registered->system_id_no}).");

    $registered->update(['status' => ResidentStatus::Listed]);

    expect(app(FindDuplicateResident::class)->message($registered))->toContain('was already listed by a family member');
});

it('refuses a second registration of the same person even when the form check was passed at the same moment', function () {
    $register = app(RegisterResident::class);

    $register->handle($this->encoder, ($this->data)());

    // The form check ran before the first one was saved, so only the check inside the transaction can stop this.
    expect(fn () => $register->handle($this->encoder, ($this->data)(['street_address' => '9 Other Road'])))
        ->toThrow(ValidationException::class);

    expect(Resident::query()->where('last_name', 'Racer')->count())->toBe(1)
        ->and(Household::query()->count())->toBe(1);
});

it('gives the refusal in the same words as the form does', function () {
    $register = app(RegisterResident::class);
    $first = $register->handle($this->encoder, ($this->data)());

    try {
        $register->handle($this->encoder, ($this->data)());
        $this->fail('The second registration should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['first_name'][0])->toBe("Twin Racer born on Aug 08, 1986 is already registered (ID {$first->system_id_no}).");
    }
});

it('still lets a person claim the record a relative listed for them, which has their own name', function () {
    $listed = Resident::factory()->listed()->create(['first_name' => 'Twin', 'last_name' => 'Racer', 'middle_name' => null, 'birthdate' => '1986-08-08']);

    $claimed = app(RegisterResident::class)->handle($this->encoder, ($this->data)(['claim_resident_id' => $listed->id, 'path' => 'existing']));

    expect($claimed->id)->toBe($listed->id)->and($claimed->fresh()->status)->toBe(ResidentStatus::Pending);
});

it('tries again when the database stops a registration because two were waiting on each other', function () {
    $deadlock = fn () => new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));

    app()->bind(GenerateIdentifiers::class, fn () => new class($deadlock) extends GenerateIdentifiers
    {
        public int $calls = 0;

        public function __construct(private Closure $failure) {}

        public function systemId(Barangay $barangay): string
        {
            if ($this->calls++ === 0) {
                throw ($this->failure)();
            }

            return parent::systemId($barangay);
        }
    });

    $resident = app(RegisterResident::class)->handle($this->encoder, ($this->data)());

    expect($resident->exists)->toBeTrue()
        ->and(Resident::query()->where('last_name', 'Racer')->count())->toBe(1);
});

it('does not hide other database errors by trying again', function () {
    app()->bind(GenerateIdentifiers::class, fn () => new class extends GenerateIdentifiers
    {
        public static int $calls = 0;

        public function systemId(Barangay $barangay): string
        {
            self::$calls++;

            throw new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[HY000]: General error: 1 no such table'));
        }
    });

    expect(fn () => app(RegisterResident::class)->handle($this->encoder, ($this->data)()))->toThrow(QueryException::class);
    expect((fn () => static::$calls)->bindTo(null, get_class(app(GenerateIdentifiers::class)))())->toBe(1);
});

it('numbers residents and households one after the other in a barangay', function () {
    $register = app(RegisterResident::class);

    $first = $register->handle($this->encoder, ($this->data)());
    $second = $register->handle($this->encoder, ($this->data)(['first_name' => 'Next', 'street_address' => '10 Next Road']));

    expect($first->system_id_no)->toBe('FL-'.now()->year.'-SJ-00001')
        ->and($second->system_id_no)->toBe('FL-'.now()->year.'-SJ-00002')
        ->and($first->household->household_code)->toBe('HH-SJ-0001')
        ->and($second->household->household_code)->toBe('HH-SJ-0002');
});
