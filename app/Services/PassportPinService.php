<?php

namespace App\Services;

use App\Jobs\GeneratePhotoThumbnailJob;
use App\Models\PassportPin;
use App\Models\PassportPinPhoto;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * หมุดความทรงจำในสมุดสะสมการเดินทาง — ผู้ใช้ปักตรงที่ที่ไปมาจริงพร้อมรูป
 *
 * หมุดเป็นของเจ้าของคนเดียว ไม่มีหน้าสาธารณะ ไม่ขึ้นฟีด — คนจะเอาไปอวดก็แชร์
 * เป็นรูปสตอรี่จากในแอปเอง จึงไม่ต้องมีระบบรายงาน/ซ่อนเนื้อหาแบบ UGC สาธารณะ
 *
 * ผูกกับทริปได้เฉพาะทริปที่ผู้ใช้ "เดินจบแล้วจริง" ({@see PassportService::completedTripsFor})
 * ป้าย "ไปกับลุยเลเขา" บนหมุดจึงเชื่อถือได้ ส่วนพิกัดเป็นของที่ผู้ใช้ปักเอง
 */
class PassportPinService
{
    /** เพดานหมุดต่อคน — กันสคริปต์ยิงสร้างหมุดถล่มที่เก็บรูป */
    public const MAX_PINS = 500;

    public const MAX_PHOTOS_PER_PIN = 8;

    public const TITLE_MAX = 80;

