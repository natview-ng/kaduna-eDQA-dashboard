import { Form } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import StateMark from '@/components/StateMark';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { update } from '@/routes/admin/user-password';

export type RecentLogin = { at: string; browser: string; ip_range: string | null; new_device: boolean };

export type AccountProps = {
    status?: string | null | undefined;
    logins: RecentLogin[];
    unlockCommand: string;
};

/**
 * My account (Phase 5 step 7): change the password (SECURITY.md §2: the policy and the last five
 * apply; saving ends your other sessions and is audited), your recent sign-ins (time WAT, browser,
 * the address only as a range, whether it was a new device), and where unlocking an account is
 * done: the console, never this page. The title comes from AdminLayout.
 */
export default function Account({ status, logins, unlockCommand }: AccountProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const head = 'bg-subtle px-3 py-2 text-left text-xs font-medium text-ink-2';

    return (
        <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
            <Panel labelledBy="password-title">
                <PanelHeader id="password-title" title={t('auth.settings.password_title')} />
                <div className="px-5 pt-3 pb-5">
                    {status && (
                        <p role="status" className="notice notice-ok mb-4">
                            {status}
                        </p>
                    )}
                    <Form
                        {...update.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        resetOnError={['password', 'password_confirmation', 'current_password']}
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <ErrorSummary errors={errors} />
                                <TextField name="current_password" type="password" label={t('auth.fields.current_password')} autoComplete="current-password" required error={errors.current_password} />
                                <TextField name="password" type="password" label={t('auth.fields.new_password')} hint={t('auth.reset.rules')} autoComplete="new-password" required error={errors.password} />
                                <TextField name="password_confirmation" type="password" label={t('auth.fields.confirm_password')} autoComplete="new-password" required error={errors.password_confirmation} />
                                <SubmitButton processing={processing}>{t('auth.settings.password_submit')}</SubmitButton>
                            </>
                        )}
                    </Form>
                </div>
            </Panel>

            <div className="flex flex-col gap-5">
                <Panel labelledBy="logins-title">
                    <PanelHeader id="logins-title" title={t('admin.account.logins.title')} description={t('admin.account.logins.description')} />
                    <div role="region" aria-label={t('admin.account.logins.caption')} tabIndex={0} className="relative mt-3 overflow-x-auto">
                        {logins.length === 0 ? (
                            <p className="px-5 pb-5 text-sm text-ink-2">{t('admin.account.logins.none')}</p>
                        ) : (
                            <table className="w-full text-sm">
                                <caption className="sr-only">{t('admin.account.logins.caption')}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" className={`${head} pl-5`}>{t('admin.account.logins.when')}</th>
                                        <th scope="col" className={head}>{t('admin.account.logins.browser')}</th>
                                        <th scope="col" className={head}>{t('admin.account.logins.ip')}</th>
                                        <th scope="col" className={`${head} pr-5`}>{t('admin.account.logins.new_device')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logins.map((login) => (
                                        <tr key={login.at} className="border-t border-rule">
                                            <th scope="row" className="py-2 pr-3 pl-5 text-left font-normal whitespace-nowrap">
                                                {months.length === 12 ? formatDateTime(login.at, months) : login.at}
                                            </th>
                                            <td className="px-3 py-2">{login.browser}</td>
                                            <td className="px-3 py-2 tabular-nums">{login.ip_range ?? t('admin.account.logins.unknown')}</td>
                                            <td className="py-2 pr-5 pl-3">
                                                <span className={`inline-flex items-center gap-1.5 ${login.new_device ? 'text-warn' : 'text-ink-2'}`}>
                                                    <StateMark mark={login.new_device ? '▲' : '○'} className="size-2" />
                                                    {login.new_device ? t('admin.account.logins.yes') : t('admin.account.logins.no')}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </Panel>

                <Panel labelledBy="unlock-title">
                    <PanelHeader id="unlock-title" title={t('admin.account.unlock.title')} level={2} />
                    <div className="px-5 pt-2 pb-5 text-sm">
                        <p>{t('admin.account.unlock.text')}</p>
                        <code className="mt-2 block rounded-md bg-subtle px-3 py-2 text-xs">{unlockCommand}</code>
                    </div>
                </Panel>
            </div>
        </div>
    );
}
