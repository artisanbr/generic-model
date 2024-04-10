<?php
/*
 * Copyright (c) 2023-2024. Artisan Digital - Todos os Direitos Reservados
 * Desenvolvido por Renalcio Carlos Jr.
 */

namespace ArtisanBR\GenericModel;

use ArrayAccess;
use ArrayObject;
use ArtisanBR\GenericModel\Concerns\HasCastables;
use ArtisanBR\GenericModel\Contracts\CastsAttributes as GenericCastsAttributes;
use ArtisanBR\GenericModel\Contracts\CastsInboundAttributes as GenericCastsInboundAttributes;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use ReflectionMethod;
use ReflectionNamedType;
use ReturnTypeWillChange;
use UnitEnum;

abstract class Model implements CastsAttributes, ArrayAccess, Arrayable, Jsonable, JsonSerializable, GenericCastsAttributes
{
    use HasTimestamps, HasCastables;

    protected $called_class = null;

    //Fix desnecessary loop
    protected $relations = [];

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
     * The model's attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be visible in arrays.
     *
     * @var array
     */
    protected $visible = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Indicates whether attributes are snake cased on arrays.
     *
     * @var bool
     */
    public static $snakeAttributes = true;

    /**
     * Indicates if all mass assignment is enabled.
     *
     * @var bool
     */
    protected static $unguarded = false;

    /**
     * The cache of the mutated attributes for each class.
     *
     */
    protected static array $mutatorCache = [];

    /**
     * The storage format of the model's date columns.
     *
     */
    protected string $dateFormat = 'd/m/Y H:i:s';

    /**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     *
     * @var array
     */
    protected static array $attributeMutatorCache = [];

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
     */
    protected static bool $modelsShouldPreventSilentlyDiscardingAttributes = false;

    /**
     * Create a new Eloquent model instance.
     *
     */
    public function __construct(array $attributes = [])
    {
        $this->called_class = $this->called_class ?? get_called_class();

        $this->syncOriginal();

        $this->fill($attributes);
    }

    /**
     * Create Model Instance on Static way
     */
    public static function make(array $attributes = []): static
    {
        return (new static($attributes));
    }

