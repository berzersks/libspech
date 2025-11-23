<?php

class AudioProxyBuffer
{
    public mixed $channel = [];

    protected string $buffer = '';
    protected array $bufferArray = [];

    public function __construct(string $string = '')
    {
        $this->buffer = $string;
    }

    public function channelAvaliable($esp=false): bool
    {
        if (!$esp) return count($this->channel) > 0;
        else return array_key_exists($esp, $this->channel);
    }

    public function setChannel(mixed $channel, $id=null): void
    {
        if (!$id) $this->channel[0] = $channel;
        else {
            $this->channel[$id] = $channel;
        }

    }

    public function append(string $data): void
    {
        $this->buffer .= $data;
    }

    public function appendKey(string $key, string $data): void
    {
        if (!array_key_exists($key, $this->bufferArray)) {
            $this->bufferArray[$key] = '';
        }
        $this->bufferArray[$key] .= $data;
    }

    public function toString(): string
    {
        return $this->buffer;
    }

    public function getAllBuffers(): array
    {
        return $this->bufferArray;
    }


    public function length($key = false): int
    {
        return $key === false
            ? strlen($this->buffer)
            : strlen($this->bufferArray[$key] ?? '');
    }

    // 🔐 Esconde o conteúdo no var_dump()
    public function __debugInfo(): array
    {
        return [
            'channel' => $this->channel,
            'buffer' => '[hidden]',
            'bufferArrayKeys' => array_keys($this->bufferArray),
            'length' => strlen($this->buffer),
        ];
    }

    // 🔐 Protege contra echo $obj
    public function __toString(): string
    {
        return '[AudioProxyBuffer object]';
    }
}
