<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing\Fixtures\Misnamed\Widgets;

/**
 * Right name, right place, but not a handler.
 *
 * Lives in its own fixture root so that it is an orphan nowhere: the point is
 * to show that the compiler checks the interface, not just the class name.
 */
final class ListWidgetsHandler
{
    public function handle(): void
    {
    }
}
