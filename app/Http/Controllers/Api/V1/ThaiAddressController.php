<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ThaiAddress;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * รายชื่อตำบล/อำเภอ/จังหวัด สำหรับช่องค้นหาที่อยู่ในหน้าเซ็นแบบฟอร์มอุทยาน
 * (แอป เว็บ และลิงก์ของเพื่อน) — ไฟล์เดียวทั้งประเทศ ~650KB (บีบอัดเหลือ ~70KB)
 * ไม่เปลี่ยนบ่อย จึงให้เบราว์เซอร์/แอปเก็บไว้ได้นานและตรวจด้วย ETag
 */
class ThaiAddressController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = resource_path(ThaiAddress::PATH);
        $etag = '"'.md5_file($path).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response()->file($path, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => $etag,
        ]);
    }
}
