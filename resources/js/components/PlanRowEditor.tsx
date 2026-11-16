import { useId, useState, type FormEvent } from 'react';
import { useAnnounce } from '@/components/Announcer';
import ScoreTag from '@/components/ScoreTag';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { ScoreValue } from '@/types/score';

export type PlanStatus = 'not_started' | 'in_progress' | 'done';

export type AdminPlanRow = {
    key: string;
    /** Null until "Generate plan for this round" stores it. */
    id: number | null;
    lga: { id: number; code: string; name: string };
    overall: ScoreValue;
    dimension: string;
    dimension_label: string;
    score: ScoreValue;
    action: string;
    /** The owner as the public plan shows it. */
    owner: string;
    owner_role: string;
    owner_role_other: string | null;
    owner_contact: string | null;
    due_date: string | null;
    status: PlanStatus;
    notes: string | null;
};

export type PlanRole = { key: string; label: string };

type Fields = { owner_role: string; owner_role_other: string; owner_contact: string; due_date: string; status: PlanStatus; notes: string };

const STATUSES: PlanStatus[] = ['not_started', 'in_progress', 'done'];

/**
 * One LGA's row of the admin plan (Phase 6 step 3): its figures and recommended action, and, once
 * stored, its follow-up saved inline: the owner (a role, or "Other role…" written out: a role or
 * unit, never a person), the contact and notes (admin-only, never public), the review date (on or
 * after today; a passed one is said in words) and the status. Each save is audited on the server.
 */
