import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import StateMark from '@/components/StateMark';

import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { RuleSummary as Rule } from '@/types/admin';

export type RuleSummaryProps = {
    rules: Rule[];
    /** The rule the table is filtered by. */
    active: string | null;
    /** Filter by this rule, or by none. */
    onChoose: (code: string | null) => void;
};

/**
 * The validation rules of the tab in view (hard or soft, the 14 and RULE_ERROR) with how many
 * records each holds open (D-57), as one compact list (D-89): name, code, why in short, and the
 * count. Each is a toggle button that filters the table by it; the chosen one shows its reason in
 * full. The panel names the severity once, in words, instead of a tag on every rule.
 */
export default function RuleSummary({ rules, active, onChoose }: RuleSummaryProps) {
    const t = useTranslate();
    const severity = rules[0]?.severity === 'soft' ? 'soft' : 'hard';

    return (
        <Panel labelledBy="issues-rules-title">
            <PanelHeader id="issues-rules-title" title={t(`admin.issues.summary.title_${severity}`)} description={t(`admin.issues.summary.description_${severity}`)} />
            <ul className="mt-3 grid divide-y divide-rule border-t border-rule 2xl:grid-cols-1 md:grid-cols-2 md:divide-y-0 2xl:divide-y">
                {rules.map((rule) => {
                    const pressed = active === rule.code;
                    // ink-2 on the pressed wash is 4.3:1, under AA: full ink when pressed.
                    const secondary = pressed ? 'text-ink' : 'text-ink-2';

                    return (
                        <li key={rule.code} className="md:border-b md:border-rule 2xl:border-b-0">
                            <button
                                type="button"
                                aria-pressed={pressed}
                                onClick={() => onChoose(pressed ? null : rule.code)}
                                className={`flex w-full items-start gap-3 px-5 py-3 text-left text-sm transition-colors duration-150 ${
                                    pressed ? 'bg-wash shadow-[inset_3px_0_0_var(--color-deep)] forced-colors:outline-2 forced-colors:outline-[Highlight]' : 'hover:bg-paper'
                                }`}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className={`block ${pressed ? 'font-semibold' : 'font-medium'}`}>{rule.name}</span>
                                    <code className={`block text-2xs ${secondary}`}>{rule.code}</code>
                                    <span className={`mt-1 text-xs ${secondary} ${pressed ? 'block' : 'line-clamp-2'}`}>{rule.why}</span>
                                </span>
                                <span className={`shrink-0 rounded-full px-2 py-px text-xs whitespace-nowrap tabular-nums ${rule.open > 0 ? 'bg-bad-bg font-medium text-bad' : secondary}`}>
                                    {t('admin.issues.summary.open', { count: formatCount(rule.open) })}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

export function SeverityTag({ severity, label }: { severity: string; label: string }) {
    const hard = severity === 'hard';

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-1.5 py-px text-2xs whitespace-nowrap ${hard ? 'bg-bad-bg text-bad' : 'bg-warn-bg text-warn'}`}>
            <StateMark mark={hard ? '■' : '▲'} className="size-2" />
            {label}
        </span>
    );
}
