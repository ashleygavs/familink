import { Head, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2, UserCheck, UserCog, UserX } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InputError from '@/components/input-error';
import { ChoiceSelect, Field } from '@/components/registration/field';
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
import { roleBadgeClasses, roleLabels } from '@/lib/roles';
import { users as usersRoute } from '@/routes/admin';
import type { RoleName } from '@/types';

type StaffUser = {
    id: number;
    name: string;
    email: string;
    role: RoleName | null;
    barangay_id: number | null;
    barangay_name: string | null;
    municipality_id: number | null;
    municipality_name: string | null;
    managed_barangay_ids: number[];
    managed_barangay_names: string[];
    active: boolean;
    can_edit: boolean;
    can_toggle: boolean;
    can_delete: boolean;
};

type MunicipalityOption = {
    id: number;
    name: string;
    barangays: { id: number; name: string }[];
};

type Props = {
    users: StaffUser[];
    municipalities: MunicipalityOption[];
    assignableRoles: RoleName[];
};

type FormData = {
    name: string;
    email: string;
    password: string;
    role: string;
    municipality_id: string;
    barangay_id: string;
    managed_barangay_ids: number[];
};

const blank = (role: string): FormData => ({
    name: '',
    email: '',
    password: '',
    role,
    municipality_id: '',
    barangay_id: '',
    managed_barangay_ids: [],
});

function placeOf(user: StaffUser): string {
    if (user.role === 'user') {
        return user.barangay_name ?? 'No barangay';
    }

    if (user.role === 'admin') {
        return `${user.municipality_name ?? 'No municipality'}: ${
            user.managed_barangay_names.join(', ') || 'no barangays'
        }`;
    }

    return 'All barangays';
}

