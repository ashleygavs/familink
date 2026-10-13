import { Head, useForm } from '@inertiajs/react';
import { Building2, MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import BarangayController from '@/actions/App/Http/Controllers/BarangayController';
import MunicipalityController from '@/actions/App/Http/Controllers/MunicipalityController';
import InputError from '@/components/input-error';
import { Field } from '@/components/registration/field';
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
import { municipalities as municipalitiesRoute } from '@/routes/admin';

type Barangay = {
    id: number;
    name: string;
    code: string;
    residents_count: number;
    households_count: number;
};

type Municipality = {
    id: number;
    name: string;
    province: string;
    barangays: Barangay[];
};

type Editing =
    | { kind: 'municipality'; record: Municipality | null }
    | { kind: 'barangay'; record: Barangay | null; municipalityId: number };

type Deleting =
    | { kind: 'municipality'; record: Municipality }
    | { kind: 'barangay'; record: Barangay };

function MunicipalityForm({
    record,
    onClose,
}: {
    record: Municipality | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: record?.name ?? '',
        province: record?.province ?? '',
    });
    const options = { preserveScroll: true, onSuccess: onClose };

    function submit(event: FormEvent) {
        event.preventDefault();

        if (record) {
            form.put(MunicipalityController.update.url(record.id), options);
        } else {
            form.post(MunicipalityController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Field id="m-name" label="Municipality" error={form.errors.name}>
                <Input
                    id="m-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field
                id="m-province"
                label="Province"
                error={form.errors.province}
            >
                <Input
                    id="m-province"
                    value={form.data.province}
                    onChange={(event) =>
                        form.setData('province', event.target.value)
                    }
                />
            </Field>
            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
            </DialogFooter>
        </form>
    );
}

function BarangayForm({
    record,
    municipalityId,
    onClose,
}: {
    record: Barangay | null;
    municipalityId: number;
    onClose: () => void;
}) {
    const form = useForm({
        municipality_id: municipalityId,
        name: record?.name ?? '',
        code: record?.code ?? '',
    });
    const options = { preserveScroll: true, onSuccess: onClose };

    function submit(event: FormEvent) {
        event.preventDefault();

        if (record) {
            form.put(BarangayController.update.url(record.id), options);
        } else {
            form.post(BarangayController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Field id="b-name" label="Barangay name" error={form.errors.name}>
                <Input
                    id="b-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field
                id="b-code"
                label="Short code"
                hint="Used in resident and household IDs, for example SJ."
                error={form.errors.code}
            >
                <Input
                    id="b-code"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value.toUpperCase())
                    }
                />
            </Field>
            <InputError message={form.errors.municipality_id} />
            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function Municipalities({
    municipalities,
}: {
    municipalities: Municipality[];
}) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [deleting, setDeleting] = useState<Deleting | null>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const deletion = useForm({});

    function confirmDelete() {
        if (!deleting) {
            return;
        }

        const url =
            deleting.kind === 'municipality'
                ? MunicipalityController.destroy.url(deleting.record.id)
                : BarangayController.destroy.url(deleting.record.id);

        deletion.delete(url, {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
            onError: (errors) =>
                setDeleteError(Object.values(errors)[0] ?? 'Could not delete.'),
        });
    }

    return (
        <>
            <Head title="Municipalities" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                            <Building2 className="size-8 text-blue-600" />
                            Municipalities and Barangays
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Admins are assigned to a municipality and the
                            barangays under it.
                        </p>
                    </div>
                    <Button
                        onClick={() =>
                            setEditing({ kind: 'municipality', record: null })
                        }
                    >
                        <Plus />
                        Add municipality
                    </Button>
                </div>

                {municipalities.map((municipality) => (
                    <section
                        key={municipality.id}
                        className="grid gap-3 rounded-xl border-2 border-blue-200 bg-card p-5 dark:border-sidebar-border"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-2xl font-bold">
                                {municipality.name}
                                <span className="ml-2 text-base font-normal text-muted-foreground">
                                    {municipality.province}
                                </span>
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setEditing({
                                            kind: 'barangay',
                                            record: null,
                                            municipalityId: municipality.id,
                                        })
                                    }
                                >
                                    <Plus />
                                    Add barangay
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setEditing({
                                            kind: 'municipality',
                                            record: municipality,
                                        })
                                    }
                                >
                                    <Pencil />
                                    Edit
                                </Button>
                                <Button
                                    variant="outline"
                                    className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800"
                                    onClick={() => {
                                        setDeleteError(null);
                                        setDeleting({
                                            kind: 'municipality',
                                            record: municipality,
                                        });
                                    }}
                                >
                                    <Trash2 />
                                    Delete
                                </Button>
                            </div>
                        </div>

                        {municipality.barangays.length === 0 ? (
                            <p className="text-base text-muted-foreground">
                                No barangays yet.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {municipality.barangays.map((barangay) => (
                                    <li
                                        key={barangay.id}
                                        className="flex flex-wrap items-center gap-3 rounded-lg border-2 border-slate-200 p-3 dark:border-border"
                                    >
                                        <MapPin className="size-5 text-blue-600" />
                                        <div className="min-w-0 flex-1">
                                            <span className="text-lg font-semibold">
                                                {barangay.name}
                                            </span>
                                            <span className="ml-2 text-base text-muted-foreground">
                                                {barangay.code} ·{' '}
                                                {barangay.residents_count}{' '}
                                                residents ·{' '}
                                                {barangay.households_count}{' '}
                                                households
                                            </span>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                setEditing({
                                                    kind: 'barangay',
                                                    record: barangay,
                                                    municipalityId:
                                                        municipality.id,
                                                })
                                            }
                                        >
                                            <Pencil />
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            className="text-red-700 hover:bg-red-50 hover:text-red-800"
                                            onClick={() => {
                                                setDeleteError(null);
                                                setDeleting({
                                                    kind: 'barangay',
                                                    record: barangay,
                                                });
                                            }}
                                        >
                                            <Trash2 />
                                            Delete
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => !isOpen && setEditing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            {editing?.kind === 'municipality'
                                ? 'Municipality'
                                : 'Barangay'}
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {editing?.record
                                ? 'Update the details.'
                                : 'Add a new one.'}
                        </DialogDescription>
                    </DialogHeader>
                    {editing?.kind === 'municipality' && (
                        <MunicipalityForm
                            key={editing.record?.id ?? 'new'}
                            record={editing.record}
                            onClose={() => setEditing(null)}
                        />
                    )}
                    {editing?.kind === 'barangay' && (
                        <BarangayForm
                            key={editing.record?.id ?? 'new'}
                            record={editing.record}
                            municipalityId={editing.municipalityId}
                            onClose={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(isOpen) => !isOpen && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Delete {deleting?.record.name}?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            One that still has barangays, residents, households
                            or staff cannot be deleted.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={deleteError ?? undefined} />
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Keep it
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={deletion.processing}
                            onClick={confirmDelete}
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

Municipalities.layout = {
    breadcrumbs: [
        {
            title: 'Municipalities',
            href: municipalitiesRoute(),
        },
    ],
};
