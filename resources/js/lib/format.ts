/**
 * Format a YYYY-MM-DD date as e.g. "Mar 14, 1980".
 */
export function formatDate(isoDate: string): string {
    return new Date(`${isoDate.slice(0, 10)}T00:00:00`).toLocaleDateString(
        'en-PH',
        { year: 'numeric', month: 'short', day: 'numeric' },
    );
}

/**
 * Capitalise a name typed in all lower or all upper case, but keep names the
 * person typed with their own capitals (for example "McKenzie"). Matches how
 * the server stores names.
 */
export function properCase(value: string): string {
    const clean = value.replace(/\s+/g, ' ').trim();

    if (
        clean !== clean.toLocaleLowerCase() &&
        clean !== clean.toLocaleUpperCase()
    ) {
        return clean;
    }

    return clean
        .toLocaleLowerCase()
        .replace(
            /(^|[\s'-])(\p{L})/gu,
            (_match, before: string, letter: string) =>
                `${before}${letter.toLocaleUpperCase()}`,
        );
}
