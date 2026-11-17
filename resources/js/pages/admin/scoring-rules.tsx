import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import ConfirmWithReasonDialog from '@/components/ConfirmWithReasonDialog';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import RuleDraftEditor from '@/components/RuleDraftEditor';
import RulePreview from '@/components/RulePreview';
import RuleVersionHistory from '@/components/RuleVersionHistory';
import RuleVersionSummary from '@/components/RuleVersionSummary';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { RescoreRunData, RoundPreviewData, RuleConfigData, RuleVersionRow, ScoredItem } from '@/types/admin';

export type AdminScoringRulesProps = {
    versions: RuleVersionRow[];
    draft: RuleVersionRow | null;
    items: ScoredItem[];
    rounds: { open: { id: number; label: string }[]; closed: { id: number; label: string; rule_version: number | null }[] };
    rescores: { round_id: number; batch_id: string | null; status: string }[];
    settings: { weighting: string; rounding: string };
};

/** How long, at most, the page waits for a publish's queued rescores to start: 40 × 3 s. */
const POLL_MS = 3000;
const POLL_TRIES = 40;

/**
 * The Scoring rules page (ARCHITECTURE.md §7, §9.3). The current version, read
 * only with the configuration it depends on; one draft, edited and saved or discarded; its impact
 * previewed per open round (in memory, nothing saved), closed rounds listed as unaffected (D-32);
 * publish behind a reasoned dialog that repeats the preview, then each open round's rescore
 * followed to its end; and the version history with a diff between any two versions.
 */
