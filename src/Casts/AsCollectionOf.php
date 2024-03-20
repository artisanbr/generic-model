<?php
/*
 * Copyright (c) 2023. Tanda Interativa - Todos os Direitos Reservados
 * Desenvolvido por Renalcio Carlos Jr.
 */

namespace Adminx\Common\Models\Casts;

use Adminx\Common\Models\Collections\GenericCollection;
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

                /*if($key == 'variables'){
                    dd($model, $key, $value, $attributes);
                }*/

                if (!isset($attributes[$key])) {
                    return collect();
                }

                $data = !is_string($attributes[$key]) ? $attributes[$key] : json_decode($attributes[$key], true);

                return GenericCollection::wrap($data ?? [])->mapInto($this->itemClass)->values();
            }

            public function set($model, $key, $value, $attributes): array
            {
                /*$json_value = is_string($value) ? $value : GenericCollection::wrap($value ?? [])->map(fn($item) => ($item instanceof GenericModel) ? $item : new
                $this->itemClass($item))->values()->toJson();*/

                $json_value = is_string($value) ? $value : GenericCollection::wrap($value ?? [])->toJson();
                //$json_value = is_string($value) ? $value : json_encode($value ?? []);

                if($key == 'items'){
                    /*dump([
                             'type'              => 'set Collection',
                             'key'               => $key,
                             'value'             => $value,
                             //'model'             => $model,
                             //'attributes'             => $attributes,
                             //'merge'             => $mergeResult,
                             'result'            => [$key => $json_value],

                             //'attributes_current' => $attributes[$key] ?? null,
                             //'trace'      => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25),
                         ]);*/
                }


                return [$key => $json_value];
            }
        };
    }
}
