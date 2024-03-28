<?php

namespace ArtisanBR\GenericModel\Concerns;

trait HasCastables
{
    protected $casts = [];

    public function getCasts(){
        return $this->casts;
    }
}
