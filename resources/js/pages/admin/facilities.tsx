import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import CascadePanel from '@/components/CascadePanel';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import FacilityEditor, { type FacilityFields } from '@/components/FacilityEditor';
import MasterListFilters from '@/components/MasterListFilters';
import MasterListTable from '@/components/MasterListTable';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import StateMark from '@/components/StateMark';
import WardManager from '@/components/WardManager';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { CascadeStatus, FacilityListFilters, FacilityListRow, LgaWithWards, Paginated } from '@/types/admin';

export type AdminFacilitiesProps = {
    facilities: Paginated<FacilityListRow>;
    facilityFilters: FacilityListFilters;
    lgas: LgaWithWards[];
    cascade: CascadeStatus;
};

type Errors = Partial<Record<keyof FacilityFields, string>>;
type Pending = { title: string; consequence: string; confirm: string; danger: boolean; send: (reason: string) => Promise<ConfirmOutcome> };

const EMPTY: FacilityFields = { code: '', name: '', lga: '', ward_id: '', level: '', ownership: '', lat: '', lng: '' };

/** The API body for a facility's fields: coordinates empty means none. */
const body = (fields: FacilityFields, includeCode: boolean) => ({
    ...(includeCode ? { code: fields.code.trim() } : {}),
    name: fields.name.trim(),
    ward_id: fields.ward_id === '' ? null : Number(fields.ward_id),
    level: fields.level,
    ownership: fields.ownership,
    lat: fields.lat.trim() === '' ? null : fields.lat.trim(),
    lng: fields.lng.trim() === '' ? null : fields.lng.trim(),
});

/**
 * The Facility list page (ARCHITECTURE.md §9.3): the master list, which every
 * submission is validated against. Search and filter it (server-side, in the URL), edit a facility
 * inline (the public-impact note says names and places show publicly, published rounds included),
 * add one, deactivate or reactivate it with a reason (never delete), and manage the wards of an
 * LGA; the 23 LGAs are fixed and the page says so. While a change has not reached the form's
 * lists, the facility media file is marked out of date until it is regenerated here.
 */
