import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { CSSProperties } from 'react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const gridBackground: CSSProperties = {
    backgroundImage:
        'linear-gradient(to right, rgb(37 99 235 / 0.08) 1px, transparent 1px), linear-gradient(to bottom, rgb(37 99 235 / 0.08) 1px, transparent 1px)',
    backgroundSize: '32px 32px',
};

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div
            className="flex min-h-svh flex-col items-center justify-center bg-sky-50/50 p-6 md:p-10 dark:bg-background"
            style={gridBackground}
        >
            <div className="w-full max-w-md">
                <div className="flex flex-col gap-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-lg dark:border-border dark:bg-card">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="flex size-16 items-center justify-center rounded-2xl bg-blue-600 text-white">
                                <ShieldCheck className="size-10" />
                            </div>
                            <span className="text-2xl font-bold text-blue-900 dark:text-white">
                                FamiLink
                            </span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-bold">{title}</h1>
                            <p className="text-center text-base text-balance text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
