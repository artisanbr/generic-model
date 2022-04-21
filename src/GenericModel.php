<?php namespace Renalcio\GModel;

use Illuminate\Support\Collection as BaseCollection;
use DevApex\Model\Contracts\CastsInboundAttributes;
use Jenssegers\Model\Model;

abstract class GenericModel extends Model
{
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
        'float',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    public function __construct(array $attributes = [])
    {
        $this->called_class = $this->called_class ?? get_called_class();
        parent::__construct($attributes);
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
        foreach ($this->casts as $key => $value) {
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
        if (strpos($castType = $this->casts[$key], ':') === false) {
            return new $castType;
        }

        $segments = explode(':', $castType, 2);

        return new $segments[0](...explode(',', $segments[1]));
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
                return (float) $value;
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
        }

        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }

        return $value;
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
