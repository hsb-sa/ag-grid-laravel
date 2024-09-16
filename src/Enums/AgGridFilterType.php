<?php

namespace HeshamFouda\AgGrid\Enums;

enum AgGridFilterType: string
{
    case Set = 'set';
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
}
