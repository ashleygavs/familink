<?php

namespace App\Actions\Residents;

use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GenerateIdentifiers
{
    /**
     * The next resident ID for a barangay, e.g. FL-2026-SJ-00005.
     */
    public function systemId(Barangay $barangay): string
    {
        $this->lock($barangay);

        $prefix = sprintf('FL-%d-%s-', now()->year, $this->barangayCode($barangay));

        return $prefix.$this->nextSequence(Resident::class, 'system_id_no', $prefix, 5);
    }

    /**
     * The next household code for a barangay, e.g. HH-SJ-0003.
     */
    public function householdCode(Barangay $barangay): string
    {
        $this->lock($barangay);

        $prefix = sprintf('HH-%s-', $this->barangayCode($barangay));

        return $prefix.$this->nextSequence(Household::class, 'household_code', $prefix, 4);
    }

    /**
     * Take turns with everyone else who is numbering residents or households in this barangay. The next number is read
     * from the numbers already used, so two requests doing that at once would pick the same number or lock each
     * other's rows and be stopped by a deadlock. Holding this one lock first, in the same order every time, avoids both.
     * It lasts until the surrounding transaction ends, and does nothing when there is none.
     */
    public function lock(Barangay $barangay): void
    {
        Barangay::query()->whereKey($barangay->id)->lockForUpdate()->value('id');
    }

    /**
     * An unguessable value stored behind a resident's QR code.
     */
    public function qrHash(): string
    {
        return hash('sha256', Str::uuid()->toString().Str::random(32));
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function nextSequence(string $model, string $column, string $prefix, int $width): string
    {
        $last = $model::query()->where($column, 'like', $prefix.'%')->lockForUpdate()->orderByDesc($column)->value($column);
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    private function barangayCode(Barangay $barangay): string
    {
        return $barangay->code ?: strtoupper(Str::substr(preg_replace('/[^A-Za-z]/', '', $barangay->name) ?? '', 0, 3));
    }
}
