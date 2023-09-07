<?php

namespace Source\Models\CafeApp;

use Source\Core\Model;

class AppCategory extends Model
{
    public function __construct()
    {
        parent::__construct("app_category", ["id"], ["name", "type"]);
    }
}