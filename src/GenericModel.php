<?php
/*
 * Copyright (c) 2023. Tanda Interativa - Todos os Direitos Reservados
 * Desenvolvido por Renalcio Carlos Jr.
 */

namespace ArtisanLabs\GModel;

use ArtisanLabs\GModel\Concerns\HasCastables;
use ArtisanLabs\GModel\Contracts\CastsAttributes;
use ArtisanLabs\GModel\Contracts\CastsInboundAttributes;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jenssegers\Model\Model;
use JsonException;
use JsonSerializable;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

abstract class GenericModel extends Model implements CastsAttributes
{
    use HasTimestamps, HasCastables;

    protected $called_class = null;

    /**
     * The attributes that have been cast using custom classes.
     *
     * @var array
     */
    protected $classCastCache = [];

    /**
     * The attributes that have been cast using "Attribute" return type mutators.
     *
     * @var array
     */
    protected $attributeCastCache = [];

    /**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var array
     */
    protected static $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'immutable_date',
        'immutable_datetime',
        'immutable_custom_datetime',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    protected static $nullable = false;

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    protected $dateFormat = 'd/m/Y H:i:s';

    /**
     * The cache of the mutated attributes for each class.
     *
     * @var array
     */
    protected static $mutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     *
     * @var array
     */
    protected static $attributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, gettable attributes for each class.
     *
     * @var array
     */
    protected static $getAttributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, settable attributes for each class.
     *
     * @var array
     */
    protected static $setAttributeMutatorCache = [];

    /**
     * The cache of the converted cast types.
     *
     * @var array
     */
    protected static $castTypeCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var \Illuminate\Contracts\Encryption\Encrypter
     */
    public static $encrypter;

    /**
     * The model attribute's original state.
     *
     * @var array
     */
    protected $original = [];

    protected $temporary = [];

    /**
     * Indicates if an exception should be thrown instead of silently discarding non-fillable attributes.
     *
     * @var bool
     */
    protected static bool $modelsShouldPreventSilentlyDiscardingAttributes = false;

    public function __construct(array $attributes = [])
    {

        $this->called_class = $this->called_class ?? get_called_class();

        $this->syncOriginal();

        parent::__construct($attributes);
    }

    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        $fillable = $this->fillableFromArray($attributes);

        foreach ($fillable as $key => $value) {
            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
            else if ($totallyGuarded || static::preventsSilentlyDiscardingAttributes()) {
                throw new MassAssignmentException(sprintf(
                                                      'Add [%s] to fillable property to allow mass assignment on [%s].',
                                                      $key, get_class($this)
                                                  ));
            }
        }


        if (static::preventsSilentlyDiscardingAttributes() && count($attributes) !== count($fillable)) {
            throw new MassAssignmentException(sprintf(
                                                  'Add fillable property [%s] to allow mass assignment on [%s].',
                                                  implode(', ', array_diff(array_keys($attributes), array_keys($fillable))),
                                                  get_class($this)
                                              ));
        }

        return $this;

