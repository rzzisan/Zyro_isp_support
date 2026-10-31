<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Filament hands a table query to search and filter closures by parameter NAME: a Builder parameter called anything
 * but $query gets an empty Builder from the container, and the page fails with a 500 as soon as someone searches or
 * filters (বিকল্প নম্বর search, 2026-10-31).
 */
class FilamentQueryClosureTest extends TestCase
{
    public function test_search_and_filter_closures_name_their_builder_query(): void
    {
        $bad = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $i => $line) {
                if (preg_match('/(?:searchable|sortable)\(query:\s*(?:fn|function)\s*\(\s*Builder\s+\$(?!query\b)|->query\(\s*(?:fn|function)\s*\(\s*Builder\s+\$(?!query\b)/', $line)) {
                    $bad[] = str_replace(app_path(), 'app', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $bad);
    }
}
