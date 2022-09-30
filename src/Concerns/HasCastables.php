<?php

namespace ArtisanLabs\GModel\Concerns;

trait HasCastables
{
    protected $casts = [];

    public function getCasts(){
        return $this->casts;
    }
}
