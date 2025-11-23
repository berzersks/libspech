<?php

#[AllowDynamicProperties]
class ArrayObjectDynamic
{
    /** @var array O array encapsulado */
    protected array $array;

    /**
     * Construtor para encapsular um array.
     *
     * @param array $array O array inicial.
     */
    public function __construct(array $array = [])
    {
        $this->array = $array;
    }

    /**
     * Obtém um valor do array.
     *
     * @param string|int $key Chave do valor.
     * @return mixed
     */
    public function get($key)
    {
        if (array_key_exists($key, $this->array)) {
            return $this->array[$key];
        }
        throw new RuntimeException("A chave '{$key}' não existe no array.");
    }

    /**
     * Define um valor no array.
     *
     * @param string|int $key Chave do valor.
     * @param mixed $value Valor a ser atribuído.
     */
    public function set($key, $value): void
    {
        $this->array[$key] = $value;
    }

    /**
     * Verifica se uma chave existe no array.
     *
     * @param string|int $key Chave a ser verificada.
     * @return bool
     */
    public function isset($key): bool
    {
        return isset($this->array[$key]);
    }

    /**
     * Remove um valor do array.
     *
     * @param string|int $key Chave do valor.
     */
    public function unset($key): void
    {
        if (array_key_exists($key, $this->array)) {
            unset($this->array[$key]);
        } else {
            throw new RuntimeException("A chave não pode ser removida porque não existe.");
        }
    }

    /**
     * Retorna o array encapsulado.
     *
     * @return array
     */
    public function getArray(): array
    {
        return $this->array;
    }

    /**
     * Adiciona um valor ao final do array.
     *
     * @param mixed $value Valor a ser adicionado.
     */
    public function append($value): void
    {
        $this->array[] = $value;
    }

    /**
     * Obtém o número de elementos no array.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->array);
    }
}