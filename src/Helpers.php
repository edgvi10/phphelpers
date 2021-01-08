<?php

namespace EDGVI10;

class Helpers
{
    public static function getEnv($filepath)
    {
        $env = file_get_contents($filepath);

        $rows = explode("\n", $env);

        $return = [];
        foreach ($rows as $row) :
            if (!empty($row)) :
                if ($row[0] !== "#") :
                    $data = explode("=", $row);
                    $key = strtolower(trim($data[0]));
                    unset($data[0]);
                    $value = implode("=", $data);
                    $return[$key] = trim($value);
                endif;
            endif;
        endforeach;

        return (object) $return;
    }

    public static function cors()
    {
        if (isset($_SERVER["HTTP_ORIGIN"])) :
            header("Access-Control-Allow-Origin: {$_SERVER["HTTP_ORIGIN"]}");
            header("Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS");
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Max-Age: 86400");
            header("Cache-Control: no-cache");
            header("Pragma: no-cache");
            header("Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization, JWT");
        endif;
    }

    public static function uuid()
    {
        return sprintf(
            "%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    public static function call(array $options, $debug = false)
    {
        $curl = curl_init();

        switch ($options["method"]):
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);
                if (isset($options["data"]) && !empty($options["data"]))
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($options["data"]));
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                if (isset($options["data"]) && !empty($options["data"]))
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($options["data"]));
                break;
            default:
                if (isset($options["data"]) && !empty($options["data"]))
                    $options["endpoint"] = sprintf("%s?%s", $options["endpoint"], http_build_query($options["data"]));
        endswitch;

        $headers = [];
        if (isset($options["headers"])) $headers = $options["headers"];
        $headers[] = "Content-Type: application/json";

        // OPTIONS:
        curl_setopt($curl, CURLOPT_URL, $options["endpoint"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_HEADER, false);

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

        // EXECUTE:
        $response = curl_exec($curl);
        curl_close($curl);

        $result["response"] = self::isJson($response) ? json_decode($response, 1) : $response;

        if ($debug) :
            $result["debug"]["curl"] = print_r($curl, true);
            $result["debug"]["options"] = print_r($options, true);

            return $result;
        endif;

        return (!empty($callback) && is_callable($callback)) ? $callback($result) : $result;
    }

    public static function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    public static function isAssoc(array $arr)
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public static function base64url_encode($data)
    {
        $b64 = base64_encode($data);

        if ($b64 === false) return false;

        $url = strtr($b64, "+/", "-_");

        return rtrim($url, "=");
    }

    public static function base64url_decode($data, $strict = false)
    {
        $b64 = strtr($data, "-_", "+/");

        return base64_decode($b64, $strict);
    }

    public static function base64ToImage($base64_string, $output_file)
    {
        $file = fopen($output_file, "wb");

        $data = explode(",", $base64_string);

        fwrite($file, base64_decode($data[1]));
        fclose($file);

        return $output_file;
    }

    public static function jwtToken($key, $jti, $exp = "", $nbf = "")
    {
        $exp = (empty($exp)) ? strtotime("+24 hours") : strtotime($exp);

        $header = [];
        $header["typ"] = "JWT";
        $header["alg"] = "HS256";
        if (!empty($jti)) $header["jti"] = $jti;

        $payload = [];
        if (!empty($exp)) $payload["exp"] = $exp;
        if (!empty($jti)) $payload["jti"] = $jti;

        $header = json_encode($header);
        $payload = json_encode($payload);

        $header = self::base64url_encode($header);
        $payload = self::base64url_encode($payload);

        $sign = hash_hmac("sha256", $header . "." . $payload, $key, true);
        $sign = self::base64url_encode($sign);

        $token = $header . "." . $payload . "." . $sign;

        return $token;
    }

    public static function JwtTokenDecode($token)
    {
        $parts = explode(".", $token);
        $headers = json_decode(self::base64url_decode($parts[0]));
        $payload = json_decode(self::base64url_decode($parts[1]));
        $sign = json_decode(self::base64url_decode($parts[2]));

        $return = [];

        $payload->exp = date("y-m-d H:i:s", $payload->exp);
        $return = $payload;

        return $return;
    }

