<?php

declare (strict_types = 1);

namespace think\model\type;

use think\Entity;

class Date extends DateTime
{
    protected $data;

    public static function from(mixed $value, Entity $model)
    {
        $static = new static();
        $static->data($value, 'Y-m-d');
        return $static;
    }
}
