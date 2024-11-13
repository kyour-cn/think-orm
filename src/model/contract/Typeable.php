<?php

declare (strict_types = 1);

namespace think\model\contract;

use think\Entity;

interface Typeable
{
    public static function from(mixed $value, Entity $model);

    /**
     * @return mixed
     */
    public function value();
}
