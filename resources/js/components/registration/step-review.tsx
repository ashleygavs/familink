import { Pencil, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError, { fieldError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    useBloodRelatives,
    bloodRelativesUrl,
} from '@/hooks/use-family-suggestions';
import { formatDate, properCase } from '@/lib/format';
import { RELATIVE_KINDS } from '@/lib/relatives';
import {
    STEP_FAMILY,
    STEP_HOUSEHOLD,
    STEP_PATH,
    STEP_PERSON,
} from '@/lib/registration-draft';
import { hasListedRelatives } from '@/lib/registration-validation';
import type { RelativeDraft } from '@/types';
import type { StepProps } from './step-types';

type Props = StepProps & {
    barangayName: string | null;
    onEdit: (step: number) => void;
};

function Section({
    title,
    onEdit,
    children,
}: {
    title: string;
    onEdit: () => void;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-2 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-xl font-bold">{title}</h3>
                <Button type="button" variant="outline" onClick={onEdit}>
                    <Pencil />
                    Edit
                </Button>
            </div>
            <div className="grid gap-1 text-base">{children}</div>
        </section>
    );
}

function describeRelative(relative: RelativeDraft): string {
    const name = [
        ...[relative.first_name, relative.middle_name, relative.last_name]
            .filter(Boolean)
            .map(properCase),
        relative.suffix,
    ]
        .filter(Boolean)
        .join(' ');
    const born = relative.birthdate
        ? `born ${formatDate(relative.birthdate)}`
        : `about ${relative.approximate_age} years old`;
    const notes = [
        relative.is_deceased ? 'deceased' : null,
        relative.lives_with_me ? 'lives with you' : null,
    ].filter(Boolean);

    return `${name} (${[born, ...notes].join(', ')})`;
}

export function StepReview({
    draft,
    update,
    errors,
    clearErrors,
    barangayName,
    onEdit,
}: Props) {
    const { person, household, relatives } = draft;
    const listed = hasListedRelatives(draft);
    const relativesInRecords = useBloodRelatives(bloodRelativesUrl(draft));
    const fullName = [
        ...[person.first_name, person.middle_name, person.last_name]
            .filter(Boolean)
            .map(properCase),
        person.suffix,
    ]
        .filter(Boolean)
        .join(' ');

    // The claimed record and the chosen links can name the same person, so each line is shown once.
    const linked = [
        draft.claim?.father_name && `Father: ${draft.claim.father_name}`,
        draft.claim?.mother_name && `Mother: ${draft.claim.mother_name}`,
        draft.claim?.spouse_name && `Spouse: ${draft.claim.spouse_name}`,
        ...(draft.claim?.children_names ?? []).map((name) => `Child: ${name}`),
        draft.father && `Father: ${draft.father.full_name}`,
        draft.mother && `Mother: ${draft.mother.full_name}`,
        draft.spouse && `Spouse: ${draft.spouse.full_name}`,
        ...draft.dependents.map((child) => `Child: ${child.full_name}`),
    ]
        .filter(Boolean)
        .filter(
            (line, index, lines) => lines.indexOf(line) === index,
        ) as string[];

    const relativeLines = relativesInRecords.data
        ? Object.values(relativesInRecords.data)
              .flat()
              .map((match) => `${match.relation}: ${match.resident.full_name}`)
        : [];
    const namedLines =
        draft.path === 'new' && !draft.claim
            ? []
            : RELATIVE_KINDS.flatMap(({ kind, title }) => {
                  return draft.viaRelatives[kind].map(
                      (resident) => `${title}: ${resident.full_name}`,
                  );
              });
    const headName = household.household?.head_name;

    return (
        <div className="grid gap-4">
            <p className="text-base text-muted-foreground">
                Please check everything. Use Edit to fix anything. New residents
                wait for admin approval before they can receive relief.
            </p>

            <Section title="Family record" onEdit={() => onEdit(STEP_PATH)}>
                <p>
                    {draft.claim
                        ? `Using the record your family listed (${draft.claim.system_id_no}).`
                        : draft.path === 'new'
                          ? 'Starting a new family record.'
                          : 'Joining an existing family record.'}
                </p>
            </Section>

            <Section title="About you" onEdit={() => onEdit(STEP_PERSON)}>
                <p className="text-lg font-semibold">{fullName}</p>
                <p>
                    Born {person.birthdate ? formatDate(person.birthdate) : '-'}{' '}
                    · {person.gender} · {person.civil_status}
                </p>
                {person.maiden_name && <p>Maiden name: {person.maiden_name}</p>}
            </Section>

            <Section title="Family" onEdit={() => onEdit(STEP_FAMILY)}>
                {listed ? (
                    <>
                        {relatives.father && (
                            <p>Father: {describeRelative(relatives.father)}</p>
                        )}
                        {relatives.mother && (
                            <p>Mother: {describeRelative(relatives.mother)}</p>
                        )}
                        {relatives.spouse && (
                            <p>Spouse: {describeRelative(relatives.spouse)}</p>
                        )}
                        {relatives.children.map((child, index) => (
                            <p key={index}>Child: {describeRelative(child)}</p>
                        ))}
                        {!relatives.father &&
                            !relatives.mother &&
                            !relatives.spouse &&
                            relatives.children.length === 0 && (
                                <p>No relatives listed.</p>
                            )}
                    </>
                ) : linked.length > 0 ? (
                    linked.map((line) => <p key={line}>{line}</p>)
                ) : (
                    <p>
                        {draft.claim
                            ? 'Keeping the links your family already made.'
                            : 'No family links yet. They can be added later.'}
                    </p>
                )}
                {relativeLines.length > 0 && (
                    <div className="mt-2 grid gap-1">
                        <p className="font-semibold">
                            Relatives found in the records
                        </p>
                        {relativeLines.map((line) => (
                            <p key={line}>{line}</p>
                        ))}
                    </div>
                )}
                {namedLines.length > 0 && (
                    <div className="mt-2 grid gap-1">
                        <p className="font-semibold">Relatives you named</p>
                        {namedLines.map((line) => (
                            <p key={line}>{line}</p>
                        ))}
                    </div>
                )}
            </Section>

            <Section title="Household" onEdit={() => onEdit(STEP_HOUSEHOLD)}>
                <p>Barangay {barangayName ?? '-'}</p>
                <p>
                    {household.mode === 'new'
                        ? `New household at ${household.street_address}`
                        : `Joining ${household.household?.household_code} (${household.household?.street_address})`}
                </p>
                <p>
                    {household.is_head
                        ? 'Head of the household (this person)'
                        : `Relationship to the head: ${household.household_role}`}
                </p>
                {!household.is_head && household.mode === 'existing' && (
                    <p>
                        Household head:{' '}
                        {headName ? (
                            <strong>{headName}</strong>
                        ) : (
                            'not registered yet'
                        )}
                    </p>
                )}
            </Section>

            {listed && (
                <div className="grid gap-2 rounded-xl border-2 border-blue-200 bg-sky-50 p-4 dark:border-sidebar-border dark:bg-sidebar">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="consent"
                            {...fieldError('consent', errors.consent)}
                            checked={draft.consent}
                            onCheckedChange={(checked) => {
                                update({ consent: checked === true });
                                clearErrors('consent');
                            }}
                            className="mt-1"
                        />
                        <Label
                            htmlFor="consent"
                            className="text-base leading-snug font-medium"
                        >
                            <ShieldCheck className="size-5 shrink-0 text-blue-600" />
                            I confirm that my relatives know I am sharing their
                            name and birthdate to build our family record, and
                            that this information is correct.
                        </Label>
                    </div>
                    <InputError id="consent-error" message={errors.consent} />
                </div>
            )}
        </div>
    );
}
