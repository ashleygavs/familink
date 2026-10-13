import { usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white group-data-[collapsible=icon]:size-8">
                <ShieldCheck className="size-6 group-data-[collapsible=icon]:size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <span className="truncate text-lg font-bold text-blue-900 dark:text-white">
                    {name}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    Family & Relief Governance
                </span>
            </div>
        </>
    );
}
