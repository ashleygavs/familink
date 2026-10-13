import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Home,
    Unlink,
} from 'lucide-react';
import { useState } from 'react';
import ApprovalController from '@/actions/App/Http/Controllers/ApprovalController';
import { SuggestedLinks } from '@/components/suggested-links';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDate } from '@/lib/format';
import { approvals } from '@/routes/admin';
import type { Paginated, PendingResident } from '@/types';

type Unlinking = {
    resident: PendingResident;
    kind: 'father' | 'mother' | 'spouse' | 'child' | 'relative';
    id?: number;
    name: string;
};

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="font-semibold">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function LinkedPerson({
    name,
    onUnlink,
}: {
    name: string;
    onUnlink: () => void;
}) {
    return (
        <span className="flex flex-wrap items-center gap-2">
            {name}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                onClick={onUnlink}
            >
                <Unlink />
                Unlink
            </Button>
        </span>
    );
}

export default function Approvals({
    pending,
}: {
    pending: Paginated<PendingResident>;
}) {
    const [archiving, setArchiving] = useState<PendingResident | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [unlinking, setUnlinking] = useState<Unlinking | null>(null);

    function decide(url: string, resident: PendingResident) {
        setBusyId(resident.id);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusyId(null);
                    setArchiving(null);
                },
            },
        );
    }

    function unlink(target: Unlinking) {
        setBusyId(target.resident.id);
        router.post(
            ApprovalController.unlink.url(target.resident.id),
            { kind: target.kind, id: target.id },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusyId(null);
                    setUnlinking(null);
                },
            },
        );
    }

    return (
        <>
            <Head title="Approvals" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-3xl font-bold text-blue-900 dark:text-white">
                        Resident Approvals
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Check each registration carefully. Only approved
                        residents can receive relief.
                    </p>
                </div>

                {pending.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-10 text-center text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-50">
                        <CheckCircle2 className="size-14 text-emerald-600" />
                        <p className="text-2xl font-bold">All caught up!</p>
                        <p className="text-lg">
                            No residents are waiting for approval.
                        </p>
                    </div>
                ) : (
                    <>
                        <p className="flex items-center gap-2 text-lg font-semibold">
                            <Clock className="size-6 text-blue-600" />
                            {pending.total} waiting for approval
                        </p>
                        <ul className="grid gap-4">
                            {pending.data.map((resident) => (
                                <li
                                    key={resident.id}
                                    className="grid gap-4 rounded-xl border-2 border-blue-200 bg-card p-5 dark:border-sidebar-border"
                                >
                                    <div>
                                        <h2 className="text-2xl font-bold">
                                            {resident.full_name}
                                        </h2>
                                        <p className="text-base text-muted-foreground">
                                            ID {resident.system_id_no} ·
                                            Barangay {resident.barangay_name}
                                        </p>
                                    </div>
                                    <dl className="grid gap-x-6 gap-y-2 text-base sm:grid-cols-2">
                                        <Detail label="Birthdate">
                                            {formatDate(resident.birthdate)}
                                        </Detail>
                                        <Detail label="Gender · Civil status">
                                            {resident.gender} ·{' '}
                                            {resident.civil_status}
                                        </Detail>
                                        <Detail label="Household">
                                            <span className="inline-flex items-center gap-1">
                                                <Home className="size-4 text-blue-600" />
                                                {resident.household_code}
                                                {resident.household_role
                                                    ? ` (${resident.household_role})`
                                                    : ''}
                                            </span>
                                        </Detail>
                                        <Detail label="Father">
                                            {resident.father_name ? (
                                                <LinkedPerson
                                                    name={resident.father_name}
                                                    onUnlink={() =>
                                                        setUnlinking({
                                                            resident,
                                                            kind: 'father',
                                                            name:
                                                                resident.father_name ??
                                                                '',
                                                        })
                                                    }
                                                />
                                            ) : (
                                                'Not linked'
                                            )}
                                        </Detail>
                                        <Detail label="Mother">
                                            {resident.mother_name ? (
                                                <LinkedPerson
                                                    name={resident.mother_name}
                                                    onUnlink={() =>
                                                        setUnlinking({
                                                            resident,
                                                            kind: 'mother',
                                                            name:
                                                                resident.mother_name ??
                                                                '',
                                                        })
                                                    }
                                                />
                                            ) : (
                                                'Not linked'
                                            )}
                                        </Detail>
                                        {resident.spouse_name && (
                                            <Detail label="Spouse">
                                                <LinkedPerson
                                                    name={resident.spouse_name}
                                                    onUnlink={() =>
                                                        setUnlinking({
                                                            resident,
                                                            kind: 'spouse',
                                                            name:
                                                                resident.spouse_name ??
                                                                '',
                                                        })
                                                    }
                                                />
                                            </Detail>
                                        )}
                                        {resident.children.length > 0 && (
                                            <Detail label="Children">
                                                {resident.children.map(
                                                    (child) => (
                                                        <LinkedPerson
                                                            key={child.id}
                                                            name={
                                                                child.full_name
                                                            }
                                                            onUnlink={() =>
                                                                setUnlinking({
                                                                    resident,
                                                                    kind: 'child',
                                                                    id: child.id,
                                                                    name: child.full_name,
                                                                })
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </Detail>
                                        )}
                                        {resident.declared_relatives.length >
                                            0 && (
                                            <Detail label="Relatives named">
                                                {resident.declared_relatives.map(
                                                    (relative) => (
                                                        <LinkedPerson
                                                            key={relative.id}
                                                            name={`${relative.relation}: ${relative.name}`}
                                                            onUnlink={() =>
                                                                setUnlinking({
                                                                    resident,
                                                                    kind: 'relative',
                                                                    id: relative.id,
                                                                    name: relative.name,
                                                                })
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </Detail>
                                        )}
                                    </dl>
                                    <SuggestedLinks resident={resident} />
                                    <div className="flex flex-wrap gap-3">
                                        <Button
                                            className="bg-emerald-700 hover:bg-emerald-800"
                                            disabled={busyId === resident.id}
                                            onClick={() =>
                                                decide(
                                                    ApprovalController.approve.url(
                                                        resident.id,
                                                    ),
                                                    resident,
                                                )
                                            }
                                        >
                                            <CheckCircle2 />
                                            Approve
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800"
                                            disabled={busyId === resident.id}
                                            onClick={() =>
                                                setArchiving(resident)
                                            }
                                        >
                                            <Archive />
                                            Archive
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {pending.last_page > 1 && (
                            <nav
                                className="flex items-center justify-between gap-3"
                                aria-label="Pagination"
                            >
                                {pending.prev_page_url ? (
                                    <Button asChild variant="outline">
                                        <Link href={pending.prev_page_url}>
                                            <ChevronLeft />
                                            Previous
                                        </Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                <span className="text-base font-medium">
                                    Page {pending.current_page} of{' '}
                                    {pending.last_page}
                                </span>
                                {pending.next_page_url ? (
                                    <Button asChild variant="outline">
                                        <Link href={pending.next_page_url}>
                                            Next
                                            <ChevronRight />
                                        </Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                            </nav>
                        )}
                    </>
                )}
            </div>

            <Dialog
                open={unlinking !== null}
                onOpenChange={(open) => !open && setUnlinking(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Remove this family link?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {unlinking?.name} will no longer be linked to{' '}
                            {unlinking?.resident.full_name}. Neither person is
                            deleted, and you can link them again later.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setUnlinking(null)}
                        >
                            Keep link
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busyId !== null}
                            onClick={() => unlinking && unlink(unlinking)}
                        >
                            <Unlink />
                            Yes, unlink
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Archive this registration?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {archiving?.full_name} will be removed from the
                            waiting list and will not be able to receive relief.
                            Use this for mistakes or duplicates.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setArchiving(null)}
                        >
                            Keep waiting
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busyId !== null}
                            onClick={() =>
                                archiving &&
                                decide(
                                    ApprovalController.archive.url(
                                        archiving.id,
                                    ),
                                    archiving,
                                )
                            }
                        >
                            <Archive />
                            Yes, archive
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Approvals.layout = {
    breadcrumbs: [
        {
            title: 'Approvals',
            href: approvals(),
        },
    ],
};
