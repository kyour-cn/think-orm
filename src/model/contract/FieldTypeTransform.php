<?php

declare (strict_types = 1);

namespace think\model\contract;

use think\Entity;
use think\Model;

interface FieldTypeTransform
{
    public static function get(mixed $value, Model|Entity $model): ?static;

    /**
     * @return static|mixed
     */
    public static function set($value, Model|Entity $model) : mixed;
}
