import { Html5Qrcode } from 'html5-qrcode';
import { useCallback, useEffect, useRef, useState } from 'react';

export const CAMERA_STOPPED_MESSAGE =
    'The camera stopped. Check that it is connected and not being used by another app, then press "Restart camera". You can still type the resident ID below.';

export const CAMERA_FAILED_MESSAGE =
    'The camera could not start. Allow camera access, or type the resident ID below.';

/**
 * Runs the camera inside the element with the given id and reports every decoded QR code. The camera
 * stops when the screen closes. `error` is set when the camera cannot start (no permission, no camera,
 * or a page that is not served over https) or when it stops later (the phone slept, the camera was
 * unplugged or another app took it), so the screen can offer a restart or typing an ID instead.
 */
export function useQrScanner(
    elementId: string,
    enabled: boolean,
    onCode: (code: string) => void,
) {
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
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
        let track: MediaStreamTrack | null = null;

        const stopped = () => {
            if (!cancelled) {
                setError(CAMERA_STOPPED_MESSAGE);
            }
        };

        // A phone that slept or switched apps may come back with the camera already gone.
        const checkWhenVisible = () => {
            if (
                document.visibilityState === 'visible' &&
                track !== null &&
                track.readyState === 'ended'
            ) {
                stopped();
            }
        };

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

                    return;
                }

                const video = document.querySelector<HTMLVideoElement>(
                    `#${elementId} video`,
                );
                const stream = video?.srcObject;

                track =
                    stream instanceof MediaStream
                        ? (stream.getVideoTracks()[0] ?? null)
                        : null;
                track?.addEventListener('ended', stopped);
                document.addEventListener('visibilitychange', checkWhenVisible);
            })
            .catch(() => {
                if (!cancelled) {
                    setError(CAMERA_FAILED_MESSAGE);
                }
            });

        return () => {
            cancelled = true;
            track?.removeEventListener('ended', stopped);
            document.removeEventListener('visibilitychange', checkWhenVisible);

            if (started) {
                void scanner
                    .stop()
                    .then(() => scanner.clear())
                    .catch(() => undefined);
            }
        };
    }, [elementId, enabled, attempt]);

    const restart = useCallback(() => {
        setError(null);
        setAttempt((current) => current + 1);
    }, []);

    return { error, restart };
}
