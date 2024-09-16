<?php

namespace HeshamFouda\AgGrid\Contracts;

use HeshamFouda\AgGrid\AgGridFormatterContext;

interface AgGridValueFormatter
{
    public function format(AgGridFormatterContext $context, mixed $value): string|int|float|null;

    /**
     * @var string|null
     */
    public const EXCEL_FORMAT = null;
}
