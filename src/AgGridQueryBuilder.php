<?php

namespace HeshamFouda\AgGrid;

use HeshamFouda\AgGrid\Contracts\AgGridCustomFilterable;
use HeshamFouda\AgGrid\Enums\AgGridDateFilterType;
use HeshamFouda\AgGrid\Enums\AgGridExportFormat;
use HeshamFouda\AgGrid\Enums\AgGridFilterType;
use HeshamFouda\AgGrid\Enums\AgGridNumberFilterType;
use HeshamFouda\AgGrid\Enums\AgGridRowModel;
use HeshamFouda\AgGrid\Enums\AgGridTextFilterType;
use HeshamFouda\AgGrid\Exceptions\InvalidSetValueOperation;
use HeshamFouda\AgGrid\Exceptions\UnauthorizedSetFilterColumn;
use HeshamFouda\AgGrid\Requests\AgGridGetRowsRequest;
use HeshamFouda\AgGrid\Requests\AgGridSetValuesRequest;
use HeshamFouda\AgGrid\Support\Column;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @mixin EloquentBuilder
 */
class AgGridQueryBuilder implements Responsable
{
    use ForwardsCalls;

    protected array $params;

    protected EloquentBuilder|Relation $subject;

    /** @var class-string<JsonResource> | null */
    protected ?string $resourceClass = null;

    protected bool $addIndexColumn = false;
    protected ?int $totalCount = null;

    /**
     * @param EloquentBuilder|Relation|Model|class-string<Model> $subject
     */
    public function __construct(array $params, EloquentBuilder|Relation|Model|string $subject)
    {
        if (is_a($subject, Model::class, true)) {
            $subject = $subject::query();
        }

        $this->params = $params;
        $this->subject = $subject;

        $model = $subject->getModel();
        if ($model instanceof AgGridCustomFilterable) {
            $model->applyAgGridCustomFilters($this->subject, $this->params['customFilters'] ?? []);
        }

        $this->addFiltersToQuery();
        $this->addToggledFilterToQuery();
        $this->addSortsToQuery();
        $this->addLimitAndOffsetToQuery();
    }

    protected function addFiltersToQuery(): void
    {
        if (!isset($this->params['filterModel'])) {
            return;
        }

        $filters = collect($this->params['filterModel']);

        // Check if we are in set values mode and exclude the filter for the given set value column
        $colId = Arr::get($this->params, 'column');
        if ($colId) {
            $filters = $filters->filter(fn($value, $key) => $key !== $colId);
        }

        foreach ($filters as $colId => $filter) {

            $column = Column::fromColId($this->subject, $colId);

            if ($column->hasRelations()) {
                $this->subject->whereHas($column->getDottedRelation(), function (EloquentBuilder $builder) use ($column, $filter) {
                    $this->addFilterToQuery($builder, $column, $filter);
                });
            } else {
                $this->addFilterToQuery($this->subject, $column, $filter);
            }
        }
    }

    protected function addFilterToQuery(EloquentBuilder|Relation $subject, Column $column, array $filter, $operator = 'and'): void
    {
        if (isset($filter['operator']) && isset($filter['conditions'])) {
            $subject->where(function (EloquentBuilder|Relation $query) use ($column, $filter) {
                foreach ($filter['conditions'] as $condition) {
                    $this->addFilterToQuery($query, $column, $condition, strtolower($filter['operator']));
                }
            });
        } else {
            $filterType = AgGridFilterType::from($filter['filterType']);
            match ($filterType) {
                AgGridFilterType::Set => $this->addSetFilterToQuery($subject, $column, $filter, $operator),
                AgGridFilterType::Text => $this->addTextFilterToQuery($subject, $column, $filter, $operator),
                AgGridFilterType::Number => $this->addNumberFilterToQuery($subject, $column, $filter, $operator),
                AgGridFilterType::Date => $this->addDateFilterToQuery($subject, $column, $filter, $operator),
            };
        }
    }

