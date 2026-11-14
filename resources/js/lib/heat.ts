import type { Band } from '@/types/score';

/**
 * Month-cell shading (CONVENTION.md §6, D-89): the band's own tint, the same four colours as
 * ScoreTag, so a 69.8 reads as Needs action everywhere instead of as a pale green. The band comes
 * from the server; nothing is computed here. Text stays ink on every tint, and the cell always
 * shows the server's ScoreValue.display.
 */
const TINT: Record<Band, string> = {
    strong: 'bg-ok-bg',
    acceptable: 'bg-fine-bg',
    review: 'bg-warn-bg',
    needs_action: 'bg-bad-bg',
};

export function heatClass(band: Band | null): string {
    return band === null ? '' : TINT[band];
}