export default function AdminFacilities({ facilities, facilityFilters: filters, lgas, cascade }: AdminFacilitiesProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const api = useApiAction<{ data: { name: string } }>();
    const add = useApiAction<{ data: { name: string } }>();
    const [editing, setEditing] = useState<number | null>(null);
    const [editErrors, setEditErrors] = useState<Errors>({});
    const [addErrors, setAddErrors] = useState<Errors>({});
    const [addKey, setAddKey] = useState(0);
    const [pending, setPending] = useState<Pending | null>(null);
    const [outcome, setOutcome] = useState<string | null>(null);
    // The media-file panel sits at the top while the file is out of date, and stays there for the
    // rest of the visit once it has been: regenerating clears out_of_date, and moving the panel
    // would remount it and lose the counts and the download link just produced.
    const [cascadeOnTop, setCascadeOnTop] = useState(cascade.out_of_date);
    if (cascade.out_of_date && !cascadeOnTop) {
        setCascadeOnTop(true);
    }
    const cascadePanel = <CascadePanel howUrl={null} outOfDate={cascade.out_of_date} onRegenerated={() => router.reload({ only: ['cascade'] })} />;

    const reload = () => router.reload({ only: ['facilities', 'lgas', 'cascade'] });
    const done = (text: string) => {
        setOutcome(text);
        announce(text);
        reload();
    };
    const visit = (next: Partial<FacilityListFilters>) => {
        const query = { ...filters, ...next };
        setEditing(null);
        router.get('/admin/facilities', Object.fromEntries(Object.entries(query).filter(([key, value]) => value !== null && value !== '' && !(key === 'page' && value === 1))), {
            only: ['facilities', 'facilityFilters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => announce(t('admin.facilities.filters.announce', { total: formatCount((page.props.facilities as Paginated<unknown> | undefined)?.total ?? 0) })),
        });
    };
    /** 422 field errors as they are; a 409 refusal about the code on the code field. */
    const errorsOf = (result: { fieldErrors: Record<string, string>; code: string | null; message: string }): Errors => ({
        ...(result.fieldErrors as Errors),
        ...(result.code === 'code_locked' || result.code === 'code_taken' ? { code: result.message } : {}),
    });

    const save = async (row: FacilityListRow, fields: FacilityFields) => {
        setEditErrors({});
        const result = await api.run('PATCH', `/admin/api/facilities/${row.id}`, body(fields, !row.code_locked && fields.code.trim() !== row.code));
        if (!result.ok) {
            setEditErrors(errorsOf(result));

            return;
        }
        setEditing(null);
        done(t('admin.facilities.edit.saved', { name: result.data.data.name }));
    };

    const create = async (fields: FacilityFields) => {
        setAddErrors({});
        const result = await add.run('POST', '/admin/api/facilities', body(fields, true));
        if (!result.ok) {
            setAddErrors(errorsOf(result));

            return;
        }
        setAddKey((key) => key + 1);
        done(t('admin.facilities.add.added', { name: result.data.data.name }));
    };

    const toggleActive = (row: FacilityListRow) => {
        const action = row.active ? 'deactivate' : 'reactivate';
        const warning = row.active && row.open_round_visits > 0 ? [t('admin.facilities.deactivate.open_round', { count: formatCount(row.open_round_visits) })] : [];
        setPending({
            title: t(`admin.facilities.${action}.title`, { name: row.name }),
            consequence: [...warning, t(`admin.facilities.${action}.consequence`)].join(' '),
            confirm: t(`admin.facilities.${action}.confirm`),
            danger: row.active,
            send: async (reason) => {
                const result = await api.run('POST', `/admin/api/facilities/${row.id}/${action}`, { reason });
                if (!result.ok) {
                    return { ok: false, message: result.message, fieldError: result.fieldErrors.reason ?? null };
                }
                done(t(`admin.facilities.${action}.done`, { name: row.name }));

                return { ok: true };
            },
        });
    };

    return (
        <div className="flex flex-col gap-5">
            <p className="flex items-start gap-2 text-sm text-ink-2">
                <StateMark mark="■" className="mt-1 text-ink-3" />
                <span>{t('admin.facilities.fixed_lgas')}</span>
            </p>

            {outcome !== null && (
                <p role="status" className="notice notice-ok">
                    {outcome}
                </p>
            )}

            {cascadeOnTop && cascadePanel}

            <Panel labelledBy="facilities-title">
                <PanelHeader id="facilities-title" title={t('admin.facilities.table.title')} />
                <MasterListFilters filters={filters} lgas={lgas} onChange={visit} />
                <MasterListTable
                    facilities={facilities}
                    lgas={lgas}
                    editing={editing}
                    editErrors={editErrors}
                    saving={api.pending}
                    onEdit={(row) => {
                        setEditErrors({});
                        setEditing(row?.id ?? null);
                    }}
                    onSave={(row, fields) => void save(row, fields)}
                    onToggleActive={toggleActive}
                    onPage={(page) => visit({ page })}
                />
            </Panel>

            <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <Panel labelledBy="add-facility-title">
                    <PanelHeader id="add-facility-title" title={t('admin.facilities.add.title')} description={t('admin.facilities.add.description')} />
                    <div className="px-5 pt-3 pb-5">
                        <FacilityEditor
                            key={addKey}
                            idPrefix="add-facility"
                            initial={EMPTY}
                            lgas={lgas}
                            errors={addErrors}
                            pending={add.pending}
                            submitLabel={t('admin.facilities.add.submit')}
                            pendingLabel={t('admin.facilities.add.submitting')}
                            onSubmit={(fields) => void create(fields)}
                        />
                    </div>
                </Panel>
                <WardManager lgas={lgas} onChanged={done} />
            </div>

            {!cascadeOnTop && cascadePanel}

            <ConfirmWithReasonDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                consequence={pending?.consequence ?? ''}
                confirmLabel={pending?.confirm ?? ''}
                danger={pending?.danger ?? false}
                onConfirm={(reason) => (pending === null ? Promise.resolve({ ok: true }) : pending.send(reason))}
                onClose={() => setPending(null)}
            />
        </div>
    );
}