    /**
     * Sync the original attributes with the current.
     *
     */
    public function syncOriginal(): self
    {
        $this->original = $this->getAttributes();

        return $this;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): self
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
    }

    /**
     * Check if instance may prevent silently discarding attributes
     */
    public static function preventsSilentlyDiscardingAttributes(): bool
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }

    /**
     * Fill the model with an array of attributes. Force mass assignment.
     *
     */
    public function forceFill(array $attributes): static
    {
        // Since some versions of PHP have a bug that prevents it from properly
        // binding the late static context in a closure, we will first store
        // the model in a variable, which we will then use in the closure.
        $model = $this;

        return static::unguarded(function () use ($model, $attributes) {
            return $model->fill($attributes);
        });
    }

    /**
     * Get the fillable attributes of a given array.
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->fillable) > 0 && !static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

        return $attributes;
    }

    /**
     * Create a new instance of the given model.
     */
    public function newInstance(array $attributes = []): static
    {
        return new static($attributes);
    }

    public function addCasts($casts = null): void
    {
        $casts = is_array($casts) ? $casts : func_get_args();

        $this->casts = array_merge($this->casts, $casts);
    }

    public function addAttributes($attributes = null): void
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function addFillables($attributes = null): void
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->fillable = array_merge($this->fillable, $attributes);
    }

    public function addAppends($appends = null): void
    {
        $appends = is_array($appends) ? $appends : func_get_args();

        $this->appends = array_merge($this->appends, $appends);
    }

    /**
     * Create a collection of models from plain arrays.
     */
    public static function hydrate(array $items): array
    {
        $instance = new static;

        $items = array_map(function ($item) use ($instance) {
            return $instance->newInstance($item);
        }, $items);

        return $items;
    }

    /**
     * Get the hidden attributes for the model.
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Set the hidden attributes for the model.
     */
    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Add hidden attributes for the model.
     */
    public function addHidden($attributes = null): void
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->hidden = array_merge($this->hidden, $attributes);
    }

    /**
     * Make the given, typically hidden, attributes visible.
     */
    public function withHidden(array $attributes): static
    {
        $this->hidden = array_diff($this->hidden, (array)$attributes);

        return $this;
    }

    /**
     * Get the visible attributes for the model.
     *
     * @return array
     */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /**
     * Set the visible attributes for the model.
     */
    public function setVisible(array $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Add visible attributes for the model.
     */
    public function addVisible($attributes = null): void
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->visible = array_merge($this->visible, $attributes);
    }

    /**
     * Append attributes to query when building a query.
     *
     */
    public function append(array|string $attributes): static
    {
        $this->appends = collect($this->appends)->merge($attributes)->toArray();

        return $this;
    }

    /**
     * Set the accessors to append to model arrays.
     */
    public function setAppends(array $appends): static
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Get the fillable attributes for the model.
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Set the fillable attributes for the model.
     */
    public function fillable(array $fillable): static
    {
        $this->fillable = $fillable;

        return $this;
    }

    /**
     * Get the guarded attributes for the model.
     *
     * @return array
     */
    public function getGuarded()
    {
        return $this->guarded;
    }

    /**
     * Set the guarded attributes for the model.
     */
    public function guard(array $guarded): static
    {
        $this->guarded = $guarded;

        return $this;
    }

    /**
     * Disable all mass assignable restrictions.
     */
    public static function unguard(bool $state = true): void
    {
        static::$unguarded = $state;
    }

    /**
     * Enable the mass assignment restrictions.
     */
    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    /**
     * Determine if current state is "unguarded".
     */
    public static function isUnguarded(): bool
    {
        return static::$unguarded;
    }

    /**
     * Run the given callable while being unguarded.
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::$unguarded) {
            return $callback();
        }

        static::unguard();

        $result = $callback();

        static::reguard();

        return $result;
    }

    /**
     * Is model Nullable?
     *
     */
    public static function nullable(): bool
    {
        return static::$nullable;
    }

    public function isNullable(): bool
    {
        return static::nullable();
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        // If the key is in the "fillable" array, we can of course assume that it's
        // a fillable attribute. Otherwise, we will check the guarded array when
        // we need to determine if the attribute is black-listed on the model.
        if (in_array($key, $this->fillable)) {
            return true;
        }

        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->fillable);
    }

    /**
     * Determine if the given key is guarded.
     */
    public function isGuarded(string $key): bool
    {
        return in_array($key, $this->guarded) || $this->guarded == ['*'];
    }

    /**
     * Determine if the model is totally guarded.
     *
     * @return bool
     */
    public function totallyGuarded(): bool
    {
        return count($this->fillable) == 0 && $this->guarded == ['*'];
    }

    /**
     * Convert the model instance to JSON.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        $attributesArray = $this->toArray();


        return collect($attributesArray)->except($this->appends)->except($this->temporary)->toArray();

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

        return collect($attributes)->forget($this->appends)->forget($this->temporary)->toArray();
    }

    /**
     * Get model dates
     */
    public function getDates(): mixed
    {
        return $this->dates ?? [];
    }

    /**
     * Convert the model instance to an array.
     *
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
        $appendAttributes = $this->getArrayableAppends();


        // We want to spin through all the mutated attributes for this model and call
        // the mutator for the attribute. We cache off every mutated attributes so
        // we don't have to constantly check on attributes that actually change.
        foreach ($mutatedAttributes as $attribute => $value) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }

            $attributes[$attribute] = $this->mutateAttributeForArray(
                $attribute, $value
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

        foreach ($attributes as $attribute => $value) {
            $attributes[$attribute] = $this->mutateAttributeForArray($attribute, $value);
        }

        // Here we will grab all of the appended, calculated attributes to this model
        // as these attributes are not really in the attributes array, but are run
        // when we need to array or JSON the model for convenience to the coder.
        foreach ($this->getArrayableAppends() as $key) {

            $attributes[$key] = $this->mutateAttributeForArray($key, $attributes[$key] ?? null);
        }


        return $attributes;
    }

    /**
     * Get the value of an attribute using its mutator for array conversion.
     */
    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        $value = $this->mutateAttribute($key, $value);

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Get the value of an attribute using its mutator.
     */
    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        $mutateFuncName = 'get' . Str::studly($key) . 'Attribute';

        return method_exists($this, $mutateFuncName) ? $this->{$mutateFuncName}($value) : $value;
    }

    /**
     * Add the casted attributes to the attributes array.
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes): array
    {
        foreach ($this->getCasts() as $key => $value) {
            if (!array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)) {
                continue;
            }

            $castedValue = $this->castAttribute($key, $attributes[$key]);


            if (!($castedValue instanceof UnitEnum) && $castedValue instanceof Arrayable) {
                $attributes[$key] = $castedValue->toArray();
                continue;
            }
            else if ($castedValue instanceof UnitEnum) {
                $attributes[$key] = $castedValue->value;
                continue;
            }
            else {
                // Here we will cast the attribute. Then, if the cast is a date or datetime cast
                // then we will serialize the date for the array. This will convert the dates
                // to strings based on the date format specified for these Eloquent models.
                $attributes[$key] = $castedValue;
                continue;
            }


            /*if ($this->isClassCastable($key)) {

                $castedValue = $this->castAttribute($key, $attributes[$key]);


                if (!($castedValue instanceof UnitEnum) && $castedValue instanceof Arrayable) {
                    $attributes[$key] = $castedValue->toArray();
                    continue;
                }else if ($castedValue instanceof UnitEnum) {
                    $attributes[$key] = $castedValue->value;
                    continue;
                }

            }else{
                // Here we will cast the attribute. Then, if the cast is a date or datetime cast
                // then we will serialize the date for the array. This will convert the dates
                // to strings based on the date format specified for these Eloquent models.
                $attributes[$key] = $this->castAttribute(
                    $key, $attributes[$key]
                );
            }*/

            // Here we will grab all of the appended, calculated attributes to this model
            // as these attributes are not really in the attributes array, but are run
            // when we need to array or JSON the model for convenience to the coder.
            /*foreach ($this->getArrayableAppends() as $key) {
                $attributes[$key] = $this->mutateAttributeForArray($key, null);
            }*/
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable attributes.
     */
    protected function getArrayableAttributes(): array
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    /**
     * Get all of the appendable values that are arrayable.
     */
    protected function getArrayableAppends(): array
    {
        if (!count($this->appends)) {
            return [];
        }

        return $this->getArrayableItems(
            array_combine($this->appends, $this->appends)
        );
    }

    /**
     * Get an attribute array of all arrayable values.
     */
    protected function getArrayableItems(array $values): array
    {
        if (count($this->getVisible()) > 0) {
            return array_intersect_key($values, array_flip($this->getVisible()));
        }

        return array_diff_key($values, array_flip($this->getHidden()));
    }

    /**
     * Get an attribute from the model.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->getAttributeValue($key);
    }

    /**
     * Get a plain attribute (not a relationship).
     */
    protected function getAttributeValue(string $key): mixed
    {
        //dump("getAttributeValue: {$key}", $this, debug_backtrace(0, 10));
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    /**
     * Transform a raw model value using mutators, casts, etc.
     */
    protected function transformModelValue(string $key, mixed $value): mixed
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
            && in_array($key, $this->getDates(), false)) {
            return $this->asDateTime($value);
        }

        // If the attribute is castable via a class, cast it.
        if ($this->isClassCastable($key)) {
            return $this->getClassCastableAttributeValue($key);
        }

        return $value;
    }

    /**
     * Get the value of an "Attribute" return type marked attribute using its mutator.
     *
     */
    protected function mutateAttributeMarkedAttribute(string $key, mixed $value): mixed
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
     */
    public function hasAttributeGetMutator(string $key): bool
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
     */
    public function hasAttributeMutator(string $key): bool
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
     */
    protected function getAttributeFromArray(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     */
    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . Str::studly($key) . 'Attribute');
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     */
    protected function isClassCastable(string $key): bool
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

        return false;

        /*return array_key_exists($key, $this->casts) &&
                class_exists($class = $this->parseCasterClass($this->casts[$key])) &&
                ! in_array($class, static::$primitiveCastTypes);*/
    }

    /**
     * Resolve the custom caster class for a given key.
     *
     */
    protected function resolveCasterClass(string $key): mixed
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
     */
    protected function parseCasterClass(string $class): string
    {
        return !str_contains($class, ':')
            ? $class
            : explode(':', $class, 2)[0];
    }

    /**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromClassCasts(): void
    {
        $resolvedAttributes = [];
        foreach ($this->classCastCache as $key => $value) {
            $caster = $this->resolveCasterClass($key);
            $resolvedAttributes[$key] = $caster instanceof GenericCastsInboundAttributes ? [$key => $value] : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes));
            /*$this->attributes = array_merge(
                $this->attributes,
                $caster instanceof CastsInboundAttributes
                    ? [$key => $value]
                    : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
            );*/
        }

        $this->attributes = array_merge(
            $this->attributes,
            $resolvedAttributes
        );
    }

    /**
     * Normalize the response from a custom class caster.
     */
    protected function normalizeCastClassResponse(string $key, mixed $value): array
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Determine whether a value is JSON castable for inbound manipulation.
     *
     */
    protected function isJsonCastable(string $key): bool
    {
        $castables = ['array', 'json', 'object', 'collection'];

        return $this->hasCast($key) &&
            in_array($this->getCastType($key), $castables, true);
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->getCastType($key);


        if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
            return null;
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
                return is_string($value) ? $this->fromJson($value, true) : $value;
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
     */
    protected function isEnumCastable(string $key): bool
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

        return false;
    }

    /**
     * Cast the given attribute to an enum.
     */
    protected function getEnumCastableAttributeValue(string $key, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        $castType = $this->getCasts()[$key];

        if ($value instanceof $castType) {
            return $value;
        }

        return $castType::from($value);
    }

    /**
     * Set the value of an enum castable attribute.
     */
    protected function setEnumCastableAttribute(string $key, null|BackedEnum|string $value): void
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
     */
    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     */
    protected function asDate(mixed $value): Carbon
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     */
    protected function asDateTime(mixed $value): ?Carbon
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
     */
    public function hasCast(string $key, array|string|null $types = null): bool
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array)$types, true) : true;
        }

        return false;
    }

    /**
     * Get the type of cast for a model attribute.
     *
     */
    protected function getCastType(string $key): string
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
     */
    protected function isCustomDateTimeCast(string $cast): bool
    {
        return str_starts_with($cast, 'date:') ||
            str_starts_with($cast, 'datetime:');
    }

    /**
     * Determine if the cast type is an immutable custom date time cast.
     *
     */
    protected function isImmutableCustomDateTimeCast(string $cast): bool
    {
        return str_starts_with($cast, 'immutable_date:') ||
            str_starts_with($cast, 'immutable_datetime:');
    }

    /**
     * Determine if the cast type is a decimal cast.
     *
     */
    protected function isDecimalCast(string $cast): bool
    {
        return str_starts_with($cast, 'decimal:');
    }

    /**
     * Convert a DateTime to a storable string.
     *
     */
    public function fromDateTime(mixed $value): string|null
    {
        return empty($value) ? $value : $this->asDateTime($value)->format(
            $this->getDateFormat()
        );
    }

    /**
     * Determine if the given attribute is a date or date castable.
     *
     */
    protected function isDateAttribute(string $key): bool
    {
        return in_array($key, $this->getDates(), true) ||
            $this->isDateCastable($key);
    }

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     *
     */
    protected function isDateCastable(string $key): bool
    {
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }

    /**
     * Get the format for database stored dates.
     *
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Determine if the given value is a standard date format.
     *
     */
    protected function isStandardDateFormat(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Return a decimal as string.
     *
     */
    protected function asDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * Decode the given float.
     *
     */
    public function fromFloat(mixed $value): float
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
     */
    protected function castAttributeAsJson(string $key, mixed $value): string
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
     */
    public function fillJsonAttribute(string $key, mixed $value): self
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
     */
    protected function castAttributeAsEncryptedString(string $key, mixed $value): string
    {
        return (static::$encrypter ?? Crypt::getFacadeRoot())->encrypt($value, false);
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     *
     */
    protected function isEncryptedCastable(string $key): bool
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
     */
    protected function getArrayAttributeWithValue(string $path, string $key, mixed $value): static
    {
        return tap($this->getArrayAttributeByKey($key), function (&$array) use ($path, $value) {
            Arr::set($array, str_replace('->', '.', $path), $value);
        });
    }

    /**
     * Get an array attribute or return an empty array if it is not set.
     *
     */
    protected function getArrayAttributeByKey(string $key): array
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
     */
    protected function getClassCastableAttributeValue(string $key): mixed
    {
        if (isset($this->classCastCache[$key])) {
            return $this->classCastCache[$key];
        }
        else {
            $caster = $this->resolveCasterClass($key);

            if ($caster instanceof GenericCastsAttributes || $caster instanceof CastsAttributes || $caster instanceof Castable) {
                return $this->classCastCache[$key] = $caster->get($this, $key, $this->attributes[$key] ?? null, $this->attributes);
            }

            return $this->classCastCache[$key] = ($this->attributes[$key] ?? null);

            /*return $this->classCastCache[$key] = $caster instanceof GenericCastsInboundAttributes
                ? ($this->attributes[$key] ?? null)
                : $caster->get($this, $key, $this->attributes[$key] ?? null, $this->attributes);*/
        }
    }

    /**
     * Set a given attribute on the model.
     *
     */
    public function setAttribute(string $key, mixed $value): static
    {
        //if($key == 'config') dd($key, $value);
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // this model, such as "json_encoding" a listing of data for storage.
        if ($this->hasSetMutator($key)) {


            $this->setMutatedAttributeValue($key, $value);

            return $this;
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
    }

    /**
     * Set the value of a "Attribute" return type marked attribute using its mutator.
     *
     */
    protected function setAttributeMarkedMutatedAttributeValue(string $key, mixed $value): static
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

        return $this;
    }

    /**
     * Determine if an "Attribute" return type marked set mutator exists for an attribute.
     *
     */
    public function hasAttributeSetMutator(string $key): bool
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
     */
    protected function setMutatedAttributeValue(string $key, mixed $value): mixed
    {
        return $this->{'set' . Str::studly($key) . 'Attribute'}($value);
    }

    /**
     * Determine if a set mutator exists for an attribute.
     *
     */
    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . Str::studly($key) . 'Attribute');
    }

    /**
     * Set the value of a class castable attribute.
     *
     */
    protected function setClassCastableAttribute(string $key, mixed $value): void
    {
        //if($key == 'config') dd($key, $value);
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
     * Encode the given value as JSON.
     *
     */
    protected function asJson(mixed $value): string
    {
        return json_encode($value);
    }

    /**
     * Decode the given JSON back into an array or object.
     *
     */
    public function fromJson(mixed $value, bool $asObject = false): mixed
    {
        return json_decode(is_string($value) ? $value : json_encode($value), !$asObject);
    }

    /**
     * Set the array of model attributes. No checking is done.
     *
     */
    public function setRawAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        $this->classCastCache = [];

        return $this;
    }

    protected function castRawValue($value)
    {

        try {

            if (is_string($value) && Str::isJson($value)) {

                /*dump([
                         'method'           => 'castRawValue',
                         'value'       => $value,
                         'json_decode'       => json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY),
                     ]);*/

                return json_decode($value, true, 512, JSON_OBJECT_AS_ARRAY);

            }

            if ($value instanceof Collection) {
                return $value->values()->toArray();
            }

            if ($value instanceof Arrayable || (is_object($value) && method_exists($value, 'toArray'))) {
                return $value->toArray();
            }

            if (empty($value)) {
                //dd(json_decode($value, true, 512, JSON_OBJECT_AS_ARRAY));
                return [];
            }

            return (array)$value;

            /*if (is_string($value)) {
                return $value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY) ?: [] : [];

            }*/

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
     * Clone the model into a new, non-existing instance.
     */
    public function replicate(?array $except = null): static
    {
        $except = $except ?: [];

        $attributes = Arr::except($this->attributes, $except);

        return with($instance = new static)->fill($attributes);
    }

    /**
     * Get all of the current attributes on the model.
     *
     */
    public function getAttributes(): array
    {
        $this->mergeAttributesFromClassCasts();

        return $this->attributes;
    }

    /**
     * Get the mutated attributes for a given instance.
     *
     */
    public function getMutatedAttributes(): array
    {
        $class = get_class($this);

        if (!isset(static::$mutatorCache[$class])) {
            static::cacheMutatedAttributes($class);
        }

        return static::$mutatorCache[$class];
    }

    /**
     * Extract and cache all the mutated attributes of a class.
     *
     */
    public static function cacheMutatedAttributes(string $class): void
    {
        $mutatedAttributes = [];

        // Here we will extract all of the mutated attributes so that we can quickly
        // spin through them after we export models to their array form, which we
        // need to be fast. This'll let us know the attributes that can mutate.
        if (preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches)) {
            foreach ($matches[1] as $match) {
                if (static::$snakeAttributes) {
                    $match = Str::snake($match);
                }

                $mutatedAttributes[] = lcfirst($match);
            }
        }

        static::$mutatorCache[$class] = $mutatedAttributes;
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     */
    protected function addCastAttributesToJsonSerialize(array $attributes, array $mutatedAttributes): array
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
     */
    protected function mutateAttributeForJsonSerialize(string $key, mixed $value): mixed
    {
        $value = $this->mutateAttribute($key, $value);


        return $value instanceof GenericModel ? $value->jsonSerialize() : $value;
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if the given attribute exists.
     *
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->$offset);
    }

    /**
     * Get the value for a given offset.
     *
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->$offset;
    }

    /**
     * Set the value for a given offset.
     *
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->$offset = $value;
    }

    /**
     * Unset the value for a given offset.
     *
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }

    /**
     * Determine if an attribute exists on the model.
     *
     */
    public function __isset(string $key): bool
    {
        return (isset($this->attributes[$key]) || isset($this->relations[$key])) ||
            ($this->hasGetMutator($key) && !is_null($this->getAttributeValue($key)));
    }

    /**
     * Unset an attribute on the model.
     *
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $instance = new static;

        return call_user_func_array([$instance, $method], $parameters);
    }

    /**
     * Convert the model to its string representation.
     *
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @throws JsonException|Exception
     */
    public function get($model, $key, $value, $attributes)
    {
        return ($this->isNullable() && is_null($value)) ? null : new static($this->castRawValue($value));

    }

    /**
     * @throws JsonException|Exception
     */
    public function set($model, $key, $value, $attributes)
    {
        //Se o valor for nulo e a model atual for nullable
        if (is_null($value) && $this->isNullable()) {
            return [$key => null]; //null;
        }

        $currentAttributes = $this->castRawValue($attributes[$key] ?? []);

        //$mergeResult = array_replace_recursive($currentAttributes, $this->castRawValue($value));
        $mergeResult = array_replace($currentAttributes, $this->castRawValue($value));

        return [$key => json_encode(self::make($mergeResult)->jsonSerialize())];
    }

    /*public static function castUsing(array $arguments)
    {
        $currentClass = self::class;

        return new class($currentClass) implements CastsAttributes
        {

            private string $castClass;

            public function __construct(string $currentClass)
            {
                $this->castClass = $currentClass;
            }

            public function get($model, $key, $value, $attributes)
            {
                if (! isset($attributes[$key])) {
                    return;
                }

                $data = Json::decode($attributes[$key]);

                return is_array($data) ? new $this->castClass($data) : null;
            }

            public function set($model, $key, $value, $attributes)
            {

                //Se o valor for nulo e a model atual for nullable
                if (is_null($value) && $this->isNullable()) {
                    return null; //null;
                }

                $classInstance = new $this->castClass();

                $currentAttributes = $classInstance->castRawValue($attributes[$key] ?? []);

                $mergeResult = array_replace_recursive($currentAttributes, $classInstance->castRawValue($value));

                //return json_encode(self::make($mergeResult)->jsonSerialize());


                return [$key => Json::encode($classInstance::make($mergeResult)->jsonSerialize())];
                //return [$key => Json::encode($value)];
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return $value->getArrayCopy();
            }
        };
    }*/
}

class_alias(Model::class, 'ArtisanBR\GenericModel\GenericModel');