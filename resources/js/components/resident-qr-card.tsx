import { QRCodeSVG } from 'qrcode.react';
import type { RegisteredResident } from '@/types';

/**
 * Printable QR card. Only this card is printed (see the print rules in app.css).
 */
export function ResidentQrCard({
    resident,
    className = '',
}: {
    resident: RegisteredResident;
    className?: string;
}) {
    return (
        <div
            className={`flex flex-col items-center gap-2 rounded-xl border-2 border-slate-300 bg-white p-4 text-center text-slate-900 ${className}`}
        >
            <p className="text-base font-semibold tracking-wide text-blue-700 uppercase">
                FamiLink Resident QR
            </p>
            <QRCodeSVG
                value={resident.qr_code_hash}
                size={176}
                level="M"
                marginSize={2}
            />
            <p className="text-xl font-bold">{resident.full_name}</p>
            <p className="text-base">ID: {resident.system_id_no}</p>
            <p className="text-base">Household: {resident.household_code}</p>
        </div>
    );
}
