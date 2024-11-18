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
use think\helper\Str;
use think\model\Collection;
use think\model\contract\EnumTransform;
use think\model\contract\FieldTypeTransform;
use think\model\contract\Modelable;
use think\model\contract\Typeable;
use WeakMap;

/**
 * Class Entity.
 */
abstract class Entity implements JsonSerializable, ArrayAccess, Arrayable, Jsonable, Modelable
{
    private static ?WeakMap $weakMap = null;

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
            $class = $this->parseModel();
            $model = new $class;
        }

        if (!self::$weakMap) {
            self::$weakMap = new WeakMap;
        }

        self::$weakMap[$this] = [
            'get'      => [],
            'data'     => [],
            'origin'   => [],
            'schema'   => [],
            'together' => [],
            'strict'   => true,
            'model'    => $model,
        ];

        $model->setEntity($this);
        $model->exists(true);

        $this->initializeData($data);
    }

    /**
     * 数据读取 类型转换.
     *
     * @param array|object $data 实体模型数据
     *
     * @return void
     */
    protected function initializeData(array | object $data)
    {
        // 获取字段列表
        $schema = $this->getFields();
        $fields = array_keys($schema);

        // 实体模型赋值
        foreach ($data as $name => $val) {
            $trueName = $this->getRealFieldName($name);
            if (in_array($trueName, $fields)) {
                // 读取数据后进行类型转换
                $value = $this->readTransform($val, $schema[$trueName] ?? 'string');

                $this->$trueName   = $value;
                $origin[$trueName] = $value;
            }

            if ($this->model()->getPk() == $trueName) {
                // 记录主键值
                $this->model()->setKey($val);
            }
        }

        $this->setWeakData('origin', $origin);

        if (!self::$weakMap[$this]['strict']) {
            // 非严格定义模式下 采用动态属性
            $this->setWeakData('data', $origin);
        }
    }

    protected function setWeakData($name, $value)
    {
        self::$weakMap[$this][$name] = $value;
    }

    protected function getWeakData($name, $default = null)
    {
        return self::$weakMap[$this][$name] ?? $default;
    }

    protected function setData($key, $name, $value)
    {
        self::$weakMap[$this][$key][$name] = $value;
    }

    protected function getRealFieldName(string $name)
    {
        return self::$weakMap[$this]['model']->getRealFieldName($name);
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
        return self::$weakMap[$this]['model'];
    }

    /**
     * 获取数据表字段列表.
     *
     * @return array|string
     */
    protected function getFields(?string $field = null)
    {
        $weakMap = self::$weakMap[$this];
        if (!empty($weakMap['schema'])) {
            return $field
            ? ($weakMap['schema'][$field] ?? 'string')
            : $weakMap['schema'];
        }

        $class     = new ReflectionClass($this);
        $propertys = $class->getProperties(ReflectionProperty::IS_PUBLIC);
        $schema    = [];

        foreach ($propertys as $property) {
            $name          = $weakMap['model']->getRealFieldName($property->getName());
            $type          = $property->hasType() ? $property->getType()->getName() : 'string';
            $schema[$name] = $type;
        }

        if (empty($schema)) {
            // 采用非严格模式
            $this->setWeakData('strict', false);
            // 获取数据表信息
            $schema = $weakMap['model']->getFieldsType($weakMap['model']->getTable());
            $type   = $weakMap['model']->getType();
            $schema = array_merge($schema, $type);
        }

        $this->setWeakData('schema', $schema);

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
     * 关联数据写入.
     *
     * @param array $relation 关联
     *
     * @return $this
     */
    public function together(array $relation)
    {
        $this->setWeakData('together', $relation);

        return $this;
    }

    /**
     * 保存模型实例数据.
     *
     * @param array|object $data 数据
     * @return bool
     */
    public function save(array | object $data = []): bool
    {
        if ($this->isVirtual()) {
            return true;
        }

        $weakMap = self::$weakMap[$this];
        if (!empty($data)) {
            $data = $this->parseData($data);
            $this->initializeData($data);
        } else {
            $data = $this->getData($this);
        }

        if (empty($data) || false === $weakMap['model']->trigger('BeforeWrite')) {
            return false;
        }

        $isUpdate = $weakMap['model']->getKey();

        foreach ($data as $name => &$val) {
            if ($val instanceof Entity) {
                $relations[$name] = $val;
                unset($data[$name]);
            } elseif ($val instanceof Collection) {
                unset($data[$name]);
            } elseif (!$isUpdate || (isset($weakMap['origin'][$name]) && $val !== $weakMap['origin'][$name])) {
                // 类型转换
                $val    = $this->writeTransform($val, $this->getFields($name));
                $method = 'set' . Str::studly($name) . 'Attr';
                // 修改器
                if (method_exists($this, $method)) {
                    $val = $this->$method($val, $data);
                }
            } else {
                unset($data[$name]);
            }
        }

        $result = $isUpdate ? $this->updateData($weakMap['model'], $data) : $this->insertData($weakMap['model'], $data);

        if (false === $result) {
            return false;
        }

        // 写入回调
        $weakMap['model']->trigger('AfterWrite');

        // 保存关联数据
        if (!empty($relations)) {
            $this->relationSave($relations);
        }

        return true;
    }

    /**
     * 新增数据.
     *
     * @param Model $model 模型对象
     * @param array $data 数据
     * @return bool
     */
    protected function insertData(Model $model, array $data): bool
    {
        // 主键自动写入
        if ($model->isAutoWriteId()) {
            $pk = $model->getPk();
            if (is_string($pk) && !isset($data[$pk])) {
                $data[$pk] = $model->autoWriteId();
            }
        }

        if (empty($data) || false === $model->trigger('BeforeInsert')) {
            return false;
        }

        // 时间字段自动写入
        foreach ($model->getDateTimeFields() as $field) {
            if (is_string($field)) {
                $type = $this->getFields($field);
                if (is_subclass_of($type, Typeable::class)) {
                    $data[$field] = $type::from('now', $this)->value();
                    $this->$field = $data[$field];
                }
            }
        }

        $fields = array_keys($this->getFields());
        $result = $model->db()->field($fields)->insert($data, true);

        $this->setKey($result);
        $model->setKey($result);
        $model->trigger('AfterInsert');
        return true;
    }

    /**
     * 更新数据.
     *
     * @param Model $model 模型对象
     * @param array $data 数据
     * @return bool
     */
    protected function updateData(Model $model, array $data): bool
    {
        if (empty($data) || false === $model->trigger('BeforeUpdate')) {
            return false;
        }

        // 时间字段自动更新
        $field = $model->getDateTimeFields(true);
        if (is_string($field)) {
            $type = $this->getFields($field);
            if (is_subclass_of($type, Typeable::class)) {
                $data[$field] = $type::from('now', $this)->value();
                $this->$field = $data[$field];
            }
        }

        $model->db(null)
            ->where($model->getPk(), $model->getKey())
            ->update($data);

        // 更新回调
        $model->trigger('AfterUpdate');
        return true;
    }

    /**
     * 写入模型关联数据（一对一）.
     *
     * @param array $relations 数据
     * @return bool
     */
    protected function relationSave(array $relations = [])
    {
        foreach ($relations as $name => $relation) {
            if (in_array($name, $this->getWeakData('together'))) {
                $relationKey = $this->getRelationKey($name);
                if ($relationKey && property_exists($relation, $relationKey)) {
                    $relation->$relationKey = self::$weakMap[$this]['model']->getKey();
                }
                $relation->save();
            }
        }
    }

    /**
     * 删除模型关联数据（一对一）.
     *
     * @param array $relations 数据
     * @return bool
     */
    protected function relationDelete(array $relations = [])
    {
        foreach ($relations as $name => $relation) {
            if (in_array($name, $this->getWeakData('together'))) {
                $relation->delete();
            }
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

    public function isVirtual()
    {
        return false;
    }
    /**
     * 删除模型数据.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if ($this->isVirtual()) {
            return true;
        }

        foreach ($this->getData() as $name => $val) {
            if ($val instanceof Entity || $val instanceof Collection) {
                $relations[$name] = $val;
            }
        }

        $result = $this->model()->delete();

        if ($result) {
            // 删除关联数据
            if (!empty($relations)) {
                $this->relationDelete($relations);
            }
        }

        return true;
    }

    /**
     * 写入数据.
     *
     * @param array|object  $data 数据
     *
     * @return static
     */
    public static function create(array | object $data): Entity
    {
        $model = new static();

        $model->save($data);

        return $model;
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

        $entity = new static();
        if ($entity->isVirtual()) {
            return true;
        }

        $query = $entity->model()->db();

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
        $pk = $this->model()->getPk();
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
        if (self::$weakMap[$this]['strict']) {
            $class = new class {
                function getPublicVars($object)
                {
                    return get_object_vars($object);
                }
            };

            $data = $class->getPublicVars($this);
        } else {
            $data = self::$weakMap[$this]['data'];
        }

        return $data;
    }

    /**
     * 模型数据转数组.
     *
     * @param array $allow 允许输出字段
     * @return array
     */
    public function toArray(array $allow = []): array
    {
        $data = $this->getdata();
        foreach ($data as $name => &$item) {
            if (!empty($allow) && !in_array($name, $allow)) {
                unset($data[$name]);
            } elseif ($item instanceof Entity || $item instanceof Collection) {
                $item = $item->toarray();
            } elseif ($item instanceof Typeable) {
                $item = $item->value();
            } elseif (is_subclass_of($item, EnumTransform::class)) {
                $item = $item->value();
            } elseif (isset(self::$weakMap[$this]['get'][$name])) {
                $item = self::$weakMap[$this]['get'][$name];
            } else {
                $method = 'get' . Str::studly($name) . 'Attr';
                if (method_exists($this, $method)) {
                    // 使用获取器转换输出
                    $item = $this->$method($item, $data);
                    $this->setData('get', $name, $item);
                }
            }
        }

        // 输出额外属性
        foreach ($this->getWeakData('get') as $name => $val) {
            if (!empty($allow) && !in_array($name, $allow)) {
                continue;
            }

            if (!array_key_exists($name, $data)) {
                $data[$name] = $val;
            }
        }
        return $data;
    }

    /**
     * 判断数据是否为空.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->getData());
    }

    /**
     * 获取器 获取数据对象的值
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function get(string $name)
    {
        $name = $this->getRealFieldName($name);
        if (array_key_exists($name, self::$weakMap[$this]['get'])) {
            return self::$weakMap[$this]['get'][$name];
        }

        $value  = $this->getValue($name);
        $method = 'get' . Str::studly($name) . 'Attr';
        if (method_exists($this, $method)) {
            $value = $this->$method($value, $this->getData());
        }

        $this->setData('get', $name, $value);
        return $value;
    }

    public function getValue($name)
    {
        return self::$weakMap[$this]['strict'] ? ($this->$name ?? null) : (self::$weakMap[$this]['data'][$name] ?? null);
    }

    /**
     * 模型数据转Json.
     *
     * @param int $options json参数
     * @param array $allow 允许输出字段
     * @return string
     */
    public function tojson(int $options = JSON_UNESCAPED_UNICODE, array $allow = []): string
    {
        return json_encode($this->toarray($allow), $options);
    }

    /**
     * 获取额外属性
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * 设置额外数据
     *
     * @param string $name  名称
     * @param mixed  $value 值
     *
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $name = $this->getRealFieldName($name);

        $this->setData('data', $name, $value);
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
        $name = $this->getRealFieldName($name);
        return isset(self::$weakMap[$this]['data'][$name]);
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
        $name = $this->getRealFieldName($name);
        unset(self::$weakMap[$this]['data'][$name]);
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

    public static function __callStatic($method, $args)
    {
        $model = new static();

        return call_user_func_array([$model->model()->db(), $method], $args);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([self::$weakMap[$this]['model'], $method], $args);
    }
}
