<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * 设备品牌解析：据 device_model / device_name 识别品牌（小米 / 华为 / vivo …）。
 *
 * 关键字表按可靠的品牌名 token 匹配（客户端上报的型号/设备名通常已含品牌，
 * 如 "Xiaomi 23127PN0CC" / "HUAWEI Mate 60"）。刻意不匹配纯数字型号码，
 * 避免跨品牌误判。无法识别时返回空串（前端显示为「未知」）。
 */
class DeviceBrand
{
    /** @var array<string,string[]> 品牌 => 关键字列表（顺序敏感，具体的排前面） */
    private const RULES = [
        '小米' => ['xiaomi', 'redmi', 'poco', '红米', '小米'],
        '荣耀' => ['honor', '荣耀'],
        '华为' => ['huawei', 'nova', 'mate', 'harmony', '鸿蒙', '华为',
            'ana-', 'els-', 'lya-', 'vog-', 'tas-', 'jad-', 'noh-', 'brq-', 'alt-', 'gla-'],
        'vivo' => ['vivo', 'iqoo'],
        'OPPO' => ['oppo', 'cph', 'pgt-', 'pht-', 'realme'],
        '一加' => ['oneplus', 'one plus', '一加'],
        '三星' => ['samsung', 'sm-', 'galaxy', 'gt-', '三星'],
        '苹果' => ['iphone', 'ipad', 'ipod', 'apple', 'mac'],
        '魅族' => ['meizu', '魅族'],
        '努比亚' => ['nubia', '红魔', 'redmagic', '努比亚'],
        '中兴' => ['zte', '中兴'],
        '摩托罗拉' => ['motorola', 'moto ', ' moto'],
        '索尼' => ['sony', 'xperia'],
        '谷歌' => ['pixel', 'google'],
        '华硕' => ['asus', 'rog phone', 'zenfone', '华硕'],
        '联想' => ['lenovo', '联想'],
        '黑鲨' => ['black shark', 'blackshark', '黑鲨'],
        'HTC' => ['htc'],
        '诺基亚' => ['nokia', '诺基亚'],
    ];

    /**
     * 解析品牌名，无法识别返回空串。
     */
    public static function resolve(?string $deviceModel, ?string $deviceName = null): string
    {
        $hay = mb_strtolower(trim((string)$deviceModel . ' ' . (string)$deviceName));
        if ($hay === '' || $hay === ' ') {
            return '';
        }
        foreach (self::RULES as $brand => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($hay, $kw) !== false) {
                    return $brand;
                }
            }
        }
        return '';
    }
}
