import { Head, usePage } from '@inertiajs/react';
import { CheckCircle2, Printer, UserPlus } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { RegistrationWizard } from '@/components/registration/registration-wizard';
import { ResidentQrCard } from '@/components/resident-qr-card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { register } from '@/routes/encoder';
import type { RegisteredResident } from '@/types';

type Props = {
    barangays: { id: number; name: string }[];
    lockedBarangayId: number | null;
    options: {
        genders: string[];
        civilStatuses: string[];
        householdRoles: string[];
    };
    registered: RegisteredResident | null;
};

const subscribe = () => () => {};

/**
 * True only in the browser. The stepper restores its draft from sessionStorage,
 * so it must not be rendered on the server.
 */
function useIsClient(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => true,
        () => false,
    );
}

export default function RegisterResident({
    barangays,
    lockedBarangayId,
    options,
    registered,
}: Props) {
    const { auth } = usePage().props;
    const isClient = useIsClient();
    const [wizardKey, setWizardKey] = useState(0);
    const [dismissedBanner, setDismissedBanner] = useState<string | null>(null);

    const showBanner =
        registered !== null && dismissedBanner !== registered.qr_code_hash;

    function registerAnother(resident: RegisteredResident) {
        setDismissedBanner(resident.qr_code_hash);
        window.scrollTo({ top: 0 });
    }

    return (
        <>
            <Head title="Register Resident" />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-3xl font-bold text-blue-900 dark:text-white">
                        Register a Resident
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Go through the steps one at a time. Your answers are
                        remembered if you reload the page. New residents wait
                        for an admin to approve them before they can receive
                        relief.
                    </p>
                </div>

                {showBanner && registered && (
                    <section
                        role="status"
                        className="rounded-xl border-2 border-emerald-300 bg-emerald-50 p-5 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-50"
                    >
                        <div className="flex flex-wrap items-start gap-4">
                            <CheckCircle2 className="size-12 shrink-0 text-emerald-600" />
                            <div className="min-w-0 flex-1">
                                <h2 className="text-2xl font-bold">
                                    Resident registered!
                                </h2>
                                <p className="mt-1 text-lg">
                                    {registered.full_name} was saved and is
                                    waiting for admin approval.
                                </p>
                                <p className="mt-1 text-base">
                                    System ID:{' '}
                                    <strong>{registered.system_id_no}</strong>
                                    {' · '}Household:{' '}
                                    <strong>{registered.household_code}</strong>
                                </p>
                                <div className="mt-4 flex flex-wrap gap-3">
                                    <Button
                                        type="button"
                                        className="bg-emerald-700 hover:bg-emerald-800"
                                        onClick={() =>
                                            registerAnother(registered)
                                        }
                                    >
                                        <UserPlus />
                                        Register Another Resident
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => window.print()}
                                    >
                                        <Printer />
                                        Print QR Card
                                    </Button>
                                </div>
                            </div>
                            <ResidentQrCard resident={registered} />
                        </div>
                        <div
                            id="print-qr"
                            className="fixed inset-0 hidden items-center justify-center bg-white print:flex"
                        >
                            <ResidentQrCard
                                resident={registered}
                                className="scale-150 border-4"
                            />
                        </div>
                    </section>
                )}

                {isClient ? (
                    <RegistrationWizard
                        key={wizardKey}
                        userId={auth.user.id}
                        barangays={barangays}
                        lockedBarangayId={lockedBarangayId}
                        options={options}
                        onSubmitted={() => setWizardKey((key) => key + 1)}
                    />
                ) : (
                    <div className="grid gap-4" aria-busy="true">
                        <Skeleton className="h-14 w-full" />
                        <Skeleton className="h-96 w-full" />
                    </div>
                )}
            </div>
        </>
    );
}

RegisterResident.layout = {
    breadcrumbs: [
        {
            title: 'Register Resident',
            href: register(),
        },
    ],
};
