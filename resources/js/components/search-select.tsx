import { Search } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Params = Record<string, string | number | boolean | null | undefined>;

type Props<T extends { id: number }> = {
    id: string;
    label: string;
    placeholder: string;
    endpoint: string;
    params?: Params;
    icon?: LucideIcon;
    helper?: string;
    error?: string;
    disabled?: boolean;
    excludeIds?: number[];
    emptyText?: string;
    renderOption: (item: T) => ReactNode;
    onSelect: (item: T) => void;
};

function buildUrl(endpoint: string, term: string, params: Params): string {
    const query = new URLSearchParams({ q: term });

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '') {
            query.set(key, String(value));
        }
    }

    return `${endpoint}?${query.toString()}`;
}

/**
 * Type-ahead search box. Fetches `{ data: T[] }` from a JSON endpoint and
 * shows large, easy-to-read result rows.
 */
export function SearchSelect<T extends { id: number }>({
    id,
    label,
    placeholder,
    endpoint,
    params = {},
    icon: Icon = Search,
    helper,
    error,
    disabled,
    excludeIds = [],
    emptyText = 'Nothing found. Check the spelling or try a different search.',
    renderOption,
    onSelect,
}: Props<T>) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [found, setFound] = useState<{ url: string; items: T[] } | null>(
        null,
    );
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);

    const term = query.trim();
    const url = term.length >= 2 ? buildUrl(endpoint, term, params) : null;

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Search failed');
                    }

                    return response.json() as Promise<{ data: T[] }>;
                })
                .then((body) => setFound({ url, items: body.data }))
                .catch((caught: unknown) => {
                    if (!(caught instanceof DOMException)) {
                        setFailedUrl(url);
                    }
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [url]);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const items = (found?.url === url ? found.items : []).filter(
        (item) => !excludeIds.includes(item.id),
    );
    const failed = failedUrl === url;
    const loading = url !== null && found?.url !== url && !failed;
    const showList = open && url !== null;

    return (
        <div ref={containerRef} className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="relative">
                <Icon className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-blue-600" />
                <Input
                    id={id}
                    value={query}
                    disabled={disabled}
                    autoComplete="off"
                    role="combobox"
                    aria-expanded={showList}
                    aria-controls={`${id}-results`}
                    placeholder={placeholder}
                    className="pl-11"
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                />
                {loading && (
                    <Spinner className="absolute top-1/2 right-3 -translate-y-1/2" />
                )}

                {showList && (
                    <ul
                        id={`${id}-results`}
                        role="listbox"
                        className="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-xl border-2 border-blue-200 bg-popover p-1 shadow-xl"
                    >
                        {failed && (
                            <li className="p-3 text-base font-semibold text-red-600">
                                The search did not work. Please try again.
                            </li>
                        )}
                        {!failed && !loading && items.length === 0 && (
                            <li className="p-3 text-base text-muted-foreground">
                                {emptyText}
                            </li>
                        )}
                        {items.map((item) => (
                            <li
                                key={item.id}
                                role="option"
                                aria-selected="false"
                            >
                                <button
                                    type="button"
                                    className="flex min-h-14 w-full flex-col items-start justify-center rounded-lg px-3 py-2 text-left hover:bg-sky-100 focus-visible:bg-sky-100 focus-visible:outline-none dark:hover:bg-sidebar-accent"
                                    onClick={() => {
                                        onSelect(item);
                                        setQuery('');
                                        setOpen(false);
                                    }}
                                >
                                    {renderOption(item)}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {helper && (
                <p className="text-base text-muted-foreground">{helper}</p>
            )}
            <InputError message={error} />
        </div>
    );
}
