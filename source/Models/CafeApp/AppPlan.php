<?php

namespace Source\Models\CafeApp;

use Source\Core\Model;

/**
 * Class Appplan
 * @package Source\Models\CafeApp
 */
class AppPlan extends Model
{
    /**
     * AppPlan constructor
     */
    public function __construct()
    {
        parent::__construct("app_plans", ["id"], ["name", "period", "period_str", "price", "status"]);
    }
}