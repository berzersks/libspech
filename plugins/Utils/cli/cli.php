<?php

namespace Plugin\Utils;

use plugins\Utils\cache;
use Swoole\Coroutine;

class cli
{
    const MENU = "(l) Listar conexões          
(t) Listar troncos           
(c) Listar contas            
(b) Banir IP                 
(d) Desbanir IP              
(i) Listar IPs banidos
(p) Trocar Porta do Servidor Web
(e) Executar EVAL-CODE
(r) Reiniciar Servidor Web
(a) Listar chamadas
(x) Permitir debug        
(q) Encerrar servidor        " . PHP_EOL;

    public static function show(): void
    {
        print self::MENU;
    }

    public static function color($color, $message): string
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
        return "\033[" . $colorCode . "m" . $message . "\033[0m";
    }

    public static function menuCallback($menuCallback): void
    {
        // Exibe o menu inicial
        self::clearScreen();
        print self::MENU . PHP_EOL;

        while (true) {
            // Lê apenas 1 caractere para otimizar
            $key = strtolower(trim(fgets(STDIN, 2)));

            // Se a entrada estiver vazia, apenas continua
            if (empty($key)) {
                continue;
            }

            // Verifica se é uma tecla especial (setas)
            if (strpos($key, "\033") === 0) {
                // Ignora teclas especiais sem limpar a tela desnecessariamente
                continue;
            }

            // Processa comando válido
            if (isset($menuCallback[$key])) {
                self::clearScreen();
                $result = $menuCallback[$key]();
                if ($result === 'break') {
                    break;
                }
                print self::MENU . PHP_EOL;
            } else {
                // Comando inválido - apenas reexibe o menu sem limpar
                print "\nComando inválido. Tente novamente.\n";
                print self::MENU . PHP_EOL;
            }
        }
    }

    /**
     * Limpa a tela de forma segura
     */
    private static function clearScreen(): void
    {
        // Verifica se está em um ambiente que suporta clear
        if (function_exists('shell_exec') && !empty(Coroutine::exec('which clear 2>/dev/null')['output'])) {
            echo Coroutine::exec('clear')['output'];
        } else {
            // Fallback para sistemas que não suportam clear
            print "\033[2J\033[H";
        }
    }

    public static function pcl(string $message, string $color = 'white'): void
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
       print "\033[" . $colorCode . "m" . $message . "\033[0m" . "\n";
    }


    public static function cl(string $color, string $message): string
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
        return "\033[" . $colorCode . "m" . $message . "\033[0m" . "\n";
    }
}