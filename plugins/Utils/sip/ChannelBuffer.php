<?php

namespace sip;

class ChannelBuffer extends \Swoole\Coroutine\Channel
{
    public function __construct(int $size = 1000)
    {
        parent::__construct($size);
    }

    public function getCapacity()
    {
        return $this->capacity;
    }
}