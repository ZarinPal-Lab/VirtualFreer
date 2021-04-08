<?
/*
  Virtual Freer
  http://freer.ir/virtual
  Copyright (c) 2011 Mohammad Hossein Beyram, freer.ir
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License v3 (http://www.gnu.org/licenses/gpl-3.0.html)
  as published by the Free Software Foundation.
*/
//-- اطلاعات کلی پلاگین
$pluginData[zarinak][type] = 'payment';
$pluginData[zarinak][name] = 'زرینک';
$pluginData[zarinak][uniq] = 'zarinak';
$pluginData[zarinak][description] = 'مخصوص پرداخت با دروازه پرداخت <a href="http://zarinpal.com">زرین‌پال‌</a>';
$pluginData[zarinak][author][name] = 'Zarinpal';
$pluginData[zarinak][author][url] = 'http://zarinpal.ir';
$pluginData[zarinak][author][email] = 'sardarn84@yahoo.com';

//-- فیلدهای تنظیمات پلاگین
$pluginData[zarinak][field][config][1][title] = 'مرچنت';
$pluginData[zarinak][field][config][1][name] = 'merchant';
$pluginData[zarinak][field][config][2][title] = 'عنوان خرید';
$pluginData[zarinak][field][config][2][name] = 'title';

//-- تابع انتقال به دروازه پرداخت
function gateway__zarinak($data)
{
    global $config,$db,$smarty;
    //include_once('include/libs/nusoap.php');
    $merchantID 	= trim($data[merchant]);
    $amount 		= round($data[amount]/10);
    $invoice_id		= $data[invoice_id];
    $callBackUrl 	= $data[callback];


    $data = array("merchant_id" => $merchantID,
        "amount" => $amount,
        "callback_url" => $callBackUrl,
        "description" => $data[title].' - '.$data[invoice_id],
        "metadata" => [ "email" => $data[email],"mobile"=>$data[mobile]],
    );
    $jsonData = json_encode($data);
    $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
    curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ));

    $result = curl_exec($ch);
    $err = curl_error($ch);
    $result = json_decode($result, true, JSON_PRETTY_PRINT);
    curl_close($ch);
    if ($result['data']['code'] == 100)
    {
        $update[payment_rand]		= $result['data']["authority"];
        $sql = $db->queryUpdate('payment', $update, 'WHERE `payment_rand` = "'.$invoice_id.'" LIMIT 1;');
        $db->execute($sql);
        //header('location:https://www.zarinpal.com/pg/StartPay/' . $res['Authority']);

        $query		= 'SELECT * FROM `category` WHERE `category_parent_id` = "0" ORDER BY `category_order`';
        $categories	= $db->fetchAll($query);
        if ($categories)
            foreach ($categories as $key => $category)
            {
                if ($categories[$key]['category_image'])
                    $categories[$key]['category_image'] = $config['MainInfo']['url'].$config['MainInfo']['upload']['image'].'resized/category_'.$category['category_image'];
                $query		= 'SELECT * FROM `product` WHERE `product_category` = "'.$category['category_id'].'" ORDER BY `product_id` ASC';
                $categories[$key]['products']	= $db->fetchAll($query);
                if ($categories[$key]['products'])
                    foreach ($categories[$key]['products'] as $product_key => $product)
                    {
                        $count_query	= 'SELECT COUNT(*) FROM `card` WHERE `card_product` = "'.$product['product_id'].'" AND (`card_res_time` < "'.($now-(60*$config['card']['reserveExpire'])).'" OR `card_res_time` = "") AND `card_status` = "1" AND `card_show` = "1"';
                        $count_card		= $db->fetch($count_query);
                        $total_card		= $count_card['COUNT(*)'];
                        $categories[$key]['products'][$product_key]['counter'] = $total_card;
                    }
            }

        $query				= 'SELECT * FROM `plugin` WHERE `plugin_type` = "payment" AND `plugin_status` = "1"';
        $payment_methods	= $db->fetchAll($query);

        $banks_logo = '';
        for ($i=0;$i<768;$i=$i+32)	{
            $banks_logo 	.= '<li style="background-position: -'.$i.'px 0px;"></li>';
        }

        //-- نمایش صفحه
        $query	= 'SELECT * FROM `config` WHERE `config_id` = "1" LIMIT 1';
        $config	= $db->fetch($query);

        $smarty->assign('config', $config);
        $smarty->assign('categories', $categories);
        $smarty->assign('payment_methods', $payment_methods);
        $smarty->assign('banks_logo', $banks_logo);
        $smarty->display('index.tpl');
        echo'
<script type="text/javascript" src="https://cdn.zarinpal.com/zarinak/v1/checkout.js"></script>
<script type="text/javascript">
    window.onload = function () {
        Zarinak.setAuthority("' . $result['data']["authority"] . '");
        Zarinak.showQR();
        Zarinak.open();
    };
</script>';
        exit;
    }
    else
    {
        $data[title] = 'خطای سیستم';
        $data[message] = '<font color="red">در اتصال به درگاه زرین‌پال مشکلی به وجود آمد٬ لطفا از درگاه سایر بانک‌ها استفاده نمایید.</font>'. $result['errors']['code'].'<br /><a href="index.php" class="button">بازگشت</a>';
        $query	= 'SELECT * FROM `config` WHERE `config_id` = "1" LIMIT 1';
        $conf	= $db->fetch($query);
        $smarty->assign('config', $conf);
        $smarty->assign('data', $data);
        $smarty->display('message.tpl');
    }
}

//-- تابع بررسی وضعیت پرداخت
function callback__zarinak($data)
{
    global $db,$get;
    $Authority 	= $get['Authority'];
    //$ref_id = $get['refID'];
    if ($_GET['Status'] == 'OK')
    {

        $merchantID = $data[merchant];
        $sql 		= 'SELECT * FROM `payment` WHERE `payment_rand` = "'.$Authority.'" LIMIT 1;';
        $payment 	= $db->fetch($sql);

        $amount		= round($payment[payment_amount]/10);


        $data = array("merchant_id" => $merchantID, "authority" => $Authority, "amount" => $amount);
        $jsonData = json_encode($data);
        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));

        $result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($result, true);
        if ($payment[payment_status] == 1)
        {
            if ($result['data']['code'] == 100)//-- موفقیت آمیز
            {
                //-- آماده کردن خروجی
                $output[status]		= 1;
                $output[res_num]	= $Authority;
                $output[ref_num]	= $result['data']['ref_id'];
                $output[payment_id]	= $payment[payment_id];
            }
            else
            {
                //-- در تایید پرداخت مشکلی به‌وجود آمده است‌
                $output[status]	= 0;
                $output[message]= 'پرداخت توسط زرین‌پال تایید نشد‌.'.$result['errors']['code'];
            }
        }
        else
        {
            //-- قبلا پرداخت شده است‌
            $output[status]	= 0;
            $output[message]= 'سفارش قبلا پرداخت شده است.';
        }
    }
    else
    {
        //-- شماره یکتا اشتباه است
        $output[status]	= 0;
        $output[message]= 'شماره یکتا اشتباه است.';
    }
    return $output;
}