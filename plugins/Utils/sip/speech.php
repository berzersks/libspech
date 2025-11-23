<?php

namespace sip;

use Swoole\Coroutine\Http\Client;

class speech
{
    public static array $rulesReplace = [
        'três,' => '3',
        'vinte e t' => '23 ',
        'dois mil e vinte e um' => '2021 ',
        'dois mil e vinte e do' => '2022 ',
        'dois mil e vinte e tr' => '2023 ',
        'dois mil e 23' => '2023 ',
        'dois mil e vinte e quatro' => '2024 ',
        'mil e novecentos e noventa e nove' => '1999 ',
        'mil quinhentos ' => '1500 ',
        'mil quinhentos e cinquenta' => '1550 ',
        'mil cento' => '1100',
        'mil duzentos' => '1200',
        'mil trezentos' => '1300',
        'mil quatrocentos' => '1400',
        'mil quinhentos' => '1500',
        'mil seiscentos' => '1600',
        'mil setecentos' => '1700',
        'mil oitocentos' => '1800',
        'mil novecentos' => '1900',
        'dois mil e vinte' => '2020',
        'oitocentos e ' => ' > ~800 ',
        'dezessete' => '17 ',
        '2020 e 3' => '2023 ',
        '2020 e 4' => '2024 ',
    ];
    private static string $dpgKey = '';

    public static function deepGramFile(string $audioFile, $fail = false, string $dpgKey = '', $model = 'nova-2'): string
    {
        self::$dpgKey = $dpgKey;

        $client = new Client('api.deepgram.com', 443, true);
        $client->setHeaders([
            'Authorization' => 'Token ' . self::$dpgKey,
            'content-type' => 'audio/wav',
        ]);
        $client->set(['timeout' => 30]);
        for ($n = 3; $n--;) {
            $client->post('/v1/listen?replace=pecle%3Atecle&replace=mil+quinhentos%3A1500&replace=quinhentos%3A500&replace=mil%3A1000&replace=cento%3A100&replace=duzentos%3A200&replace=setecentos%3A700&replace=dezessete%3A17&replace=dois%3A1000&replace=vinte+e+cinco%3A2025&keywords=agencia%3A1&keywords=no+caixa+tem%3A1&keywords=nao+ha+parcelas%3A1&language=pt-BR&model=' . $model, file_get_contents($audioFile));
            $response = $client->body;
            $d = json_decode($response, true);
            if (isset($d['results']['channels'][0]['alternatives'][0]['transcript'])) break;
        }
        //var_dump($d);
        $client->close();
        if (empty($d['results']['channels'][0]['alternatives'][0]['transcript'])) {
            if (is_callable($fail)) $fail();
            return '';
        }
        //return $d['results']['channels'][0]['alternatives'][0]['transcript'];
        $getFilters = self::$rulesReplace;
        $text = $d['results']['channels'][0]['alternatives'][0]['transcript'];

        foreach ($getFilters as $key => $value) {
            $text = str_replace(
                [',', '.',],
                '',
                $text
            );
            $text = str_replace(strtolower($key), strtolower($value), strtolower($text));
        }

        // se o audio tiver mais de 10kb, e a tradução for menor que 10 caracteres, então é um erro e faz reteste
        if (strlen($text) < 10 && filesize($audioFile) > 5000) {
            return self::deepGramFile($audioFile, $fail, $dpgKey, $model);
        }
        return $text;
    }

}