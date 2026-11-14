import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { LgaWithWards } from '@/types/admin';

export type WardManagerProps = {
    lgas: LgaWithWards[];
    /** A ward was added or renamed: the page says so and reloads. */
    onChanged: (message: string) => void;
};

/**
 * Wards within an LGA (Phase 5 step 5): choose one of the 23 LGAs, rename any of its wards or add
 * one, each audited on the server. The LGAs themselves are fixed, and the page says so beside
 * this panel. A refusal (a name the LGA already has) shows beside its field.
 */
export default function WardManager({ lgas, onChanged }: WardManagerProps) {
    const t = useTranslate();
    const ids = { lga: useId(), add: useId() };
    const api = useApiAction<{ data: { id: number; name: string } }>();
    const [lgaCode, setLgaCode] = useState(lgas[0]?.code ?? '');
    const [renaming, setRenaming] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [added, setAdded] = useState('');
    const [error, setError] = useState<{ field: 'add' | 'rename'; message: string } | null>(null);
    const lga = lgas.find((item) => item.code === lgaCode) ?? null;
    const renameInput = useRef<HTMLInputElement>(null);

    // The rename box takes focus when it opens.
    useEffect(() => {
        if (renaming !== null) {
            renameInput.current?.focus();
        }
    }, [renaming]);

    const add = async (event: FormEvent) => {
        event.preventDefault();
        if (lga === null) {
            return;
        }
        setError(null);
        const result = await api.run('POST', '/admin/api/wards', { lga_id: lga.id, name: added });
        if (!result.ok) {
            setError({ field: 'add', message: result.fieldErrors.name ?? result.message });

            return;
        }
        setAdded('');
        onChanged(t('admin.facilities.wards.added', { ward: result.data.data.name, lga: lga.name }));
    };

    const rename = async (event: FormEvent, wardId: number) => {
        event.preventDefault();
        setError(null);
        const result = await api.run('PATCH', `/admin/api/wards/${wardId}`, { name });
        if (!result.ok) {
            setError({ field: 'rename', message: result.fieldErrors.name ?? result.message });

            return;
        }
        setRenaming(null);
        onChanged(t('admin.facilities.wards.renamed', { ward: result.data.data.name }));
    };

    return (
        <Panel labelledBy="wards-title">
            <PanelHeader id="wards-title" title={t('admin.facilities.wards.title')} description={t('admin.facilities.wards.description')} />
            <div className="px-5 pt-3 pb-5">
                <div className="field">
                    <label htmlFor={ids.lga}>{t('admin.facilities.wards.lga')}</label>
                    <select id={ids.lga} value={lgaCode} onChange={(event) => { setLgaCode(event.target.value); setRenaming(null); setError(null); }}>
                        {lgas.map((item) => (
                            <option key={item.code} value={item.code}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>

                {lga !== null && (
                    <>
                        <ul aria-label={t('admin.facilities.wards.caption', { lga: lga.name })} className="mt-3 divide-y divide-rule border-y border-rule">
                            {lga.wards.length === 0 && <li className="py-2 text-sm text-ink-2">{t('admin.facilities.wards.none')}</li>}
                            {lga.wards.map((ward) => (
                                <li key={ward.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                                    {renaming === ward.id ? (
                                        <form className="flex flex-1 flex-wrap items-start gap-2" onSubmit={(event) => void rename(event, ward.id)}>
                                            <div>
                                                <label htmlFor={`rename-ward-${ward.id}`} className="sr-only">
                                                    {t('admin.facilities.wards.rename_label', { ward: ward.name })}
                                                </label>
                                                <input
                                                    id={`rename-ward-${ward.id}`}
                                                    className="control"
                                                    value={name}
                                                    ref={renameInput}
                                                    aria-invalid={error?.field === 'rename' || undefined}
                                                    aria-describedby={error?.field === 'rename' ? `rename-ward-${ward.id}-error` : undefined}
                                                    onChange={(event) => setName(event.target.value)}
                                                />
                                                {error?.field === 'rename' && (
                                                    <p id={`rename-ward-${ward.id}-error`} className="mt-1 text-xs text-bad">
                                                        {error.message}
                                                    </p>
                                                )}
                                            </div>
                                            <button type="submit" className="btn btn-primary btn-sm" disabled={api.pending}>
                                                {t('admin.facilities.wards.save')}
                                            </button>
                                            <button type="button" className="btn btn-quiet btn-sm" onClick={() => setRenaming(null)}>
                                                {t('admin.facilities.wards.cancel')}
                                            </button>
                                        </form>
                                    ) : (
                                        <>
                                            <span className="flex-1">{ward.name}</span>
                                            <button type="button" className="btn btn-quiet btn-sm" onClick={() => { setRenaming(ward.id); setName(ward.name); setError(null); }}>
                                                {t('admin.facilities.wards.rename')} <span className="sr-only">{ward.name}</span>
                                            </button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <form className="mt-3 flex flex-wrap items-start gap-2" onSubmit={(event) => void add(event)}>
                            <div>
                                <label htmlFor={ids.add} className="mb-1 block text-xs font-medium text-ink-2">
                                    {t('admin.facilities.wards.add_label', { lga: lga.name })}
                                </label>
                                <input
                                    id={ids.add}
                                    className="control"
                                    value={added}
                                    aria-invalid={error?.field === 'add' || undefined}
                                    aria-describedby={error?.field === 'add' ? `${ids.add}-error` : undefined}
                                    onChange={(event) => setAdded(event.target.value)}
                                />
                                {error?.field === 'add' && (
                                    <p id={`${ids.add}-error`} className="mt-1 text-xs text-bad">
                                        {error.message}
                                    </p>
                                )}
                            </div>
                            <button type="submit" className="btn btn-secondary mt-5" disabled={api.pending || added.trim() === ''}>
                                {t('admin.facilities.wards.add')}
                            </button>
                        </form>
                    </>
                )}
            </div>
        </Panel>
    );
}