    protected function addSetFilterToQuery(EloquentBuilder|Relation $subject, Column $column, array $filter, $operator = 'and'): void
    {
        // todo handle $operator
        $isJsonColumn = $column->isJsonColumn();
        $columnName = $column->getNameAsJsonPath();
        $values = $filter['values'];
        $all = $filter['all'] ?? false;
        $filteredValues = array_filter($values, fn($value) => $value !== null);

        $subject->where(function (EloquentBuilder $query) use ($column, $all, $columnName, $values, $filteredValues, $isJsonColumn) {
            if (count($filteredValues) !== count($values)) {
                // there was a null in there
                $query->whereNull($columnName);
            }

            if ($isJsonColumn) {
                // TODO: this does not work at the moment because laravel has no support for the ?& and ?| operators
                // TODO: find a workaround!
                $query->orWhere(
                    $column->getNameAsJsonAccessor(),
                    $all ? '?&' : '?|', '{' . implode(',', $filteredValues) . '}',
                );
            } else {
                $query->orWhereIn($columnName, $filteredValues);
            }
        });
    }

    protected function addTextFilterToQuery(EloquentBuilder|Relation $subject, Column $column, array $filter, $operator = 'and'): void
    {
        $columnName = $column->getNameAsJsonPath();
        $value = $filter['filter'] ?? null;
        $type = AgGridTextFilterType::from($filter['type']);

        match ($type) {
            AgGridTextFilterType::Equals => $subject->where($columnName, '=', $value, boolean: $operator),
            AgGridTextFilterType::NotEqual => $subject->where($columnName, '!=', $value, boolean: $operator),
            AgGridTextFilterType::Contains => $subject->where($columnName, 'ilike', '%' . $value . '%', boolean: $operator),
            AgGridTextFilterType::NotContains => $subject->where($columnName, 'not ilike', '%' . $value . '%', boolean: $operator),
            AgGridTextFilterType::StartsWith => $subject->where($columnName, 'ilike', $value . '%', boolean: $operator),
            AgGridTextFilterType::EndsWith => $subject->where($columnName, 'ilike', '%' . $value, boolean: $operator),
            AgGridTextFilterType::Blank => $subject->whereNull($columnName, boolean: $operator),
            AgGridTextFilterType::NotBlank => $subject->whereNotNull($columnName, boolean: $operator),
        };
    }

    protected function addNumberFilterToQuery(EloquentBuilder|Relation $subject, Column $column, array $filter, $operator = 'and'): void
    {
        $columnName = $column->getNameAsJsonPath();
        $value = $filter['filter'] ?? null;
        $type = AgGridNumberFilterType::from($filter['type']);

        match ($type) {
            AgGridNumberFilterType::Equals => $subject->where($columnName, '=', $value, boolean: $operator),
            AgGridNumberFilterType::NotEqual => $subject->where($columnName, '!=', $value, boolean: $operator),
            AgGridNumberFilterType::GreaterThan => $subject->where($columnName, '>', $value, boolean: $operator),
            AgGridNumberFilterType::GreaterThanOrEqual => $subject->where($columnName, '>=', $value, boolean: $operator),
            AgGridNumberFilterType::LessThan => $subject->where($columnName, '<', $value, boolean: $operator),
            AgGridNumberFilterType::LessThanOrEqual => $subject->where($columnName, '<=', $value, boolean: $operator),
            AgGridNumberFilterType::InRange => $subject->where(fn($q) => $q->where($columnName, '>=', $value)->where($columnName, '<=', $filter['filterTo']), boolean: $operator),
            AgGridNumberFilterType::Blank => $subject->whereNull($columnName, boolean: $operator),
            AgGridNumberFilterType::NotBlank => $subject->whereNotNull($columnName, boolean: $operator),
        };
    }

    protected function addDateFilterToQuery(EloquentBuilder|Relation $subject, Column $column, array $filter, $operator = 'and'): void
    {
        $columnName = $column->getNameAsJsonPath();
        $dateFrom = isset($filter['dateFrom']) ? new \DateTime($filter['dateFrom']) : null;
        $dateTo = isset($filter['dateTo']) ? new \DateTime($filter['dateTo']) : null;

        match (AgGridDateFilterType::from($filter['type'])) {
            AgGridDateFilterType::Equals => $subject->whereDate($columnName, '=', $dateFrom, boolean: $operator),
            AgGridDateFilterType::NotEqual => $subject->whereDate($columnName, '!=', $dateFrom, boolean: $operator),
            AgGridDateFilterType::GreaterThan => $subject->whereDate($columnName, '>=', $dateFrom, boolean: $operator),
            AgGridDateFilterType::LessThan => $subject->whereDate($columnName, '<=', $dateFrom, boolean: $operator),
            AgGridDateFilterType::InRange => $subject->where(fn($q) => $q->whereDate($columnName, '>=', $dateFrom)->whereDate($columnName, '<=', $dateTo), boolean: $operator),
            AgGridDateFilterType::Blank => $subject->whereNull($columnName, boolean: $operator),
            AgGridDateFilterType::NotBlank => $subject->whereNotNull($columnName, boolean: $operator),
        };
    }

