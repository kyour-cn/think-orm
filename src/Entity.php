<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2023 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think;

use ArrayAccess;
use BackedEnum;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use think\Collection;
use think\contract\Arrayable;
use think\contract\Jsonable;
use think\db\Raw;
use think\helper\Str;
use think\model\contract\EnumTransform;
use think\model\contract\FieldTypeTransform;
use think\model\contract\Typeable;

/**
 * Class Entity.
 */
abstract class Entity implements JsonSerializable, ArrayAccess, Arrayable, Jsonable
{
    use model\concern\ModelEvent;
    use model\concern\AutoWriteId;

    private $model;
    private $key;
    private $origin   = [];
    private $data     = [];
    protected $schema = [];
    protected $json   = [];

    protected $pk          = 'id';
    protected $relationKey = [];
    protected $modelClass  = '';

    /**
     * 数据表只读字段.
     *
     * @var array
     */
    protected $readonly = [];

    /**
     * 数据表废弃字段.
     *
     * @var array
     */
    protected $disuse = [];

    /**
     * 是否强制更新所有数据.
     *
     * @var bool
     */
    private $force = false;

    /**
     * 是否严格字段大小写.
     *
     * @var bool
     */
    protected $strict = true;

    /**
     * 架构函数.
     *
     * @param array|object $data 实体模型数据
     * @param Model $model 模型连接对象
     */
    public function __construct(array | object $data = [], ?Model $model = null)
    {
        // 解析模型数据
        $data = $this->parseData($data);

        // 获取对应模型对象
        if (is_null($model)) {
            $class       = $this->parseModel();
            $this->model = new $class;
        } else {
            $this->model = $model;
        }

        $this->model->setEntity($this);

        // 获取字段列表
        $fields = $this->getFields();

        // 实体模型赋值
        foreach ($data as $name => $val) {
            $trueName = $this->getRealFieldName($name);
            if (in_array($trueName, $fields)) {
                $value                 = $this->readTransform($val, $this->getFieldType($trueName));
                $this->$trueName       = $value;
                $this->data[$trueName] = $value;
            }

            if ($trueName == $this->pk) {
                // 记录主键值
                $this->key = $val;
            }
        }

        // 记录原始数据
        $this->origin = $this->data;
    }

    /**
     * 获取主键名.
     *
     * @return string
     */
    public function getPk()
    {
        return $this->pk;
    }

    /**
     * 数据读取 类型转换.
     *
     * @param mixed        $value 值
     * @param string $type  要转换的类型
     *
     * @return mixed
     */
    protected function readTransform($value, string $type)
    {
        if (is_null($value)) {
            return;
        }

        $typeTransform = static function (string $type, $value, $model) {
            if (str_contains($type, '\\') && class_exists($type)) {
                if (is_subclass_of($type, Typeable::class)) {
                    $value = $type::from($value);
                } elseif (is_subclass_of($type, FieldTypeTransform::class)) {
                    $value = $type::get($value, $model);
                } elseif (is_subclass_of($type, BackedEnum::class)) {
                    $value = $type::from($value);
                } else {
                    // 对象类型
                    $value = new $type($value);
                }
            }

            return $value;
        };

        return match ($type) {
            'string' => (string) $value,
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'array'  => empty($value) ? [] : json_decode($value, true),
            'object' => empty($value) ? new \stdClass() : json_decode($value),
            default  => $typeTransform($type, $value, $this),
        };
    }

    /**
     * 数据写入 类型转换.
     *
     * @param mixed        $value 值
     * @param string|array $type  要转换的类型
     *
     * @return mixed
     */
    protected function writeTransform($value, string $type)
    {
        if (null === $value) {
            return;
        }

        if ($value instanceof Raw) {
            return $value;
        }

        $typeTransform = static function (string $type, $value, $model) {
            if (str_contains($type, '\\') && class_exists($type)) {
                if (is_subclass_of($type, Typeable::class)) {
                    $value = $value->value();
                } elseif (is_subclass_of($type, FieldTypeTransform::class)) {
                    $value = $type::set($value, $model);
                } elseif ($value instanceof BackedEnum) {
                    $value = $value->value;
                } elseif ($value instanceof Stringable) {
                    $value = $value->__toString();
                }
            }

            return $value;
        };

        return match ($type) {
            'string' => (string) $value,
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'object' => is_object($value) ? json_encode($value, JSON_FORCE_OBJECT) : $value,
            'array'  => json_encode((array) $value, JSON_UNESCAPED_UNICODE),
            default  => $typeTransform($type, $value, $this),
        };
    }

