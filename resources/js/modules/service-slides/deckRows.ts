import type { DeckEntry, PresetStep } from './types';

export type DeckRow = {
    key: string;
    entries: DeckEntry[];
};

/** Group a source with the automatic separator that follows it for drag/drop. */
export function toRows(entries: DeckEntry[]): DeckRow[] {
    const rows: DeckRow[] = [];

    for (let i = 0; i < entries.length; i++) {
        const entry = entries[i];
        const next = entries[i + 1];

        if (
            entry.entry_type === 'source' &&
            next?.auto &&
            next.entry_type === 'template_slide' &&
            next.slot_key === entry.slot_key
        ) {
            rows.push({
                key: `source-${entry.slide_source_id}-${i}`,
                entries: [entry, next],
            });
            i++;
            continue;
        }

        rows.push({
            key: `${entry.entry_type}-${entry.slide_source_id ?? entry.slide_template_slide_id}-${i}`,
            entries: [entry],
        });
    }

    return rows;
}

export function fromRows(rows: DeckRow[]): DeckEntry[] {
    return rows.flatMap((row) => row.entries);
}

export function removeRow(rows: DeckRow[], index: number): DeckRow[] {
    return rows.filter((_, i) => i !== index);
}

/** Drop only the automatic separator from a grouped source row. */
export function dropSeparator(rows: DeckRow[], index: number): DeckRow[] {
    return rows.map((row, i) =>
        i === index
            ? { ...row, entries: row.entries.filter((entry) => !entry.auto) }
            : row,
    );
}

export function slotLabel(
    steps: PresetStep[] | undefined,
    key: string | null,
): string | null {
    if (!key || !steps) {
        return null;
    }

    const step = steps.find((item) => item.type === 'slot' && item.key === key);

    return step && step.type === 'slot' ? step.label : key;
}

export function payloadEntries(entries: DeckEntry[]) {
    return entries.map((entry) => ({
        entry_type: entry.entry_type,
        slide_template_slide_id: entry.slide_template_slide_id,
        slide_source_id: entry.slide_source_id,
        slot_key: entry.slot_key,
        auto: entry.auto,
    }));
}
