<?php

namespace App\Http\Requests;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Models\ReliefEvent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SaveReliefEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        if (! $event instanceof ReliefEvent) {
            return true;
        }

        // An event outside the user's area answers exactly like one that does not exist.
        abort_unless(ReliefEvent::query()->visibleTo($this->user())->whereKey($event->id)->exists(), 404);

        return $event->status !== EventStatus::Closed;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $event = $this->route('event');
        $event = $event instanceof ReliefEvent ? $event : null;

        $municipalityRule = $this->user()->hasRole(RoleName::SuperAdmin)
            ? Rule::exists('municipalities', 'id')
            : Rule::in($this->user()->accessibleMunicipalityIds() ?? []);

        $statuses = $event === null
            ? [EventStatus::Draft->value, EventStatus::Active->value]
            : array_map(fn (EventStatus $status): string => $status->value, [$event->status, ...$event->status->next()]);

        return [
            'title' => ['required', 'string', 'max:255'],
            'municipality_id' => ['required', 'integer', $municipalityRule],
            'allocation_type' => ['required', Rule::enum(AllocationType::class)],
            'max_claims_per_household' => ['nullable', 'integer', 'min:1', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in($statuses)],
        ];
    }

    /**
     * A super admin cannot change the limit per household of an event that is no longer a draft. Refusing it, instead
     * of ignoring it, makes sure nobody believes the rules of a running distribution were changed.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $event = $this->route('event');

            if ($validator->errors()->isNotEmpty() || ! $event instanceof ReliefEvent) {
                return;
            }

            $this->refuseLockedChanges($validator, $event);

            if (! $event->limitIsLocked() || ! $this->user()->hasRole(RoleName::SuperAdmin)) {
                return;
            }

            $submitted = $this->input('max_claims_per_household');
            $submitted = $submitted === null || $submitted === '' ? null : (int) $submitted;

            if ($submitted !== $event->max_claims_per_household && $event->allocation_type === AllocationType::PerResident) {
                $validator->errors()->add('max_claims_per_household', 'The limit per household is locked once an event is active, so the rules cannot change during a distribution.');
            }
        }];
    }

    /**
     * Once an event is Active, the municipality, the type and the start date cannot change. A request that tries is
     * refused with a message, not quietly saved without them, so nobody believes they were changed.
     */
    private function refuseLockedChanges(Validator $validator, ReliefEvent $event): void
    {
        if ($event->status !== EventStatus::Active) {
            return;
        }

        $changed = [
            'municipality_id' => (int) $this->input('municipality_id') !== $event->municipality_id,
            'allocation_type' => (string) $this->input('allocation_type') !== $event->allocation_type->value,
            'start_date' => Carbon::parse((string) $this->input('start_date'))->toDateString() !== $event->start_date->toDateString(),
        ];

        foreach (array_keys(array_filter($changed)) as $field) {
            $validator->errors()->add($field, 'This cannot be changed once the event is active. Only the title, the end date and the status can.');
        }
    }

    /**
     * Once an event is Active, claims depend on its type, place and start, so only the title, end date and
     * status may change. The limit per household is the super admin's to set while the event is a draft, and
     * only means something for a Per Resident event.
     *
     * @return array<string, mixed>
     */
    public function savedData(): array
    {
        $data = $this->validated();
        $event = $this->route('event');
        $isActive = $event instanceof ReliefEvent && $event->status === EventStatus::Active;

        $limit = $this->user()->hasRole(RoleName::SuperAdmin) ? ($data['max_claims_per_household'] ?? null) : null;
        unset($data['max_claims_per_household']);

        if ($isActive) {
            $data = [
                'title' => $data['title'],
                'end_date' => $data['end_date'],
                'status' => $data['status'],
            ];
        }

        $type = $isActive ? $event->allocation_type->value : $data['allocation_type'];

        $locked = $event instanceof ReliefEvent && $event->limitIsLocked();

        if ($this->user()->hasRole(RoleName::SuperAdmin) && ! $locked) {
            $data['max_claims_per_household'] = $type === AllocationType::PerResident->value ? $limit : null;
        }

        return $data;
    }
}
