<?php

namespace Eg\AsyncHttp\Buffer;

class MemoryBuffer implements BufferInterface
{
    private string $buffer = '';

    public function size(): int
    {
        return strlen($this->buffer);
    }

    public function reset():void{
        unset($this->buffer);
        $this->buffer = '';
    }

    public function append(string $fragment):void{
        $this->buffer .= $fragment;
    }

    public function readLine():string|null{
        if(empty($this->buffer)){
            return null;
        }

        $pos = strpos($this->buffer, "\r\n");

        if($pos === false){
            return null;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 2);

        return $line;
    }


    public function read(int $size): string|null
    {
        if($this->size() < $size){
            return null;
        }

        $data = substr($this->buffer, 0, $size);
        $this->buffer = substr($this->buffer, $size);

        return $data;
    }
}