<?php

namespace ArtisanBR\GModel\Concerns;

trait HasCastables
{
    protected $casts = [];

    public function getCasts(){
        return $this->casts;
    }
}
