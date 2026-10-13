<?php

namespace App\Http\Controllers;

use App\Actions\Residents\RecordSuggestionFeedback;
use App\Enums\RelativeKind;
use App\Models\SuggestionFeedback;
use Illuminate\Http\Request;
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
        $totals = ['accepted' => 0, 'rejected' => 0, 'unsure' => 0, 'reclassified' => 0];
        $byRole = [];
        $byConfidence = [];
        $byReason = [];
        $moved = [];

        SuggestionFeedback::query()
            ->whereHas('resident', fn ($resident) => $resident->visibleTo($request->user()))
            ->orderBy('id')
            ->lazy(1000)
            ->each(function (SuggestionFeedback $feedback) use (&$totals, &$byRole, &$byConfidence, &$byReason, &$moved): void {
                $decision = $feedback->decision;
                $totals[$decision] = ($totals[$decision] ?? 0) + 1;
                $this->count($byRole, $feedback->role, $decision);

                if ($feedback->confidence !== null) {
                    $this->count($byConfidence, $feedback->confidence, $decision);
                }

                foreach ((array) $feedback->reasons as $reason) {
                    $this->count($byReason, (string) $reason, $decision);
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
        ]);
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
