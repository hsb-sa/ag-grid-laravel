<?php

namespace HeshamFouda\AgGrid\Contracts;

interface AgGridExportTimezoneProvider
{
    public function getAgGridExportTimezone(): ?\DateTimeZone;
}
