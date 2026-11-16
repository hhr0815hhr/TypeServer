<?php

namespace Gen\Config;

/**
 * 配置行：big（源 config/tables/big.xlsx）
 *
 * **自动生成，不要手改**——改了下次 tsf config build 就没了。
 * 要加字段就改 Excel 的类型行。
 */
final class Big
{
    public int $id = 0;

    public string $bigName = "";

    public int $hp = 0;

    public float $ratio = 0.0;

    public bool $flag = false;

    /** int[] */
    public array $tags = [];

    public string $desc = "";
}
