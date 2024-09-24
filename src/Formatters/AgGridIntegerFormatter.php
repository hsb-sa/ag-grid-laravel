<?php

namespace HeshamFouda\AgGrid\Formatters;

use HeshamFouda\AgGrid\AgGridFormatterContext;
use HeshamFouda\AgGrid\Contracts\AgGridValueFormatter;

class AgGridIntegerFormatter implements AgGridValueFormatter
{
    public const EXCEL_FORMAT = '#,##0';

    /**
     * @param  string|int|null  $value
     */
    public function format(AgGridFormatterContext $context, $value): ?int
    {
        return $value;
    }
}
