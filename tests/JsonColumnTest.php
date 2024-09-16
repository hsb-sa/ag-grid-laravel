<?php

use HeshamFouda\AgGrid\AgGridQueryBuilder;
use HeshamFouda\AgGrid\Enums\AgGridNumberFilterType;
use HeshamFouda\AgGrid\Enums\AgGridTextFilterType;
use HeshamFouda\AgGrid\Tests\TestClasses\Models\Flamingo;
use HeshamFouda\AgGrid\Tests\TestClasses\Models\Keeper;

beforeEach(function () {
    $this->keeper = Keeper::factory()->createOne();
    $this->regularFlamingos = Flamingo::factory()
        ->count(3)
        ->for($this->keeper)
        ->create();
    $this->customFlamingos = Flamingo::factory()
        ->count(2)
        ->state([
            'custom_properties' => [
                'color' => 'blue',
                'age' => 3,
                'nature' => [
                    'friendly',
                    'goofy',
                ],
            ],
        ])
        ->for($this->keeper)
        ->create();
});

it('handles json columns in text filters', function () {
    $queryBuilder = new AgGridQueryBuilder(
        [
            'filterModel' => [
                'custom_properties.color' => [
                    'filterType' => 'text',
                    'type' => AgGridTextFilterType::Equals->value,
                    'filter' => 'blue',
                ],
            ],
        ],
        Flamingo::class,
    );

    expect($queryBuilder->get()->count())->toBe($this->customFlamingos->count());
});

it('handles json columns in number filters', function () {
    $queryBuilder = new AgGridQueryBuilder(
        [
            'filterModel' => [
                'custom_properties.age' => [
                    'filterType' => 'number',
                    'type' => AgGridNumberFilterType::Equals->value,
                    'filter' => 3,
                ],
            ],
        ],
        Flamingo::class,
    );

    expect($queryBuilder->get()->count())->toBe($this->customFlamingos->count());
});

/*it('handles json columns in set filters', function () {
    $queryBuilder = new AgGridQueryBuilder(
        [
            'filterModel' => [
                'custom_properties.nature' => [
                    'filterType' => 'set',
                    'values' => ['friendly'],
                ],
            ],
        ],
        Flamingo::class,
    );

    expect($queryBuilder->get()->count())->toBe($this->customFlamingos->count());
});*/
