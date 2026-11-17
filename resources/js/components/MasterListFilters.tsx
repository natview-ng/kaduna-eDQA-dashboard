import { useEffect, useId, useRef, useState } from 'react';
import Labelled from '@/components/Labelled';
import { useTranslate } from '@/lib/i18n';
import type { FacilityListFilters, LgaWithWards } from '@/types/admin';

export type MasterListFiltersProps = {
    filters: FacilityListFilters;
    lgas: LgaWithWards[];
    onChange: (next: Partial<FacilityListFilters>) => void;
};

const DEBOUNCE_MS = 300;

/**
 * The Facility list's search and filters: one search box over name, code, ward
 * and LGA (the server searches with pg_trgm), then LGA, ward (of the chosen LGA), level,
 * ownership and status, each a compact field. Typing waits 300 ms; a select applies at once. The
 * page keeps them in the URL.
 */
export default function MasterListFilters({ filters, lgas, onChange }: MasterListFiltersProps) {
    const t = useTranslate();
    const ids = { q: useId(), lga: useId(), ward: useId(), level: useId(), ownership: useId(), active: useId() };
    const [q, setQ] = useState(filters.q ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const wards = lgas.find((lga) => lga.code === filters.lga)?.wards ?? [];
    const anySet = [filters.q, filters.lga, filters.ward, filters.level, filters.ownership, filters.active].some((value) => value !== null && value !== '');

    useEffect(() => () => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
    }, []);

    const search = (value: string) => {
        setQ(value);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => onChange({ q: value === '' ? null : value, page: 1 }), DEBOUNCE_MS);
    };
    const all = <option value="">{t('admin.facilities.filters.all')}</option>;

    return (
        <form role="search" aria-label={t('admin.facilities.filters.label')} className="flex flex-wrap items-center gap-2 px-5 pt-1 pb-4" onSubmit={(event) => event.preventDefault()}>
            <div className="field min-w-64 flex-1 sm:flex-none">
                <label htmlFor={ids.q}>{t('admin.facilities.filters.search')}</label>
                <input
                    id={ids.q}
                    type="search"
                    placeholder={t('admin.facilities.filters.search_placeholder')}
                    value={q}
                    onChange={(event) => search(event.target.value)}
                />
            </div>
            <Labelled inline id={ids.lga} label={t('admin.facilities.filters.lga')}>
                <select id={ids.lga} value={filters.lga ?? ''} onChange={(event) => onChange({ lga: event.target.value || null, ward: null, page: 1 })}>
                    {all}
                    {lgas.map((lga) => (
                        <option key={lga.code} value={lga.code}>
                            {lga.name}
                        </option>
                    ))}
                </select>
            </Labelled>
            {filters.lga !== null && (
                <Labelled inline id={ids.ward} label={t('admin.facilities.filters.ward')}>
                    <select id={ids.ward} value={filters.ward === null ? '' : String(filters.ward)} onChange={(event) => onChange({ ward: event.target.value === '' ? null : Number(event.target.value), page: 1 })}>
                        {all}
                        {wards.map((ward) => (
                            <option key={ward.id} value={String(ward.id)}>
                                {ward.name}
                            </option>
                        ))}
                    </select>
                </Labelled>
            )}
            <Labelled inline id={ids.level} label={t('admin.facilities.filters.level')}>
                <select id={ids.level} value={filters.level ?? ''} onChange={(event) => onChange({ level: event.target.value || null, page: 1 })}>
                    {all}
                    {(['primary', 'secondary', 'tertiary'] as const).map((level) => (
                        <option key={level} value={level}>
                            {t(`enums.facility_level.${level}`)}
                        </option>
                    ))}
                </select>
            </Labelled>
            <Labelled inline id={ids.ownership} label={t('admin.facilities.filters.ownership')}>
                <select id={ids.ownership} value={filters.ownership ?? ''} onChange={(event) => onChange({ ownership: event.target.value || null, page: 1 })}>
                    {all}
                    {(['public', 'private'] as const).map((ownership) => (
                        <option key={ownership} value={ownership}>
                            {t(`enums.ownership.${ownership}`)}
                        </option>
                    ))}
                </select>
            </Labelled>
            <Labelled inline id={ids.active} label={t('admin.facilities.filters.active')}>
                <select id={ids.active} value={filters.active ?? ''} onChange={(event) => onChange({ active: event.target.value || null, page: 1 })}>
                    {all}
                    <option value="yes">{t('admin.facilities.filters.active_yes')}</option>
                    <option value="no">{t('admin.facilities.filters.active_no')}</option>
                </select>
            </Labelled>
            {anySet && (
                <button
                    type="button"
                    className="btn btn-quiet btn-sm"
                    onClick={() => {
                        setQ('');
                        onChange({ q: null, lga: null, ward: null, level: null, ownership: null, active: null, page: 1 });
                    }}
                >
                    {t('admin.facilities.filters.clear')}
                </button>
            )}
        </form>
    );
}
