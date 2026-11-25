<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests\Support;

use LogicException;

/**
 * Runs the PHP blocks of the README and the docs pages as written.
 *
 * A block that starts with `class`, `enum` or `interface` is a definition: it is declared once,
 * before any script runs, and a block that repeats a definition must match the first one exactly.
 * Any other PHP block is a script. A script is run and, when a `text` block follows it, that
 * block is what the script has to print. A top-level line of the form `expression;   // value`
 * has its value captured where it runs, and the value its comment claims is compared with it.
 */
final class DocsRunner
{
    private const ROOT = __DIR__.'/../..';

    private static bool $booted = false;

    /** @var array<string, string> class name => the code that declared it */
    private static array $declared = [];

    /** @return array<string, string> source name => its text: the README, then index.md, then the numbered pages */
    public static function sources(): array
    {
        $files = glob(self::ROOT.'/docs/*.md') ?: [];

        usort($files, fn (string $a, string $b) => [basename($a) !== 'index.md', basename($a)] <=> [basename($b) !== 'index.md', basename($b)]);

        $sources = ['README.md' => (string) file_get_contents(self::ROOT.'/README.md')];

        foreach ($files as $file) {
            $sources['docs/'.basename($file)] = (string) file_get_contents($file);
        }

        return $sources;
    }

    /** @return list<array{kind: string, code: string, output: ?string}> the code blocks of one page, in order */
    public static function blocks(string $markdown): array
    {
        preg_match_all('/^```(\w+)\n(.*?)\n```$/ms', $markdown, $found, PREG_SET_ORDER);

        $blocks = [];

        foreach ($found as [, $language, $code]) {
            if ($language === 'php') {
                $blocks[] = ['kind' => self::isDefinition($code) ? 'definition' : 'script', 'code' => $code, 'output' => null];
            } elseif ($language === 'text' && $blocks !== []) {
                $last = array_key_last($blocks);

                if ($blocks[$last]['kind'] === 'script' && $blocks[$last]['output'] === null) {
                    $blocks[$last]['output'] = $code;
                }
            }
        }

        return $blocks;
    }

    /** Declare every definition the pages show. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        foreach (self::sources() as $name => $markdown) {
            foreach (self::blocks($markdown) as $block) {
                if ($block['kind'] !== 'definition') {
                    continue;
                }

                if (preg_match('/^(?:final |abstract )?(?:class|enum|interface) (\w+)/m', $block['code'], $class) !== 1) {
                    throw new LogicException("{$name} has a definition block with no class name");
                }

                $className = $class[1];

                if (isset(self::$declared[$className])) {
                    if (self::$declared[$className] !== $block['code']) {
                        throw new LogicException("{$name} shows {$className} differently from an earlier page");
                    }

                    continue;
                }

                self::$declared[$className] = $block['code'];
                eval(self::strip($block['code']));
            }
        }

        self::$booted = true;
    }

    /**
     * Run a script. Returns what it printed, the variables it left, and the value of each
     * `expression;   // value` line, keyed by the line's number in the script.
     *
     * @return array{output: string, vars: array<string, mixed>, values: array<int, mixed>}
     */
    public static function run(string $script): array
    {
        self::boot();

        $lines = explode("\n", $script);

        foreach (self::claims($script) as $number => [$expression]) {
            $lines[$number - 1] = '$__values['.$number.'] = ('.$expression.');';
        }

        ob_start();

        try {
            $vars = (static function (string $__code): array {
                $__values = [];

                eval($__code);

                unset($__code);

                return get_defined_vars();
            })(self::strip(implode("\n", $lines)));
        } finally {
            $printed = (string) ob_get_clean();
        }

        /** @var array<int, mixed> $values */
        $values = $vars['__values'];
        unset($vars['__values']);

        return ['output' => $printed, 'vars' => $vars, 'values' => $values];
    }

    /**
     * The `expression;   // value` lines of a script: line number => [the expression, the value
     * its comment claims]. A comment that looks like a value but is not one this runner can read
     * would otherwise go unchecked, so it throws.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    public static function claims(string $script): array
    {
        $claims = [];

        foreach (explode("\n", $script) as $index => $line) {
            if (str_starts_with($line, 'use ') || preg_match('/^(\S.*?);\s+\/\/ (.+)$/', $line, $parts) !== 1) {
                continue;
            }

            $claim = self::literal($parts[2]);

            if ($claim === null) {
                if (preg_match('/^(?:[\'"\[{]|-?\d|(?:true|false|null)\b)/i', $parts[2]) === 1) {
                    throw new LogicException("The // value in [{$line}] can't be checked; write it as 'string', an integer, true, false or null");
                }

                continue;
            }

            $claims[$index + 1] = [$parts[1], $claim[0]];
        }

        return $claims;
    }

    /** @return list<string> */
    public static function declaredClasses(): array
    {
        self::boot();

        return array_keys(self::$declared);
    }

    /** @return array{0: mixed}|null the value a comment states, or null when it states none */
    private static function literal(string $comment): ?array
    {
        if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/", $comment, $string) === 1) {
            return [stripslashes($string[1])];
        }

        if (preg_match('/^-?\d+$/', $comment) === 1) {
            return [(int) $comment];
        }

        return match ($comment) {
            'true' => [true],
            'false' => [false],
            'null' => [null],
            default => null,
        };
    }

    private static function isDefinition(string $code): bool
    {
        return preg_match('/\A<\?php\n\n(?:use [^\n]+\n)*\n?(?:final |abstract )?(?:class|enum|interface) /', $code) === 1;
    }

    private static function strip(string $code): string
    {
        return preg_replace('/\A<\?php\s*/', '', $code) ?? $code;
    }
}
