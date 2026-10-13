import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { emptyRelative } from '@/lib/registration-draft';
import type { RelativeDraft, RelativesDraft } from '@/types';
import { RelativeCard } from './relative-card';
import type { StepProps } from './step-types';

type Slot = 'father' | 'mother' | 'spouse';

export function StepFamilyNew({
    draft,
    update,
    errors,
    clearErrors,
}: StepProps) {
    const { relatives, person } = draft;

    function setRelatives(
        next: (current: RelativesDraft) => Partial<RelativesDraft>,
    ) {
        update((current) => ({
            relatives: { ...current.relatives, ...next(current.relatives) },
        }));
        clearErrors('relatives');
    }

    function addSlot(slot: Slot) {
        const defaults: Record<Slot, Partial<RelativeDraft>> = {
            father: { last_name: person.last_name, gender: 'Male' },
            mother: { gender: 'Female' },
            spouse: {
                lives_with_me: true,
                gender:
                    person.gender === 'Male'
                        ? 'Female'
                        : person.gender === 'Female'
                          ? 'Male'
                          : '',
            },
        };

        setRelatives(() => ({ [slot]: emptyRelative(defaults[slot]) }));
    }

    function patchSlot(slot: Slot, patch: Partial<RelativeDraft>) {
        setRelatives((current) => ({
            [slot]: { ...current[slot]!, ...patch },
        }));
    }

    function patchChild(index: number, patch: Partial<RelativeDraft>) {
        setRelatives((current) => ({
            children: current.children.map((child, i) =>
                i === index ? { ...child, ...patch } : child,
            ),
        }));
    }

    const slots: {
        slot: Slot;
        title: string;
        add: string;
        askGender: boolean;
    }[] = [
        {
            slot: 'father',
            title: 'Father',
            add: 'Add my father',
            askGender: false,
        },
        {
            slot: 'mother',
            title: 'Mother',
            add: 'Add my mother',
            askGender: false,
        },
        {
            slot: 'spouse',
            title: 'Spouse or partner',
            add: 'Add my spouse or partner',
            askGender: true,
        },
    ];

    return (
        <div className="grid gap-6">
            <p className="rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-base dark:border-sidebar-border dark:bg-sidebar">
                You are the first in your family to register, so please list
                your
                <strong> blood relatives and spouse</strong> here. They will be
                saved as <strong>listed relatives</strong>: they cannot receive
                relief until they register themselves. Brothers and sisters do
                not need to be typed in; they appear automatically through
                shared parents.
            </p>

            {slots.map(({ slot, title, add, askGender }) => {
                const relative = relatives[slot];

                return relative ? (
                    <RelativeCard
                        key={slot}
                        id={slot}
                        title={title}
                        relative={relative}
                        askGender={askGender}
                        errorPrefix={`relatives.${slot}`}
                        errors={errors}
                        onChange={(patch) => patchSlot(slot, patch)}
                        onRemove={() => setRelatives(() => ({ [slot]: null }))}
                    />
                ) : (
                    <Button
                        key={slot}
                        type="button"
                        variant="outline"
                        className="justify-start"
                        onClick={() => addSlot(slot)}
                    >
                        <Plus />
                        {add}
                    </Button>
                );
            })}

            {relatives.father && relatives.mother && (
                <div className="flex items-center gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
                    <Checkbox
                        id="parents-partners"
                        checked={relatives.parents_partners}
                        onCheckedChange={(checked) =>
                            setRelatives(() => ({
                                parents_partners: checked === true,
                            }))
                        }
                    />
                    <Label
                        htmlFor="parents-partners"
                        className="text-base font-medium"
                    >
                        My father and mother are married or partners
                    </Label>
                </div>
            )}

            <section className="grid gap-4">
                <h3 className="text-xl font-bold">Children</h3>
                {relatives.children.map((child, index) => (
                    <RelativeCard
                        key={index}
                        id={`child-${index}`}
                        title={`Child ${index + 1}`}
                        relative={child}
                        askGender
                        askOtherParent={relatives.spouse !== null}
                        errorPrefix={`relatives.children.${index}`}
                        errors={errors}
                        onChange={(patch) => patchChild(index, patch)}
                        onRemove={() =>
                            setRelatives((current) => ({
                                children: current.children.filter(
                                    (_, i) => i !== index,
                                ),
                            }))
                        }
                    />
                ))}
                <Button
                    type="button"
                    variant="outline"
                    className="justify-start"
                    disabled={relatives.children.length >= 15}
                    onClick={() =>
                        setRelatives((current) => ({
                            children: [
                                ...current.children,
                                emptyRelative({
                                    last_name: person.last_name,
                                    lives_with_me: true,
                                }),
                            ],
                        }))
                    }
                >
                    <Plus />
                    Add a child
                </Button>
            </section>
        </div>
    );
}
