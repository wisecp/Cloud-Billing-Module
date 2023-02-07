<?php
    Class CloudBillingSample extends AddonModule {
        public $version = "1.0";
        function __construct(){
            $this->_name = __CLASS__;
            parent::__construct();
        }

        public function create_error($error='',$invoice_id = 0)
        {
            Helper::Load("Events");
            return Events::create([
                'type' => "info",
                'owner' => "system",
                'owner_id' => $invoice_id,
                'name'  => "module-addon-error",
                'data'  => [
                    'name' => __CLASS__,
                    'message' => $error,
                ],
            ]);
        }

        public function use_curl($endpoint = '',$data=[])
        {
            $api_url                = "https://api.example.com/v1/";
            $api_key                = $this->config["settings"]["api-key"] ?? '';
            $api_header             = [
                'Authorization: Bearer '.$api_key,
                'Content-Type: application/json'
            ];

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $api_url.$endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => $api_header,
            ));

            $response       = curl_exec($curl);

            $response       = json_decode($response,true);

            $curl_error     = curl_error($curl);

            curl_close($curl);

            $this->error = $curl_error;

            if($response && $response['status'] != "successful")
            {
                $this->error = $response['error_message'] ?? 'Unknown Error';
                $response = false;
            }

            return $response;
        }

        public function fields(){
            $settings = isset($this->config['settings']) ? $this->config['settings'] : [];
            return [
                'api-key'          => [
                    'wrap_width'        => 100,
                    'name'              => "API Key",
                    'description'       => "API Key description",
                    'type'              => "text",
                    'value'             => $settings["api-key"] ?? '',
                    'placeholder'       => "API Key enter here...",
                ],
                'secret-key'          => [
                    'wrap_width'        => 100,
                    'name'              => "Secret Key",
                    'description'       => "Secret Key description....",
                    'type'              => "password",
                    'value'             => $settings["secret-key"] ?? '',
                    'placeholder'       => "Secret Key enter here...",
                ],
                'sandbox'          => [
                    'wrap_width'        => 100,
                    'name'              => "Enable Sandbox",
                    'description'       => "Sandbox description",
                    'type'              => "approval",
                    'checked'           => $settings["sandbox"] ?? false,
                ],
            ];
        }

        public function save_fields($fields=[]){
            /*
            if(!isset($fields['example1']) || !$fields['example1']){
                $this->error = $this->lang["error1"];
                return false;
            }
            */
            return $fields;
        }

        public function activate(){
            /*
             * Here, you can perform any intervention before the module is activate.
             * If you return boolean (true), the module will be activate.
            */
            return true;
        }

        public function deactivate(){
            /*
             * Here, you can perform any intervention before the module is deactivate.
             * If you return boolean (true), the module will be deactivate.
            */
            return true;
        }

        public function adminArea()
        {
            $action = Filter::init("REQUEST/action","route");
            if(!$action) $action = 'index';

            $variables = [
                'link'              => $this->area_link,  /* https://***..com/admin/tools/addons/SampleAddon */
                'dir_link'          => $this->url,       /* https://***..com/coremio/modules/Addons/SampleAddon/ */
                'dir_path'          => $this->dir,      /* /-- DOCUMENT ROOT --/coremio/modules/Addons/SampleAddon/ */
                'dir_name'          => $this->_name,    /* SampleAddon, */
                'name'              => $this->lang["meta"]["name"], /* Sample Addon */
                'version'           => $this->config["meta"]["version"], /* 1.0 */
                'fields'            => $this->fields(),
            ];

            return [
                'page_title'        => 'Sample Cloud Billing Module',
                'breadcrumbs'       => [
                    [
                        'link'      => '',
                        'title'     => 'Sample Cloud Billing Module',
                    ],
                ],
                'content'           => $this->view($action.".php",$variables)
            ];
        }

        public function upgrade(){
            if($this->config["meta"]["version"] < 1.1)
            {
                /*
                 * WDB::query("ALTER TABLE md_SampleAddon ADD test1 varchar(255);"); # PDO::query()
                */
            }
            elseif($this->config["meta"]["version"] < 1.2)
            {
                /*
                 * WDB::query("ALTER TABLE md_SampleAddon ADD test2 varchar(255);"); # PDO::query()
                */
            }

            /*
             * If you want to give an error:
             * $this->error = "sample error text here";
             * return false;
            */

            return true;
        }

        public function created($invoice = [])
        {
            $items  = Invoices::get_items($invoice["id"]); // database: invoices_items

            $w_status   = 'Unpaid';

            if($invoice['status'] == "paid") $w_status = 'Paid';

            $currency_data      = Money::Currency($invoice["currency"]);
            $currency           = $currency_data["code"]; // ex: USD,EUR,GBP...

            $response           = $this->use_curl('create-invoice',[
                'number'            => $invoice["id"],
                'status'            => $w_status,
                'sub_total'         => $invoice["subtotal"],
                'tax_rate'          => $invoice["taxrate"],
                'tax_total'         => $invoice["tax"],
                'total'             => round($invoice['total'],2),
                'currency'          => $currency,
                'start_time'        => $invoice["cdate"],
                'end_time'          => $invoice["duedate"],
            ]);


            if($response)
            {

                foreach($items AS $item)
                {

                    $response2 = $this->use_curl('add-invoice-item',[
                        'invoice_id' => $invoice['id'],
                        'description' => $item['description'],
                        'amount'      => $item["amount"],
                    ]);
                    if(!$response2) break;
                }

            }

            if($this->error) $this->create_error($this->error,$invoice["id"]);
        }
        public function modified($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                // Sample: checking due date
                if($exists['data']['end_time'] != $invoice['duedate'])
                {
                    $response   = $this->use_curl("set-invoice",[
                        'end_time' => $invoice['duedate'],
                    ]);
                    if(!$response) $this->create_error($this->error,$invoice['id']);
                }
            }
        }
        public function deleted($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $this->use_curl('delete-invoice',['number' => $invoice['id']]);
            }
        }
        public function refunded($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $this->use_curl('set-invoice',['status' => "Refunded"]);
            }
        }
        public function unpaid($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $this->use_curl('set-invoice',['status' => "Unpaid"]);
            }
        }
        public function paid($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $this->use_curl('set-invoice',['status' => "Paid"]);
            }
        }
        public function cancelled($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $this->use_curl('set-invoice',['status' => "Cancelled"]);
            }
        }
        public function formalized($invoice = [])
        {
            $exists = $this->use_curl('get-invoice-data',[
                'number'    => $invoice['id'],
            ]);

            if($exists)
            {
                $response = $this->use_curl('set-invoice',['status' => "Formalized"]);
                if(!$response)
                {
                    $this->create_error($this->error,$invoice['id']);
                    return false;
                }
            }

            return true;
        }

        public function cronjob()
        {
            $day            = 3; // Bring in invoices exceeding 3 days
            $past_day       = 30; // maximum how many days past invoices should be brought
            $time           = DateManager::Now();
            $invoices       = WDB::select("id")->from("invoices");
            $invoices->where("status","=","paid","AND");
            $invoices->where("pmethod","!=","Balance","AND");
            $invoices->where("legal","=","1","AND");
            $invoices->where("legal","=","1","AND");
            $invoices->where("DATEDIFF('".$time."',datepaid)",">=",$day,"AND");
            $invoices->where("DATEDIFF('".$time."',datepaid)","<",$past_day,"AND");
            $invoices->where("taxed","=","0");
            $invoices->order_by("datepaid ASC");
            $invoices       = $invoices->build() ? $invoices->fetch_object() : false;

            if($invoices)
            {
                foreach($invoices AS $inv)
                {
                    $invoice = Invoices::get($inv->id);
                    $this->formalized($invoice);
                }
            }
        }
    }

    // Adding Billing Page Logo
    Hook::add("InvoiceModulesLogos",1,function(){
        $config = include __DIR__.DS."config.php";
        $folder = CORE_FOLDER.DS.MODULES_FOLDER.DS."Addons".DS."CloudBillingSample".DS;
        $logo   = $config["meta"]["logo"] ?? 'logo.png';
        if(file_exists($folder.$logo))
        {
            $logo   = Utility::image_link_determiner($logo,$folder);
            return $logo;
        }
    });

    $className = str_replace(".php","",basename(__FILE__));

    Hook::add("InvoiceCreated",1,[
        'class'     => $className,
        'method'    => "created",
    ]);

    Hook::add("InvoiceModified",1,[
        'class'     => $className,
        'method'    => "modified",
    ]);

    Hook::add("InvoiceDeleted",1,[
        'class'     => $className,
        'method'    => "deleted",
    ]);

    Hook::add("InvoiceRefunded",1,[
        'class'     => $className,
        'method'    => "refunded",
    ]);

    Hook::add("InvoiceUnpaid",1,[
        'class'     => $className,
        'method'    => "unpaid",
    ]);

    Hook::add("InvoicePaid",1,[
        'class'     => $className,
        'method'    => "paid",
    ]);

    Hook::add("InvoiceCancelled",1,[
        'class'     => $className,
        'method'    => "cancelled",
    ]);

    Hook::add("InvoiceFormalized",1,[
        'class'     => $className,
        'method'    => "formalized",
    ]);

    Hook::add("PerMinuteCronJob",1,[
        'class'     => $className,
        'method'    => "cronjob",
    ]);