<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Page copy for the static pages lives twice: in the Vue router (which sets the
 * head after boot, for people browsing) and in config/seo.php (which sets it in
 * the response body, for crawlers that never boot anything). Two copies drift,
 * and the half that drifts is the half nobody looks at — the crawler's.
 *
 * So: read the router, and fail if it says something config/seo.php does not.
 */
class SeoPageMetaSyncTest extends TestCase
{
    private const ROUTER = 'resources/js/router/index.js';

    /**
     * Pull { path, title, description, robots } out of the router's route array.
     *
     * A regex over JavaScript rather than a parser, which is only safe because
     * the target is one file written in one style. If the router is ever
     * reformatted so this stops matching, the guard below catches that too — it
     * asserts a plausible number of routes came back.
     *
     * @return array<string, array{title: ?string, description: ?string, robots: ?string}>
     */
    private function routerPages(): array
    {
        $source = file_get_contents(base_path(self::ROUTER));
        $chunks = preg_split("/\n\s*\{?\s*path:\s*'/", $source);
        array_shift($chunks);

        $pages = [];

        foreach ($chunks as $chunk) {
            $path = substr($chunk, 0, strpos($chunk, "'"));

            // Admin routes render no public meta; :param routes are resolved
            // from the database by SeoMeta, not from this map.
            if (! str_starts_with($path, '/') || str_contains($path, ':') || str_starts_with($path, '/admin')) {
                continue;
            }

            $pages[$path] = [
                'title' => $this->firstMatch("/title:\s*'([^']*)'/", $chunk),
                'description' => $this->firstMatch("/description:\s*'([^']*)'/", $chunk),
                'robots' => $this->firstMatch("/robots:\s*'([^']*)'/", $chunk),
            ];
        }

        return $pages;
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $matches) ? $matches[1] : null;
    }

    public function test_the_router_parses_into_a_plausible_set_of_pages(): void
    {
        $pages = $this->routerPages();

        // Guards the regex above: if the router gets reformatted and this drops
        // to a handful, every other assertion here would vacuously pass.
        $this->assertGreaterThan(25, count($pages), 'Parsing '.self::ROUTER.' found almost no routes — the regex has stopped matching.');
        $this->assertArrayHasKey('/', $pages);
        $this->assertArrayHasKey('/trips', $pages);
    }

    public function test_every_router_page_is_mirrored_in_the_seo_config(): void
    {
        $configured = config('seo.pages');

        foreach ($this->routerPages() as $path => $meta) {
            $this->assertArrayHasKey(
                $path,
                $configured,
                "Route {$path} exists in the router but not in config/seo.php, so crawlers get the generic site copy for it. Add it.",
            );
        }
    }

    public function test_titles_and_descriptions_match_the_router(): void
    {
        $configured = config('seo.pages');

        foreach ($this->routerPages() as $path => $meta) {
            if (! isset($configured[$path])) {
                continue; // Reported by the test above; no need to fail twice.
            }

            foreach (['title', 'description'] as $key) {
                $this->assertSame(
                    $meta[$key],
                    $configured[$path][$key],
                    "The {$key} for {$path} differs between the router and config/seo.php.",
                );
            }
        }
    }

    public function test_pages_the_router_hides_from_crawlers_are_hidden_server_side_too(): void
    {
        $configured = config('seo.pages');

        foreach ($this->routerPages() as $path => $meta) {
            if ($meta['robots'] === null || ! isset($configured[$path])) {
                continue;
            }

            $this->assertSame(
                $meta['robots'],
                $configured[$path]['robots'] ?? null,
                "{$path} is {$meta['robots']} in the router but not in config/seo.php.",
            );
        }
    }
}
