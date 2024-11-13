<?php

declare (strict_types = 1);

namespace think\model\type;

use think\model\contract\Typeable;

class DateTime implements Typeable
{
    protected $data;

    public static function from(mixed $value, $format = 'Y-m-d H:i:s.u')
    {
        $static = new static();
        $static->data($value, $format);
        return $static;
    }

    public function data($data, bool $assoc = true)
    {
        $date = new \DateTime;
        $date->setTimestamp(is_numeric($time) ? (int) $time : strtotime($time));
        $this->data = $date->format($format);
    }

    public function value()
    {
        return $this->data;
    }
}
