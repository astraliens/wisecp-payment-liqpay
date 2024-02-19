<?php

require("vendor/autoload.php");

class LiqPayModule extends PaymentGatewayModule{
    private $liqpay_object;

    function __construct(){
        $this->name=__CLASS__;
        parent::__construct();
    }

    public function config_fields(){
        global $lang;
        return [
            'public_key'                  =>[
                'name'       =>$this->lang['config-field-public-key'],
                'description'=>$this->lang['config-field-public-key-description'],
                'type'       =>"text",
                'value'      =>$this->config["settings"]["public_key"] ?? '',
                'placeholder'=>$this->lang['config-field-public-key'],
            ],
            'private_key'                 =>[
                'name'       =>$this->lang['config-field-private-key'],
                'description'=>$this->lang['config-field-private-key-description'],
                'type'       =>"password",
                'value'      =>$this->config["settings"]["private_key"] ?? '',
                'placeholder'=>$this->lang['config-field-private-key'],
            ],
            'payment_description_template'=>[
                'name'       =>$this->lang['config-field-payment-description-template'],
                'description'=>$this->lang['config-field-payment-description-template-description'],
                'type'       =>"text",
                'value'      =>$this->config["settings"]["payment_description_template"] ?? $this->lang["payment-description-template"],
                'placeholder'=>$this->lang["payment-description-template"],
            ],
        ];
    }

    private function GetPrivateKey(){
        return $this->config["settings"]["private_key"] ?? "";
    }

    private function GetPublicKey(){
        return $this->config["settings"]["public_key"] ?? "";
    }

    private function GetLiqPayObject(){
        if ($this->liqpay_object == null){
            $this->liqpay_object=new LiqPay($this->GetPublicKey(), $this->GetPrivateKey());
        }
        return $this->liqpay_object;
    }

    private function GetPaymentDescription(){
        $template=$this->config["settings"]["payment_description_template"] ?? "Payment for order.";
        $data=[
            'checkout_id'   =>$this->checkout_id,
            'invoice_id'    =>$this->checkout['data']['invoice_id'],
            'user_phone'    =>$this->clientInfo->phone,
            'user_name'     =>$this->clientInfo->name,
            'user_surname'  =>$this->clientInfo->surname,
            'user_full_name'=>$this->clientInfo->full_name,
            'user_email'    =>$this->clientInfo->email
        ];

        $replace_array=array();
        if ($template != '' and count($data) > 0){
            foreach ($data as $data_key=>$data_val){
                $replace_key='{' . $data_key . '}';
                $replace_array[$replace_key]=$data_val;
            }
        }
        return strtr($template, $replace_array);
    }

    public function area($params=[]){
        $form_html=$this->GetLiqPayObject()->cnb_form([
            'action'           =>'pay',
            'amount'           =>round($params["amount"], 2),
            'language'         =>$this->clientInfo->lang,
            'result_url'       =>$this->links['return'],
            //'server_url' =>$this->links['callback'],
            'currency'         =>$this->currency($params["currency"]),
            'description'      =>$this->GetPaymentDescription(),
            'order_id'         =>$this->checkout_id,
            'version'          =>'3',
            'sender_last_name' =>$this->clientInfo->surname,
            'sender_first_name'=>$this->clientInfo->name,
        ]);

        $form_html= "<div style='text-align:center;'>
                        <div style='margin: 0 auto; width:25%'>{$form_html}</div>
                     </div>";
        return $form_html;
    }

    public function callback(){
        $callback_data=(string) Filter::init("POST/data", "string");
        $callback_signature=(string) Filter::init("POST/signature", "string");
        $sign=base64_encode(sha1($this->GetPrivateKey() . $callback_data . $this->GetPrivateKey(), 1));
        $data=json_decode(base64_decode($callback_data), true);


        if ($sign !== $callback_signature){
            $this->error='Invalid signature';
            return false;
        }

        if ($data['action'] !== 'pay'){
            $this->error='Invalid action';
            return false;
        }

        $order_id=(int) $data['order_id'];

        if (!$order_id){
            $this->error='Order not found.';
            return false;
        }

        // Let's get the checkout information.
        $checkout=$this->get_checkout($order_id);
        // Checkout invalid error
        if (!$checkout){
            $this->error='Checkout ID unknown';
            return false;
        }

        // You introduce checkout to the system
        $this->set_checkout($checkout);

        $message_details=[
            'Payment ID'     =>@$data['payment_id'],
            'Paytype'        =>@$data['paytype'],
            //'ACQ ID'             =>@$data['acq_id'],
            'LiqPay Order ID'=>@$data['liqpay_order_id'],
            //'Sender First Name'  =>@$data['sender_first_name'],
            //'Sender Last Name'   =>@$data['sender_last_name'],
            //'Sender Card Mask2'  =>@$data['sender_card_mask2'],
            //'Sender Card Bank'   =>@$data['sender_card_bank'],
            //'Sender Card Type'   =>@$data['sender_card_type'],
            //'Sender Card Country'=>@$data['sender_card_country'],
            'IP'             =>@$data['ip'],
            //'Sender Commission'  =>@$data['sender_commission'],
            //'Receiver Commission'=>@$data['receiver_commission'],
            //'Agent Commission'   =>@$data['agent_commission'],
            //'MPI ECI'            =>@$data['mpi_eci'],
            //'Is 3Ds'             =>@$data['is_3ds'],
            //'Language'           =>@$data['language'],
            'Transaction ID' =>@$data['transaction_id'],
        ];
        $message_array=array();
        foreach ($message_details as $detail_name=>$detail_type){
            $message_array[]="{$detail_name}: {$detail_type}";
        }

        switch (@$data['status']){
            case 'success': // Успішний платіж
                return [
                    'status' =>'successful',
                    'paid'   =>[
                        'amount'  =>$data['amount'],
                        'currency'=>$data['currency'],
                    ],
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            case 'wait_secure': // Платіж на перевірці
            case 'wait_card': // Не встановлений спосіб відшкодування у одержувача
            case 'wait_accept': // Кошти з клієнта списані, але магазин ще не пройшов перевірку. Якщо магазин не пройде активацію протягом 60 днів, платежі будуть автоматично скасовані
            case 'processing': // Платіж обробляється
            case 'prepared': // Платіж створений, очікується його завершення відправником
            case 'invoice_wait': // Інвойс створений успішно, очікується оплата
            case 'cash_wait': // Очікується оплата готівкою в ТСО
                return [
                    'status' =>'pending',
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            case 'failure': // Неуспішний платіж
            case 'error': // Неуспішний платіж. Некоректно заповнені дані
                $message_array=[
                    'Error Code'=>$data['err_code'],
                    'Error'     =>$data['err_decription']
                ];
                return [
                    'status' =>'error',
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            case 'reversed': // 	Платіж повернений
            break;

            case 'subscribed': // Підписка успішно оформлена
            break;

            case 'unsubscribed': // Підписка успішно деактивована
            break;

            default:
                $this->error='Unknown status';
                return false;
            break;
        }


        return false;
    }
}
