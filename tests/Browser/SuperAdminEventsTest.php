<?php

use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
});

it('lets a super admin create an event, activate it, see its dates locked, and close it', function () {
    $this->actingAs($this->superAdmin);

    visit('/admin/events')
        ->click('New event')
        ->type('#event-title', 'Rice Distribution')
        ->type('#event-start', now()->subDay()->toDateString())
        ->type('#event-end', now()->addWeek()->toDateString())
        ->press('Create event')
        ->wait(1)
        ->assertSee('Rice Distribution')
        ->assertSee('Draft')
        ->press('Activate')
        ->wait(1)
        ->assertSee('Open for scanning')
        ->click('xpath=//button[normalize-space()="Edit"]')
        ->assertDisabled('#event-start')
        ->press('Cancel')
        ->wait(1)
        ->click('xpath=(//button[normalize-space()="Close"])[1]')
        ->assertSee('Close this event?')
        ->click('button:has-text("Yes, close it")')
        ->wait(1)
        ->assertSee('Finished')
        ->assertNoJavaScriptErrors();

    $event = ReliefEvent::query()->where('title', 'Rice Distribution')->firstOrFail();

    expect($event->status)->toBe(EventStatus::Closed)
        ->and($event->municipality_id)->toBe($this->municipality->id);
});

it('lets a super admin delete a draft that nobody has claimed from', function () {
    $draft = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id, 'title' => 'Draft Packs']);
    $this->actingAs($this->superAdmin);

    visit('/admin/events')
        ->assertSee('Draft Packs')
        ->press('Delete')
        ->click('button:has-text("Yes, delete")')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(ReliefEvent::query()->whereKey($draft->id)->exists())->toBeFalse();
});
