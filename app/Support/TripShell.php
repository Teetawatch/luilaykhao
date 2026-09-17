<?php

namespace App\Support;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What a trip page says before Vue boots.
 *
 * Until now the body of /trips/ภูกระดึง and /trips/เขาช้างเผือก was the same
 * markup byte for byte — the site-wide link list in partials/boot-shell — and
 * every word that makes the two trips different arrived only once the bundle
 * had downloaded, run, and fetched the API. Google renders JavaScript, so it
 * gets there eventually, but "eventually" is a render queue measured in days,
 * and a page whose content shows up late ranks on a weaker signal than one
 * whose content is simply in the response. Everything else — Bing, the LINE
 * and Facebook unfurlers, the AI crawlers — never gets there at all.
 *
 * So the shell prints the trip itself. The text is the same text the SPA draws
 * from the same columns; it is replaced the instant Vue mounts (it empties the
 * mount container), so nothing here can end up duplicated on screen.
 *
 * Deliberately text, not layout: this exists to be read by something that does
 * not run JavaScript, and to be a readable page for a visitor whose bundle is
 * still on its way. See SiteNav for the links that follow it.
 */
final class TripShell
{
    /** Rounds worth printing. A season's worth, not a decade's. */
    private const ROUND_LIMIT = 12;

    /** Matches the SPA's own wording (TripDetailPage.vue diffMap). */
    private const DIFFICULTY_LABELS = [
        'easy' => 'ระดับเริ่มต้น',
        'medium' => 'ระดับปานกลาง',
        'hard' => 'ระดับท้าทาย',
    ];

    private const TYPE_LABELS = [
        'trekking' => 'ทริปเดินป่า',
        'snorkeling' => 'ทริปดำน้ำตื้น',
        'van' => 'เช่ารถตู้นำเที่ยว',
    ];

    /**
     * @return array{
     *     title: string, url: string, cover: ?string, type_label: string,
     *     summary: array<int, string>, facts: array<int, array{label: string, value: string}>,
     *     price_label: ?string, rounds: array<int, array{label: string, price: string, seats: string}>,
     *     sections: array<int, array{heading: string, list?: array<int, string>, entries?: array<int, array{title: string, body: string}>}>,
     *     faqs: array<int, array{question: string, answer: string}>
     * }
     */
    public static function for(Trip $trip): array
    {
        $rounds = PageTrip::openRounds($trip);

        return [
            'title' => (string) $trip->title,
            'url' => url('/trips/'.$trip->slug),
            'cover' => MediaDisk::url($trip->cover_image),
            'type_label' => self::TYPE_LABELS[$trip->type] ?? 'ทริปท่องเที่ยว',
            'summary' => self::paragraphs($trip->description),
            'facts' => self::facts($trip),
            'price_label' => self::priceLabel($trip, $rounds),
            'rounds' => self::rounds($rounds),
            'sections' => self::sections($trip),
            'faqs' => self::faqs($trip->faqs ?? []),
        ];
    }

