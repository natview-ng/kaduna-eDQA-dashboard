import AdminTable from '@/components/AdminTable';
import type { DataTableColumn } from '@/components/DataTable';
import FacilityEditor, { type FacilityFields } from '@/components/FacilityEditor';
import ScoreTag from '@/components/ScoreTag';
import StateMark from '@/components/StateMark';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { FacilityListRow, LgaWithWards, Paginated } from '@/types/admin';

export type MasterListTableProps = {
    facilities: Paginated<FacilityListRow>;
    lgas: LgaWithWards[];
    /** The facility being edited inline, if any. */
    editing: number | null;
    editErrors: Partial<Record<keyof FacilityFields, string>>;
    saving: boolean;
    onEdit: (row: FacilityListRow | null) => void;
    onSave: (row: FacilityListRow, fields: FacilityFields) => void;
    onToggleActive: (row: FacilityListRow) => void;
    onPage: (page: number) => void;
};

export const fieldsOf = (row: FacilityListRow): FacilityFields => ({
    code: row.code,
    name: row.name,
    lga: row.lga.code,
    ward_id: String(row.ward.id),
    level: row.level,
    ownership: row.ownership,
    lat: row.lat ?? '',
    lng: row.lng ?? '',
});

/**
 * The master list (Phase 5 step 5), one server page at a time: code, name, ward, LGA, level,
 * ownership, status in words with a shape, visits and the latest score (the server's). Edit opens
 * the facility's fields in a row under it, with the public-impact note; Deactivate and Reactivate
 * ask for a reason. Never a delete.
 */
export default function MasterListTable({ facilities, lgas, editing, editErrors, saving, onEdit, onSave, onToggleActive, onPage }: MasterListTableProps) {
    const t = useTranslate();
    const key = (name: string) => t(`admin.facilities.table.${name}`);
    const columns: DataTableColumn[] = [
        { key: 'code', header: key('code') },
        { key: 'name', header: key('name') },
        { key: 'ward', header: key('ward') },
        { key: 'lga', header: key('lga') },
        { key: 'level', header: key('level') },
        { key: 'ownership', header: key('ownership') },
        { key: 'active', header: key('active') },
        { key: 'visits', header: key('visits'), align: 'end' },
        { key: 'latest', header: key('latest'), align: 'end' },
        { key: 'actions', header: key('actions') },
    ];

    return (
        <AdminTable
            caption={key('caption')}
            captionHidden
            columns={columns}
            empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{key('none')}</p>}
            meta={{ page: facilities.current_page, lastPage: facilities.last_page, from: facilities.from ?? 0, to: facilities.to ?? 0, total: facilities.total }}
            onPage={onPage}
            labels={{ showing: (figures) => t('admin.facilities.table.showing', figures), none: key('showing_none'), nav: key('pages') }}
        >
            {facilities.data.flatMap((row) => {
                const forName = (
                    <>
                        {' '}
                        <span className="sr-only">{row.name}</span>
                    </>
                );
                const line = (
                    <tr key={row.id} className="border-b border-rule last:border-b-0">
                        <td className="px-3 py-2 text-xs whitespace-nowrap text-ink-2 tabular-nums">{row.code}</td>
                        <th scope="row" className="px-3 py-2 text-left font-medium">
                            {row.name}
                        </th>
                        <td className="px-3 py-2">{row.ward.name}</td>
                        <td className="px-3 py-2 whitespace-nowrap">{row.lga.name}</td>
                        <td className="px-3 py-2">{t(`enums.facility_level.${row.level}`)}</td>
                        <td className="px-3 py-2">{t(`enums.ownership.${row.ownership}`)}</td>
                        <td className="px-3 py-2 whitespace-nowrap">
                            <span className={`inline-flex items-center gap-1.5 text-sm ${row.active ? 'text-ok' : 'text-ink-2'}`}>
                                <StateMark mark={row.active ? '●' : '○'} className="size-2" />
                                {row.active ? key('is_active') : key('is_inactive')}
                            </span>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.visits)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">
                            {row.latest === null ? (
                                <span className="text-sm text-ink-2">{key('no_score')}</span>
                            ) : (
                                <>
                                    <ScoreTag score={row.latest.score} />
                                    <span className="block text-xs text-ink-2">{row.latest.round}</span>
                                </>
                            )}
                        </td>
                        <td className="px-3 py-2">
                            <div className="flex flex-wrap gap-1.5">
                                <button type="button" className="btn btn-secondary btn-sm" aria-expanded={editing === row.id} onClick={() => onEdit(editing === row.id ? null : row)}>
                                    {key('edit')}
                                    {forName}
                                </button>
                                <button type="button" className="btn btn-quiet btn-sm" onClick={() => onToggleActive(row)}>
                                    {row.active ? key('deactivate') : key('reactivate')}
                                    {forName}
                                </button>
                            </div>
                        </td>
                    </tr>
                );

                return editing === row.id
                    ? [
                          line,
                          <tr key={`${row.id}-edit`} className="border-b border-rule bg-paper/60">
                              <td colSpan={columns.length} className="px-5 py-4">
                                  <h3 className="mb-3 text-sm font-semibold">{t('admin.facilities.edit.legend', { name: row.name })}</h3>
                                  <FacilityEditor
                                      idPrefix={`edit-facility-${row.id}`}
                                      initial={fieldsOf(row)}
                                      lgas={lgas}
                                      codeLocked={row.code_locked}
                                      publicImpact
                                      errors={editErrors}
                                      pending={saving}
                                      submitLabel={t('admin.facilities.edit.save')}
                                      pendingLabel={t('admin.facilities.edit.saving')}
                                      onSubmit={(fields) => onSave(row, fields)}
                                      onCancel={() => onEdit(null)}
                                      focusOnOpen
                                  />
                              </td>
                          </tr>,
                      ]
                    : [line];
            })}
        </AdminTable>
    );
}