    public static function errorHandler($message)
    {
        $json["success"] = false;
        $json["message"] = $message;

        return $json;
    }

    public static function requiredTest($required_fields, $data)
    {
        $json = [];
        $invalid = false;
        if (!is_array($data)) (array) $data;

        foreach ($required_fields as $field) :
            if (!isset($data[$field]) or strlen($data[$field]) === 0) :
                $invalid = true;
                $json["invalid"][] = ["field" => $field, "message" => "Required \"{$field}\" is missing"];
            endif;
        endforeach;

        return ($invalid) ? $json["invalid"] : true;
    }

    public static function validEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validCpf($cpf)
    {
        $cpf = preg_replace("/[^0-9]/is", "", $cpf);

        if (strlen($cpf) != 11)
            return false;

        if (preg_match("/(\d)\1{10}/", $cpf))
            return false;

        for ($t = 9; $t < 11; $t++) :
            for ($d = 0, $c = 0; $c < $t; $c++) :
                $d += $cpf[$c] * (($t + 1) - $c);
            endfor;

            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d)
                return false;
        endfor;

        return true;
    }

    public static function randomString($length = 16)
    {
        $key = "";
        $lower = "abcdefghijklmnopqrstuwvyxz";
        $upper = strtoupper($lower);
        $alpha = "0123456789";

        $keys = str_split($lower . $upper . $alpha);
        //print_r($keys);

        for ($i = 0; $i < $length; $i++) $key .= $keys[array_rand($keys)];

        return $key;
    }

    public static function capitalize($str, $lcase = "E|De|Do|Dos|Da|Das|No|Nos|Na|Nas", $ucase = "Rb|Uvd|Dg|R&b")
    {
        $all_lowercase = explode("|", $lcase);
        $all_uppercase = explode("|", $ucase);

        $normalizeChars = ["Á" => "A"];

        $str = strtr($str, $normalizeChars);
        $str = trim(mb_strtolower(utf8_decode($str)));
        $words = explode(" ", ucwords($str));

        for ($i = 0; $i < count($words); $i++) :
            if ($words[$i] != "") :
                if (in_array($words[$i], $all_uppercase)) :
                    $words[$i] = mb_strtoupper($words[$i]);
                elseif (in_array($words[$i], $all_lowercase)) :
                    $words[$i] = mb_strtolower($words[$i]);
                endif;
            endif;
        endfor;

        $str = implode(" ", $words);

        return utf8_encode($str);
    }

    public static function getName($fullname, $lcase = "e|de|do|dos|da|das|no|nos|na|nas", $ucase = "")
    {
        $fullname = self::capitalize($fullname);

        $all_lowercase = explode("|", $lcase);
        $all_uppercase = explode("|", $ucase);
        $fullname = explode(" ", $fullname);
        $name = $fullname[0];
        $lastname = array_reverse($fullname);

        if ($name == $lastname[0]) :
            return $name;
        elseif (in_array($lastname[1], $all_lowercase)) :
            return $name . " " . $lastname[1] . " " . $lastname[0];
        else :
            return $name . " " . $lastname[0];
        endif;
    }

    public static function extractInt($str)
    {
        return preg_replace("/[^0-9]/", "", $str);
    }

    public static function mask($mask, $string)
    {
        $string = str_replace(" ", "", $string);
        for ($i = 0; $i < strlen($string); $i++) :
            $mask[strpos($mask, "#")] = $string[$i];
        endfor;

        return $mask;
    }

    public static function phonemask($phone, $mask = true)
    {
        $phone = self::extractInt($phone);
        $phone = ltrim($phone, 0);

        if ($mask) :
            if (empty($phone)) :
                $phone = NULL;
            else :
                if (strlen($phone) == 13) :
                    $phone = self::mask("(## ##) #####-####", $phone);
                elseif (strlen($phone) == 12) :
                    $phone = self::mask("(###) #####-####", $phone);
                elseif (strlen($phone) == 11) :
                    $phone = self::mask("(##) #####-####", $phone);
                elseif (strlen($phone) == 10) :
                    $phone = self::mask("(##) ####-####", $phone);
                elseif (strlen($phone) == 9) :
                    $phone = self::mask("#####-####", $phone);
                elseif (strlen($phone) == 8) :
                    $phone = self::mask("####-####", $phone);
                endif;
            endif;
        endif;
        return $phone;
    }

    public static function phone($phone, $mask = true)
    {
        $phone = ltrim(self::extractInt($phone), "0");

        if ($mask) return self::phonemask($phone);
        else return $phone;
    }

    public static function dateTranslate($date)
    {
        if (count(explode("/", $date)) > 1) :
            return implode("-", array_reverse(explode("/", $date)));
        elseif (count(explode("-", $date)) > 1) :
            return implode("/", array_reverse(explode("-", $date)));
        endif;
    }

    public static function timemask($datetime)
    {
        if ($datetime != "0000-00-00" and $datetime != "0000-00-00 00:00:00" and $datetime != null) :
            $split = explode(" ", $datetime);

            $date = $split[0];
            $time = (isset($split[1])) ? $split[1] : null;

            switch ($date):
                case date("Y-m-d"):
                    $formatted = "hoje";
                    break;

                case date("Y-m-d", strtotime("-1 day")):
                    $formatted = "ontem";
                    break;

                case date("Y-m-d", strtotime("+1 day")):
                    $formatted = "amanhã";
                    break;

                default:
                    if (date("Y") != date("Y", strtotime($date))) :
                        $formatted = utf8_encode(strftime("%b, %Y", strtotime($date)));
                    elseif (date("W") == date("W", strtotime($date))) :
                        $formatted = utf8_encode(strftime("%A", strtotime($date)));
                    else :
                        $formatted = strftime("%d %b", strtotime($time));
                    endif;
                    break;
            endswitch;

            if (!empty($time) && date("Y") == date("Y", strtotime($date))) :
                $time = explode(":", $time);
                $time = "{$time[0]}:{$time[1]}";
                $formatted = "{$formatted}, {$time}";
            endif;
        else :
            $formatted = "--";
        endif;

        return $formatted;
    }

    public static function pricemask($decimal, $reverse = false)
    {
        if ($reverse) :
            // $decimal = str_replace(".", "", $decimal);
            $decimal = str_replace(",", ".", $decimal);
            $decimal = number_format($decimal, 2, ".", ",");
        else :
            $decimal = number_format($decimal, 2, ",", ".");
        endif;

        return $decimal;
    }

    public static function decimal($decimal)
    {
        $decimal = str_replace(".", "", $decimal);
        $decimal = str_replace(",", ".", $decimal);

        return (float) $decimal;
    }

    public static function timecount($data1, $data2 = false)
    {
        if (!$data2) $data2 = date("Y-m-d H:i:s");

        $unix_data1 = strtotime($data1);
        $unix_data2 = strtotime($data2);

        $nHours   = ($unix_data2 - $unix_data1) / 3600;
        $nMinutes = (($unix_data2 - $unix_data1) % 3600) / 60;
        $nMinutes_total = (($unix_data2 - $unix_data1)) / 60;

        $return["hour"] = str_pad(floor($nHours), 2, 0, STR_PAD_LEFT);
        $return["minute"] = str_pad(floor($nMinutes), 2, 0, STR_PAD_LEFT);
        $return["minutes"] = floor($nMinutes_total);

        $return = (object) $return;
        return $return;
    }

    public static function distance($x, $z)
    {
        $x = explode(",", $x);
        $z = explode(",", $z);

        $lat1 = deg2rad($x[0]);
        $lon1 = deg2rad($x[1]);
        $lat2 = deg2rad($z[0]);
        $lon2 = deg2rad($z[1]);

        $latD = $lat2 - $lat1;
        $lonD = $lon2 - $lon1;

        $return = 2 * asin(sqrt(pow(sin($latD / 2), 2) + cos($lat1) * cos($lat2) * pow(sin($lonD / 2), 2)));
        $return = $return * 6371;

        return number_format($return, 3, ".", "");
    }

    public static function numbermask($number, $invert = false)
    {
        if ($invert) :
            $number = str_replace(",", ".", $number);
            $number = str_replace(",", "", $number);
        else :
            if (is_numeric($number) && floor($number) != $number) :
                $decimal = explode(".", $number);
                $decimal = count($decimal[1]);
                $number = number_format($number, $decimal, ",", ".");
            else :
                $number = preg_replace("/[^0-9]/", "", $number);
                $number = number_format($number, 0, ",", ".");
            endif;
        endif;

        return $number;
    }

    public static function slugfy($string, $separator = "-")
    {
        $string = preg_replace("/[\/]/", " ", $string);
        $string = preg_replace("/[\t\n]/", " ", $string);
        $string = preg_replace("/\s{2,}/", " ", $string);
        $list = ["À" => "A", "Á" => "A", "Â" => "A", "Ã" => "A", "Ä" => "A", "Å" => "A", "Ç" => "C", "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "Ñ" => "N", "Ò" => "O", "Ó" => "O", "Ô" => "O", "Õ" => "O", "Ö" => "O", "Ø" => "O", "Ù" => "U", "Ú" => "U", "Û" => "U", "Ü" => "U", "Ý" => "Y", "à" => "a", "á" => "a", "â" => "a", "ã" => "a", "ä" => "a", "å" => "a", "æ" => "a", "ç" => "c", "è" => "e", "é" => "e", "ê" => "e", "ë" => "e", "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ð" => "o", "ñ" => "n", "ò" => "o", "ó" => "o", "ô" => "o", "õ" => "o", "ö" => "o", "ø" => "o", "ù" => "u", "ú" => "u", "û" => "u", "ý" => "y", "ý" => "y", "þ" => "b", "ÿ" => "y", "Ŕ" => "R", "ŕ" => "r"];
        $string = strtr($string, $list);
        $string = strtolower($string);

        $string = preg_replace("/[^a-z0-9 ]+/", "", $string);
        $string = preg_replace("/[\s]/", "{$separator}", $string);
        $string = preg_replace("/{$separator}{2,}/", "{$separator}", $string);

        return $string;
    }

    public static function normalize($string)
    {
        $list = ["À" => "A", "Á" => "A", "Â" => "A", "Ã" => "A", "Ä" => "A", "Å" => "A", "Ç" => "C", "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "Ñ" => "N", "Ò" => "O", "Ó" => "O", "Ô" => "O", "Õ" => "O", "Ö" => "O", "Ø" => "O", "Ù" => "U", "Ú" => "U", "Û" => "U", "Ü" => "U", "Ý" => "Y", "à" => "a", "á" => "a", "â" => "a", "ã" => "a", "ä" => "a", "å" => "a", "æ" => "a", "ç" => "c", "è" => "e", "é" => "e", "ê" => "e", "ë" => "e", "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ð" => "o", "ñ" => "n", "ò" => "o", "ó" => "o", "ô" => "o", "õ" => "o", "ö" => "o", "ø" => "o", "ù" => "u", "ú" => "u", "û" => "u", "ý" => "y", "ý" => "y", "þ" => "b", "ÿ" => "y", "Ŕ" => "R", "ŕ" => "r"];
        $string = strtr($string, $list);
        $string = strtolower($string);

        return $string;
    }

    public static function mime2ext($mime)
    {
        $all_mimes = [
            "png" => ["image/png", "image/x-png"],
            "jpeg" => ["image/jpeg", "image/pjpeg"],
            "rtx" => ["text/richtext"],
            "rtf" => ["text/rtf"],
            "zip" => ["application/x-zip", "application/zip", "application/x-zip-compressed", "application/s-compressed", "multipart/x-zip"],
            "pdf" => ["application/pdf", "application/octet-stream"],
            "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document"],
            "rar" => ["application/x-rar", "application/rar", "application/x-rar-compressed"]
        ];

        foreach ($all_mimes as $key => $value) {
            if (array_search($mime, $value) !== false) return $key;
        }

        return false;
    }
}
