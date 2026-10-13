import { Head } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { ResidentQrCard } from '@/components/resident-qr-card';
import { Button } from '@/components/ui/button';

type Card = {
    id: number;
    full_name: string;
    system_id_no: string;
    household_code: string | null;
    barangay_name: string | null;
    qr_code_hash: string;
};

type Props = {
    title: string;
    cards: Card[];
    notice: string | null;
};

/**
 * A plain sheet of QR cards for printing: no menu, two cards across, and no card is split between pages.
 */
export default function QrCards({ title, cards, notice }: Props) {
    return (
        <>
            <Head title={title} />
            <div className="min-h-screen bg-white p-4 text-slate-900 md:p-8">
                <div className="mx-auto max-w-3xl">
                    <div className="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
                        <Button
                            variant="outline"
                            className="border-slate-400 bg-white text-slate-900 hover:bg-slate-100 hover:text-slate-900"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft />
                            Back
                        </Button>
                        {cards.length > 0 && (
                            <Button
                                className="bg-blue-700 text-white hover:bg-blue-800"
                                onClick={() => window.print()}
                            >
                                <Printer />
                                Print{' '}
                                {cards.length === 1
                                    ? 'card'
                                    : `${cards.length} cards`}
                            </Button>
                        )}
                    </div>

                    <h1 className="mb-1 text-3xl font-bold text-blue-900 print:hidden">
                        {title}
                    </h1>
                    <p className="mb-6 text-lg text-slate-600 print:hidden">
                        Print and hand the card to the resident. They show it,
                        or the number on it, when claiming relief.
                    </p>

                    {notice && (
                        <p
                            role="status"
                            className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950 print:hidden"
                        >
                            {notice}
                        </p>
                    )}

                    <div
                        id="print-cards"
                        className="grid grid-cols-1 gap-4 sm:grid-cols-2 print:grid-cols-2"
                    >
                        {cards.map((card) => (
                            <ResidentQrCard
                                key={card.id}
                                resident={{
                                    id: card.id,
                                    full_name: card.full_name,
                                    system_id_no: card.system_id_no,
                                    household_code: card.household_code ?? '',
                                    qr_code_hash: card.qr_code_hash,
                                }}
                                className="break-inside-avoid"
                            />
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}
