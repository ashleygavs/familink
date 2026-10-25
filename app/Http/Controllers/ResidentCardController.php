<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResidentCardController extends Controller
{
    /**
     * The printable QR card of one resident. Only an approved resident can claim relief, so only they get a card.
     */
    public function resident(Request $request, Resident $resident): Response
    {
        abort_unless(Resident::query()->visibleTo($request->user())->whereKey($resident->id)->exists(), 404);

        if ($resident->is_deceased) {
            return $this->page("QR card for {$resident->full_name}", [], "{$resident->full_name} is recorded as deceased, so there is no QR card to print.");
        }

        if ($resident->status !== ResidentStatus::Approved) {
            return $this->page("QR card for {$resident->full_name}", [], "{$resident->full_name} is not approved yet, so there is no QR card to print.");
        }

        return $this->page("QR card for {$resident->full_name}", [$this->card($resident->loadMissing(['household', 'barangay']))]);
    }

    /**
     * One sheet with the QR card of every approved member of a household.
     */
    public function household(Request $request, Household $household): Response
    {
        abort_unless($request->user()->canAccessBarangay($household->barangay_id), 404);

        $members = Resident::query()
            ->with(['household', 'barangay'])
            ->where('household_id', $household->id)
            ->where('status', ResidentStatus::Approved->value)
            ->where('is_deceased', false)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $this->page(
            "QR cards for household {$household->household_code}",
            array_values($members->map(fn (Resident $member): array => $this->card($member))->all()),
            $members->isEmpty() ? 'No one in this household is approved yet, so there are no QR cards to print.' : null,
        );
    }

    /**
     * @param  list<array<string, string|int|null>>  $cards
     */
    private function page(string $title, array $cards, ?string $notice = null): Response
    {
        return Inertia::render('residents/qr-cards', ['title' => $title, 'cards' => $cards, 'notice' => $notice]);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function card(Resident $resident): array
    {
        return [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'system_id_no' => $resident->system_id_no,
            'household_code' => $resident->household?->household_code,
            'barangay_name' => $resident->barangay?->name,
            'qr_code_hash' => $resident->qr_code_hash,
        ];
    }
}
