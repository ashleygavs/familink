<?php

namespace App\Actions\Relief;

use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\ReliefEventChange;
use App\Models\User;

/**
 * Writes the history of a relief event: its creation, each edit with the old and new values, and its deletion.
 */
class RecordEventChange
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'title' => 'Name',
        'municipality_id' => 'Municipality',
        'allocation_type' => 'Who gets one share',
        'max_claims_per_household' => 'Most members per household',
        'start_date' => 'Starts',
        'end_date' => 'Ends',
        'status' => 'Status',
    ];

    public function created(User $actor, ReliefEvent $event): void
    {
        $this->write($actor, $event, ReliefEventChange::CREATED, 'Created the event ('.$this->describe($event).').', [
            'after' => $this->snapshot($event),
        ]);
    }

    /**
     * @param  array<string, string|null>  $before  The snapshot taken before the event was saved.
     */
    public function updated(User $actor, ReliefEvent $event, array $before): void
    {
        $after = $this->snapshot($event);
        $changed = array_keys(array_filter($after, fn (?string $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return;
        }

        $this->write($actor, $event, ReliefEventChange::UPDATED, 'Changed '.implode('; ', array_map(
            fn (string $field): string => self::LABELS[$field].' '.$this->show($field, $before[$field]).' to '.$this->show($field, $after[$field]),
            $changed,
        )).'.', [
            'before' => array_intersect_key($before, array_flip($changed)),
            'after' => array_intersect_key($after, array_flip($changed)),
        ]);
    }

    public function deleted(User $actor, ReliefEvent $event): void
    {
        $this->write($actor, $event, ReliefEventChange::DELETED, 'Deleted the draft event.', [
            'before' => $this->snapshot($event),
        ]);
    }

    /**
     * What is compared and recorded about an event, as plain text.
     *
     * @return array<string, string|null>
     */
    public function snapshot(ReliefEvent $event): array
    {
        return [
            'title' => $event->title,
            'municipality_id' => $event->municipality_id === null ? null : (string) $event->municipality_id,
            'allocation_type' => $event->allocation_type->value,
            'max_claims_per_household' => $event->max_claims_per_household === null ? null : (string) $event->max_claims_per_household,
            'start_date' => $event->start_date->toDateString(),
            'end_date' => $event->end_date->toDateString(),
            'status' => $event->status->value,
        ];
    }

    private function show(string $field, ?string $value): string
    {
        return match (true) {
            $value === null => 'none',
            $field === 'municipality_id' => Municipality::query()->whereKey((int) $value)->value('name') ?? 'unknown',
            default => $value,
        };
    }

    private function describe(ReliefEvent $event): string
    {
        $limit = $event->max_claims_per_household === null ? '' : ", up to {$event->max_claims_per_household} per household";

        return "{$event->allocation_type->value}{$limit}, {$event->start_date->format('M j, Y')} to {$event->end_date->format('M j, Y')}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(User $actor, ReliefEvent $event, string $type, string $summary, array $data): void
    {
        ReliefEventChange::query()->create([
            'relief_event_id' => $event->exists ? $event->id : null,
            'municipality_id' => $event->municipality_id,
            'event_title' => $event->title,
            'changed_by_user_id' => $actor->id,
            'type' => $type,
            'summary' => $summary,
            'data' => $data,
        ]);
    }
}
