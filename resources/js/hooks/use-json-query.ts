import { useEffect, useState } from 'react';

/**
 * Fetches `{ data: T }` from a JSON endpoint whenever the url changes.
 * Requests are debounced and cancelled when the url changes again; a null url
 * means "not ready yet".
 */
export function useJsonQuery<T>(url: string | null, debounceMs = 350) {
    const [found, setFound] = useState<{ url: string; data: T } | null>(null);
    const [failedUrl, setFailedUrl] = useState<string | null>(null);

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
                        throw new Error('Request failed');
                    }

                    return response.json() as Promise<{ data: T }>;
                })
                .then((body) => setFound({ url, data: body.data }))
                .catch((caught: unknown) => {
                    if (!(caught instanceof DOMException)) {
                        setFailedUrl(url);
                    }
                });
        }, debounceMs);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [url, debounceMs]);

    const data = url !== null && found?.url === url ? found.data : null;

    return {
        data,
        loading: url !== null && data === null && failedUrl !== url,
        failed: url !== null && failedUrl === url,
    };
}
