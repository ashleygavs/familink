import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Gender, RelativeDraft } from '@/types';
import { ChoiceSelect, Field, SuffixSelect } from './field';
import type { StepErrors } from './step-types';

type Props = {
    id: string;
    title: string;
    relative: RelativeDraft;
    onChange: (patch: Partial<RelativeDraft>) => void;
    onRemove: () => void;
    errorPrefix: string;
    errors: StepErrors;
    askGender: boolean;
    askOtherParent?: boolean;
};

/**
 * One relative a first registrant lists. Only the basics are asked so the
 * form stays short; the relative can complete their own record later.
 */
export function RelativeCard({
    id,
    title,
    relative,
    onChange,
    onRemove,
    errorPrefix,
    errors,
    askGender,
    askOtherParent = false,
}: Props) {
    const error = (field: string) => errors[`${errorPrefix}.${field}`];
    const today = new Date().toISOString().slice(0, 10);

    return (
        <fieldset className="grid gap-4 rounded-xl border-2 border-blue-200 bg-card p-4 md:p-5 dark:border-sidebar-border">
            <legend className="sr-only">{title}</legend>
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-xl font-bold">{title}</h3>
                <Button type="button" variant="outline" onClick={onRemove}>
                    <X />
                    Remove
                </Button>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Field
                    id={`${id}-first`}
                    label="First name"
                    error={error('first_name')}
                >
                    <Input
                        id={`${id}-first`}
                        value={relative.first_name}
                        onChange={(e) =>
                            onChange({ first_name: e.target.value })
                        }
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id={`${id}-middle`}
                    label="Middle name (optional)"
                    error={error('middle_name')}
                >
                    <Input
                        id={`${id}-middle`}
                        value={relative.middle_name}
                        onChange={(e) =>
                            onChange({ middle_name: e.target.value })
                        }
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id={`${id}-last`}
                    label="Last name"
                    error={error('last_name')}
                >
                    <Input
                        id={`${id}-last`}
                        value={relative.last_name}
                        onChange={(e) =>
                            onChange({ last_name: e.target.value })
                        }
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id={`${id}-suffix`}
                    label="Suffix (optional)"
                    error={error('suffix')}
                >
                    <SuffixSelect
                        id={`${id}-suffix`}
                        value={relative.suffix}
                        onChange={(suffix) => onChange({ suffix })}
                    />
                </Field>
                {askGender && (
                    <Field
                        id={`${id}-gender`}
                        label="Gender"
                        error={error('gender')}
                    >
                        <ChoiceSelect
                            id={`${id}-gender`}
                            value={relative.gender}
                            placeholder="Choose gender"
                            values={[
                                { value: 'Male', label: 'Male' },
                                { value: 'Female', label: 'Female' },
                            ]}
                            onChange={(value) =>
                                onChange({ gender: value as Gender })
                            }
                        />
                    </Field>
                )}
                <Field
                    id={`${id}-birthdate`}
                    label="Birthdate"
                    error={error('birthdate')}
                >
                    <Input
                        id={`${id}-birthdate`}
                        type="date"
                        max={today}
                        value={relative.birthdate}
                        onChange={(e) =>
                            onChange({ birthdate: e.target.value })
                        }
                    />
                </Field>
                <Field
                    id={`${id}-age`}
                    label="Or approximate age"
                    hint="Fill this in only if you do not know the birthdate."
                    error={error('approximate_age')}
                >
                    <Input
                        id={`${id}-age`}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={120}
                        value={relative.approximate_age}
                        onChange={(e) =>
                            onChange({ approximate_age: e.target.value })
                        }
                    />
                </Field>
            </div>

            <div className="grid gap-3">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id={`${id}-deceased`}
                        checked={relative.is_deceased}
                        onCheckedChange={(checked) =>
                            onChange({
                                is_deceased: checked === true,
                                lives_with_me:
                                    checked === true
                                        ? false
                                        : relative.lives_with_me,
                            })
                        }
                    />
                    <Label htmlFor={`${id}-deceased`} className="font-medium">
                        This person has passed away
                    </Label>
                </div>
                {!relative.is_deceased && (
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`${id}-lives`}
                            checked={relative.lives_with_me}
                            onCheckedChange={(checked) =>
                                onChange({ lives_with_me: checked === true })
                            }
                        />
                        <Label htmlFor={`${id}-lives`} className="font-medium">
                            Lives in my household
                        </Label>
                    </div>
                )}
                {askOtherParent && (
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`${id}-other-parent`}
                            checked={relative.other_parent_is_spouse}
                            onCheckedChange={(checked) =>
                                onChange({
                                    other_parent_is_spouse: checked === true,
                                })
                            }
                        />
                        <Label
                            htmlFor={`${id}-other-parent`}
                            className="font-medium"
                        >
                            Also the child of my spouse
                        </Label>
                    </div>
                )}
            </div>
        </fieldset>
    );
}
