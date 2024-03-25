Laravel Generic Models
=====

Este pacote provê a criação de Models Genéricas baseadas na estrutura do Eloquent do Laravel com melhorias e adaptações do pacote **jenssegers/model**.

# Instalação

```

composer require artisanbr/laravel-generic-model

```

# Versões

## Tabela de Versões

| Laravel Version | Package Version |
|-----------------|-----------------|
| 9 & 10          | v1.0.0          |
| 11              | v2.0.0          |


Features e Adaptações
--------

 - Accessors e mutators
 - Conversão de Model para Array e JSON
 - Hidden attributes nas conversões para Array/JSON
 - Guarded e fillable attributes
 - Appends accessors e mutators nas conversões para Array/JSON
 - Casting de Attributes
 
Você pode saber mais sobre os recursos originais do Eloquent Model em
https://laravel.com/docs/eloquent


Exemplo
-------

```php

use Model\GenericModel;

class User extends GenericModel {

    protected $hidden = ['password'];

    protected $guarded = ['password'];

    protected $casts = ['age' => 'integer'];

    public function save()
    {
        return API::post('/items', $this->attributes);
    }

    public function setBirthdayAttribute($value)
    {
        $this->attributes['birthday'] = strtotime($value);
    }

    public function getBirthdayAttribute($value)
    {
        return new DateTime("@$value");
    }

    public function getAgeAttribute($value)
    {
        return $this->birthday->diff(new DateTime('now'))->y;
    }
}

$item = new User(array('name' => 'john'));
$item->password = 'bar';

echo $item; // {"name":"john"}
```


Baseado e Adaptado de https://github.com/jenssegers/model