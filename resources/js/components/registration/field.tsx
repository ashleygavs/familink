import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-base text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export function ChoiceSelect({
    id,
    value,
    placeholder,
    values,
    onChange,
    className = 'w-full',
}: {
    id: string;
    value: string;
    placeholder: string;
    values: { value: string; label: string }[];
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id} className={className}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {values.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** The name endings a person can have, in the order they are offered. */
export const NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

/**
 * The optional "Jr., Sr., II..." choice that goes after a last name. An empty value means no suffix.
 */
export function SuffixSelect({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <ChoiceSelect
            id={id}
            value={value === '' ? 'none' : value}
            placeholder="None"
            values={[
                { value: 'none', label: 'None' },
                ...NAME_SUFFIXES.map((suffix) => ({
                    value: suffix,
                    label: suffix,
                })),
            ]}
            onChange={(chosen) => onChange(chosen === 'none' ? '' : chosen)}
        />
    );
}

export const asChoices = (values: string[]) =>
    values.map((value) => ({ value, label: value }));
