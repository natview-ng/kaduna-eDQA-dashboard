import ScoreTag from '@/components/ScoreTag';
import StateMark from '@/components/StateMark';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { RoundPreviewData } from '@/types/admin';

export type RulePreviewProps = {
    /** Null until the preview has run. */
    rounds: RoundPreviewData[] | null;
    /** Round id => its label ("Q3 2026"). */
    labels: Record<number, string>;
    closed: { id: number; label: string; rule_version: number | null }[];
};

/**
 * The publish preview, every number the server's: for each open round, which
 * facilities and LGAs change band (now → under the draft) and the state overall before and after;
 * then the closed rounds, listed as unaffected (D-32), and the note that the public dashboard only
 * changes when an open round is closed.
 */
export default function RulePreview({ rounds, labels, closed }: RulePreviewProps) {
    const t = useTranslate();
    const cell = 'px-3 py-1.5';
    const head = 'bg-subtle px-3 py-2 text-left text-xs font-medium text-ink-2';

    return (
        <div className="flex flex-col gap-5">
            {rounds !== null && rounds.length === 0 && <p className="text-sm">{t('admin.scoring.preview.no_open')}</p>}
            {rounds?.map((round) => {
                const label = labels[round.round_id] ?? `Q${round.quarter} ${round.year}`;

                return (
                    <section key={round.round_id} aria-labelledby={`preview-round-${round.round_id}`} className="rounded-md border border-rule p-4">
                        <h3 id={`preview-round-${round.round_id}`} className="text-base font-semibold">
                            {t('admin.scoring.preview.round', { round: label })}
                        </h3>
                        <p className="mt-1 text-sm">
                            {t('admin.scoring.preview.summary', { facilities: formatCount(round.facilities_changing_count), lgas: formatCount(round.lgas_changing_count), assessments: formatCount(round.assessments) })}
                        </p>
                        {round.skipped > 0 && <p className="mt-1 text-sm text-ink-2">{t('admin.scoring.preview.skipped', { count: formatCount(round.skipped) })}</p>}
                        <dl className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <dt className="font-medium">{t('admin.scoring.preview.state')}</dt>
                            <dd className="m-0 flex items-center gap-2">
                                <ScoreTag score={round.state_overall_before} />
                                <span aria-hidden="true">→</span>
                                <span className="sr-only">{t('admin.scoring.preview.after')}</span>
                                <ScoreTag score={round.state_overall_after} />
                            </dd>
                        </dl>
                        {round.facilities_changing_count === 0 && round.lgas_changing_count === 0 ? (
                            <p className="mt-3 text-sm text-ink-2">{t('admin.scoring.preview.none_changing')}</p>
                        ) : (
                            <div className="mt-3 grid gap-4 lg:grid-cols-2">
                                {round.facilities_changing_count > 0 && (
                                    <table className="w-full text-sm">
                                        <caption className="mb-1 text-left text-sm font-semibold">{t('admin.scoring.preview.facilities', { round: label })}</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.facility')}</th>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.before')}</th>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.after')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {round.facilities_changing_band.map((row) => (
                                                <tr key={row.facility_id} className="border-t border-rule">
                                                    <th scope="row" className={`${cell} text-left font-normal`}>
                                                        {row.name}
                                                        <span className="block text-xs text-ink-2">{row.code}</span>
                                                    </th>
                                                    <td className={cell}><ScoreTag score={row.before} /></td>
                                                    <td className={cell}><ScoreTag score={row.after} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                                {round.lgas_changing_count > 0 && (
                                    <table className="w-full text-sm">
                                        <caption className="mb-1 text-left text-sm font-semibold">{t('admin.scoring.preview.lgas', { round: label })}</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.lga')}</th>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.before')}</th>
                                                <th scope="col" className={head}>{t('admin.scoring.preview.after')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {round.lgas_changing_band.map((row) => (
                                                <tr key={row.lga_id} className="border-t border-rule">
                                                    <th scope="row" className={`${cell} text-left font-normal`}>{row.name}</th>
                                                    <td className={cell}><ScoreTag score={row.before} /></td>
                                                    <td className={cell}><ScoreTag score={row.after} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        )}
                    </section>
                );
            })}

            <section aria-labelledby="preview-closed" className="text-sm">
                <h3 id="preview-closed" className="font-semibold">
                    {t('admin.scoring.preview.closed_title')}
                </h3>
                {closed.length === 0 ? (
                    <p className="mt-1 text-ink-2">{t('admin.scoring.preview.closed_none')}</p>
                ) : (
                    <ul className="mt-1 flex flex-col gap-0.5">
                        {closed.map((round) => (
                            <li key={round.id} className="flex items-center gap-2">
                                <StateMark mark="●" className="text-ok" />
                                {t('admin.scoring.preview.closed_item', { round: round.label, version: round.rule_version ?? '—' })}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 font-medium">{t('admin.scoring.preview.public_note')}</p>
            </section>
        </div>
    );
}
