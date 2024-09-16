<?php

namespace HeshamFouda\AgGrid\Formatters;

use HeshamFouda\AgGrid\AgGridFormatterContext;
use HeshamFouda\AgGrid\Contracts\AgGridValueFormatter;

class AgGridBooleanFormatter implements AgGridValueFormatter
{
    public function format(AgGridFormatterContext $context, $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value ? __('Ja') : __('Nein');
    }
}
