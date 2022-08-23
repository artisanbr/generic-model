<?php namespace Renalcio\GModel;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Renalcio\GModel\Concerns\HasCastables;
use Renalcio\GModel\Contracts\CastsInboundAttributes;
use Jenssegers\Model\Model;
use Renalcio\GModel\Contracts\CastsAttributes;

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

    public function __construct($attributes = [])
    {
        if(!is_array($attributes)){
            $attributes = !empty($attributes) ? (is_string($attributes) ? json_encode($attributes, JSON_OBJECT_AS_ARRAY) : $attributes) : [];
        }

        $this->called_class = $this->called_class ?? get_called_class();


        parent::__construct($attributes);
    }

    /**
     * Is model Nullable?
     * @return bool
     */
    public static function nullable() : bool {
        return static::$nullable;
    }

    /**
     * Get model dates
     * @return array|bool|float|BaseCollection|int|mixed|string|null
     */
    public function getDates()
    {
        return $this->dates ?? [];
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
            if (! array_key_exists($key, $attributes)) {
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

        return $attributes;
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     * @param  array  $attributes
     * @param  array  $mutatedAttributes
     * @return array
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
    {
        foreach ($this->getCasts() as $key => $value) {
            if (! array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes) ||
                $this->isClassCastable($key)) {
                continue;
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
    protected function getArrayableAttributes()
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getAttributeValue($key)
    {
        // If the attribute is castable via a class, cast it.
        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }

        return parent::getAttributeValue($key);
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isClassCastable($key)
    {
        return array_key_exists($key, $this->casts) &&
                class_exists($class = $this->parseCasterClass($this->casts[$key])) &&
                ! in_array($class, static::$primitiveCastTypes);
    }

    /**
     * Resolve the custom caster class for a given key.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function resolveCasterClass($key)
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
     * @param  string  $class
     * @return string
     */
    protected function parseCasterClass($class)
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
    protected function mergeAttributesFromClassCasts()
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
     * @param  string  $key
     * @param  mixed  $value
     * @return array
     */
    protected function normalizeCastClassResponse($key, $value)
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return $value;
        }


        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return $this->fromFloat($value);
            case 'decimal':
                return $this->asDecimal($value, explode(':', $this->getCasts()[$key], 2)[1]);
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
                return $this->fromJson($value);
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

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }

        if ($this->isEnumCastable($key)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        return $value;
    }

    /**
     * Determine if the given key is cast using an enum.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEnumCastable($key)
    {
        if (! array_key_exists($key, $this->getCasts())) {
            return false;
        }

        $castType = $this->getCasts()[$key];

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
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function getEnumCastableAttributeValue($key, $value)
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
     * Return a timestamp as unix timestamp.
     *
     * @param  mixed  $value
     * @return int
     */
    protected function asTimestamp($value)
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDate($value)
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDateTime($value)
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
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat;
    }

    /**
     * Determine if the given value is a standard date format.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isStandardDateFormat($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Return a decimal as string.
     *
     * @param  float  $value
     * @param  int  $decimals
     * @return string
     */
    protected function asDecimal($value, $decimals)
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Decode the given float.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromFloat($value)
    {
        return match ((string) $value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float) $value,
        };
    }

    /**
     * Cast the given attribute using a custom cast class.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getClassCastableAttributeValue($key)
    {
        if (isset($this->classCastCache[$key])) {
            return $this->classCastCache[$key];
        } else {
            $caster = $this->resolveCasterClass($key);

            return $this->classCastCache[$key] = $caster instanceof CastsInboundAttributes
                ? ($this->attributes[$key] ?? null)
                : $caster->get($this, $key, $this->attributes[$key] ?? null, $this->attributes);
        }
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Set the value of a class castable attribute.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    protected function setClassCastableAttribute($key, $value)
    {
        if (is_null($value)) {
            $this->attributes = array_merge($this->attributes, array_map(
                function () {
                },
                $this->normalizeCastClassResponse($key, $this->resolveCasterClass($key)->set(
                    $this, $key, $this->{$key}, $this->attributes
                ))
            ));
        } else {
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
    public function getAttributes()
    {
        $this->mergeAttributesFromClassCasts();

        return parent::getAttributes();
    }

    /**
     * Set the array of model attributes. No checking is done.
     *
     * @param  array  $attributes
     * @return $this
     */
    public function setRawAttributes(array $attributes)
    {
        $this->attributes = $attributes;

        $this->classCastCache = [];

        return $this;
    }

    protected function buildCastAttributes($value){

        if(is_string($value)){
            return json_decode($value, true);
        }else if(is_object($value) && $value instanceof $this->called_class){
            return $value->toArray();
        }

        return $value ?? [];
    }

    public function get($model, $key, $value, $attributes)
    {
        return new $this->called_class($this->buildCastAttributes($value));
    }

    public function set($model, $key, $value, $attributes)
    {

        if($value && !($value instanceof $this->called_class)){
            $value = new $this->called_class($this->buildCastAttributes($value));
        }

        return [$key => $value];//['address_line_one' => $value->lineOne, 'address_line_two' => $value->lineTwo];
    }
}

class_alias(GenericModel::class, 'Renalcio\GModel\Model');