        //return parent::fill($attributes); // TODO: Change the autogenerated stub
    }

    public function addCasts($casts = null)
    {
        $casts = is_array($casts) ? $casts : func_get_args();

        $this->casts = array_merge($this->casts, $casts);
    }

    public function addAttributes($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function addFillables($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->fillable = array_merge($this->fillable, $attributes);
    }

    public function addAppends($appends = null)
    {
        $appends = is_array($appends) ? $appends : func_get_args();

        $this->appends = array_merge($this->appends, $appends);
    }

    /**
     * Append attributes to query when building a query.
     *
     * @param array|string $attributes
     *
     * @return $this
     */
    public function append(array|string $attributes): static
    {
        $this->appends = collect($this->appends)->merge($attributes)->toArray();

        return $this;
    }

    public static function preventsSilentlyDiscardingAttributes()
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }

    /**
     * Sync the original attributes with the current.
     *
     * @return $this
     */
    public function syncOriginal()
    {
        $this->original = $this->getAttributes();

        return $this;
    }

    /**
     * Is model Nullable?
     *
     * @return bool
     */
    public static function nullable(): bool
    {
        return static::$nullable;
    }

    public function isNullable(): bool
    {
        return static::$nullable;
    }

    /**
     * Get model dates
     *
     * @return array|bool|float|BaseCollection|int|mixed|string|null
     */
    public function getDates()
    {
        return $this->dates ?? [];
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->attributesToArray();
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes();


        $mutatedAttributes = $this->getMutatedAttributes();

        // We want to spin through all the mutated attributes for this model and call
        // the mutator for the attribute. We cache off every mutated attributes so
        // we don't have to constantly check on attributes that actually change.
        foreach ($mutatedAttributes as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->mutateAttributeForArray(
                $key, $attributes[$key]
            );
        }

        // Next we will handle any casts that have been setup for this model and cast
        // the values to their appropriate type. If the attribute has a mutator we
        // will not perform the cast on those attributes to avoid any confusion.
        $attributes = $this->addCastAttributesToArray(
            $attributes, $mutatedAttributes
        );

        // Here we will grab all of the appended, calculated attributes to this model
        // as these attributes are not really in the attributes array, but are run
        // when we need to array or JSON the model for convenience to the coder.

        foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }

        parent::attributesToArray();

        return $attributes;
    }

    /**
     * Get the value of an attribute using its mutator for array conversion.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected function mutateAttributeForArray($key, $value)
    {
        $value = $this->mutateAttribute($key, $value);


        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     * @param array $attributes
     * @param array $mutatedAttributes
     *
     * @return array
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
    {

        foreach ($this->getCasts() as $key => $value) {
            if (!array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)) {
                continue;
            }

            if ($this->isClassCastable($key)) {

                $castedValue = $this->castAttribute($key, $attributes[$key]);

                if ($castedValue instanceof Arrayable && !($castedValue instanceof UnitEnum)) {
                    $attributes[$key] = $castedValue->toArray();
                    continue;
                }
            }

            // Here we will cast the attribute. Then, if the cast is a date or datetime cast
            // then we will serialize the date for the array. This will convert the dates
            // to strings based on the date format specified for these Eloquent models.
            $attributes[$key] = $this->castAttribute(
                $key, $attributes[$key]
            );
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable attributes.
     *
     * @return array
     */
    protected
    function getArrayableAttributes()
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * @param string $key
     *
     * @return mixed
     */
    protected
    function getAttributeValue($key)
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Transform a raw model value using mutators, casts, etc.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function transformModelValue($key, $value)
    {
        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }
        else if ($this->hasAttributeGetMutator($key)) {
            return $this->mutateAttributeMarkedAttribute($key, $value);
        }

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependent upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if ($value !== null
            && \in_array($key, $this->getDates(), false)) {
            return $this->asDateTime($value);
        }

        // If the attribute is castable via a class, cast it.
        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }

        return parent::getAttributeValue($key);
    }

    /**
     * Get the value of an "Attribute" return type marked attribute using its mutator.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function mutateAttributeMarkedAttribute($key, $value)
    {
        if (array_key_exists($key, $this->attributeCastCache)) {
            return $this->attributeCastCache[$key];
        }

        $attribute = $this->{Str::camel($key)}();

        $value = call_user_func($attribute->get ?: function ($value) {
            return $value;
        },                      $value, $this->attributes);

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        }
        else {
            unset($this->attributeCastCache[$key]);
        }

        return $value;
    }

    /**
     * Determine if a "Attribute" return type marked get mutator exists for an attribute.
     *
     * @param string $key
     *
     * @return bool
     */
    public
    function hasAttributeGetMutator($key)
    {
        if (isset(static::$getAttributeMutatorCache[get_class($this)][$key])) {
            return static::$getAttributeMutatorCache[get_class($this)][$key];
        }

        if (!$this->hasAttributeMutator($key)) {
            return static::$getAttributeMutatorCache[get_class($this)][$key] = false;
        }

        return static::$getAttributeMutatorCache[get_class($this)][$key] = is_callable($this->{Str::camel($key)}()->get);
    }

    /**
     * Determine if a "Attribute" return type marked mutator exists for an attribute.
     *
     * @param string $key
     *
     * @return bool
     */
    public
    function hasAttributeMutator($key): bool
    {
        if (isset(static::$attributeMutatorCache[get_class($this)][$key])) {
            return static::$attributeMutatorCache[get_class($this)][$key];
        }

        if (!method_exists($this, $method = Str::camel($key))) {
            return static::$attributeMutatorCache[get_class($this)][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$attributeMutatorCache[get_class($this)][$key] =
            ($returnType instanceof ReflectionNamedType &&
                $returnType->getName() === Attribute::class);
    }

    /**
     * Get an attribute from the $attributes array.
     *
     * @param string $key
     *
     * @return mixed
     */
    protected
    function getAttributeFromArray($key)
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     * @param string $key
     *
     * @return bool
     */
    protected
    function isClassCastable($key)
    {

        $casts = $this->getCasts();

        if (!array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        /*return array_key_exists($key, $this->casts) &&
                class_exists($class = $this->parseCasterClass($this->casts[$key])) &&
                ! in_array($class, static::$primitiveCastTypes);*/
    }

    /**
     * Resolve the custom caster class for a given key.
     *
     * @param string $key
     *
     * @return mixed
     */
    protected
    function resolveCasterClass($key)
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && str_contains($castType, ':')) {
            $segments = explode(':', $castType, 2);

            $castType = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castType, Castable::class)) {
            $castType = $castType::castUsing($arguments);
        }

        if (is_object($castType)) {
            return $castType;
        }

        return new $castType(...$arguments);


        /*if (strpos($castType = $this->casts[$key], ':') === false) {
            return new $castType;
        }

        $segments = explode(':', $castType, 2);

        return new $segments[0](...explode(',', $segments[1]));*/
    }

    /**
     * Parse the given caster class, removing any arguments.
     *
     * @param string $class
     *
     * @return string
     */
    protected
    function parseCasterClass($class)
    {
        return strpos($class, ':') === false
            ? $class
            : explode(':', $class, 2)[0];
    }

    /**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected
    function mergeAttributesFromClassCasts()
    {
        foreach ($this->classCastCache as $key => $value) {
            $caster = $this->resolveCasterClass($key);

            $this->attributes = array_merge(
                $this->attributes,
                $caster instanceof CastsInboundAttributes
                    ? [$key => $value]
                    : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
            );
        }
    }

    /**
     * Normalize the response from a custom class caster.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return array
     */
    protected
    function normalizeCastClassResponse($key, $value)
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return $value;
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int)$value;
            case 'real':
            case 'float':
            case 'double':
                return $this->fromFloat($value);
            case 'decimal':
                return $this->asDecimal($value, explode(':', $this->getCasts()[$key], 2)[1]);
            case 'string':
                return (string)$value;
            case 'bool':
            case 'boolean':
                return (bool)$value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
                return is_string($value) ? $this->fromJson($value) : $value;
            case 'collection':
                return new BaseCollection(is_string($value) ? $this->fromJson($value) : $value);
            case 'date':
                return $this->asDate($value);
            case 'datetime':
            case 'custom_datetime':
                return $this->asDateTime($value);
            case 'immutable_date':
                return $this->asDate($value)->toImmutable();
            case 'immutable_custom_datetime':
            case 'immutable_datetime':
                return $this->asDateTime($value)->toImmutable();
            case 'timestamp':
                return $this->asTimestamp($value);
        }

        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }


        return $value;
    }

    /**
     * Determine if the given key is cast using an enum.
     *
     * @param string $key
     *
     * @return bool
     */
    protected
    function isEnumCastable($key)
    {
        $casts = $this->getCasts();

        if (!array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (function_exists('enum_exists') && enum_exists($castType)) {
            return true;
        }
    }

    /**
     * Cast the given attribute to an enum.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function getEnumCastableAttributeValue($key, $value)
    {
        if (is_null($value)) {
            return;
        }

        $castType = $this->getCasts()[$key];

        if ($value instanceof $castType) {
            return $value;
        }

        return $castType::from($value);
    }

    /**
     * Set the value of an enum castable attribute.
     *
     * @param string      $key
     * @param \BackedEnum $value
     *
     * @return void
     */
    protected
    function setEnumCastableAttribute($key, $value)
    {
        $enumClass = $this->getCasts()[$key];

        if (!isset($value)) {
            $this->attributes[$key] = null;
        }
        else if ($value instanceof $enumClass) {
            $this->attributes[$key] = $value->value;
        }
        else {
            $this->attributes[$key] = $enumClass::tryFrom($value)?->value ?? null;
        }
    }

    /**
     * Return a timestamp as unix timestamp.
     *
     * @param mixed $value
     *
     * @return int
     */
    protected
    function asTimestamp($value)
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * @param mixed $value
     *
     * @return \Illuminate\Support\Carbon
     */
    protected
    function asDate($value)
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param mixed $value
     *
     * @return \Illuminate\Support\Carbon
     */
    protected
    function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Date::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'), $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        try {
            $date = Date::createFromFormat($format, $value);
        } catch (InvalidArgumentException $e) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param string            $key
     * @param array|string|null $types
     *
     * @return bool
     */
    public
    function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array)$types, true) : true;
        }

        return false;
    }

    /**
     * Get the type of cast for a model attribute.
     *
     * @param string $key
     *
     * @return string
     */
    protected
    function getCastType($key)
    {
        $castType = $this->getCasts()[$key];

        if (isset(static::$castTypeCache[$castType])) {
            return static::$castTypeCache[$castType];
        }

        if ($this->isCustomDateTimeCast($castType)) {
            $convertedCastType = 'custom_datetime';
        }
        else if ($this->isImmutableCustomDateTimeCast($castType)) {
            $convertedCastType = 'immutable_custom_datetime';
        }
        else if ($this->isDecimalCast($castType)) {
            $convertedCastType = 'decimal';
        }
        else {
            $convertedCastType = trim(strtolower($castType));
        }

        return static::$castTypeCache[$castType] = $convertedCastType;
    }

    /**
     * Determine if the cast type is a custom date time cast.
     *
     * @param string $cast
     *
     * @return bool
     */
    protected
    function isCustomDateTimeCast($cast)
    {
        return str_starts_with($cast, 'date:') ||
            str_starts_with($cast, 'datetime:');
    }

    /**
     * Determine if the cast type is an immutable custom date time cast.
     *
     * @param string $cast
     *
     * @return bool
     */
    protected
    function isImmutableCustomDateTimeCast($cast)
    {
        return str_starts_with($cast, 'immutable_date:') ||
            str_starts_with($cast, 'immutable_datetime:');
    }

    /**
     * Determine if the cast type is a decimal cast.
     *
     * @param string $cast
     *
     * @return bool
     */
    protected
    function isDecimalCast($cast)
    {
        return str_starts_with($cast, 'decimal:');
    }

    /**
     * Convert a DateTime to a storable string.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    public
    function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Determine if the given attribute is a date or date castable.
     *
     * @param string $key
     *
     * @return bool
     */
    protected
    function isDateAttribute($key)
    {
        return in_array($key, $this->getDates(), true) ||
            $this->isDateCastable($key);
    }

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     *
     * @param string $key
     *
     * @return bool
     */
    protected
    function isDateCastable($key)
    {
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public
    function getDateFormat()
    {
        return $this->dateFormat;
    }

    /**
     * Determine if the given value is a standard date format.
     *
     * @param string $value
     *
     * @return bool
     */
    protected
    function isStandardDateFormat($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Return a decimal as string.
     *
     * @param float $value
     * @param int   $decimals
     *
     * @return string
     */
    protected
    function asDecimal($value, $decimals)
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Decode the given float.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public
    function fromFloat($value)
    {
        return match ((string)$value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float)$value,
        };
    }

    /**
     * Cast the given attribute to JSON.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return string
     */
    protected
    function castAttributeAsJson($key, $value)
    {
        $value = $this->asJson($value);

        if ($value === false) {
            throw JsonEncodingException::forAttribute(
                $this, $key, json_last_error_msg()
            );
        }

        return $value;
    }

    /**
     * Set a given JSON attribute on the model.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public
    function fillJsonAttribute($key, $value)
    {
        [$key, $path] = explode('->', $key, 2);

        $value = $this->asJson($this->getArrayAttributeWithValue(
            $path, $key, $value
        ));

        $this->attributes[$key] = $this->isEncryptedCastable($key)
            ? $this->castAttributeAsEncryptedString($key, $value)
            : $value;

        if ($this->isClassCastable($key)) {
            unset($this->classCastCache[$key]);
        }

        return $this;
    }

    /**
     * Cast the given attribute to an encrypted string.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return string
     */
    protected
    function castAttributeAsEncryptedString($key, $value)
    {
        return (static::$encrypter ?? Crypt::getFacadeRoot())->encrypt($value, false);
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     *
     * @param string $key
     *
     * @return bool
     */
    protected
    function isEncryptedCastable($key)
    {
        return $this->hasCast($key, [
            'encrypted',
            'encrypted:array',
            'encrypted:collection',
            'encrypted:json',
            'encrypted:object',
        ]);
    }

    /**
     * Get an array attribute with the given key and value set.
     *
     * @param string $path
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    protected
    function getArrayAttributeWithValue($path, $key, $value)
    {
        return tap($this->getArrayAttributeByKey($key), function (&$array) use ($path, $value) {
            Arr::set($array, str_replace('->', '.', $path), $value);
        });
    }

    /**
     * Get an array attribute or return an empty array if it is not set.
     *
     * @param string $key
     *
     * @return array
     */
    protected
    function getArrayAttributeByKey($key)
    {
        if (!isset($this->attributes[$key])) {
            return [];
        }

        return $this->fromJson(
            $this->isEncryptedCastable($key)
                ? $this->fromEncryptedString($this->attributes[$key])
                : $this->attributes[$key]
        );
    }

    /**
     * Cast the given attribute using a custom cast class.
     *
     * @param string $key
     *
     * @return mixed
     */
    protected
    function getClassCastableAttributeValue($key)
    {
        if (isset($this->classCastCache[$key])) {
            return $this->classCastCache[$key];
        }
        else {
            $caster = $this->resolveCasterClass($key);

            return $this->classCastCache[$key] = $caster instanceof CastsInboundAttributes
                ? ($this->attributes[$key] ?? null)
                : $caster->get($this, $key, $this->attributes[$key] ?? null, $this->attributes);
        }
    }

    /**
     * Set a given attribute on the model.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public
    function setAttribute($key, $value)
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // this model, such as "json_encoding" a listing of data for storage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }
        else if ($this->hasAttributeSetMutator($key)) {
            return $this->setAttributeMarkedMutatedAttributeValue($key, $value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        else if ($value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isEnumCastable($key)) {
            $this->setEnumCastableAttribute($key, $value);

            return $this;
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (!is_null($value) && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (str_contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (!is_null($value) && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;

        //return parent::setAttribute($key, $value);
    }

    /**
     * Set the value of a "Attribute" return type marked attribute using its mutator.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function setAttributeMarkedMutatedAttributeValue($key, $value)
    {
        $attribute = $this->{Str::camel($key)}();

        $callback = $attribute->set ?: function ($value) use ($key) {
            $this->attributes[$key] = $value;
        };

        $this->attributes = array_merge(
            $this->attributes,
            $this->normalizeCastClassResponse(
                $key, $callback($value, $this->attributes)
            )
        );

        if ($attribute->withCaching || (is_object($value) && $attribute->withObjectCaching)) {
            $this->attributeCastCache[$key] = $value;
        }
        else {
            unset($this->attributeCastCache[$key]);
        }
    }

    /**
     * Determine if an "Attribute" return type marked set mutator exists for an attribute.
     *
     * @param string $key
     *
     * @return bool
     */
    public
    function hasAttributeSetMutator($key)
    {
        $class = get_class($this);

        if (isset(static::$setAttributeMutatorCache[$class][$key])) {
            return static::$setAttributeMutatorCache[$class][$key];
        }

        if (!method_exists($this, $method = Str::camel($key))) {
            return static::$setAttributeMutatorCache[$class][$key] = false;
        }

        $returnType = (new ReflectionMethod($this, $method))->getReturnType();

        return static::$setAttributeMutatorCache[$class][$key] =
            ($returnType instanceof ReflectionNamedType &&
                $returnType->getName() === Attribute::class &&
                is_callable($this->{$method}()->set));
    }

    /**
     * Set the value of an attribute using its mutator.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected
    function setMutatedAttributeValue($key, $value)
    {
        return $this->{'set' . Str::studly($key) . 'Attribute'}($value);
    }

    /**
     * Set the value of a class castable attribute.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    protected
    function setClassCastableAttribute($key, $value)
    {
        if (is_null($value)) {
            $this->attributes = array_merge($this->attributes, array_map(
                function () {},
                $this->normalizeCastClassResponse($key, $this->resolveCasterClass($key)->set(
                    $this, $key, $this->{$key}, $this->attributes
                ))
            ));
        }
        else {
            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse($key, $this->resolveCasterClass($key)->set(
                    $this, $key, $value, $this->attributes
                ))
            );
        }

        unset($this->classCastCache[$key]);
    }


    /**
     * Get all of the current attributes on the model.
     *
     * @return array
     */
    public
    function getAttributes()
    {
        $this->mergeAttributesFromClassCasts();

        return parent::getAttributes();
    }

    /**
     * Set the array of model attributes. No checking is done.
     *
     * @param array $attributes
     *
     * @return $this
     */
    public
    function setRawAttributes(array $attributes)
    {
        $this->attributes = $attributes;

        $this->classCastCache = [];

        return $this;
    }

    /**
     * @throws JsonException
     */
    protected
    function buildCastAttributes(string|self|array|null $value): array
    {

        try {

            if (is_string($value)) {
                return $value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY) ?: [] : [];

            }

            if ($value instanceof static) {
                return $value->toArray();
            }

            return $value ?? [];

        } catch (\Exception $e) {
            dd([
                   'value'   => $value,
                   'code'    => $e->getCode(),
                   'message' => $e->getMessage(),
                   'file'    => $e->getFile(),
                   'line'    => $e->getLine(),
                   'trace'   => $e->getTrace(),
               ]);

        }
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {

        $attributes = collect($this->getArrayableAttributes())->forget($this->appends)->forget($this->temporary)->toArray();


        $mutatedAttributes = collect($this->getMutatedAttributes())->forget($this->appends)->forget($this->temporary)->toArray();

        // We want to spin through all the mutated attributes for this model and call
        // the mutator for the attribute. We cache off every mutated attributes so
        // we don't have to constantly check on attributes that actually change.
        foreach ($mutatedAttributes as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->mutateAttributeForJsonSerialize(
                $key, $attributes[$key]
            );
        }

        // Next we will handle any casts that have been setup for this model and cast
        // the values to their appropriate type. If the attribute has a mutator we
        // will not perform the cast on those attributes to avoid any confusion.
        $attributes = $this->addCastAttributesToJsonSerialize(
            $attributes, $mutatedAttributes
        );

        /*foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForJsonSerialize($key, null);
        }*/

        return collect($attributes)->forget($this->appends)->forget($this->temporary)->toArray(); //$this->toArray();
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     * @param array $attributes
     * @param array $mutatedAttributes
     *
     * @return array
     */
    protected function addCastAttributesToJsonSerialize(array $attributes, array $mutatedAttributes)
    {

        foreach ($this->getCasts() as $key => $value) {
            if (!array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)) {
                continue;
            }

            if ($this->isClassCastable($key)) {

                $castedValue = $this->castAttribute($key, $attributes[$key]);

                if ($castedValue instanceof JsonSerializable && !($castedValue instanceof UnitEnum)) {
                    $attributes[$key] = $castedValue->jsonSerialize();
                    continue;
                }
            }

            // Here we will cast the attribute. Then, if the cast is a date or datetime cast
            // then we will serialize the date for the array. This will convert the dates
            // to strings based on the date format specified for these Eloquent models.
            $attributes[$key] = $this->castAttribute(
                $key, $attributes[$key]
            );
        }

        return $attributes;
    }

    /**
     * Get the value of an attribute using its mutator for json serializable conversion.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected function mutateAttributeForJsonSerialize($key, $value)
    {
        $value = $this->mutateAttribute($key, $value);


        return $value instanceof GenericModel ? $value->jsonSerialize() : $value;
    }

    public
    static function make(array $attributes = []): static
    {
        return (new static($attributes));
    }

    /**
     * @throws JsonException
     */
    public function get($model, $key, $value, $attributes)
    {
        try {
            /*if($key == "breadcrumb"){
                dd($this->isNullable() && empty($value));
                dd("gModel get: $key", $value);
                //dd($this->isNullable());
            }*/

            return ($this->isNullable() && empty($value)) ? null : new static($this->buildCastAttributes($value));

        } catch (\Exception $e) {
            dump("exception get: $key", $value, $attributes[$key]);
        }
    }


    public function set($model, $key, $value, $attributes)
    {
        /*if ($key == 'breadcrumb') {
            dd("gModel set: $key", $value, $attributes[$key]);
            dd(static::make($currentAttributes)->fill($this->buildCastAttributes($value))->jsonSerialize());
        }*/

        try {
            if ($value) {

                $currentAttributes = $this->buildCastAttributes($attributes[$key] ?? []);

                return [$key => collect(static::make($currentAttributes)->fill($this->buildCastAttributes($value))->jsonSerialize())->toJson()];
            }

            return [$key => ($this->isNullable() && empty($value)) ? null : json_encode($value)];//['address_line_one' => $value->lineOne, 'address_line_two' => $value->lineTwo];

        } catch (\Exception $e) {
            dump("exception set: $key", $value, $attributes[$key]);
        }
    }
}

class_alias(GenericModel::class, 'ArtisanLabs\GModel\Model');
