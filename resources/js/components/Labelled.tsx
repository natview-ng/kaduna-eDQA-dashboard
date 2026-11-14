import type { ReactNode } from 'react';

export type LabelledProps = {
    /** The control's id: the label points at it. */
    id: string;
    label: string;
    children: ReactNode;
    /** A compact filter (D-89): the label inside the border, before an unstyled select. */
    inline?: boolean;
};

/** A small label for one form control: above it (the bulk bar), or inside a filter field. */
export default function Labelled({ id, label, children, inline = false }: LabelledProps) {
    if (inline) {
        return (
            <div className="field">
                <label htmlFor={id}>{label}</label>
                {children}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-xs text-ink-2">
                {label}
            </label>
            {children}
        </div>
    );
}
