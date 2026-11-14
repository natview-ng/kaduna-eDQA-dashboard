import type { ReactNode, Ref } from 'react';

export type TopBarProps = {
    /** The header itself, so the shell can measure it for scroll-padding-top. */
    headerRef?: Ref<HTMLElement>;
    title: string | null;
    subtitle?: string | null;
    /** Receives focus when the page changes (not when a filter does). */
    titleRef?: Ref<HTMLHeadingElement>;
    /** The page tabs, on dashboard pages: a row of their own above the title. */
    nav?: ReactNode;
    /** The filter bar, on dashboard pages: beside the title, on the right. */
    children?: ReactNode;
    /** Beside the title: the account menu, in the admin layout only (the public site has none, D-78). */
    actions?: ReactNode;
};

/**
 * The top bar (sticky from 760px; on a phone it would cover most of the screen): the page tabs,
 * then the page's h1 and subtitle with the filters and any actions on the right (D-89).
 */
export default function TopBar({ title, subtitle, titleRef, headerRef, nav, children, actions }: TopBarProps) {
    return (
        <header ref={headerRef} className="z-20 border-b border-rule bg-panel rail:sticky rail:top-0">
            {nav !== undefined && <div className="border-b border-rule px-4 pt-1 rail:px-7">{nav}</div>}
            <div className="flex flex-wrap items-end gap-x-6 gap-y-3 px-4 py-4 rail:px-7 rail:py-5">
                <div className="min-w-0 flex-1 basis-72">
                    {title !== null && (
                        <h1 ref={titleRef} tabIndex={-1} className="text-lg font-semibold tracking-[-0.015em] text-ink outline-offset-4">
                            {title}
                        </h1>
                    )}
                    {subtitle !== undefined && subtitle !== null && <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{subtitle}</p>}
                </div>
                {children}
                {actions}
            </div>
        </header>
    );
}
