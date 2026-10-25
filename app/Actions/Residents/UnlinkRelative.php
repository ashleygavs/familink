<?php

namespace App\Actions\Residents;

use App\Models\RelativeLink;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UnlinkRelative
{
    public function __construct(private RecordResidentChange $record) {}

    /**
     * Remove one family link from a resident: the father, mother or spouse, a child, or a named relative.
     * The other person's record is kept; only the connection is cut. When an author is given, every record that
     * changed gets a line in its history.
     *
     * @param  string  $kind  father, mother, spouse, child or relative.
     * @param  int|null  $id  The child's resident id, or the relative link id.
     */
    public function handle(Resident $resident, string $kind, ?int $id = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($resident, $kind, $id, $actor): void {
            match ($kind) {
                'father' => $this->unlinkParent($resident, 'father_id', 'father', $actor),
                'mother' => $this->unlinkParent($resident, 'mother_id', 'mother', $actor),
                'spouse' => $this->unlinkSpouse($resident, $actor),
                'child' => $this->unlinkChild($resident, (int) $id, $actor),
                'relative' => $this->unlinkNamedRelative($resident, $id, $actor),
                default => throw new InvalidArgumentException("Unknown family link: {$kind}"),
            };
        });
    }

    private function unlinkParent(Resident $resident, string $column, string $label, ?User $actor): void
    {
        $parent = $resident->{$column} === null ? null : Resident::query()->whereKey($resident->{$column})->first();

        $resident->update([$column => null]);

        if ($actor !== null && $parent !== null) {
            $this->record->handle($resident, $actor, ResidentChange::FAMILY_UNLINKED, "Removed {$parent->full_name} as {$label}.", ['kind' => $label, 'relative_id' => $parent->id]);
        }
    }

    private function unlinkSpouse(Resident $resident, ?User $actor): void
    {
        if ($resident->spouse_id === null) {
            return;
        }

        $partner = Resident::query()->find($resident->spouse_id);
        $linkedBack = $partner !== null && $partner->spouse_id === $resident->id;

        if ($linkedBack) {
            $partner->update(['spouse_id' => null]);
        }

        $resident->update(['spouse_id' => null]);

        if ($partner === null) {
            return;
        }

        $ownRestored = $this->restoreCivilStatus($resident, $partner->id);
        $partnerRestored = $linkedBack ? $this->restoreCivilStatus($partner, $resident->id) : null;

        if ($actor === null) {
            return;
        }

        $this->record->handle($resident, $actor, ResidentChange::FAMILY_UNLINKED, $this->spouseSummary($partner, $ownRestored), ['kind' => 'spouse', 'partner_id' => $partner->id]);

        if ($linkedBack) {
            $this->record->handle($partner, $actor, ResidentChange::FAMILY_UNLINKED, $this->spouseSummary($resident, $partnerRestored), ['kind' => 'spouse', 'partner_id' => $resident->id]);
        }
    }

    /**
     * Put a civil status back to what it was before the link was made, when the history says what that was and the
     * person is still shown as married because of it. Returns the status restored, or null when nothing changed.
     */
    private function restoreCivilStatus(Resident $person, int $partnerId): ?string
    {
        $link = ResidentChange::query()
            ->where('resident_id', $person->id)
            ->where('type', ResidentChange::FAMILY_LINKED)
            ->where('data->kind', 'spouse')
            ->where('data->partner_id', $partnerId)
            ->latest('id')
            ->first();

        $before = is_array($link?->data) ? ($link->data['civil_status_before'] ?? null) : null;

        if (! is_string($before) || $before === 'Married' || $person->civil_status !== 'Married') {
            return null;
        }

        $person->update(['civil_status' => $before]);

        return $before;
    }

    private function spouseSummary(Resident $other, ?string $restored): string
    {
        return "Removed {$other->full_name} as spouse.".($restored === null ? '' : " Civil status is back to {$restored}.");
    }

    private function unlinkChild(Resident $resident, int $childId, ?User $actor): void
    {
        $child = Resident::query()->find($childId);

        if ($child === null) {
            return;
        }

        $column = match (true) {
            $child->father_id === $resident->id => 'father_id',
            $child->mother_id === $resident->id => 'mother_id',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $child->update([$column => null]);

        if ($actor !== null) {
            $role = $column === 'father_id' ? 'father' : 'mother';

            $this->record->handle($resident, $actor, ResidentChange::FAMILY_UNLINKED, "Removed {$child->full_name} as child.", ['kind' => 'child', 'relative_id' => $child->id]);
            $this->record->handle($child, $actor, ResidentChange::FAMILY_UNLINKED, "Removed {$resident->full_name} as {$role}.", ['kind' => $role, 'relative_id' => $resident->id]);
        }
    }

    private function unlinkNamedRelative(Resident $resident, ?int $id, ?User $actor): void
    {
        $link = RelativeLink::query()
            ->with(['resident', 'relative'])
            ->whereKey($id)
            ->where(fn ($query) => $query->where('resident_id', $resident->id)->orWhere('relative_id', $resident->id))
            ->first();

        if ($link === null) {
            return;
        }

        $named = $link->resident_id === $resident->id;
        $other = $named ? $link->relative : $link->resident;
        $relation = ($named ? $link->relation : $link->relation->inverse())->label();

        $link->delete();

        if ($actor !== null) {
            $this->record->handle($resident, $actor, ResidentChange::FAMILY_UNLINKED, "Removed {$other->full_name} as {$relation}.", ['kind' => 'relative', 'relative_id' => $other->id]);
        }
    }
}
