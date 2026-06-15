<?php

return [
    // Points earned per booking are floor(total_amount / baht_per_point).
    // 100 THB = 1 point.
    'baht_per_point' => (int) env('LOYALTY_BAHT_PER_POINT', 100),
];
