<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-editable config for the website's urgent-trips popup (flash sale +
 * almost-full trips). Stored as one JSON block under the `urgent_popup` key;
 * missing fields fall back to the defaults so old stored blocks stay valid.
 */
class UrgentPopupSettings
{
    public const KEY = 'urgent_popup';

    public const DEFAULTS = [
        'enabled' => true,
        'show_flash_sale' => true,
        'show_almost_full' => true,
        'seat_threshold' => 5,
        'title' => null,
    ];

    public static function get(): array
    {
        $stored = Setting::get(self::KEY, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }
}
