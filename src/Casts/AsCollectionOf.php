<?php
/*
 * Copyright (c) 2023. Artisan Digital - Todos os Direitos Reservados
 * Desenvolvido por Renalcio Carlos Jr.
 */

namespace ArtisanBR\GenericModel\Casts;

use ArtisanBR\GenericModel\Collections\GenericCollection;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\AsCollection;

class AsCollectionOf extends AsCollection
{


    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments[0] ?? GenericCollection::class) implements CastsAttributes
        {
            public function __construct(
                protected string $itemClass,
            ) {}

            public function get($model, $key, $value, $attributes)
            {

                if (!isset($attributes[$key])) {
                    return collect();
                }

                dd($value);

                $data = !is_string($attributes[$key]) ? $attributes[$key] : json_decode($attributes[$key], true);

                return GenericCollection::wrap($data ?? [])->mapInto($this->itemClass)->values();
            }

            public function set($model, $key, $value, $attributes): array
            {

                $json_value = is_string($value) ? $value : GenericCollection::wrap($value ?? [])->toJson();

                return [$key => $json_value];
            }
        };
    }
}
