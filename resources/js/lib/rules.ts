import type { RuleConfigData } from '@/types/admin';

/**
 * Helpers for the Scoring rules page. They compare and check what an
 * administrator types; they compute no score (lib/architecture.test.ts). The server checks the
 * same rules again (RuleConfig) and refuses what it cannot apply.
 */

export type BandKey = 'strong' | 'acceptable' | 'review';
export const BAND_KEYS: readonly BandKey[] = ['strong', 'acceptable', 'review'];

export type BandError = { kind: 'range' } | { kind: 'order'; above: BandKey; value: string };

/**
 * What is wrong with the thresholds as typed: each a number from 0 to 100, and each below the one
 * above it (Strong > Acceptable > Review). The order is reported on the lower band.
 */
export function bandErrors(bands: Record<BandKey, string>): Partial<Record<BandKey, BandError>> {
    const errors: Partial<Record<BandKey, BandError>> = {};
    const value = (key: BandKey): number | null => {
        const text = bands[key].trim();
        const number = Number(text);

        return text === '' || !Number.isFinite(number) || number < 0 || number > 100 ? null : number;
    };
    for (const key of BAND_KEYS) {
        if (value(key) === null) {
            errors[key] = { kind: 'range' };
        }
    }
    BAND_KEYS.slice(1).forEach((key, index) => {
        const above = BAND_KEYS[index] as BandKey;
        const mine = value(key);
        const upper = value(above);
        if (errors[key] === undefined && mine !== null && upper !== null && !(mine < upper)) {
            errors[key] = { kind: 'order', above, value: bands[above].trim() };
        }
    });

    return errors;
}

/** A weight as typed is valid when it is a number above 0. */
export function weightValid(text: string): boolean {
    const number = Number(text.trim());

    return text.trim() !== '' && Number.isFinite(number) && number > 0;
}

export type ConfigChange =
    | { kind: 'band'; band: BandKey; from: number; to: number }
    | { kind: 'weight'; item: string; from: number | null; to: number | null }
    | { kind: 'na'; from: string; to: string }
    | { kind: 'choice'; choice: string; from: string | null; to: string | null };

/** Every difference between two versions' configs, bands first, then weights, N/A and choices. */
export function configChanges(from: RuleConfigData, to: RuleConfigData): ConfigChange[] {
    const changes: ConfigChange[] = [];
    for (const band of BAND_KEYS) {
        if (from.bands[band] !== to.bands[band]) {
            changes.push({ kind: 'band', band, from: from.bands[band], to: to.bands[band] });
        }
    }
    const weights = [...new Set([...Object.keys(from.item_weights), ...Object.keys(to.item_weights)])].sort();
    for (const item of weights) {
        const a = from.item_weights[item] ?? null;
        const b = to.item_weights[item] ?? null;
        if (a !== b) {
            changes.push({ kind: 'weight', item, from: a, to: b });
        }
    }
    if (from.na_policy !== to.na_policy) {
        changes.push({ kind: 'na', from: from.na_policy, to: to.na_policy });
    }
    const choices = [...new Set([...Object.keys(from.choice_map), ...Object.keys(to.choice_map)])].sort();
    for (const choice of choices) {
        const a = from.choice_map[choice] ?? null;
        const b = to.choice_map[choice] ?? null;
        if (a !== b) {
            changes.push({ kind: 'choice', choice, from: a, to: b });
        }
    }

    return changes;
}