    protected function addToggledFilterToQuery(): void
    {
        if (!isset($this->params['rowModel'])) {
            return;
        }
        match (AgGridRowModel::from($this->params['rowModel'])) {
            AgGridRowModel::ServerSide => $this->addServerSideToggledFilterToQuery(),
            AgGridRowModel::ClientSide => $this->addClientSideToggledFilterToQuery(),
        };
    }

    protected function addServerSideToggledFilterToQuery(): void
    {
        if ($this->params['selectAll']) {
            // the toggled nodes are deselected
            $this->subject->whereNotIn($this->subject->getModel()->getKeyName(), $this->params['toggledNodes']);
        } else {
            // the toggled nodes are selected
            $this->subject->whereIn($this->subject->getModel()->getKeyName(), $this->params['toggledNodes'])->get();
        }
    }

    protected function addClientSideToggledFilterToQuery(): void
    {
        $this->subject->whereIn($this->subject->getModel()->getKeyName(), $this->params['toggledNodes']);
    }

    protected function addSortsToQuery(): void
    {
        if (!isset($this->params['sortModel'])) {
            return;
        }

        $sorts = collect($this->params['sortModel']);

        if ($sorts->isNotEmpty()) {
            // clear all existing sorts
            $this->subject->reorder();
        }

        foreach ($sorts as $sort) {
            $column = Column::fromColId($this->subject, $sort['colId']);
            $this->subject->orderBy($column->getNameAsJsonPath(), $sort['sort']);
        }

        // we need an additional sort condition so that the order is stable in all cases
        $modelKeyName = $this->subject->getModel()->getKeyName();
        if (
            $modelKeyName !== 'id' &&
            !$sorts->contains('colId', $modelKeyName) &&
            (
                empty($this->subject->getQuery()->groups) || !in_array($modelKeyName, $this->subject->getQuery()->groups)
            )
        ) {
            $this->subject->orderBy($modelKeyName, 'asc');
        }
    }

    public function getQuery(): QueryBuilder
    {
        if ($this->subject instanceof EloquentBuilder) {
            return $this->subject->getQuery();
        }

        return $this->subject->getBaseQuery();
    }

    protected function addLimitAndOffsetToQuery(): void
    {
        $startRow = $this->params['startRow'] ?? null;
        $endRow = $this->params['endRow'] ?? null;

        if ($startRow === null || $endRow === null) {
            return;
        }

        $this->subject->offset($startRow)->limit($endRow - $startRow);
    }

    /**
     * Returns a new AgGridQueryBuilder for an AgGridGetRowsRequest.
     *
     * @param EloquentBuilder|Relation|Model|class-string<Model> $subject
     */
    public static function forRequest(AgGridGetRowsRequest $request, EloquentBuilder|Relation|Model|string $subject): AgGridQueryBuilder
    {
        return new AgGridQueryBuilder($request->validated(), $subject);
    }

    /**
     * Returns a new AgGridQueryBuilder for an AgGridGetRowsRequest.
     *
     * @param EloquentBuilder|Relation|Model|class-string<Model> $subject
     */
    public static function forSetValuesRequest(AgGridSetValuesRequest $request, EloquentBuilder|Relation|Model|string $subject): AgGridQueryBuilder
    {
        return new AgGridQueryBuilder($request->validated(), $subject);
    }

    /**
     * Returns a new AgGridQueryBuilder for a selection.
     *
     * @param EloquentBuilder|Relation|Model|class-string<Model> $subject
     */
    public static function forSelection(array $selection, EloquentBuilder|Relation|Model|string $subject): AgGridQueryBuilder
    {
        return new AgGridQueryBuilder($selection, $subject);
    }

    /**
     * @param int $count
     *
     * @return AgGridQueryBuilder
     */
    public function setTotalCount(int $count): self
    {
        $this->totalCount = $count;

        return $this;
    }

