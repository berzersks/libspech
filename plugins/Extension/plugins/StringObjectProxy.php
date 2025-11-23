<?php

use Swoole\StringObject;

class StringObjectProxy extends StringObject
{
    /**
     * Consome e retorna os primeiros $length bytes da string.
     *
     * @param int $length Quantidade de bytes a consumir.
     * @return false|string Retorna os bytes consumidos, ou false se a string estiver vazia.
     */
    public function cpos(int $length): false|string
    {
        $value = $this->string;
        $currentLength = strlen($value);

        if ($currentLength === 0) {
            return false;
        }

        $slice = substr($value, 0, $length);
        $this->string = substr($value, $length);  // Atualiza a string, removendo os bytes lidos
        return $slice;
    }
}
