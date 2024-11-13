<?php

declare (strict_types = 1);

namespace think\model\contract;

interface Typeable
{
    public static function from(mixed $value, $option);

    /**
     * @return mixed
     */
    public function value();
}
