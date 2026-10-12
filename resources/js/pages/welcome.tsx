import { Head, Link, usePage } from '@inertiajs/react';
import { LogIn, ShieldCheck } from 'lucide-react';
import type { CSSProperties } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const gridBackground: CSSProperties = {
    backgroundImage:
        'linear-gradient(to right, rgb(37 99 235 / 0.08) 1px, transparent 1px), linear-gradient(to bottom, rgb(37 99 235 / 0.08) 1px, transparent 1px)',
    backgroundSize: '32px 32px',
};

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div
                className="flex min-h-screen flex-col items-center justify-center gap-6 bg-sky-50/50 p-6 text-center dark:bg-background"
                style={gridBackground}
            >
                <div className="flex size-20 items-center justify-center rounded-3xl bg-blue-600 text-white">
                    <ShieldCheck className="size-12" />
                </div>
                <h1 className="text-4xl font-bold text-blue-900 dark:text-white">
                    FamiLink
                </h1>
                <p className="max-w-xl text-xl text-balance text-slate-700 dark:text-slate-300">
                    Family tree governance and municipal relief distribution,
                    made simple and fair for every barangay.
                </p>
                <Link
                    href={auth.user ? dashboard() : login()}
                    className={buttonVariants({
                        size: 'lg',
                        className: 'bg-blue-600 hover:bg-blue-700',
                    })}
                >
                    <LogIn />
                    {auth.user ? 'Go to Dashboard' : 'Log In to FamiLink'}
                </Link>
            </div>
        </>
    );
}
