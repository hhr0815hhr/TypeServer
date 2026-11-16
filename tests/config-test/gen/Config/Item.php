<?php

namespace Gen\Config;

/**
 * 配置行：item（源 config/tables/item.xlsx）
 *
 * **自动生成，不要手改**——改了下次 tsf config build 就没了。
 * 要加字段就改 Excel 的类型行。
 */
final class Item
{
    public int $id = 0;

    public string $itemName = "";

    public int $quality = 0;

    public int $attack = 0;

    public float $speed = 0.0;

    public int $price = 0;

    public bool $stackable = false;

    public string $desc = "";

    /** int[] */
    public array $tags = [];
}
