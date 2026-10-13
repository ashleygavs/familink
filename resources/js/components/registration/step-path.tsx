import { Link2, UsersRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import InputError from '@/components/input-error';
import type { FamilyPath } from '@/types';
import type { StepProps } from './step-types';

const OPTIONS: {
    path: FamilyPath;
    icon: LucideIcon;
    title: string;
    description: string;
}[] = [
    {
        path: 'new',
        icon: UsersRound,
        title: 'Start a new family record',
        description:
            "I'm the first in my family to register. I will list my father, mother, spouse and children myself.",
    },
    {
        path: 'existing',
        icon: Link2,
        title: 'Join an existing family record',
        description:
            'Someone in my family is already registered. I will find my family and link myself to it.',
    },
];

export function StepPath({ draft, update, errors, clearErrors }: StepProps) {
    return (
        <div className="grid gap-5">
            <div
                role="radiogroup"
                aria-label="Family record"
                className="grid gap-4 md:grid-cols-2"
            >
                {OPTIONS.map(({ path, icon: Icon, title, description }) => {
                    const selected = draft.path === path;

                    return (
                        <button
                            key={path}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => {
                                update({ path });
                                clearErrors('path');
                            }}
                            className={`flex min-h-44 flex-col items-start gap-3 rounded-2xl border-2 p-6 text-left focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none ${
                                selected
                                    ? 'border-blue-600 bg-blue-50 dark:bg-sidebar-accent'
                                    : 'border-slate-200 hover:border-blue-300 dark:border-border'
                            }`}
                        >
                            <span
                                className={`flex size-14 items-center justify-center rounded-xl ${
                                    selected
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-sky-100 text-blue-700'
                                }`}
                            >
                                <Icon className="size-8" />
                            </span>
                            <span className="text-xl font-bold">{title}</span>
                            <span className="text-base text-muted-foreground">
                                {description}
                            </span>
                        </button>
                    );
                })}
            </div>
            <InputError message={errors.path} />
            <p className="rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-base dark:border-sidebar-border dark:bg-sidebar">
                <strong>Not sure?</strong> Choose "Join an existing family
                record". We will search for your family first, and you can start
                a new one if nobody is found.
            </p>
        </div>
    );
}
