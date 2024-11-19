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
            'hidden'   => [],
            'visible'  => [],
            'append'   => [],
            'mapping'  => [],
            'strict'   => true,
            'model'    => $model,
        ];

        $model->setEntity($this);
        // 初始化模型数据
        $this->initializeData($data);
    }

    /**
     * 解析模型实例名称.
     *
     * @return string
     */
    protected function parseModel(): string
    {
        return str_replace('\\entity', '\\model', static::class);
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
     * 初始化模型数据.
     *
     * @param array|object $data 实体模型数据
     * @param bool  $fromSave
     *
     * @return void
     */
    protected function initializeData(array | object $data, bool $fromSave = false)
    {
        // 分析数据
        $data = $this->parseData($data);
        // 获取字段列表
        $schema = $this->getFields();
        $fields = array_keys($schema);

        // 实体模型赋值
        foreach ($data as $name => $val) {
            if (!empty(self::$weakMap[$this]['mapping'])) {
                $key = array_search($name, self::$weakMap[$this]['mapping']);
                if (is_string($key)) {
                    $name = $key;
                }
            }
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

        if (!empty($origin)) {
            if ($this->model()->getKey()) {
                $this->model()->exists(true);
            }

            if (!$fromSave) {
                $this->setWeakData('origin', $origin);
            }

            if (!$this->isStrictMode()) {
                // 非严格定义模式下 采用动态属性
                $this->setWeakData('data', $origin);
            }
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

    protected function isStrictMode(): bool
    {
        return self::$weakMap[$this]['strict'];
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
            $name          = $this->getRealFieldName($property->getName());
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
     * 关联数据写入或删除.
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
     * 强制写入或删除
     *
     * @param bool $force 强制更新
     *
     * @return $this
     */
    public function force(bool $force = true)
    {
        $this->model()->force($force);

        return $this;
    }

    /**
     * 新增数据是否使用Replace.
     *
     * @param bool $replace
     *
     * @return $this
     */
    public function replace(bool $replace = true)
    {
        $this->model()->replace($replace);

        return $this;
    }

    /**
     * 设置需要附加的输出属性.
     *
     * @param array $append 属性列表
     * @param bool  $merge  是否合并
     *
     * @return $this
     */
    public function append(array $append, bool $merge = false)
    {
        self::$weakMap[$this]['append'] = $merge ? array_merge(self::$weakMap[$this]['append'], $append) : $append;

        return $this;
    }

    /**
     * 设置需要隐藏的输出属性.
     *
     * @param array $hidden 属性列表
     * @param bool  $merge  是否合并
     *
     * @return $this
     */
    public function hidden(array $hidden, bool $merge = false)
    {
        self::$weakMap[$this]['hidden'] = $merge ? array_merge(self::$weakMap[$this]['hidden'], $hidden) : $hidden;

        return $this;
    }

    /**
     * 设置需要输出的属性.
     *
     * @param array $visible
     * @param bool  $merge   是否合并
     *
     * @return $this
     */
    public function visible(array $visible, bool $merge = false)
    {
        self::$weakMap[$this]['visible'] = $merge ? array_merge(self::$weakMap[$this]['visible'], $visible) : $visible;

        return $this;
    }

    /**
     * 设置属性的映射输出.
     *
     * @param array $map
     *
     * @return $this
     */
    public function mapping(array $map)
    {
        self::$weakMap[$this]['mapping'] = $map;

        return $this;
    }

    /**
     * 字段值增长
     *
     * @param string $field 字段名
     * @param float  $step  增长值
     *
     * @return $this
     */
    public function inc(string $field, float $step = 1)
    {
        $this->set($field, $this->get($field) + $step);
        return $this;
    }

    /**
     * 字段值减少.
     *
     * @param string $field 字段名
     * @param float  $step  增长值
     *
     * @return $this
     */
    public function dec(string $field, float $step = 1)
    {
        $this->set($field, $this->get($field) - $step);
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

        if (!empty($data)) {
            // 初始化模型数据
            $this->initializeData($data, true);
        }

        $data     = $this->getData();
        $origin   = $this->getOrigin();
        $isUpdate = $this->model()->getKey() && !$this->model()->isForce();

        foreach ($data as $name => &$val) {
            if ($val instanceof Entity) {
                $relations[$name] = $val;
                unset($data[$name]);
            } elseif ($val instanceof Collection) {
                unset($data[$name]);
            } elseif ($isUpdate && ((isset($origin[$name]) && $val === $origin[$name]) || $this->model()->getPk() == $name)) {
                unset($data[$name]);
            } else {
                // 类型转换
                $val    = $this->writeTransform($val, $this->getFields($name));
                $method = 'set' . Str::studly($name) . 'Attr';
                // 修改器
                if (method_exists($this, $method)) {
                    $val = $this->$method($val, $data);
                }
            }
        }

        $result = $this->model()->save($data);

        if (false === $result) {
            return false;
        }

        // 保存关联数据
        if (!empty($relations)) {
            $this->relationSave($relations);
        }

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
     * @param bool  $force 是否强制删除
     *
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
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
            $result->force($force)->delete();
        }

        return true;
    }

    /**
     * 设置主键值
     *
     * @param int|string $value 值
     * @return void
     */
    public function setKey($value)
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
    public function getData(): array
    {
        if ($this->isStrictMode()) {
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
     * 获取原始数据.
     *
     * @return array
     */
    public function getOrigin(): array
    {
        return self::$weakMap[$this]['origin'];
    }

    /**
     * 模型数据转数组.
     *
     * @param array $allow 允许输出字段
     * @return array
     */
    public function toArray(array $allow = []): array
    {
        $data = $this->getData();
        if (empty($allow)) {
            foreach (['visible', 'hidden', 'append'] as $convert) {
                ${$convert} = self::$weakMap[$this][$convert];
                foreach (${$convert} as $key => $val) {
                    if (is_string($key)) {
                        $relation[$key][$convert] = $val;
                        unset(${$convert}[$key]);
                    } elseif (str_contains($val, '.')) {
                        [$relName, $name]               = explode('.', $val);
                        $relation[$relName][$convert][] = $name;
                        unset(${$convert}[$key]);
                    }
                }
            }
            $allow = array_diff($visible ?: array_keys($data), $hidden);
        }

        foreach ($data as $name => &$item) {
            if ($item instanceof Entity || $item instanceof Collection) {
                if (!empty($relation[$name])) {
                    // 处理关联数据输出
                    foreach ($relation[$name] as $key => $val) {
                        $item->$key($val);
                    }
                }
                $item = $item->toarray();
            } elseif (!empty($allow) && !in_array($name, $allow)) {
                unset($data[$name]);
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

            if (isset(self::$weakMap[$this]['mapping'][$name])) {
                // 检查字段映射
                $key        = self::$weakMap[$this]['mapping'][$name];
                $data[$key] = $data[$name];
                unset($data[$name]);
            }
        }

        // 输出额外属性 必须定义获取器
        foreach (self::$weakMap[$this]['append'] as $key) {
            $data[$key] = $this->get($key);
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
     * 设置数据对象的值
     *
     * @param string $name  名称
     * @param mixed  $value 值
     *
     * @return void
     */
    public function set(string $name, $value): void
    {
        if (!empty(self::$weakMap[$this]['mapping'])) {
            $key = array_search($name, self::$weakMap[$this]['mapping']);
            if (is_string($key)) {
                $name = $key;
            }
        }
        $name = $this->getRealFieldName($name);
        if ($this->isStrictMode()) {
            $this->$name = $value;
        } else {
            $this->setData('data', $name, $value);
        }
    }

    /**
     * 获取数据对象的值（使用获取器）
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function get(string $name)
    {
        if (isset(self::$weakMap[$this]['mapping'][$name])) {
            // 检查字段映射
            $name = self::$weakMap[$this]['mapping'][$name];
        }

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

    public function getValue(string $name)
    {
        if ($this->isStrictMode()) {
            return $this->$name ?? null;
        }
        return self::$weakMap[$this]['data'][$name] ?? null;
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
     * 获取属性（非严格模式）
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
     * 设置数据（非严格模式）
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
     * 检测数据对象的值（非严格模式）
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
     * 销毁数据对象的值（非严格模式）
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

    public function __debugInfo()
    {
        if (!$this->isStrictMode()) {
            return [
                'data'   => self::$weakMap[$this]['data'],
                'origin' => self::$weakMap[$this]['origin'],
                'schema' => self::$weakMap[$this]['schema'],
            ];
        } else {
            return [
                'origin' => self::$weakMap[$this]['origin'],
                'schema' => self::$weakMap[$this]['schema'],
            ];
        }
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
        return call_user_func_array([$this->model(), $method], $args);
    }
}
