<?php

namespace HeshamFouda\AgGrid\Contracts;

interface AgGridValueGetter
{
    public function get(mixed $data): mixed;
}
