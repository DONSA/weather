<?php

namespace App;

class Parser
{
    private function getYrDataErrorMessage($msg = 'Fail')
    {
        return [
            '0'=> ['tag'=> 'WEATHERDATA','type'=> 'open','level'=> '1'],
            '1'=> ['tag'=> 'LOCATION','type'=> 'open','level'=> '2'],
            '2'=> ['tag'=> 'NAME','type'=> 'complete','level'=> '3','value'=> $msg],
            '3'=> ['tag'=> 'LOCATION','type'=> 'complete','level'=> '3'],
            '4'=> ['tag'=> 'LOCATION', 'type'=> 'close', 'level'=> '2'],
            '5'=> ['tag'=> 'FORECAST', 'type'=> 'open', 'level'=> '2'],
            '6'=> ['tag'=> 'ERROR', 'type'=> 'complete', 'level'=> '3', 'value'=> $msg],
            '7'=> ['tag'=> 'FORECAST', 'type'=> 'close', 'level'=> '2'],
            '8'=> ['tag'=> 'WEATHERDATA', 'type'=> 'close', 'level'=> '1']
        ];
    }

    private function getYrXMLErrorMessage($msg = 'Fail')
    {
        return $this->getXMLEntities($msg);
    }

    private function loadXMLData($url, $timeout = 10)
    {
        // $url .= '/varsel.xml';
        $url .= '/forecast_hour_by_hour.xml';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout
            ]
        ]);

        $data = file_get_contents($url, 0, $ctx);

        if (!$data){
            throw new InvalidArgumentException('Invalid data');
        }

        if (function_exists('curl_init')) {
            $localXmlUrl = 'cache/curl.temp.xml';

            $ch = curl_init($url);
            $fp = fopen($localXmlUrl, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
            $data = file_get_contents($localXmlUrl, 0, $ctx);

            unlink($localXmlUrl);

            if (false === $data) {
                return $this->getYrXMLErrorMessage('There was an error fetching weather data was read from yr.no');
            }

            return $data;
        }

        return $this->getYrXMLErrorMessage('Det oppstod en feil mens værdata ble forsøkt lest fra yr.no. Teknisk info: Denne PHP-installasjon har verken URL enablede fopen_wrappers eller cURL. Dette gjør det umulig å hente ned værdata. Se imiddlertid følgende dokumentasjon: http://no.php.net/manual/en/wrappers.php, http://no.php.net/manual/en/book.curl.php');
    }

    private function parseXMLIntoStruct($data)
    {
        $parser = xml_parser_create('ISO-8859-1');

        if ((0 === $parser)||(false === $parser)) {
            return $this->getYrDataErrorMessage('Det oppstod en feil mens værdata ble forsøkt hentet fra yr.no. Teknisk info: Kunne ikke lage XML parseren.');
        }

        $values = [];
        if (false === xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1)) {
            return $this->getYrDataErrorMessage('Det oppstod en feil mens værdata ble forsøkt hentet fra yr.no. Teknisk info: Kunne ikke stille inn XML-parseren.');
        }

        if (0 === xml_parse_into_struct($parser, $data, $values, $index)) {
            return $this->getYrDataErrorMessage('Det oppstod en feil mens værdata ble forsøkt hentet fra yr.no. Teknisk info: Parsing av XML feilet.');
        }

        if (false === xml_parser_free($parser)) {
            return $this->getYrDataErrorMessage('Det oppstod en feil mens værdata ble forsøkt hentet fra yr.no. Kunne ikke frigjøre XML-parseren.');
        }

        return $values;
    }


    private function sanitizeString($in)
    {
        if(is_array($in)) {
            return $in;
        }

        if(null === $in) {
            return null;
        }

        return htmlentities(
            strip_tags($in)
        );
    }

    private function rearrangeChildren($vals, &$i) {
        $children = [];

        if (isset($vals[$i]['value'])) {
            $children['VALUE'] = $this->sanitizeString($vals[$i]['value']);
        }

        while (++$i < count($vals)) {

            if (isset($vals[$i]['value'])) {
                $val = $this->sanitizeString($vals[$i]['value']);
            } else {
                unset($val);
            }

            if (isset($vals[$i]['type'])) {
                $typ = $this->sanitizeString($vals[$i]['type']);
            } else {
                unset($typ);
            }

            if(isset($vals[$i]['attributes'])) {
                $atr = $this->sanitizeString($vals[$i]['attributes']);
            } else {
                unset($atr);
            }

            if (isset($vals[$i]['tag'])) {
                $tag = $this->sanitizeString($vals[$i]['tag']);
            } else {
                unset($tag);
            }

            switch ($vals[$i]['type']){
            case 'cdata':
                $children['VALUE']= isset($children['VALUE']) ? $val : $children['VALUE'] . $val;
                break;
            case 'complete':
                if (isset($atr)) {
                    $children[$tag][]['ATTRIBUTES'] = $atr;
                    $index = count($children[$tag])-1;
                    if (isset($val))$children[$tag][$index]['VALUE'] = $val;
                    else $children[$tag][$index]['VALUE'] = '';
                } else {
                    if (isset($val))$children[$tag][]['VALUE'] = $val;
                    else $children[$tag][]['VALUE'] = '';
                }
                break;
            case 'open':
                if (isset($atr)) {
                    $children[$tag][]['ATTRIBUTES'] = $atr;
                    $index = count($children[$tag])-1;
                    $children[$tag][$index] = array_merge(
                        $children[$tag][$index],
                        $this->rearrangeChildren($vals, $i)
                    );
                } else {
                    $children[$tag][] = $this->rearrangeChildren($vals, $i);
                }
                break;
            case 'close':
                return $children;
            }
        }
    }

    private function rearrangeDataStructure($values)
    {
        $tree = [];
        $i = 0;
        if (isset($values[$i]['attributes'])) {
            $tree[$values[$i]['tag']][]['ATTRIBUTES']=$values[$i]['attributes'];
            $index=count($tree[$values[$i]['tag']])-1;
            $tree[$values[$i]['tag']][$index]=array_merge($tree[$values[$i]['tag']][$index], $this->rearrangeChildren($values, $i));
        } else {
            $tree[$values[$i]['tag']][] = $this->rearrangeChildren($values, $i);
        }

        if (isset($tree['WEATHERDATA'][0]['FORECAST'][0])) {
            return $tree['WEATHERDATA'][0]['FORECAST'][0];
        }


        return $this->getYrDataErrorMessage('There was an error processing data from yr.no');
    }

    public function getXMLTree($url): array
    {
        return $this->rearrangeDataStructure(
            $this->parseXMLIntoStruct(
                $this->loadXMLData($url)
            )
        );
    }

    public static function parseTime($time, $is24Hours = false)
    {
        if ($is24Hours) {
            return str_replace('00', '24', $time);
        }

        return str_replace(':00:00', '', $time);
    }


    public function getXMLEntities($string){
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7F]/e', '$this->_privateXMLEntities("$0")', $string);
    }

    private function _privateXMLEntities($num){
        $chars = [
            128 => '&#8364;', 130 => '&#8218;',
            131 => '&#402;', 132 => '&#8222;',
            133 => '&#8230;', 134 => '&#8224;',
            135 => '&#8225;',136 => '&#710;',
            137 => '&#8240;',138 => '&#352;',
            139 => '&#8249;',140 => '&#338;',
            142 => '&#381;', 145 => '&#8216;',
            146 => '&#8217;',147 => '&#8220;',
            148 => '&#8221;',149 => '&#8226;',
            150 => '&#8211;',151 => '&#8212;',
            152 => '&#732;',153 => '&#8482;',
            154 => '&#353;',155 => '&#8250;',
            156 => '&#339;',158 => '&#382;',
            159 => '&#376;'
        ];

        $num = ord($num);

        return (
        ($num > 127 && $num < 160) ? $chars[$num] : "&#{$num};"
        );
    }
}