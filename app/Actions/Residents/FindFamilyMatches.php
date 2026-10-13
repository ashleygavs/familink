<?php

namespace App\Actions\Residents;

use App\Actions\Residents\Concerns\PrioritisesLocalCandidates;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Suggests likely relatives of a person from the residents already on record.
 *
 * Suggestions are scored from the surname, the Filipino naming convention (a child's middle
 * name is the mother's maiden surname), the household and plausible age gaps. Only matches at or
 * above the medium threshold are returned, and a person always confirms them.
 *
 * @phpstan-type Match array{resident: Resident, score: int, confidence: string, reasons: list<string>}
 * @phpstan-type Claim array{resident: Resident, reasons: list<string>}
 * @phpstan-type Relative array{resident: Resident, relation: string, score: int, confidence: string, reasons: list<string>}
 */
class FindFamilyMatches
{
    use PrioritisesLocalCandidates;

    private const HIGH = 70;

    private const MEDIUM = 45;

    private const DEFAULT_LIMIT = 3;

    /** The most candidate rows scored per role, after the database has filtered and ordered them. */
    private const POOL = 300;

    public function __construct(private FindLikelyRelatives $likelyRelatives) {}

    /**
     * @param  array{first_name?: string|null, middle_name?: string|null, maiden_name?: string|null, last_name?: string|null, birthdate: string, gender?: string|null, civil_status?: string|null, household_id?: int|null, barangay_id?: int|null}  $person
     * @param  int|list<int>|null  $scope  Limit candidates to this barangay, or these barangays, and the relatives listed from them. Null means everyone.
     * @param  Resident|null  $subject  The resident being matched, excluded from the results.
     * @param  array{limit?: int, rejected?: array<string, mixed>}  $options  How many matches to return per role (1 to 12, default 3), and the
     *                                                                        residents the person already said "no" to, by role (father, mother,
     *                                                                        spouse, children) and, under "relatives", by relative kind.
     * @return array{claims: list<Claim>, father: list<Match>, mother: list<Match>, spouse: list<Match>, children: list<Match>, relatives: list<Relative>}
     */
    public function handle(array $person, int|array|null $scope = null, ?Resident $subject = null, array $options = []): array
    {
        $ids = fn (mixed $values): array => array_values(array_unique(array_map('intval', (array) $values)));
        $rejected = (array) ($options['rejected'] ?? []);

        $gender = $person['gender'] ?? null;
        $civilStatus = $person['civil_status'] ?? $subject?->civil_status;
        $marriedWoman = $gender === Gender::Female->value
            && in_array($civilStatus, ['Married', 'Widowed', 'Separated', 'Annulled'], true);
        $last = Resident::normalizeName($person['last_name'] ?? null);
        $maiden = Resident::normalizeName($person['maiden_name'] ?? null);

        $context = [
            'birth' => Carbon::parse($person['birthdate'])->startOfDay(),
            'first' => Resident::normalizeName($person['first_name'] ?? null),
            'middle' => Resident::normalizeName($person['middle_name'] ?? null),
            'last' => $last,
            'maiden' => $maiden,
            // A married woman carries her husband's surname, so her parents are matched by her maiden name.
            'parent_surname' => $marriedWoman ? $maiden : $last,
            // The parents of the spouse are in-laws, never the person's own parents.
            'excluded_ids' => array_values(array_filter([$subject?->spouse?->father_id, $subject?->spouse?->mother_id])),
            'gender' => $gender,
            'civil_status' => $civilStatus,
            'household_id' => isset($person['household_id']) ? (int) $person['household_id'] : null,
            'barangay_id' => isset($person['barangay_id']) ? (int) $person['barangay_id'] : null,
            'scope' => is_int($scope) ? [$scope] : $scope,
            'subject' => $subject,
            'limit' => max(1, min(12, (int) ($options['limit'] ?? self::DEFAULT_LIMIT))),
            'rejected' => [
                'father' => $ids($rejected['father'] ?? []),
                'mother' => $ids($rejected['mother'] ?? []),
                'spouse' => $ids($rejected['spouse'] ?? []),
                'children' => $ids($rejected['children'] ?? []),
                'relatives' => array_map($ids, (array) ($rejected['relatives'] ?? [])),
            ],
        ];

        $result = ['claims' => $this->claims($context), 'father' => [], 'mother' => [], 'spouse' => [], 'children' => [], 'relatives' => []];

        if ($context['gender'] === null) {
            return $result;
        }

        [$context['sibling_fathers'], $context['sibling_mothers']] = $this->siblingParents($context);

        $result['father'] = $this->fathers($context);
        $result['mother'] = $this->mothers($context, $result['father']);
        $result['spouse'] = $this->spouses($context, $result['father'], $result['mother']);
        $result['children'] = $this->children($context);
        $result['relatives'] = $this->likelyRelatives->handle($context, array_map(fn (array $match): int => $match['resident']->id, [...$result['father'], ...$result['mother']]));

        return $result;
    }

    /**
     * The parents of residents who share this person's surname and have a similar age: those residents may
     * be brothers or sisters, so their parents are likely this person's parents too.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, string>, 1: array<int, string>} Father and mother ids, each with the sibling's first name.
     */
    private function siblingParents(array $context): array
    {
        if ($context['parent_surname'] === null) {
            return [[], []];
        }

        $siblings = $this->base($context)
            ->where('last_name_key', $context['parent_surname'])
            ->where(fn (Builder $query) => $query->whereNotNull('father_id')->orWhereNotNull('mother_id'))
            ->where('birthdate', '>=', $context['birth']->copy()->subYears(20)->toDateString())
            ->where('birthdate', '<=', $context['birth']->copy()->addYears(20)->toDateString());

        $siblings = $this->prioritise($siblings, $context)->limit(20)->get();

        $fathers = [];
        $mothers = [];

        foreach ($siblings as $sibling) {
            if ($sibling->father_id !== null) {
                $fathers[$sibling->father_id] = $sibling->first_name;
            }

            if ($sibling->mother_id !== null) {
                $mothers[$sibling->mother_id] = $sibling->first_name;
            }
        }

        return [$fathers, $mothers];
    }

    /**
     * Listed relatives that look like the person who is registering ("Is this you?").
     *
     * @param  array<string, mixed>  $context
     * @return list<Claim>
     */
    private function claims(array $context): array
    {
        if ($context['first'] === null || $context['last'] === null) {
            return [];
        }

        $scored = [];

        $candidates = $this->base($context)
            ->with(['childrenAsFather', 'childrenAsMother'])
            ->where('status', ResidentStatus::Listed->value)
            ->where('is_deceased', false)
            ->where('last_name_key', $context['last'])
            ->when($context['gender'], fn (Builder $query, string $gender) => $query->where('gender', $gender))
            ->limit(20)
            ->get();

        foreach ($candidates as $candidate) {
            $sameFirstName = $candidate->first_name_key === $context['first'];

            if (! $sameFirstName && ! $this->similarFirstName($candidate->first_name_key, $context['first'])) {
                continue;
            }

            $days = (int) abs($candidate->birthdate->diffInDays($context['birth'], true));
            $years = abs($candidate->birthdate->diffInYears($context['birth'], true));
            $reasons = [$sameFirstName ? 'Same name' : 'Similar name'];

            if ($days === 0) {
                $reasons[] = 'Same birthdate';
                $score = 100;
            } elseif ($years <= 1 || ($candidate->birthdate_estimated && $years <= 2)) {
                $reasons[] = 'Similar birthdate';
                $score = 80;
            } elseif ($sameFirstName) {
                $reasons[] = 'Different birthdate, please check with your family';
                $score = 50;
            } else {
                continue;
            }

            $scored[] = ['score' => $score - ($sameFirstName ? 0 : 5), 'claim' => ['resident' => $candidate, 'reasons' => $reasons]];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice(array_column($scored, 'claim'), 0, 3);
    }

    /**
     * Names that are close enough to be the same person, e.g. "Mark" and "Mark Flores" or "Marc" and "Mark".
     */
    private function similarFirstName(?string $candidateKey, string $key): bool
    {
        if ($candidateKey === null || min(strlen($candidateKey), strlen($key)) < 3) {
            return false;
        }

        return str_starts_with($key, $candidateKey)
            || str_starts_with($candidateKey, $key)
            || (min(strlen($candidateKey), strlen($key)) >= 4 && levenshtein($candidateKey, $key) <= 1);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<Match>
     */
    private function fathers(array $context): array
    {
        $candidates = $this->base($context)
            ->where('gender', Gender::Male->value)
            ->whereNotIn('id', [...$context['excluded_ids'], ...$context['rejected']['father']])
            ->where('birthdate', '<=', $context['birth']->copy()->subYears(15)->toDateString())
            ->where('birthdate', '>=', $context['birth']->copy()->subYears(55)->toDateString())
            ->where(function (Builder $query) use ($context): void {
                $this->surnameOrHousehold($query, $context);

                if ($context['sibling_fathers'] !== []) {
                    $query->orWhereIn('id', array_keys($context['sibling_fathers']));
                }
            });

        $candidates = $this->prioritise($candidates, $context)->limit(self::POOL)->get();

        return $this->rank($candidates, function (Resident $candidate) use ($context): ?array {
            $gap = $this->yearsOlder($candidate, $context['birth']);

            if ($gap < 15 || $gap > 55) {
                return null;
            }

            [$score, $reasons] = [0, []];
            $siblingName = $context['sibling_fathers'][$candidate->id] ?? null;

            $this->add($score, $reasons, 40, 'Same surname', $this->sameKey($candidate->last_name_key, $context['parent_surname']));
            $this->add($score, $reasons, 35, "Father of {$siblingName}, who has your surname and a similar age", $siblingName !== null);
            $this->add($score, $reasons, 30, 'Lives in the household you chose', $this->sameHousehold($candidate, $context));
            $this->add($score, $reasons, $gap <= 50 ? 20 : 10, "About {$gap} years older", true);
            $this->add($score, $reasons, 10, 'Same barangay', $this->sameBarangay($candidate, $context));

            return [$score, $reasons];
        }, $context['limit']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<Match>  $fathers
     * @return list<Match>
     */
    private function mothers(array $context, array $fathers): array
    {
        $fatherSpouseIds = [];

        foreach ($fathers as $father) {
            if ($father['resident']->spouse_id !== null) {
                $fatherSpouseIds[] = $father['resident']->spouse_id;
            }
        }

        $candidates = $this->base($context)
            ->where('gender', Gender::Female->value)
            ->whereNotIn('id', [...$context['excluded_ids'], ...$context['rejected']['mother']])
            ->where('birthdate', '<=', $context['birth']->copy()->subYears(15)->toDateString())
            ->where('birthdate', '>=', $context['birth']->copy()->subYears(50)->toDateString())
            ->where(function (Builder $query) use ($context, $fatherSpouseIds): void {
                $query->whereRaw('1 = 0');

                if ($context['parent_surname'] !== null) {
                    $query->orWhere('last_name_key', $context['parent_surname']);
                }

                if ($context['middle'] !== null) {
                    $query->orWhere('last_name_key', $context['middle'])->orWhere('maiden_name_key', $context['middle']);
                }

                if ($context['household_id'] !== null) {
                    $query->orWhere('household_id', $context['household_id']);
                }

                if ($fatherSpouseIds !== []) {
                    $query->orWhereIn('id', $fatherSpouseIds);
                }

                if ($context['sibling_mothers'] !== []) {
                    $query->orWhereIn('id', array_keys($context['sibling_mothers']));
                }
            });

        $candidates = $this->prioritise($candidates, $context)->limit(self::POOL)->get();

        return $this->rank($candidates, function (Resident $candidate) use ($context, $fatherSpouseIds): ?array {
            $gap = $this->yearsOlder($candidate, $context['birth']);

            if ($gap < 15 || $gap > 50) {
                return null;
            }

            [$score, $reasons] = [0, []];
            $siblingName = $context['sibling_mothers'][$candidate->id] ?? null;

            $this->add($score, $reasons, 40, 'Spouse of the suggested father', in_array($candidate->id, $fatherSpouseIds, true));
            $this->add($score, $reasons, 35, "Mother of {$siblingName}, who has your surname and a similar age", $siblingName !== null);

            if ($this->sameKey($candidate->maiden_name_key, $context['middle'])) {
                $this->add($score, $reasons, 40, 'Her maiden name matches your middle name', true);
            } else {
                $this->add($score, $reasons, 30, 'Her surname matches your middle name', $this->sameKey($candidate->last_name_key, $context['middle']));
            }

            $this->add($score, $reasons, 25, 'Same surname', $this->sameKey($candidate->last_name_key, $context['parent_surname']));
            $this->add($score, $reasons, 25, 'Lives in the household you chose', $this->sameHousehold($candidate, $context));
            $this->add($score, $reasons, $gap <= 45 ? 15 : 5, "About {$gap} years older", true);
            $this->add($score, $reasons, 10, 'Same barangay', $this->sameBarangay($candidate, $context));

            return [$score, $reasons];
        }, $context['limit']);
    }

    /**
     * Nobody is suggested as the spouse of someone who is single or already has a spouse, and brothers
     * or sisters (children of the suggested parents) are never suggested as a spouse.
     *
     * @param  array<string, mixed>  $context
     * @param  list<Match>  $fathers
     * @param  list<Match>  $mothers
     * @return list<Match>
     */
    private function spouses(array $context, array $fathers, array $mothers): array
    {
        $sameBarangayAndSurname = $context['last'] !== null && $context['barangay_id'] !== null;

        if (
            $context['civil_status'] === 'Single'
            || $context['subject']?->spouse_id !== null
            || ($context['household_id'] === null && ! $sameBarangayAndSurname)
        ) {
            return [];
        }

        $parentIds = array_map(fn (array $match): int => $match['resident']->id, [...$fathers, ...$mothers]);

        $candidates = $this->base($context)
            ->where('gender', $context['gender'] === Gender::Male->value ? Gender::Female->value : Gender::Male->value)
            ->whereNotIn('id', $context['rejected']['spouse'])
            ->whereNull('spouse_id')
            ->where('birthdate', '<=', now()->subYears(16)->toDateString())
            ->where('birthdate', '<=', $context['birth']->copy()->addYears(25)->toDateString())
            ->where('birthdate', '>=', $context['birth']->copy()->subYears(25)->toDateString())
            ->where(function (Builder $query) use ($context, $sameBarangayAndSurname): void {
                $query->whereRaw('1 = 0');

                if ($context['household_id'] !== null) {
                    $query->orWhere('household_id', $context['household_id']);
                }

                if ($sameBarangayAndSurname) {
                    $query->orWhere(fn (Builder $inner) => $inner->where('last_name_key', $context['last'])->where('barangay_id', $context['barangay_id']));
                }
            });

        $candidates = $this->prioritise($candidates, $context)->limit(self::POOL)->get();

        return $this->rank($candidates, function (Resident $candidate) use ($context, $parentIds): ?array {
            $difference = abs($this->yearsOlder($candidate, $context['birth']));
            $isFamily = in_array($candidate->id, $parentIds, true)
                || in_array($candidate->father_id, $parentIds, true)
                || in_array($candidate->mother_id, $parentIds, true);

            if ($difference > 25 || $isFamily) {
                return null;
            }

            [$score, $reasons] = [0, []];
            $this->add($score, $reasons, 40, 'Lives in the household you chose', $this->sameHousehold($candidate, $context));
            $this->add($score, $reasons, 15, 'Same surname', $this->sameKey($candidate->last_name_key, $context['last']));
            $this->add($score, $reasons, $difference <= 10 ? 20 : 10, 'Similar age', true);
            $this->add($score, $reasons, 15, 'Recorded as married', $candidate->civil_status === 'Married');
            $this->add($score, $reasons, 10, 'Same barangay', $this->sameBarangay($candidate, $context));

            return [$score, $reasons];
        }, $context['limit']);
    }

    /**
     * Residents without a recorded parent of this person's gender who look like this person's children.
     *
     * @param  array<string, mixed>  $context
     * @return list<Match>
     */
    private function children(array $context): array
    {
        $isFather = $context['gender'] === Gender::Male->value;
        $slot = $isFather ? 'father_id' : 'mother_id';

        $candidates = $this->base($context)
            ->whereNull($slot)
            ->whereNotIn('id', $context['rejected']['children'])
            ->where('birthdate', '>=', $context['birth']->copy()->addYears(15)->toDateString())
            ->where(function (Builder $query) use ($context, $isFather): void {
                $query->whereRaw('1 = 0');

                if ($context['last'] !== null) {
                    $query->orWhere('last_name_key', $context['last']);
                }

                if (! $isFather) {
                    foreach (array_filter([$context['maiden'], $context['last']]) as $key) {
                        $query->orWhere('middle_name_key', $key);
                    }
                }

                if ($context['household_id'] !== null) {
                    $query->orWhere('household_id', $context['household_id']);
                }
            });

        $candidates = $this->prioritise($candidates, $context)->limit(self::POOL)->get();

        return $this->rank($candidates, function (Resident $candidate) use ($context, $isFather): array {
            $gap = -$this->yearsOlder($candidate, $context['birth']);

            [$score, $reasons] = [0, []];

            if ($isFather) {
                $this->add($score, $reasons, 40, 'Same surname', $this->sameKey($candidate->last_name_key, $context['last']));
            } else {
                if ($this->sameKey($candidate->middle_name_key, $context['maiden'])) {
                    $this->add($score, $reasons, 35, 'Your maiden name is their middle name', true);
                } else {
                    $this->add($score, $reasons, 35, 'Your surname is their middle name', $this->sameKey($candidate->middle_name_key, $context['last']));
                }

                $this->add($score, $reasons, 25, 'Same surname', $this->sameKey($candidate->last_name_key, $context['last']));
            }

            $this->add($score, $reasons, 30, 'Lives in the household you chose', $this->sameHousehold($candidate, $context));
            $this->add($score, $reasons, $gap <= 45 ? 15 : 5, "About {$gap} years younger", true);
            $this->add($score, $reasons, 10, 'Same barangay', $this->sameBarangay($candidate, $context));

            return [$score, $reasons];
        }, $context['limit']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Builder<Resident>
     */
    private function base(array $context): Builder
    {
        return Resident::query()
            ->with(['barangay', 'household', 'listedBy', 'father', 'mother', 'spouse'])
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when($context['subject'], fn (Builder $query, Resident $subject) => $query->whereKeyNot($subject->id))
            ->when($context['scope'] !== null, fn (Builder $query) => $query->where(
                fn (Builder $scoped) => $scoped
                    ->whereIn('barangay_id', $context['scope'])
                    ->orWhereHas('listedBy', fn (Builder $lister) => $lister->whereIn('barangay_id', $context['scope'])),
            ));
    }

    /**
     * @param  Builder<Resident>  $query
     * @param  array<string, mixed>  $context
     */
    private function surnameOrHousehold(Builder $query, array $context): void
    {
        $query->whereRaw('1 = 0');

        if ($context['parent_surname'] !== null) {
            $query->orWhere('last_name_key', $context['parent_surname']);
        }

        if ($context['household_id'] !== null) {
            $query->orWhere('household_id', $context['household_id']);
        }
    }

    /**
     * Score the candidates, drop weak ones and return the best matches first.
     *
     * @param  iterable<Resident>  $candidates
     * @param  Closure(Resident): (array{0: int, 1: list<string>}|null)  $scorer
     * @return list<Match>
     */
    private function rank(iterable $candidates, Closure $scorer, int $limit = 3): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            $scored = $scorer($candidate);

            if ($scored === null || $scored[0] < self::MEDIUM) {
                continue;
            }

            $matches[] = [
                'resident' => $candidate,
                'score' => $scored[0],
                'confidence' => $scored[0] >= self::HIGH ? 'high' : 'medium',
                'reasons' => $scored[1],
            ];
        }

        usort($matches, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param  list<string>  $reasons
     */
    private function add(int &$score, array &$reasons, int $points, string $reason, bool $applies): void
    {
        if ($applies) {
            $score += $points;
            $reasons[] = $reason;
        }
    }

    private function sameKey(?string $candidateKey, ?string $key): bool
    {
        return $key !== null && $candidateKey === $key;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sameHousehold(Resident $candidate, array $context): bool
    {
        return $context['household_id'] !== null && $candidate->household_id === $context['household_id'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sameBarangay(Resident $candidate, array $context): bool
    {
        return $context['barangay_id'] !== null && $candidate->barangay_id === $context['barangay_id'];
    }

    /**
     * How many whole years the candidate is older than the person (negative when younger).
     */
    private function yearsOlder(Resident $candidate, Carbon $birth): int
    {
        return (int) $candidate->birthdate->diffInYears($birth, false);
    }
}
