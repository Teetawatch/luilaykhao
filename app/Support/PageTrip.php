<?php

namespace App\Support;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Database\Eloquent\Collection;

/**
 * The trip a public URL is about.
 *
 * Both halves of the response are built from it: SeoMeta writes the <head>, the
 * boot shell writes the body. The route resolves it once and hands the same
 * instance to both rather than letting each look it up — this is the most
 * visited page on the site and the second query would be pure duplication.
 *
 * Deliberately not filtered by status: the SPA and the public API both render
 * whatever slug they are given (see TripController::show), so the shell has to
 * agree with them or a crawler would be told a page exists that visitors cannot
 * see, or the reverse.
 */
final class PageTrip
{
    /**
     * The trip behind a path, or null when the path is not a trip page.
     */
    public static function forPath(string $path): ?Trip
    {
        $slug = self::slugFrom($path);

        return $slug === null ? null : Trip::where('slug', $slug)->first();
    }

    /**
     * The rounds still on sale, cheapest way of asking twice.
     *
     * Memoised on the model instance rather than statically: a static cache
     * would survive into the next request of the same test process and hand
     * back rounds that have since been created or cancelled.
     *
     * @return Collection<int, TripSchedule>
     */
    public static function openRounds(Trip $trip): Collection
    {
        if (! $trip->relationLoaded('openRounds')) {
            $rounds = $trip->schedules()
                ->where('status', 'open')
                // Thai wall-clock: a round leaving today is still on sale even
                // once UTC has rolled over into tomorrow.
                ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
                ->orderBy('departure_date')
                ->get();

            // effective_price reads $schedule->trip->price_per_person. Without
            // this every round would fetch the trip it was just loaded from.
            $rounds->each(fn (TripSchedule $round) => $round->setRelation('trip', $trip));

            $trip->setRelation('openRounds', $rounds);
        }

        /** @var Collection<int, TripSchedule> */
        return $trip->getRelation('openRounds');
    }

    /** The slug in `/trips/{slug}`, or null for any other path. */
    public static function slugFrom(string $path): ?string
    {
        $path = '/'.trim($path, '/');

        return preg_match('#^/trips/([^/]+)$#', $path, $matches)
            ? urldecode($matches[1])
            : null;
    }
}