    /**
     * The description, split where the admin split it. A wall of text with the
     * newlines stripped out reads as one paragraph to a person and as one
     * undifferentiated blob to everything else.
     *
     * @return array<int, string>
     */
    private static function paragraphs(?string $text): array
    {
        $clean = trim(strip_tags((string) $text));

        if ($clean === '') {
            return [];
        }

        return collect(preg_split('/\R{1,}/', $clean) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The numbers people actually search with — "ภูกระดึง กี่วัน", "ระยะทาง
     * กี่กิโล", "สูงเท่าไหร่". They live in columns already; they just never
     * reached the HTML.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function facts(Trip $trip): array
    {
        $facts = [];

        if ($trip->location) {
            $facts[] = ['label' => 'สถานที่', 'value' => (string) $trip->location];
        }

        if ($trip->destination_type === 'international' && $trip->country_code) {
            $facts[] = ['label' => 'ประเทศ', 'value' => Countries::name($trip->country_code) ?: (string) $trip->country_code];
        }

        if ($trip->duration_days) {
            $facts[] = ['label' => 'ระยะเวลา', 'value' => $trip->duration_days.' วัน'];
        }

        if ($label = self::DIFFICULTY_LABELS[$trip->difficulty] ?? null) {
            $facts[] = ['label' => 'ระดับความยาก', 'value' => $label];
        }

        if ($trip->distance_km) {
            $facts[] = ['label' => 'ระยะทางเดิน', 'value' => rtrim(rtrim(number_format((float) $trip->distance_km, 1), '0'), '.').' กิโลเมตร'];
        }

        if ($trip->elevation_gain_m) {
            $facts[] = ['label' => 'ความสูงสะสม', 'value' => number_format((int) $trip->elevation_gain_m).' เมตร'];
        }

        if ($trip->max_participants) {
            $facts[] = ['label' => 'รับได้สูงสุด', 'value' => $trip->max_participants.' คน'];
        }

        if ($trip->departure_point) {
            $facts[] = ['label' => 'จุดออกเดินทาง', 'value' => (string) $trip->departure_point];
        }

        return $facts;
    }

    /**
     * "เริ่มต้น ฿3,500 ต่อคน" — from the cheapest round on sale rather than the
     * trip's list price, so the shell never quotes a number the page will
     * contradict a second later.
     *
     * @param  Collection<int, TripSchedule>  $rounds
     */
    private static function priceLabel(Trip $trip, $rounds): ?string
    {
        $prices = $rounds->map(fn (TripSchedule $round) => $round->effective_price)->filter();
        $price = $prices->isNotEmpty() ? $prices->min() : (float) $trip->price_per_person;

        return $price > 0 ? 'เริ่มต้น ฿'.number_format((float) $price).' ต่อคน' : null;
    }

    /**
     * The rounds on sale, as dates a person would recognise. This is the half
     * of a trip page that changes — and the half that answers "ยังมีรอบไหม".
     *
     * @param  Collection<int, TripSchedule>  $rounds
     * @return array<int, array{label: string, price: string, seats: string}>
     */
    private static function rounds($rounds): array
    {
        return $rounds
            ->take(self::ROUND_LIMIT)
            ->map(fn (TripSchedule $round) => [
                'label' => ThaiDate::range($round->departure_date, $round->return_date),
                'price' => '฿'.number_format($round->effective_price),
                'seats' => $round->available_seats > 0
                    ? 'ว่าง '.$round->available_seats.' ที่'
                    : 'เต็มแล้ว',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{heading: string, list?: array<int, string>, entries?: array<int, array{title: string, body: string}>}>
     */
    private static function sections(Trip $trip): array
    {
        $sections = [];

        foreach (self::itinerary($trip) as $sector) {
            $sections[] = $sector;
        }

        if ($entries = self::highlights($trip->highlights ?? [])) {
            $sections[] = ['heading' => 'ไฮไลต์ของทริป', 'entries' => $entries];
        }

        if ($list = self::strings($trip->preparations ?? [])) {
            $sections[] = ['heading' => 'สิ่งที่ต้องเตรียม', 'list' => $list];
        }

        if ($list = self::strings($trip->inclusions ?? [])) {
            $sections[] = ['heading' => 'รวมในราคาแล้ว', 'list' => $list];
        }

        if ($list = self::strings($trip->exclusions ?? [])) {
            $sections[] = ['heading' => 'ไม่รวมในราคา', 'list' => $list];
        }

        if ($list = self::mustKnow($trip->must_know ?? [])) {
            $sections[] = ['heading' => 'ค่าใช้จ่ายที่ต้องรู้ก่อนไป', 'list' => $list];
        }

        return $sections;
    }

    /**
     * The itinerary comes in two shapes: sectors of days (current) or a flat
     * list of days (what trips authored before sectors existed still hold).
     * TripDetailPage.vue normalises the same two — see itinerarySectors.
     *
     * @return array<int, array{heading: string, entries: array<int, array{title: string, body: string}>}>
     */
    private static function itinerary(Trip $trip): array
    {
        $raw = $trip->itinerary ?? [];

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $sectors = array_key_exists('sector', (array) ($raw[0] ?? []))
            ? $raw
            : [['sector' => 'กำหนดการเดินทาง', 'items' => $raw]];

        $out = [];

        foreach ($sectors as $sector) {
            $entries = [];

            foreach ((array) ($sector['items'] ?? []) as $day) {
                $title = trim((string) ($day['title'] ?? ''));
                $body = trim(strip_tags((string) ($day['description'] ?? '')));

                if ($title === '' && $body === '') {
                    continue;
                }

                $number = trim((string) ($day['day'] ?? ''));

                $entries[] = [
                    'title' => $number !== '' ? 'วันที่ '.$number.' · '.$title : $title,
                    'body' => $body,
                ];
            }

            if ($entries !== []) {
                $out[] = [
                    'heading' => trim((string) ($sector['sector'] ?? '')) ?: 'กำหนดการเดินทาง',
                    'entries' => $entries,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $highlights
     * @return array<int, array{title: string, body: string}>
     */
    private static function highlights(array $highlights): array
    {
        $out = [];

        foreach ($highlights as $highlight) {
            $title = trim((string) ($highlight['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $out[] = ['title' => $title, 'body' => trim(strip_tags((string) ($highlight['desc'] ?? '')))];
        }

        return $out;
    }

    /**
     * Costs the price does not cover (entry fees, porters). People search for
     * these by name — "ภูกระดึง ค่าลูกหาบ" — and the answer was JavaScript-only.
     *
     * @param  array<string, mixed>  $mustKnow
     * @return array<int, string>
     */
    private static function mustKnow(array $mustKnow): array
    {
        $out = [];

        foreach ((array) ($mustKnow['items'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $per = ($item['price_type'] ?? '') === 'per_person' ? 'ต่อคน' : 'ครั้งเดียว';

            $out[] = $price > 0
                ? $name.' ฿'.number_format($price).' ('.$per.')'
                : $name;
        }

        if ($remarks = trim(strip_tags((string) ($mustKnow['remarks'] ?? '')))) {
            $out[] = $remarks;
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $faqs
     * @return array<int, array{question: string, answer: string}>
     */
    private static function faqs(array $faqs): array
    {
        $out = [];

        foreach ($faqs as $faq) {
            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim(strip_tags((string) ($faq['answer'] ?? '')));

            if ($question === '' || $answer === '') {
                continue;
            }

            $out[] = ['question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function strings(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim(strip_tags((string) (is_array($value) ? ($value['title'] ?? '') : $value))))
            ->filter()
            ->map(fn (string $value) => Str::limit($value, 300))
            ->values()
            ->all();
    }
}
