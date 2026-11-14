import DimensionBar from '@/components/DimensionBar';
import StateMark from '@/components/StateMark';
import StatusLabel from '@/components/StatusLabel';
import { formatCount, formatThreshold } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { DimensionScores, StateSummary } from '@/types/dashboard';

export type HomeHeroProps = {
    hero: StateSummary;
    dimensions: DimensionScores;
    /** The month in view ("June 2026") when the Duration is one month (D-75), else null. */
    month?: string | null;
};

const DIMENSIONS = ['availability', 'consistency', 'validity'] as const;
const TICKS = Array.from({ length: 100 }, (_, i) => i + 1);

/**
 * Home's hero (ARCHITECTURE.md §9.2.1, D-89). Left: the average quality score with its band, how
 * far it shows below Strong (the server's strongGap), a tick meter to the Strong mark, and the
 * four counts, each a description list (term then value). Right: the three dimensions and the
 * overall as bars with the Strong mark, and a callout naming the weakest. The thresholds come from
 * the rule version, never written in.
 */
export default function HomeHero({ hero, dimensions, month = null }: HomeHeroProps) {
    const t = useTranslate();
    const hasData = hero.facilitiesVisited > 0;
    // A threshold missing from the props (no rule version) reads as a dash, never NaN.
    const strong = typeof hero.strongAt === 'number' ? formatThreshold(hero.strongAt) : '—';
    const weak = typeof hero.weakBelow === 'number' ? formatThreshold(hero.weakBelow) : '—';
    const visited = formatCount(hero.facilitiesVisited);
    const minis: [string, string, string | null][] = [
        [t('dashboard.home.facilities'), visited, null],
        [t('dashboard.home.lgas'), formatCount(hero.lgasCovered), hasData ? t('dashboard.home.lgas_of') : null],
        [t('dashboard.home.strong', { threshold: strong }), formatCount(hero.facilitiesStrong), hasData ? t('dashboard.home.of_visited', { count: visited }) : null],
        [t('dashboard.home.weak', { threshold: weak }), formatCount(hero.facilitiesWeak), hasData ? t('dashboard.home.of_visited', { count: visited }) : null],
    ];
    const weakest = dimensions.weakest;
    // The server's display text, laid out as a whole number and a smaller decimal ("86" ".1").
    const [whole, decimal] = hero.overall.display.split('.');
    // The meter is drawn from the server's headline (a whole number); it only repeats the figure.
    const filled = Number(hero.overall.headline);
    const showMeter = hasData && typeof hero.strongAt === 'number' && Number.isFinite(filled);

    return (
        <div className="overflow-hidden rounded-panel border border-rule bg-panel lg:grid lg:grid-cols-[340px_minmax(0,1fr)]">
            <div className="flex flex-col border-b border-rule bg-panel lg:border-r lg:border-b-0">
                <div className="px-6 pt-5 pb-5">
                    <dl>
                        <dt className="text-md font-semibold tracking-[-0.015em]">{t('dashboard.home.average_label')}</dt>
                        <dd className="m-0 mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span className="text-display leading-none font-semibold tracking-[-0.04em] text-ink tabular-nums">
                                {whole}
                                {decimal !== undefined && <span className="text-xl font-medium tracking-[-0.01em] text-ink-2">.{decimal}</span>}
                            </span>
                            <StatusLabel band={hero.overall.band} label={hero.overall.bandLabel} />
                        </dd>
                    </dl>
                    {hasData && hero.strongGap != null && <p className="mt-3 text-sm text-ink">{t('dashboard.home.strong_gap', { gap: hero.strongGap, threshold: strong })}</p>}
                    {hasData && hero.strongGap == null && hero.overall.band === 'strong' && <p className="mt-3 text-sm text-ink">{t('dashboard.home.strong_reached', { threshold: strong })}</p>}
                    <p className="mt-1 max-w-[40ch] text-sm leading-snug text-ink-2">
                        {hasData
                            ? month === null
                                ? t('dashboard.home.averaged_across', { count: visited })
                                : t('dashboard.home.averaged_across_month', { count: visited, month })
                            : t('dashboard.home.no_assessments')}
                    </p>
                    {showMeter && (
                        <div aria-hidden="true" className="mt-5">
                            <div className="flex h-7 items-end gap-[2px]">
                                {TICKS.map((tick) => (
                                    <span
                                        key={tick}
                                        className={`flex-1 rounded-xs forced-colors:bg-[CanvasText] ${
                                            tick === hero.strongAt ? 'h-full bg-warn-fill' : tick <= filled ? 'h-5 bg-deep' : 'h-5 bg-subtle forced-colors:bg-[Canvas]'
                                        }`}
                                    />
                                ))}
                            </div>
                            <div className="relative mt-1.5 h-4 text-2xs text-ink-2">
                                <span className="absolute left-0">0</span>
                                <span className="absolute -translate-x-1/2 whitespace-nowrap" style={{ left: `${hero.strongAt}%` }}>
                                    {t('dashboard.home.meter_strong', { threshold: strong })}
                                </span>
                            </div>
                        </div>
                    )}
                </div>
                <dl className="mt-auto grid grid-cols-2 gap-px border-t border-rule bg-rule">
                    {minis.map(([label, value, of]) => (
                        <div key={label} className="flex flex-col-reverse bg-panel px-5 py-3.5">
                            <dt className="mt-0.5 text-xs leading-tight text-ink-2">{label}</dt>
                            <dd className="m-0 text-xl font-semibold tabular-nums">
                                {value}
                                {of !== null && <span className="ml-1.5 text-xs font-normal text-ink-2">{of}</span>}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
            <section aria-labelledby="home-dimensions" className="min-w-0 px-6 pt-5 pb-6">
                <h2 id="home-dimensions" className="text-md tracking-[-0.015em]">
                    {t('dashboard.home.dimensions_title')}
                </h2>
                <p className="mt-1 max-w-[64ch] text-sm text-ink-2">{month === null ? t('dashboard.home.dimensions_intro') : t('dashboard.home.dimensions_intro_month', { month })}</p>
                <ul className="mt-4 flex flex-col divide-y divide-rule">
                    {DIMENSIONS.map((dimension) => (
                        <DimensionBar
                            key={dimension}
                            label={t(`enums.dimension.${dimension}`)}
                            help={t(`dashboard.home.dimension_help.${dimension}`)}
                            score={dimensions[dimension]}
                            strongAt={hero.strongAt}
                        />
                    ))}
                    <DimensionBar label={t('dashboard.home.overall')} help={t('dashboard.home.dimension_help.overall')} score={dimensions.overall} strongAt={hero.strongAt} />
                </ul>
                {typeof hero.strongAt === 'number' && <p className="mt-2 text-xs text-ink-2">{t('dashboard.home.marker_legend', { threshold: strong })}</p>}
                {weakest !== null && hasData && (
                    <p className="mt-3 flex items-start gap-2.5 rounded-md bg-subtle px-3.5 py-3 text-sm text-ink">
                        <StateMark mark="▲" className="mt-1 text-warn-fill" />
                        <span>{t('dashboard.home.weakest_note', { dimension: t(`enums.dimension.${weakest}`), score: dimensions[weakest].display })}</span>
                    </p>
                )}
            </section>
        </div>
    );
}
