<?php

namespace App;

use GuzzleHttp\Client;

class App
{
    /**
     * @var Parser $parser
     */
    private $parser;

    /**
     * @var Client $client
     */
    private $client;

    /**
     * App constructor.
     */
    public function __construct()
    {
        $this->parser = new Parser();
        $this->client = new Client([
            'base_uri' => 'https://api.pushover.net',
            'timeout'  => 2.0,
        ]);
    }

    public function run(): void
    {
        $response = $this->parser->getXMLTree(getenv('URL'));

        if (isset($response['ERROR'])) {
            throw new \RuntimeException($response['ERROR'][0]['VALUE']);
        }

        if (!isset($response['TABULAR'][0]['TIME'])) {
            return;
        }

        $message = '';
        foreach ($response['TABULAR'][0]['TIME'] as $time) {
            [$fromdate, $fromtime] = explode('T', $time['ATTRIBUTES']['FROM']);
            [$todate, $totime] = explode('T', $time['ATTRIBUTES']['TO']);

            $fromTime = Parser::parseTime($fromtime);
            $toTime = Parser::parseTime($totime, 1);

            $now = (new \DateTime())->setTime(0, 0);
            $date = new \DateTime($fromdate);
            $isToday = $now->diff($date)->days === 0;

            if ($isToday) {

                $precipitation = floatval($time['PRECIPITATION'][0]['ATTRIBUTES']['VALUE']);
                $temperature = round($time['TEMPERATURE'][0]['ATTRIBUTES']['VALUE']);
                $temperature = $temperature >= 0 ? $temperature : -$temperature;

                if ($precipitation > 0) {

                    // $windDirection = round($time['WINDDIRECTION'][0]['ATTRIBUTES']['DEG'] / 22.5);

                    // $r = round($time['WINDSPEED'][0]['ATTRIBUTES']['MPS']);
                    // $w = $time['WINDSPEED'][0]['ATTRIBUTES']['NAME'];

                    $symbolName = $time['SYMBOL'][0]['ATTRIBUTES']['NAME'];
                    $symbolCode = \intval($time['SYMBOL'][0]['ATTRIBUTES']['NUMBER']);

                    $description = '';
                    switch ($symbolCode) {
                        case 5: // Rain showers
                            $icon = '🌦';
                            break;
                        case 9: // Rain
                            $icon = '🌧';
                            break;
                        case 10: // Heavy rain
                            $icon = '🌧';
                            break;
                        case 12: // Sleet
                            $icon = '❄️';
                            break;
                        default:
                            $description = "{$symbolName} ({$symbolCode})";
                            $icon = '❔';
                    }

                    $message .= "{$icon} {$fromTime}:00h | {$precipitation}mm | {$temperature}°C";

                    if ($description) {
                        $message .= " | {$description}";
                    }

                    $message .= "\n";
                }
            }
        }

        if (!empty($message)) {
            $this->client->post('/1/messages.json', [
                'json' => [
                    'token' => getenv('PUSHOVER_TOKEN'),
                    'user' => getenv('PUSHOVER_USER'),
                    'title' => 'Will it rain?',
                    'message' => trim($message),
                    'sound' => 'gamelan',
                ]
            ]);
        }
    }
}
