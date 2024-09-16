<?php

namespace HeshamFouda\AgGrid\Tests\TestClasses\Models;

use HeshamFouda\AgGrid\AgGridColumnDefinition;
use HeshamFouda\AgGrid\Contracts\AgGridCustomFilterable;
use HeshamFouda\AgGrid\Contracts\AgGridExportable;
use HeshamFouda\AgGrid\Formatters\AgGridArrayFormatter;
use HeshamFouda\AgGrid\Formatters\AgGridBackedEnumFormatter;
use HeshamFouda\AgGrid\Formatters\AgGridBooleanFormatter;
use HeshamFouda\AgGrid\Formatters\AgGridDateFormatter;
use HeshamFouda\AgGrid\Formatters\AgGridDateTimeFormatter;
use HeshamFouda\AgGrid\Tests\TestClasses\Enums\FlamingoSpecies;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flamingo extends Model implements AgGridCustomFilterable, AgGridExportable
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'weight' => 'float',
        'species' => FlamingoSpecies::class,
        'preferred_food_types' => 'array',
        'last_vaccinated_on' => 'date',
        'custom_properties' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Keeper::class);
    }

    public function applyAgGridCustomFilters(EloquentBuilder $query, array $filters): void
    {
        $query->when($filters['withTrashed'] ?? false, function ($query) {
            return $query->withTrashed();
        });
    }

    public static function getAgGridColumnDefinitions(): array
    {
        return [
            new AgGridColumnDefinition(
                'id',
                __('ID'),
            ),
            new AgGridColumnDefinition(
                'name',
                __('Name'),
            ),
            new AgGridColumnDefinition(
                'species',
                __('Species'),
                new AgGridBackedEnumFormatter(),
            ),
            new AgGridColumnDefinition(
                'weight',
                __('Weight'),
            ),
            new AgGridColumnDefinition(
                'is_hungry',
                __('Is Hungry'),
                new AgGridBooleanFormatter()
            ),
            new AgGridColumnDefinition(
                'last_vaccinated_on',
                __('Last Vaccinated'),
                new AgGridDateFormatter(),
            ),
            new AgGridColumnDefinition(
                'preferred_food_types',
                __('Preferred Food Types'),
                new AgGridArrayFormatter(),
            ),
            new AgGridColumnDefinition(
                'keeper_id',
                __('Keeper'),
                null,
                fn ($data) => $data->keeper->name,
            ),
            new AgGridColumnDefinition(
                'created_at',
                __('Created At'),
                new AgGridDateTimeFormatter(),
            ),
            new AgGridColumnDefinition(
                'updated_at',
                __('Updated At'),
                new AgGridDateTimeFormatter(),
            ),
        ];
    }

    public static function provideAgGridSetValues(string $column): ?array
    {
        return match ($column) {
            'species' => FlamingoSpecies::setValues(),
            default => null
        };
    }
}
