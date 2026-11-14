import StateMark from '@/components/StateMark';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList, type Translate } from '@/lib/i18n';
import type { ShellStatus, SyncState, SyncStatus } from '@/types/shell';

export type SyncStatusBarProps = {
    status: ShellStatus;
};

// D-89: the bar itself stays neutral; each source carries its state in its mark colour, its
// shape and its words, so a failure is still said and seen without painting the whole line red.
const TONE: Record<SyncState, string> = {
    not_configured: 'text-ink-3',
    disabled: 'text-ink-3',
    not_connected: 'text-ink-3',
    healthy: 'text-ok',
    stale: 'text-warn-fill',
    failed: 'text-bad',
    auth_failed: 'text-bad',
};

// A shape per state as well as a colour and the words (CLAUDE.md hard rule 11); decorative.
const MARK: Record<SyncState, string> = { not_configured: '○', disabled: '○', not_connected: '○', healthy: '●', stale: '▲', failed: '■', auth_failed: '■' };

/**
 * The sources' sync line above the top bar (ARCHITECTURE.md §9.1): every source by name, worst
 * state first (the server orders them), each said in words with its own mark (ACCESSIBILITY.md
 * §6), then the counts. Not configured, switched off and waiting for the first pull are neutral:
 * nothing has failed.
 */
export default function SyncStatusBar({ status }: SyncStatusBarProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const at = (value: string | null) => (value === null || months.length !== 12 ? '' : formatDateTime(value, months));
    const counts = t('admin.sync.counts', { assessments: formatCount(status.assessments), quarantined: formatCount(status.quarantined) });

    return (
        <div role="status" aria-label={t('admin.sync.label')} className="flex flex-wrap items-center gap-x-6 gap-y-1 border-b border-rule bg-panel px-4 py-2 text-xs text-ink-2 rail:px-7">
            {status.sources.length === 0 ? (
                <span>{t('admin.sync.none')}</span>
            ) : (
                status.sources.map((source) => (
                    <span key={source.key} className="inline-flex items-center gap-2">
                        <StateMark mark={MARK[source.state]} className={`size-2 ${TONE[source.state]}`} />
                        <span className={source.state === 'failed' || source.state === 'auth_failed' ? 'text-ink' : undefined}>{sentence(t, source, at)}</span>
                    </span>
                ))
            )}
            <span className="tabular-nums rail:ml-auto">{counts}</span>
        </div>
    );
}

function sentence(t: Translate, source: SyncStatus, at: (value: string | null) => string): string {
    const name = { source: source.name };
    const minutes = source.minutesSinceSuccess === null ? '' : formatCount(source.minutesSinceSuccess);
    const success = source.lastSuccessAt === null ? t('admin.sync.never_succeeded') : at(source.lastSuccessAt);

    switch (source.state) {
        case 'not_configured':
        case 'disabled':
        case 'not_connected':
            return t(`admin.sync.${source.state}`, name);
        case 'healthy':
            return t('admin.sync.healthy', { ...name, minutes });
        case 'stale':
            return t('admin.sync.stale', { ...name, minutes, limit: source.staleAfterMinutes });
        case 'auth_failed':
            return t('admin.sync.auth_failed', { ...name, time: at(source.lastFailedAt), success });
        case 'failed':
            return source.lastErrorClass === null
                ? t('admin.sync.failed_no_error', { ...name, time: at(source.lastFailedAt), success })
                : t('admin.sync.failed', { ...name, time: at(source.lastFailedAt), error: source.lastErrorClass, success });
    }
}
