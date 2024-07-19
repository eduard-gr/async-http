<?php

namespace Eg\AsyncHttp\Buffer;

interface BufferInterface
{
    public function reset():void;
    public function size():int;
    public function append(string $fragment):void;
    public function readLine():string|null;
    public function read(int $size):string|null;
}