function StaffForm({
    editing,
    municipalities,
    assignableRoles,
    onClose,
}: {
    editing: StaffUser | null;
    municipalities: MunicipalityOption[];
    assignableRoles: RoleName[];
    onClose: () => void;
}) {
    const form = useForm<FormData>(
        editing
            ? {
                  name: editing.name,
                  email: editing.email,
                  password: '',
                  role: editing.role ?? assignableRoles[0],
                  municipality_id: editing.municipality_id?.toString() ?? '',
                  barangay_id: editing.barangay_id?.toString() ?? '',
                  managed_barangay_ids: editing.managed_barangay_ids,
              }
            : blank(
                  assignableRoles.includes('user')
                      ? 'user'
                      : assignableRoles[0],
              ),
    );
    const { data, setData, errors, processing } = form;

    const barangayChoices = municipalities.flatMap((municipality) =>
        municipality.barangays.map((barangay) => ({
            value: barangay.id.toString(),
            label: `${barangay.name} (${municipality.name})`,
        })),
    );
    const chosenMunicipality = municipalities.find(
        (municipality) => municipality.id.toString() === data.municipality_id,
    );

    function toggleBarangay(id: number, checked: boolean) {
        setData(
            'managed_barangay_ids',
            checked
                ? [...data.managed_barangay_ids, id]
                : data.managed_barangay_ids.filter((current) => current !== id),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) {
            form.put(UserController.update.url(editing.id), options);
        } else {
            form.post(UserController.store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Field id="staff-name" label="Full name" error={errors.name}>
                <Input
                    id="staff-name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                />
            </Field>
            <Field id="staff-email" label="Email" error={errors.email}>
                <Input
                    id="staff-email"
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                />
            </Field>
            <Field
                id="staff-password"
                label={editing ? 'New password' : 'Password'}
                hint={
                    editing
                        ? 'Leave empty to keep the current password.'
                        : undefined
                }
                error={errors.password}
            >
                <Input
                    id="staff-password"
                    type="password"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(event) =>
                        setData('password', event.target.value)
                    }
                />
            </Field>
            <Field id="staff-role" label="Role" error={errors.role}>
                <ChoiceSelect
                    id="staff-role"
                    value={data.role}
                    placeholder="Choose a role"
                    values={assignableRoles.map((role) => ({
                        value: role,
                        label: roleLabels[role],
                    }))}
                    onChange={(role) => setData('role', role)}
                />
            </Field>

            {data.role === 'user' && (
                <Field
                    id="staff-barangay"
                    label="Barangay"
                    hint="An encoder works in exactly one barangay."
                    error={errors.barangay_id}
                >
                    <ChoiceSelect
                        id="staff-barangay"
                        value={data.barangay_id}
                        placeholder="Choose a barangay"
                        values={barangayChoices}
                        onChange={(value) => setData('barangay_id', value)}
                    />
                </Field>
            )}

            {data.role === 'admin' && (
                <>
                    <Field
                        id="staff-municipality"
                        label="Municipality"
                        error={errors.municipality_id}
                    >
                        <ChoiceSelect
                            id="staff-municipality"
                            value={data.municipality_id}
                            placeholder="Choose a municipality"
                            values={municipalities.map((municipality) => ({
                                value: municipality.id.toString(),
                                label: municipality.name,
                            }))}
                            onChange={(value) => {
                                setData((current) => ({
                                    ...current,
                                    municipality_id: value,
                                    managed_barangay_ids: [],
                                }));
                            }}
                        />
                    </Field>
                    <fieldset className="grid gap-2">
                        <legend className="text-base font-semibold">
                            Barangays this admin handles
                        </legend>
                        {chosenMunicipality === undefined ? (
                            <p className="text-base text-muted-foreground">
                                Choose a municipality first.
                            </p>
                        ) : (
                            chosenMunicipality.barangays.map((barangay) => (
                                <label
                                    key={barangay.id}
                                    className="flex min-h-12 items-center gap-3 rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border"
                                >
                                    <Checkbox
                                        checked={data.managed_barangay_ids.includes(
                                            barangay.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleBarangay(
                                                barangay.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    {barangay.name}
                                </label>
                            ))
                        )}
                        <InputError
                            message={
                                errors.managed_barangay_ids ??
                                errors['managed_barangay_ids.0']
                            }
                        />
                    </fieldset>
                </>
            )}

            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {editing ? 'Save changes' : 'Create account'}
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function Users({
    users,
    municipalities,
    assignableRoles,
}: Props) {
    const { auth, errors } = usePage<{ errors: Record<string, string> }>()
        .props;
    const [deactivating, setDeactivating] = useState<StaffUser | null>(null);
    const toggling = useForm({});
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<StaffUser | null>(null);
    const [deleting, setDeleting] = useState<StaffUser | null>(null);
    const deletion = useForm({});

    function open(user: StaffUser | null) {
        setEditing(user);
        setFormOpen(true);
    }

    return (
        <>
            <Head title="Staff accounts" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                            <UserCog className="size-8 text-blue-600" />
                            Staff Accounts
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            {auth.role_name === 'super_admin'
                                ? 'Create admins for a municipality and encoders for a barangay.'
                                : 'Create encoders and assign them to the barangays you handle.'}
                        </p>
                    </div>
                    <Button onClick={() => open(null)}>
                        <Plus />
                        Add account
                    </Button>
                </div>

                {errors.user && (
                    <p
                        role="alert"
                        className="rounded-xl border-2 border-red-300 bg-red-50 p-4 text-lg font-semibold text-red-900"
                    >
                        {errors.user}
                    </p>
                )}

                {users.length === 0 ? (
                    <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                        No accounts yet.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {users.map((user) => (
                            <li
                                key={user.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-200 bg-card p-4 dark:border-sidebar-border"
                            >
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <span className="flex flex-wrap items-center gap-2 text-xl font-bold">
                                        {user.name}
                                        {user.role && (
                                            <span
                                                className={`rounded-md px-2 py-0.5 text-sm font-bold ${roleBadgeClasses[user.role]}`}
                                            >
                                                {roleLabels[user.role]}
                                            </span>
                                        )}
                                        {!user.active && (
                                            <span className="rounded-md bg-amber-500 px-2 py-0.5 text-sm font-bold text-black">
                                                Inactive
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-base text-muted-foreground">
                                        {user.email}
                                    </span>
                                    <span className="text-base">
                                        {placeOf(user)}
                                    </span>
                                </div>
                                <div className="flex gap-2">
                                    {user.can_edit && (
                                        <Button
                                            variant="outline"
                                            onClick={() => open(user)}
                                        >
                                            <Pencil />
                                            Edit
                                        </Button>
                                    )}
                                    {user.can_toggle &&
                                        (user.active ? (
                                            <Button
                                                variant="outline"
                                                className="border-amber-400 text-amber-800 hover:bg-amber-50 hover:text-amber-900"
                                                onClick={() =>
                                                    setDeactivating(user)
                                                }
                                            >
                                                <UserX />
                                                Deactivate
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                className="border-emerald-400 text-emerald-800 hover:bg-emerald-50"
                                                disabled={toggling.processing}
                                                onClick={() =>
                                                    toggling.post(
                                                        UserController.reactivate.url(
                                                            user.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <UserCheck />
                                                Reactivate
                                            </Button>
                                        ))}
                                    {user.can_delete && (
                                        <Button
                                            variant="outline"
                                            className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800"
                                            onClick={() => setDeleting(user)}
                                        >
                                            <Trash2 />
                                            Delete
                                        </Button>
                                    )}
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
                            {editing ? 'Edit account' : 'Add account'}
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {editing
                                ? `Update the account of ${editing.name}.`
                                : 'The person can sign in with this email and password.'}
                        </DialogDescription>
                    </DialogHeader>
                    {formOpen && (
                        <StaffForm
                            key={editing?.id ?? 'new'}
                            editing={editing}
                            municipalities={municipalities}
                            assignableRoles={assignableRoles}
                            onClose={() => setFormOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={deactivating !== null}
                onOpenChange={(isOpen) => !isOpen && setDeactivating(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Deactivate this account?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {deactivating?.name} will be signed out and cannot
                            sign in again until someone reactivates the account.
                            Their records are kept.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDeactivating(null)}
                        >
                            Keep active
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={toggling.processing}
                            onClick={() =>
                                deactivating &&
                                toggling.post(
                                    UserController.deactivate.url(
                                        deactivating.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setDeactivating(null),
                                    },
                                )
                            }
                        >
                            <UserX />
                            Yes, deactivate
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
                            Delete this account?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {deleting?.name} will no longer be able to sign in.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Keep account
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={deletion.processing}
                            onClick={() =>
                                deleting &&
                                deletion.delete(
                                    UserController.destroy.url(deleting.id),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setDeleting(null),
                                    },
                                )
                            }
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

Users.layout = {
    breadcrumbs: [
        {
            title: 'Staff Accounts',
            href: usersRoute(),
        },
    ],
};
