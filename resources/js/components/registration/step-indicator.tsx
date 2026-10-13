import { Check } from 'lucide-react';

type Props = {
    steps: readonly { id: string; title: string }[];
    current: number;
    maxStep: number;
    onSelect: (index: number) => void;
};

/**
 * Numbered progress bar. People can jump back to any step they have already
 * reached, but not skip ahead.
 */
export function StepIndicator({ steps, current, maxStep, onSelect }: Props) {
    return (
        <nav aria-label="Registration progress">
            <ol className="hidden items-center md:flex">
                {steps.map((step, index) => {
                    const reachable = index <= maxStep;
                    const done = index < current;
                    const active = index === current;

                    return (
                        <li
                            key={step.id}
                            className="flex flex-1 items-center last:flex-none"
                        >
                            <button
                                type="button"
                                disabled={!reachable}
                                aria-current={active ? 'step' : undefined}
                                onClick={() => onSelect(index)}
                                className="group flex items-center gap-3 rounded-lg p-1 text-left focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed"
                            >
                                <span
                                    className={`flex size-11 shrink-0 items-center justify-center rounded-full text-lg font-bold ${
                                        active
                                            ? 'bg-blue-600 text-white'
                                            : done
                                              ? 'bg-emerald-600 text-white'
                                              : reachable
                                                ? 'bg-blue-100 text-blue-800'
                                                : 'bg-slate-200 text-slate-500 dark:bg-muted'
                                    }`}
                                >
                                    {done ? (
                                        <Check className="size-6" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span
                                    className={`text-base whitespace-nowrap ${
                                        active
                                            ? 'font-bold text-foreground'
                                            : 'font-medium text-muted-foreground'
                                    }`}
                                >
                                    {step.title}
                                </span>
                            </button>
                            {index < steps.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className={`mx-3 h-1 flex-1 rounded-full ${
                                        done
                                            ? 'bg-emerald-500'
                                            : 'bg-slate-200 dark:bg-muted'
                                    }`}
                                />
                            )}
                        </li>
                    );
                })}
            </ol>

            <div className="md:hidden">
                <p className="text-lg font-bold">
                    Step {current + 1} of {steps.length}:{' '}
                    <span className="text-blue-700 dark:text-blue-400">
                        {steps[current].title}
                    </span>
                </p>
                <div
                    className="mt-2 h-3 overflow-hidden rounded-full bg-slate-200 dark:bg-muted"
                    role="progressbar"
                    aria-valuemin={1}
                    aria-valuemax={steps.length}
                    aria-valuenow={current + 1}
                >
                    <div
                        className="h-full rounded-full bg-blue-600 transition-all"
                        style={{
                            width: `${((current + 1) / steps.length) * 100}%`,
                        }}
                    />
                </div>
            </div>
        </nav>
    );
}
