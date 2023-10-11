<?php

namespace Source\App;

use Source\Core\Controller;

use Source\Core\Session;
use Source\Core\View;
use Source\Models\Auth;
use Source\Models\CafeApp\AppCategory;
use Source\Models\CafeApp\AppInvoice;
use Source\Models\CafeApp\AppOrder;
use Source\Models\CafeApp\AppPlan;
use Source\Models\CafeApp\AppSubscription;
use Source\Models\CafeApp\AppWallet;
use Source\Models\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\User;
use Source\Support\Email;
use Source\Support\Message;
use Source\Support\Thumb;
use Source\Support\Upload;

/**
 * Class App
 * @package Source\App
 */
class App extends Controller
{
    /** @var User */
    private $user;

    /**
     * App constructor.
     */
    public function __construct()
    {
        parent::__construct(__DIR__ . "/../../themes/" . CONF_VIEW_APP . "/");

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar o APP.")->flash();
            redirect("/entrar");
        }

        (new Access())->report();
        (new Online())->report();

        (new AppWallet())->start($this->user);
        (new AppInvoice())->fixed($this->user, 3);

        //Confirmação de e-mail
        if ($this->user->status != "confirmed") {
            $session = new Session();
            if (!$session->has("appconfirmed")) {
                $this->message->info(
                    "IMPORTANTE: Acesse seu e-mail para confirmar seu cadastro e ativar todos os recursos."
                )->flash();
                $session->set("appconfirmed", true);
                (new Auth())->register($this->user);
            }
        }
    }

    /**
     * @param array|null $data
     * @return void
     */
    public function dash(?array $data): void
    {
        if (!empty($data["wallet"])) {
            $session = new Session();

            if ($data["wallet"] == "all") {
                $session->unset("walletfilter");
                echo json_encode(["filter" => true]);
                return;
            }

            $wallet = filter_var($data["wallet"], FILTER_VALIDATE_INT);
            $getWallet = (new AppWallet())->find(
                "user_id = :user AND id = :id",
                "user={$this->user->id}&id={$wallet}"
            )->count();

            if ($getWallet) {
                $session->set("walletfilter", $wallet);
            }

            echo json_encode(["filter" => true]);
            return;
        }

        //CHART UPDATE
        $chartData = (new AppInvoice())->chartData($this->user);
        $categories = str_replace("'", "", explode(",", $chartData->categories));
        $json["chart"] = [
            "categories" => $categories,
            "income" => array_map("abs", explode(",", $chartData->income)),
            "expense" => array_map("abs", explode(",", $chartData->expense))
        ];

        //WALLET
        $wallet = (new AppInvoice())->balance($this->user);
        $wallet->wallet = str_price($wallet->wallet);
        $wallet->status = ($wallet->balance == "positive" ? "gradient-green" : "gradient-red");
        $wallet->income = str_price($wallet->income);
        $wallet->expense = str_price($wallet->expense);
        $json["wallet"] = $wallet;

        echo json_encode($json);
    }

    /**
     * @param array|null $data
     * @return void
     */

    /**
     * APP HOME
     */
    public function home(): void
    {
        $head = $this->seo->render(
            "Olá {$this->user->first_name}. Vamos controlar? - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        //CHART
        $chartData = (new AppInvoice())->chartData($this->user);
        //END CHART

        //INCOME && EXPENSE
        $whereWallet = "";
        if ((new Session())->has("walletfilter")) {
            $whereWallet = "AND wallet_id = " . (new Session())->walletfilter;
        }

        //Faz a busca dos registros a receber
        $income = (new AppInvoice())
            ->find(
                "user_id = :user AND type = 'income' AND status = 'unpaid' AND date(due_at) <= date(now() + INTERVAL 1 MONTH) {$whereWallet}",
                "user={$this->user->id}"
            )
            ->order("due_at")
            ->fetch(true);

        //Faz a busca dos registros a pagar
        $expense = (new AppInvoice())
            ->find(
                "user_id = :user AND type = 'expense' AND status = 'unpaid' AND date(due_at) <= date(now() + INTERVAL 1 MONTH) {$whereWallet}",
                "user={$this->user->id}"
            )
            ->order("due_at")
            ->fetch(true);

        //END INCOME && EXPENSE

        //WALLET
        $wallet = (new AppInvoice())->balance($this->user);
        //END WALLET

        //POSTS

        //Pega os POSTS no BLOG e joga na tela
        $posts = (new Post())->find()->limit(3)->order("post_at DESC")->fetch(true);
        //END POSTS

        echo $this->view->render("home", [
            "head" => $head,
            "chart" => $chartData,
            "income" => $income,
            "expense" => $expense,
            "wallet" => $wallet,
            "posts" => $posts
        ]);
    }


    /**
     * @param array $data
     * @return void
     * @throws \Exception
     */
    public function filter(array $data): void
    {
        $status = (!empty($data["status"]) ? $data["status"] : "all");
        $category = (!empty($data["category"]) ? $data["category"] : "all");
        $date = (!empty($data["date"]) ? $data["date"] : date("m/Y"));

        //Separa os dois parametros
        list($m, $y) = explode("/", $date);

        //valida as datas
        $m = ($m >= 1 && $m <= 12 ? $m : date("m"));

        //Limita o filtros a 10 anos
        $y = ($y <= date("Y", strtotime("+10year")) ? $y : date("Y", strtotime("+10year")));

        $start = new \DateTime(date("Y-m-t"));
        $end = new \DateTime(date("Y-m-t", strtotime("{$y}-{$m}+1month")));
        $diff = $start->diff($end);

        if (!$diff->invert) {
            $afterMonths = (floor($diff->days / 30));
            (new AppInvoice())->fixed($this->user, $afterMonths);
        }

        $redirect = ($data["filter"] == "income" ? "receber" : "pagar");
        $json["redirect"] = url("/app/{$redirect}/{$status}/{$category}/{$m}-{$y}");
        echo json_encode($json);
    }

    /**
     * @param array|null $data
     * @return void
     */
    public function income(?array $data): void
    {
        $head = $this->seo->render(
            "Minhas receitas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $categories = (new AppCategory())
            ->find("type = :t", "t=income", "id, name")
            ->order("order_by, name")
            ->fetch("true");

        echo $this->view->render("invoices", [
            "user" => $this->user,
            "head" => $head,
            "type" => "income",
            "categories" => $categories,
            "invoices" => (new AppInvoice())->filter($this->user, "income", ($data ?? null)),
            "filter" => (object)[
                "status" => ($data["status"] ?? null),
                "category" => ($data["category"] ?? null),
                "date" => (!empty($data["date"]) ? str_replace("-", "/", $data["date"]) : null)
            ]
        ]);
    }

    /**
     * @param array|null $data
     * @return void
     */
    public function expense(?array $data): void
    {
        $head = $this->seo->render(
            "Minhas despesas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $categories = (new AppCategory())
            ->find("type = :t", "t=expense", "id, name")
            ->order("order_by, name")
            ->fetch("true");

        echo $this->view->render("invoices", [
            "user" => $this->user,
            "head" => $head,
            "type" => "expense",
            "categories" => $categories,
            "invoices" => (new AppInvoice())->filter($this->user, "expense", ($data ?? null)),
            "filter" => (object)[
                "status" => ($data["status"] ?? null),
                "category" => ($data["category"] ?? null),
                "date" => (!empty($data["date"]) ? str_replace("-", "/", $data["date"]) : null)
            ]
        ]);
    }

    /**
     * @return void
     */
    public function fixed(): void
    {
        $head = $this->seo->render(
            "Minhas contas fixas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $whereWallet = "";
        if ((new Session())->has("walletfilter")) {
            $whereWallet = "AND wallet_id = " . (new Session())->walletfilter;
        }

        echo $this->view->render("recurrences", [
            "head" => $head,
            "invoices" => (new AppInvoice())->find(
                "user_id = :user AND type IN('fixed_income', 'fixed_expense') {$whereWallet}",
                "user={$this->user->id}"
            )->fetch(true)
        ]);
    }

    /**
     * @param array|null $data
     * @return void
     */
    public function wallets(?array $data): void
    {
        //create carteira
        if (!empty($data["wallet"]) && !empty($data["wallet_name"])) {
            $wallet = new AppWallet();
            $wallet->user_id = $this->user->id;
            $wallet->wallet = filter_var($data["wallet_name"], FILTER_SANITIZE_SPECIAL_CHARS);
            $wallet->save();

            echo json_encode(["reload" => true]);
            return;
        }

        //update carteira
        if (!empty($data["wallet"]) && !empty($data["wallet_edit"])) {
            $wallet = (new AppWallet())->find(
                "user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["wallet"]}"
            )->fetch();

            if ($wallet) {
                $wallet->wallet = filter_var($data["wallet_edit"], FILTER_SANITIZE_SPECIAL_CHARS);
                $wallet->save();
            }

            echo json_encode(["wallet_edit" => true]);
            return;
        }

        //delete carteira
        if (!empty($data["wallet"]) && !empty($data["wallet_remove"])) {
            $wallet = (new AppWallet())->find(
                "user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["wallet"]}"
            )->fetch();

            if ($wallet) {
                $wallet->destroy();
                (new Session())->unset("walletfilter");
            }

            echo json_encode(["wallet_remove" => true]);
            return;
        }


        $head = $this->seo->render(
            "Minhas carteiras - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $wallets = (new AppWallet())
            ->find("user_id = :user", "user={$this->user->id}")
            ->order("wallet")
            ->fetch(true);

        echo $this->view->render("wallets", [
            "head" => $head,
            "wallets" => $wallets
        ]);
    }

    /**
     * @param array $data
     * @return void
     */
    public function launch(array $data): void
    {
        //Empede que usuário faças varías requisições de uma vez no servidor
        if (request_limit("applaunch", 20, 60 * 5)) {
            $json["message"] = $this->message->warning(
                "Foi muito rápido {$this->user->first_name}! Por favor aguarde 5 minutos para novos lançamentos."
            )->render();
            echo json_encode($json);
            return;
        }

        //Verifica a quantidade de parcelas para que o usuário nao extipule os máximo
        if (!empty($data["enrollments"]) && ($data["enrollments"] < 2 || $data["enrollments"] > 420)) {
            $json["message"] = $this->message->warning(
                "Ooops {$this->user->first_name}! Para lançar o número de parcelas deve ser entre 2 e 420."
            )->render();
            echo json_encode($json);
            return;
        }

        //Evita que HTML seja aceitos
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
        $status = (date($data["due_at"]) <= date("Y-m-d") ? "paid" : "unpaid");

        $invoice = (new AppInvoice());
        $invoice->user_id = $this->user->id;
        $invoice->wallet_id = $data["wallet"];
        $invoice->category_id = $data["category"];
        $invoice->invoice_of = null;
        $invoice->description = $data["description"];
        $invoice->type = ($data["repeat_when"] == "fixed" ? "fixed_{$data["type"]}" : $data["type"]);
        $invoice->value = str_replace([".", ","], ["", "."], $data["value"]);
        $invoice->currency = $data["currency"];
        $invoice->due_at = $data["due_at"];
        $invoice->repeat_when = $data["repeat_when"];
        $invoice->period = (!empty($data["period"]) ? $data["period"] : "month");
        $invoice->enrollments = (!empty($data["enrollments"]) ? $data["enrollments"] : 1);
        $invoice->enrollment_of = 1;
        $invoice->status = ($data["repeat_when"] == "fixed" ? "paid" : $status);

        if (!$invoice->save()) {
            $json["message"] = $invoice->message()->before("Ooops! ")->render();
            echo json_encode($json);
            return;
        }

        if ($invoice->repeat_when == "enrollment") {
            $invoiceOf = $invoice->id;
            for ($enrollment = 1; $enrollment < $invoice->enrollments; $enrollment++) {
                $invoice->id = null;
                $invoice->invoice_of = $invoiceOf;
                $invoice->due_at = date("Y-m-d", strtotime($data["due_at"] . "+{$enrollment}month"));
                $invoice->status = (date($invoice->due_at) <= date("Y-m-d") ? "paid" : "unpaid");
                $invoice->enrollment_of = $enrollment + 1;
                $invoice->save();
            }
        }

        if ($invoice->type == "income") {
            $this->message->success("Receita lançada com sucesso. Use o filtro para controlar.")->render();
        } else {
            $this->message->success("Despesa lançada com sucesso. Use o filtro para controlar.")->render();
        }

        $json["reload"] = true;
        echo json_encode($json);
    }

    /**
     * @param array $data
     * @throws \Exception
     */
    public function support(array $data): void
    {
        //Caso o usuario não envie uma mensagem já e bloqueado já no começo
        if (empty($data["message"])) {
            $json["message"] = $this->message->warning("Para enviar escreva sua mensagem.")->render();
            echo json_encode($json);
            return;
        }

        //Limita o número de requisições que o usuário pode enviar
        if (request_limit("appsupport", 3, 60 * 5)) {
            $json["message"] = $this->message->warning(
                "Por favor, aguarde 5 minutos para enviar novos contatos, sugestões ou reclamções"
            )->render();
            echo json_encode($json);
            return;
        }

        //Bloquea o numero de menssagens para diferentes canais
        if (request_repeat("message", $data["message"])) {
            $json["message"] = $this->message->info(
                "Já recebemos sua solicitação {$this->user->first_name}. Agradecemos pelo contato e responderemos em breve."
            )->render();
            echo json_encode($json);
            return;
        }

        //Faz o disparo do e-mail
        $subject = date_fmt() . " - {$data["subject"]}";
        $message = filter_var($data["message"], FILTER_SANITIZE_STRIPPED);

        $view = new View(__DIR__ . "/../../shared/views/email");
        $body = $view->render("mail", [
            "subject" => $subject,
            "message" => str_textarea($message)
        ]);

        (new Email())->bootstrap(
            $subject,
            $body,
            CONF_MAIL_SUPPORT,
            "Suporte " . CONF_SITE_NAME
        )->queue($this->user->email, "{$this->user->first_name} {$this->user->last_name}");

        $this->message->success(
            "Recebemos sua solicitação {$this->user->first_name}. Agradecemos pelo contato e responderemos em breve."
        )->flash();
        $json["reload"] = true;
        echo json_encode($json);
    }

    /**
     * @param array $data
     * @return void
     */
    public function onpaid(array $data): void
    {
        $invoice = (new AppInvoice())
            ->find("user_id = :user AND id = :id", "user={$this->user->id}&id={$data["invoice"]}")
            ->fetch();

        if (!$invoice) {
            $this->message->error("Ooops! Ocorreu um erro ao atualizar o lançamento :/")->flash();
            $json["reload"] = true;
            echo json_encode($json);
            return;
        }

        $invoice->status = ($invoice->status == "paid" ? "unpaid" : "paid");
        $invoice->save();

        $y = date("Y");
        $m = date("m");
        if (!empty($data["date"])) {
            list($m, $y) = explode("/", $data["date"]);
        }

        $json["onpaid"] = (new AppInvoice())->balanceMonth($this->user, $y, $m, $invoice->type);
        echo json_encode($json);
    }


    /**
     * @param array $data
     * @return void
     */
    public function invoice(array $data)
    {
        //atualizar fatura
        if (!empty($data["update"])) {
            $invoice = (new AppInvoice())->find(
                "user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["invoice"]}"
            )->fetch();

            if (!$invoice) {
                $json["message"] = $this->message->error(
                    "Ooops! Não foi possível carregar a fatura {$this->user->first_name}. Você pode tentar novamente."
                )->render();
                echo json_encode($json);
                return;
            }

            //verifica a data que esta sendo atualizada
            if ($data["due_day"] < 1 || $data["due_day"] > $dayofMonth = date("t", strtotime($invoice->due_at))) {
                $json["message"] = $this->message->warning(
                    "O vencimento deve ser entre dia 1 e dia {$dayofMonth} para este mês."
                )->render();
                echo json_encode($json);
                return;
            }

            //amarzena os dados no banco de dados
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $due_day = date("Y-m", strtotime($invoice->due_at)) . "-" . $data["due_day"];
            $invoice->category_id = $data["category"];
            $invoice->description = $data["description"];
            $invoice->due_at = date("Y-m-d", strtotime($due_day));
            $invoice->value = str_replace([".", ","], ["", "."], $data["value"]);
            $invoice->wallet_id = $data["wallet"];
            $invoice->status = $data["status"];

            if (!$invoice->save()) {
                $json["message"] = $invoice->message()->before("Ooops! ")->after(" {$this->user->first_name}.")->render(
                );
                echo json_encode($json);
                return;
            }

            //verifica se a fatura e fixa e faz o ajuste correto
            $invoiceOf = (new AppInvoice())->find(
                "user_id = :user AND invoice_of = :of",
                "user={$this->user->id}&of={$invoice->id}"
            )->fetch(true);

            if (!empty($invoiceOf) && in_array($invoice->type, ["fixed_income", "fixed_expense"])) {
                foreach ($invoiceOf as $invoiceItem) {
                    if ($data["status"] == "unpaid" && $invoiceItem->status == "unpaid") {
                        $invoiceItem->destroy();
                    } else {
                        //atualiza os dados das fatura que ainda não foram pagas
                        $due_day = date("Y-m", strtotime($invoiceItem->due_at)) . "-" . $data["due_day"];
                        $invoiceItem->category_id = $data["category"];
                        $invoiceItem->descrption = $data["description"];
                        $invoiceItem->wallet_id = $data["wallet"];

                        if ($invoiceItem->status == "unpaid") {
                            $invoiceItem->value = str_replace([".", ","], ["", "."], $data["value"]);
                            $invoiceItem->due_at = date("Y-m-d", strtotime($due_day));
                        }

                        $invoiceItem->save();
                    }
                }
            }

            $json["message"] = $this->message->success(
                "Pronto {$this->user->first_name}, a atualização foi efetuada com sucesso."
            )->render();
            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Aluguel - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        //faz a leitura e manda o objeto para a view
        $invoice = (new AppInvoice())->find(
            "user_id = :user AND id = :invoice",
            "user={$this->user->id}&invoice={$data["invoice"]}"
        )->fetch();

        if (!$invoice) {
            $this->message->error("Ooops Você tentou acessar uma fatura que não existe")->flash();
            redirect("/app");
        }

        echo $this->view->render("invoice", [
            "head" => $head,
            "invoice" => $invoice,
            "wallets" => (new AppWallet())
                ->find("user_id = :user", "user={$this->user->id}", "id, wallet")
                ->order("wallet")
                ->fetch(true),
            "categories" => (new AppCategory())
                ->find("type = :type", "type={$invoice->category()->type}")
                ->fetch(true)
        ]);
    }

    /**
     * @param array $data
     * @return void
     */
    public function remove(array $data)
    {
        $invoice = (new AppInvoice())->find(
            "user_id = :user AND id = :invoice",
            "user={$this->user->id}&invoice={$data["invoice"]}"
        )->fetch();

        if ($invoice) {
            $invoice->destroy();
        }

        $this->message->success("Tudo pronto {$this->user->first_name}. O lançamento foi removido com sucesso!")->flash(
        );
        $json["redirect"] = url("/app");
        echo json_encode($json);
    }


    /**
     * @param array|null $data
     * @return void
     */
    public function profile(?array $data): void
    {
        if (!empty($data["update"])) {
            list($d, $m, $y) = explode("/", $data["datebirth"]);
            $user = (new User())->findById($this->user->id);
            $user->first_name = $data["first_name"];
            $user->last_name = $data["last_name"];
            $user->genre = $data["genre"];
            $user->datebirth = "{$y}-{$m}-{$d}";
            $user->document = preg_replace("/[^0-9]/", "", $data["document"]);

            //verifica se a imagem está sendo alterada
            if (!empty($_FILES["photo"])) {
                $file = $_FILES["photo"];
                $upload = new Upload();

                //remove a imagem antiga
                if ($this->user->photo()) {
                    (new Thumb())->flush("storage/{$this->user->photo}");
                    $upload->remove("storage/{$this->user->photo}");
                }

                if (!$user->photo = $upload->image($file, "{$user->first_name} {$user->last_name} " . time(), 360)) {
                    $json["message"] = $upload->message()->before("Ooops {$this->user->first_name}! ")->after(
                        "."
                    )->render();
                    echo json_encode($json);
                    return;
                }
            }

            //validar senha se são iguais
            if (!empty($data["password"])) {
                if (empty($data["password_re"]) || $data["password"] != $data["password_re"]) {
                    $json["message"] = $this->message->warning(
                        "Para de alterar sua senha, informe e repita a nova senha!"
                    )->render();
                    echo json_encode($json);
                    return;
                }
            }

            if (!$user->save()) {
                $json["message"] = $user->message()->render();
                echo json_encode($json);
                return;
            }

            $json["message"] = $this->message->success(
                "Pronto {$this->user->first_name}. Seus dados foram atualizados com sucesso!"
            )->render();
            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Meu perfil - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profile", [
            "head" => $head,
            "user" => $this->user,
            "photo" => ($this->user->photo() ? image($this->user->photo, 360, 360) :
                theme("assets/images/avatar.jpg", CONF_VIEW_APP))
        ]);
    }

    public function signature(?array $data): void
    {
        $head = $this->seo->render(
            "Assinatura - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("signature", [
            "head" => $head,
            "subscription" => (new AppSubscription())
                ->find("user_id = :user AND status != :status", "user={$this->user->id}&status=canceled")
                ->fetch(),
            "orders" => (new AppOrder())
                ->find()
                ->order("created_at DESC")
                ->fetch(true),
            "plans" => (new AppPlan())
            ->find("status = :status", "status=active")
            ->order("name, price")
            ->fetch(true)
        ]);
    }

    /**
     * APP LOGOUT
     */
    public function logout()
    {
        (new Message())->info("Você saiu com sucesso " . Auth::user()->first_name . ". Volte logo :)")->flash();

        Auth::logout();
        redirect("/entrar");
    }
}