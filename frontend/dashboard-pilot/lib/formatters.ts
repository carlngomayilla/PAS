const numberFormatter = new Intl.NumberFormat('fr-FR');
const decimalFormatter = new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 1,
});
const currencyFormatter = new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'XAF',
    maximumFractionDigits: 0,
});

export function formatNumber(value: number): string {
    return numberFormatter.format(Number.isFinite(value) ? value : 0);
}

export function formatDecimal(value: number): string {
    return decimalFormatter.format(Number.isFinite(value) ? value : 0);
}

export function formatCurrency(value: number): string {
    return currencyFormatter.format(Number.isFinite(value) ? value : 0);
}

export function formatGeneratedAt(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Date non disponible';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Africa/Libreville',
    }).format(date);
}

export function humanizeKey(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, (letter) => letter.toUpperCase());
}
