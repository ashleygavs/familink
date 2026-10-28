<?php

use App\Actions\Residents\EndMarriage;
use App\Enums\CivilStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $this->barangay = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();

    $home = fn () => Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $make = fn (Household $h, string $name, string $gender, int $born, array $x = []) => Resident::factory()->create([
        'first_name' => $name, 'last_name' => 'Sampleton', 'middle_name' => null, 'suffix' => null, 'gender' => $gender,
        'birthdate' => "{$born}-06-15", 'household_id' => $h->id, 'barangay_id' => $this->barangay->id, ...$x,
    ]);

    $this->anaHome = $home();
    $this->oldHome = $home();
    $andoHome = $home();

    $ramon = $make($andoHome, 'Ramon', 'Male', 1915);
    $ando = $make($andoHome, 'Tito Ando', 'Male', 1938, ['father_id' => $ramon->id]);
    $tess = $make($andoHome, 'Tess', 'Female', 1962, ['father_id' => $ando->id]);
    $make($andoHome, 'Uno', 'Male', 1988, ['mother_id' => $tess->id]);

    $lolo = $make($this->oldHome, 'Lolo Ben', 'Male', 1940, ['father_id' => $ramon->id]);
    $lola = $make($this->oldHome, 'Lola Rosa', 'Female', 1942, ['spouse_id' => $lolo->id]);
    $lolo->update(['spouse_id' => $lola->id]);
    $pedro = $make($this->oldHome, 'Pedro', 'Male', 1965, ['father_id' => $lolo->id, 'mother_id' => $lola->id]);
    $maria = $make($this->oldHome, 'Maria', 'Female', 1968, ['father_id' => $lolo->id, 'mother_id' => $lola->id]);
    $ben = $make($this->oldHome, 'Ben', 'Male', 1993, ['mother_id' => $maria->id]);
    $elena = $make($this->oldHome, 'Elena', 'Female', 1994, ['spouse_id' => $ben->id]);
    $ben->update(['spouse_id' => $elena->id]);
    $make($this->oldHome, 'Dino', 'Male', 2018, ['father_id' => $ben->id]);

    $ana = $make($this->anaHome, 'Ana', 'Female', 1990, ['father_id' => $pedro->id]);
    $this->anaHome->update(['head_resident_id' => $ana->id]);
    $make($this->anaHome, 'Carla', 'Female', 2015, ['mother_id' => $ana->id]);
});

it('shows more people for each wider reach and goes back to the same people when a shorter one is chosen again', function () {
    $this->actingAs($this->superAdmin);

    visit("/family-tree/{$this->anaHome->id}")
        ->assertSee('7 people.')
        ->click('button:has-text("First cousins")')
        ->wait(1)
        ->assertSee('9 people.')
        ->click('button:has-text("Second cousins")')
        ->wait(1)
        ->assertSee('13 people.')
        ->click('Close family')
        ->wait(1)
        ->assertSee('7 people.')
        ->click('button:has-text("First cousins")')
        ->wait(1)
        ->assertSee('9 people.')
        ->click('Close family')
        ->wait(1)
        ->assertSee('7 people.')
        ->assertNoJavaScriptErrors();
});

it('stays in the relatives list when the reach is changed', function () {
    $this->actingAs($this->superAdmin);

    visit("/family-tree/{$this->anaHome->id}")
        ->assertSee('7 people.')
        ->click('button:has-text("Relatives list")')
        ->assertAttribute('button:has-text("Relatives list")', 'aria-pressed', 'true')
        ->click('button:has-text("First cousins")')
        ->wait(1)
        ->assertSee('9 people.')
        ->assertAttribute('button:has-text("Relatives list")', 'aria-pressed', 'true')
        ->assertAttribute('button:has-text("Tree")', 'aria-pressed', 'false')
        ->click('button:has-text("Close family")')
        ->wait(1)
        ->assertSee('7 people.')
        ->assertAttribute('button:has-text("Relatives list")', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();
});

it('shows a marriage that has ended in the legend and on the person', function () {
    $this->actingAs($this->superAdmin);
    $ben = Resident::query()->where('first_name', 'Ben')->firstOrFail();
    app(EndMarriage::class)->handle($this->superAdmin, $ben, CivilStatus::Separated);

    visit("/family-tree/{$this->oldHome->id}")
        ->assertSee('Marriage ended (separated or annulled)')
        ->click('button:has-text("Relatives list")')
        ->click('button[aria-pressed]:has-text("Elena Sampleton")')
        ->assertSee('Separated from Ben Sampleton.')
        ->assertNoJavaScriptErrors();
});

it('says plainly when a wider reach has nobody more to add', function () {
    $this->actingAs($this->superAdmin);

    // This household's own members already reach almost the whole family, so the widest level adds nobody.
    visit("/family-tree/{$this->oldHome->id}")
        ->assertSee('12 people.')
        ->click('button:has-text("First cousins")')
        ->wait(1)
        ->assertSee('13 people.')
        ->assertSee('Adds 1 more person, such as first cousins.')
        ->click('button:has-text("Second cousins")')
        ->wait(1)
        ->assertSee('13 people.')
        ->assertSee('Nobody more is related at this distance, so this shows the same people as the shorter reach.')
        ->assertNoJavaScriptErrors();
});
