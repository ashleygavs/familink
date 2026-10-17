import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    HeartCrack,
    HeartHandshake,
    History,
    Home,
    Network,
    Pencil,
    Printer,
    QrCode,
    TriangleAlert,
    Unlink,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ResidentCardController from '@/actions/App/Http/Controllers/ResidentCardController';
import ResidentManagementController from '@/actions/App/Http/Controllers/ResidentManagementController';
import { ResidentSummary } from '@/components/parent-selector';
import { ChoiceSelect, Field } from '@/components/registration/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { familyTree } from '@/routes';
import { index as residentsIndex } from '@/routes/residents';
import type { HouseholdOption, ResidentOption } from '@/types';

type Props = {
    resident: {
        id: number;
        full_name: string;
        system_id_no: string;
        status: string;
        civil_status: string | null;
        gender: string;
        birthdate: string;
        barangay_name: string | null;
        is_deceased: boolean;
        deceased_at: string | null;
        first_name: string;
        middle_name: string | null;
        last_name: string;
        suffix: string | null;
        maiden_name: string | null;
        is_head: boolean;
    };
    family: {
        father: string | null;
        mother: string | null;
        spouse: { id: number; full_name: string } | null;
        children: { id: number; full_name: string }[];
    };
    household: {
        id: number;
        household_code: string;
        street_address: string;
        role: string | null;
        head_name: string | null;
        members: {
            id: number;
            full_name: string;
            role: string | null;
            is_deceased: boolean;
        }[];
    } | null;
    /** Relief events that are open right now, which pause household changes. */
    openEvents: string[];
    canMarry: boolean;
    canReplaceCard: boolean;
    /** QR codes that were replaced, with how often each was scanned afterwards. */
    retiredCards: {
        id: number;
        reason: string;
        replaced_at: string | null;
        replaced_by: string;
        attempts: number;
        last_attempt_at: string | null;
    }[];
    canEndMarriage: boolean;
    /** People in this marriage who could go back to their maiden name. */
    maidenNameChoices: { id: number; full_name: string; maiden_name: string }[];
    roleChoices: string[];
    history: {
        id: number;
        type: string;
        summary: string;
        changed_by: string;
        changed_at: string | null;
    }[];
};

function Card({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: typeof Home;
    children: React.ReactNode;
}) {
    return (
        <section className="grid gap-2 rounded-xl border-2 border-blue-200 bg-card p-5 dark:border-sidebar-border">
            <h2 className="flex items-center gap-2 text-xl font-bold">
                <Icon className="size-6 text-blue-600" />
                {title}
            </h2>
            <div className="grid gap-1 text-base">{children}</div>
        </section>
    );
}

