<?php

/*
|--------------------------------------------------------------------------
| Main lines: how sub-series are grouped for browsing
|--------------------------------------------------------------------------
|
| The wikis name the series a car was sold in ("HW Art Cars", "1-100: Sports
| Cars") but not the main line it belongs to ("Mainline", Matchbox's
| "Mainline"), and a collector browses brand → line → sub-series → car. This is
| the editorial list of lines, per wiki, and how a series is placed in one.
| `catalog:classify-series` applies it; edit here and re-run with --reclassify.
|
| A series is checked against each line **in order**, and the first match
| wins. It matches a line when its own name matches one of `names` (regexes),
| or its wiki article carries one of `categories` (exact, case-insensitive).
| Order is the whole design: a series is often in several categories at once
| ("Mainline Segment" and "Wal-Mart Exclusive"), so specific lines — Treasure
| Hunts, the premium lines — come before broad ones. Anything unmatched goes to
| `other`, which keeps it browsable rather than lost.
|
| A series whose name *is* a line's name ("Mainline", "Car Culture") becomes
| that line rather than a child of it.
|
| Every list below was tuned against the full 2026-10 Hot Wheels and 2026-09
| Matchbox dumps; the classifier's --dry-run reports what stays in `other` and
| the categories it carries, which is what to read before adding a rule.
|
*/

