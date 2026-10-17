import { usePage } from '@inertiajs/react';
import { CheckCircle2, X } from 'lucide-react';
import { useState } from 'react';

/**
 * Large success message for actions such as approving a resident.
 */
export function FlashBanner() {
    const message = usePage().props.flash.success;
    const [dismissed, setDismissed] = useState<string | null>(null);

    if (!message || dismissed === message) {
        return null;
    }

    return (
        <div
            role="status"
            className="mx-4 mt-4 flex items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 text-emerald-900 md:mx-6 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100 print:hidden"
        >
            <CheckCircle2 className="size-8 shrink-0 text-emerald-600" />
            <p className="flex-1 text-lg font-semibold">{message}</p>
            <button
                type="button"
                onClick={() => setDismissed(message)}
                aria-label="Close message"
                className="flex size-12 items-center justify-center rounded-lg hover:bg-emerald-100 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none dark:hover:bg-emerald-900"
            >
                <X className="size-6" />
            </button>
        </div>
    );
}
