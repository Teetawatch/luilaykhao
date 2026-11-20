<?php

namespace App\Services;

/**
 * แปลไม่ครบทุกชุด — พกคำแปลของชุดที่สำเร็จไปแล้วมาด้วย ผู้เรียกจะได้บันทึกส่วน
 * นั้นก่อน แล้วให้งานในคิวลองใหม่เฉพาะช่องที่ยังขาด
 */
class PartialTranslation extends \RuntimeException
{
    /** @param  array<string, string>  $results */
    public function __construct(public readonly array $results, \Throwable $previous)
    {
        parent::__construct('Content translation stopped part-way: '.$previous->getMessage(), 0, $previous);
    }
}
