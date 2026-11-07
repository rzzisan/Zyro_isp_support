<?php

namespace App\Services;

use App\Models\KbKeywordOverride;
use App\Models\KbRouteOverride;
use App\Support\Menu;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Reads the ZyroDesk AI knowledge base (repo docs/kb, format in docs/kb/README.md) and lays the super-admin
 * overlay (kb_route_overrides, kb_keyword_overrides) on top. Read-only on the repo; a KB text change still goes
 * through a PR. Stale check needs git in the repo; without it that check is skipped.
 */
class KbCatalog
{
    public const SALES_HEADING = 'সেলস অংশ';

    private ?array $files = null;

    public static function root(): string
    {
        return realpath(base_path('..')) ?: base_path('..');
    }

    public static function kbDir(): string
    {
        return self::root().'/docs/kb';
    }

    /** @return array<string, array<string, mixed>> keyed by KB id */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }
        $out = [];
        $dir = self::kbDir();
        if (! is_dir($dir)) {
            return $this->files = [];
        }
        foreach (glob($dir.'/*/*.md') ?: [] as $path) {
            $file = $this->parseFile($path);
            if ($file) {
                $out[$file['id']] = $file;
            }
        }
        ksort($out);

        return $this->files = $out;
    }

    private function parseFile(string $path): ?array
    {
        $text = (string) file_get_contents($path);
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $text, $m)) {
            return null;
        }
        $meta = $this->parseFrontmatter($m[1]);
        if (empty($meta['id'])) {
            return ['id' => basename($path, '.md'), 'parse_error' => true, 'path' => $this->rel($path)] + $this->emptyFile();
        }
        [$sales, $customer, $headings] = $this->splitBody($m[2]);

        return [
            'id' => (string) $meta['id'],
            'title' => (string) ($meta['title'] ?? $meta['id']),
            'module' => (string) ($meta['module'] ?? ''),
            'menu' => $meta['menu'] ?? null,
            'status' => (string) ($meta['status'] ?? 'live'),
            'plans' => array_values((array) ($meta['plans'] ?? [])),
            'audience' => array_values((array) ($meta['audience'] ?? [])),
            'keywords' => array_values(array_map('strval', (array) ($meta['keywords'] ?? []))),
            'related' => array_values(array_map(fn ($r) => [
                'to' => (string) ($r['id'] ?? ''),
                'when' => (string) ($r['when'] ?? ''),
            ], array_filter((array) ($meta['related'] ?? []), 'is_array'))),
            'data' => (array) ($meta['data'] ?? []),
            'code' => array_values(array_map('strval', (array) ($meta['code'] ?? []))),
            'updated' => (string) ($meta['updated'] ?? ''),
            'path' => $this->rel($path),
            'sales' => $sales,
            'customer' => $customer,
            'headings' => $headings,
            'parse_error' => false,
        ];
    }

    /**
     * The small YAML subset of docs/kb/README.md: `key: value`, `key: [a, b]`, `key:` followed by `- item` lines,
     * and `- id: x` / `  when: y` maps. Quoted or `#` comments after a space are dropped.
     */
    public function parseFrontmatter(string $yaml): array
    {
        $out = [];
        $key = null;
        $itemIdx = null;
        foreach (preg_split('/\r?\n/', $yaml) as $raw) {
            $line = rtrim($raw);
            $hash = strpos($line, ' #');
            if ($hash !== false) {
                $line = rtrim(substr($line, 0, $hash));
            }
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! str_starts_with($line, ' ') && preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/u', $line, $kv)) {
                $key = $kv[1];
                $itemIdx = null;
                $out[$key] = $kv[2] === '' ? [] : $this->scalar($kv[2]);

                continue;
            }
            if ($key === null || ! is_array($out[$key] ?? null)) {
                continue;
            }
            if (preg_match('/^\s*-\s+(\w+):\s*(.*)$/u', $line, $it)) {
                $out[$key][] = [$it[1] => $this->scalar($it[2])];
                $itemIdx = array_key_last($out[$key]);

                continue;
            }
            if (preg_match('/^\s*-\s+(.+)$/u', $line, $it)) {
                $out[$key][] = $this->scalar($it[1]);
                $itemIdx = null;

                continue;
            }
            if ($itemIdx !== null && preg_match('/^\s+(\w+):\s*(.*)$/u', $line, $kv2)) {
                $out[$key][$itemIdx][$kv2[1]] = $this->scalar($kv2[2]);
            }
        }

        return $out;
    }

    private function scalar(string $v): mixed
    {
        $v = trim($v);
        if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));

            return $inner === '' ? [] : array_map(fn ($x) => $this->scalar($x), array_map('trim', explode(',', $inner)));
        }

        return trim($v, "\"'");
    }

    private function emptyFile(): array
    {
        return ['title' => '', 'module' => '', 'menu' => null, 'status' => 'live', 'plans' => [], 'audience' => [],
            'keywords' => [], 'related' => [], 'data' => [], 'code' => [], 'updated' => '', 'sales' => '', 'customer' => '', 'headings' => []];
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function splitBody(string $body): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/u', $line, $h)) {
                $current = $h[1];
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }
        $sales = trim($sections[self::SALES_HEADING] ?? '');
        $customerParts = [];
        foreach ($sections as $title => $content) {
            if ($title !== self::SALES_HEADING) {
                $customerParts[] = "## {$title}\n".trim($content);
            }
        }

        return [$sales, trim(implode("\n\n", $customerParts)), array_keys($sections)];
    }

    /** Markdown body as HTML, raw HTML escaped. */
    public function render(string $markdown): string
    {
        return $markdown === '' ? '' : (string) Str::markdown($markdown, ['html_input' => 'escape']);
    }

    /** Index rows from docs/kb/index.md. @return array<int, array{id: string, title: string, menu: array<int, string>, status: string, file: ?string}> */
    public function indexRows(): array
    {
        $path = self::kbDir().'/index.md';
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $line) {
            if (! preg_match('/^\|\s*([a-z_]+\.[a-z_0-9]+)\s*\|(.*)\|\s*$/', $line, $m)) {
                continue;
            }
            $cells = array_map('trim', explode('|', $m[2]));
            $menu = array_values(array_filter(array_map('trim', explode(',', $cells[1] ?? ''))));
            $file = null;
            if (! empty($cells[3]) && preg_match('/\(([^)]+\.md)\)/', $cells[3], $f)) {
                $file = $f[1];
            }
            $rows[] = ['id' => $m[1], 'title' => $cells[0] ?? '', 'menu' => $menu, 'status' => $cells[2] ?? '', 'file' => $file];
        }

        return $rows;
    }

    /** Overlay rows as arrays. @return array{routes: array<int, array<string, mixed>>, keywords: array<int, array<string, mixed>>} */
    public function overrides(): array
    {
        return [
            'routes' => KbRouteOverride::orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all(),
            'keywords' => KbKeywordOverride::orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all(),
        ];
    }

    /** Effective keywords: repo keywords plus active extra keywords. */
    public function keywords(string $id): array
    {
        $file = $this->files()[$id] ?? null;
        $kw = $file['keywords'] ?? [];
        foreach ($this->overrides()['keywords'] as $k) {
            if ($k['kb_id'] === $id && $k['active']) {
                $kw[] = $k['keyword'];
            }
        }

        return array_values(array_unique($kw));
    }

    /**
     * Effective links out of each file, with their origin: repo (from the file) or panel (overlay add).
     * Disabled repo links are dropped. @return array<int, array{from: string, to: string, when: string, source: string, repo_disabled: bool}>
     */
    public function edges(): array
    {
        $files = $this->files();
        $ov = $this->overrides()['routes'];
        $edges = [];
        foreach ($files as $id => $file) {
            foreach ($file['related'] as $r) {
                $disabled = collect($ov)->contains(fn ($o) => $o['from_id'] === $id && $o['to_id'] === $r['to'] && $o['action'] === 'disable' && $o['active']);
                $edges[$id.'>'.$r['to']] = ['from' => $id, 'to' => $r['to'], 'when' => $r['when'], 'source' => 'repo', 'active' => ! $disabled];
            }
        }
        foreach ($ov as $o) {
            $key = $o['from_id'].'>'.$o['to_id'];
            if ($o['action'] === 'add') {
                $edges[$key] = ['from' => $o['from_id'], 'to' => $o['to_id'], 'when' => (string) $o['when'], 'source' => 'panel', 'active' => (bool) $o['active']];
            } elseif (isset($edges[$key])) {
                // disable row: keep the edge visible but off; reactivating it means deleting the row
                $edges[$key]['active'] = false;
            }
        }

        return array_values($edges);
    }

    /** Active links only, as the router reads them. */
    public function activeEdges(): array
    {
        return array_values(array_filter($this->edges(), fn ($e) => $e['active']));
    }

    /** Problems: unknown targets, index rows without a file, menu keys with no KB file, stale files. */
    public function coverage(): array
    {
        $files = $this->files();
        $index = $this->indexRows();
        $broken = [];
        foreach ($this->edges() as $e) {
            if (! isset($files[$e['to']]) || ! isset($files[$e['from']])) {
                $broken[] = $e;
            }
        }
        $missing = array_values(array_filter($index, fn ($r) => $r['file'] === null));
        $fileIds = collect($files)->pluck('menu')->flatten()->filter()->all();
        $indexMenus = collect($index)->pluck('menu')->flatten()->filter()->all();
        $covered = array_unique(array_merge($indexMenus, array_map('strval', $fileIds)));
        $noKb = array_values(array_filter(array_keys(Menu::ITEMS), fn ($k) => ! in_array($k, $covered, true)));

        $stale = [];
        foreach ($files as $id => $f) {
            $kbTime = $this->gitTime($f['path']);
            if ($kbTime === null) {
                continue;
            }
            foreach ($f['code'] as $code) {
                $codeTime = $this->gitTime($code);
                if ($codeTime !== null && $codeTime > $kbTime) {
                    $stale[] = ['id' => $id, 'code' => $code];
                    break;
                }
            }
        }

        $errors = collect($files)->filter(fn ($f) => $f['parse_error'])->keys()->all();

        return [
            'total' => count($files),
            'index_rows' => count($index),
            'missing' => $missing,
            'no_kb_menus' => $noKb,
            'broken' => $broken,
            'stale' => $stale,
            'parse_errors' => $errors,
            'stale_check' => $this->gitTime('docs/kb/index.md') !== null,   // stale needs git in the repo
        ];
    }

    /** Keywords that appear in more than one file (a warning when routing can't tell them apart). */
    public function keywordOverlaps(): array
    {
        $map = [];
        foreach (array_keys($this->files()) as $id) {
            foreach ($this->keywords($id) as $k) {
                $map[mb_strtolower($k)][] = $id;
            }
        }

        return collect($map)->filter(fn ($ids) => count(array_unique($ids)) > 1)
            ->map(fn ($ids, $k) => ['keyword' => $k, 'ids' => array_values(array_unique($ids))])->values()->all();
    }

    /**
     * Keyword test: which main file would be read for the question, and its linked files whose `when` words also
     * appear in the question. Plain keyword matching; the real router (plan §16.3) is a later step.
     *
     * @return array{main: ?array, scores: array<int, array{id: string, score: int, hits: array<int, string>}>, related: array<int, array{id: string, when: string, hits: array<int, string>}>}
     */
    public function testQuestion(string $question): array
    {
        $q = mb_strtolower($question);
        $scores = [];
        foreach (array_keys($this->files()) as $id) {
            $hits = [];
            foreach ($this->keywords($id) as $k) {
                if ($k !== '' && mb_strpos($q, mb_strtolower($k)) !== false) {
                    $hits[] = $k;
                }
            }
            if ($hits) {
                $scores[] = ['id' => $id, 'score' => count($hits), 'hits' => $hits];
            }
        }
        usort($scores, fn ($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $main = $scores[0]['id'] ?? null;
        $related = [];
        if ($main) {
            foreach ($this->activeEdges() as $e) {
                if ($e['from'] !== $main) {
                    continue;
                }
                $words = array_filter(preg_split('/[\s,।।.()\-]+/u', mb_strtolower($e['when'])), fn ($w) => mb_strlen($w) >= 3);
                $hits = array_values(array_filter($words, fn ($w) => mb_strpos($q, $w) !== false));
                if ($hits) {
                    $related[] = ['id' => $e['to'], 'when' => $e['when'], 'hits' => $hits];
                }
            }
        }

        return ['main' => $main, 'scores' => array_slice($scores, 0, 5), 'related' => array_slice($related, 0, 3)];
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(self::root(), '', $path), '/');
    }

    /** Last commit time of a repo path (unix), or null when git is unavailable. */
    private function gitTime(string $relPath): ?int
    {
        try {
            $r = Process::timeout(5)->path(self::root())->run(['git', 'log', '-1', '--format=%ct', '--', $relPath]);
        } catch (\Throwable) {
            return null;
        }
        $out = trim($r->output());

        return $r->successful() && ctype_digit($out) ? (int) $out : null;
    }
}