export default function PlanRowEditor({ row, roles, today }: { row: AdminPlanRow; roles: PlanRole[]; today: string }) {
    const t = useTranslate();
    const announce = useAnnounce();
    const id = useId();
    const api = useApiAction<{ data: Omit<Fields, 'owner_role_other' | 'owner_contact' | 'notes'> & { owner_role_other: string | null; owner_contact: string | null; notes: string | null } }>();
    const initial = (): Fields => ({
        owner_role: row.owner_role,
        owner_role_other: row.owner_role_other ?? '',
        owner_contact: row.owner_contact ?? '',
        due_date: row.due_date ?? '',
        status: row.status,
        notes: row.notes ?? '',
    });
    const [fields, setFields] = useState<Fields>(initial);
    const [saved, setSaved] = useState<Fields>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [outcome, setOutcome] = useState<string | null>(null);
    const set = <K extends keyof Fields>(key: K, value: Fields[K]) => setFields((current) => ({ ...current, [key]: value }));
    const label = 'mb-1 block text-xs font-medium text-ink-2';
    const past = fields.due_date !== '' && fields.due_date < today;

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (row.id === null) {
            return;
        }
        setErrors({});
        setOutcome(null);
        // Only what changed: an unchanged past review date may stay as it is.
        const changed = Object.fromEntries(Object.entries(fields).filter(([key, value]) => saved[key as keyof Fields] !== value));
        if (fields.owner_role === 'other') {
            changed.owner_role = 'other';
            changed.owner_role_other = fields.owner_role_other;
        }
        const result = await api.run('PATCH', `/admin/api/plan-actions/${row.id}`, changed);
        if (!result.ok) {
            setErrors(result.fieldErrors);

            return;
        }
        setSaved(fields);
        const text = t('admin.plan.saved', { lga: row.lga.name, dimension: row.dimension_label });
        setOutcome(text);
        announce(text);
    };
    const describe = (key: string, extra?: string) => [errors[key] === undefined ? null : `${id}-${key}-error`, extra ?? null].filter(Boolean).join(' ') || undefined;
    const error = (key: string) =>
        errors[key] !== undefined && (
            <p id={`${id}-${key}-error`} className="mt-1 text-xs text-bad">
                {errors[key]}
            </p>
        );

    return (
        <li className="border-t border-rule px-5 py-4 first:border-t-0">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="text-base font-semibold">{row.lga.name}</h3>
                <p className="flex items-center gap-2 text-sm">
                    <span className="text-ink-2">{t('admin.plan.table.overall')}</span>
                    <ScoreTag score={row.overall} />
                </p>
            </div>
            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                <span className="text-ink-2">{t('admin.plan.table.weakest')}:</span>
                <span className="font-medium">{row.dimension_label}</span>
                <ScoreTag score={row.score} />
            </p>
            <p className="mt-1 text-sm">
                <span className="text-ink-2">{t('admin.plan.table.action')}:</span> {row.action}
            </p>

            {row.id === null ? (
                <p className="mt-2 text-sm text-ink-2">
                    {t('admin.plan.unsaved')} {t('admin.plan.owner')}: {row.owner}
                </p>
            ) : (
                <form aria-label={t('admin.plan.owner_for', { lga: `${row.lga.name}, ${row.dimension_label}` })} noValidate onSubmit={(event) => void save(event)} className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label htmlFor={`${id}-owner`} className={label}>
                            {t('admin.plan.owner')}
                        </label>
                        <select id={`${id}-owner`} className="control w-full" value={fields.owner_role} aria-describedby={describe('owner_role', `${id}-owner-hint`)} onChange={(event) => set('owner_role', event.target.value)}>
                            {roles.map((role) => (
                                <option key={role.key} value={role.key}>
                                    {role.label}
                                </option>
                            ))}
                            <option value="other">{t('admin.plan.other')}</option>
                        </select>
                        <p id={`${id}-owner-hint`} className="mt-1 text-xs text-ink-2">
                            {t('admin.plan.owner_hint')}
                        </p>
                        {error('owner_role')}
                    </div>
                    {fields.owner_role === 'other' && (
                        <div>
                            <label htmlFor={`${id}-other`} className={label}>
                                {t('admin.plan.other_label')}
                            </label>
                            <input id={`${id}-other`} className="control w-full" maxLength={80} required value={fields.owner_role_other} aria-invalid={errors.owner_role_other !== undefined || undefined} aria-describedby={describe('owner_role_other', `${id}-owner-hint`)} onChange={(event) => set('owner_role_other', event.target.value)} />
                            {error('owner_role_other')}
                        </div>
                    )}
                    <div>
                        <label htmlFor={`${id}-contact`} className={label}>
                            {t('admin.plan.contact')}
                        </label>
                        <input id={`${id}-contact`} className="control w-full" maxLength={200} value={fields.owner_contact} aria-describedby={describe('owner_contact', `${id}-contact-hint`)} onChange={(event) => set('owner_contact', event.target.value)} />
                        <p id={`${id}-contact-hint`} className="mt-1 text-xs text-ink-2">
                            {t('admin.plan.contact_hint')}
                        </p>
                        {error('owner_contact')}
                    </div>
                    <div>
                        <label htmlFor={`${id}-review`} className={label}>
                            {t('admin.plan.review')}
                        </label>
                        <input id={`${id}-review`} type="date" className="control w-full" min={fields.due_date !== saved.due_date ? today : undefined} value={fields.due_date} aria-invalid={errors.due_date !== undefined || undefined} aria-describedby={describe('due_date', past ? `${id}-review-past` : undefined)} onChange={(event) => set('due_date', event.target.value)} />
                        {past && (
                            <p id={`${id}-review-past`} className="mt-1 text-xs text-warn">
                                {t('admin.plan.review_past')}
                            </p>
                        )}
                        {error('due_date')}
                    </div>
                    <div>
                        <label htmlFor={`${id}-status`} className={label}>
                            {t('admin.plan.status')}
                        </label>
                        <select id={`${id}-status`} className="control w-full" value={fields.status} onChange={(event) => set('status', event.target.value as PlanStatus)}>
                            {STATUSES.map((status) => (
                                <option key={status} value={status}>
                                    {t(`enums.plan_action_status.${status}`)}
                                </option>
                            ))}
                        </select>
                        {error('status')}
                    </div>
                    <div className="sm:col-span-2 xl:col-span-3">
                        <label htmlFor={`${id}-notes`} className={label}>
                            {t('admin.plan.notes')}
                        </label>
                        <textarea id={`${id}-notes`} className="control min-h-16 w-full" maxLength={2000} value={fields.notes} aria-describedby={describe('notes')} onChange={(event) => set('notes', event.target.value)} />
                        {error('notes')}
                    </div>
                    <div className="flex flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-3">
                        <button type="submit" className="btn btn-primary btn-sm" disabled={api.pending}>
                            {api.pending ? t('admin.plan.saving') : t('admin.plan.save')}
                        </button>
                        {outcome !== null && (
                            <p role="status" className="text-sm text-ok">
                                {outcome}
                            </p>
                        )}
                        {api.error !== null && Object.keys(errors).length === 0 && (
                            <p role="alert" className="text-sm text-bad">
                                {api.error}
                            </p>
                        )}
                    </div>
                </form>
            )}
        </li>
    );
}
