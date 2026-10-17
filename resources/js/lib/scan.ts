export type ScanResult = {
    granted: boolean;
    code: string;
    message: string;
    /** Something the encoder should look at even though the claim was allowed. */
    warning: string | null;
    resident: {
        full_name: string;
        system_id_no: string;
        barangay_name: string | null;
        household_code: string | null;
    } | null;
    previous: {
        name: string;
        claimed_at: string;
        scanned_by: string;
        collected_by: string | null;
    } | null;
};

/** Someone other than the resident collecting the relief, with the authorization they showed. */
export type Representative = {
    name: string;
    relationship: string;
    verified: boolean;
};

const csrfToken = () => {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
};

/**
 * Sends a scanned code (or a typed ID) for an event and returns the server's decision. A denial is a normal
 * result; only a network or validation problem throws.
 */
export async function submitScan(
    url: string,
    eventId: number,
    code: string,
    representative: Representative | null = null,
): Promise<ScanResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            event_id: eventId,
            code,
            ...(representative === null
                ? {}
                : {
                      representative: true,
                      representative_name: representative.name,
                      representative_relationship: representative.relationship,
                      representative_verified: representative.verified,
                  }),
        }),
    });

    if (!response.ok) {
        if (response.status === 422) {
            const body = (await response.json().catch(() => null)) as {
                errors?: Record<string, string[]>;
            } | null;
            const first = Object.values(body?.errors ?? {})[0]?.[0];

            throw new Error(
                first ?? 'This event cannot be scanned. Choose another event.',
            );
        }

        throw new Error(
            'Could not reach the server. Check the connection and try again.',
        );
    }

    return (await response.json()) as ScanResult;
}

/**
 * A short tone so the result is heard as well as seen: one high beep for approved, two low ones for denied.
 */
export function playTone(granted: boolean): void {
    try {
        const context = new AudioContext();
        const beep = (start: number, frequency: number) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.frequency.value = frequency;
            gain.gain.value = 0.2;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start(context.currentTime + start);
            oscillator.stop(context.currentTime + start + 0.18);
        };

        if (granted) {
            beep(0, 880);
        } else {
            beep(0, 220);
            beep(0.25, 220);
        }

        window.setTimeout(() => void context.close(), 1000);
    } catch {
        // Sound is optional; the screen still shows the result.
    }
}
