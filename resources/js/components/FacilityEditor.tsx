import { useEffect, useRef, useState, type FormEvent } from 'react';
import PublicImpactNote from '@/components/PublicImpactNote';
import { useTranslate } from '@/lib/i18n';
import type { LgaWithWards } from '@/types/admin';

export type FacilityFields = { code: string; name: string; lga: string; ward_id: string; level: string; ownership: string; lat: string; lng: string };

export type FacilityEditorProps = {
    /** Prefixes the inputs' ids, so two editors never share one. */
    idPrefix: string;
    initial: FacilityFields;
    lgas: LgaWithWards[];
    /** The code cannot change: an assessment holds it. */
    codeLocked?: boolean;
    /** Shows the public-impact note: an existing facility's name and place show publicly. */
    publicImpact?: boolean;
    errors: Partial<Record<keyof FacilityFields, string>>;
    pending: boolean;
    submitLabel: string;
    pendingLabel: string;
    onSubmit: (fields: FacilityFields) => void;
    onCancel?: () => void;
    /** Moves focus to the first field when it opens (the inline editor). */
    focusOnOpen?: boolean;
};

/**
 * The facility fields (Phase 5 step 5), for the inline editor and the add form: name, code
 * (locked once assessed), LGA then one of its wards (the LGA follows the ward on the server),
 * level, ownership and coordinates. Field errors sit beside their field.
 */
export default function FacilityEditor({ idPrefix, initial, lgas, codeLocked = false, publicImpact = false, errors, pending, submitLabel, pendingLabel, onSubmit, onCancel, focusOnOpen = false }: FacilityEditorProps) {
    const t = useTranslate();
    const [fields, setFields] = useState(initial);
    const first = useRef<HTMLInputElement>(null);
    const wards = lgas.find((lga) => lga.code === fields.lga)?.wards ?? [];

    useEffect(() => {
        if (focusOnOpen) {
            first.current?.focus();
        }
    }, [focusOnOpen]);

    const set = (key: keyof FacilityFields, value: string) => setFields((current) => ({ ...current, [key]: value, ...(key === 'lga' ? { ward_id: '' } : {}) }));
    const id = (key: string) => `${idPrefix}-${key}`;
    const describedBy = (key: keyof FacilityFields, extra?: string) => [errors[key] === undefined ? null : `${id(key)}-error`, extra ?? null].filter(Boolean).join(' ') || undefined;
    const error = (key: keyof FacilityFields) =>
        errors[key] !== undefined && (
            <p id={`${id(key)}-error`} className="mt-1 text-xs text-bad">
                {errors[key]}
            </p>
        );
    const label = 'mb-1 block text-xs font-medium text-ink-2';
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit(fields);
    };

    return (
        <form noValidate onSubmit={submit} className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="lg:col-span-2">
                    <label htmlFor={id('name')} className={label}>
                        {t('admin.facilities.table.name')}
                    </label>
                    <input ref={first} id={id('name')} className="control w-full" value={fields.name} required aria-invalid={errors.name !== undefined || undefined} aria-describedby={describedBy('name')} onChange={(event) => set('name', event.target.value)} />
                    {error('name')}
                </div>
                <div>
                    <label htmlFor={id('code')} className={label}>
                        {t('admin.facilities.table.code')}
                    </label>
                    <input
                        id={id('code')}
                        className="control w-full"
                        value={fields.code}
                        required
                        readOnly={codeLocked}
                        aria-readonly={codeLocked || undefined}
                        aria-invalid={errors.code !== undefined || undefined}
                        aria-describedby={describedBy('code', codeLocked ? `${id('code')}-locked` : undefined)}
                        onChange={(event) => set('code', event.target.value)}
                    />
                    {codeLocked && (
                        <p id={`${id('code')}-locked`} className="mt-1 text-xs text-ink-2">
                            {t('admin.facilities.edit.code_locked')}
                        </p>
                    )}
                    {error('code')}
                </div>
                <div>
                    <label htmlFor={id('level')} className={label}>
                        {t('admin.facilities.table.level')}
                    </label>
                    <select id={id('level')} className="control w-full" value={fields.level} aria-invalid={errors.level !== undefined || undefined} aria-describedby={describedBy('level')} onChange={(event) => set('level', event.target.value)}>
                        <option value="">{t('admin.facilities.add.choose')}</option>
                        {(['primary', 'secondary', 'tertiary'] as const).map((level) => (
                            <option key={level} value={level}>
                                {t(`enums.facility_level.${level}`)}
                            </option>
                        ))}
                    </select>
                    {error('level')}
                </div>
                <div>
                    <label htmlFor={id('lga')} className={label}>
                        {t('admin.facilities.table.lga')}
                    </label>
                    <select id={id('lga')} className="control w-full" value={fields.lga} onChange={(event) => set('lga', event.target.value)}>
                        <option value="">{t('admin.facilities.add.choose')}</option>
                        {lgas.map((lga) => (
                            <option key={lga.code} value={lga.code}>
                                {lga.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor={id('ward_id')} className={label}>
                        {t('admin.facilities.table.ward')}
                    </label>
                    <select id={id('ward_id')} className="control w-full" value={fields.ward_id} required aria-invalid={errors.ward_id !== undefined || undefined} aria-describedby={describedBy('ward_id')} onChange={(event) => set('ward_id', event.target.value)}>
                        <option value="">{t('admin.facilities.add.choose')}</option>
                        {wards.map((ward) => (
                            <option key={ward.id} value={String(ward.id)}>
                                {ward.name}
                            </option>
                        ))}
                    </select>
                    {error('ward_id')}
                </div>
                <div>
                    <label htmlFor={id('ownership')} className={label}>
                        {t('admin.facilities.table.ownership')}
                    </label>
                    <select id={id('ownership')} className="control w-full" value={fields.ownership} aria-invalid={errors.ownership !== undefined || undefined} aria-describedby={describedBy('ownership')} onChange={(event) => set('ownership', event.target.value)}>
                        <option value="">{t('admin.facilities.add.choose')}</option>
                        {(['public', 'private'] as const).map((ownership) => (
                            <option key={ownership} value={ownership}>
                                {t(`enums.ownership.${ownership}`)}
                            </option>
                        ))}
                    </select>
                    {error('ownership')}
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <div>
                        <label htmlFor={id('lat')} className={label}>
                            {t('admin.facilities.edit.lat')}
                        </label>
                        <input id={id('lat')} inputMode="decimal" className="control w-full" value={fields.lat} aria-invalid={errors.lat !== undefined || undefined} aria-describedby={describedBy('lat')} onChange={(event) => set('lat', event.target.value)} />
                        {error('lat')}
                    </div>
                    <div>
                        <label htmlFor={id('lng')} className={label}>
                            {t('admin.facilities.edit.lng')}
                        </label>
                        <input id={id('lng')} inputMode="decimal" className="control w-full" value={fields.lng} aria-invalid={errors.lng !== undefined || undefined} aria-describedby={describedBy('lng')} onChange={(event) => set('lng', event.target.value)} />
                        {error('lng')}
                    </div>
                </div>
            </div>
            {publicImpact && <PublicImpactNote>{t('admin.facilities.edit.public_impact')}</PublicImpactNote>}
            <div className="flex flex-wrap gap-2">
                <button type="submit" className="btn btn-primary" disabled={pending}>
                    {pending ? pendingLabel : submitLabel}
                </button>
                {onCancel !== undefined && (
                    <button type="button" className="btn btn-quiet" onClick={onCancel}>
                        {t('admin.facilities.edit.cancel')}
                    </button>
                )}
            </div>
        </form>
    );
}
