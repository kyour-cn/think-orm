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
use think\contract\Arrayable;
use think\contract\Jsonable;
use think\db\Raw;
use think\model\Collection;
use think\model\contract\EnumTransform;
use think\model\contract\FieldTypeTransform;
use think\model\contract\Typeable;

/**
 * Class Entity.
 */
abstract class Entity implements JsonSerializable, ArrayAccess, Arrayable, Jsonable
{
    private Model $modelInstance;

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
            $class               = $this->parseModel();
            $this->modelInstance = new $class;
        } else {
            $this->modelInstance = $model;
        }

        $this->modelInstance->setEntity($this);

        // 获取字段列表
        $schema = $this->getFields();
        $fields = array_keys($schema);

        // 实体模型赋值
        foreach ($data as $name => $val) {
            $trueName = $this->modelInstance->getRealFieldName($name);
            if (in_array($trueName, $fields)) {
                $value             = $this->readTransform($val, $schema[$trueName] ?? 'string');
                $this->$trueName   = $value;
                $origin[$trueName] = $value;
            }

            if ($this->modelInstance->getPk() == $trueName) {
                // 记录主键值
                $this->modelInstance->setKey($val);
            }
        }

        $this->modelInstance->origin($origin);
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
                    $value = $type::from($value, $model);
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
                    $value = $value->value($model);
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
     * 解析模型实例名称.
     *
     * @return string
     */
    protected function parseModel()
    {
        return str_replace('entity', 'model', static::class);
    }

    /**
     * 获取模型实例.
     *
     * @return Model
     */
    public function model(): Model
    {
        return $this->modelInstance;
    }

    /**
     * 获取数据表字段列表.
     *
     * @return array|string
     */
    protected function getFields(?string $field = null)
    {
        $class     = new ReflectionClass($this);
        $propertys = $class->getProperties(ReflectionProperty::IS_PUBLIC);
        $schema    = [];
        foreach ($propertys as $property) {
            $name          = $this->modelInstance->getRealFieldName($property->getName());
            $type          = $property->hasType() ? $property->getType()->getName() : 'string';
            $schema[$name] = $type;
        }

        if ($field) {
            return $schema[$field] ?? 'string';
        }

        return $schema;
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

        if (empty($data) || false === $this->modelInstance->trigger('BeforeWrite')) {
            return false;
        }

        foreach ($data as $name => &$val) {
            if ($val instanceof Entity) {
                $relations[$name] = $val;
                unset($data[$name]);
            } elseif ($val instanceof Collection) {
                unset($data[$name]);
            } else {
                $val = $this->writeTransform($val, $this->getFields($name));
            }
        }

        $result = $this->modelInstance->getKey() ?
        $this->updateData($model, $data) :
        $this->insertData($model, $data);

        if (false === $result) {
            return false;
        }

        // 写入回调
        $this->modelInstance->trigger('AfterWrite');

        // 保存关联数据
        if (!empty($relations)) {
            $this->relationSave($relations);
        }

        $this->modelInstance->origin($this->getData());

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
        if ($this->modelInstance->isAutoWriteId()) {
            $pk = $this->modelInstance->getPk();
            if (is_string($pk) && !isset($data[$pk])) {
                $data[$pk] = $this->modelInstance->autoWriteId();
            }
        }

        if (empty($data) || false === $this->modelInstance->trigger('BeforeInsert')) {
            return false;
        }

        // 时间字段自动写入
        foreach ($this->modelInstance->getDateTimeFields() as $field) {
            if (is_string($field)) {
                $type = $this->getFields($field);
                if (is_subclass_of($type, Typeable::class)) {
                    $data[$field] = $type::from('now', $this)->value();
                    $this->$field = $data[$field];
                }
            }
        }

        $result = $this->modelInstance->db()->insert($data, true);

        $this->modelInstance->setKey($result);
        $this->modelInstance->trigger('AfterInsert');
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
        $data = $this->modelInstance->getChangedData($data);

        if (empty($data) || false === $this->modelInstance->trigger('BeforeUpdate')) {
            return false;
        }

        // 时间字段自动更新
        $field = $this->modelInstance->getDateTimeFields(true);
        if (is_string($field)) {
            $type = $this->getFields($field);
            if (is_subclass_of($type, Typeable::class)) {
                $data[$field] = $type::from('now', $this)->value();
                $this->$field = $data[$field];
            }
        }

        $this->modelInstance->db(null)
            ->where($this->modelInstance->getPk(), $this->modelInstance->getKey())
            ->update($data);

        // 更新回调
        $this->modelInstance->trigger('AfterUpdate');
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
            $relationKey = $this->getRelationKey($name);
            if ($relationKey && property_exists($relation, $relationKey)) {
                $relation->$relationKey = $this->modelInstance->getKey();
            }
            $relation->save();
        }
    }

    protected function getRelationKeys(): array
    {
        return [];
    }

    protected function getRelationKey(string $relation)
    {
        $relationKey = $this->getRelationKeys();
        return $relationKey[$relation] ?? null;
    }

    /**
     * 删除模型数据.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->modelInstance->getKey() || false === $this->modelInstance->trigger('BeforeDelete')) {
            return false;
        }

        $result = $this->modelInstance
            ->where($this->modelInstance->getPk(), $this->modelInstance->getKey())
            ->delete();
        if ($result) {
            $this->modelInstance->trigger('AfterDelete');
        }

        return true;
    }

    /**
     * 删除记录.
     *
     * @param mixed $data  主键列表 支持闭包查询条件
     *
     * @return bool
     */
    public static function destroy($data): bool
    {
        if (empty($data) && 0 !== $data) {
            return false;
        }

        $model = new static();
        $query = $model->model()->db();

        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = [];
        } elseif ($data instanceof \Closure) {
            $data($query);
            $data = [];
        }

        $resultSet = $query->select((array) $data);

        foreach ($resultSet as $result) {
            $result->delete();
        }

        return true;
    }

    /**
     * 设置主键值
     *
     * @param int|string $value 值
     * @return void
     */
    protected function setKey($value)
    {
        $this->modelInstance->setKey($value);
        $pk = $this->modelInstance->getPk();
        if (is_string($pk)) {
            $this->$pk = $value;
        }
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
            if ($item instanceof Entity || $item instanceof Collection) {
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
        $this->$name = $value;
    }

    public function offsetExists(mixed $name): bool
    {
        return isset($this->$name);
    }

    public function offsetUnset(mixed $name): void
    {
        unset($this->$name);
    }

    public function offsetGet(mixed $name): mixed
    {
        return $this->$name ?? null;
    }

    public function __debugInfo()
    {
        return $this->getdata();
    }

    public static function __callStatic($method, $args)
    {
        $model = new static();

        return call_user_func_array([$model->model()->db(), $method], $args);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->modelInstance, $method], $args);
    }
}