    /**
     * 获取实际的字段名.
     *
     * @param string $name 字段名
     *
     * @return string
     */
    protected function getRealFieldName(string $name): string
    {
        if (!$this->strict) {
            return Str::snake($name);
        }

        return $name;
    }

    /**
     * 解析模型实例名称.
     *
     * @return string
     */
    protected function parseModel()
    {
        return $this->modelClass ?: str_replace('entity', 'model', static::class);
    }

    /**
     * 获取模型实例.
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * 获取数据表字段列表.
     *
     * @return array
     */
    protected function getFields(): array
    {
        if (!empty($this->schema)) {
            return array_keys($this->schema);
        }

        $class     = new ReflectionClass($this);
        $propertys = $class->getProperties(ReflectionProperty::IS_PUBLIC);
        $schema    = [];
        foreach ($propertys as $property) {
            $name          = $this->getRealFieldName($property->getName());
            $type          = $property->hasType() ? $property->getType()->getName() : 'string';
            $schema[$name] = $type;
        }
        $this->schema = $schema;
        return array_keys($schema);
    }

    /**
     * 获取字段类型.
     *
     * @param string $field 字段名
     *
     * @return string
     */
    protected function getFieldType(string $field): string
    {
        return $this->schema[$field] ?? 'string';
    }

