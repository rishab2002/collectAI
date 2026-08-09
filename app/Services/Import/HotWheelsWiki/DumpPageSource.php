<?php

namespace App\Services\Import\HotWheelsWiki;

use RuntimeException;

/**
 * Pages read from the wiki's XML dump.
 *
 * The bulk path, and the reason the import isn't a crawler: one 16 MB download
 * covers all 11,041 pages, against 11,041 requests to someone else's site for
 * the same data.
 *
 * Streamed, never loaded. The archive expands to hundreds of megabytes of XML,
 * and a bulk importer that needs all of it resident is one that falls over on
 * the machine it most needs to run on. The parser is fed in chunks and only the
 * pages actually asked for are accumulated — which is decided as soon as the
 * title element closes, before the revision text that follows it is read at all.
 */
final class DumpPageSource implements PageSource
{
    private const CHUNK = 65536;

    /**
     * Elements whose text is kept, by their path inside a <page>.
     */
    private const CAPTURED = ['title', 'ns', 'id', 'revision/id', 'revision/text'];

    public function __construct(
        private readonly string $path,
        private readonly string $sevenZip = '7z',
    ) {}

    public function page(string $title): ?WikiPage
    {
        foreach ($this->pages([$title]) as $page) {
            return $page;
        }

        return null;
    }

    /**
     * @param  list<string>  $titles
     * @return iterable<WikiPage>
     */
    public function pages(array $titles): iterable
    {
        // Titles are matched after normalising underscores to spaces, which is
        // how MediaWiki treats them: a link to `List_of_2011_Hot_Wheels` and the
        // page titled `List of 2011 Hot Wheels` are the same page.
        $wanted = [];

        foreach ($titles as $title) {
            $wanted[$this->normalize($title)] = true;
        }

        yield from $this->stream($wanted);
    }

    /**
     * Every article in the dump, for a run that wants the lot.
     *
     * @return iterable<WikiPage>
     */
    public function all(): iterable
    {
        yield from $this->stream([]);
    }

    /**
     * @param  array<string, true>  $wanted  Empty means every page.
     * @return iterable<WikiPage>
     */
    private function stream(array $wanted): iterable
    {
        $handle = $this->open();
        $found = [];

        // Written to by the element handlers, drained after each chunk. The
        // handlers can't yield — expat calls them, not us — so this is how a
        // pull interface is built on top of a push parser.
        $ready = [];

        $parser = $this->parser($wanted, $ready, $found);

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false) {
                    throw new RuntimeException("Failed reading the dump at {$this->path}.");
                }

                if (xml_parse($parser, $chunk, feof($handle)) !== 1) {
                    throw new RuntimeException(sprintf(
                        'Malformed dump XML at line %d: %s',
                        xml_get_current_line_number($parser),
                        xml_error_string(xml_get_error_code($parser)),
                    ));
                }

                foreach ($ready as $page) {
                    yield $page;
                }

                $ready = [];

