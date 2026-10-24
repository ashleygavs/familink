import { Head } from '@inertiajs/react';
import {
    Camera,
    CheckCircle2,
    Keyboard,
    PackageX,
    QrCode,
    ScanLine,
    TriangleAlert,
    UserRound,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import ReliefController from '@/actions/App/Http/Controllers/ReliefController';
import InputError from '@/components/input-error';
import { ChoiceSelect, Field } from '@/components/registration/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useQrScanner } from '@/hooks/use-qr-scanner';
import { formatDate } from '@/lib/format';
import { playTone, submitScan } from '@/lib/scan';
import type { Representative, ScanResult } from '@/lib/scan';
import { scanner as scannerRoute } from '@/routes/encoder';

type OpenEvent = {
    id: number;
    title: string;
    municipality_name: string | null;
    allocation_type: string;
    max_claims_per_household: number | null;
    end_date: string;
};

/** Seconds the result stays on screen before the scanner is ready for the next person. */
const RESET_SECONDS = 4;

/** A card seen again within this many seconds of its last sighting is the same card still held up, not a new scan. */
const SAME_CARD_SECONDS = 6;

function ResultScreen({
    result,
    seconds,
    onNext,
}: {
    result: ScanResult;
    seconds: number;
    onNext: () => void;
}) {
    const Icon = result.granted ? CheckCircle2 : XCircle;

    return (
        <div
            role="alert"
            aria-live="assertive"
            className={`fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 p-6 text-center text-white ${
                result.granted ? 'bg-emerald-600' : 'bg-red-600'
            }`}
        >
            <Icon className="size-32" aria-hidden />
            <p className="text-5xl font-extrabold tracking-wide">
                {result.granted ? 'APPROVED' : 'NOT ALLOWED'}
            </p>
            {result.resident && (
                <div className="grid gap-1">
                    <p className="text-3xl font-bold">
                        {result.resident.full_name}
                    </p>
                    <p className="text-xl">
                        ID {result.resident.system_id_no}
                        {result.resident.household_code
                            ? ` · ${result.resident.household_code}`
                            : ''}
                    </p>
                </div>
            )}
            <p className="max-w-xl text-2xl font-semibold">{result.message}</p>
            {result.warning && (
                <p
                    role="alert"
                    className="flex max-w-xl items-start gap-2 rounded-xl bg-amber-300 p-4 text-left text-xl font-bold text-amber-950"
                >
                    <TriangleAlert className="mt-1 size-6 shrink-0" />
                    {result.warning}
                </p>
            )}
            {result.previous && (
                <p className="text-xl">
                    Scanned by {result.previous.scanned_by}
                    {result.previous.collected_by
                        ? ` · collected by ${result.previous.collected_by}`
                        : ''}
                </p>
            )}
            <Button
                size="lg"
                className="min-h-14 bg-white px-8 text-xl text-slate-900 hover:bg-slate-100"
                onClick={onNext}
            >
                <ScanLine className="size-6" />
                Scan next
            </Button>
            <p className="text-lg">Ready again in {seconds}…</p>
        </div>
    );
}

export default function QRScanner({
    events,
    relationships,
}: {
    events: OpenEvent[];
    relationships: string[];
}) {
    const [eventId, setEventId] = useState(events[0]?.id.toString() ?? '');
    const [collecting, setCollecting] = useState(false);
    const [representative, setRepresentative] = useState({
        name: '',
        relationship: '',
        verified: false,
    });
    const [result, setResult] = useState<ScanResult | null>(null);
    const [seconds, setSeconds] = useState(RESET_SECONDS);
    const [problem, setProblem] = useState<string | null>(null);
    const [manualCode, setManualCode] = useState('');
    const [counts, setCounts] = useState({ approved: 0, denied: 0 });
    const busy = useRef(false);
    const lastCameraScan = useRef<{ code: string; at: number } | null>(null);
    const event = events.find(
        (candidate) => candidate.id.toString() === eventId,
    );

    async function check(code: string) {
        if (busy.current || !event) {
            return;
        }

        const claimant: Representative | null = collecting
            ? {
                  name: representative.name.trim(),
                  relationship: representative.relationship,
                  verified: representative.verified,
              }
            : null;

        if (
            claimant !== null &&
            (claimant.name.length < 3 ||
                claimant.relationship === '' ||
                !claimant.verified)
        ) {
            setProblem(
                'Someone else is collecting: enter their name and relationship, and confirm you checked their authorization, before scanning.',
            );

            return;
        }

        busy.current = true;
        setProblem(null);

        try {
            const outcome = await submitScan(
                ReliefController.scan.url(),
                event.id,
                code,
                claimant,
            );

            playTone(outcome.granted);
            setSeconds(RESET_SECONDS);
            setResult(outcome);
            setManualCode('');
            // The next person starts as themselves, so one person's details are never reused for another.
            setCollecting(false);
            setRepresentative({ name: '', relationship: '', verified: false });
            setCounts((current) => ({
                approved: current.approved + (outcome.granted ? 1 : 0),
                denied: current.denied + (outcome.granted ? 0 : 1),
            }));
        } catch (caught) {
            busy.current = false;
            // Nothing was recorded, so the same card may be tried again at once.
            lastCameraScan.current = null;
            setProblem(
                caught instanceof Error
                    ? caught.message
                    : 'Something went wrong.',
            );
        }
    }

    function next() {
        busy.current = false;
        setResult(null);
    }

    // Count down while a result is showing, then get ready for the next person.
    useEffect(() => {
        if (result === null) {
            return;
        }

        const timer = window.setInterval(() => {
            setSeconds((current) => {
                if (current <= 1) {
                    busy.current = false;
                    setResult(null);

                    return RESET_SECONDS;
                }

                return current - 1;
            });
        }, 1000);

        return () => window.clearInterval(timer);
    }, [result]);

    // The camera keeps seeing the same card while it is held up, so a card seen again within a few seconds is ignored.
    // Otherwise a person who was just approved would be scanned again and shown a red "already claimed".
    function scanFromCamera(code: string) {
        const last = lastCameraScan.current;
        lastCameraScan.current = { code, at: Date.now() };

        if (
            last !== null &&
            last.code === code &&
            Date.now() - last.at < SAME_CARD_SECONDS * 1000
        ) {
            return;
        }

        void check(code);
    }

    const { error: cameraError, restart: restartCamera } = useQrScanner(
        'qr-reader',
        event !== undefined,
        scanFromCamera,
    );

    function submitManual(formEvent: FormEvent) {
        formEvent.preventDefault();

        if (manualCode.trim() !== '') {
            void check(manualCode.trim());
        }
    }

    return (
        <>
            <Head title="Scan QR Code" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <QrCode className="size-8 text-blue-600" />
                        Scan QR Code
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Point the camera at the resident&apos;s QR code. Green
                        means they can receive relief.
                    </p>
                </div>

                {events.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border-2 border-amber-300 bg-amber-50 p-10 text-center text-amber-950">
                        <PackageX className="size-14 text-amber-600" />
                        <p className="text-2xl font-bold">
                            No relief event is open right now
                        </p>
                        <p className="text-lg">
                            Ask your admin to activate an event. It can only be
                            scanned while it is Active and today is inside its
                            dates.
                        </p>
                    </div>
                ) : (
                    <>
                        {events.length > 1 ? (
                            <Field id="scan-event" label="Relief event">
                                <ChoiceSelect
                                    id="scan-event"
                                    value={eventId}
                                    placeholder="Choose an event"
                                    values={events.map((candidate) => ({
                                        value: candidate.id.toString(),
                                        label: `${candidate.title} · ${candidate.municipality_name ?? 'No municipality'} · ${candidate.allocation_type}`,
                                    }))}
                                    onChange={setEventId}
                                />
                            </Field>
                        ) : null}

                        {event && (
                            <p className="rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-lg dark:border-sidebar-border dark:bg-sidebar">
                                <span className="font-bold">{event.title}</span>
                                {' · '}
                                {event.municipality_name} ·{' '}
                                {event.allocation_type}
                                {event.allocation_type === 'Per Resident' &&
                                    event.max_claims_per_household !== null &&
                                    ` (up to ${event.max_claims_per_household} per household)`}{' '}
                                · until {formatDate(event.end_date)}
                            </p>
                        )}

                        <div className="grid gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
                            <label className="flex min-h-12 items-center gap-3 text-lg font-semibold">
                                <Checkbox
                                    checked={collecting}
                                    onCheckedChange={(checked) =>
                                        setCollecting(checked === true)
                                    }
                                />
                                <UserRound className="size-5 text-blue-600" />
                                Someone else is collecting for the resident
                            </label>
                            {collecting && (
                                <div className="grid gap-3">
                                    <Field
                                        id="rep-name"
                                        label="Name of the person collecting"
                                    >
                                        <Input
                                            id="rep-name"
                                            value={representative.name}
                                            autoComplete="off"
                                            onChange={(inputEvent) =>
                                                setRepresentative({
                                                    ...representative,
                                                    name: inputEvent.target
                                                        .value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="rep-relationship"
                                        label="Their relationship to the resident"
                                    >
                                        <ChoiceSelect
                                            id="rep-relationship"
                                            value={representative.relationship}
                                            placeholder="Choose"
                                            values={relationships.map(
                                                (relationship) => ({
                                                    value: relationship,
                                                    label: relationship,
                                                }),
                                            )}
                                            onChange={(relationship) =>
                                                setRepresentative({
                                                    ...representative,
                                                    relationship,
                                                })
                                            }
                                        />
                                    </Field>
                                    <label className="flex min-h-12 items-center gap-3 rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border">
                                        <Checkbox
                                            checked={representative.verified}
                                            onCheckedChange={(checked) =>
                                                setRepresentative({
                                                    ...representative,
                                                    verified: checked === true,
                                                })
                                            }
                                        />
                                        I checked their ID and the
                                        resident&apos;s authorization letter
                                    </label>
                                    <p className="text-base text-muted-foreground">
                                        This applies to the next scan only. It
                                        is recorded with the claim.
                                    </p>
                                </div>
                            )}
                        </div>

                        <div className="overflow-hidden rounded-xl border-2 border-blue-200 dark:border-sidebar-border">
                            <div id="qr-reader" className="min-h-64 w-full" />
                            {cameraError && (
                                <div className="grid gap-3 p-4">
                                    <p
                                        role="alert"
                                        className="text-base font-semibold text-red-700"
                                    >
                                        {cameraError}
                                    </p>
                                    <Button
                                        variant="outline"
                                        className="min-h-12 text-lg"
                                        onClick={restartCamera}
                                    >
                                        <Camera className="size-5" />
                                        Restart camera
                                    </Button>
                                </div>
                            )}
                        </div>

                        <form
                            onSubmit={submitManual}
                            className="grid gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border"
                        >
                            <Field
                                id="manual-code"
                                label="Or type the resident ID"
                                hint="Use this when the QR code is damaged or the camera does not work."
                            >
                                <Input
                                    id="manual-code"
                                    value={manualCode}
                                    autoComplete="off"
                                    placeholder="FL-2026-SJ-00001"
                                    onChange={(inputEvent) =>
                                        setManualCode(inputEvent.target.value)
                                    }
                                />
                            </Field>
                            <Button
                                type="submit"
                                disabled={manualCode.trim() === ''}
                            >
                                <Keyboard />
                                Check this ID
                            </Button>
                            <InputError message={problem ?? undefined} />
                        </form>

                        <p className="text-center text-lg text-muted-foreground">
                            This session: {counts.approved} approved ·{' '}
                            {counts.denied} not allowed
                        </p>
                    </>
                )}
            </div>

            {result && (
                <ResultScreen result={result} seconds={seconds} onNext={next} />
            )}
        </>
    );
}

QRScanner.layout = {
    breadcrumbs: [
        {
            title: 'Scan QR Code',
            href: scannerRoute(),
        },
    ],
};
