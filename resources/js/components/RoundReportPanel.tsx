import { useId, useState } from 'react';
import ExportButton from '@/components/ExportButton';
import Labelled from '@/components/Labelled';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { useTranslate } from '@/lib/i18n';
import type { RoundRow } from '@/types/admin';

export type RoundReportPanelProps = {
    rounds: RoundRow[];
};

/** The latest closed round, else the newest round: closed rounds are the report's default. */
function defaultRound(rounds: RoundRow[]): RoundRow | undefined {
    const byRecency = [...rounds].sort((a, b) => b.year - a.year || b.quarter - a.quarter);

    return byRecency.find((round) => round.status === 'closed') ?? byRecency[0];
}

/**
 * The PDF round report (Phase 6 step 5, D-101) on the Rounds page: choose a round (the latest
 * closed one first), then the export plumbing queues it, follows it and offers the download. An
 * open round's report is a draft, watermarked DRAFT on every page; the panel says so before it is
 * asked for.
 */
export default function RoundReportPanel({ rounds }: RoundReportPanelProps) {
    const t = useTranslate();
    const id = useId();
    const [roundId, setRoundId] = useState<number | null>(defaultRound(rounds)?.id ?? null);
    const chosen = rounds.find((round) => round.id === roundId) ?? defaultRound(rounds);

    return (
        <Panel labelledBy={`${id}-title`}>
            <PanelHeader id={`${id}-title`} title={t('admin.rounds.report.title')} description={t('admin.rounds.report.description')} />
            <div className="flex flex-col gap-3 px-5 pt-3 pb-5">
                {chosen === undefined ? (
                    <p className="text-sm text-ink-2">{t('admin.rounds.report.none')}</p>
                ) : (
                    <>
                        <div className="w-full sm:w-auto">
                            <Labelled inline id={`${id}-round`} label={t('admin.rounds.report.round')}>
                                <select id={`${id}-round`} value={chosen.id} onChange={(event) => setRoundId(Number(event.target.value))}>
                                    {rounds.map((round) => (
                                        <option key={round.id} value={round.id}>
                                            {round.status === 'open' ? t('admin.rounds.report.open_option', { round: round.label }) : round.label}
                                        </option>
                                    ))}
                                </select>
                            </Labelled>
                        </div>
                        {chosen.status === 'open' && (
                            <p className="notice notice-warn text-sm">
                                {t('admin.rounds.report.draft', { round: chosen.label })}
                            </p>
                        )}
                        {/* A new round starts a new export: the last one's link belongs to its round. */}
                        <ExportButton key={chosen.id} types={[{ key: 'round_report', offersPrivate: false, formats: ['pdf'] }]} filter={{ round: chosen.id }} />
                    </>
                )}
            </div>
        </Panel>
    );
}