                // Everything asked for has been seen, so there is no reason to
                // decompress the rest of the archive. Matters: the pages for one
                // year are a rounding error against the whole dump.
                if ($wanted !== [] && count($found) === count($wanted)) {
                    break;
                }
            }
        } finally {
            xml_parser_free($parser);
            $this->close($handle);
        }
    }

    /**
     * @param  array<string, true>  $wanted
     * @param  list<WikiPage>  $ready
     * @param  array<string, true>  $found
     */
    private function parser(array $wanted, array &$ready, array &$found): \XMLParser
    {
        $parser = xml_parser_create('UTF-8');

        // Element names arrive as written rather than upper-cased, which is the
        // only way `<text>` and `<Text>` could ever be told apart and is what
        // every path below assumes.
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 0);

        /** @var list<string> $path */
        $path = [];
        $fields = [];
        $capture = false;
        $inPage = false;

        xml_set_element_handler(
            $parser,
            function ($parser, string $name) use (&$path, &$fields, &$capture, &$inPage): void {
                if ($name === 'page') {
                    $inPage = true;
                    $path = [];
                    $fields = [];
                    // Until the title is known, everything is worth reading —
                    // it's a handful of short elements. The revision text is
                    // what must not be accumulated blindly.
                    $capture = true;

                    return;
                }

                if ($inPage) {
                    $path[] = $name;

                    if ($this->isCaptured($path)) {
                        $fields[implode('/', $path)] = '';
                    }
                }
            },
            function ($parser, string $name) use (&$path, &$fields, &$capture, &$inPage, &$ready, &$found, $wanted): void {
                if ($name === 'page') {
                    $this->finishPage($fields, $capture, $wanted, $ready, $found);
                    $inPage = false;
                    $capture = false;

                    return;
                }

                if (! $inPage) {
                    return;
                }

                array_pop($path);

                // The moment the decision can be made. Everything after this in
                // the page is the revision, including its text — so a page
                // nobody asked for costs nothing but the bytes to skip it.
                if ($name === 'title' && $wanted !== []) {
                    $capture = isset($wanted[$this->normalize($fields['title'] ?? '')]);
                }
            },
        );

        xml_set_character_data_handler(
            $parser,
            function ($parser, string $data) use (&$path, &$fields, &$capture): void {
                if (! $capture || $path === []) {
                    return;
                }

                $key = implode('/', $path);

                // Character data arrives in pieces for anything long, and the
                // revision text is always long.
                if (array_key_exists($key, $fields)) {
                    $fields[$key] .= $data;
                }
            },
        );

        return $parser;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, true>  $wanted
     * @param  list<WikiPage>  $ready
     * @param  array<string, true>  $found
     */
    private function finishPage(array $fields, bool $capture, array $wanted, array &$ready, array &$found): void
    {
        $title = $fields['title'] ?? null;

        if (! $capture || $title === null || ! isset($fields['revision/text'])) {
            return;
        }

        // Namespace 0 is articles. Everything else — templates, categories, talk
        // pages, user pages — is either not a casting or not content.
        if (($fields['ns'] ?? '0') !== '0') {
            return;
        }

        $ready[] = new WikiPage(
            title: $title,
            pageId: (int) ($fields['id'] ?? 0),
            revisionId: isset($fields['revision/id']) ? (int) $fields['revision/id'] : null,
            wikitext: $fields['revision/text'],
        );

        if ($wanted !== []) {
            $found[$this->normalize($title)] = true;
        }
    }

    /**
     * @param  list<string>  $path
     */
    private function isCaptured(array $path): bool
    {
        return in_array(implode('/', $path), self::CAPTURED, true);
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $title)));
    }

    /**
     * @return resource
     */
    private function open()
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("No readable dump at {$this->path}.");
        }

        // A dump that has already been decompressed is opened directly. Useful
        // when the same archive is imported more than once, and it is what makes
        // this class testable without shelling out.
        if (! str_ends_with(strtolower($this->path), '.7z')) {
            $handle = fopen($this->path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Could not open the dump at {$this->path}.");
            }

            return $handle;
        }

        // -so writes the archive's contents to stdout; -bso0 and -bsp0 keep
        // 7-Zip's own banner and progress meter out of that stream, which would
        // otherwise be parsed as XML.
        $command = sprintf(
            '%s x -so -bso0 -bsp0 %s',
            escapeshellarg($this->sevenZip),
            escapeshellarg($this->path),
        );

        $handle = popen($command, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not run {$this->sevenZip} to read {$this->path}.");
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function close($handle): void
    {
        if (str_ends_with(strtolower($this->path), '.7z')) {
            // Ignored deliberately: the stream is closed early on purpose once
            // every wanted page has been seen, and 7-Zip exits non-zero on the
            // broken pipe that causes.
            pclose($handle);

            return;
        }

        fclose($handle);
    }
}
