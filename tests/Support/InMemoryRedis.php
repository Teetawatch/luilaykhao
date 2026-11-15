<?php

namespace Tests\Support;

/**
 * Redis ปลอมในหน่วยความจำ สำหรับสลับแทน facade ด้วย Redis::swap()
 *
 * มีเฉพาะคำสั่งที่โค้ดในแอปใช้จริง คำสั่งอื่นโยน exception เหมือนตอน Redis ล่ม
 * — โค้ดฝั่งแอปทุกจุดต้องรับมือกรณีนั้นได้อยู่แล้ว เทสต์จะได้ไม่ผ่านแบบบังเอิญ
 *
 * ตั้ง [$down] = true เพื่อจำลอง Redis ล่มทั้งตัว (ทุกคำสั่งโยน exception)
 */
class InMemoryRedis
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, int> unix time ที่คีย์หมดอายุ */
    public array $expires = [];

    public bool $down = false;

    /** @var list<string> */
    public array $calls = [];

    public static function down(): self
    {
        $redis = new self;
        $redis->down = true;

        return $redis;
    }

    private function touch(string $command, string $key): void
    {
        $this->calls[] = $command.' '.$key;

        if ($this->down) {
            throw new \RuntimeException('Connection refused (InMemoryRedis down)');
        }

        if (isset($this->expires[$key]) && $this->expires[$key] <= now()->timestamp) {
            unset($this->data[$key], $this->expires[$key]);
        }
    }

    public function get(string $key): mixed
    {
        $this->touch('get', $key);
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): bool
    {
        $this->touch('set', $key);
        $this->data[$key] = $value;
        unset($this->expires[$key]);

        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->touch('setex', $key);
        $this->data[$key] = $value;
        $this->expires[$key] = now()->timestamp + $ttl;

        return true;
    }

    public function del(string ...$keys): int
    {
        $removed = 0;
        foreach ($keys as $key) {
            $this->touch('del', $key);
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key], $this->expires[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    public function exists(string $key): int
    {
        $this->touch('exists', $key);

        return array_key_exists($key, $this->data) ? 1 : 0;
    }

    public function expire(string $key, int $ttl): bool
    {
        $this->touch('expire', $key);
        if (! array_key_exists($key, $this->data)) {
            return false;
        }
        $this->expires[$key] = now()->timestamp + $ttl;

        return true;
    }

    public function ttl(string $key): int
    {
        $this->touch('ttl', $key);
        if (! array_key_exists($key, $this->data)) {
            return -2;
        }

        return isset($this->expires[$key]) ? $this->expires[$key] - now()->timestamp : -1;
    }

    // ── hash ──

    public function hset(string $key, string|int $field, string $value): int
    {
        $this->touch('hset', $key);
        $hash = $this->data[$key] ?? [];
        $isNew = ! array_key_exists((string) $field, $hash);
        $hash[(string) $field] = $value;
        $this->data[$key] = $hash;

        return $isNew ? 1 : 0;
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $this->touch('hgetall', $key);

        return is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
    }

    public function hdel(string $key, string|int ...$fields): int
    {
        $this->touch('hdel', $key);
        $hash = is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
        $removed = 0;
        foreach ($fields as $field) {
            if (array_key_exists((string) $field, $hash)) {
                unset($hash[(string) $field]);
                $removed++;
            }
        }
        if ($hash === []) {
            unset($this->data[$key], $this->expires[$key]);
        } else {
            $this->data[$key] = $hash;
        }

        return $removed;
    }

    // ── sorted set (เก็บเป็น member => score) ──

    /** @param  array<string|int, int|float>  $dictionary */
    public function zadd(string $key, array $dictionary): int
    {
        $this->touch('zadd', $key);
        $set = is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
        $added = 0;
        foreach ($dictionary as $member => $score) {
            $added += array_key_exists((string) $member, $set) ? 0 : 1;
            $set[(string) $member] = $score;
        }
        $this->data[$key] = $set;

        return $added;
    }

    /** @return array<string, int|float> member => score เรียงจากน้อยไปมาก */
    private function sorted(string $key): array
    {
        $set = is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
        asort($set);

        return $set;
    }

    public function zremrangebyrank(string $key, int $start, int $stop): int
    {
        $this->touch('zremrangebyrank', $key);
        $members = array_keys($this->sorted($key));
        $count = count($members);
        $from = $start < 0 ? max(0, $count + $start) : $start;
        $to = $stop < 0 ? $count + $stop : min($stop, $count - 1);
        $removed = 0;
        for ($i = $from; $i <= $to; $i++) {
            unset($this->data[$key][$members[$i]]);
            $removed++;
        }

        return $removed;
    }

    /** @return list<string> */
    public function zrevrange(string $key, int $start, int $stop): array
    {
        $this->touch('zrevrange', $key);
        $members = array_map('strval', array_reverse(array_keys($this->sorted($key))));
        $length = $stop < 0 ? null : $stop - $start + 1;

        return array_values(array_slice($members, $start, $length));
    }

    public function __call(string $method, array $arguments): mixed
    {
        throw new \RuntimeException("InMemoryRedis does not implement {$method}()");
    }
}
