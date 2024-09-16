<?php

namespace HeshamFouda\AgGrid;

use DateTimeZone;

class AgGridFormatterContext
{
    public function __construct(public string $locale, public ?DateTimeZone $timezone = null)
    {
    }
}
