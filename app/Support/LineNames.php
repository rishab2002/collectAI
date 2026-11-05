<?php

namespace App\Support;

/**
 * Series names that are the line itself rather than a sub-series of it.
 *
 * "2009 New Models" is not a wave a car was sold in — it is a note that the
 * casting was new that year. Every car in it was a mainline car, and the wave
 * it actually belongs to is the segment printed up the right-hand edge of the
 * card ("HW J-Imports", "Factory Fresh"), which the wiki's Series column does
 * not carry. Filed as a sub-series it invents a hundred-odd waves that never
 * existed and buries the real ones.
 *
 * So these names collapse: a series matching the pattern is shown, grouped
 * and browsed as the line it sits under, exactly as a series that shares its
 * line's name already is.
 *
 * **Nothing is deleted.** The rows keep their releases and their provenance,
 * and a re-import finds them where it left them — which is the point, because
 * the importer keys a series by its name and `record_sources` allows one
 * provenance row per record, so merging the rows would either lose a source
 * key or break that. This is a presentation rule, applied everywhere a series
 * is presented, and `config/catalog_lines.collapse` is where it is edited.
 */
final class LineNames
{
    public static function pattern(): string
    {
        return (string) config('catalog_lines.collapse', '');
    }

    public static function isLineItself(?string $name): bool
    {
        $pattern = self::pattern();

        return $pattern !== '' && $name !== null && preg_match('/'.$pattern.'/i', $name) === 1;
    }

    /**
     * The same test in SQL, so the browse groups these the way the model
     * renders them. Postgres `~*` is a case-insensitive regex match.
     *
     * The pattern is written into the SQL rather than bound, which is the one
     * surprising thing here and is deliberate. Bound, the expression carries
     * a placeholder, and the placeholder in the SELECT is a *different*
     * parameter from the one in the GROUP BY — so although the two read
     * identically, Postgres sees two expressions and refuses to group by
     * either ("column s.name must appear in the GROUP BY clause"). The value
     * is a constant in `config/catalog_lines.php`, as much part of the
     * program as the SQL around it and never anything a visitor supplies;
     * quotes are escaped regardless.
     */
    public static function sql(string $column): string
    {
        $pattern = self::pattern();

        return $pattern === '' ? 'FALSE' : $column." ~* '".str_replace("'", "''", $pattern)."'";
    }

    /**
     * The sub-series name to group a release under: its own, or **NULL** when
     * the name collapses into its line.
     *
     * Null rather than the line's name so the expression carries one binding
     * and is textually identical in SELECT, GROUP BY and ORDER BY, which is
     * what Postgres needs to see to group by it at all. The caller swaps the
     * null for the line's name after the query — `??= $line->name`.
     */
    public static function effectiveName(string $seriesColumn): string
    {
        return 'CASE WHEN '.self::sql($seriesColumn)." THEN NULL ELSE {$seriesColumn} END";
    }
}
