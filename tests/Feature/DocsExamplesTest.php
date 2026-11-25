<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests\Feature;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Keaton\HashList\HashList;
use Keaton\HashList\Models\HashListEntry;
use Keaton\HashList\Tests\Support\DocsRunner;
use Keaton\HashList\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The README and the docs (docs/*.md), run as written: every PHP block that is a script is run in a
 * freshly booted app, and the text block after it is what it prints. A `// value` comment on a
 * line is what that line's expression returns. The pages are grouped, in order, linking only to
 * pages that exist.
 */
class DocsExamplesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** The nav groups, in the order the sidebar shows them. */
    private const GROUPS = [
        'Start here',
        'Basic usage',
        'Advanced',
        'Reference',
    ];

    /** @return array<string, array{string, string, ?string}> */
    public static function scripts(): array
    {
        $cases = [];

        foreach (DocsRunner::sources() as $name => $markdown) {
            foreach (DocsRunner::blocks($markdown) as $position => $block) {
                if ($block['kind'] === 'script') {
                    $cases["{$name} block {$position}"] = [$name, $block['code'], $block['output']];
                }
            }
        }

        return $cases;
    }

    // ------------------------------------------------------------------
    // Every example runs, in a fresh app, and does what its page says

    #[DataProvider('scripts')]
    #[Test]
    public function every_script_runs_and_prints_what_its_page_shows(string $page, string $script, ?string $shown): void
    {
        $this->assertStartsFromDefaults($page);

        $claims = DocsRunner::claims($script);
        $ran = DocsRunner::run($script);

        $this->assertTrue($shown !== null || $claims !== [], "A script in {$page} has neither an output block nor a // value to check");

        if ($shown !== null) {
            $this->assertSame($shown, trim($ran['output']), "Output of a script in {$page}");
        }

        foreach ($claims as $number => [$expression, $value]) {
            $this->assertArrayHasKey($number, $ran['values'], "Line {$number} of a script in {$page} never ran");
            $this->assertSame($value, $ran['values'][$number], "Line {$number} of a script in {$page}: {$expression}");
        }
    }

    #[Test]
    public function the_pages_show_enough_code_to_be_worth_running(): void
    {
        $kinds = array_count_values(array_column(array_merge(...array_values(array_map(DocsRunner::blocks(...), DocsRunner::sources()))), 'kind'));

        $this->assertGreaterThanOrEqual(14, $kinds['script'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $kinds['definition'] ?? 0);
    }

    #[Test]
    public function every_text_block_follows_a_script(): void
    {
        foreach (DocsRunner::sources() as $name => $markdown) {
            $texts = preg_match_all('/^```text$/m', $markdown);
            $outputs = count(array_filter(DocsRunner::blocks($markdown), fn (array $block) => $block['output'] !== null));

            $this->assertSame($texts, $outputs, "{$name} has a text block that does not follow a script");
        }
    }

    #[Test]
    public function code_is_shown_only_as_fenced_blocks_of_a_known_kind(): void
    {
        foreach (DocsRunner::sources() as $name => $markdown) {
            $inFence = false;
            $role = '';

            foreach (explode("\n", $markdown) as $number => $line) {
                $at = "{$name} line ".($number + 1);

                if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $fence) === 1) {
                    $this->assertSame('```', $fence[1], "{$at} opens a code fence other than ```");

                    if (! $inFence) {
                        $this->assertContains($fence[2], ['php', 'text', 'bash'], "{$at} opens a ```{$fence[2]} block");

                        $role = $fence[2];
                    }

                    $inFence = ! $inFence;

                    continue;
                }

                if ($inFence && $role === 'bash') {
                    $this->assertDoesNotMatchRegularExpression('/<\?(?:php|=)|;\s*$|->|::/', $line, "{$at} is PHP in a ```bash block");
                }

                if (! $inFence) {
                    $this->assertDoesNotMatchRegularExpression('/^(?: {4}|\t)\S/', $line, "{$at} is an indented code block");
                    $this->assertDoesNotMatchRegularExpression('/<(?:pre|code)\b/i', $line, "{$at} is an HTML code block");
                }
            }

            $this->assertFalse($inFence, "{$name} never closes a code fence");
        }
    }

    #[Test]
    public function nothing_points_at_an_examples_folder_that_no_longer_exists(): void
    {
        $this->assertStringNotContainsString('docs/examples', implode("\n", DocsRunner::sources()));
        $this->assertStringNotContainsString('<!-- example:', implode("\n", DocsRunner::sources()));
        $this->assertDirectoryDoesNotExist(self::ROOT.'/docs/examples');
    }

    // ------------------------------------------------------------------
    // The page conventions the docs site relies on

    #[Test]
    public function the_pages_are_index_then_numbered_in_order(): void
    {
        $names = array_keys(self::pages());

        $this->assertSame('index.md', $names[0]);
        $this->assertGreaterThanOrEqual(6, count($names));
        $this->assertLessThanOrEqual(8, count($names));

        foreach (array_slice($names, 1) as $position => $name) {
            $this->assertMatchesRegularExpression('/^'.sprintf('%02d', $position + 2).'-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/', $name, 'Pages are numbered 02, 03, ... in order');
        }
    }

    #[Test]
    public function the_pages_are_grouped_in_sidebar_order_and_start_with_their_group(): void
    {
        $seen = [];

        foreach (self::pages() as $name => $text) {
            $this->assertMatchesRegularExpression('/\A<!-- nav-group: (.+) -->\n# \S/', $text, "{$name} must start with its nav group and a title");

            if (preg_match('/\A<!-- nav-group: (.+) -->/', $text, $group) !== 1) {
                $this->fail("{$name} has no nav group");
            }

            $seen[] = $group[1];
        }

        $positions = array_map(fn (string $group) => array_search($group, self::GROUPS, true), $seen);
        $inOrder = $positions;
        sort($inOrder);

        $this->assertNotContains(false, $positions, 'A page names a nav group that is not in the list');
        $this->assertSame($inOrder, $positions, 'The pages are not in sidebar order');
        $this->assertSame(self::GROUPS, array_values(array_unique($seen)), 'A nav group has no page');
    }

    #[Test]
    public function every_page_has_two_headings_and_ends_with_a_next_link_except_the_last(): void
    {
        $pages = self::pages();
        $last = array_key_last($pages);

        foreach ($pages as $name => $text) {
            $this->assertGreaterThanOrEqual(2, preg_match_all('/^## /m', $text), "{$name} needs at least two H2 headings");

            if ($name !== $last) {
                $this->assertMatchesRegularExpression('/\n\*\*Next:\*\* \[[^\]]+\]\(\S+\.md\)\.\n\z/', $text, "{$name} must end with a Next link");
            }
        }
    }

    #[Test]
    public function each_next_link_points_at_the_following_page(): void
    {
        $names = array_keys(self::pages());

        foreach ($names as $position => $name) {
            if (! isset($names[$position + 1])) {
                continue;
            }

            $this->assertStringContainsString("]({$names[$position + 1]}).\n", self::pages()[$name], "{$name} does not link on to {$names[$position + 1]}");
        }
    }

    #[Test]
    public function every_link_to_a_page_points_at_a_page_that_exists(): void
    {
        preg_match_all('/\]\(((?:\.\/)?[0-9a-z-]+\.md)\)/', implode("\n", self::pages()), $links);

        $this->assertNotEmpty($links[1]);

        foreach ($links[1] as $link) {
            $this->assertFileExists(self::ROOT.'/docs/'.ltrim($link, './'), "Broken docs link {$link}");
        }
    }

    #[Test]
    public function the_readme_badge_names_the_workflow_that_runs_the_tests(): void
    {
        $this->assertStringContainsString('/laravel-hash-list/run-tests.yml?branch=1.x', DocsRunner::sources()['README.md']);
        $this->assertFileExists(self::ROOT.'/.github/workflows/run-tests.yml');
    }

    #[Test]
    public function every_relative_link_in_the_readme_points_at_a_file_in_the_repo(): void
    {
        preg_match_all('/\]\((?!https?:)([^)#]+)\)/', DocsRunner::sources()['README.md'], $links);

        foreach (['CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'LICENSE.md'] as $footer) {
            $this->assertContains($footer, $links[1], "The README does not link to {$footer}");
        }

        foreach ($links[1] as $link) {
            $this->assertFileExists(self::ROOT.'/'.$link, "The README links to {$link}, which is not in the repo");
        }
    }

    #[Test]
    public function the_readme_opens_with_a_before_and_an_after_on_the_same_addresses(): void
    {
        $blocks = DocsRunner::blocks(DocsRunner::sources()['README.md']);

        $this->assertStringNotContainsString('HashList', $blocks[0]['code'], 'The before does not use the package');
        $this->assertStringContainsString('HashList::has($email)', $blocks[1]['code']);
        $this->assertNotNull($blocks[0]['output']);
        $this->assertNotNull($blocks[1]['output']);

        $before = DocsRunner::run($blocks[0]['code'])['vars'];
        $after = DocsRunner::run($blocks[1]['code'])['vars'];

        $this->assertSame($before['emails'], $after['emails']);
    }

    // ------------------------------------------------------------------
    // The example class, and the payoff

    #[Test]
    public function the_partner_import_replaces_the_list_with_tonights_file(): void
    {
        DocsRunner::boot();

        $list = app(HashList::class)->on('partner-7');
        $list->addHash(hash('sha256', 'old@example.com'));

        $file = $this->streamOf("EMAIL_SHA256\n".hash('sha256', 'new@example.com')."\n");

        $import = app('ImportPartnerList');
        $result = $import('7', $file);

        $this->assertSame(1, $result->added);
        $this->assertTrue($list->has('new@example.com'));
        $this->assertFalse($list->has('old@example.com'));
    }

    #[Test]
    public function the_partner_import_keeps_last_nights_list_when_tonights_file_is_empty(): void
    {
        DocsRunner::boot();

        $list = app(HashList::class)->on('partner-7');
        $list->addHash(hash('sha256', 'lee@example.com'));

        try {
            $import = app('ImportPartnerList');
            $import('7', $this->streamOf("EMAIL_SHA256\n"));
            $this->fail('An empty file should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertSame("Tonight's file is empty.", $e->getMessage());
        }

        $this->assertSame(1, $list->count());
    }

    #[Test]
    public function the_sharing_night_stores_no_address_and_keeps_the_list_when_the_next_file_is_broken(): void
    {
        $script = collect(self::scripts())->first(fn (array $case) => str_contains($case[1], 'ImportPartnerList'));
        $this->assertNotNull($script, 'No page script runs ImportPartnerList');

        $ran = DocsRunner::run($script[1]);

        $this->assertSame(4, $ran['vars']['result']->added);
        $this->assertSame(6, HashListEntry::query()->count());
        $this->assertSame(0, HashListEntry::query()->where('hash', 'like', '%@%')->count());
        $this->assertSame(4, HashListEntry::query()->where('list', 'partner-museum')->count(), 'the broken file was rolled back');
    }

    // ------------------------------------------------------------------
    // helpers

    /** @return array<string, string> page file name => its text, in file-name order (index first) */
    private static function pages(): array
    {
        $files = glob(self::ROOT.'/docs/*.md') ?: [];

        usort($files, fn (string $a, string $b) => [basename($a) !== 'index.md', basename($a)] <=> [basename($b) !== 'index.md', basename($b)]);

        $pages = [];

        foreach ($files as $file) {
            $pages[basename($file)] = (string) file_get_contents($file);
        }

        return $pages;
    }

    /**
     * Every example starts from a fresh application: the package's default config and an empty
     * table, with nothing set up that the page does not show.
     */
    private function assertStartsFromDefaults(string $page): void
    {
        $this->assertSame(require self::ROOT.'/config/hash_list.php', config('hash_list'), "config changed before a script in {$page} runs");
        $this->assertSame(0, HashListEntry::query()->count(), "the table is not empty before a script in {$page} runs");
        $this->assertSame([], app('events')->getListeners(MessageSent::class), "a mail listener is registered before a script in {$page} runs");
        $this->assertCount(1, app('events')->getListeners(MessageSending::class), "only the package's guard listens before a script in {$page} runs");

        foreach (DocsRunner::declaredClasses() as $class) {
            $this->assertFalse(app()->bound($class), "{$class} is bound before a script in {$page} runs");
        }
    }
}
