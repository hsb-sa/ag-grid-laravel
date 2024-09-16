<?php

namespace HeshamFouda\AgGrid\Contracts;

use HeshamFouda\AgGrid\AgGridColumnDefinition;

interface AgGridExportable
{
    /**
     * @return AgGridColumnDefinition[]
     */
    public static function getAgGridColumnDefinitions(): array;
}
