import { Html5Qrcode } from 'html5-qrcode';
import { useEffect, useRef, useState } from 'react';

/**
 * Runs the camera inside the element with the given id and reports every decoded QR code. The camera
 * stops when the screen closes. `error` is set when the camera cannot start (no permission, no camera,
 * or a page that is not served over https), so the screen can offer typing an ID instead.
 */
export function useQrScanner(
    elementId: string,
    enabled: boolean,
    onCode: (code: string) => void,
) {
    const [error, setError] = useState<string | null>(null);
    const handler = useRef(onCode);

    useEffect(() => {
        handler.current = onCode;
    }, [onCode]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const scanner = new Html5Qrcode(elementId);
        let started = false;
        let cancelled = false;

        scanner
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                (decoded) => handler.current(decoded),
                () => undefined,
            )
            .then(() => {
                started = true;

                if (cancelled) {
                    void scanner.stop().catch(() => undefined);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError(
                        'The camera could not start. Allow camera access, or type the resident ID below.',
                    );
                }
            });

        return () => {
            cancelled = true;

            if (started) {
                void scanner
                    .stop()
                    .then(() => scanner.clear())
                    .catch(() => undefined);
            }
        };
    }, [elementId, enabled]);

    return { error };
}