    /**
     * @param bool $addIndexColumn
     *
     * @return AgGridQueryBuilder
     */
    public function addIndexColumn(): self
    {
        $this->addIndexColumn = true;

        return $this;
    }

    public function getSubject(): Relation|EloquentBuilder
    {
        return $this->subject;
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    public function resource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function toSetValues(array $allowedColumns = []): Collection
    {
        $colId = Arr::get($this->params, 'column');
        if (empty($colId)) {
            throw InvalidSetValueOperation::make();
        }

        if (collect($allowedColumns)->first() !== '*' && !in_array($colId, $allowedColumns)) {
            throw UnauthorizedSetFilterColumn::make($colId);
        }

        $column = Column::fromColId($this->subject, $colId);

        if ($column->hasRelations()) {

            $dottedRelation = $column->getDottedRelation();

            return $this->subject->with($dottedRelation)
                ->get()
                ->map(fn(Model $model) => Arr::get($this->traverse($model, $dottedRelation)->toArray(), $column->getName()))
                ->unique()
                ->sort()
                ->values();
        }

        $columnName = $column->isNestedJsonColumn() ? $column->getNameAsJsonPath() : $column->getName();

        // When getting from json, postgres uses ?column? as columns name instead the 'A->B'
        $pluckColumn = $column->isNestedJsonColumn() ? '?column?' : $column->getName();

        $values = $this->subject
            ->select($columnName)
            ->distinct()
            ->orderBy($columnName)
            ->pluck($pluckColumn);

        if ($column->isJsonColumn() && !$column->isNestedJsonColumn()) {
            // --> We need to flat the data, because we have a flat json array
            return $values->flatten(1)->unique()->sort()->values();
        }

        return $values;
    }

    protected function traverse($model, $key, $default = null): Model
    {
        if (is_array($model)) {
            return Arr::get($model, $key, $default);
        }

        if (is_null($key)) {
            return $model;
        }

        if (isset($model[$key])) {
            return $model[$key];
        }

        foreach (explode('.', $key) as $segment) {
            try {
                $model = $model->$segment;
            } catch (\Exception $e) { // @phpstan-ignore-line
                return value($default);
            }
        }

        return $model;
    }

    public function __call($name, $arguments)
    {
        $result = $this->forwardCallTo($this->subject, $name, $arguments);

        /*
         * If the forwarded method call is part of a chain we can return $this
         * instead of the actual $result to keep the chain going.
         */
        if ($result === $this->subject) {
            return $this;
        }

        return $result;
    }

    public function toResponse($request): mixed
    {
        $exportFormat = $this->params['exportFormat'] ?? null;
        if ($exportFormat !== null) {
            // this is an export
            $writerType = match (AgGridExportFormat::from($exportFormat)) {
                AgGridExportFormat::Excel => \Maatwebsite\Excel\Excel::XLSX,
                AgGridExportFormat::Csv => \Maatwebsite\Excel\Excel::CSV,
                AgGridExportFormat::Tsv => \Maatwebsite\Excel\Excel::TSV,
            };

            return Excel::download(
                new AgGridExport($this->subject, $this->params['exportColumns'] ?? null),
                'export.' . strtolower($writerType),
                $writerType
            );
        }

        if ($this->totalCount !== null) {
            $total = $this->totalCount;
        } else {
            $clone = $this->clone();
            tap($clone->getQuery(), function (QueryBuilder $query) {
                /** @phpstan-ignore-next-line */
                $query->limit = $query->offset = $query->orders = null;
                $query->cleanBindings(['order']);
            });
            $total = $clone->count();
        }

        $data = $this->get();

        // wrap in a resource
        if ($this->resourceClass !== null) {
            /** @var class-string<JsonResource> $resourceClass */
            $resourceClass = $this->resourceClass;
            if (is_a($resourceClass, ResourceCollection::class, true)) {
                // the resource is already a collection
                $data = new $resourceClass($data);
            } else {
                // wrap in an anonymous collection
                $data = $resourceClass::collection($data);
            }
        }

        if ($this->addIndexColumn) {
            $data = $data->map(function ($item, $index) use ($request) {
                if ($item instanceof JsonResource)
                    $item = $item->toArray($request);
                $item['__index'] = ($request->startRow ?? 0) + $index + 1;
                return $item;
            });
        }

        return response()->json([
            'total' => $total,
            'data' => $data,
        ]);
    }
}
