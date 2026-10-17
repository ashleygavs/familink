<?php

namespace App\Http\Requests;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Models\ReliefEvent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReliefEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        if (! $event instanceof ReliefEvent) {
            return true;
        }

        return $event->status !== EventStatus::Closed
            && ReliefEvent::query()->visibleTo($this->user())->whereKey($event->id)->exists();
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

            if ($validator->errors()->isNotEmpty() || ! $event instanceof ReliefEvent || ! $event->limitIsLocked() || ! $this->user()->hasRole(RoleName::SuperAdmin)) {
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
