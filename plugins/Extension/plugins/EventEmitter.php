<?php

use Swoole\Coroutine;

class EventEmitter
{
    private array $events = [];

    public function then(callable $callback): void
    {
        $this->on('Finish', $callback);
    }

    /**
     * Registra um evento e sua callback.
     *
     * @param string $event Nome do evento.
     * @param callable $callback Função a ser executada quando o evento for emitido.
     */
    public function on(string $event, callable $callback): void
    {
        $this->events[$event][] = $callback;
    }

    /**
     * Emite um evento e executa todos os callbacks registrados.
     *
     * @param string $event Nome do evento.
     * @param mixed ...$args Argumentos a serem passados para as callbacks.
     */
    public function emit(string $event, ...$args): void
    {
        if (!isset($this->events[$event])) {
            return;
        }

        foreach ($this->events[$event] as $callback) {
            Coroutine::create(function () use ($callback, $args) {
                try {
                    call_user_func_array($callback, $args);
                } catch (\Throwable $e) {
                    echo "Erro no evento '$event': " . $e->getMessage() . PHP_EOL;
                }
            });
        }
    }

    /**
     * Remove um evento ou uma callback específica de um evento.
     *
     * @param string $event Nome do evento.
     * @param callable|null $callback Se fornecido, remove apenas a callback específica.
     */
    public function off(string $event, ?callable $callback = null): void
    {
        if (!isset($this->events[$event])) {
            return;
        }

        if ($callback === null) {
            unset($this->events[$event]);
        } else {
            $this->events[$event] = array_filter(
                $this->events[$event],
                fn($existingCallback) => $existingCallback !== $callback
            );

            // Remove o evento se não restarem callbacks
            if (empty($this->events[$event])) {
                unset($this->events[$event]);
            }
        }
    }
}
