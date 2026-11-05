<?php

namespace Tests\Feature;

use App\Assistant\Coverage;
use App\Assistant\Registry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The console assistant keeps up with the console.
 *
 * A new page or action fails this test until someone decides, in
 * App\Assistant\Coverage, whether the assistant does it (and with which
 * tool), will later, or never should; and until docs/admin-assistant.md
 * records that decision. If this test failed for you, see that page.
 */
class AssistantCoverageTest extends TestCase
{
    private function routeNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->values()->all();
    }

    public function test_every_console_route_has_a_decision(): void
    {
        $missing = array_diff($this->routeNames(), array_keys(Coverage::ROUTES));

        $this->assertSame([], array_values($missing),
            'These routes have no entry in App\Assistant\Coverage::ROUTES. Add a tool, or mark them later or never.');
    }

    public function test_the_map_names_no_route_that_is_gone(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(Coverage::ROUTES), $this->routeNames())));
    }

    public function test_every_decision_is_well_formed_and_names_real_tools(): void
    {
        $tools = Registry::all()->pluck('name')->all();

        foreach (Coverage::ROUTES as $route => $decision) {
            $this->assertMatchesRegularExpression('/^(tool:[a-z_,]+|later: .+|never: .+)$/', $decision, $route);
            if (str_starts_with($decision, 'tool:')) {
                foreach (explode(',', substr($decision, 5)) as $tool) {
                    $this->assertContains($tool, $tools, "{$route} names tool {$tool}, which the registry does not have.");
                }
            }
        }
    }

    public function test_every_tool_is_used_by_some_route_or_stands_alone(): void
    {
        $named = collect(Coverage::ROUTES)->filter(fn ($d) => str_starts_with($d, 'tool:'))
            ->flatMap(fn ($d) => explode(',', substr($d, 5)))->unique()->all();

        foreach (Registry::all() as $tool) {
            $this->assertTrue(in_array($tool->name, $named, true) || in_array($tool->name, Coverage::STANDALONE, true),
                "{$tool->name} covers no route; map it, or list it in Coverage::STANDALONE.");
        }
    }

    public function test_the_coverage_page_is_up_to_date(): void
    {
        $page = str_replace("\r\n", "\n", file_get_contents(Coverage::page()));

        $this->assertStringContainsString(Coverage::section(), $page,
            'docs/admin-assistant.md is out of date. Run: php artisan assistant:coverage');

        foreach (array_keys(Coverage::ROUTES) as $route) {
            $this->assertStringContainsString("`{$route}`", $page, "docs/admin-assistant.md does not mention {$route}.");
        }
        foreach (Registry::all() as $tool) {
            $this->assertStringContainsString("`{$tool->name}`", $page, "docs/admin-assistant.md does not mention {$tool->name}.");
        }
    }
}
