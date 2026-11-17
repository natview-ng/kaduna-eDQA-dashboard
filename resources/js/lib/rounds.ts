/**
 * Calendar helpers for the Rounds page. Dates are the "YYYY-MM-DD" strings the
 * API takes; nothing here is a score, and no figure is computed (lib/architecture.test.ts).
 */

const QUARTER_START = ['01-01', '04-01', '07-01', '10-01'] as const;
const QUARTER_END = ['03-31', '06-30', '09-30', '12-31'] as const;
const QUARTER_OF_MONTH = [1, 1, 1, 2, 2, 2, 3, 3, 3, 4, 4, 4] as const;

/** A quarter's own dates: the window a new round starts with. */
export function quarterDates(year: number, quarter: number): { start: string; end: string } {
    const index = Math.min(4, Math.max(1, quarter)) - 1;

    return { start: `${year}-${QUARTER_START[index]}`, end: `${year}-${QUARTER_END[index]}` };
}

/** The quarter after the newest round, or the one `today` falls in when there is none. */
export function nextQuarter(rounds: readonly { year: number; quarter: number }[], today: Date): { year: number; quarter: number } {
    const newest = rounds[0];
    if (newest === undefined) {
        return { year: today.getFullYear(), quarter: QUARTER_OF_MONTH[today.getMonth()] ?? 1 };
    }

    return newest.quarter === 4 ? { year: newest.year + 1, quarter: 1 } : { year: newest.year, quarter: newest.quarter + 1 };
}

/**
 * Which window field a refusal belongs to (the API answers 409 { code, message }): the round
 * already existing is the quarter's; an end before the start is the end's; a start outside the
 * quarter or a clash with another round is the start's; past the late-visit grace is the end's.
 */
export function windowErrorField(code: string, start: string, quarter: { start: string; end: string }): 'quarter' | 'window_start' | 'window_end' {
    switch (code) {
        case 'round_exists':
            return 'quarter';
        case 'window_order':
            return 'window_end';
        case 'window_outside_quarter':
            return start < quarter.start || start > quarter.end ? 'window_start' : 'window_end';
        default:
            return 'window_start';
    }
}
