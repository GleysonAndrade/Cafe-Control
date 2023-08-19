<?php

namespace Source\Core;

use Source\Support\Message;
use Source\Support\Seo;

/**
 * FSPHP | Class Controller
 *
 * @author Gleyson A. <gleysondev@yahoo.com>
 * @package Source\Core
 */
class Controller
{
    /**
     * @var View
     */
    protected $view;
    /**
     * @var Seo
     */
    protected $seo;
    /**
     * @var Message
     */
    protected $message;

    /**
     * @param $pathToViews
     */
    public function __construct($pathToViews = null)
    {
        $this->view = new View($pathToViews);
        $this->seo = new Seo();
        $this->message = new Message();
    }
}