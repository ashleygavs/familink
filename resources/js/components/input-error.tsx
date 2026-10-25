import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p
            role="alert"
            {...props}
            className={cn(
                'text-base font-semibold text-red-600 dark:text-red-400',
                className,
            )}
        >
            {message}
        </p>
    ) : null;
}

/**
 * The attributes that tell a screen reader a field is wrong and which message belongs to it.
 * The message next to it must have the id `{fieldId}-error`.
 */
export function fieldError(fieldId: string, message?: string) {
    return {
        'aria-invalid': message ? true : undefined,
        'aria-describedby': message ? `${fieldId}-error` : undefined,
    };
}
