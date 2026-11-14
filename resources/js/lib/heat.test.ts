import { describe, expect, it } from 'vitest';
import { heatClass } from '@/lib/heat';

// CONVENTION.md §6 (D-89): a month cell takes its band's tint, the same colours as ScoreTag.

describe('heatClass', () => {
    it('gives each band its own tint', () => {
        expect(heatClass('strong')).toBe('bg-ok-bg');
        expect(heatClass('acceptable')).toBe('bg-fine-bg');
        expect(heatClass('review')).toBe('bg-warn-bg');
        expect(heatClass('needs_action')).toBe('bg-bad-bg');
    });

    it('leaves a cell with no score unshaded', () => {
        expect(heatClass(null)).toBe('');
    });
});
