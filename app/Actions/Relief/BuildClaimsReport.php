<?php

namespace App\Actions\Relief;

use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use Generator;

/**
 * Every claim of one event as rows for a report: who claimed, where, how they were identified, and whether someone
 * else collected for them.
 */
class BuildClaimsReport
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'Claimed at', 'Event', 'Barangay', 'Household', 'Resident ID', 'Resident', 'Identified by',
        'Collected by', 'Relationship to resident', 'Authorization checked', 'Recorded by',
    ];

    /**
     * @param  list<int>|null  $barangayIds  Only claims of residents in these barangays, or null for all.
     * @return Generator<int, list<string|int|bool|null>>
     */
    public function rows(ReliefEvent $event, ?array $barangayIds): Generator
    {
        $claims = ReliefLog::query()
            ->with(['resident.barangay', 'household', 'scannedBy'])
            ->where('event_id', $event->id)
            ->when($barangayIds !== null, fn ($query) => $query->whereHas('resident', fn ($resident) => $resident->whereIn('barangay_id', $barangayIds)))
            ->orderBy('created_at')
            ->orderBy('id');

        foreach ($claims->lazyById(500) as $claim) {
            yield [
                $claim->created_at?->format('Y-m-d H:i'),
                $event->title,
                $claim->resident->barangay?->name,
                $claim->household->household_code,
                $claim->resident->system_id_no,
                $claim->resident->full_name,
                $claim->entry_method === ReliefLog::MANUAL ? 'Typed resident ID' : 'QR code',
                $claim->claimed_by_representative ? $claim->representative_name : 'The resident',
                $claim->claimed_by_representative ? $claim->representative_relationship : null,
                $claim->claimed_by_representative ? $claim->representative_verified : null,
                $claim->scannedBy->name,
            ];
        }
    }
}
