<?php

namespace App\Http\Controllers;

use App\Actions\Residents\RecordSuggestionFeedback;
use App\Enums\RelativeKind;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\SuggestionFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SuggestionReportController extends Controller
{
    /**
     * How often each kind of suggested relative is confirmed, rejected or set aside, so the matching rules
     * can be tuned from real use. A rate is the share of confirmed answers among yes, no and "related another
     * way" answers; "not sure" does not count either way.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $input = $request->validate([
            'range' => ['nullable', 'in:30,90,all'],
            'place' => ['nullable', 'regex:/^[mb]:\d+$/'],
        ]);
        $range = $input['range'] ?? '90';
        $place = $input['place'] ?? null;
        $placeIds = $place === null ? null : $this->barangayIdsFor($place, $user->accessibleBarangayIds());

        $totals = ['accepted' => 0, 'rejected' => 0, 'unsure' => 0, 'reclassified' => 0];
        $byRole = [];
        $byConfidence = [];
        $byReason = [];
        $moved = [];

        SuggestionFeedback::query()
            ->whereHas('resident', fn ($resident) => $resident->visibleTo($user)
                ->when($placeIds !== null, fn ($scoped) => $scoped->whereIn('barangay_id', $placeIds)))
            ->when($range !== 'all', fn ($query) => $query->where('created_at', '>=', Carbon::now()->subDays((int) $range)))
            ->orderBy('id')
            ->lazy(1000)
            ->each(function (SuggestionFeedback $feedback) use (&$totals, &$byRole, &$byConfidence, &$byReason, &$moved): void {
                $decision = $feedback->decision;
                $totals[$decision] = ($totals[$decision] ?? 0) + 1;
                $this->count($byRole, $feedback->role, $decision);

                if ($feedback->confidence !== null) {
                    $this->count($byConfidence, $feedback->confidence, $decision);
                }

                foreach (array_unique(array_map(fn ($reason): string => $this->generalReason((string) $reason), (array) $feedback->reasons)) as $reason) {
                    $this->count($byReason, $reason, $decision);
                }

                if ($decision === 'reclassified' && $feedback->reclassified_as !== null) {
                    $key = "{$feedback->role}>{$feedback->reclassified_as}";
                    $moved[$key] = ($moved[$key] ?? 0) + 1;
                }
            });

        return Inertia::render('admin/suggestion-report', [
            'totals' => [...$totals, 'all' => array_sum($totals)],
            'byRole' => $this->rows($byRole, fn (string $key) => $this->roleLabel($key)),
            'byConfidence' => $this->rows($byConfidence, fn (string $key) => $key === 'high' ? 'Strong match' : 'Possible match'),
            'byReason' => array_slice($this->rows($byReason, fn (string $key) => $key), 0, 15),
            'reclassified' => $this->movedRows($moved),
            'filters' => ['range' => $range, 'place' => $place],
            'places' => $this->places($user->accessibleBarangayIds()),
        ]);
    }

    /**
     * The barangays a chosen place covers, never wider than what the user may see.
     * A place is `m:ID` for a municipality or `b:ID` for one barangay.
     *
     * @param  list<int>|null  $accessible
     * @return list<int>
     */
    private function barangayIdsFor(string $place, ?array $accessible): array
    {
        [$kind, $id] = explode(':', $place);

        $ids = $kind === 'm'
            ? Municipality::query()->findOrFail((int) $id)->barangays()->pluck('id')->map(fn ($barangay): int => (int) $barangay)->all()
            : [(int) $id];

        return array_values($accessible === null ? $ids : array_intersect($ids, $accessible));
    }

    /**
     * Municipalities with the barangays the user may filter by.
     *
     * @param  list<int>|null  $accessible
     * @return array<int, array{id: int, name: string, barangays: array<int, array{id: int, name: string}>}>
     */
    private function places(?array $accessible): array
    {
        return Municipality::query()
            ->with(['barangays' => fn ($query) => $query->when($accessible !== null, fn ($inner) => $inner->whereIn('id', $accessible))->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Municipality $municipality) => $municipality->barangays->isNotEmpty())
            ->map(fn (Municipality $municipality): array => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'barangays' => $municipality->barangays->map(fn (Barangay $barangay): array => ['id' => $barangay->id, 'name' => $barangay->name])->values()->all(),
            ])->values()->all();
    }

    /**
     * Reasons that only differ by a number or a person's name count as one, so the table stays readable.
     * "About 23 years older" becomes "Age gap fits (older)" and "Parent of your cousin Marcus" becomes
     * "Parent of your cousin".
     */
    private function generalReason(string $reason): string
    {
        return match (true) {
            (bool) preg_match('/^About \d+ years (older|younger)$/', $reason, $match) => "Age gap fits ({$match[1]})",
            (bool) preg_match('/^(.+?) of your (cousin|uncle or aunt|brother or sister|nephew or niece|grandparent|grandchild) .+$/', $reason, $match) => "{$match[1]} of your {$match[2]}",
            (bool) preg_match('/^(.+?) of .+, your (.+)$/', $reason, $match) => "{$match[1]} of your {$match[2]}",
            default => $reason,
        };
    }

    /**
     * @param  array<string, array<string, int>>  $groups
     */
    private function count(array &$groups, string $key, string $decision): void
    {
        $groups[$key][$decision] = ($groups[$key][$decision] ?? 0) + 1;
    }

    /**
     * @param  array<string, array<string, int>>  $groups
     * @param  callable(string): string  $label
     * @return list<array{key: string, label: string, accepted: int, rejected: int, unsure: int, reclassified: int, total: int, rate: int|null}>
     */
    private function rows(array $groups, callable $label): array
    {
        $rows = [];

        foreach ($groups as $key => $counts) {
            $accepted = $counts['accepted'] ?? 0;
            $rejected = $counts['rejected'] ?? 0;
            $reclassified = $counts['reclassified'] ?? 0;
            $unsure = $counts['unsure'] ?? 0;
            $answered = $accepted + $rejected + $reclassified;

            $rows[] = [
                'key' => (string) $key,
                'label' => $label((string) $key),
                'accepted' => $accepted,
                'rejected' => $rejected,
                'unsure' => $unsure,
                'reclassified' => $reclassified,
                'total' => $answered + $unsure,
                'rate' => $answered === 0 ? null : (int) round($accepted / $answered * 100),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * @param  array<string, int>  $moved
     * @return list<array{from: string, to: string, count: int}>
     */
    private function movedRows(array $moved): array
    {
        arsort($moved);

        return array_map(function (string $key, int $count): array {
            [$from, $to] = explode('>', $key);

            return ['from' => $this->roleLabel($from), 'to' => $this->roleLabel($to), 'count' => $count];
        }, array_slice(array_keys($moved), 0, 10), array_slice(array_values($moved), 0, 10));
    }

    private function roleLabel(string $role): string
    {
        if (in_array($role, RecordSuggestionFeedback::ROLES, true)) {
            return ucfirst($role === 'children' ? 'Children' : $role);
        }

        return RelativeKind::tryFrom($role)?->label() ?? $role;
    }
}