    public const NOTE_MAX = 500;

    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private PassportService $passport,
        private SchedulePhotoService $photos,
    ) {}

    /** กติกา validate ของข้อมูลหมุด — [$partial] = แก้ไข ส่งมาเฉพาะช่องที่เปลี่ยน */
    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $today = now(self::TIMEZONE)->toDateString();

        return [
            'title' => [$req, 'string', 'max:'.self::TITLE_MAX],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.self::NOTE_MAX],
            'latitude' => [$req, 'numeric', 'between:-90,90'],
            'longitude' => [$req, 'numeric', 'between:-180,180'],
            // ไปมาแล้วเท่านั้น — สมุดนี้ไม่ใช่รายการที่อยากไป
            'visited_on' => [$req, 'date', 'after_or_equal:1950-01-01', 'before_or_equal:'.$today],
            'trip_id' => ['sometimes', 'nullable', 'integer'],
            'source' => ['sometimes', 'string', 'in:'.implode(',', PassportPin::SOURCES)],
        ];
    }

    /** กติกา validate ของรูปที่แนบมา (`photos[]`) — ไม่บังคับมีรูป */
    public static function photoRules(): array
    {
        return [
            'photos' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS_PER_PIN],
            'photos.*' => [
                'file',
                'mimes:'.SchedulePhotoService::ALLOWED_MIMES,
                'max:'.SchedulePhotoService::MAX_FILE_SIZE_KB,
            ],
        ];
    }

    /** @return Collection<int, PassportPin> */
    public function pinsFor(int $userId): Collection
    {
        return PassportPin::where('user_id', $userId)
            ->with(['photos', 'trip:id,title,slug'])
            ->orderByDesc('visited_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     *
     * @throws \Exception ข้อความภาษาไทยเมื่อทำไม่ได้
     */
    public function create(User $user, array $data, array $files = []): PassportPin
    {
        if (PassportPin::where('user_id', $user->id)->count() >= self::MAX_PINS) {
            throw new \Exception('ปักหมุดได้สูงสุด '.self::MAX_PINS.' หมุด ลองลบหมุดเก่าที่ไม่ใช้แล้วก่อน');
        }

        $attrs = $this->attributes($user->id, $data);
        $attrs['source'] ??= 'map';

        // รูปขึ้น R2 ก่อน ธุรกรรมทีหลัง — ไม่ถือ transaction ค้างระหว่างอัปโหลด
        $uploads = $this->storeFiles($user->id, $files);

        $pin = DB::transaction(function () use ($user, $attrs, $uploads) {
            $pin = PassportPin::create($attrs + ['user_id' => $user->id]);
            $this->attachUploads($pin, $uploads);

            return $pin;
        });

        return $pin->load(['photos', 'trip:id,title,slug']);
    }

    public function update(PassportPin $pin, array $data): PassportPin
    {
        $pin->fill($this->attributes($pin->user_id, $data))->save();

        return $pin->load(['photos', 'trip:id,title,slug']);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     *
     * @throws \Exception เมื่อรูปเกินเพดานต่อหมุด
     */
    public function addPhotos(PassportPin $pin, array $files): PassportPin
    {
        $existing = $pin->photos()->count();
        if ($existing + count($files) > self::MAX_PHOTOS_PER_PIN) {
            throw new \Exception('แนบรูปได้สูงสุด '.self::MAX_PHOTOS_PER_PIN.' รูปต่อหมุด');
        }

        $uploads = $this->storeFiles($pin->user_id, $files);
        DB::transaction(fn () => $this->attachUploads($pin, $uploads));

        return $pin->load(['photos', 'trip:id,title,slug']);
    }

    public function deletePhoto(PassportPin $pin, int $photoId): PassportPin
    {
        $pin->photos()->whereKey($photoId)->firstOrFail()->delete();

        return $pin->load(['photos', 'trip:id,title,slug']);
    }

    public function delete(PassportPin $pin): void
    {
        DB::transaction(fn () => $pin->delete());
    }

    public function format(PassportPin $pin): array
    {
        return [
            'id' => $pin->id,
            'title' => $pin->title,
            'note' => $pin->note,
            'latitude' => (float) $pin->latitude,
            'longitude' => (float) $pin->longitude,
            'visited_on' => $pin->visited_on?->toDateString(),
            'visited_on_label' => ThaiDate::short($pin->visited_on),
            'source' => $pin->source,
            'trip' => $pin->trip ? [
                'id' => $pin->trip->id,
                'title' => $pin->trip->title,
                'slug' => $pin->trip->slug,
            ] : null,
            'photos' => $pin->photos->map(fn (PassportPinPhoto $p) => [
                'id' => $p->id,
                'url' => $p->public_url,
                'thumb_url' => $p->thumb_public_url,
                'width' => $p->width,
                'height' => $p->height,
            ])->values()->all(),
            'created_at' => $pin->created_at?->toIso8601String(),
        ];
    }

    /** ทริปที่ผู้ใช้เดินจบแล้ว — ตัวเลือกเดียวที่หมุดผูกได้ */
    public function linkableTripIds(int $userId): array
    {
        return $this->passport->completedTripsFor($userId)
            ->map(fn (array $t) => $t['trip']->id)
            ->unique()
            ->values()
            ->all();
    }

    /** @throws \Exception เมื่อผูกกับทริปที่ยังไม่เคยเดินจบ */
    private function attributes(int $userId, array $data): array
    {
        $attrs = array_intersect_key($data, array_flip([
            'title', 'note', 'latitude', 'longitude', 'visited_on', 'trip_id', 'source',
        ]));

        if (isset($attrs['title'])) {
            $attrs['title'] = trim($attrs['title']);
        }
        if (array_key_exists('note', $attrs)) {
            $attrs['note'] = trim((string) $attrs['note']) ?: null;
        }

        if (! empty($attrs['trip_id'])) {
            if (! in_array((int) $attrs['trip_id'], $this->linkableTripIds($userId), true)) {
                throw new \Exception('ผูกหมุดได้เฉพาะทริปที่คุณเดินทางจบแล้ว');
            }
            $attrs['trip_id'] = (int) $attrs['trip_id'];
        } elseif (array_key_exists('trip_id', $attrs)) {
            $attrs['trip_id'] = null;
        }

        return $attrs;
    }

    /** @param  array<int, UploadedFile>  $files */
    private function storeFiles(int $userId, array $files): array
    {
        $disk = MediaDisk::name();

        return array_map(
            fn (UploadedFile $file) => $this->photos->storeFile($file, "passport-pins/{$userId}", $disk) + ['disk' => $disk],
            array_values($files),
        );
    }

    private function attachUploads(PassportPin $pin, array $uploads): void
    {
        $sort = (int) $pin->photos()->max('sort_order');

        foreach ($uploads as $info) {
            $photo = $pin->photos()->create([
                'disk' => $info['disk'],
                'path' => $info['path'],
                'url' => $info['url'],
                'width' => $info['width'],
                'height' => $info['height'],
                'sort_order' => ++$sort,
            ]);

            GeneratePhotoThumbnailJob::dispatch(PassportPinPhoto::class, $photo->id)->afterCommit();
        }
    }
}
