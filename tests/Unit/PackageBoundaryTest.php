<?php

declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * This package must not know that Laravel exists.
 *
 * It was split out of a Laravel cache driver, and the split was clean only
 * because nothing under src/ had reached for the framework - by habit rather
 * than by rule. A rule is cheaper than the split was: the first `config()` or
 * `Str::` in the protocol layer would quietly re-couple the two, and nobody
 * would notice until somebody tried to use this from Symfony, or from a queue
 * driver, or from a vector client that has no business pulling in
 * illuminate/cache.
 *
 * The composer manifest is checked too, because a dependency can arrive without
 * a single `use` statement to give it away.
 */
class PackageBoundaryTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_no_source_file_mentions_the_framework(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $source = file_get_contents($path);

            foreach (['Illuminate\\', 'Laravel\\', 'use function config', 'use function app'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($path).' mentions '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, 'this package must stay framework-free: '.implode('; ', $offenders));
    }

    public function test_it_declares_no_framework_dependency(): void
    {
        /** @var array{require?: array<string, string>} $manifest */
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        $framework = array_filter(
            array_keys($manifest['require'] ?? []),
            static fn (string $package) => str_starts_with($package, 'illuminate/')
                || str_starts_with($package, 'laravel/')
                || str_starts_with($package, 'symfony/')
        );

        $this->assertSame([], array_values($framework), 'a framework dependency has crept into require');
    }

    /**
     * The testing helpers ship in src/ on purpose - the cache driver and anything
     * else built on this needs them - so they are held to the same rule as the
     * rest of the package rather than treated as test-only code.
     */
    public function test_the_shipped_test_doubles_are_framework_free_too(): void
    {
        $testing = array_filter(
            $this->sourceFiles(),
            static fn (string $path) => str_contains($path, DIRECTORY_SEPARATOR.'Testing'.DIRECTORY_SEPARATOR)
        );

        $this->assertNotEmpty($testing, 'the Testing helpers have moved; this test needs updating');

        foreach ($testing as $path) {
            $this->assertStringNotContainsString('Illuminate\\', file_get_contents($path), basename($path));
        }
    }

    /**
     * The README promises "no extensions beyond core streams". That promise
     * belongs in the manifest, where Composer enforces it for every installer,
     * rather than in CI - a first attempt tried to hold it by stripping the
     * runner's extensions instead, which only broke Composer itself and proved
     * nothing about the package.
     *
     * ext-* under require-dev is fine: that is the toolchain, not the library.
     */
    public function test_it_requires_no_php_extension(): void
    {
        /** @var array{require?: array<string, string>} $manifest */
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        $extensions = array_filter(
            array_keys($manifest['require'] ?? []),
            static fn (string $package) => str_starts_with($package, 'ext-')
        );

        $this->assertSame([], array_values($extensions), 'an extension requirement has crept into require');
    }
}