    /**
     * 解析模型数据.
     *
     * @param array|object $data 数据
     *
     * @return array
     */
    protected function parseData(array | object $data): array
    {
        if ($data instanceof Model) {
            $data = array_merge($data->getData(), $data->getRelation());
        } elseif (is_object($data)) {
            $data = get_object_vars($data);
        }

        // 废弃字段
        foreach ($this->disuse as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * 保存模型实例数据.
     *
     * @param array|object $data 数据
     * @return bool
     */
    public function save(array | object $data = []): bool
    {
        $data = !empty($data) ? $this->parseData($data) : $this->getData($this);

        if (empty($data) || false === $this->trigger('BeforeWrite')) {
            return false;
        }

        foreach ($data as $name => &$val) {
            if ($val instanceof Entity) {
                $relations[$name] = $val;
                unset($data[$name]);
            } elseif ($val instanceof Collection || in_array($name, $this->disuse)) {
                unset($data[$name]);
            } else {
                $val = $this->writeTransform($val, $this->getFieldType($name));
            }
        }

        $result = $this->key ? $this->updateData($data) : $this->insertData($data);

        if (false === $result) {
            return false;
        }

        // 写入回调
        $this->trigger('AfterWrite');

        // 保存关联数据
        if (!empty($relations)) {
            $this->relationSave($relations);
        }

        $this->origin = $this->getData();

        return true;

    }

    /**
     * 新增数据.
     *
     * @param array|object $data 数据
     * @return bool
     */
    protected function insertData(array $data): bool
    {
        // 主键自动写入
        if ($this->isAutoWriteId()) {
            $pk = $this->getPk();
            if (is_string($pk) && !isset($data[$pk])) {
                $data[$pk] = $this->autoWriteId();
            }
        }

        if (empty($data) || false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $result = $this->model->db()->insert($data, true);

        $this->setKey($result);
        $this->trigger('AfterInsert');
        return true;
    }

    /**
     * 更新数据.
     *
     * @param array|object $data 数据
     * @return bool
     */
    protected function updateData(array $data): bool
    {
        $data = $this->getChangedData($data);

        if (empty($data) || false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->model->db(null)->where($this->pk, $this->key)->update($data);

        // 更新回调
        $this->trigger('AfterUpdate');
        return true;
    }

    /**
     * 保存模型关联数据（一对一）.
     *
     * @param array $relations 数据
     * @return bool
     */
    protected function relationSave(array $relations = [])
    {
        foreach ($relations as $name => $relation) {
            $relationKey            = $this->relationKey[$name];
            $relation->$relationKey = $this->getKey();
            $relation->save();
        }
    }

    /**
     * 删除模型数据.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->key || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        $result = $this->model->where($this->pk, $this->key)->delete();
        if ($result) {
            $this->trigger('AfterDelete');
        }

        // 清空数据
        $this->clearData();
        return true;
    }

    /**
     * 删除记录.
     *
     * @param mixed $data  主键列表 支持闭包查询条件
     * @param bool  $force 是否强制删除
     *
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
    {
        if (empty($data) && 0 !== $data) {
            return false;
        }

        $model = new static();
        $query = $model->model->db();

        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = [];
        } elseif ($data instanceof \Closure) {
            $data($query);
            $data = [];
        }

        $resultSet = $query->select((array) $data);

        foreach ($resultSet as $result) {
            $result->force($force)->delete();
        }

        return true;
    }

    /**
     * 更新是否强制写入数据 而不做比较（亦可用于软删除的强制删除）.
     *
     * @param bool $force
     *
     * @return $this
     */
    public function force(bool $force = true)
    {
        $this->force = $force;

        return $this;
    }

    /**
     * 判断模型是否为空.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 获取模型对象的主键值
     *
     * @return mixed
     */
    protected function getKey()
    {
        $pk = $this->getPk();

        if (is_string($pk) && !is_null($this->$pk)) {
            return $this->$pk;
        }
    }

    /**
     * 设置主键值
     *
     * @param int|string $value 值
     * @return void
     */
    protected function setKey($value)
    {
        $pk = $this->getPk();

        if (is_string($pk)) {
            $this->$pk = $value;
        }
    }

    /**
     * 获取有更新的数据.
     *
     * @return array
     */
    protected function getChangedData($data): array
    {
        $data = $this->force ? $data : array_udiff_assoc($data, $this->origin, function ($a, $b) {
            if ((empty($a) || empty($b)) && $a !== $b) {
                return 1;
            }

            return is_object($a) || $a != $b ? 1 : 0;
        });

        // 只读字段不允许更新
        foreach ($this->readonly as $field) {
            if (array_key_exists($field, $data)) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 获取模型数据.
     *
     * @return array
     */
    protected function getData(): array
    {
        $me = new class {
            function getPublicVars($object)
            {
                return get_object_vars($object);
            }
        };
        return $me->getPublicVars($this);
    }

    /**
     * 模型数据转数组.
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = $this->getdata();
        foreach ($data as $name => &$item) {
            if ($item instanceof Entity) {
                $item = $item->toarray();
            } elseif ($item instanceof Typeable) {
                $item = $item->value();
            } elseif (is_subclass_of($item, EnumTransform::class)) {
                $item = $item->value();
            }
        }
        return $data;
    }

    /**
     * 模型数据转Json.
     *
     * @param int $options json参数
     * @return string
     */
    public function tojson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toarray(), $options);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    // JsonSerializable
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ArrayAccess
    public function offsetSet(mixed $name, mixed $value): void
    {
        $this->setAttr($name, $value);
    }

    public function offsetExists(mixed $name): bool
    {
        return $this->__isset($name);
    }

    public function offsetUnset(mixed $name): void
    {
        $this->__unset($name);
    }

    public function offsetGet(mixed $name): mixed
    {
        return $this->getAttr($name);
    }

    /**
     * 清空模型数据.
     *
     * @return void
     */
    protected function clearData()
    {
        $this->data   = [];
        $this->origin = [];
        $this->key    = null;
    }

    /**
     * 设置数据对象值
     *
     * @param string $name  属性名
     * @param mixed  $value 属性值
     *
     * @return void
     */
    public function setAttr(string $name, $value): void
    {
        $name = $this->getRealFieldName($name);

        if ((array_key_exists($name, $this->origin) || empty($this->origin)) && $value instanceof Stringable) {
            // 对象类型
            $value = $value->__toString();
        }

        // 设置数据对象属性
        $fields = $this->getFields();
        if (in_array($name, $fields)) {
            $this->$name = $value;
        }
        $this->data[$name] = $value;
    }

    /**
     * 获取数据对象值
     *
     * @param string $name  属性名
     *
     * @return mixed
     */
    public function getAttr(string $name)
    {
        $name = $this->getRealFieldName($name);
        return $this->$name ?? null;
    }

    /**
     * 设置数据对象的值
     *
     * @param string $name  名称
     * @param mixed  $value 值
     *
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->setAttr($name, $value);
    }

    /**
     * 获取数据对象的值
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->getAttr($name);
    }

    /**
     * 检测数据对象的值
     *
     * @param string $name 名称
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        $name   = $this->getRealFieldName($name);
        $fields = $this->getFields();
        return in_array($name, $fields) && isset($this->$name);
    }

    /**
     * 销毁数据对象的值
     *
     * @param string $name 名称
     *
     * @return void
     */
    public function __unset(string $name): void
    {
        unset(
            $this->data[$name],
            $this->$name
        );
    }

    public function __debugInfo()
    {
        return $this->getdata();
    }

    public static function __callStatic($method, $args)
    {
        $model = new static();

        return call_user_func_array([$model->model->db(), $method], $args);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->model, $method], $args);
    }
}
