<?php

namespace HeshamFouda\AgGrid\Formatters;

use HeshamFouda\AgGrid\AgGridFormatterContext;
use HeshamFouda\AgGrid\Contracts\AgGridValueFormatter;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class AgGridFloatFormatter implements AgGridValueFormatter
{
    public const EXCEL_FORMAT = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1;

    /**
     * @param  string|float|null  $value
     */
    public function format(AgGridFormatterContext $context, $value): ?string
    {
        return $value;
    }
}