export default function AdminScoringRules({ versions, draft, items, rounds, rescores, settings }: AdminScoringRulesProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const api = useApiAction<{ data: RuleVersionRow }>();
    const previewApi = useApiAction<{ data: { rounds: RoundPreviewData[] } }>();
    const current = versions.find((version) => version.status === 'current') ?? null;
    const [preview, setPreview] = useState<RoundPreviewData[] | null>(null);
    const [dirty, setDirty] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [outcome, setOutcome] = useState<string | null>(null);
    const [publishing, setPublishing] = useState(false);
    const [following, setFollowing] = useState(false);
    const [tries, setTries] = useState(0);
    const labels = Object.fromEntries(rounds.open.map((round) => [round.id, round.label]));

    const say = (text: string) => {
        setOutcome(text);
        announce(text);
    };
    const reload = (only: string[] = ['versions', 'draft', 'rescores']) => router.reload({ only });

    // After a publish: reload until every open round's queued rescore has started (then each is
    // followed in its own ProgressPanel), for two minutes at most.
    const waiting = following && rounds.open.some((round) => !rescores.some((run) => run.round_id === round.id && run.batch_id !== null));
    useEffect(() => {
        if (!waiting || tries >= POLL_TRIES) {
            return;
        }
        const timer = setTimeout(() => {
            setTries((n) => n + 1);
            router.reload({ only: ['rescores'] });
        }, POLL_MS);

        return () => clearTimeout(timer);
    }, [waiting, tries, rescores]);

    const start = async () => {
        if (current === null) {
            return;
        }
        setError(null);
        const result = await api.run('POST', '/admin/api/scoring-rules', { from: current.id, changes: {} });
        if (!result.ok) {
            setError(result.message);

            return;
        }
        reload();
    };

    const save = async (changes: Pick<RuleConfigData, 'bands' | 'item_weights' | 'na_policy'>) => {
        if (draft === null) {
            return;
        }
        setError(null);
        const result = await api.run('PATCH', `/admin/api/scoring-rules/${draft.id}`, { changes });
        if (!result.ok) {
            setError(result.message);

            return;
        }
        setDirty(false);
        setPreview(null);
        say(t('admin.scoring.draft.saved', { version: draft.version }));
        reload();
    };

    const discard = async () => {
        if (draft === null) {
            return;
        }
        const result = await api.run('DELETE', `/admin/api/scoring-rules/${draft.id}`);
        if (!result.ok) {
            setError(result.message);

            return;
        }
        setPreview(null);
        setDirty(false);
        say(t('admin.scoring.draft.discarded'));
        reload();
    };

    const runPreview = async () => {
        if (draft === null) {
            return;
        }
        const result = await previewApi.run('POST', `/admin/api/scoring-rules/${draft.id}/preview`);
        if (result.ok) {
            setPreview(result.data.data.rounds);
        }
    };

    const summary = (): string =>
        [
            ...(preview ?? []).map((round) =>
                t('admin.scoring.publish.round_summary', {
                    round: labels[round.round_id] ?? `Q${round.quarter} ${round.year}`,
                    facilities: formatCount(round.facilities_changing_count),
                    lgas: formatCount(round.lgas_changing_count),
                    before: round.state_overall_before.display,
                    after: round.state_overall_after.display,
                }),
            ),
            ...((preview ?? []).length === 0 ? [t('admin.scoring.publish.no_open')] : []),
            t('admin.scoring.publish.closed'),
        ].join(' ');

    const rescoreStage = ({ data }: { data: RescoreRunData }): ProgressStage => ({
        key: data.status,
        label: t(`admin.scoring.publish.stages.${data.status}`),
        terminal: data.status !== 'running',
    });
    const showRescores = following || rescores.some((run) => run.status === 'running');

    return (
        <div className="flex flex-col gap-5">
            {outcome !== null && (
                <p role="status" className="notice notice-ok">
                    {outcome}
                </p>
            )}

            <RuleVersionSummary current={current} settings={settings} />

            {showRescores && rounds.open.length > 0 && (
                <Panel labelledBy="rules-rescores-title">
                    <PanelHeader id="rules-rescores-title" title={t('admin.scoring.publish.progress', { round: rounds.open.map((round) => round.label).join(', ') })} />
                    <div className="grid gap-3 px-5 pt-3 pb-5 lg:grid-cols-2">
                        {rounds.open.map((round) => {
                            const run = rescores.find((item) => item.round_id === round.id && item.batch_id !== null);

                            return run?.batch_id == null ? (
                                <p key={round.id} className="text-sm text-ink-2">
                                    {t('admin.scoring.publish.queued', { round: round.label })}
                                </p>
                            ) : (
                                <ProgressPanel<{ data: RescoreRunData }>
                                    key={run.batch_id}
                                    title={t('admin.scoring.publish.progress', { round: round.label })}
                                    url={`/admin/api/rescores/${run.batch_id}`}
                                    stage={rescoreStage}
                                    onDone={() => reload(['versions', 'rescores'])}
                                >
                                    {({ data }) => (
                                        <dl className="flex justify-between gap-3 text-sm">
                                            <dt>{t('admin.scoring.publish.processed')}</dt>
                                            <dd className="font-medium text-ink tabular-nums">
                                                {formatCount(data.assessments.processed)} / {formatCount(data.assessments.total)}
                                            </dd>
                                        </dl>
                                    )}
                                </ProgressPanel>
                            );
                        })}
                    </div>
                </Panel>
            )}

            <Panel labelledBy="rules-draft-title">
                <PanelHeader id="rules-draft-title" title={draft === null ? t('admin.scoring.draft.none_title') : t('admin.scoring.draft.title', { version: draft.version })} />
                {draft === null ? (
                    <div className="px-5 pt-3 pb-5">
                        <p className="text-sm text-ink-2">{t('admin.scoring.draft.none')}</p>
                        {current !== null && (
                            <button type="button" className="btn btn-primary mt-3" disabled={api.pending} onClick={() => void start()}>
                                {api.pending ? t('admin.scoring.draft.starting') : t('admin.scoring.draft.start', { version: current.version })}
                            </button>
                        )}
                        {error !== null && (
                            <p role="alert" className="notice notice-bad mt-3 text-bad">
                                {error}
                            </p>
                        )}
                    </div>
                ) : (
                    <RuleDraftEditor
                        key={draft.id}
                        version={draft.version}
                        config={draft.config}
                        items={items}
                        pending={api.pending}
                        error={error}
                        onSave={(changes) => void save(changes)}
                        onDiscard={() => void discard()}
                        onDirtyChange={(value) => {
                            setDirty(value);
                            if (value) {
                                setPreview(null);
                            }
                        }}
                    />
                )}
            </Panel>

            {draft !== null && (
                <Panel labelledBy="rules-preview-title">
                    <PanelHeader id="rules-preview-title" title={t('admin.scoring.preview.title')} description={t('admin.scoring.preview.description')} />
                    <div className="flex flex-col gap-4 px-5 pt-3 pb-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <button type="button" className="btn btn-secondary" disabled={dirty || previewApi.pending} aria-describedby={dirty ? 'rules-preview-unsaved' : undefined} onClick={() => void runPreview()}>
                                {previewApi.pending ? t('admin.scoring.preview.running') : t('admin.scoring.preview.button')}
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                disabled={preview === null || dirty}
                                aria-describedby={preview === null ? 'rules-publish-needs' : undefined}
                                onClick={() => setPublishing(true)}
                            >
                                {t('admin.scoring.publish.button')}
                            </button>
                            {dirty && (
                                <span id="rules-preview-unsaved" className="text-sm text-ink-2">
                                    {t('admin.scoring.preview.unsaved')}
                                </span>
                            )}
                            {!dirty && preview === null && (
                                <span id="rules-publish-needs" className="text-sm text-ink-2">
                                    {t('admin.scoring.publish.needs_preview')}
                                </span>
                            )}
                        </div>
                        {previewApi.error !== null && (
                            <p role="alert" className="notice notice-bad text-bad">
                                {previewApi.error}
                            </p>
                        )}
                        <RulePreview rounds={preview} labels={labels} closed={rounds.closed} />
                    </div>
                </Panel>
            )}

            <RuleVersionHistory versions={versions} items={items} />

            <ConfirmWithReasonDialog
                open={publishing && draft !== null}
                title={draft === null ? '' : t('admin.scoring.publish.title', { version: draft.version })}
                consequence={summary()}
                confirmLabel={t('admin.scoring.publish.confirm')}
                onConfirm={async (reason) => {
                    if (draft === null) {
                        return { ok: true };
                    }
                    const result = await api.run('POST', `/admin/api/scoring-rules/${draft.id}/publish`, { reason });
                    if (!result.ok) {
                        return { ok: false, message: result.message, fieldError: result.fieldErrors.reason ?? null };
                    }
                    setPreview(null);
                    setFollowing(true);
                    setTries(0);
                    say(t('admin.scoring.publish.done', { version: draft.version }));
                    reload();

                    return { ok: true };
                }}
                onClose={() => setPublishing(false)}
            />
        </div>
    );
}
