import { Head, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Lock,
    PackageCheck,
    Pencil,
    Play,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ReliefEventController from '@/actions/App/Http/Controllers/ReliefEventController';
import {
    ChoiceSelect,
    Field,
    asChoices,
} from '@/components/registration/field';
import { Button } from '@/components/ui/button';
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
import { events as eventsRoute } from '@/routes/admin';

type EventStatus = 'Draft' | 'Active' | 'Closed';

type ReliefEvent = {
    id: number;
    title: string;
    municipality_id: number;
    municipality_name: string | null;
    allocation_type: string;
    max_claims_per_household: number | null;
    start_date: string;
    end_date: string;
    status: EventStatus;
    next_statuses: EventStatus[];
    is_open: boolean;
    claims_count: number;
    can_edit: boolean;
    can_delete: boolean;
};

type Props = {
    events: ReliefEvent[];
    municipalities: { id: number; name: string }[];
    allocationTypes: string[];
    canSetLimit: boolean;
};

type FormData = {
    title: string;
    municipality_id: string;
    allocation_type: string;
    max_claims_per_household: string;
    start_date: string;
    end_date: string;
    status: string;
};

const statusClasses: Record<EventStatus, string> = {
    Draft: 'bg-slate-600 text-white',
    Active: 'bg-emerald-600 text-white',
    Closed: 'bg-slate-900 text-white dark:bg-slate-200 dark:text-slate-900',
};

function EventForm({
    editing,
    municipalities,
    allocationTypes,
    canSetLimit,
    onClose,
}: {
    editing: ReliefEvent | null;
    municipalities: Props['municipalities'];
    allocationTypes: string[];
    canSetLimit: boolean;
    onClose: () => void;
}) {
    const form = useForm<FormData>({
        title: editing?.title ?? '',
        municipality_id: (
            editing?.municipality_id ??
            municipalities[0]?.id ??
            ''
        ).toString(),
        allocation_type: editing?.allocation_type ?? allocationTypes[0],
        max_claims_per_household:
            editing?.max_claims_per_household?.toString() ?? '',
        start_date: editing?.start_date ?? '',
        end_date: editing?.end_date ?? '',
        status: editing?.status ?? 'Draft',
    });
    const { data, setData, errors, processing } = form;
    const locked = editing?.status === 'Active';
    const statuses = editing
        ? [editing.status, ...editing.next_statuses]
        : ['Draft', 'Active'];

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) {
            form.put(ReliefEventController.update.url(editing.id), options);
        } else {
            form.post(ReliefEventController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Field id="event-title" label="Event name" error={errors.title}>
                <Input
                    id="event-title"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                />
            </Field>
            {municipalities.length > 1 && (
                <Field
                    id="event-municipality"
                    label="Municipality"
                    error={errors.municipality_id}
                >
                    <ChoiceSelect
                        id="event-municipality"
                        value={data.municipality_id}
                        placeholder="Choose a municipality"
                        values={municipalities.map((municipality) => ({
                            value: municipality.id.toString(),
                            label: municipality.name,
                        }))}
                        onChange={(value) => setData('municipality_id', value)}
                    />
                </Field>
            )}
            <Field
                id="event-type"
                label="Who gets one share"
                hint={
                    locked
                        ? 'This cannot change once the event is active.'
                        : 'Per Household: one claim for the whole household. Per Resident: one claim for each person.'
                }
                error={errors.allocation_type}
            >
                <ChoiceSelect
                    id="event-type"
                    value={data.allocation_type}
                    placeholder="Choose a type"
                    values={asChoices(allocationTypes)}
                    onChange={(value) => setData('allocation_type', value)}
                    className={
                        locked
                            ? 'pointer-events-none w-full opacity-60'
                            : 'w-full'
                    }
                />
            </Field>
            {canSetLimit && data.allocation_type === 'Per Resident' && (
                <Field
                    id="event-limit"
                    label="Most members per household"
                    hint="Leave empty for no limit. After this many members of one household have claimed, the next one is refused."
                    error={errors.max_claims_per_household}
                >
                    <Input
                        id="event-limit"
                        type="number"
                        min={1}
                        max={50}
                        inputMode="numeric"
                        value={data.max_claims_per_household}
                        onChange={(event) =>
                            setData(
                                'max_claims_per_household',
                                event.target.value,
                            )
                        }
                    />
                </Field>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id="event-start"
                    label="Starts"
                    error={errors.start_date}
                >
                    <Input
                        id="event-start"
                        type="date"
                        value={data.start_date}
                        disabled={locked}
                        onChange={(event) =>
                            setData('start_date', event.target.value)
                        }
                    />
                </Field>
                <Field id="event-end" label="Ends" error={errors.end_date}>
                    <Input
                        id="event-end"
                        type="date"
                        value={data.end_date}
                        onChange={(event) =>
                            setData('end_date', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field
                id="event-status"
                label="Status"
                hint="Only an Active event inside its dates can be scanned. A closed event never reopens."
                error={errors.status}
            >
                <ChoiceSelect
                    id="event-status"
                    value={data.status}
                    placeholder="Choose a status"
                    values={asChoices(statuses)}
                    onChange={(value) => setData('status', value)}
                />
            </Field>
            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {editing ? 'Save changes' : 'Create event'}
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function ReliefEvents({
    events,
    municipalities,
    allocationTypes,
    canSetLimit,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ReliefEvent | null>(null);
    const [deleting, setDeleting] = useState<ReliefEvent | null>(null);
    const [closing, setClosing] = useState<ReliefEvent | null>(null);
    const action = useForm({});

    function open(event: ReliefEvent | null) {
        setEditing(event);
        setFormOpen(true);
    }

    function changeStatus(event: ReliefEvent, status: EventStatus) {
        // Reuse the update endpoint with the unchanged details and the new status.
        const form = {
            title: event.title,
            municipality_id: event.municipality_id,
            allocation_type: event.allocation_type,
            max_claims_per_household: event.max_claims_per_household,
            start_date: event.start_date,
            end_date: event.end_date,
            status,
        };

        action.transform(() => form);
        action.put(ReliefEventController.update.url(event.id), {
            preserveScroll: true,
            onFinish: () => setClosing(null),
        });
    }

    return (
        <>
            <Head title="Relief Events" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                            <PackageCheck className="size-8 text-blue-600" />
                            Relief Events
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Encoders can only scan an Active event while today
                            is inside its dates.
                        </p>
                    </div>
                    <Button onClick={() => open(null)}>
                        <Plus />
                        New event
                    </Button>
                </div>

                {errors.event && (
                    <p
                        role="alert"
                        className="rounded-xl border-2 border-red-300 bg-red-50 p-4 text-lg font-semibold text-red-900"
                    >
                        {errors.event}
                    </p>
                )}

                {events.length === 0 ? (
                    <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                        No relief events yet. Create one to start distributing.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {events.map((event) => (
                            <li
                                key={event.id}
                                className="grid gap-3 rounded-xl border-2 border-blue-200 bg-card p-4 dark:border-sidebar-border"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="grid gap-1">
                                        <span className="flex flex-wrap items-center gap-2 text-xl font-bold">
                                            {event.title}
                                            <span
                                                className={`rounded-md px-2 py-0.5 text-sm font-bold ${statusClasses[event.status]}`}
                                            >
                                                {event.status}
                                            </span>
                                            {event.is_open && (
                                                <span className="rounded-md bg-emerald-100 px-2 py-0.5 text-sm font-bold text-emerald-900">
                                                    Open for scanning
                                                </span>
                                            )}
                                        </span>
                                        <span className="flex items-center gap-2 text-base text-muted-foreground">
                                            <CalendarDays className="size-4" />
                                            {formatDate(
                                                event.start_date,
                                            )} to{' '}
                                            {formatDate(event.end_date)} ·{' '}
                                            {event.allocation_type}
                                            {event.max_claims_per_household !==
                                                null &&
                                                event.allocation_type ===
                                                    'Per Resident' &&
                                                ` (up to ${event.max_claims_per_household} per household)`}{' '}
                                            · {event.municipality_name}
                                        </span>
                                        <span className="text-base">
                                            {event.claims_count}{' '}
                                            {event.claims_count === 1
                                                ? 'claim'
                                                : 'claims'}{' '}
                                            recorded
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {event.next_statuses.includes(
                                            'Active',
                                        ) && (
                                            <Button
                                                className="bg-emerald-700 hover:bg-emerald-800"
                                                disabled={action.processing}
                                                onClick={() =>
                                                    changeStatus(
                                                        event,
                                                        'Active',
                                                    )
                                                }
                                            >
                                                <Play />
                                                Activate
                                            </Button>
                                        )}
                                        {event.next_statuses.includes(
                                            'Closed',
                                        ) && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setClosing(event)
                                                }
                                            >
                                                <Lock />
                                                Close
                                            </Button>
                                        )}
                                        {event.can_edit && (
                                            <Button
                                                variant="outline"
                                                onClick={() => open(event)}
                                            >
                                                <Pencil />
                                                Edit
                                            </Button>
                                        )}
                                        {event.can_delete && (
                                            <Button
                                                variant="outline"
                                                className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800"
                                                onClick={() =>
                                                    setDeleting(event)
                                                }
                                            >
                                                <Trash2 />
                                                Delete
                                            </Button>
                                        )}
                                        {event.status === 'Closed' && (
                                            <span className="flex items-center gap-1 text-base text-muted-foreground">
                                                <CheckCircle2 className="size-5" />
                                                Finished
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            {editing ? 'Edit event' : 'New event'}
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {editing?.status === 'Active'
                                ? 'This event is active, so only its name, end date and status can change.'
                                : 'Set who gets one share and when it can be claimed.'}
                        </DialogDescription>
                    </DialogHeader>
                    {formOpen && (
                        <EventForm
                            key={editing?.id ?? 'new'}
                            editing={editing}
                            municipalities={municipalities}
                            allocationTypes={allocationTypes}
                            canSetLimit={canSetLimit}
                            onClose={() => setFormOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={closing !== null}
                onOpenChange={(isOpen) => !isOpen && setClosing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Close this event?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {closing?.title} will stop accepting scans. Its{' '}
                            {closing?.claims_count} recorded claims are kept. A
                            closed event cannot be reopened.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setClosing(null)}
                        >
                            Keep it open
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={action.processing}
                            onClick={() =>
                                closing && changeStatus(closing, 'Closed')
                            }
                        >
                            <Lock />
                            Yes, close it
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(isOpen) => !isOpen && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Delete this draft?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {deleting?.title} has no claims and will be removed.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Keep it
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={action.processing}
                            onClick={() => {
                                if (!deleting) {
                                    return;
                                }

                                action.transform((current) => current);
                                action.delete(
                                    ReliefEventController.destroy.url(
                                        deleting.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setDeleting(null),
                                    },
                                );
                            }}
                        >
                            <Trash2 />
                            Yes, delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ReliefEvents.layout = {
    breadcrumbs: [
        {
            title: 'Relief Events',
            href: eventsRoute(),
        },
    ],
};