return [

    'other' => 'Other Series',

    /*
    | Series names that are the line itself, not a wave inside it.
    |
    | "2009 New Models" is not a wave a car was sold in, it is a note that the
    | casting was new that year — every car in it is a mainline car, and the
    | wave it really belongs to is the segment printed up the right-hand edge
    | of the card, which the wiki's Series column does not carry. Filed as
    | sub-series these invent a hundred-odd waves that never existed and bury
    | the real ones, so they are shown and grouped as their line instead.
    |
    | A POSIX regex, used case-insensitively by Postgres (`~*`) and by PHP
    | alike — see App\Support\LineNames. Nothing is deleted: the rows keep
    | their releases and their provenance, and a re-import finds them where it
    | left them. Qualified names are deliberately NOT caught: "2005 First
    | Editions: Realistix" and "2006 First Editions 5-Pack" say something more
    | than "new this year" and stay as sub-series.
    */
    'collapse' => '^[[:space:]]*([0-9]{4}[[:space:]]+)?(new models?|first editions?)[[:space:]]*$',

    // An unmatched series whose releases mostly carry a collector number in the
    // year's run (≥70%, at least 3 releases) is a mainline segment. See
    // SeriesClassifier::lineFor() for the measurement behind the bar.
    'numbered_fallback' => [
        'hot-wheels' => 'Mainline',
        'matchbox' => 'Mainline',
    ],

    'hot-wheels' => [
        ['name' => 'Treasure Hunts',
            'names' => ['/treasure hunt/i'],
            'categories' => ['Treasure Hunts', 'Super Treasure Hunts', 'Treasure Hunt']],
        ['name' => 'Red Line Club',
            'names' => ['/\bRLC\b/', '/red ?line club/i', '/hotwheelscollectors/i'],
            'categories' => ['Red Line Club', 'RLC Exclusives', 'HotWheelsCollectors.com Series', 'RLC']],
        ['name' => 'Conventions & Events',
            'names' => ['/convention/i', '/nationals/i'],
            'categories' => ['Conventions', 'Hot Wheels Collectors Convention', 'Hot Wheels Collectors Conventions', 'Events']],
        ['name' => 'Car Culture',
            'names' => ['/^car culture\b/i'],
            'categories' => ['Car Culture', 'Car Culture by Year']],
        ['name' => 'Pop Culture',
            'names' => ['/^pop culture\b/i'],
            'categories' => ['Pop Culture']],
        ['name' => 'Boulevard',
            'names' => ['/boulevard/i'],
            'categories' => ['Hot Wheels Boulevard', 'Hot Wheels Boulevard by Year']],
        ['name' => 'Fast & Furious',
            'names' => ['/fast (&|and) furious/i'],
            'categories' => ['Fast & Furious Series']],
        ['name' => 'Mystery Models',
            'names' => ['/mystery (models?|cars?)/i'],
            'categories' => ['Mystery Models']],
        ['name' => 'Mainline',
            'names' => ['/^mainline$/i', '/first editions?/i', '/new models/i'],
            'categories' => ['Mainline Segment', 'First Editions', 'New Models']],
        ['name' => 'Multi-Packs',
            'names' => ['/\d+-?\s?packs?\b/i', '/multi-?packs?/i', '/gift pack/i'],
            'categories' => ['5-Packs', 'Multi-Packs', 'Multipack Exclusive', '2-Packs', '3-Packs', '4-Packs', '9-Packs', '10-Packs', '20-Packs', 'Gift Pack']],
        ['name' => 'Seasonal',
            'names' => ['/halloween|easter|holiday|christmas|valentine|spring|winter|summer/i'],
            'categories' => ['Holiday Cars', 'Halloween', 'Easter', 'Holiday Hot Rods']],
        ['name' => 'Track Sets & Playsets',
            'names' => ['/track set|playset|play set/i'],
            'categories' => ['Track Sets', 'Playsets']],
        ['name' => 'Premium',
            'names' => [],
            'categories' => ['Premium', 'Elite 64', 'Hot Wheels Premium', 'Retro Entertainment', 'Team Transport']],
        ['name' => 'Promotions & Exclusives',
            'names' => ['/exclusive|promo/i'],
            'categories' => ['Promotions', 'Wal-Mart Exclusive', 'Target Exclusive', 'Toys R Us Exclusive', 'Kmart Exclusive']],
        ['name' => 'Color Changers',
            'names' => ['/color shifters|color reveal|colour shifters/i'],
            'categories' => ['Color Shifters']],
    ],

    // Tomica and Mini GT series *are* their lines (one per toy line), so each
    // rule maps a series to itself — classify-series then leaves them at the top
    // of the tree rather than moving them into Other.
    'tomica' => [
        ['name' => 'Tomica Limited Vintage Neo', 'names' => ['/^Tomica Limited Vintage Neo$/'], 'categories' => []],
        ['name' => 'Tomica Limited Vintage', 'names' => ['/^Tomica Limited Vintage$/'], 'categories' => []],
        ['name' => 'Tomica Premium', 'names' => ['/^Tomica Premium$/'], 'categories' => []],
        ['name' => 'Tomica Limited', 'names' => ['/^Tomica Limited$/'], 'categories' => []],
        ['name' => 'Classic Tomica', 'names' => ['/^Classic Tomica$/'], 'categories' => []],
    ],

    'mini-gt' => [
        ['name' => 'Mini GT', 'names' => ['/^Mini GT$/'], 'categories' => []],
        ['name' => 'Kaido House', 'names' => ['/^Kaido House$/'], 'categories' => []],
    ],

    'matchbox' => [
        // The basic range has been sold as 1-75, 1-100, 1-120 and 1-125 over the
        // years — one line, whatever the count on the card said that year.
        ['name' => 'Mainline',
            'names' => ['/^(1-75|1-100|1-120|1-125|1-75\/100|MB ?75)\b/i', '/^(basic range|regular wheels|superfast)\b/i'],
            'categories' => ['Basic Range', '1-75', '1-100', '1-120', '1-125']],
        ['name' => 'Multi-Packs',
            'names' => ['/^\d+[- ]?packs?\b/i', '/\b\d+\/\d+[- ]?packs?\b/i', '/multi-?packs?/i', '/gift set/i'],
            'categories' => ['5-Packs', '9-Packs', '10-Packs', 'Multi-Packs', 'Gift Sets']],
        ['name' => 'Premium & Collectors',
            'names' => ['/premiere|collectibles|collectors|superfast america|best of|lesney edition|models of yesteryear|dinky/i'],
            'categories' => ['Premiere Collection', 'Collectibles', 'Matchbox Collectibles', 'Models of Yesteryear', 'Superfast']],
        ['name' => 'Themed Series',
            'names' => ['/across america|around the world|mission force|hitch|battle kings|launchers|heroes|real working rigs|sky busters|convoy|jurassic|stars of cars|graffic traffic|coca-cola|motortrend/i'],
            'categories' => ['Film and Television', 'Jurassic Park franchise', 'Jurassic World', 'Jurassic World Series by Year']],
        ['name' => 'Playsets',
            'names' => ['/playset|play set/i'],
            'categories' => ['Playsets']],
        ['name' => 'Promotions & Exclusives',
            'names' => ['/promotional|promo|exclusive|code red|code 2|euro edition|toy fair/i'],
            'categories' => ['Promotional models', 'Promotions', 'Wal-Mart Exclusive']],
    ],

];
