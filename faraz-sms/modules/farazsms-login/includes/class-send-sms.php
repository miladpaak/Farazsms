<?php

namespace FarazSMS;


class Send_SMS {

    public function code() {
        $code = Farazsms_Get_Setting('sms', 'code_length');
        switch ($code) {
            case 4:
                $code = rand(1000, 9999);
                break;
            case 5:
                $code = rand(10000, 99999);
                break;
            case 6:
                $code = rand(100000, 999999);
                break;
            case 7:
                $code = rand(1000000, 9999999);
                break;
            case 8:
                $code = rand(10000000, 99999999);
                break;
            case 9:
                $code = rand(100000000, 999999999);
                break;
            case 10:
                $code = rand(1000000000, 9999999999);
                break;
            default:
                $code = rand(10000, 99999);
                break;
        }

        return $code;
    }

    public function send($number, $code) {

        $opt = [
            'apikey'        => Farazsms_Get_Setting('sms', 'api_key'),
            'pattern_code'  => Farazsms_Get_Setting('sms', 'pattern_code'),
            'sender'        => Farazsms_Get_Setting('sms', 'sender'),
        ];
    
        $data = [
            'code' => $opt['pattern_code'],
            'attributes' => [
                'code' => $code
            ],
            'recipient'     => $number,
            'line_number'   => $opt['sender'],
            'number_format' => 'english'
        ];
    
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Api-Key: ' . $opt['apikey'],
                'Content-Type: application/json'
            ],
        ]);
    
        $response = curl_exec($curl);
        curl_close($curl);
    
        return json_decode($response, true);
    }

    public function send_pattern($number, $pattern_code, $attributes) {
        $opt = [
            'apikey'        => Farazsms_Get_Setting('sms', 'api_key'),
            'sender'        => Farazsms_Get_Setting('sms', 'sender'),
        ];
        if (empty($opt['apikey']) || empty($opt['sender']) || empty($pattern_code)) {
            return false;
        }
        if (!is_array($attributes)) {
            $attributes = [];
        }
        $data = [
            'code' => $pattern_code,
            'attributes' => $attributes,
            'recipient'     => $number,
            'line_number'   => $opt['sender'],
            'number_format' => 'english'
        ];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Api-Key: ' . $opt['apikey'],
                'Content-Type: application/json'
            ],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response, true);
    }
}