function MarriageDialog({
    resident,
    open,
    onClose,
}: {
    resident: Props['resident'];
    open: boolean;
    onClose: () => void;
}) {
    const [spouse, setSpouse] = useState<ResidentOption | null>(null);
    const form = useForm({ spouse_id: '', takes_surname_id: 'none' });

    function choose(person: ResidentOption) {
        setSpouse(person);
        form.setData('spouse_id', String(person.id));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            spouse_id: Number(data.spouse_id),
            takes_surname_id:
                data.takes_surname_id === 'none'
                    ? null
                    : Number(data.takes_surname_id),
        }));
        form.post(ResidentManagementController.marriage.url(resident.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSpouse(null);
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Record a marriage
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        {resident.full_name} and the other person will each be
                        recorded as the other&apos;s spouse and marked Married.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {spouse === null ? (
                        <SearchSelect<ResidentOption>
                            id="marriage-spouse"
                            label="Who did they marry?"
                            placeholder="Search by name or resident ID"
                            endpoint={ResidentManagementController.spouseCandidates.url(
                                resident.id,
                            )}
                            helper="Only approved, unmarried adults in your barangays are shown."
                            error={form.errors.spouse_id}
                            renderOption={(person) => (
                                <ResidentSummary resident={person} />
                            )}
                            onSelect={choose}
                        />
                    ) : (
                        <div className="grid gap-2 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-50">
                            <ResidentSummary resident={spouse} />
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                onClick={() => {
                                    setSpouse(null);
                                    form.setData('spouse_id', '');
                                }}
                            >
                                Choose someone else
                            </Button>
                        </div>
                    )}
                    {spouse && (
                        <Field
                            id="marriage-surname"
                            label="Who now uses the other's surname?"
                            hint="The old surname is kept as a maiden name."
                            error={form.errors.takes_surname_id}
                        >
                            <ChoiceSelect
                                id="marriage-surname"
                                value={form.data.takes_surname_id}
                                placeholder="No one"
                                values={[
                                    { value: 'none', label: 'No one' },
                                    {
                                        value: String(resident.id),
                                        label: resident.full_name,
                                    },
                                    {
                                        value: String(spouse.id),
                                        label: spouse.full_name,
                                    },
                                ]}
                                onChange={(value) =>
                                    form.setData('takes_surname_id', value)
                                }
                            />
                        </Field>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || spouse === null}
                        >
                            <HeartHandshake />
                            Record marriage
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ReplaceCardDialog({
    resident,
    open,
    onClose,
}: {
    resident: Props['resident'];
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(ResidentManagementController.replaceCard.url(resident.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Replace the QR card
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        {resident.full_name} gets a new QR code. The old code
                        stops working immediately and any scan of it is counted.
                        The resident ID stays the same. Print the new card for
                        them afterwards.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <Field
                        id="card-reason"
                        label="What happened to the card?"
                        hint="If it was lost or stolen, the resident ID can no longer be typed in place of the QR code, so nobody can use it by reading the number."
                        error={form.errors.reason}
                    >
                        <ChoiceSelect
                            id="card-reason"
                            value={form.data.reason}
                            placeholder="Choose"
                            values={['Lost', 'Stolen', 'Damaged'].map(
                                (reason) => ({ value: reason, label: reason }),
                            )}
                            onChange={(value) => form.setData('reason', value)}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing || !form.data.reason}
                        >
                            <QrCode />
                            Replace card
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EndMarriageDialog({
    resident,
    spouseName,
    maidenNameChoices,
    open,
    onClose,
}: {
    resident: Props['resident'];
    spouseName: string;
    maidenNameChoices: Props['maidenNameChoices'];
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm<{
        civil_status: string;
        restore_maiden_name_ids: number[];
    }>({ civil_status: '', restore_maiden_name_ids: [] });

    function toggle(id: number, checked: boolean) {
        form.setData((current) => ({
            ...current,
            restore_maiden_name_ids: checked
                ? [...current.restore_maiden_name_ids, id]
                : current.restore_maiden_name_ids.filter((item) => item !== id),
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(ResidentManagementController.endMarriage.url(resident.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        End a marriage
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        {resident.full_name} and {spouseName} will no longer be
                        recorded as spouses, and both can marry again. Their
                        children stay linked to both of them, and their
                        household does not change.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <Field
                        id="end-marriage-status"
                        label="How did it end?"
                        hint="Separated: legally separated. Annulled: the marriage was declared void."
                        error={form.errors.civil_status}
                    >
                        <ChoiceSelect
                            id="end-marriage-status"
                            value={form.data.civil_status}
                            placeholder="Choose"
                            values={[
                                { value: 'Separated', label: 'Separated' },
                                { value: 'Annulled', label: 'Annulled' },
                            ]}
                            onChange={(value) =>
                                form.setData('civil_status', value)
                            }
                        />
                    </Field>
                    {maidenNameChoices.length > 0 && (
                        <fieldset className="grid gap-2">
                            <legend className="text-base font-semibold">
                                Go back to the maiden name
                            </legend>
                            {maidenNameChoices.map((person) => (
                                <label
                                    key={person.id}
                                    className="flex min-h-12 items-center gap-3 rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border"
                                >
                                    <Checkbox
                                        checked={form.data.restore_maiden_name_ids.includes(
                                            person.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggle(person.id, checked === true)
                                        }
                                    />
                                    {person.full_name} uses the surname again:{' '}
                                    {person.maiden_name}
                                </label>
                            ))}
                            <InputErrorLine
                                message={form.errors.restore_maiden_name_ids}
                            />
                        </fieldset>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing || !form.data.civil_status
                            }
                        >
                            <Unlink />
                            End marriage
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MoveDialog({
    resident,
    household,
    roleChoices,
    open,
    onClose,
}: {
    resident: Props['resident'];
    household: Props['household'];
    roleChoices: string[];
    open: boolean;
    onClose: () => void;
}) {
    const [target, setTarget] = useState<HouseholdOption | null>(null);
    const form = useForm({
        mode: 'new',
        household_id: '',
        street_address: '',
        household_role: '',
        is_head: false,
        new_head_id: '',
    });
    const livingMembers =
        household?.members.filter((m) => !m.is_deceased) ?? [];
    const mustChooseHead = resident.is_head && livingMembers.length > 0;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            household_id:
                data.mode === 'existing' && data.household_id
                    ? Number(data.household_id)
                    : null,
            street_address: data.mode === 'new' ? data.street_address : null,
            household_role: data.is_head ? null : data.household_role || null,
            is_head: data.is_head,
            new_head_id:
                mustChooseHead && data.new_head_id
                    ? Number(data.new_head_id)
                    : null,
        }));
        form.post(ResidentManagementController.household.url(resident.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setTarget(null);
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Move to another household
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        Relief already claimed stays with the household that
                        claimed it. Only claims from now on count for the new
                        household.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {form.data.mode === 'new' && form.errors.household_id && (
                        <p
                            role="alert"
                            className="rounded-xl border-2 border-red-300 bg-red-50 p-3 text-base font-semibold text-red-900"
                        >
                            {form.errors.household_id}
                        </p>
                    )}
                    <Field id="move-mode" label="Move to">
                        <ChoiceSelect
                            id="move-mode"
                            value={form.data.mode}
                            placeholder="Choose"
                            values={[
                                { value: 'new', label: 'A new household' },
                                {
                                    value: 'existing',
                                    label: 'A household already on record',
                                },
                            ]}
                            onChange={(mode) => {
                                form.setData('mode', mode);
                                setTarget(null);
                                form.setData('household_id', '');
                            }}
                        />
                    </Field>
                    {form.data.mode === 'new' ? (
                        <Field
                            id="move-address"
                            label="Address of the new household"
                            error={form.errors.street_address}
                        >
                            <Input
                                id="move-address"
                                value={form.data.street_address}
                                onChange={(event) =>
                                    form.setData(
                                        'street_address',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    ) : target === null ? (
                        <SearchSelect<HouseholdOption>
                            id="move-household"
                            label="Which household?"
                            placeholder="Search by code, street or head"
                            endpoint={ResidentManagementController.households.url()}
                            error={form.errors.household_id}
                            renderOption={(option) => (
                                <>
                                    <span className="text-lg font-bold">
                                        {option.household_code}
                                    </span>
                                    <span className="text-base text-muted-foreground">
                                        {option.street_address}
                                        {option.head_name
                                            ? ` · Head: ${option.head_name}`
                                            : ''}
                                    </span>
                                </>
                            )}
                            onSelect={(option) => {
                                setTarget(option);
                                form.setData('household_id', String(option.id));
                            }}
                        />
                    ) : (
                        <div className="grid gap-1 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-50">
                            <span className="text-lg font-bold">
                                {target.household_code}
                            </span>
                            <span>{target.street_address}</span>
                            <Button
                                type="button"
                                variant="outline"
                                className="mt-1 w-fit"
                                onClick={() => {
                                    setTarget(null);
                                    form.setData('household_id', '');
                                }}
                            >
                                Choose another
                            </Button>
                        </div>
                    )}
                    <label className="flex min-h-12 items-center gap-3 rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border">
                        <Checkbox
                            checked={form.data.is_head}
                            onCheckedChange={(checked) =>
                                form.setData('is_head', checked === true)
                            }
                        />
                        This person is the head of that household
                    </label>
                    <InputErrorLine message={form.errors.is_head} />
                    {!form.data.is_head && (
                        <Field
                            id="move-role"
                            label="Relationship to the household head"
                            error={form.errors.household_role}
                        >
                            <ChoiceSelect
                                id="move-role"
                                value={form.data.household_role}
                                placeholder="Choose relationship"
                                values={roleChoices.map((role) => ({
                                    value: role,
                                    label: role,
                                }))}
                                onChange={(role) =>
                                    form.setData('household_role', role)
                                }
                            />
                        </Field>
                    )}
                    {mustChooseHead && household && (
                        <Field
                            id="move-new-head"
                            label={`Who becomes the head of ${household.household_code}?`}
                            hint="This person heads the household they are leaving."
                            error={form.errors.new_head_id}
                        >
                            <ChoiceSelect
                                id="move-new-head"
                                value={form.data.new_head_id}
                                placeholder="Choose the new head"
                                values={livingMembers.map((member) => ({
                                    value: String(member.id),
                                    label: member.full_name,
                                }))}
                                onChange={(id) =>
                                    form.setData('new_head_id', id)
                                }
                            />
                        </Field>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Home />
                            Move household
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeceasedDialog({
    resident,
    household,
    open,
    onClose,
}: {
    resident: Props['resident'];
    household: Props['household'];
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({ deceased_at: '', new_head_id: '' });
    const livingMembers =
        household?.members.filter((m) => !m.is_deceased) ?? [];
    const mustChooseHead = resident.is_head && livingMembers.length > 0;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            deceased_at: data.deceased_at || null,
            new_head_id:
                mustChooseHead && data.new_head_id
                    ? Number(data.new_head_id)
                    : null,
        }));
        form.post(ResidentManagementController.deceased.url(resident.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Record a death
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        {resident.full_name} will no longer be able to claim
                        relief or be counted as eligible. Relief already claimed
                        stays on record, and a living spouse is marked Widowed.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <Field
                        id="deceased-at"
                        label="Date of death (if known)"
                        error={form.errors.deceased_at}
                    >
                        <Input
                            id="deceased-at"
                            type="date"
                            max={new Date().toISOString().slice(0, 10)}
                            value={form.data.deceased_at}
                            onChange={(event) =>
                                form.setData('deceased_at', event.target.value)
                            }
                        />
                    </Field>
                    {mustChooseHead && household && (
                        <Field
                            id="deceased-new-head"
                            label={`Who becomes the head of ${household.household_code}?`}
                            hint="This person heads their household."
                            error={form.errors.new_head_id}
                        >
                            <ChoiceSelect
                                id="deceased-new-head"
                                value={form.data.new_head_id}
                                placeholder="Choose the new head"
                                values={livingMembers.map((member) => ({
                                    value: String(member.id),
                                    label: member.full_name,
                                }))}
                                onChange={(id) =>
                                    form.setData('new_head_id', id)
                                }
                            />
                        </Field>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            <HeartCrack />
                            Record death
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

function CorrectionDialog({
    resident,
    open,
    onClose,
}: {
    resident: Props['resident'];
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        first_name: resident.first_name,
        middle_name: resident.middle_name ?? '',
        last_name: resident.last_name,
        suffix: resident.suffix ?? 'none',
        maiden_name: resident.maiden_name ?? '',
        birthdate: resident.birthdate,
        gender: resident.gender,
    });

    function text(
        field: 'first_name' | 'middle_name' | 'last_name' | 'maiden_name',
        label: string,
    ) {
        return (
            <Field
                id={`correct-${field}`}
                label={label}
                error={form.errors[field]}
            >
                <Input
                    id={`correct-${field}`}
                    value={form.data[field]}
                    onChange={(event) =>
                        form.setData(field, event.target.value)
                    }
                />
            </Field>
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            suffix: data.suffix === 'none' ? null : data.suffix,
        }));
        form.patch(ResidentManagementController.correct.url(resident.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Correct registration
                    </DialogTitle>
                    <DialogDescription className="text-base">
                        Fix a mistake in the details. The resident keeps their
                        ID, QR code and family links, and the change is kept in
                        their history.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {text('first_name', 'First name')}
                    {text('middle_name', 'Middle name')}
                    {text('last_name', 'Last name')}
                    <Field
                        id="correct-suffix"
                        label="Suffix"
                        error={form.errors.suffix}
                    >
                        <ChoiceSelect
                            id="correct-suffix"
                            value={form.data.suffix}
                            placeholder="None"
                            values={[
                                { value: 'none', label: 'None' },
                                ...SUFFIXES.map((s) => ({
                                    value: s,
                                    label: s,
                                })),
                            ]}
                            onChange={(value) => form.setData('suffix', value)}
                        />
                    </Field>
                    {text('maiden_name', 'Maiden name')}
                    <Field
                        id="correct-birthdate"
                        label="Birthdate"
                        error={form.errors.birthdate}
                    >
                        <Input
                            id="correct-birthdate"
                            type="date"
                            max={new Date().toISOString().slice(0, 10)}
                            value={form.data.birthdate}
                            onChange={(event) =>
                                form.setData('birthdate', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="correct-gender"
                        label="Gender"
                        error={form.errors.gender}
                    >
                        <ChoiceSelect
                            id="correct-gender"
                            value={form.data.gender}
                            placeholder="Choose"
                            values={[
                                { value: 'Male', label: 'Male' },
                                { value: 'Female', label: 'Female' },
                            ]}
                            onChange={(value) => form.setData('gender', value)}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Pencil />
                            Save correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function InputErrorLine({ message }: { message?: string }) {
    return message ? (
        <p className="text-base font-semibold text-red-700">{message}</p>
    ) : null;
}

export default function ManageResident({
    resident,
    family,
    household,
    openEvents,
    canMarry,
    canReplaceCard,
    retiredCards,
    canEndMarriage,
    maidenNameChoices,
    roleChoices,
    history,
}: Props) {
    const [marrying, setMarrying] = useState(false);
    const [endingMarriage, setEndingMarriage] = useState(false);
    const [replacingCard, setReplacingCard] = useState(false);
    const [moving, setMoving] = useState(false);
    const [correcting, setCorrecting] = useState(false);
    const [dying, setDying] = useState(false);
    const isArchived = resident.status === 'Archived';

    function markLiving() {
        if (
            window.confirm(
                `Record ${resident.full_name} as living again? Use this only if the death was entered by mistake.`,
            )
        ) {
            router.post(
                ResidentManagementController.living.url(resident.id),
                {},
                { preserveScroll: true },
            );
        }
    }

    return (
        <>
            <Head title={resident.full_name} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="grid gap-2">
                    <Button asChild variant="ghost" className="w-fit">
                        <Link href={residentsIndex()}>
                            <ArrowLeft />
                            Residents
                        </Link>
                    </Button>
                    <h1 className="flex flex-wrap items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        {resident.full_name}
                        <span className="rounded-md bg-blue-600 px-2 py-0.5 text-sm font-bold text-white">
                            {resident.status}
                        </span>
                        {resident.is_deceased && (
                            <span className="rounded-md bg-slate-700 px-2 py-0.5 text-sm font-bold text-white">
                                Deceased
                                {resident.deceased_at
                                    ? ` ${formatDate(resident.deceased_at)}`
                                    : ''}
                            </span>
                        )}
                    </h1>
                    <p className="text-lg text-muted-foreground">
                        ID {resident.system_id_no} · {resident.gender} · born{' '}
                        {formatDate(resident.birthdate)} ·{' '}
                        {resident.civil_status ?? 'No civil status'}
                        {resident.barangay_name
                            ? ` · Barangay ${resident.barangay_name}`
                            : ''}
                    </p>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Button
                        disabled={!canMarry}
                        onClick={() => setMarrying(true)}
                    >
                        <HeartHandshake />
                        Record a marriage
                    </Button>
                    {canEndMarriage && (
                        <Button
                            variant="outline"
                            onClick={() => setEndingMarriage(true)}
                        >
                            <Unlink />
                            End a marriage
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        disabled={
                            resident.status === 'Listed' ||
                            openEvents.length > 0
                        }
                        onClick={() => setMoving(true)}
                    >
                        <Home />
                        Move to another household
                    </Button>
                    <Button
                        variant="outline"
                        disabled={isArchived}
                        onClick={() => setCorrecting(true)}
                    >
                        <Pencil />
                        Correct registration
                    </Button>
                    {canReplaceCard && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setReplacingCard(true)}
                            >
                                <QrCode />
                                Replace QR card
                            </Button>
                            <Button asChild variant="outline">
                                <a
                                    href={ResidentCardController.resident.url(
                                        resident.id,
                                    )}
                                >
                                    <Printer />
                                    Print QR card
                                </a>
                            </Button>
                        </>
                    )}
                    {resident.is_deceased ? (
                        <Button
                            variant="outline"
                            disabled={isArchived}
                            onClick={markLiving}
                        >
                            Mark as living again
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            disabled={isArchived}
                            onClick={() => setDying(true)}
                        >
                            <HeartCrack />
                            Record a death
                        </Button>
                    )}
                </div>
                {openEvents.length > 0 && (
                    <p
                        role="status"
                        className="flex gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 p-3 text-base text-amber-950"
                    >
                        <TriangleAlert className="mt-0.5 size-5 shrink-0" />
                        <span>
                            Households cannot be changed while a relief event is
                            open (<strong>{openEvents.join(', ')}</strong>).
                            This stops a household from being split to claim
                            more than once. Try again after the event closes.
                        </span>
                    </p>
                )}
                {!canMarry && (
                    <p className="text-base text-muted-foreground">
                        A marriage can only be recorded for an approved, living
                        adult who has no spouse on record. To marry again, end
                        the current marriage first.
                    </p>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card title="Family" icon={Users}>
                        <p>Father: {family.father ?? 'Not linked'}</p>
                        <p>Mother: {family.mother ?? 'Not linked'}</p>
                        <p>
                            Spouse:{' '}
                            {family.spouse ? (
                                <Link
                                    className="text-blue-700 underline-offset-2 hover:underline dark:text-sky-300"
                                    href={ResidentManagementController.show.url(
                                        family.spouse.id,
                                    )}
                                >
                                    {family.spouse.full_name}
                                </Link>
                            ) : (
                                'None'
                            )}
                        </p>
                        <p>
                            Children:{' '}
                            {family.children.length === 0
                                ? 'None on record'
                                : family.children
                                      .map((child) => child.full_name)
                                      .join(', ')}
                        </p>
                    </Card>
                    <Card title="Household" icon={Home}>
                        {household ? (
                            <>
                                <p className="font-semibold">
                                    {household.household_code}
                                    {household.role
                                        ? ` (${household.role})`
                                        : ''}
                                </p>
                                <p>{household.street_address}</p>
                                <p>Head: {household.head_name ?? 'None'}</p>
                                <p>
                                    Also living there:{' '}
                                    {household.members.length === 0
                                        ? 'No one else'
                                        : household.members
                                              .map((member) =>
                                                  member.is_deceased
                                                      ? `${member.full_name} (deceased)`
                                                      : member.full_name,
                                              )
                                              .join(', ')}
                                </p>
                                <Link
                                    href={familyTree(household.id)}
                                    className="mt-1 flex items-center gap-1 text-blue-700 underline-offset-2 hover:underline dark:text-sky-300"
                                >
                                    <Network className="size-4" />
                                    View family tree
                                </Link>
                            </>
                        ) : (
                            <p>Not in a household yet.</p>
                        )}
                    </Card>
                </div>

                {retiredCards.length > 0 && (
                    <Card title="Replaced QR cards" icon={QrCode}>
                        <ul className="grid gap-2">
                            {retiredCards.map((card) => (
                                <li
                                    key={card.id}
                                    className={`rounded-lg border-2 p-3 ${
                                        card.attempts > 0
                                            ? 'border-amber-300 bg-amber-50 text-amber-950'
                                            : 'border-slate-200 dark:border-border'
                                    }`}
                                >
                                    <p className="font-semibold">
                                        {card.reason}
                                        {card.replaced_at
                                            ? ` · replaced ${formatDate(card.replaced_at)}`
                                            : ''}{' '}
                                        by {card.replaced_by}
                                    </p>
                                    <p className="text-sm">
                                        {card.attempts === 0
                                            ? 'The old code has not been scanned since.'
                                            : `The old code was scanned ${card.attempts} ${card.attempts === 1 ? 'time' : 'times'} since${
                                                  card.last_attempt_at
                                                      ? `, last on ${formatDate(card.last_attempt_at)}`
                                                      : ''
                                              }. Each try was refused.`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <Card title="Changes made after approval" icon={History}>
                    {history.length === 0 ? (
                        <p className="text-muted-foreground">No changes yet.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {history.map((change) => (
                                <li
                                    key={change.id}
                                    className="rounded-lg border-2 border-slate-200 p-3 dark:border-border"
                                >
                                    <p className="font-semibold">
                                        {change.summary}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {change.changed_by}
                                        {change.changed_at
                                            ? ` · ${formatDate(change.changed_at)}`
                                            : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <MarriageDialog
                resident={resident}
                open={marrying}
                onClose={() => setMarrying(false)}
            />
            <ReplaceCardDialog
                key={`card-${replacingCard}`}
                resident={resident}
                open={replacingCard}
                onClose={() => setReplacingCard(false)}
            />
            {family.spouse && (
                <EndMarriageDialog
                    key={`end-${endingMarriage}`}
                    resident={resident}
                    spouseName={family.spouse.full_name}
                    maidenNameChoices={maidenNameChoices}
                    open={endingMarriage}
                    onClose={() => setEndingMarriage(false)}
                />
            )}
            <CorrectionDialog
                key={`correct-${correcting}`}
                resident={resident}
                open={correcting}
                onClose={() => setCorrecting(false)}
            />
            <DeceasedDialog
                resident={resident}
                household={household}
                open={dying}
                onClose={() => setDying(false)}
            />
            <MoveDialog
                resident={resident}
                household={household}
                roleChoices={roleChoices}
                open={moving}
                onClose={() => setMoving(false)}
            />
        </>
    );
}

ManageResident.layout = {
    breadcrumbs: [{ title: 'Residents', href: residentsIndex() }],
};
