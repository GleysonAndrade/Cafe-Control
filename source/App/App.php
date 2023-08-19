<?php

namespace Source\App;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Support\Message;

class  App extends Controller
{
    public function __construct()
    {
        parent::__construct(__DIR__ . "/../../themes/" . CONF_VIEW_APP);

        //RESTRIÇÂO DE ACESSO
        if (!Auth::user()) {
            $this->message->warning("Efetue login  para acessar o APP.")->flash();
            redirect("/entrar");
        }
    }

    public function home()
    {
        echo flash();
        var_dump(Auth::user());
        echo "<a title='Sair' href='". url("/app/sair") . "'>Sair</a>";
    }

    public function logout()
    {
        (new Message())->info("Você saiu com sucesso " . Auth::user()->first_name . " Volte logo :)")->flash();

        Auth::logout();
        redirect("/entrar");
    }
}