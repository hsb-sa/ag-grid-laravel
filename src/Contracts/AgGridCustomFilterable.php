<?php

namespace HeshamFouda\AgGrid\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

interface AgGridCustomFilterable
{
    public function applyAgGridCustomFilters(EloquentBuilder $query, array $filters): void;
}
